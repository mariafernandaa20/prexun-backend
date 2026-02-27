<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nomina;
use App\Services\NominaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class NominaUserController extends Controller
{
    protected $nominaService;

    public function __construct(NominaService $nominaService)
    {
        $this->nominaService = $nominaService;
    }

    /**
     * Listado de nóminas del usuario autenticado.
     */
    public function index()
    {
        $user = Auth::user();
        $nominas = Nomina::with('seccion')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($nominas);
    }

    /**
     * Firma de una nómina específica.
     */
    public function sign(Request $request, Nomina $nomina)
    {
        // Verificar propiedad
        if ($nomina->user_id !== Auth::id()) {
            return response()->json(['error' => 'No tienes permiso para firmar esta nómina.'], 403);
        }

        $request->validate([
            'signature' => 'required|string', // Base64 de la firma
        ]);

        try {
            $this->nominaService->signNomina($nomina, $request->signature);
            return response()->json([
                'message' => 'Nómina firmada exitosamente',
                'nomina' => $nomina->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Visualización segura del PDF para el usuario.
     */
    public function show(Nomina $nomina)
    {
        if ($nomina->user_id !== Auth::id()) {
            return response()->json(['error' => 'No tienes permiso para ver esta nómina.'], 403);
        }

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
}
