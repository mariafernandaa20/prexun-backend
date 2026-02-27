<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\Denomination;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Traits\GeneratesFolios;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
  use GeneratesFolios;

  public function index(Request $request)
  {
    $campus_id = $request->campus_id;
    $perPage = (int) $request->query('per_page', 10);
    $page = (int) $request->query('page', 1);
    $search = $request->query('search');
    $payment_method = $request->query('payment_method');
    $card_id = $request->query('card_id');
    $folio = $request->query('folio');
    $sortBy = $request->query('sort_by', 'folio');
    $sortDirection = $request->query('sort_direction', 'desc');
    $dateFrom = $request->query('date_from');
    $dateTo = $request->query('date_to');
    $groupByMonth = $request->query('group_by_month', false);

    $allowedSorts = ['folio', 'created_at', 'payment_date'];
    $allowedDirections = ['asc', 'desc'];
    
    if (!in_array($sortBy, $allowedSorts)) {
      $sortBy = 'folio';
    }
    
    if (!in_array($sortDirection, $allowedDirections)) {
      $sortDirection = 'desc';
    }

    $query = Transaction::with(['student', 'campus', 'student.grupo', 'card'])
      ->where('campus_id', $campus_id)
      ->where('paid', true);

    // Filtro de búsqueda por nombre de estudiante
    if ($search) {
      $query->whereHas('student', function ($q) use ($search) {
        $searchTerms = explode(' ', trim($search));
        foreach ($searchTerms as $term) {
          $q->where(function ($subQuery) use ($term) {
            $subQuery->where('firstname', 'LIKE', '%' . $term . '%')
              ->orWhere('lastname', 'LIKE', '%' . $term . '%')
              ->orWhere('username', 'LIKE', '%' . $term . '%');
          });
        }
      });
    }

    // Filtro por folio
    if ($folio) {
      $query->where(function ($q) use ($folio) {
        $q->where('folio', 'LIKE', $folio . '%' )
          ->orWhere('folio_new', 'LIKE', $folio . '%')
          ->orWhere('folio_cash', 'LIKE', $folio . '%')
          ->orWhere('folio_transfer', 'LIKE', $folio . '%')
          ->orWhere('folio_card', 'LIKE', $folio . '%');
      });
    }

    // Filtro por método de pago
    if ($payment_method && $payment_method !== 'all') {
      $query->where('payment_method', $payment_method);
    }

    // Filtro por tarjeta específica
    if ($card_id && $card_id !== 'all') {
      $query->where('card_id', $card_id);
    }

    // Filtro por rango de fechas
    if ($dateFrom) {
      $query->whereDate('payment_date', '>=', $dateFrom);
    }
    
    if ($dateTo) {
      $query->whereDate('payment_date', '<=', $dateTo);
    }

    // Agrupación por mes si está habilitada
    if ($groupByMonth) {
      $query->selectRaw('*, YEAR(payment_date) as year, MONTH(payment_date) as month')
            ->orderByRaw('YEAR(payment_date) ' . $sortDirection . ', MONTH(payment_date) ' . $sortDirection);
    }

    $query->orderBy($sortBy, $sortDirection);
    
    if ($sortBy !== 'folio') {
      $query->orderBy('folio', 'desc');
    }

    $transactions = $query->paginate($perPage, ['*'], 'page', $page);

    $transactions->getCollection()->each(function ($transaction) {
      if ($transaction->image) {
        $transaction->image = asset('storage/' . $transaction->image);
      }
    });

    $response = $transactions->toArray();

    // Si está agrupado por mes, agregar información de agrupación
    if ($groupByMonth) {
      $grouped = $transactions->getCollection()->groupBy(function($transaction) {
        return Carbon::parse($transaction->payment_date)->format('Y-m');
      })->map(function($group) {
        return [
          'count' => $group->count(),
          'total' => $group->sum('amount'),
          'transactions' => $group->values()
        ];
      });
      
      $response['grouped_by_month'] = $grouped;
    }

    return response()->json($response, 200);
  }



  public function notPaid(Request $request)
  {
    $campus_id = $request->query('campus_id');
    $expiration_date = $request->query('expiration_date');
    $perPage = (int) $request->query('per_page', 10);
    $page = (int) $request->query('page', 1);
    $search = $request->query('search');
    $payment_method = $request->query('payment_method');
    $card_id = $request->query('card_id');
    $folio = $request->query('folio');
    $sortBy = $request->query('sort_by', 'expiration_date');
    $sortDirection = $request->query('sort_direction', 'asc');
    $dateFrom = $request->query('date_from');
    $dateTo = $request->query('date_to');

    $allowedSorts = ['folio', 'created_at', 'expiration_date'];
    $allowedDirections = ['asc', 'desc'];
    
    if (!in_array($sortBy, $allowedSorts)) {
      $sortBy = 'expiration_date';
    }
    
    if (!in_array($sortDirection, $allowedDirections)) {
      $sortDirection = 'asc';
    }

    if (!$campus_id) {
      return response()->json(['error' => 'campus_id is required'], 400);
    }

    $query = Transaction::with('student', 'campus', 'student.grupo', 'card')
      ->where('campus_id', $campus_id)
      ->where('paid', false);

    // Filtro de búsqueda por nombre de estudiante
    if ($search) {
      $query->whereHas('student', function ($q) use ($search) {
        $searchTerms = explode(' ', trim($search));
        foreach ($searchTerms as $term) {
          $q->where(function ($subQuery) use ($term) {
            $subQuery->where('firstname', 'LIKE', '%' . $term . '%')
              ->orWhere('lastname', 'LIKE', '%' . $term . '%')
              ->orWhere('username', 'LIKE', '%' . $term . '%');
          });
        }
      });
    }

    // Filtro por folio
    if ($folio) {
      $query->where(function ($q) use ($folio) {
        $q->where('folio', 'LIKE', '%' . $folio)
          ->orWhere('folio_new', 'LIKE', '%' . $folio . '%')
          ->orWhere('folio_cash', 'LIKE', '%' . $folio)
          ->orWhere('folio_transfer', 'LIKE', '%' . $folio)
          ->orWhere('folio_card', 'LIKE', '%' . $folio);
      });
    }

    // Filtro por método de pago
    if ($payment_method && $payment_method !== 'all') {
      $query->where('payment_method', $payment_method);
    }

    // Filtro por tarjeta específica
    if ($card_id && $card_id !== 'all') {
      $query->where('card_id', $card_id);
    }

    if ($expiration_date) {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration_date)) {
        return response()->json(['error' => 'Invalid date format (YYYY-MM-DD)'], 400);
      }
      $query->whereDate('expiration_date', $expiration_date);
    }

    // Filtro por rango de fechas
    if ($dateFrom) {
      $query->whereDate('created_at', '>=', $dateFrom);
    }
    
    if ($dateTo) {
      $query->whereDate('created_at', '<=', $dateTo);
    }

    $query->orderBy($sortBy, $sortDirection);
    
    if ($sortBy !== 'folio') {
      $query->orderBy('folio', 'desc');
    }

    $charges = $query->paginate($perPage, ['*'], 'page', $page);

    $charges->getCollection()->transform(function ($charge) {
      if ($charge->image) {
        $charge->image = asset('storage/' . $charge->image);
      }
      return $charge;
    });

    return response()->json($charges);
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'student_id' => 'required|exists:students,id',
      'campus_id' => 'required|exists:campuses,id',
      'amount' => 'required|numeric|min:0',
      'payment_method' => ['required', Rule::in(['cash', 'transfer', 'card'])],
      'transaction_type' => ['nullable', Rule::in(['income', 'payment', 'ingreso'])],
      'expiration_date' => 'nullable|date',
      'payment_date' => 'nullable|date',
      'notes' => 'nullable|string|max:255',
      'paid' => 'required|boolean',
      'debt_id' => 'nullable|exists:debts,id',
      'image' => 'nullable|image',
      'card_id' => 'nullable|exists:cards,id',
      'sat' => 'nullable|boolean',
      'cash_register_id' => 'nullable|exists:cash_registers,id',
    ]);

    if ($request->hasFile('image')) {
      $validated['image'] = $request->file('image')->store('transactions', 'public');
    }

    try {
      return DB::transaction(function () use ($validated) {
        $shouldGenerateSpecificFolio = $this->shouldGenerateSpecificFolio($validated['payment_method'], $validated['card_id'] ?? null);

        $folio = null;
        $folioNew = null;

        // Solo generar folio si la transacción está pagada
        if ($validated['paid'] && !$shouldGenerateSpecificFolio) {
          $folio = $this->generateMonthlyFolio(
            $validated['campus_id'],
            Transaction::class,
            'payment_date',
            $validated['payment_date'] ?? now()
          );
        }
        if ($validated['paid']) {
          // Generar folio nuevo con prefijo y mes/año
          $folioNew = $this->folioNew($validated['campus_id'], $validated['payment_method'], $validated['card_id'] ?? null, $validated['payment_date'] ?? now());
        }

        $transaction = Transaction::create([
          'student_id' => $validated['student_id'],
          'campus_id' => $validated['campus_id'],
          'amount' => $validated['amount'],
          'payment_method' => $validated['payment_method'],
          'notes' => $validated['notes'] ?? null,
          'paid' => $validated['paid'],
          'transaction_type' => $validated['transaction_type'] ?? ($validated['paid'] ? 'income' : 'payment'),
          'expiration_date' => $validated['expiration_date'] ?? Carbon::now()->addDays(15)->format('Y-m-d'),
          'uuid' => Str::uuid(),
          'debt_id' => $validated['debt_id'] ?? null,
          'card_id' => $validated['card_id'] ?? null,
          'folio' => $folio,
          'folio_new' => $folioNew,
          'image' => $validated['image'] ?? null,
          'payment_date' => $validated['payment_date'] ?? ($validated['paid'] ? now() : null),
          'cash_register_id' => $validated['cash_register_id'] ?? null
        ]);


        if ($validated['paid'] && $shouldGenerateSpecificFolio) {
          $paymentFolio = $this->generatePaymentMethodFolio(
            $validated['campus_id'],
            $validated['payment_method'],
            $validated['card_id'] ?? null,
            $validated['payment_date'] ?? now()
          );

          if ($paymentFolio) {
            $transaction->{$paymentFolio['column']} = $paymentFolio['value'];
            $transaction->save();
          }
        }

        if ($validated['debt_id'] && $validated['paid']) {
          $debt = \App\Models\Debt::find($validated['debt_id']);
          if ($debt) {
            $debt->updatePaymentStatus();
          }
        }

        if ($validated['payment_method'] === 'cash' && !empty($validated['denominations'])) {
          foreach ($validated['denominations'] as $value => $quantity) {
            $denomination = Denomination::firstOrCreate(
              ['value' => $value],
              ['type' => $value >= 100 ? 'billete' : 'moneda']
            );

            if ($quantity > 0) {
              TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'denomination_id' => $denomination->id,
                'quantity' => $quantity
              ]);
            }
          }
        }

        if ($transaction->image) {
          $transaction->image = asset('storage/' . $transaction->image);
        }

        return response()->json(
          $transaction->load('transactionDetails'),
          201
        );
      });
    } catch (\Exception $e) {
      return response()->json([
        'message' => 'Error al procesar la transacción',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function update($id, Request $request)
  {
    $validated = $request->validate([
      'student_id' => 'nullable|exists:students,id',
      'campus_id' => 'nullable|exists:campuses,id',
      'amount' => 'nullable|numeric|min:0',
      'payment_method' => ['nullable', Rule::in(['cash', 'transfer', 'card'])],
      'transaction_type' => ['nullable', Rule::in(['income', 'payment', 'ingreso'])],
      'notes' => 'nullable|string|max:255',
      'paid' => 'nullable|boolean',
      'cash_register_id' => 'nullable|exists:cash_registers,id',
      'payment_date' => 'nullable|date',
      'image' => 'nullable|image',
      'card_id' => 'nullable|exists:cards,id',
      'sat' => 'nullable|boolean'
    ]);

    if ($request->hasFile('image')) {
      $validated['image'] = $request->file('image')->store('transactions', 'public');
    }

    try {
      return DB::transaction(function () use ($id, $validated) {
        $transaction = Transaction::findOrFail($id);
        $oldPaid = $transaction->paid;
        $oldPaymentMethod = $transaction->payment_method;
        $oldCardId = $transaction->card_id;
        $transaction->folio_new = $this->folioNew($transaction->campus_id, $transaction->payment_method, $transaction->card_id ?? null, $transaction->payment_date ?? now());

        $transaction->update($validated);
        Log::info('Transaction updated', ['transaction' => $transaction]);
        $paymentMethodChanged = $oldPaymentMethod !== $transaction->payment_method;
        $cardChanged = $oldCardId !== $transaction->card_id;
        $paidStatusChanged = !$oldPaid && $transaction->paid;

        if ($transaction->paid) {
          $shouldGenerateSpecificFolio = $this->shouldGenerateSpecificFolio($transaction->payment_method, $transaction->card_id);

          // Si el método de pago cambió, limpiar folios específicos
          if ($paymentMethodChanged || $cardChanged) {
            $transaction->folio_transfer = null;
            $transaction->folio_cash = null;
            $transaction->folio_card = null;
          }

          if ($shouldGenerateSpecificFolio) {
            // Si cambió a método específico, limpiar folio general
            if ($paymentMethodChanged || $cardChanged) {
              $transaction->folio_new = null;
            }

            $paymentFolio = $this->generatePaymentMethodFolio(
              $transaction->campus_id,
              $transaction->payment_method,
              $transaction->card_id,
              $transaction->payment_date ?? now()
            );

            if ($paymentFolio) {
              $transaction->{$paymentFolio['column']} = $paymentFolio['value'];
            }
          } else {
            // Solo generar folio general si no tiene uno ya
            if (!$transaction->folio && !$transaction->folio_new) {
              $folio = $this->generateMonthlyFolio($transaction->campus_id);
              $transaction->folio = $folio;
            }
          }

          $transaction->folio_new = $this->folioNew($transaction->campus_id, $transaction->payment_method, $transaction->card_id ?? null, $transaction->payment_date ?? now());
          $transaction->save();
        }

        if ($oldPaid && !$transaction->paid) {
          $transaction->folio = null;
          $transaction->folio_new = null;
          $transaction->folio_transfer = null;
          $transaction->folio_cash = null;
          $transaction->folio_card = null;
          $transaction->save();
        }

        if (isset($validated['payment_method']) && $validated['payment_method'] === 'cash' && isset($validated['denominations'])) {
          $transaction->transactionDetails()->delete();

          foreach ($validated['denominations'] as $value => $quantity) {
            if ($quantity > 0) {
              $denomination = Denomination::firstOrCreate(
                ['value' => $value],
                ['type' => $value >= 100 ? 'billete' : 'moneda']
              );

              TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'denomination_id' => $denomination->id,
                'quantity' => $quantity
              ]);
            }
          }
        }

        if ($transaction->image) {
          $transaction->image = asset('storage/' . $transaction->image);
        }

        return response()->json(
          $transaction->load('transactionDetails.denomination'),
          200
        );
      });
    } catch (\Exception $e) {
      return response()->json([
        'message' => 'Error al actualizar la transacción',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function all()
  {
    $charges = Transaction::with('student', 'campus', 'student.grupo')->get();
    return response()->json($charges);
  }

  public function show($id)
  {
    $charge = Transaction::with('student', 'campus')->findOrFail($id)->load('student', 'campus', 'student.grupo');
    return $charge;
  }

  public function showByUuid($uuid)
  {
    $transaction = Transaction::with([
      'student',
      'campus',
      'student.grupo',
      'student.assignments.period',
      'student.assignments.grupo',
      'student.assignments.semanaIntensiva',
      'card',
      'debt.assignment.period',
      'debt.assignment.grupo',
      'debt.assignment.semanaIntensiva'
    ])
      ->where('uuid', $uuid)
      ->firstOrFail();

    // Agregar el folio formateado
    $transaction->display_folio = $this->getDisplayFolio($transaction);

    return $transaction;
  }

  public function updateFolio(Request $request, $id)
  {
    $request->validate([
      'folio' => 'required|integer|min:1',
    ]);

    $transaction = Transaction::findOrFail($id);

    $transaction->folio_new = $this->folioNew($transaction->campus_id, $transaction->payment_method, $transaction->card_id ?? null, $transaction->payment_date ?? now());

    $transaction->folio = $request->folio;
    $transaction->save();

    return response()->json($transaction, 200);
  }

  public function destroy($id)
  {
    $charge = Transaction::find($id);
    $charge->delete();
    return response()->json(['message' => 'Charge deleted successfully']);
  }

  public function destroyImage(Request $request, $id)
  {
    $user = $request->user();
    $allowedRoles = ['super_admin', 'contador', 'contadora'];
    if (!$user || !in_array($user->role, $allowedRoles, true)) {
      return response()->json([
        'message' => 'No tienes permisos para eliminar el comprobante'
      ], 403);
    }

    try {
      return DB::transaction(function () use ($id) {
        $transaction = Transaction::findOrFail($id);

        if (!$transaction->image) {
          return response()->json([
            'message' => 'No hay comprobante para eliminar',
            'transaction' => $transaction
          ], 200);
        }

        $imagePath = $transaction->image;

        if (preg_match('/^https?:\\/\\//i', $imagePath)) {
          $parsedUrl = parse_url($imagePath);
          $path = $parsedUrl['path'] ?? null;
          if ($path) {
            $storagePos = strpos($path, '/storage/');
            if ($storagePos !== false) {
              $imagePath = ltrim(substr($path, $storagePos + strlen('/storage/')), '/');
            }
          }
        } elseif (str_starts_with($imagePath, 'storage/')) {
          $imagePath = substr($imagePath, strlen('storage/'));
        } elseif (str_starts_with($imagePath, '/storage/')) {
          $imagePath = ltrim(substr($imagePath, strlen('/storage/')), '/');
        }

        if ($imagePath && Storage::disk('public')->exists($imagePath)) {
          Storage::disk('public')->delete($imagePath);
        }

        $transaction->image = null;
        $transaction->save();

        return response()->json([
          'message' => 'Comprobante eliminado correctamente',
          'transaction' => $transaction
        ], 200);
      });
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
      return response()->json(['message' => 'Transacción no encontrada'], 404);
    } catch (\Exception $e) {
      return response()->json([
        'message' => 'Error al eliminar el comprobante',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function importFolios(Request $request)
  {
    $request->validate([
      'file' => 'required|file|mimes:csv,txt',
      'campus_id' => 'required|exists:campuses,id'
    ]);

    $file = $request->file('file');
    $campus_id = $request->input('campus_id');

    if (!$file->isValid()) {
      return response()->json(['error' => 'Invalid file upload'], 400);
    }

    $path = $file->getRealPath();
    $records = array_map('str_getcsv', file($path));

    // Remove header row if exists
    if (isset($records[0]) && is_array($records[0]) && count($records[0]) >= 3) {
      // Check if first row looks like a header
      if (!is_numeric($records[0][0])) {
        array_shift($records);
      }
    }

    $errors = [];
    $updated = 0;
    $notFound = 0;

    try {
      DB::beginTransaction();

      foreach ($records as $index => $record) {
        $rowNum = $index + 1; // For error reporting

        // Validate record structure
        if (count($record) < 3) {
          $errors[] = "Row {$rowNum}: Invalid format, expected 3 columns";
          continue;
        }

        $oldFolio = trim($record[0]);
        $recordCampusId = trim($record[1]);
        $newFolio = trim($record[2]);

        // Validate data
        if (empty($oldFolio) || empty($recordCampusId) || empty($newFolio)) {
          $errors[] = "Row {$rowNum}: Empty values not allowed";
          continue;
        }

        if (!is_numeric($recordCampusId)) {
          $errors[] = "Row {$rowNum}: Campus ID must be numeric";
          continue;
        }

        // Only process records for the selected campus
        if ((int)$recordCampusId !== (int)$campus_id) {
          $errors[] = "Row {$rowNum}: Campus ID {$recordCampusId} doesn't match selected campus {$campus_id}";
          continue;
        }

        // Find and update the transaction
        $transaction = Transaction::where('folio', $oldFolio)
          ->where('campus_id', $campus_id)
          ->first();

        if (!$transaction) {
          $notFound++;
          $errors[] = "Row {$rowNum}: Transaction with folio {$oldFolio} not found in campus {$campus_id}";
          continue;
        }

        $transaction->folio = $newFolio;
        $transaction->save();
        $updated++;
      }

      DB::commit();

      return response()->json([
        'message' => 'Import completed',
        'updated' => $updated,
        'not_found' => $notFound,
        'errors' => $errors
      ]);
    } catch (\Exception $e) {
      DB::rollBack();
      return response()->json([
        'message' => 'Error processing CSV file',
        'error' => $e->getMessage(),
        'errors' => $errors
      ], 500);
    }
  }

  /**
   * Método para diagnosticar y reparar folios incorrectos
   * Solo para uso administrativo
   */
  public function repairFolios(Request $request)
  {
    $request->validate([
      'campus_id' => 'required|exists:campuses,id',
      'month' => 'nullable|integer|min:1|max:12',
      'year' => 'nullable|integer|min:2020|max:2030',
      'dry_run' => 'nullable|boolean'
    ]);

    $campusId = $request->campus_id;
    $month = $request->month ?? now()->month;
    $year = $request->year ?? now()->year;
    $dryRun = $request->dry_run ?? true;

    $report = [
      'campus_id' => $campusId,
      'month' => $month,
      'year' => $year,
      'dry_run' => $dryRun,
      'transactions_processed' => 0,
      'folios_fixed' => 0,
      'changes' => [],
      'errors' => []
    ];

    try {
      $transactions = Transaction::where('campus_id', $campusId)
        ->whereMonth('created_at', $month)
        ->whereYear('created_at', $year)
        ->where('paid', true)
        ->orderBy('created_at', 'asc')
        ->get();

      // Contadores para cada método de pago
      $folioCounters = [
        'cash' => 0,
        'transfer' => 0,
        'card' => 0
      ];

      foreach ($transactions as $transaction) {
        $report['transactions_processed']++;
        $needsUpdate = false;
        $originalTransaction = $transaction->toArray();

        // Verificar y corregir folio específico por método de pago
        if (in_array($transaction->payment_method, ['cash', 'transfer', 'card'])) {
          $folioColumn = 'folio_' . $transaction->payment_method;
          $currentFolio = $transaction->{$folioColumn};

          // Si es transferencia con tarjeta SAT, no debería tener folio específico
          if ($transaction->payment_method === 'transfer' && $transaction->card_id) {
            $card = \App\Models\Card::find($transaction->card_id);
            if ($card && $card->sat) {
              if ($currentFolio !== null) {
                $transaction->{$folioColumn} = null;
                $needsUpdate = true;
              }
              continue;
            }
          }

          // Si es pago con tarjeta, verificar configuración especial
          if ($transaction->payment_method === 'card') {
            // Si no hay card_id o la tarjeta tiene SAT = true, no debería tener folio específico
            if (!$transaction->card_id) {
              if ($currentFolio !== null) {
                $transaction->{$folioColumn} = null;
                $needsUpdate = true;
              }
              continue;
            }

            $card = \App\Models\Card::find($transaction->card_id);
            if ($card && $card->sat) {
              if ($currentFolio !== null) {
                $transaction->{$folioColumn} = null;
                $needsUpdate = true;
              }
              continue;
            }
            // Si la tarjeta no tiene SAT = true, generar folio específico T
          }

          // Incrementar contador y asignar folio correcto
          $folioCounters[$transaction->payment_method]++;
          $expectedFolio = $folioCounters[$transaction->payment_method];

          if ($currentFolio !== $expectedFolio) {
            $transaction->{$folioColumn} = $expectedFolio;
            $needsUpdate = true;
          }
        }

        if ($needsUpdate) {
          $report['folios_fixed']++;
          if (!$dryRun) {
            $transaction->save();
          }

          $report['changes'][] = [
            'transaction_id' => $transaction->id,
            'folio' => $transaction->folio,
            'payment_method' => $transaction->payment_method,
            'before' => $originalTransaction[$folioColumn] ?? null,
            'after' => $transaction->{$folioColumn}
          ];
        }
      }

      return response()->json($report);
    } catch (\Exception $e) {
      $report['errors'][] = $e->getMessage();
      return response()->json($report, 500);
    }
  }
}
