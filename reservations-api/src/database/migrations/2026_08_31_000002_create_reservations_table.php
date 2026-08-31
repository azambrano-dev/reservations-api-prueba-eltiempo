<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // Identificador opaco provisto por el cliente. El indice UNIQUE es
            // TODO el mecanismo de idempotencia: dos peticiones con el mismo
            // request_id no pueden crear dos filas; la segunda choca contra el
            // constraint y esa violacion es la senal que el servicio interpreta
            // como "replay".
            $table->string('request_id', 64)->unique();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Snapshot de auditoria del stock DESPUES de aplicar esta reserva
            // (en las rechazadas, el stock real sin tocar). No es autoritativo:
            // nada lo lee para decidir disponibilidad, solo sirve para inspeccion
            // y para los asserts del arnes de concurrencia.
            $table->unsignedInteger('remaining_stock');

            $table->string('status', 16);

            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
