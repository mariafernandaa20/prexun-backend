# 🚀 Chat con Gemini IA - Guía de Uso

## ✅ Integración Completada

El sistema de chat ahora está completamente integrado con **Gemini AI**. Aquí está todo lo que necesitas saber:

---

## 📋 Cambios Realizados

### Backend

1. **MensajeController actualizado**
   - Ahora genera respuestas automáticas con Gemini
   - Guarda tanto el mensaje del usuario como la respuesta de la IA
   - Mantiene historial de conversación para contexto

2. **Rutas API agregadas**
   - `POST /api/mensajes` - Enviar mensaje y recibir respuesta IA
   - `GET /api/mensajes?student_id=X` - Obtener historial de chat
   - `DELETE /api/mensajes?student_id=X` - Limpiar chat (botón Reset)

3. **Integración con AIFunctionService**
   - Usa Gemini por defecto
   - OpenAI disponible como fallback
   - Acceso a funciones MCP (calificaciones, pagos, etc.)

### Frontend

El frontend ya está listo en [`/app/(protected)/chat/page.tsx`](app/(protected)/chat/page.tsx) y tiene:
- Selector de estudiante
- Chat en tiempo real
- Soporte para imágenes
- Formato Markdown en respuestas
- Historial persistente
- Botón Reset

---

## 🔧 Configuración Requerida

### 1. API Key de Gemini

Ya está configurada en tu `.env`:
```bash
GEMINI_API_KEY=
```

### 2. Verificar Base de Datos

Asegúrate de tener la conexión correcta en `.env`:
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tu_base_de_datos
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password
```

### 3. Limpiar Caché

```bash
cd /Users/emmanuel/Documents/GitHub/prexun-backend
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

---

## 🎯 Cómo Usar el Chat

### Desde el Frontend

1. **Accede a la página de chat:**
   ```
   http://localhost:3000/chat
   ```

2. **Selecciona un estudiante** del dropdown

3. **Escribe tu mensaje** y presiona Enter o el botón de enviar

4. **Gemini responderá automáticamente** con:
   - Información del estudiante
   - Acceso a funciones MCP (calificaciones, pagos, etc.)
   - Respuestas en español
   - Formato profesional

### Ejemplo de Conversación

```
Usuario: Hola, ¿cuáles son mis calificaciones?

Asistente IA: ¡Hola María! 👋

Aquí están tus calificaciones actuales:

📊 CALIFICACIONES

Curso: Matemáticas
- Examen 1: 85/100
- Examen 2: 90/100
- Promedio: 87.5

Curso: Español
- Ensayo 1: 95/100
- Ensayo 2: 88/100
- Promedio: 91.5

¿Necesitas información sobre algún curso específico?
```

---

## 🧪 Pruebas

### Probar Gemini Directamente

```bash
cd /Users/emmanuel/Documents/GitHub/prexun-backend
php test_gemini.php
```

### Probar Integración Completa

```bash
php test_chat_integration.php
```

**Nota:** Si aparece error de base de datos, verifica tu configuración en `.env`

---

## 🔍 Troubleshooting

### Problema: No se genera respuesta

**Solución:**
1. Verifica que `GEMINI_API_KEY` esté en `.env`
2. Ejecuta: `php artisan config:clear`
3. Revisa logs: `storage/logs/laravel.log`

### Problema: Respuestas en inglés

**Solución:**
- El sistema está configurado para forzar español
- Si persiste, revisa la tabla `contexts` para instrucciones activas
- Las instrucciones por defecto ya fuerzan español

### Problema: Error de base de datos

**Solución:**
```bash
# Verifica tu .env
cat .env | grep DB_

# Prueba la conexión
php artisan migrate:status
```

### Problema: Timeout o respuestas lentas

**Solución:**
- Gemini Flash es rápido, pero depende de tu conexión
- El timeout está configurado a 30 segundos
- Si es muy lento, considera cambiar a OpenAI temporalmente:

```php
// En cualquier controlador o servicio
$aiService->setAIProvider('openai');
```

---

## 🎨 Funcionalidades del Chat

### ✅ Disponibles Ahora

- [x] Chat en tiempo real con Gemini
- [x] Historial de conversación por estudiante
- [x] Respuestas automáticas inteligentes
- [x] Acceso a funciones MCP (calificaciones, pagos, asistencias)
- [x] Formato Markdown en respuestas
- [x] Soporte multimodal (imágenes)
- [x] Reset de conversación
- [x] Respuestas en español forzado
- [x] OpenAI como alternativa

### 🔄 Próximamente

- [ ] Contextos personalizados por estudiante
- [ ] Análisis de sentimiento
- [ ] Respuestas sugeridas
- [ ] Exportar conversaciones

---

## 📊 Ventajas de Usar Gemini

1. **Costo:** ~10x más barato que GPT-4
2. **Velocidad:** Respuestas más rápidas
3. **Context Window:** 1M tokens (mucho contexto)
4. **Multimodal:** Soporte nativo para imágenes
5. **API Simple:** Fácil de mantener

---

## 🔐 Seguridad

- Las conversaciones se guardan por estudiante
- Requiere autenticación (middleware `auth:sanctum`)
- Solo usuarios autenticados pueden acceder
- Las API keys nunca se exponen al frontend

---

## 📞 Soporte

Si tienes problemas:

1. Revisa los logs: `storage/logs/laravel.log`
2. Verifica la configuración: `.env`
3. Limpia caché: `php artisan config:clear`
4. Prueba con el script: `php test_gemini.php`

---

## 🎉 ¡Listo para Usar!

El sistema está completamente funcional. Solo necesitas:

1. ✅ Configurar base de datos (si aún no lo hiciste)
2. ✅ Acceder a `/chat` en el frontend
3. ✅ Seleccionar un estudiante
4. ✅ Empezar a chatear

**El chat ahora usa Gemini por defecto y generará respuestas automáticamente.**

---

## 📝 Documentación Adicional

- [Integración de Gemini](docs/gemini-integration.md) - Documentación técnica completa
- [API de Gemini](https://ai.google.dev/docs) - Documentación oficial
- [MCP Functions](docs/mcp-whatsapp-system.md) - Funciones disponibles para la IA

---

**Última actualización:** 14 de enero de 2026
