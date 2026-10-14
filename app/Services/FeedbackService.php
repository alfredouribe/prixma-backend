<?php

namespace App\Services;

use App\Mail\NewFeedbackMail;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class FeedbackService
{
    /**
     * Crea el registro de feedback y encola (best-effort) el correo de
     * notificación interno. El registro ya quedó guardado antes de
     * intentar encolar el correo, y un fallo al encolar se atrapa y se
     * registra en el log — nunca revierte el registro ni rompe la
     * respuesta HTTP (mismo criterio ya usado en el proyecto para
     * notificaciones de Matching/Chat: el guardado real nunca depende del
     * correo).
     */
    public function submit(User $user, array $data): Feedback
    {
        $feedback = Feedback::create([
            'user_id' => $user->id,
            'type' => $data['type'],
            'message' => $data['message'],
            'app_version' => $data['app_version'] ?? null,
            'platform' => $data['platform'] ?? null,
        ]);

        $this->notifyStaff($feedback);

        return $feedback;
    }

    private function notifyStaff(Feedback $feedback): void
    {
        try {
            $feedback->loadMissing('user.profile');

            Mail::to(config('services.feedback.notify_email'))
                ->queue(new NewFeedbackMail($feedback));
        } catch (Throwable $e) {
            Log::error('FeedbackService: fallo al encolar el correo de notificación.', [
                'feedback_id' => $feedback->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
