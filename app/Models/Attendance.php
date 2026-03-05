<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'student_id',
        'grupo_id',
        'date',
        'present',
        'user_id',
        'attendance_time',
        'class_marks',
        'notes'
    ];

    protected $casts = [
        'date' => 'date',
        'present' => 'boolean',
        'attendance_time' => 'datetime',
        'class_marks' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}