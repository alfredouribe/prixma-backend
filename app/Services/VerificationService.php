<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VerificationService
{
    /**
     * Crea una nueva VerificationRequest para el perfil del usuario.
     *
     * El documento (y la selfie, si viene) llegan como multipart/form-data y
     * se comprimen con ffmpeg. A diferencia del resto de la Media Upload
     * Pipeline, el resultado NO se sube a S3: es un archivo de vida corta
     * (se elimina en cuanto un admin aprueba o rechaza la solicitud) que se
     * revisa en el mismo servidor, así que se guarda en el disco `local`
     * privado del backend (ver constitution.md → "Media Upload Pipeline",
     * excepción documentada para Verification). El observer
     * (VerificationRequestObserver) sincroniza profiles.verification_status
     * = 'pending' automáticamente.
     */
    public function submit(User $user, UploadedFile $document, ?UploadedFile $selfie = null): VerificationRequest
    {
        $profile = $user->profile ?? abort(404);

        if ($profile->verification_status === 'pending') {
            throw new BusinessException('Ya tienes una solicitud de verificación en revisión.');
        }

        if ($profile->verification_status === 'verified') {
            throw new BusinessException('Tu perfil ya está verificado.');
        }

        $basePath = 'verification/' . $profile->id . '/' . (string) Str::uuid();

        $documentPath = $this->compressAndStore($document, $basePath . '/document.jpg');
        $selfiePath = $selfie ? $this->compressAndStore($selfie, $basePath . '/selfie.jpg') : null;

        return DB::transaction(function () use ($profile, $documentPath, $selfiePath) {
            return VerificationRequest::create([
                'profile_id'    => $profile->id,
                'document_path' => $documentPath,
                'selfie_path'   => $selfiePath,
                'status'        => 'pending',
            ]);
        });
    }

    /**
     * Comprime una imagen con ffmpeg (reduce calidad/tamaño) y la guarda en
     * el disco privado `local` bajo la ruta dada. El archivo temporal se
     * borra siempre, incluso si la compresión falla.
     */
    private function compressAndStore(UploadedFile $file, string $path): string
    {
        $outputPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'verification_' . Str::uuid() . '.jpg';

        try {
            $process = new Process([
                'ffmpeg',
                '-y',
                '-i', $file->getRealPath(),
                '-vf', "scale='min(1080,iw)':-2",
                '-q:v', '5',
                $outputPath,
            ]);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new BusinessException('No pudimos procesar tu documento. Verifica que la imagen no esté dañada e intenta de nuevo.');
            }

            Storage::disk('local')->put($path, file_get_contents($outputPath));
        } finally {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
        }

        return $path;
    }

    /**
     * Borra el documento (y la selfie, si existe) del disco local, y limpia
     * la carpeta de la solicitud si queda vacía. Se llama tanto al aprobar
     * como al rechazar: el archivo ya cumplió su propósito una vez
     * revisado, sin importar el resultado. Antes de borrar cada archivo se
     * valida que exista (`$disk->exists()`) — si ya no está (ej. un admin
     * que aprueba/rechaza la misma solicitud dos veces por doble clic, o
     * cualquier borrado previo) simplemente se omite: es un estado válido,
     * no un fallo, así que no lanza excepción ni se loguea como error.
     */
    private function deleteStoredFiles(VerificationRequest $verificationRequest): void
    {
        $disk = Storage::disk('local');

        collect([$verificationRequest->document_path, $verificationRequest->selfie_path])
            ->filter()
            ->each(function (string $path) use ($disk) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            });

        $directory = dirname($verificationRequest->document_path);

        // Nunca borrar el disco raíz — solo aplica cuando el documento
        // realmente vive bajo verification/{profile}/{uuid}/.
        if ($directory !== '' && $directory !== '.' && $disk->exists($directory) && empty($disk->allFiles($directory))) {
            $disk->deleteDirectory($directory);
        }
    }

    /**
     * Retorna la VerificationRequest vigente (más reciente) del perfil,
     * o null si el usuario nunca ha intentado verificarse.
     */
    public function getStatus(User $user): ?VerificationRequest
    {
        $profile = $user->profile ?? abort(404);

        return VerificationRequest::where('profile_id', $profile->id)
            ->latest('created_at')
            ->first();
    }

    /**
     * Aprueba una solicitud. Llamado tanto desde el panel de admin
     * (Filament, vía Action) como potencialmente desde un endpoint admin
     * futuro — por eso vive en el service, no en un controller/Resource.
     */
    public function approve(VerificationRequest $verificationRequest, Admin $admin): VerificationRequest
    {
        $verificationRequest = DB::transaction(function () use ($verificationRequest, $admin) {
            $verificationRequest->update([
                'status'      => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $verificationRequest->fresh();
        });

        $this->deleteStoredFiles($verificationRequest);

        return $verificationRequest;
    }

    /**
     * Rechaza una solicitud con motivo obligatorio.
     */
    public function reject(VerificationRequest $verificationRequest, Admin $admin, string $reason): VerificationRequest
    {
        $verificationRequest = DB::transaction(function () use ($verificationRequest, $admin, $reason) {
            $verificationRequest->update([
                'status'            => 'rejected',
                'rejection_reason'  => $reason,
                'reviewed_by'       => $admin->id,
                'reviewed_at'       => now(),
            ]);

            return $verificationRequest->fresh();
        });

        $this->deleteStoredFiles($verificationRequest);

        return $verificationRequest;
    }
}
