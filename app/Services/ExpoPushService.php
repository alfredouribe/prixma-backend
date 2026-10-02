<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpoPushService
{
    private const PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    // Límite real documentado por la API de Expo — un solo POST no acepta
    // más de 100 mensajes, hay que repartir en lotes si un usuario tiene
    // más dispositivos registrados que eso.
    private const CHUNK_SIZE = 100;

    /**
     * Reemplaza a `FcmService` (2026-10-01) — el backend ya no habla
     * directo con Firebase. Ver features/notifications/specs/plan.md →
     * "Migración a Expo Push Service". No requiere credenciales: la API
     * pública de Expo relaya a FCM/APNs usando las credenciales que Expo
     * administra del lado de EAS, no de este backend.
     *
     * Best-effort, igual que antes: quien llama (los Jobs de notificación)
     * ya creó el registro en BD antes de intentar esto, así que un fallo
     * de red/API nunca debe tumbar el job ni perder el historial in-app.
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

        $anySuccess = false;

        foreach (array_chunk($tokens, self::CHUNK_SIZE) as $chunk) {
            $messages = array_map(fn (string $token): array => [
                'to' => $token,
                'title' => $payload['title'] ?? '',
                'body' => $payload['body'] ?? '',
                // A diferencia de FCM (que exigía `data` como mapa
                // string => string, de ahí el `stringifyData()` que tenía
                // `FcmService`), la API de Expo acepta cualquier valor
                // serializable a JSON — ya no hace falta forzar strings.
                'data' => $payload['data'] ?? [],
            ], $chunk);

            try {
                $response = $this->client()->post(self::PUSH_URL, $messages);

                if (!$response->successful()) {
                    Log::warning('ExpoPushService: la API de Expo respondió con error.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $tickets = $response->json('data', []);
                $successes = collect($tickets)->where('status', 'ok')->count();
                $failures = count($tickets) - $successes;

                if ($failures > 0) {
                    Log::warning('ExpoPushService: envío con fallas parciales.', [
                        'failures' => $failures,
                        'successes' => $successes,
                    ]);
                }

                $anySuccess = $anySuccess || $successes > 0;
            } catch (Throwable $e) {
                Log::error('ExpoPushService: fallo al enviar push.', ['error' => $e->getMessage()]);
            }
        }

        return $anySuccess;
    }

    private function client(): PendingRequest
    {
        $http = Http::acceptJson()->asJson()->timeout(10);

        // Opcional — "Enhanced Security for Push Notifications" de Expo.
        // Sin configurar, la API pública sigue funcionando igual.
        $accessToken = config('services.expo.access_token');

        if (filled($accessToken)) {
            $http = $http->withToken($accessToken);
        }

        return $http;
    }
}
