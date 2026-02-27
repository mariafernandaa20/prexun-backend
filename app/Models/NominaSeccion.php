<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NominaSeccion extends Model
{
    use HasFactory;

    protected $table = 'nomina_secciones';

    protected $fillable = [
        'nombre',
        'fecha_subida',
    ];

    protected $casts = [
        'fecha_subida' => 'datetime',
    ];

    public function nominas(): HasMany
    {
        return $this->hasMany(Nomina::class, 'seccion_id');
    }
}
