<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Grupo;
use App\Models\Period;
use App\Services\Moodle;
use Illuminate\Support\Facades\Log;

class GrupoController extends Controller
{
  protected $moodleService;

  public function __construct(Moodle $moodleService)
  {
    $this->moodleService = $moodleService;
  }

  public function index(Request $request)
  {
    $plantelId = $request->query('plantel_id');
    $periodId = $request->query('period_id');

    $query = Grupo::with(['period', 'campuses'])
      ->withCount([
        'activeAssignments as active_assignments_count'
      ]);

    if ($plantelId) {
      $query->whereHas('campuses', function ($q) use ($plantelId) {
        $q->where('campuses.id', $plantelId);
      });
    }

    if ($periodId) {
      $query->where('period_id', $periodId);
    }

    $grupos = $query->get()
      ->map(function ($grupo) {
        // Agregar los valores calculados como propiedades temporales
        $grupo->setAttribute('available_slots', $grupo->capacity - $grupo->active_assignments_count);
        $grupo->setAttribute('is_almost_full', ($grupo->capacity - $grupo->active_assignments_count) <= 3);
        $grupo->setAttribute('is_full', ($grupo->capacity - $grupo->active_assignments_count) <= 0);
        return $grupo;
      });

    return response()->json($grupos);
  }

  public function store(Request $request)
  {
    $validated = $request->validate([
      'name' => 'string|sometimes',
      'type' => 'string|sometimes',
      'period_id' => 'integer|min:1|sometimes',
      'capacity' => 'integer|min:1|sometimes',
      'frequency' => 'array|nullable',
      'start_time' => 'sometimes|date_format:H:i|nullable',
      'end_time' => 'sometimes|date_format:H:i|nullable',
      'start_date' => 'sometimes|date_format:Y-m-d|nullable',
      'end_date' => 'sometimes|date_format:Y-m-d|nullable',
      'campus_ids' => 'nullable|array',
      'campus_ids.*' => 'exists:campuses,id'
    ]);

    $validated['frequency'] = json_encode($validated['frequency']);

    $grupo = Grupo::create($validated);

    // Asignar campus al grupo
    if ($request->has('campus_ids')) {
      $grupo->campuses()->sync($request->campus_ids);
    }

    // Crear cohort en Moodle
    try {
      $period = Period::find($validated['period_id']);
      if ($period) {
        $cohortName = $period->name . $grupo->name;

        $cohortData = [
          'cohorts' => [
            [
              'name' => $cohortName,
              'idnumber' => 'G' . $grupo->id,
              'description' => 'Grupo ' . $grupo->name . ' del periodo ' . $period->name,
              'descriptionformat' => 1,
              'visible' => 1,
              'categorytype' => [
                'type' => 'system',
                'value' => ''
              ]
            ]
          ]
        ];

        $response = $this->moodleService->createCohorts($cohortData);

        if ($response['status'] === 'success' && isset($response['data'][0]['id'])) {
          // Guardar el ID del cohort en Moodle
          $grupo->moodle_id = $response['data'][0]['id'];
          $grupo->save();

          Log::info('Cohort created in Moodle', [
            'grupo_id' => $grupo->id,
            'moodle_id' => $grupo->moodle_id,
            'cohort_name' => $cohortName
          ]);
        } else {
          Log::error('Failed to create cohort in Moodle', [
            'grupo_id' => $grupo->id,
            'error' => $response['message'] ?? 'Unknown error'
          ]);
        }
      }
    } catch (\Exception $e) {
      Log::error('Exception creating cohort in Moodle', [
        'grupo_id' => $grupo->id,
        'error' => $e->getMessage()
      ]);
    }

    // Cargar las relaciones para la respuesta
    $grupo->load(['period', 'campuses']);

    return response()->json($grupo, 201);
  }

  public function show($id)
  {
    $grupo = Grupo::with(['period', 'campuses'])
      ->withCount([
        'activeAssignments as students_count'
      ])
      ->findOrFail($id);

    return response()->json($grupo);
  }

  public function getStudents(Request $request, $id)
  {
    $plantelId = $request->query('plantel_id');

    $grupo = Grupo::findOrFail($id);

    $assignmentsQuery = $grupo->studentAssignments()
      ->active()
      ->with(['student.carrera', 'student.period']);

    if ($plantelId) {
      $assignmentsQuery->whereHas('student', function ($q) use ($plantelId) {
        $q->where('campus_id', $plantelId);
      });
    }

    $studentsFromAssignments = $assignmentsQuery->get()
      ->map(function ($assignment) {
        return $assignment->student;
      })
      ->filter()
      ->unique('id')
      ->values();

    return response()->json($studentsFromAssignments);
  }


  public function update(Request $request, $id)
  {
    $validated = $request->validate([
      'name' => 'string|sometimes',
      'type' => 'string|sometimes',
      'period_id' => 'integer|min:1|sometimes',
      'capacity' => 'integer|min:1|sometimes',
      'frequency' => 'array|nullable',
      'start_time' => 'sometimes|date_format:H:i|nullable',
      'end_time' => 'sometimes|date_format:H:i|nullable',
      'start_date' => 'sometimes|date_format:Y-m-d|nullable',
      'end_date' => 'sometimes|date_format:Y-m-d|nullable',
      'campus_ids' => 'nullable|array',
      'campus_ids.*' => 'exists:campuses,id'
    ]);

    $dataToUpdate = [];

    foreach (['name', 'type', 'period_id', 'capacity', 'start_time', 'end_time', 'start_date', 'end_date'] as $field) {
      if (isset($validated[$field])) {
        $dataToUpdate[$field] = $validated[$field];
      }
    }

    if (isset($validated['frequency']) && !empty($validated['frequency'])) {
      $dataToUpdate['frequency'] = json_encode($validated['frequency']);
    }

    $grupo = Grupo::findOrFail($id);
    $oldName = $grupo->name;
    $oldPeriodId = $grupo->period_id;

    $grupo->update($dataToUpdate);

    if ($request->has('campus_ids')) {
      $grupo->campuses()->sync($request->campus_ids);
    }

    // Actualizar cohort en Moodle si cambió el nombre o el periodo
    if (($oldName !== $grupo->name || $oldPeriodId !== $grupo->period_id) && $grupo->moodle_id) {
      try {
        $period = Period::find($grupo->period_id);
        if ($period) {
          $cohortName = $period->name . $grupo->name;

          // Actualizar el cohort en Moodle
          $cohortData = [
            'cohorts' => [
              [
                'id' => $grupo->moodle_id,
                'name' => $cohortName,
                'idnumber' => 'G' . $grupo->id,
                'description' => 'Grupo ' . $grupo->name . ' del periodo ' . $period->name,
                'descriptionformat' => 1,
                'visible' => 1
              ]
            ]
          ];

          $response = $this->moodleService->updateCohorts($cohortData);

          if ($response['status'] === 'success') {
            Log::info('Cohort updated in Moodle', [
              'grupo_id' => $grupo->id,
              'moodle_id' => $grupo->moodle_id,
              'cohort_name' => $cohortName
            ]);
          } else {
            Log::error('Failed to update cohort in Moodle', [
              'grupo_id' => $grupo->id,
              'error' => $response['message'] ?? 'Unknown error'
            ]);
          }
        }
      } catch (\Exception $e) {
        Log::error('Exception updating cohort in Moodle', [
          'grupo_id' => $grupo->id,
          'error' => $e->getMessage()
        ]);
      }
    }

    // Crear cohort en Moodle si no existe
    if (!$grupo->moodle_id) {
      try {
        $period = Period::find($grupo->period_id);
        if ($period) {
          $cohortName = $period->name . $grupo->name;

          $cohortData = [
            'cohorts' => [
              [
                'name' => $cohortName,
                'idnumber' => 'G' . $grupo->id,
                'description' => 'Grupo ' . $grupo->name . ' del periodo ' . $period->name,
                'descriptionformat' => 1,
                'visible' => 1,
                'categorytype' => [
                  'type' => 'system',
                  'value' => ''
                ]
              ]
            ]
          ];

          $response = $this->moodleService->createCohorts($cohortData);

          if ($response['status'] === 'success' && isset($response['data'][0]['id'])) {
            // Guardar el ID del cohort en Moodle
            $grupo->moodle_id = $response['data'][0]['id'];
            $grupo->save();

            Log::info('Cohort created in Moodle during update', [
              'grupo_id' => $grupo->id,
              'moodle_id' => $grupo->moodle_id,
              'cohort_name' => $cohortName
            ]);
          } else {
            Log::error('Failed to create cohort in Moodle during update', [
              'grupo_id' => $grupo->id,
              'error' => $response['message'] ?? 'Unknown error'
            ]);
          }
        }
      } catch (\Exception $e) {
        Log::error('Exception creating cohort in Moodle during update', [
          'grupo_id' => $grupo->id,
          'error' => $e->getMessage()
        ]);
      }
    }

    $grupo->load(['period', 'campuses']);

    return response()->json($grupo);
  }

  public function destroy($id)
  {
    $grupo = Grupo::findOrFail($id);

    // Eliminar cohort en Moodle si existe
    if ($grupo->moodle_id) {
      try {
        $cohortData = [
          'cohortids' => [$grupo->moodle_id]
        ];

        $response = $this->moodleService->deleteCohorts($cohortData);

        if ($response['status'] === 'success') {
          Log::info('Cohort deleted in Moodle', [
            'grupo_id' => $grupo->id,
            'moodle_id' => $grupo->moodle_id
          ]);
        } else {
          Log::error('Failed to delete cohort in Moodle', [
            'grupo_id' => $grupo->id,
            'error' => $response['message'] ?? 'Unknown error'
          ]);
        }
      } catch (\Exception $e) {
        Log::error('Exception deleting cohort in Moodle', [
          'grupo_id' => $grupo->id,
          'error' => $e->getMessage()
        ]);
      }
    }

    $grupo->delete();

    return response()->json(['message' => 'Grupo eliminado exitosamente'], 200);
  }
}
