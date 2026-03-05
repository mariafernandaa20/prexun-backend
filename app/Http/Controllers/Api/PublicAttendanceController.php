<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\StudentAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PublicAttendanceController extends Controller
{
    private const MAX_CLASSES_PER_DAY = 5;

    /**
     * Registrar asistencia mediante número de teléfono
     */
    public function registerByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string|required_without:whatsapp',
            'whatsapp' => 'nullable|string|required_without:phone',
            'attendance_time' => 'nullable|date',
        ]);

        $rawPhone = $request->input('whatsapp') ?: $request->input('phone');
        $phone = $this->normalizePhone($rawPhone);

        if (strlen($phone) < 10) {
            return response()->json([
                'success' => false,
                'message' => 'Número de teléfono inválido. Debe contener al menos 10 dígitos.'
            ], 422);
        }

        $phoneDigits = substr($phone, -10);

        $phoneSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), ',', '')";
        $tutorPhoneSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(tutor_phone, ''), ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), ',', '')";

        $baseStudentQuery = Student::query()
            ->where(function ($query) use ($phoneDigits, $phoneSql, $tutorPhoneSql) {
                $query
                    ->whereRaw("RIGHT({$phoneSql}, 10) = ?", [$phoneDigits])
                    ->orWhereRaw("RIGHT({$tutorPhoneSql}, 10) = ?", [$phoneDigits]);
            });

        $student = (clone $baseStudentQuery)->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Estudiante no encontrado con el número proporcionado.'
            ], 404);
        }

        $assignmentQuery = StudentAssignment::query()
            ->active()
            ->current()
            ->where('student_id', $student->id)
            ->whereNotNull('grupo_id')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');

        $assignment = $assignmentQuery->first();
        $grupoId = $assignment?->grupo_id;

        if (!$grupoId) {
            return response()->json([
                'success' => false,
                'message' => 'El estudiante ' . $student->firstname . ' ' . $student->lastname . ' no tiene un grupo asignado.',
                'student' => [
                    'name' => $student->firstname . ' ' . $student->lastname,
                    'matricula' => $student->matricula ?: $student->id
                ]
            ], 422);
        }

        $attendanceTime = $request->input('attendance_time')
            ? Carbon::parse($request->input('attendance_time'), config('app.timezone'))
            : Carbon::now(config('app.timezone'));

        $today = $attendanceTime->copy()->toDateString();
        $window = $this->resolveWindow($attendanceTime);

        $existing = Attendance::where('student_id', $student->id)
            ->where('date', $today)
            ->first();

        if (!$existing) {
            if ($window !== 'check_in') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se permite entrada antes del minuto 15 de cada hora.',
                    'code' => 'OUTSIDE_CHECKIN_WINDOW',
                ], 422);
            }

            $classMarks = [
                [
                    'class_number' => 1,
                    'check_in' => $attendanceTime->toDateTimeString(),
                    'check_out' => null,
                ],
            ];

            $attendance = Attendance::create([
                'student_id' => $student->id,
                'grupo_id' => $grupoId,
                'date' => $today,
                'present' => true,
                'attendance_time' => $attendanceTime,
                'class_marks' => $classMarks,
                'notes' => 'Entrada clase 1 registrada vía API pública (WhatsApp)',
            ]);

            Log::info('Public attendance check-in created', [
                'student_id' => $student->id,
                'phone' => $phone,
                'grupo_id' => $grupoId,
                'class_number' => 1,
                'window' => $window,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Entrada registrada correctamente (Clase 1).',
                'action' => 'check_in',
                'class_number' => 1,
                'student' => [
                    'name' => $student->firstname . ' ' . $student->lastname,
                    'matricula' => $student->matricula ?: $student->id,
                    'phone' => $student->phone,
                ],
                'attendance' => $attendance,
            ]);
        }

        $classMarks = is_array($existing->class_marks) ? $existing->class_marks : [];
        $openClassIndex = $this->findOpenClassIndex($classMarks);

        if ($window === 'locked') {
            if ($openClassIndex !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Entrada registrada. La salida se habilita a partir del minuto 45.',
                    'code' => 'WAIT_FOR_CHECKOUT_WINDOW',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Registros disponibles antes del minuto 15 y salidas a partir del minuto 45.',
                'code' => 'WINDOW_LOCKED',
            ], 422);
        }

        if ($window === 'check_out') {
            if ($openClassIndex === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay entrada activa para registrar salida.',
                    'code' => 'NO_ACTIVE_CHECKIN',
                ], 422);
            }

            $classMarks[$openClassIndex]['check_out'] = $attendanceTime->toDateTimeString();
            $classNumber = (int) ($classMarks[$openClassIndex]['class_number'] ?? ($openClassIndex + 1));

            $existing->class_marks = $classMarks;
            $existing->notes = "Salida clase {$classNumber} registrada vía API pública (WhatsApp)";
            $existing->save();

            Log::info('Public attendance check-out registered', [
                'student_id' => $student->id,
                'grupo_id' => $grupoId,
                'class_number' => $classNumber,
                'window' => $window,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Salida registrada correctamente (Clase {$classNumber}).",
                'action' => 'check_out',
                'class_number' => $classNumber,
                'student' => [
                    'name' => $student->firstname . ' ' . $student->lastname,
                    'matricula' => $student->matricula ?: $student->id,
                    'phone' => $student->phone,
                ],
                'attendance' => $existing->fresh(),
            ]);
        }

        if ($openClassIndex !== null) {
            $currentClassNumber = (int) ($classMarks[$openClassIndex]['class_number'] ?? ($openClassIndex + 1));

            return response()->json([
                'success' => false,
                'message' => "Ya tienes una entrada activa para la clase {$currentClassNumber}.",
                'code' => 'ALREADY_CHECKED_IN',
            ], 422);
        }

        if (count($classMarks) >= self::MAX_CLASSES_PER_DAY) {
            return response()->json([
                'success' => false,
                'message' => 'Ya se alcanzó el máximo de clases registrables del día.',
                'code' => 'MAX_CLASSES_REACHED',
            ], 422);
        }

        $classNumber = count($classMarks) + 1;
        $classMarks[] = [
            'class_number' => $classNumber,
            'check_in' => $attendanceTime->toDateTimeString(),
            'check_out' => null,
        ];

        $existing->class_marks = $classMarks;
        if (!$existing->attendance_time) {
            $existing->attendance_time = $attendanceTime;
        }
        $existing->notes = "Entrada clase {$classNumber} registrada vía API pública (WhatsApp)";
        $existing->save();

        Log::info('Public attendance check-in registered', [
            'student_id' => $student->id,
            'grupo_id' => $grupoId,
            'class_number' => $classNumber,
            'window' => $window,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Entrada registrada correctamente (Clase {$classNumber}).",
            'action' => 'check_in',
            'class_number' => $classNumber,
            'student' => [
                'name' => $student->firstname . ' ' . $student->lastname,
                'matricula' => $student->matricula ?: $student->id,
                'phone' => $student->phone,
            ],
            'attendance' => $existing->fresh(),
        ]);
    }

    /**
     * Normalizar el número de teléfono para la búsqueda
     */
    private function normalizePhone($phone)
    {
        // Quitar caracteres no numéricos
        $normalized = preg_replace('/[^0-9]/', '', $phone);
        
        // Si tiene 12 dígitos y empieza con 52 (México), tomamos los últimos 10
        if (strlen($normalized) > 10) {
            $normalized = substr($normalized, -10);
        }
        
        return $normalized;
    }

    private function resolveWindow(Carbon $dateTime): string
    {
        $minute = (int) $dateTime->format('i');

        if ($minute < 15) {
            return 'check_in';
        }

        if ($minute >= 45) {
            return 'check_out';
        }

        return 'locked';
    }

    private function findOpenClassIndex(array $classMarks): ?int
    {
        for ($index = count($classMarks) - 1; $index >= 0; $index--) {
            $mark = $classMarks[$index] ?? null;
            if (!$mark) {
                continue;
            }

            $hasCheckIn = !empty($mark['check_in']);
            $hasCheckOut = !empty($mark['check_out']);

            if ($hasCheckIn && !$hasCheckOut) {
                return $index;
            }
        }

        return null;
    }
}
