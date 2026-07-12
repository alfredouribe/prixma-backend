<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve el documento/selfie de identidad del panel admin haciendo streaming
 * desde el disco privado `local` — nunca S3, nunca una URL pública/firmada
 * (ver constitution.md → "Media Upload Pipeline", excepción documentada para
 * Verification). La ruta vive dentro del grupo `authenticatedRoutes` del
 * panel Filament (`AdminPanelProvider`), así que ya pasó por el middleware
 * de autenticación del guard `admin` antes de llegar aquí; igual se
 * autoriza explícitamente con la policy (constitution.md regla 3: "Policies
 * gate everything", nunca un chequeo de rol implícito).
 */
class VerificationDocumentController extends Controller
{
    public function document(VerificationRequest $verificationRequest): StreamedResponse
    {
        return $this->stream($verificationRequest, $verificationRequest->document_path);
    }

    public function selfie(VerificationRequest $verificationRequest): StreamedResponse
    {
        return $this->stream($verificationRequest, $verificationRequest->selfie_path);
    }

    private function stream(VerificationRequest $verificationRequest, ?string $path): StreamedResponse
    {
        // auth('admin')->user()->can(...), no Gate::authorize()/$this->authorize():
        // el guard por default de la app es 'web', y Gate resuelve el usuario
        // autenticado vía ese guard — no vería la sesión del admin. Mismo
        // patrón que ya usan las Actions de ViewVerificationRequest.
        abort_unless((bool) auth('admin')->user()?->can('view', $verificationRequest), 403);

        abort_if(blank($path) || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
