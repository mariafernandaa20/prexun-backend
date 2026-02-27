<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = [
        'campus_id',
        'name',
        'color',
        'is_favorite',
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_tag');
    }
}
