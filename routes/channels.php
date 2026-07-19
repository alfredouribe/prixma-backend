<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
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
