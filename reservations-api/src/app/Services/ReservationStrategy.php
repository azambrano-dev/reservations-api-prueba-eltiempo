<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ProductNotFoundException;

interface ReservationStrategy
{
    /**
     * Reserva $quantity unidades de $productId de forma idempotente por $requestId.
     *
     * Reintentar con el mismo $requestId y el mismo payload devuelve siempre el
     * mismo resultado (wasReplay = true) sin volver a mover stock.
     *
     * @throws ProductNotFoundException si el producto no existe
     * @throws IdempotencyConflictException si $requestId ya se uso con otro payload
     */
    public function reserve(string $requestId, int $productId, int $quantity): ReservationResult;
}
