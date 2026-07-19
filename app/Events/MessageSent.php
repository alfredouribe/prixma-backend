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

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public Conversation $conversation,
    ) {
    }

    /**
     * Canal privado por conversación — ver features/chat/specs/plan.md →
     * "Real-time" y routes/channels.php para la autorización.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversation->id}")];
    }

    public function broadcastWith(): array
    {
        return ['message' => new MessageResource($this->message)];
    }
}
