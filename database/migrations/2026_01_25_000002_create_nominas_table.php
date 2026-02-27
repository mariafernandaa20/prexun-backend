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
        Schema::create('nominas', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $blueprint->foreignId('seccion_id')->constrained('nomina_secciones')->onDelete('cascade');
            $blueprint->string('archivo_original_path');
            $blueprint->string('archivo_firmado_path')->nullable();
            $blueprint->enum('estado', ['pendiente', 'firmado'])->default('pendiente');
            $blueprint->timestamp('fecha_firma')->nullable();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nominas');
    }
};
