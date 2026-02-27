<?php

namespace App\Services\Moodle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

abstract class BaseMoodleService
{    protected Client $client;
    protected string $token;
    protected string $url;

    public function __construct()
    {
        $this->client = new Client(['http_errors' => false]); 
        $this->token = config('moodle.token');
        $this->url = config('moodle.url');
    }

    /**
     * Enviar una solicitud a la API de Moodle.
     * 
     * @param string $wsfunction Función de la API de Moodle a llamar
     * @param array $data Datos específicos para la función
     * @return array Respuesta formateada
     */
    protected function sendRequest(string $wsfunction, array $data = [])
    {
        try {
            $response = $this->client->post($this->url, [
                'form_params' => [
                    'wstoken' => $this->token,
                    'wsfunction' => $wsfunction,
                    'moodlewsrestformat' => 'json'
                ] + $data
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);

            Log::info("Moodle API Response", [
                'wsfunction' => $wsfunction,
                'status_code' => $statusCode,
                'body' => $body
            ]);

            // Verificar si hay errores en la respuesta
            if ($statusCode !== 200 || isset($body['exception']) || isset($body['errorcode'])) {
                Log::error("Moodle API error", ['status' => $statusCode, 'response' => $body]);
                
                $errorMessage = $body['message'] ?? 'Error desconocido';
                if (isset($body['debuginfo'])) {
                    $errorMessage .= ' - ' . $body['debuginfo'];
                }
                
                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'code' => $body['errorcode'] ?? $statusCode,
                    'debuginfo' => $body['debuginfo'] ?? null
                ];
            }

            // Verificar si hay warnings en la respuesta
            if (isset($body['warnings']) && !empty($body['warnings'])) {
                Log::info('Moodle API Response' . json_encode($body));

                // Si hay warnings pero la operación fue exitosa, devolver éxito con warnings
                return [
                    'status' => 'success',
                    'data' => $body,
                    'warnings' => $body['warnings']
                ];
            }

            return [
                'status' => 'success',
                'data' => $body
            ];
        } catch (RequestException $e) {
            return $this->handleException($e);
        } catch (\Exception $e) {
            Log::error("Moodle API Exception", ['exception' => $e->getMessage()]);
            return [
                'status' => 'error',
                'message' => 'Error inesperado: ' . $e->getMessage(),
                'code' => $e->getCode()
            ];
        }
    }

    /**
     * Manejo de excepciones para peticiones fallidas.
     */
    protected function handleException($e)
    {
        Log::error("Moodle API RequestException", [
            'exception' => $e->getMessage(),
            'request' => $e->getRequest(),
            'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
        ]);

        return [
            'status' => 'error',
            'message' => 'Error de conexión con Moodle: ' . $e->getMessage(),
            'code' => $e->getCode()
        ];
    }
}
