<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 
        'code', 
        'description', 
        'address', 
        'is_active',
        'folio_inicial',
        'titular',
        'grupo_ids' 
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'campus_user');

    }

    public function latestCashRegister()
    {
        return $this->hasOne(CashRegister::class)
                    ->where('status', 'abierta')
                    ->latest();
    }

    public function cashRegisters()
    {
        return $this->hasMany(CashRegister::class);
    }

    public function caja()
    {
        return $this->hasOne(CashRegister::class)
                    ->where('status', 'abierta')
                    ->latest();
    }

    public function grupos()
    {
        return $this->belongsToMany(Grupo::class, 'campus_group_pivot', 'campus_id', 'grupo_id');
    }

    public function semanasIntensivas()
    {
        return $this->belongsToMany(SemanaIntensiva::class, 'campus_semana_intensiva_pivot', 'campus_id', 'semana_intensiva_id');
    }
}
