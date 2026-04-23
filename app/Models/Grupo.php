<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grupo extends Model
{
    protected $fillable = [
        'name',
        'type',
        'plantel_id',
        'period_id',
        'capacity',
        'frequency',
        'start_time',
        'end_time',
        'start_date',
        'end_date',
        'moodle_id',
        'contacto_prefijo'
    ];

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function student()
    {
        return $this->hasMany(Student::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }
     
    
    public function campuses()
    {
        return $this->belongsToMany(Campus::class, 'campus_group_pivot', 'grupo_id', 'campus_id');
    }

    /**
     * Get all student assignments for this group.
     */
    public function studentAssignments()
    {
        return $this->hasMany(StudentAssignment::class, 'grupo_id');
    }

    /**
     * Get only active and current student assignments for this group.
     */
    public function activeAssignments()
    {
        return $this->hasMany(StudentAssignment::class, 'grupo_id')
                   ->active()
                   ->current();
    }

    /**
     * Get the count of active assignments (students currently assigned to this group).
     */
    public function getActiveAssignmentsCountAttribute()
    {
        return $this->activeAssignments()->count();
    }

    /**
     * Get available slots based on active assignments.
     */
    public function getAvailableSlotsAttribute()
    {
        return $this->capacity - $this->active_assignments_count;
    }

    /**
     * Check if the group is almost full (3 or fewer slots available).
     */
    public function getIsAlmostFullAttribute()
    {
        return $this->available_slots <= 3;
    }

    /**
     * Check if the group is full (no slots available).
     */
    public function getIsFullAttribute()
    {
        return $this->available_slots <= 0;
    }

    /**
     * Get students that are currently assigned to this group through active assignments.
     */
    public function getAssignedStudentsAttribute()
    {
        return $this->activeAssignments->map(function ($assignment) {
            return $assignment->student;
        });
    }
    protected $casts = [
        'contacto_prefijo' => 'boolean'
    ];
}
