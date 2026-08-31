<?php

use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::post('reservations', [ReservationController::class, 'store'])
    ->name('reservations.store');
