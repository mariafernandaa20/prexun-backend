<?php

namespace App\Services;

use App\Models\Nomina;
use App\Models\NominaSeccion;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use Exception;

class NominaService
{
    /**
     * Procesa un array de archivos PDF de nómina.
     * Espera el formato: RFC_OTROS.pdf (ej. XAXX010101AAA_S1.pdf)
     */
    public function processUpload(array $files, NominaSeccion $seccion): array
    {
        $results = [
            'success' => 0,
            'failed' => []
        ];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) continue;

            $filename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $rfc = '';

            if (strtolower($extension) === 'xml') {
                try {
                    $xml = simplexml_load_file($file->getRealPath());
                    $namespaces = $xml->getNamespaces(true);
                    // Intentar encontrar RFC del receptor en CFDI 3.3/4.0
                    $receptor = $xml->xpath('//cfdi:Receptor');
                    if (!$receptor) {
                        $receptor = $xml->xpath('//Receptor');
                    }
                    
                    if ($receptor) {
                        $rfc = (string)($receptor[0]['Rfc'] ?? $receptor[0]['rfc'] ?? '');
                    }
                } catch (\Exception $e) {
                    // Fallback a nombre de archivo
                }
            }

            if (!$rfc) {
                // Extraer RFC: asumimos que es la parte antes del primer guion bajo o punto
                $rfc = Str::before(Str::before($filename, '_'), '.');
            }

            $user = User::where('rfc', $rfc)->first();

            if (!$user) {
                $results['failed'][] = [
                    'file' => $filename,
                    'reason' => "Usuario con RFC {$rfc} no encontrado."
                ];
                continue;
            }

            $path = $file->store("nominas/{$seccion->id}", 'private');

            // Solo creamos registro si es PDF. Si es XML lo guardamos pero no es la nomina principal?
            // Usualmente el PDF es el que se firma.
            if (strtolower($extension) === 'pdf') {
                $nomina = Nomina::create([
                    'user_id' => $user->id,
                    'seccion_id' => $seccion->id,
                    'archivo_original_path' => $path,
                    'estado' => 'pendiente',
                ]);

                // Crear notificación para el usuario
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Nueva Nómina Disponible',
                    'message' => "Se ha subido una nueva nómina ({$seccion->nombre}). Por favor, revísala y fírmala.",
                    'path' => '/nominas',
                ]);

                $results['success']++;
            }
        }

        return $results;
    }

    /**
     * Estampa la firma en el PDF original y guarda el resultado.
     */
    public function signNomina(Nomina $nomina, string $signatureBase64): void
    {
        $pdfContent = Storage::disk('private')->get($nomina->archivo_original_path);
        $tempOriginalFile = tempnam(sys_get_temp_dir(), 'pdf_orig');
        file_put_contents($tempOriginalFile, $pdfContent);
    
        // Procesar firma base64
        $signatureData = explode(',', $signatureBase64);
        $signatureImg = base64_decode(end($signatureData));
        $tempSignatureFile = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($tempSignatureFile, $signatureImg);
    
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($tempOriginalFile);
    
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
    
            // Añadimos la página con el tamaño exacto de la original para que coincidan
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);
            $pdf->SetLineWidth(0);
            $pdf->SetDrawColor(0,0,0,0);
            // Solo estampamos en la última página
            if ($pageNo === $pageCount) {
                // Ajuste de coordenadas:
                // w = ancho de la firma en mm
                // x = posición horizontal (derecha)
                // y = posición vertical (abajo)
                
                $w = 20; 
                $x = $size['width'] - $w - 20; 
                $y = $size['height'] - 150; 
    
                $h = 10; 
                $pdf->Image($tempSignatureFile, $x, $y, 0, $h, 'PNG');
            }
        }
    
        $signedPdfContent = $pdf->Output('', 'S');
        $signedPath = str_replace('.pdf', '_firmado.pdf', $nomina->archivo_original_path);
        
        Storage::disk('private')->put($signedPath, $signedPdfContent);
    
        $nomina->update([
            'archivo_firmado_path' => $signedPath,
            'estado' => 'firmado',
            'fecha_firma' => now(),
        ]);
    
        @unlink($tempOriginalFile);
        @unlink($tempSignatureFile);
    } 

    /**
     * Obtiene el contenido del archivo de forma segura.
     */
    public function getFileContent(Nomina $nomina)
    {
        $path = $nomina->archivo_firmado_path ?? $nomina->archivo_original_path;
        
        if (!Storage::disk('private')->exists($path)) {
            throw new Exception("El archivo no existe en el almacenamiento.");
        }

        return Storage::disk('private')->get($path);
    }
}
