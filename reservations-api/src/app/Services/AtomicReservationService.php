<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reserva de stock atomica e idempotente. El detalle de las decisiones
 * (lock pesimista, attempts: 1, manejo del duplicado) esta en README.md y
 * prompts.md.
 */
final class AtomicReservationService implements ReservationStrategy
{
    public function reserve(string $requestId, int $productId, int $quantity): ReservationResult
    {
        try {
            $reservation = DB::transaction(function () use ($requestId, $productId, $quantity) {
                // Lock exclusivo de la fila hasta el COMMIT: $stock sigue siendo
                // el valor real cuando se escribe la reserva.
                $product = Product::query()->lockForUpdate()->find($productId);

                if ($product === null) {
                    throw new ProductNotFoundException($productId);
                }

                $stock = (int) $product->stock;

                if ($stock >= $quantity) {
                    $remaining = $stock - $quantity;

                    $product->stock = $remaining;
                    $product->saveOrFail();

                    $status = ReservationStatus::Confirmed;
                } else {
                    // Stock insuficiente: el producto no se toca.
                    $remaining = $stock;
                    $status = ReservationStatus::Rejected;
                }

                return Reservation::create([
                    'request_id' => $requestId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'remaining_stock' => $remaining,
                    'status' => $status,
                ]);
            }, attempts: 1);

            return new ReservationResult($reservation, wasReplay: false);
        } catch (UniqueConstraintViolationException) {
            // request_id ya usado: reintento del cliente o carrera perdida.
            // Se resuelve fuera de la transaccion abortada.
            return $this->replay($requestId, $productId, $quantity);
        }
    }

    private function replay(string $requestId, int $productId, int $quantity): ReservationResult
    {
        $existing = Reservation::query()->where('request_id', $requestId)->firstOrFail();

        if ((int) $existing->product_id !== $productId || (int) $existing->quantity !== $quantity) {
            throw new IdempotencyConflictException($requestId);
        }

        return new ReservationResult($existing, wasReplay: true);
    }
}
