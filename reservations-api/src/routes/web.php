<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Reservations API - El Tiempo',
        'version' => '1.0.0',
    ]);
});
