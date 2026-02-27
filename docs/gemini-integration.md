# Integración de Gemini AI

## Descripción

Se ha integrado Google Gemini como proveedor principal de IA en el proyecto, manteniendo OpenAI (ChatGPT) como alternativa disponible.

## Configuración

### 1. Variables de Entorno

Agregar en el archivo `.env`:

```bash
# API Key de Gemini (requerida)
GEMINI_API_KEY=tu_api_key_aqui

# API Key de OpenAI (opcional, solo si usarás el fallback)
OPENAI_API_KEY=tu_api_key_aqui
```

### 2. Obtener API Key de Gemini

1. Ir a [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Iniciar sesión con tu cuenta de Google
3. Crear o copiar una API Key
4. Agregar la clave al archivo `.env`

## Arquitectura

### Archivos Creados/Modificados

1. **`app/Services/GeminiService.php`** (NUEVO)
   - Servicio dedicado para interactuar con la API de Gemini
   - Maneja chat simple y function calling
   - Convierte formatos entre OpenAI y Gemini

2. **`app/Services/AIFunctionService.php`** (MODIFICADO)
   - Ahora usa Gemini por defecto
   - Mantiene OpenAI disponible como alternativa
   - Agrega métodos para cambiar entre proveedores

3. **`config/services.php`** (MODIFICADO)
   - Agregada configuración de Gemini

4. **`database/migrations/2025_07_22_211426_create_contexts_table.php`** (MODIFICADO)
   - Actualizados comentarios para reflejar el uso de IA genérica

## Uso

### Uso por defecto (Gemini)

```php
use App\Services\AIFunctionService;

$aiService = app(AIFunctionService::class);

$response = $aiService->processWhatsAppMessage(
    phoneNumber: '+523121234567',
    incomingMessage: '¿Cuáles son mis calificaciones?',
    conversationHistory: []
);
```

### Uso explícito con Gemini

```php
$response = $aiService->processWithGemini(
    phoneNumber: '+523121234567',
    incomingMessage: '¿Cuáles son mis calificaciones?',
    conversationHistory: []
);
```

### Uso con OpenAI (alternativa)

```php
$response = $aiService->processWithOpenAI(
    phoneNumber: '+523121234567',
    incomingMessage: '¿Cuáles son mis calificaciones?',
    conversationHistory: []
);
```

### Cambiar proveedor dinámicamente

```php
// Cambiar a OpenAI
$aiService->setAIProvider('openai');
$response = $aiService->processWhatsAppMessage(...);

// Volver a Gemini
$aiService->setAIProvider('gemini');
$response = $aiService->processWhatsAppMessage(...);

// Ver proveedor actual
$currentProvider = $aiService->getAIProvider(); // 'gemini' o 'openai'
```

## Características

### GeminiService

#### Métodos Principales

1. **`chat(array $messages, int $maxTokens, float $temperature): array`**
   - Chat simple sin funciones
   - Retorna texto de respuesta

2. **`chatWithFunctionCalling(array $messages, array $tools, int $maxTokens, float $temperature): array`**
   - Chat con capacidad de llamar funciones MCP
   - Convierte tools de formato OpenAI a Gemini
   - Retorna respuesta completa con function calls

#### Métodos Auxiliares

- `extractFunctionCalls(array $geminiResponse): array` - Extrae llamadas a funciones
- `extractTextResponse(array $geminiResponse): ?string` - Extrae texto de respuesta
- `convertMessagesToGeminiFormat(array $messages): array` - Convierte mensajes
- `convertToolsToGeminiFormat(array $tools): array` - Convierte herramientas

### AIFunctionService

#### Métodos Públicos Nuevos

1. **`setAIProvider(string $provider): void`**
   - Cambia el proveedor de IA
   - Valores: `'gemini'` o `'openai'`

2. **`getAIProvider(): string`**
   - Retorna el proveedor actual

3. **`processWithOpenAI(...): array`**
   - Fuerza el uso de OpenAI temporalmente
   - Restaura el proveedor original después

4. **`processWithGemini(...): array`**
   - Fuerza el uso de Gemini temporalmente
   - Restaura el proveedor original después

#### Métodos Privados Nuevos

1. **`callAIWithFunctions(array $messages, array $tools): array`**
   - Delegador que elige entre Gemini u OpenAI

2. **`callGeminiWithFunctions(array $messages, array $tools): array`**
   - Llamada específica a Gemini

3. **`processAIResponse(array $aiResponse): array`**
   - Delegador de procesamiento

4. **`processGeminiResponse(array $geminiResponse): array`**
   - Procesa respuestas de Gemini y ejecuta funciones MCP

## Ventajas de Gemini

1. **Costo**: Gemini Flash es más económico que GPT-4
2. **Velocidad**: Respuestas más rápidas
3. **Context Window**: Mayor capacidad de contexto
4. **Multimodal**: Soporte nativo para imágenes (futuro)
5. **API más simple**: Menos configuración

## Modelos Disponibles

### Gemini 1.5 Flash (actual)
- Modelo: `gemini-1.5-flash`
- Rápido y económico
- Ideal para chat y funciones
- Context: 1M tokens

### Gemini 1.5 Pro (opcional)
- Modelo: `gemini-1.5-pro`
- Más potente
- Mayor precisión
- Context: 2M tokens

Para cambiar el modelo, editar en `GeminiService.php`:

```php
private string $model = 'gemini-1.5-pro'; // o 'gemini-1.5-flash'
```

## Compatibilidad

✅ **Totalmente compatible** con el código existente
- Los controladores no necesitan cambios
- Las rutas siguen funcionando igual
- El WhatsApp webhook sigue funcionando
- Todas las funciones MCP funcionan igual

## Fallback a OpenAI

Si Gemini falla por cualquier razón, puedes cambiar temporalmente:

```php
// En caso de emergencia, cambiar por defecto a OpenAI
// Editar AIFunctionService.php línea 19:
private string $aiProvider = 'openai'; // cambiar 'gemini' por 'openai'
```

## Testing

```bash
# Probar servicio de Gemini
php artisan tinker

$gemini = app(\App\Services\GeminiService::class);
$response = $gemini->chat([
    ['role' => 'user', 'content' => '¿Cómo estás?']
]);
print_r($response);
```

## Monitoreo

Todos los logs de Gemini están en:
- `storage/logs/laravel.log`
- Búsqueda: "Gemini" o "Ejecutando función MCP solicitada por Gemini"

## Costos Aproximados

### Gemini Flash (actual)
- Input: $0.075 / 1M tokens
- Output: $0.30 / 1M tokens
- ~10x más barato que GPT-4

### OpenAI (alternativa)
- GPT-4o-mini: $0.15 / 1M input, $0.60 / 1M output

## Troubleshooting

### Error: "API key de Gemini no configurada"
- Verificar que `GEMINI_API_KEY` esté en `.env`
- Ejecutar `php artisan config:clear`

### Error: "Error en la API de Gemini: 400"
- Verificar que la API key sea válida
- Verificar límites de rate de la API

### Respuestas en inglés
- El sistema está configurado para forzar español
- Si persiste, revisar los system messages

## Próximos Pasos

1. ✅ Integración básica de Gemini
2. ✅ Function calling con MCP
3. ✅ Mantener OpenAI como alternativa
4. 🔄 Monitorear performance y costos
5. 📋 Considerar multimodal (imágenes) en futuro

## Referencias

- [Gemini API Docs](https://ai.google.dev/docs)
- [Gemini Pricing](https://ai.google.dev/pricing)
- [Function Calling](https://ai.google.dev/docs/function_calling)
