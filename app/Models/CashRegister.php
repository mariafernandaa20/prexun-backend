<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegister extends Model
{
    protected $fillable = [
        'initial_amount',
        'initial_amount_cash',
        'final_amount',
        'final_amount_cash',
        'next_day',
        'next_day_cash',
        'opened_at',
        'closed_at',
        'campus_id',
        'consecutivo',
        'notes',
        'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'initial_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'cash_register_id');
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'abierta');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'cerrada');
    }

    // Obtener la caja activa de un campus
    public static function getActiveByCampus($campusId)
    {
        return self::where('campus_id', $campusId)
            ->where('status', 'abierta')
            ->first();
    }

    public function getCurrentBalance()
    {
        $incomingTotal = $this->transactions()
            ->where('transaction_type', 'income')
            ->where('paid', true)
            ->where('payment_method', 'cash')
            ->sum('amount');

        $outgoingTotal = $this->gastos()
            ->whereIn('method', ['cash', 'Efectivo'])
            ->sum('amount');

        return $this->initial_amount + $incomingTotal - $outgoingTotal;
    }

    public function getDifference()
    {
        if (!$this->final_amount) {
            return null;
        }

        return $this->final_amount - $this->getCurrentBalance();
    }

    public function close($finalAmount, $notes = null)
    {
        $this->update([
            'final_amount' => $finalAmount,
            'closed_at' => now(),
            'status' => 'cerrada',
            'notes' => $notes
        ]);
    }

    public function getTransactionsSummary()
    {
        return [
            'total_income' => $this->transactions()
                ->where('transaction_type', 'income')
                ->where('paid', true)
                ->sum('amount'),
            'total_cash_income' => $this->transactions()
                ->where('transaction_type', 'income')
                ->where('paid', true)
                ->where('payment_method', 'cash')
                ->sum('amount'),
            'total_expenses' => $this->gastos()->sum('amount'),
            'total_cash_expenses' => $this->gastos()->whereIn('method', ['cash', 'Efectivo'])->sum('amount'),
            'total_transactions' => $this->transactions()->count()
        ];
    }

    public function getDenominationsSummary()
    {
        return $this->transactions()
            ->with('transactionDetails.denomination')
            ->get()
            ->pluck('transactionDetails')
            ->flatten()
            ->groupBy('denomination_id')
            ->map(function ($details) {
                $denomination = $details->first()->denomination;
                return [
                    'value' => $denomination->value,
                    'type' => $denomination->type,
                    'total_quantity' => $details->sum('quantity'),
                    'total_amount' => $denomination->value * $details->sum('quantity')
                ];
            });
    }

    public function getCashBalance()
    {
        $cashTransactions = $this->transactions()
            ->where('payment_method', 'cash')
            ->where('paid', true)
            ->sum('amount');

        $cashExpenses = $this->gastos()
            ->whereIn('method', ['cash', 'Efectivo'])
            ->sum('amount');

        return $this->initial_amount + $cashTransactions - $cashExpenses;
    }

    public function isBalanced()
    {
        $expectedAmount = $this->getCashBalance();
        $tolerance = 0.01;
        
        return $this->final_amount 
            ? abs($this->final_amount - $expectedAmount) <= $tolerance
            : null;
    }
}