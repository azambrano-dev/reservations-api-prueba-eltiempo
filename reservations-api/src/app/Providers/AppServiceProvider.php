<?php

namespace App\Providers;

use App\Services\ReservationStrategy;
use App\Services\ReservationStrategyFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ReservationStrategyFactory::class);

        // Binding por defecto: la estrategia elegida en config/reservations.php.
        // El controlador puede pedir otra explicitamente a la factory (seam de
        // pruebas por cabecera, solo fuera de produccion).
        $this->app->bind(
            ReservationStrategy::class,
            fn ($app) => $app->make(ReservationStrategyFactory::class)->make(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
