<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);

        return [
            'request_id' => (string) Str::uuid(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'remaining_stock' => fake()->numberBetween(0, 100),
            'status' => ReservationStatus::Confirmed,
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => ReservationStatus::Rejected]);
    }
}
