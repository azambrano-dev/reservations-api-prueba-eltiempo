<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Implementacion DELIBERADAMENTE incorrecta.
 *
 * Lee el stock, espera, y luego escribe -- sin bloqueo y sin transaccion. Dos
 * peticiones concurrentes leen el mismo stock y ambas lo decrementan sobre el
 * mismo valor base (last-write-wins), produciendo sobreventa.
 *
 * Existe SOLO para que el arnes `reservations:stress --strategy=naive` pueda
 * demostrar el fallo que AtomicReservationService evita. No usar en produccion.
 * El binding por defecto (config reservations.strategy) es 'atomic'.
 */
final class NaiveReservationService implements ReservationStrategy
{
    public function __construct(private readonly int $raceDelayMs = 0) {}

    public function reserve(string $requestId, int $productId, int $quantity): ReservationResult
    {
        try {
            $product = Product::query()->find($productId); // sin lockForUpdate

            if ($product === null) {
                throw new ProductNotFoundException($productId);
            }

            $stock = (int) $product->stock;

            // Ensancha la ventana entre lectura y escritura: es justo aqui donde
            // otra peticion lee el mismo $stock antes de que esta lo decremente.
            if ($this->raceDelayMs > 0) {
                usleep($this->raceDelayMs * 1000);
            }

            if ($stock >= $quantity) {
                $remaining = $stock - $quantity;
                $product->stock = $remaining; // pisa el decremento de la otra peticion
                $product->save();
                $status = ReservationStatus::Confirmed;
            } else {
                $remaining = $stock;
                $status = ReservationStatus::Rejected;
            }

            $reservation = Reservation::create([
                'request_id' => $requestId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'remaining_stock' => $remaining,
                'status' => $status,
            ]);

            return new ReservationResult($reservation, wasReplay: false);
        } catch (UniqueConstraintViolationException) {
            $existing = Reservation::query()->where('request_id', $requestId)->firstOrFail();

            return new ReservationResult($existing, wasReplay: true);
        }
    }
}
