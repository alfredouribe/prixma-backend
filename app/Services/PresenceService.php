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
        return collect($this->onlineUserIds())->contains($userId);
    }

    /**
     * IDs de todos los usuarios conectados ahora mismo al canal de
     * presencia global. Usado por el dashboard de Filament para el conteo
     * de "en línea ahora" — mismo fail-safe que `isUserOnline()`: cualquier
     * error de red/API con Reverb se trata como "nadie en línea" en vez de
     * romper la página que lo consulte.
     *
     * @return list<string>
     */
    public function onlineUserIds(): array
    {
        try {
            $pusher = Broadcast::connection('reverb')->getPusher();
            $result = $pusher->getPresenceUsers(self::PRESENCE_CHANNEL);

            return collect($result->users ?? [])
                ->map(fn ($member) => (string) $member->id)
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('PresenceService: no se pudo consultar el canal de presencia de Reverb.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
