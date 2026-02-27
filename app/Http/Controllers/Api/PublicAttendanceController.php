<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PublicAttendanceController extends Controller
{
    /**
     * Registrar asistencia mediante número de teléfono
     */
    public function registerByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $phone = $this->normalizePhone($request->phone);

        // Buscar al estudiante por su teléfono o el de su tutor
        $student = Student::where('phone', 'LIKE', "%$phone%")
            ->orWhere('tutor_phone', 'LIKE', "%$phone%")
            ->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Estudiante no encontrado con el número: ' . $phone
            ], 404);
        }

        // Obtener el grupo del estudiante
        // Intentamos primero por grupo_id directo, luego por asignaciones si es necesario
        $grupoId = $student->grupo_id;

        if (!$grupoId && $student->assignments()->exists()) {
            $grupoId = $student->assignments()->first()->grupo_id;
        }

        if (!$grupoId) {
            return response()->json([
                'success' => false,
                'message' => 'El estudiante ' . $student->firstname . ' ' . $student->lastname . ' no tiene un grupo asignado.',
                'student' => [
                    'name' => $student->firstname . ' ' . $student->lastname,
                    'matricula' => $student->id
                ]
            ], 422);
        }

        $today = Carbon::today()->toDateString();
        
        // Verificar si ya tiene asistencia hoy
        $existing = Attendance::where('student_id', $student->id)
            ->where('date', $today)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'La asistencia ya estaba registrada para hoy.',
                'already_registered' => true,
                'student' => [
                    'name' => $student->firstname . ' ' . $student->lastname,
                    'matricula' => $student->id,
                    'phone' => $student->phone
                ],
                'attendance' => $existing
            ]);
        }

        // Crear el registro de asistencia
        $attendance = Attendance::create([
            'student_id' => $student->id,
            'grupo_id' => $grupoId,
            'date' => $today,
            'present' => true,
            'attendance_time' => Carbon::now(),
            'notes' => 'Registrado vía API pública (Teléfono)'
        ]);

        Log::info('Asistencia pública registrada', [
            'student_id' => $student->id,
            'phone' => $phone,
            'grupo_id' => $grupoId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Asistencia registrada correctamente.',
            'already_registered' => false,
            'student' => [
                'name' => $student->firstname . ' ' . $student->lastname,
                'matricula' => $student->id,
                'phone' => $student->phone
            ],
            'attendance' => $attendance
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
        if (strlen($normalized) == 12 && str_starts_with($normalized, '52')) {
            $normalized = substr($normalized, 2);
        }
        
        return $normalized;
    }
}
