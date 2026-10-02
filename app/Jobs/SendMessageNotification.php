<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SendMessageNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Constraint: features/notifications/specs/spec.md → "Preview de
    // mensajes: máximo 60 caracteres, truncar con '...'".
    private const PREVIEW_LENGTH = 60;

    public function __construct(
        private readonly User $recipient,
        private readonly User $sender,
        private readonly Message $message,
    ) {
        // Ver comentario en SendMatchNotification — no se puede redeclarar
        // `$queue` como propiedad con valor por defecto (choca con el trait
        // Queueable), se fija en el constructor.
        $this->onQueue('notifications');
    }

    public function handle(ExpoPushService $expoPush): void
    {
        $title = $this->sender->profile?->display_name ?? '';
        $preview = Str::limit($this->message->content, self::PREVIEW_LENGTH);

        $notification = $this->recipient->notifications()->create([
            'type' => 'message',
            'title' => $title,
            'body' => $preview,
            'data' => ['conversation_id' => $this->message->conversation_id],
        ]);

        $tokens = $this->recipient->deviceTokens()->pluck('token')->all();

        if (empty($tokens)) {
            return;
        }

        $sent = $expoPush->sendToDevices($tokens, [
            'title' => $title,
            'body' => $preview,
            'data' => ['conversation_id' => $this->message->conversation_id, 'type' => 'message'],
        ]);

        if ($sent) {
            $notification->update(['sent_at' => now()]);
        }
    }
}
