<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * La optimizacion de servidores de Minecraft se elimino de la extension: se
 * borra su tabla en las instalaciones que la crearon (v1.0.x / v1.1.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('antiddos_optimizations');
    }

    public function down(): void
    {
        // No se recrea: la funcion ya no existe.
    }
};
