<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Bug real 2026-10-02: con los dos participantes dentro del mismo chat,
 * el check "✓✓ visto" del emisor nunca se actualizaba en vivo — solo
 * `ChatService::markAsRead()` marcaba `read_at` en la BD, sin avisar al
 * otro lado por Reverb, así que el emisor solo veía el cambio al salir y
 * volver a entrar a la conversación (lo que dispara el fetch REST de
 * nuevo). Mismo canal que `MessageSent` (`conversation.{id}`) — ambos
 * participantes ya están suscritos ahí.
 */
class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $readAt,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'read_at' => $this->readAt,
        ];
    }
}
