<?php

namespace App\Services;

use App\Jobs\SendMatchNotification;
use App\Jobs\SendMessageNotification;
use App\Jobs\SendSuperLikeNotification;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function __construct(private readonly PresenceService $presence) {}

    /**
     * Historial paginado del usuario autenticado, más reciente primero —
     * ver conventions/backend.md → "Paginación" (offset-based, igual que
     * eventos/bloqueados).
     */
    public function list(User $user, int $page = 1): LengthAwarePaginator
    {
        return Notification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'page', $page);
    }

    public function markAllAsRead(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * El ownership se resuelve con el `where('user_id', ...)` de la query
     * (mismo criterio que `SafetyService::unblockUser()`/`deleteGeoBlock()`)
     * — intentar marcar como leída una notificación ajena da 404, no 403,
     * para no revelar que el id existe.
     */
    public function markAsRead(User $user, string $notificationId): Notification
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $notification->update(['read_at' => now()]);

        return $notification->fresh();
    }

    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)->whereNull('read_at')->count();
    }

    public function sendMatchNotification(User $user1, User $user2, string $conversationId): void
    {
        SendMatchNotification::dispatch($user1, $user2, $conversationId);
    }

    /**
     * Solo dispara la notificación si el receptor está desconectado del
     * canal de presencia — evita spamear push + in-app a alguien que está
     * viendo la conversación en ese momento (spec.md → constraint "cuando
     * la app está en foreground, mostrar notificación in-app, no push del
     * sistema"). Ver features/notifications/specs/plan.md → "Presencia
     * online".
     */
    public function sendMessageNotification(User $recipient, User $sender, Message $message): void
    {
        if ($this->presence->isUserOnline($recipient->id)) {
            return;
        }

        SendMessageNotification::dispatch($recipient, $sender, $message);
    }

    public function sendSuperLikeNotification(User $recipient): void
    {
        SendSuperLikeNotification::dispatch($recipient);
    }

    /**
     * Upsert por (user_id, token) — el índice único en `device_tokens`
     * garantiza que reinstalar la app o volver a loguearse en el mismo
     * dispositivo actualiza la fila existente en vez de duplicarla.
     */
    public function registerDeviceToken(User $user, string $token, string $platform): void
    {
        DeviceToken::updateOrCreate(
            ['user_id' => $user->id, 'token' => $token],
            ['platform' => $platform],
        );
    }

    /**
     * Elimina todos los tokens del usuario al hacer logout (plan.md →
     * "FCM Token"). No distingue por dispositivo — el cliente no manda un
     * identificador estable de dispositivo, solo el token FCM vigente.
     */
    public function removeDeviceToken(User $user): void
    {
        DeviceToken::where('user_id', $user->id)->delete();
    }
}
