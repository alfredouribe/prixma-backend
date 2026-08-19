<?php

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Payloads fabricados con la forma real y documentada del webhook de
 * RevenueCat (no una forma inventada) — ver
 * features/subscriptions/specs/plan.md → "Webhook de RevenueCat". No hay
 * cuenta real de RevenueCat todavía (ver spec.md → "Bloqueos externos
 * actuales"), así que estos fixtures son la única forma posible de probar
 * esto en esta ronda.
 */
function revenueCatPayload(string $type, array $eventOverrides = []): array
{
    return [
        'api_version' => '1.0',
        'event' => array_merge([
            'type' => $type,
            'id' => 'event-'.fake()->uuid(),
            'app_user_id' => null, // el test siempre lo sobreescribe
            'original_app_user_id' => null,
            'product_id' => 'prixma_plus_monthly',
            'period_type' => 'NORMAL',
            'purchased_at_ms' => now()->subDay()->valueOf(),
            'expiration_at_ms' => now()->addDays(30)->valueOf(),
            'environment' => 'SANDBOX',
            'entitlement_ids' => ['prixma_plus'],
            'transaction_id' => 'GPA.1234-5678-9012-34567',
            'original_transaction_id' => 'GPA.1234-5678-9012-34567',
            'store' => 'PLAY_STORE',
        ], $eventOverrides),
    ];
}

beforeEach(function () {
    $this->service = new SubscriptionService();
    $this->user = User::factory()->create();
});

// ---------------------------------------------------------------------------
// Eventos que activan/renuevan — INITIAL_PURCHASE, RENEWAL, UNCANCELLATION,
// PRODUCT_CHANGE (mismo efecto, tabla exacta en plan.md)
// ---------------------------------------------------------------------------

it('INITIAL_PURCHASE crea una suscripción activa prixma_plus', function () {
    $payload = revenueCatPayload('INITIAL_PURCHASE', [
        'app_user_id' => $this->user->id,
        'expiration_at_ms' => now()->addDays(30)->valueOf(),
    ]);

    $this->service->handleRevenueCatEvent($payload);

    $subscription = Subscription::where('user_id', $this->user->id)->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->plan)->toBe('prixma_plus');
    expect($subscription->status)->toBe('active');
    expect($subscription->provider)->toBe('google');
    expect($subscription->provider_ref)->toBe('GPA.1234-5678-9012-34567');
    expect($subscription->started_at)->not->toBeNull();
    expect($subscription->expires_at->diffInMinutes(now()->addDays(30)))->toBeLessThan(1);
});

it('INITIAL_PURCHASE usa transaction_id si no viene original_transaction_id', function () {
    $payload = revenueCatPayload('INITIAL_PURCHASE', [
        'app_user_id' => $this->user->id,
        'transaction_id' => 'GPA.fallback-id',
        'original_transaction_id' => null,
    ]);

    $this->service->handleRevenueCatEvent($payload);

    expect(Subscription::where('user_id', $this->user->id)->first()->provider_ref)
        ->toBe('GPA.fallback-id');
});

it('RENEWAL actualiza expires_at sin mover started_at', function () {
    $originalStartedAt = now()->subDays(20);
    $subscription = Subscription::factory()->for($this->user)->create([
        'status' => 'active',
        'started_at' => $originalStartedAt,
        'expires_at' => now()->addDay(),
    ]);

    $newExpiration = now()->addDays(30);
    $payload = revenueCatPayload('RENEWAL', [
        'app_user_id' => $this->user->id,
        'expiration_at_ms' => $newExpiration->valueOf(),
    ]);

    $this->service->handleRevenueCatEvent($payload);

    $subscription->refresh();
    expect($subscription->status)->toBe('active');
    // No usar equalTo() — el roundtrip a MySQL trunca microsegundos, así
    // que un Carbon en memoria y el mismo valor recién leído de la BD no
    // comparan igual bit a bit; mismo patrón de tolerancia ya usado en el
    // resto de este archivo (diffInMinutes/diffInDays < 1).
    expect($subscription->started_at->diffInSeconds($originalStartedAt))->toBeLessThan(1);
    expect($subscription->expires_at->diffInMinutes($newExpiration))->toBeLessThan(1);
});

it('UNCANCELLATION reactiva una suscripción expirada', function () {
    $subscription = Subscription::factory()->for($this->user)->expired()->create();

    $payload = revenueCatPayload('UNCANCELLATION', ['app_user_id' => $this->user->id]);
    $this->service->handleRevenueCatEvent($payload);

    expect($subscription->fresh()->status)->toBe('active');
});

it('PRODUCT_CHANGE mantiene la suscripción activa con la nueva expiración', function () {
    Subscription::factory()->for($this->user)->create(['status' => 'active']);

    $newExpiration = now()->addDays(60);
    $payload = revenueCatPayload('PRODUCT_CHANGE', [
        'app_user_id' => $this->user->id,
        'expiration_at_ms' => $newExpiration->valueOf(),
    ]);
    $this->service->handleRevenueCatEvent($payload);

    $subscription = Subscription::where('user_id', $this->user->id)->first();
    expect($subscription->status)->toBe('active');
    expect($subscription->expires_at->diffInMinutes($newExpiration))->toBeLessThan(1);
});

// ---------------------------------------------------------------------------
// CANCELLATION / EXPIRATION / BILLING_ISSUE
// ---------------------------------------------------------------------------

it('CANCELLATION no cambia el status — el acceso sigue vigente hasta EXPIRATION', function () {
    $subscription = Subscription::factory()->for($this->user)->create([
        'status' => 'active',
        'expires_at' => now()->addDays(10),
    ]);

    $payload = revenueCatPayload('CANCELLATION', ['app_user_id' => $this->user->id]);
    $this->service->handleRevenueCatEvent($payload);

    $subscription->refresh();
    expect($subscription->status)->toBe('active');
    expect($subscription->expires_at->diffInMinutes(now()->addDays(10)))->toBeLessThan(1);
});

it('EXPIRATION marca la suscripción como expired', function () {
    $subscription = Subscription::factory()->for($this->user)->create(['status' => 'active']);

    $payload = revenueCatPayload('EXPIRATION', ['app_user_id' => $this->user->id]);
    $this->service->handleRevenueCatEvent($payload);

    expect($subscription->fresh()->status)->toBe('expired');
});

it('EXPIRATION sin una suscripción previa no falla (no-op)', function () {
    $payload = revenueCatPayload('EXPIRATION', ['app_user_id' => $this->user->id]);

    $this->service->handleRevenueCatEvent($payload);

    expect(Subscription::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('BILLING_ISSUE no cambia el status en v1', function () {
    $subscription = Subscription::factory()->for($this->user)->create(['status' => 'active']);

    $payload = revenueCatPayload('BILLING_ISSUE', ['app_user_id' => $this->user->id]);
    $this->service->handleRevenueCatEvent($payload);

    expect($subscription->fresh()->status)->toBe('active');
});

// ---------------------------------------------------------------------------
// Robustez — nunca debe lanzar, siempre no-op ante datos inesperados
// ---------------------------------------------------------------------------

it('un tipo de evento no reconocido se ignora sin crear ni modificar nada', function () {
    $payload = revenueCatPayload('SUBSCRIPTION_PAUSED', ['app_user_id' => $this->user->id]);

    $this->service->handleRevenueCatEvent($payload);

    expect(Subscription::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('un app_user_id sin usuario correspondiente se ignora sin lanzar', function () {
    $payload = revenueCatPayload('INITIAL_PURCHASE', ['app_user_id' => (string) \Illuminate\Support\Str::uuid()]);

    $this->service->handleRevenueCatEvent($payload);

    expect(Subscription::count())->toBe(0);
});

it('un payload sin event.type o event.app_user_id se ignora sin lanzar', function () {
    $this->service->handleRevenueCatEvent(['event' => ['app_user_id' => $this->user->id]]);
    $this->service->handleRevenueCatEvent(['event' => ['type' => 'INITIAL_PURCHASE']]);
    $this->service->handleRevenueCatEvent([]);

    expect(Subscription::count())->toBe(0);
});
