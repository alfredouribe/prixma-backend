<?php

use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Database\Seeders\GenderIdentitySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OrientationSeeder;
use Database\Seeders\PronounSeeder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed([
        GenderIdentitySeeder::class,
        OrientationSeeder::class,
        PronounSeeder::class,
        InterestSeeder::class,
    ]);

    $this->user  = User::factory()->withCompletedOnboarding()->create();
    $this->token = $this->user->createToken('mobile')->plainTextToken;

    $this->profile = Profile::create([
        'user_id'              => $this->user->id,
        'display_name'         => 'Alicia',
        'intention'            => 'friendship',
        'onboarding_step'      => 6,
        'onboarding_completed' => true,
    ]);
});

// ---------------------------------------------------------------------------
// GET /api/profiles/me/settings
// ---------------------------------------------------------------------------

describe('settings (GET)', function () {

    it('retorna la configuración actual del usuario autenticado', function () {
        UserSetting::create([
            'user_id'                      => $this->user->id,
            'selfie_verification_enabled'  => false,
            'incognito_mode_enabled'       => true,
            'geo_block_enabled'            => true,
            'reports_enabled'              => false,
        ]);

        $this->withToken($this->token)
            ->getJson('/api/profiles/me/settings')
            ->assertStatus(200)
            ->assertJsonPath('data.selfie_verification_enabled', false)
            ->assertJsonPath('data.incognito_mode_enabled', true)
            ->assertJsonPath('data.geo_block_enabled', true)
            ->assertJsonPath('data.reports_enabled', false)
            ->assertJsonStructure([
                'data' => ['id', 'selfie_verification_enabled', 'incognito_mode_enabled', 'geo_block_enabled', 'reports_enabled'],
            ]);
    });

    it('crea la fila con defaults si el usuario aún no tiene user_settings', function () {
        expect(UserSetting::where('user_id', $this->user->id)->exists())->toBeFalse();

        $this->withToken($this->token)
            ->getJson('/api/profiles/me/settings')
            ->assertStatus(200)
            ->assertJsonPath('data.selfie_verification_enabled', true)
            ->assertJsonPath('data.incognito_mode_enabled', false)
            ->assertJsonPath('data.geo_block_enabled', false)
            ->assertJsonPath('data.reports_enabled', true);

        $this->assertDatabaseHas('user_settings', ['user_id' => $this->user->id]);
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/profiles/me/settings')->assertStatus(401);
    });

    it('incluye las preferencias de notificaciones con default true para un usuario nuevo', function () {
        expect(UserSetting::where('user_id', $this->user->id)->exists())->toBeFalse();

        $this->withToken($this->token)
            ->getJson('/api/profiles/me/settings')
            ->assertStatus(200)
            ->assertJsonPath('data.notify_matches_enabled', true)
            ->assertJsonPath('data.notify_messages_enabled', true)
            ->assertJsonPath('data.notify_events_enabled', true)
            ->assertJsonStructure([
                'data' => [
                    'id', 'selfie_verification_enabled', 'incognito_mode_enabled',
                    'geo_block_enabled', 'reports_enabled',
                    'notify_matches_enabled', 'notify_messages_enabled', 'notify_events_enabled',
                ],
            ]);
    });

});

// ---------------------------------------------------------------------------
// PATCH /api/profiles/me/settings
// ---------------------------------------------------------------------------

describe('settings (PATCH)', function () {

    it('actualiza un campo y lo persiste', function () {
        UserSetting::create(['user_id' => $this->user->id]);

        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/settings', ['incognito_mode_enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.incognito_mode_enabled', true);

        $this->assertDatabaseHas('user_settings', [
            'user_id'                 => $this->user->id,
            'incognito_mode_enabled'  => true,
        ]);
    });

    it('actualización parcial no toca los demás campos', function () {
        UserSetting::create([
            'user_id'                      => $this->user->id,
            'selfie_verification_enabled'  => true,
            'incognito_mode_enabled'       => false,
            'geo_block_enabled'            => false,
            'reports_enabled'              => true,
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/settings', ['geo_block_enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.geo_block_enabled', true)
            ->assertJsonPath('data.selfie_verification_enabled', true)
            ->assertJsonPath('data.incognito_mode_enabled', false)
            ->assertJsonPath('data.reports_enabled', true);
    });

    it('crea la fila si aún no existe (cuenta vieja) y aplica el patch', function () {
        expect(UserSetting::where('user_id', $this->user->id)->exists())->toBeFalse();

        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/settings', ['reports_enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.reports_enabled', false);

        $this->assertDatabaseHas('user_settings', [
            'user_id'          => $this->user->id,
            'reports_enabled'  => false,
        ]);
    });

    it('requiere autenticación', function () {
        $this->patchJson('/api/profiles/me/settings', ['incognito_mode_enabled' => true])
            ->assertStatus(401);
    });

    it('falla si un campo no es boolean', function () {
        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/settings', ['incognito_mode_enabled' => 'no-soy-boolean'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['incognito_mode_enabled']);
    });

    it('actualiza una preferencia de notificación sin tocar las demás', function () {
        UserSetting::create(['user_id' => $this->user->id]);

        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/settings', ['notify_messages_enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.notify_messages_enabled', false)
            ->assertJsonPath('data.notify_matches_enabled', true)
            ->assertJsonPath('data.notify_events_enabled', true);

        $this->assertDatabaseHas('user_settings', [
            'user_id'                  => $this->user->id,
            'notify_messages_enabled'  => false,
            'notify_matches_enabled'   => true,
            'notify_events_enabled'    => true,
        ]);
    });

});
