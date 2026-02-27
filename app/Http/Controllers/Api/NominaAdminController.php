<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nomina;
use App\Models\NominaSeccion;
use App\Models\User;
use App\Models\Notification;
use App\Services\NominaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class NominaAdminController extends Controller
{
    protected $nominaService;

    public function __construct(NominaService $nominaService)
    {
        $this->nominaService = $nominaService;
    }

    /**
     * Listado de secciones de nóminas con sus respectivos registros.
     */
    public function index()
    {
        $secciones = NominaSeccion::withCount([
            'nominas as total_nominas',
            'nominas as firmadas_count' => function ($query) {
                $query->where('estado', 'firmado');
            },
            'nominas as pendientes_count' => function ($query) {
                $query->where('estado', 'pendiente');
            }
        ])->orderBy('fecha_subida', 'desc')->get();

        return response()->json($secciones);
    }

    /**
     * Obtiene usuarios activos que no son proveedores.
     */
    public function getActiveUsers()
    {
        $users = User::where('suspendido', false)
            ->where('role', '!=', 'proveedor')
            ->orderBy('name')
            ->get(['id', 'name', 'rfc', 'role']);

        return response()->json($users);
    }

    /**
     * Subida masiva de nóminas y creación de sección.
     */
    public function store(Request $request)
    {
        // Validación más permisiva para evitar bloqueos por campos vacíos
        $request->validate([
            'nombre' => 'nullable|string',
            'seccion_id' => 'nullable|exists:nomina_secciones,id',
            'files' => 'nullable',
            'files.*' => 'mimes:pdf,xml|max:10240',
        ]);

        if ($request->seccion_id) {
            $seccion = NominaSeccion::findOrFail($request->seccion_id);
        } else {
            $seccion = NominaSeccion::create([
                'nombre' => $request->nombre ?? 'Sin nombre ' . now()->format('Y-m-d H:i'),
                'fecha_subida' => now(),
            ]);
        }

        // Obtener archivos de forma segura
        $files = $request->file('files');
        if (!is_array($files)) {
            $files = $files ? [$files] : [];
        }
        
        $results = $this->nominaService->processUpload($files, $seccion);

        return response()->json([
            'message' => 'Procesamiento completado',
            'seccion_id' => $seccion->id,
            'seccion' => $seccion,
            'results' => $results
        ]);
    }

    /**
     * Sube una nómina directamente a un usuario específico.
     */
    public function uploadToUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'seccion_id' => 'required|exists:nomina_secciones,id',
            'file' => 'required|mimes:pdf|max:10240',
        ]);

        $seccion = NominaSeccion::findOrFail($request->seccion_id);
        $user = User::findOrFail($request->user_id);
        
        $file = $request->file('file');
        // Generas un nombre tipo: 17152345_nomina_enero.pdf
        $fileName = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs("nominas/{$seccion->id}", $fileName, 'private');
        
        $nomina = Nomina::create([
            'user_id' => $user->id,
            'seccion_id' => $seccion->id,
            'archivo_original_path' => $path,
            'estado' => 'pendiente',
        ]);

        // Crear notificación para el usuario
        Notification::create([
            'user_id' => $user->id,
            'title' => 'Nueva Nómina Asignada',
            'message' => "Se te ha asignado una nueva nómina de la sección '{$seccion->nombre}' manualmente.",
            'path' => '/nominas',
        ]);

        return response()->json([
            'message' => 'Nómina asignada correctamente',
            'nomina' => $nomina
        ]);
    }

    /**
     * Detalle de una sección específica.
     */
    public function showSeccion(NominaSeccion $seccion)
    {
        $seccion->load('nominas.user:id,name,rfc');
        return response()->json($seccion);
    }

    /**
     * Visualización segura del PDF.
     */
    public function showNomina(Nomina $nomina)
    {
        try {
            $content = $this->nominaService->getFileContent($nomina);
            
            return Response::make($content, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="nomina_' . $nomina->id . '.pdf"'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
    /**
     * Elimina una nómina y sus archivos asociados.
     */
    public function destroy(Nomina $nomina)
    {
        try {
            // Eliminar archivos del storage
            if ($nomina->archivo_original_path) {
                \Illuminate\Support\Facades\Storage::disk('private')->delete($nomina->archivo_original_path);
            }
            if ($nomina->archivo_firmado_path) {
                \Illuminate\Support\Facades\Storage::disk('private')->delete($nomina->archivo_firmado_path);
            }

            // Eliminar registro
            $nomina->delete();

            return response()->json(['message' => 'Nómina eliminada correctamente']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'No se pudo eliminar la nómina: ' . $e->getMessage()], 500);
        }
    }
}
