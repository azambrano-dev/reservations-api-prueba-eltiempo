<?php

namespace App\Services;

use InvalidArgumentException;

final class ReservationStrategyFactory
{
    /**
     * @param  string|null  $name  atomic|naive; null => config('reservations.strategy')
     * @param  int|null  $raceDelayMs  solo aplica a 'naive'; null => config('reservations.race_delay_ms')
     */
    public function make(?string $name = null, ?int $raceDelayMs = null): ReservationStrategy
    {
        $name ??= (string) config('reservations.strategy', 'atomic');

        return match ($name) {
            'atomic' => new AtomicReservationService,
            'naive' => new NaiveReservationService(
                $raceDelayMs ?? (int) config('reservations.race_delay_ms', 0),
            ),
            default => throw new InvalidArgumentException("Unknown reservation strategy [{$name}]."),
        };
    }
}
