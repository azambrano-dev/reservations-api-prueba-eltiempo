<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // unsignedInteger es la segunda capa de defensa del invariante
            // "stock nunca negativo". La primera es el bloqueo pesimista + la
            // comprobacion en el servicio; si esa logica fallara, con sql_mode
            // estricto un decremento por debajo de cero aborta con error 1264
            // en vez de hacer wrap-around a un numero enorme.
            $table->unsignedInteger('stock')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
