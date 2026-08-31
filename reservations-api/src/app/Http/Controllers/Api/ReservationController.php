<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Services\ReservationStrategyFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationStrategyFactory $strategies) {}

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $strategy = $this->strategies->make(
            $this->strategyOverride($request),
            $this->raceDelayOverride($request),
        );

        $result = $strategy->reserve(
            $request->validated('request_id'),
            (int) $request->validated('product_id'),
            (int) $request->validated('quantity'),
        );

        $reservation = $result->reservation;

        $status = match (true) {
            $reservation->status === ReservationStatus::Rejected => Response::HTTP_CONFLICT, // 409, sin stock
            $result->wasReplay => Response::HTTP_OK,                                          // 200, idempotente
            default => Response::HTTP_CREATED,                                                // 201, nueva
        };

        $response = ReservationResource::make($reservation)
            ->response()
            ->setStatusCode($status);

        if ($result->wasReplay) {
            $response->header('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * Seam de pruebas: forzar la estrategia por cabecera SOLO fuera de
     * produccion, para que el arnes de concurrencia ejercite la implementacion
     * naive sobre HTTP real sin reiniciar php-fpm. En produccion se ignora y
     * manda config/reservations.php.
     */
    private function strategyOverride(Request $request): ?string
    {
        if (app()->isProduction()) {
            return null;
        }

        return $request->header('X-Reservation-Strategy') ?: null;
    }

    private function raceDelayOverride(Request $request): ?int
    {
        if (app()->isProduction()) {
            return null;
        }

        $value = $request->header('X-Reservation-Race-Delay-Ms');

        return is_numeric($value) ? (int) $value : null;
    }
}
