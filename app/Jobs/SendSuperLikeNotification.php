<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSuperLikeNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Copy exacto: spec.md → "Tipos de notificaciones". Nunca lleva el
    // nombre de quien dio el super like — constraint explícita: "no revelar
    // quién dio el super like (solo Prixma+ puede verlo)".
    private const TITLE = 'A alguien le encantaste ⭐';
    private const BODY = 'Explora para descubrir quién.';

    public function __construct(private readonly User $recipient)
    {
        // Ver comentario en SendMatchNotification — no se puede redeclarar
        // `$queue` como propiedad con valor por defecto (choca con el trait
        // Queueable), se fija en el constructor.
        $this->onQueue('notifications');
    }

    public function handle(FcmService $fcm): void
    {
        $notification = $this->recipient->notifications()->create([
            'type' => 'super_like',
            'title' => self::TITLE,
            'body' => self::BODY,
        ]);

        $tokens = $this->recipient->deviceTokens()->pluck('token')->all();

        if (empty($tokens)) {
            return;
        }

        $sent = $fcm->sendToDevices($tokens, [
            'title' => self::TITLE,
            'body' => self::BODY,
            'data' => ['type' => 'super_like'],
        ]);

        if ($sent) {
            $notification->update(['sent_at' => now()]);
        }
    }
}
