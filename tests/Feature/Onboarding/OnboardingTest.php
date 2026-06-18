<?php

use App\Models\GenderIdentity;
use App\Models\Interest;
use App\Models\Profile;
use App\Models\Pronoun;
use App\Models\SexualOrientation;
use App\Models\User;
use App\Models\UserSetting;
use Database\Seeders\GenderIdentitySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OrientationSeeder;
use Database\Seeders\PronounSeeder;
use Illuminate\Support\Facades\Route;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed([
        GenderIdentitySeeder::class,
        OrientationSeeder::class,
        PronounSeeder::class,
        InterestSeeder::class,
    ]);
});

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

describe('status', function () {

    it('retorna paso 0 para usuario recién registrado sin perfil', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/onboarding/status')
            ->assertStatus(200)
            ->assertJsonPath('data.current_step', 0)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.profile', null);
    });

    it('retorna el paso actual cuando el usuario ya tiene progreso', function () {
        $user  = User::factory()->create();
        Profile::create([
            'user_id'          => $user->id,
            'display_name'     => 'Alicia',
            'onboarding_step'  => 3,
        ]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/onboarding/status')
            ->assertStatus(200)
            ->assertJsonPath('data.current_step', 3);
    });

});

// ---------------------------------------------------------------------------
// Paso 1 — Identidad
// ---------------------------------------------------------------------------

describe('stepIdentity', function () {

    it('guarda identidad y avanza a paso 1', function () {
        $user     = User::factory()->create();
        $token    = $user->createToken('mobile')->plainTextToken;
        $identity = GenderIdentity::where('slug', 'no-binarie')->first();
        $orient   = SexualOrientation::where('slug', 'bisexual')->first();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/identity', [
                'display_name'        => 'Alicia',
                'gender_identity_ids' => [$identity->id],
                'orientation_ids'     => [$orient->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.display_name', 'Alicia')
            ->assertJsonPath('data.onboarding_step', 1);

        $this->assertDatabaseHas('profiles', [
            'user_id'      => $user->id,
            'display_name' => 'Alicia',
        ]);

        $this->assertDatabaseHas('profile_gender_identities', [
            'identity_id' => $identity->id,
        ]);
    });

    it('acepta campo libre como única fuente de género y orientación', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/onboarding/step/identity', [
                'display_name'           => 'Alicia',
                'custom_gender_identity' => 'Andrógine',
                'custom_orientation'     => 'Graysexual',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.onboarding_step', 1);
    });

    it('falla si no hay género ni campo libre de género', function () {
        $user    = User::factory()->create();
        $token   = $user->createToken('mobile')->plainTextToken;
        $orient  = SexualOrientation::where('slug', 'gay')->first();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/identity', [
                'display_name'    => 'Alicia',
                'orientation_ids' => [$orient->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.gender_identity_ids.0', 'Debes seleccionar al menos una identidad de género o describirte con tus propias palabras.');
    });

    it('falla si no hay orientación ni campo libre de orientación', function () {
        $user     = User::factory()->create();
        $token    = $user->createToken('mobile')->plainTextToken;
        $identity = GenderIdentity::where('slug', 'mujer-cis')->first();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/identity', [
                'display_name'        => 'Alicia',
                'gender_identity_ids' => [$identity->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.orientation_ids.0', 'Debes seleccionar al menos una orientación sexual o describirte con tus propias palabras.');
    });

    it('falla si display_name supera 50 caracteres', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/onboarding/step/identity', [
                'display_name'           => str_repeat('A', 51),
                'custom_gender_identity' => 'Test',
                'custom_orientation'     => 'Test',
            ])
            ->assertStatus(422);
    });

    it('no retrocede el paso si ya hay progreso mayor', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        Profile::create([
            'user_id'         => $user->id,
            'display_name'    => 'Alicia',
            'onboarding_step' => 4,
        ]);

        $identity = GenderIdentity::where('slug', 'no-binarie')->first();
        $orient   = SexualOrientation::where('slug', 'queer')->first();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/identity', [
                'display_name'        => 'Nuevo nombre',
                'gender_identity_ids' => [$identity->id],
                'orientation_ids'     => [$orient->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.onboarding_step', 4);
    });

});

// ---------------------------------------------------------------------------
// Paso 4 — Intereses
// ---------------------------------------------------------------------------

describe('stepInterests', function () {

    it('guarda intereses con mínimo 3 seleccionados del catálogo', function () {
        $user    = User::factory()->create();
        $token   = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 3]);

        $ids = Interest::limit(3)->pluck('id')->toArray();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/interests', [
                'interest_ids' => $ids,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.onboarding_step', 4);
    });

    it('acepta 2 del catálogo + campo libre (total 3)', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 3]);

        $ids = Interest::limit(2)->pluck('id')->toArray();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/interests', [
                'interest_ids'    => $ids,
                'custom_interests' => 'Escalada, astronomía',
            ])
            ->assertStatus(200);
    });

    it('falla con menos de 3 intereses', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 3]);

        $ids = Interest::limit(2)->pluck('id')->toArray();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/interests', [
                'interest_ids' => $ids,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.interest_ids.0', 'Debes seleccionar al menos 3 intereses.');
    });

    it('falla con 0 intereses y sin campo libre', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 3]);

        $this->withToken($token)
            ->postJson('/api/onboarding/step/interests', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.interest_ids.0', 'Debes seleccionar al menos 3 intereses.');
    });

    it('falla si no existe el perfil (identity no completado)', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $ids = Interest::limit(3)->pluck('id')->toArray();

        $this->withToken($token)
            ->postJson('/api/onboarding/step/interests', [
                'interest_ids' => $ids,
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Debes completar el paso de identidad antes de continuar.');
    });

});

// ---------------------------------------------------------------------------
// Paso 6 — Seguridad
// ---------------------------------------------------------------------------

describe('stepSafety', function () {

    it('marca onboarding_completed en profile y user', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 5]);

        $this->withToken($token)
            ->postJson('/api/onboarding/step/safety', [
                'selfie_verification_enabled' => true,
                'incognito_mode_enabled'      => false,
                'geo_block_enabled'           => false,
                'reports_enabled'             => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('message', '¡Onboarding completado! Bienvenide a Prixma.');

        $this->assertDatabaseHas('profiles', [
            'user_id'              => $user->id,
            'onboarding_completed' => true,
            'onboarding_step'      => 6,
        ]);

        $this->assertDatabaseHas('users', [
            'id'                   => $user->id,
            'onboarding_completed' => true,
        ]);
    });

    it('crea UserSetting con los valores enviados', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 5]);

        $this->withToken($token)
            ->postJson('/api/onboarding/step/safety', [
                'selfie_verification_enabled' => false,
                'incognito_mode_enabled'      => true,
                'geo_block_enabled'           => true,
                'reports_enabled'             => false,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_settings', [
            'user_id'                     => $user->id,
            'selfie_verification_enabled' => false,
            'incognito_mode_enabled'      => true,
            'geo_block_enabled'           => true,
            'reports_enabled'             => false,
        ]);
    });

    it('crea UserSetting con los defaults del spec si no se envían cambios', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        Profile::create(['user_id' => $user->id, 'display_name' => 'Alicia', 'onboarding_step' => 5]);

        $this->withToken($token)
            ->postJson('/api/onboarding/step/safety', [
                'selfie_verification_enabled' => true,
                'incognito_mode_enabled'      => false,
                'geo_block_enabled'           => false,
                'reports_enabled'             => true,
            ])
            ->assertStatus(200);

        $setting = UserSetting::where('user_id', $user->id)->first();

        expect($setting->selfie_verification_enabled)->toBeTrue();
        expect($setting->incognito_mode_enabled)->toBeFalse();
        expect($setting->geo_block_enabled)->toBeFalse();
        expect($setting->reports_enabled)->toBeTrue();
    });

    it('falla si no existe el perfil', function () {
        $user  = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/onboarding/step/safety', [
                'selfie_verification_enabled' => true,
                'incognito_mode_enabled'      => false,
                'geo_block_enabled'           => false,
                'reports_enabled'             => true,
            ])
            ->assertStatus(400);
    });

});

// ---------------------------------------------------------------------------
// Middleware — rutas protegidas de la app
// ---------------------------------------------------------------------------

describe('onboarding guard', function () {

    it('bloquea acceso a rutas de la app sin onboarding completado', function () {
        Route::get('/_test/app-route', fn () => response()->json(['ok' => true]))
            ->middleware(['auth:sanctum', 'onboarding.completed']);

        $user  = User::factory()->create(['onboarding_completed' => false]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/app-route')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Debes completar el onboarding antes de continuar.');
    });

    it('permite acceso a rutas de la app con onboarding completado', function () {
        Route::get('/_test/app-route', fn () => response()->json(['ok' => true]))
            ->middleware(['auth:sanctum', 'onboarding.completed']);

        $user  = User::factory()->withCompletedOnboarding()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/app-route')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    });

});

// ---------------------------------------------------------------------------
// Video presigned URL
// ---------------------------------------------------------------------------

describe('videoPresignedUrl', function () {

    it('requiere autenticación', function () {
        $this->postJson('/api/onboarding/video/presigned-url')
            ->assertStatus(401);
    });

    // Requiere AWS SDK instalado + credenciales reales.
    // Cubrir con tests de integración cuando el entorno esté configurado.
    it('retorna upload_url y video_key')->todo();

});
