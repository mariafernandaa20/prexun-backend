<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TeacherAttendanceController extends Controller
{
  public function store(Request $request)
  {
    try {
      DB::beginTransaction();

      // Procesar la fecha antes de la validación
      $dateInput = $request->date;
      $originalDate = $dateInput; // Guardar fecha original para logs
      if ($dateInput) {
        try {
          // Si viene en formato ISO 8601 con hora, extraer solo la fecha
          if (strpos($dateInput, 'T') !== false) {
            $dateInput = Carbon::parse($dateInput)->format('Y-m-d');
          }
          // Convertir formato español dd/m/yyyy o d/m/yyyy a Y-m-d
          elseif (strpos($dateInput, '/') !== false) {
            // Intentar diferentes formatos españoles
            $formats = ['d/m/Y', 'j/n/Y', 'd/n/Y', 'j/m/Y'];
            $parsed = false;

            foreach ($formats as $format) {
              try {
                $dateInput = Carbon::createFromFormat($format, $dateInput)->format('Y-m-d');
                $parsed = true;
                break;
              } catch (\Exception $e) {
                continue;
              }
            }

            if (!$parsed) {
              throw new \Exception("No se pudo convertir la fecha: {$dateInput}");
            }
          }
          // Si ya está en formato Y-m-d, verificar que sea válido y mantenerlo
          elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            // Validar que la fecha sea válida
            $carbon = Carbon::createFromFormat('Y-m-d', $dateInput);
            if ($carbon->format('Y-m-d') !== $dateInput) {
              throw new \Exception("Fecha inválida: {$dateInput}");
            }
            // La fecha ya está en el formato correcto, no necesita cambios
          }
          // Otros formatos posibles
          else {
            $dateInput = Carbon::parse($dateInput)->format('Y-m-d');
          }

          $request->merge(['date' => $dateInput]);

          Log::info('Fecha procesada exitosamente:', [
            'fecha_original' => $originalDate,
            'fecha_procesada' => $dateInput
          ]);

        } catch (\Exception $dateError) {
          Log::error('Error al procesar fecha:', [
            'fecha_original' => $originalDate,
            'error' => $dateError->getMessage()
          ]);

          return response()->json([
            'success' => false,
            'message' => 'Formato de fecha inválido. Formatos aceptados: YYYY-MM-DD, DD/MM/YYYY, D/M/YYYY.',
            'fecha_recibida' => $originalDate,
            'error_detalle' => $dateError->getMessage()
          ], 422);
        }
      }

      $validatedData = $request->validate([
        'grupo_id' => 'required|exists:grupos,id',
        'date' => 'required|date_format:Y-m-d',
        'attendance' => 'required|array',
      ]);

      $attendanceCount = 0;
      $presentCount = 0;
      $absentCount = 0;
      $alreadyExistsCount = 0;
      $newRecordsCount = 0;

      foreach ($request->attendance as $record) {
        $studentId = $record['student_id'];
        $isPresent = $record['present'];
        $attendanceTime = $record['attendance_time'] ?? now()->toISOString();
        $notes = $record['notes'] ?? null;

        // Verificar si ya existe el registro
        $existingAttendance = Attendance::where('student_id', $studentId)
          ->where('grupo_id', $request->grupo_id)
          ->where('date', $request->date)
          ->first();

        if ($existingAttendance) {
          // Si ya existe, no actualizar, solo contar como procesado
          Log::info('Asistencia ya existente, no se actualiza', [
            'student_id' => $studentId,
            'grupo_id' => $request->grupo_id,
            'fecha' => $request->date,
            'presente_existente' => $existingAttendance->present
          ]);

          $attendanceCount++;
          $alreadyExistsCount++;
          if ($existingAttendance->present) {
            $presentCount++;
          } else {
            $absentCount++;
          }
          continue;
        }

        // Solo crear si no existe
        $attendance = Attendance::create([
          'student_id' => $studentId,
          'grupo_id' => $request->grupo_id,
          'date' => $request->date,
          'present' => $isPresent,
          'attendance_time' => $attendanceTime,
          'notes' => $notes
        ]);

        if (!$attendance) {
          throw new \Exception('Error al guardar la asistencia para el estudiante ' . $studentId);
        }

        $attendanceCount++;
        $newRecordsCount++;
        if ($isPresent) {
          $presentCount++;
        } else {
          $absentCount++;
        }
      }

      DB::commit();

      Log::info('Asistencia procesada exitosamente', [
        'fecha' => $request->date,
        'grupo_id' => $request->grupo_id,
        'total_estudiantes' => $attendanceCount,
        'nuevos_registros' => $newRecordsCount,
        'ya_existian' => $alreadyExistsCount,
        'presentes' => $presentCount,
        'ausentes' => $absentCount,
        'timestamp' => now()->toISOString()
      ]);

      // Determinar el mensaje apropiado
      if ($newRecordsCount > 0 && $alreadyExistsCount > 0) {
        $message = "Se guardaron {$newRecordsCount} asistencias nuevas. {$alreadyExistsCount} ya estaban registradas.";
      } elseif ($newRecordsCount > 0) {
        $message = 'Asistencia guardada correctamente';
      } else {
        $message = 'Todas las asistencias ya estaban registradas previamente';
      }

      return response()->json([
        'success' => true,
        'message' => $message,
        'summary' => [
          'total_processed' => $attendanceCount,
          'new_records' => $newRecordsCount,
          'already_existed' => $alreadyExistsCount,
          'present_count' => $presentCount,
          'absent_count' => $absentCount
        ]
      ]);
    } catch (\Exception $e) {
      DB::rollBack();

      Log::error('Error al guardar asistencia:', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al guardar la asistencia',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function getAttendance(Request $request, $grupo_id, $date)
  {
    try {
      $plantelId = $request->query('plantel_id');
      // Procesar el formato de fecha
      try {
        if (strpos($date, 'T') !== false) {
          $date = Carbon::parse($date)->format('Y-m-d');
        } elseif (strpos($date, '/') !== false) {
          // Intentar diferentes formatos españoles
          $formats = ['d/m/Y', 'j/n/Y', 'd/n/Y', 'j/m/Y'];
          $parsed = false;

          foreach ($formats as $format) {
            try {
              $date = Carbon::createFromFormat($format, $date)->format('Y-m-d');
              $parsed = true;
              break;
            } catch (\Exception $e) {
              continue;
            }
          }

          if (!$parsed) {
            throw new \Exception("No se pudo convertir la fecha: {$date}");
          }
        } else {
          $date = Carbon::parse($date)->format('Y-m-d');
        }
      } catch (\Exception $dateError) {
        Log::error('Error al procesar fecha en getAttendance:', [
          'fecha_original' => $date,
          'error' => $dateError->getMessage()
        ]);

        return response()->json([
          'success' => false,
          'message' => 'Formato de fecha inválido en consulta.',
          'fecha_recibida' => $date
        ], 422);
      }

      $query = Attendance::with('student')
        ->where('grupo_id', $grupo_id)
        ->where('date', $date);

      if ($plantelId) {
        $query->whereHas('student', function ($q) use ($plantelId) {
          $q->where('campus_id', $plantelId);
        });
      }

      $attendance = $query->get();

      return response()->json([
        'success' => true,
        'data' => $attendance
      ]);
    } catch (\Exception $e) {
      Log::error('Error al obtener asistencia:', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al obtener la asistencia',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function findStudent(Request $request, Student $student)
  {
    $plantelId = $request->query('plantel_id');

    Log::info("Buscando estudiante con ID o matrícula: {$student->id}" . ($plantelId ? " filtrado por plantel " . $plantelId : ""));

    if ($plantelId && $student->campus_id != $plantelId) {
      return response()->json([
        'success' => false,
        'message' => 'Estudiante no pertenece a este plantel'
      ], 404);
    }

    $student->load([
      'assignments.grupo',
      'assignments.semanaIntensiva'
    ]);

    return response()->json([
      'success' => true,
      'data' => $student
    ]);
  }


  public function quickStore(Request $request)
  {
    try {
      $dateInput = $request->date;
      $originalDate = $dateInput;
      if ($dateInput) {
        try {
          if (strpos($dateInput, 'T') !== false) {
            $dateInput = Carbon::parse($dateInput)->format('Y-m-d');
          } elseif (strpos($dateInput, '/') !== false) {
            // Intentar diferentes formatos españoles
            $formats = ['d/m/Y', 'j/n/Y', 'd/n/Y', 'j/m/Y'];
            $parsed = false;

            foreach ($formats as $format) {
              try {
                $dateInput = Carbon::createFromFormat($format, $dateInput)->format('Y-m-d');
                $parsed = true;
                break;
              } catch (\Exception $e) {
                continue;
              }
            }

            if (!$parsed) {
              throw new \Exception("No se pudo convertir la fecha: {$dateInput}");
            }
          } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            // Validar que la fecha sea válida
            $carbon = Carbon::createFromFormat('Y-m-d', $dateInput);
            if ($carbon->format('Y-m-d') !== $dateInput) {
              throw new \Exception("Fecha inválida: {$dateInput}");
            }
            // La fecha ya está en el formato correcto, no necesita cambios
          } else {
            $dateInput = Carbon::parse($dateInput)->format('Y-m-d');
          }
          $request->merge(['date' => $dateInput]);

          Log::info('Fecha procesada en quickStore:', [
            'fecha_original' => $originalDate,
            'fecha_procesada' => $dateInput
          ]);

        } catch (\Exception $dateError) {
          Log::error('Error al procesar fecha en quickStore:', [
            'fecha_original' => $originalDate,
            'error' => $dateError->getMessage()
          ]);

          return response()->json([
            'success' => false,
            'message' => 'Formato de fecha inválido: ' . $dateError->getMessage(),
            'fecha_recibida' => $originalDate
          ], 422);
        }
      }

      $validated = $request->validate([
        'student_id' => 'required|exists:students,id',
        'date' => 'required|date_format:Y-m-d',
        'present' => 'required|boolean',
        'attendance_time' => 'nullable|date'
      ]);

      $student = Student::with('assignments')->findOrFail($validated['student_id']);

      // La fecha ya está procesada
      $date = $validated['date'];

      if (!$student->assignments) {
        throw new \Exception('El estudiante no tiene asignaciones.');
      }

      $grupo_id = $student->assignments->first()->grupo_id;

      // Primero verificar si ya existe el registro
      $existingAttendance = Attendance::where('student_id', $validated['student_id'])
        ->where('grupo_id', $grupo_id)
        ->where('date', $date)
        ->first();
      //si el estudiante esta marcado como falta y marca asistencia, se actualiza la asistencia existente
      if ($existingAttendance) {

        if (!$existingAttendance->present && $validated['present']) {
          $existingAttendance->update([
            'present' => true,
            'attendance_time' => $validated['attendance_time'] ?? now(),
          ]);

          Log::info('Asistencia actualizada de ausente a presente', [
            'attendance_id' => $existingAttendance->id,
            'student_id' => $validated['student_id'],
            'grupo_id' => $grupo_id,
            'fecha' => $date,
            'estado_anterior' => false,
            'nuevo_estado' => true,
            'timestamp' => now()->toISOString()
          ]);

          return response()->json([
            'success' => true,
            'message' => 'Asistencia actualizada de ausente a presente',
            'data' => $existingAttendance->fresh(),
            'updated_from_absent' => true
          ]);
        }

        // Si ya existe y no necesita actualización
        Log::info('Asistencia ya registrada previamente', [
          'attendance_id' => $existingAttendance->id,
          'student_id' => $validated['student_id'],
          'grupo_id' => $grupo_id,
          'fecha' => $date,
          'estado_actual' => $existingAttendance->present,
          'estado_solicitado' => $validated['present'],
          'timestamp' => now()->toISOString()
        ]);

        return response()->json([
          'success' => true,
          'message' => 'La asistencia ya estaba registrada previamente',
          'data' => $existingAttendance->fresh(),
          'updated_from_absent' => false
        ]);
      }

      // Si no existe, crear el nuevo registro
      $attendance = Attendance::create([
        'student_id' => $validated['student_id'],
        'grupo_id' => $grupo_id,
        'date' => $date,
        'present' => $validated['present'],
        'attendance_time' => $validated['attendance_time'] ?? now(),
      ]);

      Log::info('Asistencia rápida guardada exitosamente', [
        'attendance_id' => $attendance->id,
        'student_id' => $validated['student_id'],
        'grupo_id' => $grupo_id,
        'fecha' => $date,
        'presente' => $validated['present'],
        'timestamp' => now()->toISOString()
      ]);

      return response()->json([
        'success' => true,
        'message' => 'Asistencia guardada correctamente',
        'data' => $existingAttendance->fresh(),
        'updated_from_absent' => false
      ]);
    } catch (\Exception $e) {
      Log::error('Error al guardar asistencia rápida:', [
        'error' => $e->getMessage(),
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al guardar la asistencia: ' . $e->getMessage(),
      ], 500);
    }
  }

  public function updateAttendance(Request $request, $attendanceId)
  {
    try {
      $validated = $request->validate([
        'present' => 'required|boolean',
        'notes' => 'nullable|string|max:500',
        'attendance_time' => 'nullable|date'
      ]);

      $attendance = Attendance::findOrFail($attendanceId);

      $attendance->update([
        'present' => $validated['present'],
        'notes' => $validated['notes'],
        'attendance_time' => $validated['attendance_time'] ?? $attendance->attendance_time
      ]);

      Log::info('Asistencia actualizada', [
        'attendance_id' => $attendanceId,
        'student_id' => $attendance->student_id,
        'presente' => $validated['present'],
        'notas' => $validated['notes'],
        'timestamp' => now()->toISOString()
      ]);

      return response()->json([
        'success' => true,
        'message' => 'Asistencia actualizada correctamente',
        'data' => $attendance->load('student')
      ]);
    } catch (\Exception $e) {
      Log::error('Error al actualizar asistencia:', [
        'error' => $e->getMessage(),
        'attendance_id' => $attendanceId
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al actualizar la asistencia',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function getTodayAttendance(Request $request, $date)
  {
    $plantelId = $request->query('plantel_id');
    Log::info("Obteniendo asistencias para la fecha " . $date . ($plantelId ? " filtrado por plantel " . $plantelId : ""));
    try {
      $query = Attendance::with(['student', 'grupo'])
        ->where('date', $date)
        ->orderBy('updated_at', 'desc');

      if ($plantelId) {
        $query->whereHas('student', function ($q) use ($plantelId) {
          $q->where('campus_id', $plantelId);
        });
      }

      $attendance = $query->get();

      return response()->json([
        'success' => true,
        'data' => $attendance
      ]);
    } catch (\Exception $e) {
      Log::error('Error al obtener asistencias del día:', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al obtener las asistencias del día',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Generar reporte de asistencia de un estudiante
   * Calcula días presentes y ausentes en un rango de fechas
   */
  public function getStudentAttendanceReport($studentID, Request $request)
  {

    $student = Student::with(['grupo.period'])->find($studentID);

    try {
      $excludeWeekends = $request->exclude_weekends ?? true;

      // Obtener todos los registros de asistencia del estudiante
      $attendanceRecords = Attendance::where('student_id', $student->id)
        ->orderBy('date')
        ->get();

      return response()->json([
        'success' => true,
        'data' => $attendanceRecords
      ]);
    } catch (\Exception $e) {
      Log::error('Error al generar reporte de asistencia:', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al generar el reporte de asistencia',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  private function getEmptyReport($student, $excludeWeekends)
  {
    // Cargar relaciones si es necesario para el reporte
    $student->loadMissing('grupo.period');

    return [
      'student' => [
        'id' => $student->id,
        'firstname' => $student->firstname,
        'lastname' => $student->lastname,
        'matricula' => $student->matricula,
        'grupo' => $student->grupo ? $student->grupo->name : null,
        'period' => $student->grupo && $student->grupo->period ? $student->grupo->period->name : null,
      ],
      'period' => [
        'start_date' => null,
        'end_date' => null,
        'total_days' => 0,
        'exclude_weekends' => $excludeWeekends,
      ],
      'statistics' => [
        'present_count' => 0,
        'absent_count' => 0,
        'total_days' => 0,
        'attendance_percentage' => 0,
        'absent_percentage' => 0,
      ],
      'attendance_details' => [
        'all_days' => [],
        'present_days' => [],
        'absent_days' => [],
      ]
    ];
  }
  /**
   * Generar reporte de asistencia de un grupo completo
   */
  public function getGroupAttendanceReport($groupId, Request $request)
  {
    try {
      $validatedData = $request->validate([
        'start_date' => 'required|date_format:Y-m-d',
        'end_date' => 'required|date_format:Y-m-d',
        'exclude_weekends' => 'boolean',
      ]);

      $plantelId = $request->query('plantel_id');

      $grupo = \App\Models\Grupo::with([
        'students' => function ($query) use ($plantelId) {
          if ($plantelId) {
            $query->where('campus_id', $plantelId);
          }
        },
        'period'
      ])->findOrFail($groupId);

      $excludeWeekends = $request->exclude_weekends ?? true;

      $studentsReports = [];

      foreach ($grupo->students as $student) {
        // Crear una request temporal para cada estudiante
        $studentRequest = new Request([
          'start_date' => $request->start_date,
          'end_date' => $request->end_date,
          'exclude_weekends' => $excludeWeekends,
          'plantel_id' => $plantelId, // Pasar también el plantel_id
        ]);

        $studentReportResponse = $this->getStudentAttendanceReport($student->id, $studentRequest);
        $studentReportData = json_decode($studentReportResponse->getContent(), true);

        if ($studentReportData['success']) {
          $studentsReports[] = $studentReportData['data'];
        }
      }

      // Calcular estadísticas del grupo
      $totalStudents = count($studentsReports);
      $totalPresentDays = array_sum(array_column(array_column($studentsReports, 'statistics'), 'present_count'));
      $totalAbsentDays = array_sum(array_column(array_column($studentsReports, 'statistics'), 'absent_count'));
      $totalPossibleDays = array_sum(array_column(array_column($studentsReports, 'statistics'), 'total_days'));

      $groupAttendancePercentage = $totalPossibleDays > 0 ? round(($totalPresentDays / $totalPossibleDays) * 100, 2) : 0;

      $groupReport = [
        'group' => [
          'id' => $grupo->id,
          'name' => $grupo->name,
          'period' => $grupo->period ? $grupo->period->name : null,
          'total_students' => $totalStudents,
        ],
        'period' => [
          'start_date' => $request->start_date,
          'end_date' => $request->end_date,
          'exclude_weekends' => $excludeWeekends,
        ],
        'group_statistics' => [
          'total_students' => $totalStudents,
          'total_present_days' => $totalPresentDays,
          'total_absent_days' => $totalAbsentDays,
          'total_possible_days' => $totalPossibleDays,
          'group_attendance_percentage' => $groupAttendancePercentage,
        ],
        'students_reports' => $studentsReports,
      ];

      return response()->json([
        'success' => true,
        'data' => $groupReport
      ]);
    } catch (\Exception $e) {
      Log::error('Error al generar reporte de grupo:', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Error al generar el reporte del grupo',
        'error' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Helper para obtener nombres de días en español
   */
  private function getDayNameInSpanish($dayNumber)
  {
    $days = [
      1 => 'Lunes',
      2 => 'Martes',
      3 => 'Miércoles',
      4 => 'Jueves',
      5 => 'Viernes',
      6 => 'Sábado',
      7 => 'Domingo'
    ];

    return $days[$dayNumber] ?? 'Desconocido';
  }
}
