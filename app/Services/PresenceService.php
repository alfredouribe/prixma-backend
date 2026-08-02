<?php

namespace App\Services;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

class PresenceService
{
    /**
     * El canal se registra como `online` en routes/channels.php, pero sobre
     * el protocolo Pusher (que Reverb implementa) todo canal de presencia
     * viaja con el prefijo `presence-` — la API REST de miembros requiere el
     * nombre completo.
     */
    private const PRESENCE_CHANNEL = 'presence-online';

    /**
     * ¿Está el usuario conectado ahora mismo al canal de presencia global?
     * Usado por NotificationService::sendMessageNotification() para no
     * enviar push mientras el receptor está activo en la app.
     *
     * Fail-safe: cualquier error de red/API con Reverb se trata como
     * "no está en línea" — se prefiere notificar de más a perder una
     * notificación real por un fallo transitorio del servidor de
     * WebSockets. Ver features/notifications/specs/plan.md → "Presencia
     * online".
     */
    public function isUserOnline(string $userId): bool
    {
        try {
            $pusher = Broadcast::connection('reverb')->getPusher();
            $result = $pusher->getPresenceUsers(self::PRESENCE_CHANNEL);

            return collect($result->users ?? [])
                ->contains(fn ($member) => (string) $member->id === $userId);
        } catch (Throwable $e) {
            Log::warning('PresenceService: no se pudo consultar el canal de presencia de Reverb.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
