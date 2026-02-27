<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentAssignment;
use App\Models\Student;
use App\Models\Period;
use App\Models\Grupo;
use App\Models\SemanaIntensiva;
use App\Models\Carrera;
use App\Services\Moodle\MoodleService;
use App\Services\StudentGradesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class StudentAssignmentController extends Controller
{
    protected $moodleService;
    protected $gradesService;

    public function __construct(MoodleService $moodleService, StudentGradesService $gradesService)
    {
        $this->moodleService = $moodleService;
        $this->gradesService = $gradesService;
    }
    /**
     * Display a listing of student assignments.
     */
    public function index(Request $request)
    {
        $query = StudentAssignment::with(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera']);

        // Apply filters
        if ($request->has('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->has('period_id')) {
            $query->where('period_id', $request->period_id);
        }

        if ($request->has('grupo_id')) {
            $query->where('grupo_id', $request->grupo_id);
        }

        if ($request->has('semana_intensiva_id')) {
            $query->where('semana_intensiva_id', $request->semana_intensiva_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('book_delivery_type')) {
            $query->where('book_delivery_type', $request->book_delivery_type);
        }

        if ($request->has('book_delivered')) {
            $query->where('book_delivered', $request->boolean('book_delivered'));
        }

        // Apply scopes
        if ($request->has('only_active') && $request->boolean('only_active')) {
            $query->active();
        }

        if ($request->has('only_current') && $request->boolean('only_current')) {
            $query->current();
        }

        $perPage = (int) $request->query('per_page', 15);
        $page = (int) $request->query('page', 1);

        $assignments = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($assignments);
    }

    /**
     * Store a newly created student assignment.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'period_id' => 'nullable|exists:periods,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'semana_intensiva_id' => 'nullable|exists:semanas_intensivas,id',
            'carrer_id' => 'nullable|exists:carreers,id',
            'assigned_at' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:assigned_at',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
            'book_delivered' => 'boolean',
            'book_delivery_type' => 'nullable|in:digital,fisico,paqueteria',
            'book_delivery_date' => 'nullable|date',
            'book_notes' => 'nullable|string|max:1000',
            'book_modulos' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
            'book_general' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Set default values
        $validated['assigned_at'] = $validated['assigned_at'] ?? now();
        $validated['is_active'] = $validated['is_active'] ?? true;

        $assignment = StudentAssignment::create($validated);

        // Sync with Moodle if grupo or semana intensiva is assigned
        $this->syncAssignmentWithMoodle($assignment);

        return response()->json(
            $assignment->load(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera']),
            201
        );
    }

    /**
     * Display the specified student assignment.
     */
    public function show($id)
    {
        $assignment = StudentAssignment::with(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera'])
            ->findOrFail($id);

        return response()->json($assignment);
    }

    /**
     * Update the specified student assignment.
     */
    public function update(Request $request, $id)
    {
        $assignment = StudentAssignment::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'student_id' => 'sometimes|exists:students,id',
            'period_id' => 'nullable|exists:periods,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'semana_intensiva_id' => 'nullable|exists:semanas_intensivas,id',
            'carrer_id' => 'nullable|exists:carreers,id',
            'assigned_at' => 'sometimes|date',
            'valid_until' => 'nullable|date|after_or_equal:assigned_at',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
            'book_delivered' => 'boolean',
            'book_delivery_type' => 'nullable|in:digital,fisico,paqueteria',
            'book_delivery_date' => 'nullable|date',
            'book_notes' => 'nullable|string|max:1000',
            'book_modulos' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
            'book_general' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        // Capture old values for Moodle sync
        $oldGrupoId = $assignment->grupo_id;
        $oldSemanaIntensivaId = $assignment->semana_intensiva_id;
        $oldCarrerId = $assignment->carrer_id;

        $assignment->update($validator->validated());

        // Sync with Moodle if grupo or semana intensiva changed
        $newGrupoId = $assignment->grupo_id;
        $newSemanaIntensivaId = $assignment->semana_intensiva_id;
        $newCarrerId = $assignment->carrer_id;

        if ($oldGrupoId !== $newGrupoId || $oldSemanaIntensivaId !== $newSemanaIntensivaId || $oldCarrerId !== $newCarrerId) {
            $this->updateMoodleCohorts($assignment, $oldGrupoId, $newGrupoId, $oldSemanaIntensivaId, $newSemanaIntensivaId, $oldCarrerId, $newCarrerId);
        }

        return response()->json(
            $assignment->fresh(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera'])
        );
    }

    /**
     * Remove the specified student assignment.
     */
    public function destroy($id)
    {
        $assignment = StudentAssignment::findOrFail($id);

        // Remove student from all associated cohorts in Moodle before deleting
        $this->removeAssignmentFromMoodle($assignment);

        $assignment->delete();

        return response()->json([
            'message' => 'Asignación eliminada correctamente'
        ]);
    }

    /**
     * Get assignments for a specific student.
     */
    public function getByStudent($studentId)
    {
        $assignments = StudentAssignment::with(['period', 'grupo', 'semanaIntensiva', 'carrera'])
            ->where('student_id', $studentId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Get assignments for a specific period.
     */
    public function getByPeriod($periodId)
    {
        $assignments = StudentAssignment::with(['student', 'grupo', 'semanaIntensiva'])
            ->where('period_id', $periodId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($assignments);
    }

    /**
     * Get assignments for a specific grupo.
     */
    public function getByGrupo($grupoId)
    {
        $assignments = StudentAssignment::with(['student', 'period', 'semanaIntensiva'])
            ->where('grupo_id', $grupoId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($assignments);
    }

    /**
     * Get assignments for a specific semana intensiva.
     */
    public function getBySemanaIntensiva($semanaIntensivaId)
    {
        $assignments = StudentAssignment::with(['student', 'period', 'grupo'])
            ->where('semana_intensiva_id', $semanaIntensivaId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($assignments);
    }

    /**
     * Bulk create assignments.
     */
    public function bulkStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'assignments' => 'required|array|min:1',
            'assignments.*.student_id' => 'required|exists:students,id',
            'assignments.*.period_id' => 'nullable|exists:periods,id',
            'assignments.*.grupo_id' => 'nullable|exists:grupos,id',
            'assignments.*.semana_intensiva_id' => 'nullable|exists:semanas_intensivas,id',
            'assignments.*.carrer_id' => 'nullable|exists:carreers,id',
            'assignments.*.assigned_at' => 'nullable|date',
            'assignments.*.valid_until' => 'nullable|date',
            'assignments.*.is_active' => 'boolean',
            'assignments.*.notes' => 'nullable|string|max:1000',
            'assignments.*.book_modulos' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
            'assignments.*.book_general' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $assignments = [];
        $now = now();

        foreach ($request->assignments as $assignmentData) {
            $assignmentData['assigned_at'] = $assignmentData['assigned_at'] ?? $now;
            $assignmentData['is_active'] = $assignmentData['is_active'] ?? true;

            $assignment = StudentAssignment::create($assignmentData);
            $assignments[] = $assignment->load(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera']);

            // Sync with Moodle
            $this->syncAssignmentWithMoodle($assignment);
        }

        return response()->json([
            'message' => 'Asignaciones creadas correctamente',
            'assignments' => $assignments
        ], 201);
    }

    /**
     * Bulk update assignments.
     */
    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'assignment_ids' => 'required|array|min:1',
            'assignment_ids.*' => 'exists:student_assignments,id',
            'updates' => 'required|array',
            'updates.period_id' => 'nullable|exists:periods,id',
            'updates.grupo_id' => 'nullable|exists:grupos,id',
            'updates.semana_intensiva_id' => 'nullable|exists:semanas_intensivas,id',
            'updates.carrer_id' => 'nullable|exists:carreers,id',
            'updates.valid_until' => 'nullable|date',
            'updates.is_active' => 'sometimes|boolean',
            'updates.notes' => 'nullable|string|max:1000',
            'updates.book_modulos' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
            'updates.book_general' => 'nullable|in:no entregado,paqueteria,en fisico,digital',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $assignmentIds = $request->assignment_ids;
        $updates = $request->updates;

        // Get assignments before update for Moodle sync
        $assignmentsBeforeUpdate = StudentAssignment::whereIn('id', $assignmentIds)
            ->select('id', 'student_id', 'grupo_id', 'semana_intensiva_id', 'carrer_id')
            ->get()
            ->keyBy('id');

        StudentAssignment::whereIn('id', $assignmentIds)->update($updates);

        $updatedAssignments = StudentAssignment::with(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera'])
            ->whereIn('id', $assignmentIds)
            ->get();

        // Sync with Moodle if grupo or semana intensiva changed
        if (isset($updates['grupo_id']) || isset($updates['semana_intensiva_id']) || isset($updates['carrer_id'])) {
            foreach ($updatedAssignments as $assignment) {
                $oldAssignment = $assignmentsBeforeUpdate->get($assignment->id);
                if ($oldAssignment) {
                    $this->updateMoodleCohorts(
                        $assignment,
                        $oldAssignment->grupo_id,
                        $assignment->grupo_id,
                        $oldAssignment->semana_intensiva_id,
                        $assignment->semana_intensiva_id,
                        $oldAssignment->carrer_id,
                        $assignment->carrer_id
                    );
                }
            }
        }

        return response()->json([
            'message' => 'Asignaciones actualizadas correctamente',
            'assignments' => $updatedAssignments
        ]);
    }

    /**
     * Toggle active status of assignments.
     */
    public function toggleActive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'assignment_ids' => 'required|array|min:1',
            'assignment_ids.*' => 'exists:student_assignments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $assignments = StudentAssignment::whereIn('id', $request->assignment_ids)->get();

        foreach ($assignments as $assignment) {
            $assignment->update(['is_active' => !$assignment->is_active]);
        }

        $updatedAssignments = StudentAssignment::with(['student', 'period', 'grupo', 'semanaIntensiva', 'carrera'])
            ->whereIn('id', $request->assignment_ids)
            ->get();

        return response()->json([
            'message' => 'Estado de asignaciones actualizado correctamente',
            'assignments' => $updatedAssignments
        ]);
    }

    /**
     * Get students that have active assignments in a specific period.
     */
    public function getStudentsByAssignedPeriod(Request $request, $periodId)
    {
        $campus_id = $request->get('campus_id');
        $search = $request->get('search');
        $searchDate = $request->get('searchDate');
        $searchPhone = $request->get('searchPhone');
        $searchMatricula = $request->get('searchMatricula');
        $grupo = $request->get('grupo');
        $semanaIntensivaFilter = $request->get('semanaIntensivaFilter');
        $perPage = $request->get('perPage', 10);
        $page = $request->get('page', 1);

        Log::info('Fetching students by assigned period', [
            'period_id' => $periodId,
            'campus_id' => $campus_id,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
        ]);

        // Start with the base query joining students through assignments
        $query = Student::with(['period', 'transactions', 'municipio', 'prepa', 'facultad', 'carrera', 'grupo'])
            ->whereHas('assignments', function ($q) use ($periodId) {
                $q->where('period_id', $periodId)
                    ->where('is_active', true);
            });

        // Apply campus filter
        if ($campus_id) {
            $query->where('campus_id', $campus_id);
        }

        // Apply search filters
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'LIKE', "%{$search}%")
                    ->orWhere('lastname', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if ($searchPhone) {
            $query->where('phone', 'LIKE', "%{$searchPhone}%");
        }

        if ($searchMatricula) {
            $query->where('id', 'LIKE', "%{$searchMatricula}%");
        }

        if ($searchDate) {
            $query->whereDate('created_at', $searchDate);
        }

        // Apply grupo filter - can filter by assignments or student's direct grupo
        if ($grupo) {
            $query->where(function ($q) use ($grupo, $periodId) {
                $q->where('grupo_id', $grupo)
                    ->orWhereHas('assignments', function ($subQ) use ($grupo, $periodId) {
                        $subQ->where('grupo_id', $grupo)
                            ->where('period_id', $periodId)
                            ->where('is_active', true);
                    });
            });
        }

        // Apply semana intensiva filter
        if ($semanaIntensivaFilter) {
            $query->where(function ($q) use ($semanaIntensivaFilter, $periodId) {
                $q->where('semana_intensiva_id', $semanaIntensivaFilter)
                    ->orWhereHas('assignments', function ($subQ) use ($semanaIntensivaFilter, $periodId) {
                        $subQ->where('semana_intensiva_id', $semanaIntensivaFilter)
                            ->where('period_id', $periodId)
                            ->where('is_active', true);
                    });
            });
        }

        // Get paginated results
        $students = $query->paginate($perPage, ['*'], 'page', $page);

        // Calculate debt for each student and add assignment info
        foreach ($students as $student) {
            $periodCost = $student->period ? $student->period->price : 0;
            $totalPaid = $student->transactions->sum('amount');
            $student->current_debt = $periodCost - $totalPaid;

            // Add information about assignments in this specific period
            $student->period_assignments = $student->assignments()
                ->with(['grupo', 'semanaIntensiva', 'carrera'])
                ->where('period_id', $periodId)
                ->where('is_active', true)
                ->get();
        }

        return response()->json($students);
    }

    /**
     * Sync assignment with Moodle when creating new assignments.
     */
    private function syncAssignmentWithMoodle(StudentAssignment $assignment)
    {
        try {
            $student = $assignment->student;

            if (!$student) {
                Log::warning('Student not found for assignment', ['assignment_id' => $assignment->id]);
                return;
            }

            // Ensure student has Moodle ID
            $this->ensureStudentHasMoodleId($student);

            $cohortsToAdd = [];

            // Add to grupo cohort if assigned
            if ($assignment->grupo_id && $assignment->grupo) {
                $grupo = $assignment->grupo;
                if ($grupo->moodle_id) {
                    $cohortsToAdd[] = [
                        'cohorttype' => ['type' => 'id', 'value' => $grupo->moodle_id],
                        'usertype' => ['type' => 'username', 'value' => (string) $student->id]
                    ];

                    Log::info('Preparing to add student to group cohort', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'grupo_id' => $grupo->id,
                        'grupo_moodle_id' => $grupo->moodle_id
                    ]);
                }
            }

            // Add to semana intensiva cohort if assigned
            if ($assignment->semana_intensiva_id && $assignment->semanaIntensiva) {
                $semanaIntensiva = $assignment->semanaIntensiva;
                if ($semanaIntensiva->moodle_id) {
                    $cohortsToAdd[] = [
                        'cohorttype' => ['type' => 'id', 'value' => $semanaIntensiva->moodle_id],
                        'usertype' => ['type' => 'username', 'value' => (string) $student->id]
                    ];

                    Log::info('Preparing to add student to semana intensiva cohort', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'semana_intensiva_id' => $semanaIntensiva->id,
                        'semana_moodle_id' => $semanaIntensiva->moodle_id
                    ]);
                }
            }

            // Add to carrer modulos cohorts if assigned
            if ($assignment->carrer_id && $assignment->carrera) {
                $carrera = $assignment->carrera()->with('modulos')->first();
                foreach ($carrera->modulos as $modulo) {
                    if ($modulo->moodle_id) {
                        $cohortsToAdd[] = [
                            'cohorttype' => ['type' => 'id', 'value' => $modulo->moodle_id],
                            'usertype' => ['type' => 'username', 'value' => (string) $student->id]
                        ];
                        Log::info('Preparing to add student to carrer modulo cohort', [
                            'student_id' => $student->id,
                            'assignment_id' => $assignment->id,
                            'carrer_id' => $carrera->id,
                            'modulo_id' => $modulo->id,
                            'modulo_moodle_id' => $modulo->moodle_id
                        ]);
                    }
                }
            }

            // Add to cohorts in Moodle
            if (!empty($cohortsToAdd)) {
                $response = $this->moodleService->cohorts()->addUserToCohort($cohortsToAdd);

                if ($response['status'] === 'success') {
                    Log::info('Student successfully added to cohorts in Moodle', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'cohorts_count' => count($cohortsToAdd)
                    ]);
                } else {
                    Log::error('Error adding student to cohorts in Moodle', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'error' => $response['message'] ?? 'Unknown error'
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Exception during Moodle sync for assignment', [
                'assignment_id' => $assignment->id,
                'student_id' => $assignment->student_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Update Moodle cohorts when assignment changes.
     */
    private function updateMoodleCohorts(
        StudentAssignment $assignment,
        $oldGrupoId,
        $newGrupoId,
        $oldSemanaIntensivaId,
        $newSemanaIntensivaId,
        $oldCarrerId,
        $newCarrerId
    ) {
        $student = $assignment->student;
        if (!$student || !$student->moodle_id) {
            Log::warning('Moodle sync skipped: student not found or has no moodle_id', [
                'student_id' => $assignment->student_id,
                'assignment_id' => $assignment->id,
            ]);
            return;
        }

        $cohortsToRemove = $this->prepareCohortsToRemove($student, $oldGrupoId, $newGrupoId, $oldSemanaIntensivaId, $newSemanaIntensivaId, $oldCarrerId, $newCarrerId);
        if (!empty($cohortsToRemove)) {
            $response = $this->moodleService->cohorts()->removeUsersFromCohorts($cohortsToRemove);
            Log::info('Moodle response for removing user from cohorts', [
                'student_id' => $student->id,
                'assignment_id' => $assignment->id,
                'cohorts_removed_payload' => $cohortsToRemove,
                'response' => $response
            ]);
        }

        $cohortsToAdd = $this->prepareCohortsToAdd($student, $oldGrupoId, $newGrupoId, $oldSemanaIntensivaId, $newSemanaIntensivaId, $oldCarrerId, $newCarrerId);
        if (!empty($cohortsToAdd)) {
            $response = $this->moodleService->cohorts()->addUserToCohort($cohortsToAdd);
            Log::info('Moodle response for adding user to cohorts', [
                'student_id' => $student->id,
                'assignment_id' => $assignment->id,
                'cohorts_added_payload' => $cohortsToAdd,
                'response' => $response
            ]);
        }
    }

    private function prepareCohortsToRemove($student, $oldGrupoId, $newGrupoId, $oldSemanaIntensivaId, $newSemanaIntensivaId, $oldCarrerId, $newCarrerId)
    {
        $cohortsToRemove = [];
        $studentMoodleId = $student->moodle_id;

        // Grupo cohort removal
        if ($oldGrupoId && $oldGrupoId !== $newGrupoId) {
            $oldGrupo = \App\Models\Grupo::find($oldGrupoId);
            if ($oldGrupo && $oldGrupo->moodle_id) {
                $cohortsToRemove[] = [
                    'cohortid' => $oldGrupo->moodle_id,
                    'userid' => $studentMoodleId
                ];
            }
        }

        // Semana Intensiva cohort removal
        if ($oldSemanaIntensivaId && $oldSemanaIntensivaId !== $newSemanaIntensivaId) {
            $oldSemanaIntensiva = \App\Models\SemanaIntensiva::find($oldSemanaIntensivaId);
            if ($oldSemanaIntensiva && $oldSemanaIntensiva->moodle_id) {
                $cohortsToRemove[] = [
                    'cohortid' => $oldSemanaIntensiva->moodle_id,
                    'userid' => $studentMoodleId
                ];
            }
        }

        // Carrer modulos cohorts removal
        if ($oldCarrerId && $oldCarrerId !== $newCarrerId) {
            $oldCarrera = \App\Models\Carrera::with('modulos')->find($oldCarrerId);
            if ($oldCarrera) {
                foreach ($oldCarrera->modulos as $modulo) {
                    if ($modulo->moodle_id) {
                        $cohortsToRemove[] = [
                            'cohortid' => $modulo->moodle_id,
                            'userid' => $studentMoodleId
                        ];
                    }
                }
            }
        }

        return $cohortsToRemove;
    }

    private function prepareCohortsToAdd($student, $oldGrupoId, $newGrupoId, $oldSemanaIntensivaId, $newSemanaIntensivaId, $oldCarrerId, $newCarrerId)
    {
        $cohortsToAdd = [];

        // Grupo cohort addition
        if ($newGrupoId && $newGrupoId !== $oldGrupoId) {
            $newGrupo = \App\Models\Grupo::find($newGrupoId);
            if ($newGrupo && $newGrupo->moodle_id) {
                $cohortsToAdd[] = [
                    'cohorttype' => ['type' => 'id', 'value' => $newGrupo->moodle_id],
                    'usertype' => ['type' => 'username', 'value' => (string) $student->id]
                ];
            }
        }

        // Semana Intensiva cohort addition
        if ($newSemanaIntensivaId && $newSemanaIntensivaId !== $oldSemanaIntensivaId) {
            $newSemanaIntensiva = \App\Models\SemanaIntensiva::find($newSemanaIntensivaId);
            if ($newSemanaIntensiva && $newSemanaIntensiva->moodle_id) {
                $cohortsToAdd[] = [
                    'cohorttype' => ['type' => 'id', 'value' => $newSemanaIntensiva->moodle_id],
                    'usertype' => ['type' => 'username', 'value' => (string) $student->id]
                ];
            }
        }

        // Carrer modulos cohorts addition
        if ($newCarrerId && $newCarrerId !== $oldCarrerId) {
            $newCarrera = \App\Models\Carrera::with('modulos')->find($newCarrerId);
            if ($newCarrera) {
                foreach ($newCarrera->modulos as $modulo) {
                    if ($modulo->moodle_id) {
                        $cohortsToAdd[] = [
                            'cohorttype' => ['type' => 'id', 'value' => $modulo->moodle_id],
                            'usertype' => ['type' => 'username', 'value' => (string) $student->id]
                        ];
                    }
                }
            }
        }

        return $cohortsToAdd;
    }

    /**
     * Remove assignment from Moodle when deleting assignments.
     */
    private function removeAssignmentFromMoodle(StudentAssignment $assignment)
    {
        try {
            $student = $assignment->student;

            if (!$student || !$student->moodle_id) {
                Log::warning('Student not found or has no moodle_id for assignment removal', [
                    'assignment_id' => $assignment->id,
                    'student_id' => $assignment->student_id
                ]);
                return;
            }

            $cohortsToRemove = [];

            // Remove from grupo cohort if assigned
            if ($assignment->grupo_id && $assignment->grupo) {
                $grupo = $assignment->grupo;
                if ($grupo->moodle_id) {
                    $cohortsToRemove[] = [
                        'cohortid' => $grupo->moodle_id,
                        'userid' => $student->moodle_id
                    ];

                    Log::info('Preparing to remove student from group cohort on assignment deletion', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'grupo_id' => $grupo->id,
                        'grupo_moodle_id' => $grupo->moodle_id
                    ]);
                }
            }

            // Remove from semana intensiva cohort if assigned
            if ($assignment->semana_intensiva_id && $assignment->semanaIntensiva) {
                $semanaIntensiva = $assignment->semanaIntensiva;
                if ($semanaIntensiva->moodle_id) {
                    $cohortsToRemove[] = [
                        'cohortid' => $semanaIntensiva->moodle_id,
                        'userid' => $student->moodle_id
                    ];

                    Log::info('Preparing to remove student from semana intensiva cohort on assignment deletion', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'semana_intensiva_id' => $semanaIntensiva->id,
                        'semana_moodle_id' => $semanaIntensiva->moodle_id
                    ]);
                }
            }

            // Remove from carrer modulos cohorts if assigned
            if ($assignment->carrer_id && $assignment->carrera) {
                $carrera = $assignment->carrera()->with('modulos')->first();
                foreach ($carrera->modulos as $modulo) {
                    if ($modulo->moodle_id) {
                        $cohortsToRemove[] = [
                            'cohortid' => $modulo->moodle_id,
                            'userid' => $student->moodle_id
                        ];

                        Log::info('Preparing to remove student from carrer modulo cohort on assignment deletion', [
                            'student_id' => $student->id,
                            'assignment_id' => $assignment->id,
                            'carrer_id' => $carrera->id,
                            'modulo_id' => $modulo->id,
                            'modulo_moodle_id' => $modulo->moodle_id
                        ]);
                    }
                }
            }

            // Remove from cohorts in Moodle
            if (!empty($cohortsToRemove)) {
                $response = $this->moodleService->cohorts()->removeUsersFromCohorts($cohortsToRemove);

                Log::info('Moodle response for removing user from all cohorts on assignment deletion', [
                    'student_id' => $student->id,
                    'assignment_id' => $assignment->id,
                    'cohorts_removed_payload' => $cohortsToRemove,
                    'response' => $response
                ]);

                if ($response['status'] === 'success') {
                    Log::info('Student successfully removed from all cohorts in Moodle on assignment deletion', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'cohorts_count' => count($cohortsToRemove)
                    ]);
                } else {
                    Log::error('Error removing student from cohorts in Moodle on assignment deletion', [
                        'student_id' => $student->id,
                        'assignment_id' => $assignment->id,
                        'error' => $response['message'] ?? 'Unknown error'
                    ]);
                }
            } else {
                Log::info('No cohorts to remove for assignment deletion', [
                    'student_id' => $student->id,
                    'assignment_id' => $assignment->id
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Exception during Moodle sync for assignment deletion', [
                'assignment_id' => $assignment->id,
                'student_id' => $assignment->student_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Ensure student has a Moodle ID, fetch and save if missing.
     */
    private function ensureStudentHasMoodleId(Student $student): void
    {
        if (!$student->moodle_id) {
            $username = (string) $student->id;
            $moodleUser = $this->moodleService->users()->getUserByUsername($username);

            if ($moodleUser['status'] === 'success' && isset($moodleUser['data']['id'])) {
                $student->moodle_id = $moodleUser['data']['id'];
                $student->save();

                Log::info('Moodle ID fetched and saved for student', [
                    'student_id' => $student->id,
                    'moodle_id' => $student->moodle_id
                ]);
            } else {
                Log::warning('Failed to fetch Moodle ID for student', [
                    'student_id' => $student->id,
                    'username' => $username,
                    'error' => $moodleUser['message'] ?? 'Unknown error'
                ]);

                throw new \Exception('Failed to fetch Moodle ID for student: ' . $student->id);
            }
        }
    }

    public function getStudentGrades(Request $request, $studentId)
    {
        try {
            $student = Student::findOrFail($studentId);

            $result = $this->gradesService->getStudentGrades($student);

            if (!$result['success']) {
                return response()->json([
                    'message' => $result['error']
                ], 500);
            }

            return response()->json($result['data']);



        } catch (\Exception $e) {
            Log::error('Error obteniendo calificaciones del estudiante', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al obtener calificaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getStudentCourses(Request $request, $studentId)
    {
        try {
            $student = Student::findOrFail($studentId);

            $this->ensureStudentHasMoodleId($student);

            // Obtener resumen de calificaciones que incluye los cursos
            $gradesOverview = $this->moodleService->grades()->getCourseGradesOverview($student->moodle_id);

            if (!$gradesOverview || !isset($gradesOverview['grades'])) {
                return response()->json([
                    'message' => 'No se encontraron cursos para este estudiante en Moodle',
                    'student' => [
                        'id' => $student->id,
                        'firstname' => $student->firstname,
                        'lastname' => $student->lastname,
                        'moodle_id' => $student->moodle_id,
                    ],
                    'courses' => []
                ]);
            }

            $coursesWithInfo = [];
            foreach ($gradesOverview['grades'] as $courseGrade) {
                $coursesWithInfo[] = [
                    'moodle_course_id' => $courseGrade['courseid'],
                    'course_name' => $courseGrade['coursename'] ?? 'Curso desconocido',
                    'course_shortname' => $courseGrade['courseshortname'] ?? '',
                    'grade' => $courseGrade['grade'] ?? null,
                ];
            }

            return response()->json([
                'student' => [
                    'id' => $student->id,
                    'firstname' => $student->firstname,
                    'lastname' => $student->lastname,
                    'moodle_id' => $student->moodle_id,
                ],
                'courses' => $coursesWithInfo,
            ]);

        } catch (\Exception $e) {
            Log::error('Error obteniendo cursos del estudiante', [
                'student_id' => $studentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al obtener cursos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCourseActivities(Request $request, $studentId, $courseId)
    {
        try {
            $student = Student::findOrFail($studentId);

            $this->ensureStudentHasMoodleId($student);

            $courseContents = $this->moodleService->courses()->getCourseContents($courseId);

            if (!$courseContents) {
                return response()->json([
                    'message' => 'No se pudo obtener el contenido del curso',
                    'data' => []
                ], 404);
            }

            $gradableActivities = [];
            foreach ($courseContents as $section) {
                if (isset($section['modules'])) {
                    foreach ($section['modules'] as $module) {
                        if (
                            isset($module['modname']) &&
                            in_array($module['modname'], ['assign', 'quiz', 'forum', 'workshop'])
                        ) {
                            $gradableActivities[] = [
                                'id' => $module['id'],
                                'name' => $module['name'],
                                'type' => $module['modname'],
                                'section' => $section['name'],
                            ];
                        }
                    }
                }
            }

            return response()->json([
                'student_id' => $student->id,
                'course_id' => $courseId,
                'activities' => $gradableActivities,
                'activities_count' => count($gradableActivities),
            ]);

        } catch (\Exception $e) {
            Log::error('Error obteniendo actividades del curso', [
                'student_id' => $studentId,
                'course_id' => $courseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error al obtener actividades',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
