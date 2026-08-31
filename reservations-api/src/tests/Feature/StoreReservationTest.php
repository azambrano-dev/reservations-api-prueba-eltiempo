<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreReservationTest extends TestCase
{
    use RefreshDatabase;

    private function reserve(array $payload): TestResponse
    {
        return $this->postJson('/api/reservations', $payload);
    }

    public function test_confirms_a_reservation_and_decrements_stock(): void
    {
        $product = Product::factory()->withStock(10)->create();

        $response = $this->reserve([
            'request_id' => 'req-confirm-1',
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.remaining_stock', 7);

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_quantity_equal_to_stock_is_allowed_and_leaves_zero(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $this->reserve([
            'request_id' => 'req-exact',
            'product_id' => $product->id,
            'quantity' => 5,
        ])->assertCreated()->assertJsonPath('data.remaining_stock', 0);

        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_insufficient_stock_is_rejected_persisted_and_leaves_stock_intact(): void
    {
        $product = Product::factory()->withStock(2)->create();

        $response = $this->reserve([
            'request_id' => 'req-reject-1',
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.remaining_stock', 2);

        $this->assertSame(2, $product->fresh()->stock, 'el stock no se toca en un rechazo');
        $this->assertDatabaseHas('reservations', [
            'request_id' => 'req-reject-1',
            'status' => ReservationStatus::Rejected->value,
        ]);
    }

    public function test_missing_product_returns_404_not_422(): void
    {
        $this->reserve([
            'request_id' => 'req-404',
            'product_id' => 999999,
            'quantity' => 1,
        ])->assertNotFound()->assertJsonPath('error.code', 'product_not_found');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_identical_replay_returns_200_with_header_and_no_side_effects(): void
    {
        $product = Product::factory()->withStock(10)->create();
        $payload = ['request_id' => 'req-replay', 'product_id' => $product->id, 'quantity' => 4];

        $first = $this->reserve($payload)->assertCreated();
        $stockAfterFirst = $product->fresh()->stock;

        $second = $this->reserve($payload);

        $second->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($stockAfterFirst, $product->fresh()->stock, 'el replay no vuelve a mover stock');
    }

    public function test_replay_rejected_reservation_stays_rejected_and_stock_intact(): void
    {
        $product = Product::factory()->withStock(2)->create();
        $payload = ['request_id' => 'req-replay-rejected', 'product_id' => $product->id, 'quantity' => 5];

        $first = $this->reserve($payload)
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.remaining_stock', 2);

        // Acá repongo el stock para que el replay pueda ser confirmado si no se maneja correctamente la idempotencia.
        // Si la implementación reevalua en el replay, confirmaría la reserva, movería stock y el test ser caería aquí.
        $product->update(['stock' => 20]);

        $this->reserve($payload)
            ->assertStatus(409)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.remaining_stock', 20);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(20, $product->fresh()->stock, 'el replay no toca el stock');
    }

    public function test_same_request_id_with_different_payload_conflicts(): void
    {
        $product = Product::factory()->withStock(10)->create();

        $this->reserve(['request_id' => 'req-conflict', 'product_id' => $product->id, 'quantity' => 1])
            ->assertCreated();

        $this->reserve(['request_id' => 'req-conflict', 'product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(9, $product->fresh()->stock);
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_rejects_bad_payloads(array $payload): void
    {
        Product::factory()->withStock(10)->create(['id' => 1]);

        $this->reserve($payload)->assertStatus(422);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'sin request_id' => [['product_id' => 1, 'quantity' => 1]],
            'request_id demasiado largo' => [['request_id' => str_repeat('x', 65), 'product_id' => 1, 'quantity' => 1]],
            'quantity cero' => [['request_id' => 'a', 'product_id' => 1, 'quantity' => 0]],
            'quantity negativa' => [['request_id' => 'a', 'product_id' => 1, 'quantity' => -3]],
            'quantity no entera' => [['request_id' => 'a', 'product_id' => 1, 'quantity' => 'many']],
            'sin product_id' => [['request_id' => 'a', 'quantity' => 1]],
        ];
    }
}
