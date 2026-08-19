<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Procesa los eventos del webhook de RevenueCat — única fuente de verdad
 * que escribe en `subscriptions` (ver constraints en
 * features/subscriptions/specs/spec.md). El webhook nunca se confía un
 * estado de suscripción que venga directo del cliente.
 *
 * Forma real del payload de RevenueCat (no inventada — su formato
 * documentado de webhooks): `{ "api_version": "1.0", "event": { "type":
 * "INITIAL_PURCHASE", "app_user_id": "...", "expiration_at_ms": ...,
 * "original_transaction_id": "...", ... } }`. `app_user_id` es el UUID de
 * `User` porque el SDK del cliente se inicializa con
 * `appUserID = user.id` (ver plan.md), así que `User::find()` resuelve
 * directo sin ningún mapeo de IDs externo.
 */
class SubscriptionService
{
    /**
     * Estos 4 tipos tienen el mismo efecto en `subscriptions` — tabla de
     * eventos exacta en features/subscriptions/specs/plan.md.
     */
    private const ACTIVATING_EVENTS = [
        'INITIAL_PURCHASE',
        'RENEWAL',
        'UNCANCELLATION',
        'PRODUCT_CHANGE',
    ];

    public function handleRevenueCatEvent(array $payload): void
    {
        $event = $payload['event'] ?? [];
        $type = $event['type'] ?? null;
        $appUserId = $event['app_user_id'] ?? null;

        if (!$type || !$appUserId) {
            Log::warning('SubscriptionService: payload de RevenueCat sin event.type o event.app_user_id, ignorado.', [
                'payload' => $payload,
            ]);

            return;
        }

        $user = User::find($appUserId);

        if (!$user) {
            // Mismo criterio que un evento no reconocido: reintentar no va
            // a hacer que el usuario aparezca, y RevenueCat reintenta
            // indefinidamente ante cualquier respuesta que no sea 2xx — no
            // hay nada que ganar respondiendo distinto a esto.
            Log::warning('SubscriptionService: app_user_id sin usuario correspondiente, evento ignorado.', [
                'app_user_id' => $appUserId,
                'type' => $type,
            ]);

            return;
        }

        match (true) {
            in_array($type, self::ACTIVATING_EVENTS, true) => $this->activate($user, $event),
            $type === 'EXPIRATION' => $this->expire($user),
            $type === 'CANCELLATION' => Log::info(
                'SubscriptionService: CANCELLATION recibido — sin cambio de status, el usuario apagó auto-renovación pero conserva acceso hasta que expire el periodo pagado (lo dispara EXPIRATION).',
                ['user_id' => $user->id]
            ),
            $type === 'BILLING_ISSUE' => Log::info(
                'SubscriptionService: BILLING_ISSUE recibido — sin cambio de status en v1, RevenueCat sigue reintentando el cobro del lado de Google.',
                ['user_id' => $user->id]
            ),
            default => Log::info('SubscriptionService: tipo de evento no reconocido, ignorado.', [
                'type' => $type,
            ]),
        };
    }

    private function activate(User $user, array $event): void
    {
        $subscription = Subscription::firstOrNew(['user_id' => $user->id]);

        $subscription->fill([
            'plan' => 'prixma_plus',
            'status' => 'active',
            // Google Play Billing es la única plataforma conectada en esta
            // ronda — ver spec.md → "Decisiones confirmadas" (Apple/iOS
            // pospuesto, Stripe fuera de este flujo).
            'provider' => 'google',
            'provider_ref' => $event['original_transaction_id']
                ?? $event['transaction_id']
                ?? $event['id']
                ?? null,
            'expires_at' => $this->msToDatetime($event['expiration_at_ms'] ?? null),
        ]);

        // `started_at` no aparece en la tabla de eventos de plan.md (aplica
        // igual a los 4 tipos "activadores"). Se fija una sola vez, en la
        // primera activación real; renovaciones/reactivaciones posteriores
        // no la mueven — decisión razonable no explícita en plan.md,
        // ver nota en tasks.md.
        if (!$subscription->started_at) {
            $subscription->started_at = now();
        }

        $subscription->save();
    }

    private function expire(User $user): void
    {
        // Null-safe: si nunca existió un registro para este usuario (ej.
        // entrega fuera de orden del webhook), no hay nada que expirar.
        $user->subscription?->update(['status' => 'expired']);
    }

    private function msToDatetime(?int $milliseconds): ?Carbon
    {
        return $milliseconds !== null
            ? Carbon::createFromTimestampMs($milliseconds)
            : null;
    }
}
