<?php

namespace App\Services;

use App\Models\Reservation;

final class ReservationResult
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly bool $wasReplay,
    ) {}
}
