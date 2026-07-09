<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Admin;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerificationService
{
    /**
     * Genera una URL pre-firmada para que el móvil suba directo a S3 el
     * documento (INE) o la selfie de comparación. El archivo nunca pasa
     * por Laravel. Bucket privado, separado del de fotos de perfil.
     */
    public function generatePresignedUrl(User $user, string $type = 'document'): array
    {
        $profile = $user->profile ?? abort(404);

        $filename = $type === 'selfie' ? 'selfie' : 'document';
        $key = 'verification/' . $profile->id . '/' . Str::uuid() . '/' . $filename . '.jpg';

        $s3Client = Storage::disk('s3_identity')->getClient();

        $command = $s3Client->getCommand('PutObject', [
            'Bucket'      => config('filesystems.disks.s3_identity.bucket'),
            'Key'         => $key,
            'ContentType' => 'image/jpeg',
            'ACL'         => 'private',
        ]);

        $presignedRequest = $s3Client->createPresignedRequest($command, '+15 minutes');

        return [
            'upload_url' => (string) $presignedRequest->getUri(),
            'key'        => $key,
        ];
    }

    /**
     * Crea una nueva VerificationRequest para el perfil del usuario.
     * El observer (VerificationRequestObserver) sincroniza
     * profiles.verification_status = 'pending' automáticamente.
     */
    public function submit(User $user, string $documentS3Key, ?string $selfieS3Key = null): VerificationRequest
    {
        $profile = $user->profile ?? abort(404);

        if ($profile->verification_status === 'pending') {
            throw new BusinessException('Ya tienes una solicitud de verificación en revisión.');
        }

        if ($profile->verification_status === 'verified') {
            throw new BusinessException('Tu perfil ya está verificado.');
        }

        return DB::transaction(function () use ($profile, $documentS3Key, $selfieS3Key) {
            return VerificationRequest::create([
                'profile_id'      => $profile->id,
                'document_s3_key' => $documentS3Key,
                'selfie_s3_key'   => $selfieS3Key,
                'status'          => 'pending',
            ]);
        });
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
        return DB::transaction(function () use ($verificationRequest, $admin) {
            $verificationRequest->update([
                'status'      => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $verificationRequest->fresh();
        });
    }

    /**
     * Rechaza una solicitud con motivo obligatorio.
     */
    public function reject(VerificationRequest $verificationRequest, Admin $admin, string $reason): VerificationRequest
    {
        return DB::transaction(function () use ($verificationRequest, $admin, $reason) {
            $verificationRequest->update([
                'status'            => 'rejected',
                'rejection_reason'  => $reason,
                'reviewed_by'       => $admin->id,
                'reviewed_at'       => now(),
            ]);

            return $verificationRequest->fresh();
        });
    }
}
