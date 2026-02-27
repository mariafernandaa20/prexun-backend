<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nomina;
use App\Services\NominaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class NominaPublicController extends Controller
{
    protected $nominaService;

    public function __construct(NominaService $nominaService)
    {
        $this->nominaService = $nominaService;
    }

    /**
     * Obtiene información pública de la nómina mediante su token.
     */
    public function getInfo(string $token)
    {
        $nomina = Nomina::with(['user:id,name,rfc', 'seccion:id,nombre'])
            ->where('external_token', $token)
            ->first();

        if (!$nomina) {
            return response()->json(['error' => 'Nómina no encontrada.'], 404);
        }

        return response()->json([
            'id' => $nomina->id,
            'token' => $nomina->external_token,
            'user' => [
                'name' => $nomina->user->name,
                'rfc' => $nomina->user->rfc,
            ],
            'seccion' => $nomina->seccion->nombre,
            'estado' => $nomina->estado,
            'fecha_firma' => $nomina->fecha_firma,
            'created_at' => $nomina->created_at,
        ]);
    }

    /**
     * Firma la nómina mediante el token externo.
     */
    public function sign(Request $request, string $token)
    {
        $nomina = Nomina::where('external_token', $token)->first();

        if (!$nomina) {
            return response()->json(['error' => 'Nómina no encontrada.'], 404);
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
     * Visualización del PDF mediante el token.
     */
    public function view(string $token)
    {
        $nomina = Nomina::where('external_token', $token)->first();

        if (!$nomina) {
            return response()->json(['error' => 'Nómina no encontrada.'], 404);
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
