<?php

use App\Models\Subscription;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['services.revenuecat.webhook_secret' => 'test-secret']);
});

function revenueCatWebhookPayload(string $type, string $appUserId): array
{
    return [
        'api_version' => '1.0',
        'event' => [
            'type' => $type,
            'id' => 'event-'.fake()->uuid(),
            'app_user_id' => $appUserId,
            'product_id' => 'prixma_plus_monthly',
            'expiration_at_ms' => now()->addDays(30)->valueOf(),
            'environment' => 'SANDBOX',
            'transaction_id' => 'GPA.1234-5678-9012-34567',
            'original_transaction_id' => 'GPA.1234-5678-9012-34567',
            'store' => 'PLAY_STORE',
        ],
    ];
}

it('200 con el secreto correcto y aplica el efecto del evento', function () {
    $user = User::factory()->create();

    $this->withToken('test-secret')
        ->postJson('/api/webhooks/revenuecat', revenueCatWebhookPayload('INITIAL_PURCHASE', $user->id))
        ->assertStatus(200);

    $subscription = Subscription::where('user_id', $user->id)->first();
    expect($subscription)->not->toBeNull();
    expect($subscription->status)->toBe('active');
});

it('401 sin header Authorization, sin tocar la base de datos', function () {
    $user = User::factory()->create();

    $this->postJson('/api/webhooks/revenuecat', revenueCatWebhookPayload('INITIAL_PURCHASE', $user->id))
        ->assertStatus(401);

    expect(Subscription::where('user_id', $user->id)->exists())->toBeFalse();
});

it('401 con un secreto incorrecto, sin tocar la base de datos', function () {
    $user = User::factory()->create();

    $this->withToken('secreto-incorrecto')
        ->postJson('/api/webhooks/revenuecat', revenueCatWebhookPayload('INITIAL_PURCHASE', $user->id))
        ->assertStatus(401);

    expect(Subscription::where('user_id', $user->id)->exists())->toBeFalse();
});

it('401 cuando REVENUECAT_WEBHOOK_SECRET no está configurado (placeholder vacío)', function () {
    config(['services.revenuecat.webhook_secret' => null]);
    $user = User::factory()->create();

    $this->withToken('test-secret')
        ->postJson('/api/webhooks/revenuecat', revenueCatWebhookPayload('INITIAL_PURCHASE', $user->id))
        ->assertStatus(401);
});

it('200 ante un tipo de evento no reconocido, sin crear nada y sin 500', function () {
    $user = User::factory()->create();

    $this->withToken('test-secret')
        ->postJson('/api/webhooks/revenuecat', revenueCatWebhookPayload('SUBSCRIPTION_PAUSED', $user->id))
        ->assertStatus(200);

    expect(Subscription::where('user_id', $user->id)->exists())->toBeFalse();
});
