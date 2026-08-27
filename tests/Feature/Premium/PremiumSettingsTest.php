<?php

use App\Models\PlatformSetting;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createPremiumTestUser(bool $isPremium = false): array
{
    $user = User::factory()->withCompletedOnboarding()->create(['is_premium' => $isPremium]);

    Profile::create([
        'user_id' => $user->id,
        'display_name' => fake()->name(),
        'city' => 'CDMX',
        'intention' => 'friendship',
        'onboarding_step' => 6,
        'onboarding_completed' => true,
    ]);

    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'token');
}

// ---------------------------------------------------------------------------
// GET /api/premium/settings
// ---------------------------------------------------------------------------

it('devuelve los valores reales de PlatformSetting y is_premium del usuario autenticado', function () {
    PlatformSetting::current()->update([
        'free_swipes_per_ad' => 7,
        'free_likes_per_day' => 4,
        'free_chat_minutes_before_ad' => 2,
    ]);
    ['token' => $token] = createPremiumTestUser(isPremium: true);

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.is_premium', true)
        ->assertJsonPath('data.free_swipes_per_ad', 7)
        ->assertJsonPath('data.free_likes_per_day', 4)
        ->assertJsonPath('data.free_chat_minutes_before_ad', 2);
});

it('is_premium es false para un usuario sin Prixma+', function () {
    ['token' => $token] = createPremiumTestUser(isPremium: false);

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.is_premium', false);
});

it('is_premium refleja un premium_until activo aunque is_premium sea false', function () {
    ['user' => $user, 'token' => $token] = createPremiumTestUser(isPremium: false);
    $user->update(['premium_until' => now()->addDays(3)]);

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.is_premium', true);
});

it('is_premium es false cuando premium_until ya expiró y is_premium es false', function () {
    ['user' => $user, 'token' => $token] = createPremiumTestUser(isPremium: false);
    $user->update(['premium_until' => now()->subDay()]);

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.is_premium', false);
});

it('chat_ad_video_url es null cuando no hay video configurado', function () {
    ['token' => $token] = createPremiumTestUser();

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.chat_ad_video_url', null);
});

it('chat_ad_video_url viene firmada cuando hay un video configurado', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('premium/ad.mp4', 'contenido');
    PlatformSetting::current()->update(['chat_ad_video_key' => 'premium/ad.mp4']);
    ['token' => $token] = createPremiumTestUser();

    $response = $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200);

    expect($response->json('data.chat_ad_video_url'))->not->toBeNull();
});

it('crea PlatformSetting con los defaults de la migración si no existe ninguna fila', function () {
    expect(PlatformSetting::count())->toBe(0);
    ['token' => $token] = createPremiumTestUser();

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.free_swipes_per_ad', 5)
        ->assertJsonPath('data.free_likes_per_day', 5)
        ->assertJsonPath('data.free_chat_minutes_before_ad', 1);

    expect(PlatformSetting::count())->toBe(1);
});

it('requiere autenticación', function () {
    $this->getJson('/api/premium/settings')->assertStatus(401);
});

it('expone rewind_credits del usuario autenticado', function () {
    ['user' => $user, 'token' => $token] = createPremiumTestUser();
    $user->update(['rewind_credits' => 4]);

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.rewind_credits', 4);
});

it('expone extra_super_likes del usuario autenticado', function () {
    ['user' => $user, 'token' => $token] = createPremiumTestUser();
    $user->update(['extra_super_likes' => 3]);

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.extra_super_likes', 3);
});

it('expone premium_until del usuario autenticado cuando está presente', function () {
    ['user' => $user, 'token' => $token] = createPremiumTestUser();
    $expiry = now()->addDays(5);
    $user->update(['premium_until' => $expiry]);

    $response = $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200);

    expect($response->json('data.premium_until'))->not->toBeNull();
});

it('premium_until es null cuando el usuario nunca tuvo uno', function () {
    ['token' => $token] = createPremiumTestUser();

    $this->withToken($token)
        ->getJson('/api/premium/settings')
        ->assertStatus(200)
        ->assertJsonPath('data.premium_until', null);
});
