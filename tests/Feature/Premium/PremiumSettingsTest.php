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
