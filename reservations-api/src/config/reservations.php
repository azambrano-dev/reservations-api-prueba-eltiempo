<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Estrategia de reserva
    |--------------------------------------------------------------------------
    |
    | 'atomic' -> SELECT ... FOR UPDATE + transaccion. Es la implementacion
    |             correcta y la que debe correr siempre en produccion.
    | 'naive'  -> lee, espera, escribe. Incorrecta a proposito; solo para que
    |             el arnes de concurrencia demuestre la sobreventa.
    |
    */
    'strategy' => env('RESERVATION_STRATEGY', 'atomic'),

    /*
    |--------------------------------------------------------------------------
    | Retardo artificial lectura->escritura (ms)
    |--------------------------------------------------------------------------
    |
    | Solo lo usa NaiveReservationService. Ensancha la ventana de carrera para
    | hacer la sobreventa reproducible al 100%. 0 = sin retardo.
    |
    */
    'race_delay_ms' => (int) env('RESERVATION_RACE_DELAY', 0),

];
