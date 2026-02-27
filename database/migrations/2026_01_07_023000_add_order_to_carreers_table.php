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
        Schema::table('carreers', function (Blueprint $table) {
            // Agregamos la columna 'orden' para enumerar las carreras (1, 2, 3...)
            // unique() asegura que no haya dos carreras con el mismo número
            $table->integer('orden')->nullable()->unique()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carreers', function (Blueprint $table) {
            $table->dropColumn('orden');
        });
    }
};
