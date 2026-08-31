<?php

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ProductNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope uniforme para los errores de dominio del endpoint de reservas.
        $exceptions->render(function (ProductNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(
                ['error' => ['code' => 'product_not_found', 'message' => $e->getMessage()]],
                404,
            );
        });

        $exceptions->render(function (IdempotencyConflictException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(
                ['error' => ['code' => 'idempotency_conflict', 'message' => $e->getMessage()]],
                409,
            );
        });

        // MySQL 1205 = lock wait timeout. Con attempts:1 no reintentamos: 503 y
        // que el cliente decida cuando volver a intentarlo.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*') || ($e->errorInfo[1] ?? null) !== 1205) {
                return null;
            }

            return response()->json(
                ['error' => ['code' => 'lock_timeout', 'message' => 'The product is busy, please retry later.']],
                503,
            )->header('Retry-After', '1');
        });
    })->create();
