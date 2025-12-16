<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\CashRegister;
use Illuminate\Http\Request;

class CashCutController extends Controller
{
    public function index(Request $request)
    {
        $campusId = $request->query('campus_id');
        
        $query = CashRegister::with(['transactions', 'gastos', 'campus']);
        
        if ($campusId) {
            $query->where('campus_id', $campusId);
        }
        
        $cashRegisters = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($cashRegisters);
    }

    public function show(Request $request, $id)
    {
        $cashRegister = CashRegister::with(['transactions', 'gastos', 'campus'])
            ->findOrFail($id);

        $transactions = $cashRegister->transactions->map(function ($transaction) {
            return [
                ...$transaction->toArray(),
                'denominations' => $transaction->transactionDetails->map(function ($detail) {
                    return [
                        'value' => $detail->denomination->value,
                        'type' => $detail->denomination->type,
                        'quantity' => $detail->quantity,
                    ];
                }),
            ];
        });

        $gastos = $cashRegister->gastos->map(function ($gasto) {
            return [
                ...$gasto->toArray(),
                'denominations' => $gasto->gastoDetails->map(function ($detail) {
                    return [
                        'value' => $detail->denomination->value,
                        'type' => $detail->denomination->type,
                        'quantity' => $detail->quantity,
                    ];
                }),
            ];
        });

        $response = [
            ...$cashRegister->toArray(),
            'status' => $cashRegister->status,
            'campus_id' => $cashRegister->campus_id,
            'transactions' => $transactions,
            'gastos' => $gastos,
            'summary' => $cashRegister->getTransactionsSummary(),
            'current_balance' => $cashRegister->getCurrentBalance(),
            'cash_balance' => $cashRegister->getCashBalance(),
            'is_balanced' => $cashRegister->isBalanced(),
        ];

        return response()->json($response);
    }

    public function current(Request $request, Campus $campus)
    {
        $cashRegister = CashRegister::where('campus_id', $campus->id)
            ->where('status', 'abierta')
            ->with(['transactions', 'gastos'])
            ->first();

        if (!$cashRegister) {
            return response()->json(
                ['message' => 'No hay registro de caja abierto en este campus'],
                404
            );
        }

        $transactions = $cashRegister->transactions->map(function ($transaction) {
            return [
                ...$transaction->toArray(),
                'denominations' => $transaction->transactionDetails->map(function ($detail) {
                    return [
                        'value' => $detail->denomination->value,
                        'type' => $detail->denomination->type,
                        'quantity' => $detail->quantity,
                    ];
                }),
            ];
        });

        $gastos = $cashRegister->gastos->map(function ($gasto) {
            return [
                ...$gasto->toArray(),
                'denominations' => $gasto->gastoDetails->map(function ($detail) {
                    return [
                        'value' => $detail->denomination->value,
                        'type' => $detail->denomination->type,
                        'quantity' => $detail->quantity,
                    ];
                }),
            ];
        });

        $response = [
            ...$cashRegister->toArray(),
            'status' => $cashRegister->status,
            'campus_id' => $cashRegister->campus_id,
            'transactions' => $transactions,
            'gastos' => $gastos,
            'summary' => $cashRegister->getTransactionsSummary(),
            'current_balance' => $cashRegister->getCurrentBalance(),
            'cash_balance' => $cashRegister->getCashBalance(),
            'is_balanced' => $cashRegister->isBalanced(),
        ];

        return response()->json($response);
    }


    public function store(Request $request)
{
    $validated = $request->validate([
        'campus_id' => 'required|exists:campuses,id',
        'initial_amount' => 'required|numeric|min:0',
        'initial_amount_cash' => 'nullable|array',
        'notes' => 'nullable|string|max:255',
        'status' => 'required|in:abierta,cerrada',
        'next_day' => 'nullable',
    ]);

    // Validar que no haya otra caja abierta para este campus
    if ($validated['status'] === 'abierta') {
        $existingOpenCashRegister = CashRegister::getActiveByCampus($validated['campus_id']);
        if ($existingOpenCashRegister) {
            return response()->json([
                'message' => 'Ya existe una caja abierta para este campus. Debe cerrarla antes de abrir una nueva.',
                'cash_register_id' => $existingOpenCashRegister->id
            ], 422);
        }
    }

    // Siempre usar el monto final de la última caja cerrada
    $latestCashRegister = CashRegister::where('campus_id', $validated['campus_id'])
        ->where('status', 'cerrada')
        ->latest('closed_at')
        ->first();

    if ($latestCashRegister && $latestCashRegister->final_amount !== null) {
        // Usar el monto final y denominaciones de la última caja cerrada
        $validated['initial_amount'] = $latestCashRegister->final_amount;
        $validated['initial_amount_cash'] = is_string($latestCashRegister->final_amount_cash) 
            ? json_decode($latestCashRegister->final_amount_cash, true)
            : $latestCashRegister->final_amount_cash;
    } else {
        // Si no hay caja anterior o no tiene final_amount, inicializar en 0
        $validated['initial_amount'] = 0;
        $validated['initial_amount_cash'] = [
            "5" => 0,
            "10" => 0,
            "20" => 0,
            "50" => 0,
            "100" => 0,
            "200" => 0,
            "500" => 0,
            "1000" => 0
        ];
    }

    $initialAmountCash = json_encode($validated['initial_amount_cash']);

    $cashRegister = CashRegister::create([
        'campus_id' => $validated['campus_id'],
        'initial_amount' => $validated['initial_amount'],
        'initial_amount_cash' => $initialAmountCash,
        'notes' => $validated['notes'] ?? null,
        'opened_at' => now(),
        'status' => $validated['status'],
        'closed_at' => $validated['status'] === 'cerrada' ? now() : null,
    ]);

    return response()->json($cashRegister, 201);
}

    public function update(Request $request, CashRegister $cashRegister)
    {
        $validated = $request->validate([
            'final_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
            'status' => 'required|in:abierta,cerrada',
            'final_amount_cash' => 'nullable|array',
            'next_day' => 'nullable|numeric',
            'next_day_cash' => 'nullable|array',
        ]);

        // Validar que la caja esté abierta antes de cerrarla
        if ($validated['status'] === 'cerrada' && $cashRegister->status === 'cerrada') {
            return response()->json([
                'message' => 'Esta caja ya está cerrada.'
            ], 422);
        }

        $finalAmountCash = isset($validated['final_amount_cash'])
            ? (is_string($validated['final_amount_cash']) 
                ? $validated['final_amount_cash'] 
                : json_encode($validated['final_amount_cash']))
            : null;

        $nextDayCash = isset($validated['next_day_cash'])
            ? (is_string($validated['next_day_cash']) 
                ? $validated['next_day_cash'] 
                : json_encode($validated['next_day_cash']))
            : null;

        $cashRegister->update([
            'next_day' => $validated['next_day'] ?? null,
            'next_day_cash' => $nextDayCash,
            'final_amount' => $validated['final_amount'],
            'notes' => $validated['notes'] ?? $cashRegister->notes,
            'final_amount_cash' => $finalAmountCash,
            'status' => $validated['status'],
            'closed_at' => $validated['status'] === 'cerrada' ? now() : null,
        ]);

        return response()->json($cashRegister->fresh(), 200);
    }
}
