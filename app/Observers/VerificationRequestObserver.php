<?php

namespace App\Observers;

use App\Models\VerificationRequest;

/**
 * Sincroniza profiles.verification_status (caché rápida) a partir de los
 * cambios de estado en VerificationRequest (fuente de verdad / auditoría).
 * Nunca se actualiza `verification_status` a mano desde un controller o
 * servicio — este observer es el único responsable de mantenerlo en sync.
 */
class VerificationRequestObserver
{
    public function created(VerificationRequest $verificationRequest): void
    {
        $verificationRequest->profile()->update(['verification_status' => 'pending']);
    }

    public function updated(VerificationRequest $verificationRequest): void
    {
        if (! $verificationRequest->wasChanged('status')) {
            return;
        }

        $newStatus = match ($verificationRequest->status) {
            'approved' => 'verified',
            'rejected' => 'rejected',
            default => null,
        };

        if ($newStatus === null) {
            return;
        }

        $verificationRequest->profile()->update(['verification_status' => $newStatus]);
    }
}
