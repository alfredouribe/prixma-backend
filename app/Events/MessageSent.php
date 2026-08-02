<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Mismo límite que SendMessageNotification (features/notifications/specs/spec.md
    // → "Preview de mensajes: máximo 60 caracteres").
    private const PREVIEW_LENGTH = 60;

    public function __construct(
        public Message $message,
        public Conversation $conversation,
    ) {
    }

    /**
     * Dos canales: el de la conversación (para quien ya tiene el hilo
     * abierto, append en tiempo real) y el privado del destinatario (para
     * el toast in-app global — no llega a ningún otro lado de la app hoy,
     * ver features/chat/specs/plan.md → "Toast in-app de mensaje nuevo").
     * Nunca se agrega el canal del propio emisor.
     */
    public function broadcastOn(): array
    {
        // Cast a string explícito: user_id_1/sender_id pueden llegar como
        // objeto UUID (recién creado, sin recargar de BD) o como string
        // (recargado) según el estado del modelo en memoria — comparar sin
        // castear compara tipos distintos y siempre da `false`.
        $recipientId = (string) $this->conversation->user_id_1 === (string) $this->message->sender_id
            ? (string) $this->conversation->user_id_2
            : (string) $this->conversation->user_id_1;

        return [
            new PrivateChannel("conversation.{$this->conversation->id}"),
            new PrivateChannel("App.Models.User.{$recipientId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'message' => new MessageResource($this->message),
            'conversation_id' => $this->conversation->id,
            // Mismo copy que la notificación push/in-app (brand/copies.md →
            // "Notificaciones (bandeja)" → tipo `message`): título = nombre
            // del emisor, cuerpo = preview truncado del mensaje.
            'sender_name' => $this->message->sender->profile?->display_name ?? '',
            // Misma foto que ya usa ConversationResource para `other_user.photo`
            // (primera foto ordenada por posición); `null` si no tiene ninguna,
            // el frontend cae al ícono genérico en ese caso.
            'sender_photo' => $this->message->sender->profile?->photos->first()?->url,
            'preview' => Str::limit($this->message->content, self::PREVIEW_LENGTH),
        ];
    }
}
