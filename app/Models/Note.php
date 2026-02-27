<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    protected $fillable = [
        'student_id',
        'user_id',
        'text',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($note) {
            \App\Models\StudentEvent::createEvent(
                $note->student_id,
                'note_created',
                null,
                $note->toArray(),
                "Nota añadida por: " . (auth()->user()?->name ?? 'Sistema')
            );
        });

        static::updated(function ($note) {
            \App\Models\StudentEvent::createEvent(
                $note->student_id,
                'note_updated',
                $note->getOriginal(),
                $note->toArray(),
                "Nota editada por: " . (auth()->user()?->name ?? 'Sistema'),
                ['text']
            );
        });

        static::deleted(function ($note) {
            \App\Models\StudentEvent::createEvent(
                $note->student_id,
                'note_deleted',
                $note->toArray(),
                null,
                "Nota eliminada por: " . (auth()->user()?->name ?? 'Sistema')
            );
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
