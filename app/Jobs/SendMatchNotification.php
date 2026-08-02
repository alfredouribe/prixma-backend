<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMatchNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Copy exacto: features/notifications/specs/spec.md → "Tipos de notificaciones".
    private const TITLE = '¡Es un match! 🌟';

    public function __construct(
        private readonly User $user1,
        private readonly User $user2,
        private readonly string $conversationId,
    ) {
        // No se puede redeclarar `public $queue = 'notifications'` como
        // propiedad de clase — el trait Queueable ya declara `public $queue`
        // sin valor por defecto, y PHP considera un fatal error de
        // composición cuando el valor por defecto difiere. `onQueue()` es la
        // forma correcta de fijarlo.
        $this->onQueue('notifications');
    }

    public function handle(FcmService $fcm): void
    {
        foreach ([$this->user1, $this->user2] as $user) {
            $otherUser = $user->id === $this->user1->id ? $this->user2 : $this->user1;

            // La notificación in-app siempre se crea, sin importar si hay
            // tokens de dispositivo registrados — el historial dentro de la
            // app no depende de push (ver plan.md → "FCM sin credenciales").
            $notification = $user->notifications()->create([
                'type' => 'match',
                'title' => self::TITLE,
                'body' => "Tú y {$otherUser->profile?->display_name} se gustaron mutuamente.",
                'data' => ['conversation_id' => $this->conversationId],
            ]);

            $tokens = $user->deviceTokens()->pluck('token')->all();

            if (empty($tokens)) {
                continue;
            }

            $sent = $fcm->sendToDevices($tokens, [
                'title' => self::TITLE,
                'body' => "Tú y {$otherUser->profile?->display_name} se gustaron mutuamente.",
                'data' => ['conversation_id' => $this->conversationId, 'type' => 'match'],
            ]);

            // domain.md → Notification: "sent_at se registra cuando FCM
            // confirma entrega" — nunca se asume éxito solo por intentarlo.
            if ($sent) {
                $notification->update(['sent_at' => now()]);
            }
        }
    }
}
