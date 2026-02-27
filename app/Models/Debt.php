<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Debt extends Model
{
    protected $fillable = [
        'student_id',
        'period_id',
        'assignment_id',
        'concept',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'due_date',
        'status',
        'description'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'due_date' => 'date'
    ];
    protected static function boot()
    {
        parent::boot();


        static::created(function ($debt) {
            \App\Models\StudentEvent::createEvent(
                $debt->student_id,
                'debt_created',
                null,
                $debt->toArray(),
                "Deuda añadida de $" . $debt->total_amount . " por el usuario: " . (auth()->user()?->name ?? 'Sistema')
            );
        });


        static::updated(function ($debt) {
            $changes = $debt->getDirty();
            $original = $debt->getOriginal();

            if (isset($changes['status']) && $changes['status'] === 'paid') {
                \App\Models\StudentEvent::createEvent(
                    $debt->student_id,
                    'debt_paid',
                    $original,
                    $debt->toArray(),
                    "Deuda marcada como pagada por: " . (auth()->user()?->name ?? 'Sistema'),
                    ['status']
                );
            } else {
                \App\Models\StudentEvent::createEvent(
                    $debt->student_id,
                    'debt_updated',
                    $original,
                    $debt->toArray(),
                    "Deuda actualizada por: " . (auth()->user()?->name ?? 'Sistema'),
                    array_keys($changes)
                );
            }
        });


        static::deleted(function ($debt) {
            \App\Models\StudentEvent::createEvent(
                $debt->student_id,
                'debt_deleted',
                $debt->toArray(),
                null,
                "Deuda eliminada por: " . (auth()->user()?->name ?? 'Sistema')
            );
        });
    }
    // Relaciones
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentAssignment::class, 'assignment_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // Métodos de utilidad
    public function updatePaymentStatus(): void
    {
        $this->paid_amount = $this->transactions()->where('paid', true)->sum('amount');
        $this->remaining_amount = $this->total_amount - $this->paid_amount;

        if ($this->remaining_amount <= 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        } else {
            $this->status = now()->gt($this->due_date) ? 'overdue' : 'pending';
        }

        $this->save();
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && now()->gt($this->due_date);
    }

    public function getPaymentPercentageAttribute(): float
    {
        if ($this->total_amount == 0) {
            return 0;
        }

        return round(($this->paid_amount / $this->total_amount) * 100, 2);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForPeriod($query, $periodId)
    {
        return $query->where('period_id', $periodId);
    }
}