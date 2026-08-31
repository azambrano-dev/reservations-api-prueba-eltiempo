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

        // Contencion de InnoDB que el cliente puede resolver reintentando:
        //   1205 = lock wait timeout (innodb_lock_wait_timeout, 10s en compose).
        //   1213 = deadlock. El analisis dice que no puede darse con el esquema
        //          actual, pero attempts:1 no deja red: un cambio de esquema
        //          (reservas multilinea, alta de productos, un FOR UPDATE sobre
        //          indice secundario) podria reintroducirlo. Mismo remedio de
        //          cliente que el 1205, asi que mismo 503 + Retry-After en vez
        //          de un 500 sin diagnostico. No se sube attempts: reintentar
        //          tambien absorberia el 1205, justo lo que se quiere evitar.
        $exceptions->render(function (QueryException $e, Request $request) {
            $code = $e->errorInfo[1] ?? null;

            if (! $request->is('api/*') || ! in_array($code, [1205, 1213], true)) {
                return null;
            }

            return response()->json(
                ['error' => [
                    'code' => $code === 1213 ? 'deadlock' : 'lock_timeout',
                    'message' => 'The product is busy, please retry later.',
                ]],
                503,
            )->header('Retry-After', '1');
        });
    })->create();
