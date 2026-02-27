<?php

/**
 * Script de prueba rápida para Gemini
 * 
 * Uso: php test_gemini.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\GeminiService;
use App\Services\AIFunctionService;

echo "=================================\n";
echo "  PRUEBA DE GEMINI AI\n";
echo "=================================\n\n";

// Test 1: Gemini básico
echo "📝 Test 1: Chat simple con Gemini\n";
echo "---------------------------------\n";

$gemini = app(GeminiService::class);

$response = $gemini->chat([
    ['role' => 'user', 'content' => 'Responde en español: ¿Qué es Laravel?']
], 200, 0.7);

if ($response['success']) {
    echo "✅ Éxito!\n";
    echo "Respuesta: " . $response['content'] . "\n\n";
} else {
    echo "❌ Error: " . $response['error'] . "\n\n";
}

// Test 2: Verificar proveedor en AIFunctionService
echo "📝 Test 2: Verificar proveedor de IA\n";
echo "---------------------------------\n";

$aiService = app(AIFunctionService::class);
$provider = $aiService->getAIProvider();

echo "✅ Proveedor actual: " . strtoupper($provider) . "\n\n";

// Test 3: Gemini con mensajes múltiples
echo "📝 Test 3: Conversación con contexto\n";
echo "---------------------------------\n";

$response = $gemini->chat([
    ['role' => 'user', 'content' => 'Mi nombre es Juan'],
    ['role' => 'model', 'content' => 'Hola Juan, es un placer conocerte.'],
    ['role' => 'user', 'content' => '¿Cuál es mi nombre?']
], 100, 0.7);

if ($response['success']) {
    echo "✅ Éxito! Gemini recuerda el contexto\n";
    echo "Respuesta: " . $response['content'] . "\n\n";
} else {
    echo "❌ Error: " . $response['error'] . "\n\n";
}

echo "=================================\n";
echo "  FIN DE PRUEBAS\n";
echo "=================================\n";
