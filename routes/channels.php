<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

/**
 * Canal privado por usuario (Reverb) — usado para eventos dirigidos a un
 * usuario sin importar en qué pantalla esté (ej. toast in-app de mensaje
 * nuevo, ver App\Events\MessageSent::broadcastOn()). Los IDs son UUID, no
 * enteros — el scaffold por defecto de Laravel comparaba con `(int)`, que
 * para cualquier UUID evalúa a `0`, así que la regla siempre daba `true`
 * sin importar el usuario (bug de seguridad real, corregido 2026-08-02).
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

/**
 * Canal privado de una conversación de chat (Reverb). Solo los dos
 * participantes pueden suscribirse — ver features/chat/specs/plan.md →
 * "Real-time".
 */
Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId) {
    $conversation = Conversation::find($conversationId);

    if (!$conversation) {
        return false;
    }

    return $user->id === $conversation->user_id_1 || $user->id === $conversation->user_id_2;
});

/**
 * Canal de presencia global — cualquier usuario autenticado puede unirse.
 * `PresenceService::isUserOnline()` consulta la lista de miembros de este
 * canal (vía la API REST estilo Pusher que expone Reverb) para decidir si
 * enviar push de mensaje. Ver features/notifications/specs/plan.md →
 * "Presencia online".
 */
Broadcast::channel('online', function ($user) {
    return ['id' => $user->id];
});
