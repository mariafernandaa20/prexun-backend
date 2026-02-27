<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';

    protected $fillable = [
        'student_id',
        'campus_id',
        'transaction_type',
        'cash_register_id',
        'amount',
        'paid',
        'payment_date',
        'expiration_date',
        'payment_method',
        'denominations',
        'notes',
        'uuid',
        'folio',
        'image',
        'card_id',
        'sat',
        'folio_sat',
        'folio_new',
        'folio_cash',
        'folio_card',
        'debt_id'
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'expiration_date' => 'date',
        'paid' => 'boolean',
        'sat' => 'boolean',
    ];
    protected static function boot()
    {
        parent::boot();

        static::created(function ($transaction) {
            \App\Models\StudentEvent::createEvent(
                $transaction->student_id,
                'payment_created',
                null,
                $transaction->toArray(),
                "Pago añadido de $" . $transaction->amount . " por el usuario: " . (auth()->user()?->name ?? 'Sistema')
            );
        });

        static::updated(function ($transaction) {
            $changes = $transaction->getDirty();
            $original = $transaction->getOriginal();
            $user = auth()->user()?->name ?? 'Sistema';

            if (array_key_exists('image', $changes) && is_null($changes['image'])) {
                $desc = "El usuario " . $user . " retiró la imagen del pago";
            } elseif (isset($changes['image'])) {
                $desc = "El usuario " . $user . " adjuntó una nueva imagen al pago";
            } else {
                $campos = [];
                if (isset($changes['amount']))
                    $campos[] = "monto ($" . $original['amount'] . " -> $" . $changes['amount'] . ")";
                if (isset($changes['folio']))
                    $campos[] = "folio (" . ($original['folio'] ?? 'N/A') . " -> " . $changes['folio'] . ")";
                if (isset($changes['payment_method']))
                    $campos[] = "método de pago";

                if (empty($campos)) {
                    $campos = array_keys($changes);
                }

                $desc = "Pago editado (" . implode(', ', $campos) . ") por: " . $user;
            }

            \App\Models\StudentEvent::createEvent(
                $transaction->student_id,
                'payment_updated',
                $original,
                $transaction->toArray(),
                $desc,
                array_keys($changes)
            );
        });
    }


    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function card()
    {
        return $this->belongsTo(Card::class, 'card_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function transactionDetails()
    {
        return $this->hasMany(TransactionDetail::class);
    }
    public function denominations()
    {
        return $this->hasMany(Denomination::class);
    }
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function debt()
    {
        return $this->belongsTo(Debt::class);
    }
}
