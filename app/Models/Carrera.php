<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Carrera extends Model
{
    protected $table = 'carreers';
    public $timestamps = false;
    protected $fillable = ['name', 'facultad_id', 'orden'];

    public function assignments()
    {
        return $this->hasMany(StudentAssignment::class, 'carrer_id');
    }

    public function facultad()
    {
        return $this->belongsTo(Facultad::class);
    }
    
    public function modulos()
    {
        return $this->belongsToMany(Modulo::class, 'carrer_modulo', 'carrer_id', 'modulo_id');
    }
}