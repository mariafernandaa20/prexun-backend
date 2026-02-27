<?php

namespace App\Http\Controllers;

use App\Models\Mensaje;
use App\Models\Student;
use App\Services\AIFunctionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MensajeController extends Controller
{
    private AIFunctionService $aiService;

    public function __construct(AIFunctionService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function guardarmensaje(Request $request) 
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'mensaje' => 'required|string',
            'student_id' => 'required|integer|exists:students,id',
            'role' => 'required|string',
        ]); 

        $message = Mensaje::create([
            'nombre' => $request->nombre,
            'mensaje' => $request->mensaje,
            'student_id' => $request->student_id,
            'role' => $request->role,
        ]); 

        $aiResponse = null;
        
        if ($request->role === 'user') {
            try {
                $student = Student::find($request->student_id);
                
                if (!$student) {
                    throw new \Exception('Estudiante no encontrado');
                }
                
                $conversationHistory = Mensaje::where('student_id', $request->student_id)
                    ->orderBy('created_at', 'desc')
                    ->take(10)
                    ->get()
                    ->reverse();

                $systemMessage = $this->buildSystemMessage($student);
                
                $messages = [
                    ['role' => 'system', 'content' => $systemMessage]
                ];
                
                foreach ($conversationHistory as $msg) {
                    $messages[] = [
                        'role' => $msg->role === 'user' ? 'user' : 'model',
                        'content' => $msg->mensaje
                    ];
                }
                
                $messages[] = [
                    'role' => 'user',
                    'content' => $request->mensaje
                ];

                $gemini = app(\App\Services\GeminiService::class);
                $result = $gemini->chat($messages, 500, 0.7);

                if ($result['success'] && isset($result['content'])) {
                    $aiResponse = $result['content'];
                    
                    Mensaje::create([
                        'nombre' => 'Asistente IA',
                        'mensaje' => $aiResponse,
                        'student_id' => $request->student_id,
                        'role' => 'assistant',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error generando respuesta de IA', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'student_id' => $request->student_id
                ]);
                
                $aiResponse = 'Lo siento, hubo un error al procesar tu mensaje. Por favor intenta de nuevo.';
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Mensaje guardado correctamente',
            'mensaje' => $message,
            'response' => $aiResponse
        ]);
    }
    
    public function index(Request $request)
    {
        $studentId = $request->query('student_id');
        
        if ($studentId) {
            $mensajes = Mensaje::where('student_id', $studentId)
                              ->orderBy('created_at', 'asc')
                              ->get();
        } else {
            $mensajes = Mensaje::orderBy('created_at', 'desc')->get();
        }
        
        return response()->json([
            'success' => true,
            'data' => $mensajes
        ]);
    }

    public function destroy(Request $request)
    {
        $studentId = $request->query('student_id');
        
        if ($studentId) {
            Mensaje::where('student_id', $studentId)->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Mensajes del estudiante eliminados correctamente'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'student_id es requerido'
        ], 400);
    }

    private function buildSystemMessage(Student $student): string
    {
        $message = "Eres un asistente de IA para una institución educativa en México.\n\n";
        $message .= "IMPORTANTE: Debes responder SIEMPRE en ESPAÑOL.\n\n";
        
        $message .= "INFORMACIÓN DEL ESTUDIANTE:\n";
        $message .= "- Nombre: {$student->nombre} {$student->apellido_paterno} {$student->apellido_materno}\n";
        $message .= "- Matrícula: {$student->matricula}\n";
        $message .= "- ID: {$student->id}\n";
        $message .= "- Email: {$student->email}\n";
        $message .= "- Campus ID: {$student->campus_id}\n";
        $message .= "- Carrera ID: {$student->carrer_id}\n";
        $message .= "- Estado: " . ($student->activo ? 'Activo' : 'Inactivo') . "\n\n";
        
        $message .= "Tu trabajo es:\n";
        $message .= "1. Responder de manera amigable y profesional\n";
        $message .= "2. Usar un tono formal pero cercano\n";
        $message .= "3. Saludar al estudiante por su nombre cuando sea apropiado\n";
        $message .= "4. Proporcionar información útil y clara\n";
        $message .= "5. Mantener las respuestas concisas pero completas\n\n";
        
        $message .= "Si te preguntan qué modelo eres, responde que eres un asistente basado en Gemini AI de Google.\n";
        
        return $message;
    }
}
