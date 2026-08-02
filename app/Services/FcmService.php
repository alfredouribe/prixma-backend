<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

class FcmService
{
    /**
     * `$messaging` inyectable por constructor (nullable, default `null`)
     * solo para tests — `$this->mock(Messaging::class)` se resuelve
     * automáticamente vía el contenedor. En producción, sin binding
     * registrado, Laravel cae al default `null` y `resolveMessaging()`
     * construye un `Messaging` real desde las credenciales configuradas.
     * Ver features/notifications/specs/plan.md → "Envío real —
     * kreait/firebase-php".
     */
    public function __construct(private readonly ?Messaging $messaging = null) {}

    /**
     * Envía un push a un conjunto de tokens de dispositivo. Best-effort:
     * sin credenciales configuradas (`services.fcm.credentials_path`) es
     * un no-op que solo loggea; un fallo de red/API tampoco lanza —
     * quien llama (los Jobs de notificación) ya creó el registro en BD
     * antes de intentar esto, así que un fallo de push nunca debe tumbar
     * el job ni perder el historial in-app.
     *
     * @return bool true si al menos un token recibió el push exitosamente
     *              (permite al caller decidir si marcar `sent_at`) — éxito
     *              parcial entre varios dispositivos del mismo usuario
     *              cuenta como éxito, domain.md no exige unanimidad.
     */
    public function sendToDevices(array $tokens, array $payload): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $messaging = $this->messaging ?? $this->resolveMessaging();

        if (!$messaging) {
            Log::info('FcmService: Firebase no está configurado todavía, push omitido.', [
                'tokens_count' => count($tokens),
                'title' => $payload['title'] ?? null,
            ]);

            return false;
        }

        try {
            $message = CloudMessage::new()
                ->withNotification(FcmNotification::create($payload['title'] ?? '', $payload['body'] ?? ''))
                ->withData($this->stringifyData($payload['data'] ?? []));

            $report = $messaging->sendMulticast($message, $tokens);

            if ($report->hasFailures()) {
                Log::warning('FcmService: envío con fallas parciales.', [
                    'failures' => $report->failures()->count(),
                    'successes' => $report->successes()->count(),
                ]);
            }

            return $report->successes()->count() > 0;
        } catch (Throwable $e) {
            Log::error('FcmService: fallo al enviar push.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function resolveMessaging(): ?Messaging
    {
        $path = config('services.fcm.credentials_path');

        if (blank($path) || !file_exists($path)) {
            return null;
        }

        return (new Factory())->withServiceAccount($path)->createMessaging();
    }

    /**
     * FCM exige que `data` sea un mapa string => string.
     *
     * @return array<non-empty-string, string>
     */
    private function stringifyData(array $data): array
    {
        return array_map(static fn ($value): string => (string) $value, $data);
    }
}
