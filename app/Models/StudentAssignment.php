<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'student_assignments';

    protected $fillable = [
        'student_id',
        'period_id',
        'grupo_id',
        'semana_intensiva_id',
        'assigned_at',
        'valid_until',
        'is_active',
        'carrer_id',
        'notes',
        'book_delivered',
        'book_delivery_type',
        'book_delivery_date',
        'book_notes',
        'book_modulos',
        'book_general',
    ];

    protected $casts = [
        'assigned_at' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'book_delivered' => 'boolean',
        'book_delivery_date' => 'date',
        'book_modulos' => 'string',
        'book_general' => 'string',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($assignment) {
            \App\Models\StudentEvent::createEvent(
                $assignment->student_id,
                'assignment_created',
                null,
                $assignment->toArray(),
                "Nueva asignación de carrera/grupo añadida por: " . (auth()->user()?->name ?? 'Sistema')
            );
        });

        static::deleted(function ($assignment) {
            \App\Models\StudentEvent::createEvent(
                $assignment->student_id,
                'assignment_deleted',
                $assignment->toArray(),
                null,
                "Asignación de carrera/grupo eliminada por: " . auth()->user()->name,
                null,
                ['datos_eliminados' => $assignment->toArray()]
            );
        });

        static::updated(function ($assignment) {
            $original = $assignment->getOriginal();
            $changes = $assignment->getDirty();
            $user = auth()->user()?->name ?? 'Sistema';

            $descriptions = [];
            if (isset($changes['carrer_id'])) {
                $descriptions[] = "cambió de carrera";
            }
            if (isset($changes['grupo_id'])) {
                $descriptions[] = "cambió de grupo";
            }
            if (isset($changes['semana_intensiva_id'])) {
                $descriptions[] = "cambió de semana intensiva";
            }
            if (isset($changes['is_active'])) {
                $status = $changes['is_active'] ? 'activó' : 'desactivó';
                $descriptions[] = "$status la asignación";
            }

            if (empty($descriptions)) {
                $descriptions[] = "actualizó la asignación (" . implode(', ', array_keys($changes)) . ")";
            }

            $finalDesc = "Asignación de carrera/grupo: " . implode(', ', $descriptions) . " por $user";

            \App\Models\StudentEvent::createEvent(
                $assignment->student_id,
                'assignment_updated',
                $original,
                $changes,
                $finalDesc,
                array_keys($changes),
                ['datos_cambiados' => $changes]
            );
        });
    }
    /**
     * Get the student that this assignment belongs to.
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the carrer that this assignment belongs to.
     */
    public function carrera()
    {
        return $this->belongsTo(Carrera::class, 'carrer_id');
    }

    /**
     * Get the period that this assignment belongs to.
     */
    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * Get the group that this assignment belongs to.
     */
    public function grupo()
    {
        return $this->belongsTo(Grupo::class, 'grupo_id');
    }

    /**
     * Get the semana intensiva that this assignment belongs to.
     */
    public function semanaIntensiva()
    {
        return $this->belongsTo(SemanaIntensiva::class, 'semana_intensiva_id');
    }

    /**
     * Scope a query to only include active assignments.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include current assignments (not expired).
     */
    public function scopeCurrent($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('valid_until')
                ->orWhere('valid_until', '>=', now());
        });
    }
}
