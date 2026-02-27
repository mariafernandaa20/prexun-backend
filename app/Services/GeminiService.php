<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private string $model = 'gemini-2.0-flash';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    public function chat(array $messages, int $maxTokens = 500, float $temperature = 0.7): array
    {
        try {
            if (!$this->apiKey) {
                return [
                    'success' => false,
                    'error' => 'API key de Gemini no configurada'
                ];
            }

            $contents = $this->convertMessagesToGeminiFormat($messages);
            $systemInstruction = $this->extractSystemInstruction($messages);

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ]
            ];

            if ($systemInstruction) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $systemInstruction]]
                ];
            }

            $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Error de Gemini API', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Error en la API de Gemini: ' . $response->status()
                ];
            }

            $data = $response->json();
            
            $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            return [
                'success' => true,
                'content' => $content,
                'data' => $data
            ];

        } catch (\Exception $e) {
            Log::error('Error al conectar con Gemini', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Error de conexión con Gemini: ' . $e->getMessage()
            ];
        }
    }

    public function chatWithFunctionCalling(
        array $messages, 
        array $tools, 
        int $maxTokens = 500, 
        float $temperature = 0.7
    ): array {
        try {
            if (!$this->apiKey) {
                return [
                    'success' => false,
                    'error' => 'API key de Gemini no configurada'
                ];
            }

            $contents = $this->convertMessagesToGeminiFormat($messages);
            $systemInstruction = $this->extractSystemInstruction($messages);
            $geminiTools = $this->convertToolsToGeminiFormat($tools);

            $payload = [
                'contents' => $contents,
                'tools' => $geminiTools,
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens,
                ]
            ];

            if ($systemInstruction) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $systemInstruction]]
                ];
            }

            $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Error de Gemini API con funciones', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Error en la API de Gemini: ' . $response->status()
                ];
            }

            $data = $response->json();
            
            return [
                'success' => true,
                'data' => $data
            ];

        } catch (\Exception $e) {
            Log::error('Error al conectar con Gemini para funciones', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Error de conexión con Gemini'
            ];
        }
    }

    private function convertMessagesToGeminiFormat(array $messages): array
    {
        $contents = [];
        
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                continue;
            }

            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            
            if (isset($message['tool_calls'])) {
                $parts = [];
                foreach ($message['tool_calls'] as $toolCall) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $toolCall['function']['name'],
                            'args' => json_decode($toolCall['function']['arguments'], true)
                        ]
                    ];
                }
                
                $contents[] = [
                    'role' => 'model',
                    'parts' => $parts
                ];
            } elseif (isset($message['tool_call_id'])) {
                $contents[] = [
                    'role' => 'function',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $message['name'],
                                'response' => [
                                    'content' => $message['content']
                                ]
                            ]
                        ]
                    ]
                ];
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $message['content']]]
                ];
            }
        }

        return $contents;
    }

    private function extractSystemInstruction(array $messages): ?string
    {
        $systemMessages = array_filter($messages, fn($msg) => $msg['role'] === 'system');
        
        if (empty($systemMessages)) {
            return null;
        }

        return implode("\n\n", array_map(fn($msg) => $msg['content'], $systemMessages));
    }

    private function convertToolsToGeminiFormat(array $tools): array
    {
        $functionDeclarations = [];

        foreach ($tools as $tool) {
            if ($tool['type'] === 'function') {
                $function = $tool['function'];
                
                $functionDeclarations[] = [
                    'name' => $function['name'],
                    'description' => $function['description'],
                    'parameters' => $this->convertParametersToGeminiFormat($function['parameters'])
                ];
            }
        }

        return [
            [
                'functionDeclarations' => $functionDeclarations
            ]
        ];
    }

    private function convertParametersToGeminiFormat(array $parameters): array
    {
        $geminiParams = [
            'type' => 'OBJECT',
            'properties' => [],
            'required' => $parameters['required'] ?? []
        ];

        foreach ($parameters['properties'] as $propName => $propDef) {
            $geminiParams['properties'][$propName] = [
                'type' => strtoupper($propDef['type']),
                'description' => $propDef['description']
            ];
        }

        return $geminiParams;
    }

    public function extractFunctionCalls(array $geminiResponse): array
    {
        $functionCalls = [];

        if (!isset($geminiResponse['candidates'][0]['content']['parts'])) {
            return $functionCalls;
        }

        foreach ($geminiResponse['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['functionCall'])) {
                $functionCalls[] = [
                    'function' => [
                        'name' => $part['functionCall']['name'],
                        'arguments' => json_encode($part['functionCall']['args'])
                    ]
                ];
            }
        }

        return $functionCalls;
    }

    public function extractTextResponse(array $geminiResponse): ?string
    {
        if (!isset($geminiResponse['candidates'][0]['content']['parts'])) {
            return null;
        }

        foreach ($geminiResponse['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }

        return null;
    }
}
