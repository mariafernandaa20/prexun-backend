<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nomina_secciones', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('nombre');
            $blueprint->timestamp('fecha_subida')->useCurrent();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nomina_secciones');
    }
};
