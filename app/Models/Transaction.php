<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
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
        'folio_sat'
    ];


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
}
