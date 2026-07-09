<?php

use App\Models\Admin;
use App\Models\Profile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\VerificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createAdmin(array $attributes = []): Admin
{
    return Admin::create(array_merge([
        'name'     => 'Admin de prueba',
        'email'    => fake()->unique()->safeEmail(),
        'password' => Hash::make('password'),
        'role'     => 'admin',
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('s3_identity');

    $this->user  = User::factory()->withCompletedOnboarding()->create();
    $this->token = $this->user->createToken('mobile')->plainTextToken;

    $this->profile = Profile::create([
        'user_id'              => $this->user->id,
        'display_name'         => 'Alicia',
        'city'                 => 'CDMX',
        'intention'            => 'friendship',
        'onboarding_step'      => 6,
        'onboarding_completed' => true,
    ]);
});

// ---------------------------------------------------------------------------
// POST /api/verification/presigned-url
// ---------------------------------------------------------------------------

describe('presignedUrl', function () {

    it('requiere autenticación', function () {
        $this->postJson('/api/verification/presigned-url')->assertStatus(401);
    });

    // Requiere AWS SDK instalado + credenciales reales para computar la firma
    // contra un endpoint real. Se cubre con test de integración cuando el
    // entorno esté configurado (mismo criterio que Onboarding/Profile).
    it('retorna upload_url y key')->todo();

});

// ---------------------------------------------------------------------------
// POST /api/verification
// ---------------------------------------------------------------------------

describe('submit', function () {

    it('requiere autenticación', function () {
        $this->postJson('/api/verification', [])->assertStatus(401);
    });

    it('document_s3_key es obligatorio', function () {
        $this->withToken($this->token)
            ->postJson('/api/verification', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_s3_key']);
    });

    it('usuario sube documento y queda pending', function () {
        $response = $this->withToken($this->token)
            ->postJson('/api/verification', [
                'document_s3_key' => 'verification/' . $this->profile->id . '/doc.jpg',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        expect(VerificationRequest::where('profile_id', $this->profile->id)->count())->toBe(1);
        expect($this->profile->fresh()->verification_status)->toBe('pending');
    });

    it('acepta selfie_s3_key opcional', function () {
        $response = $this->withToken($this->token)
            ->postJson('/api/verification', [
                'document_s3_key' => 'doc.jpg',
                'selfie_s3_key'   => 'selfie.jpg',
            ]);

        $response->assertStatus(201);
        expect(VerificationRequest::first()->selfie_s3_key)->toBe('selfie.jpg');
    });

    it('no permite una nueva solicitud si ya hay una pendiente', function () {
        VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_s3_key' => 'doc-1.jpg',
            'status'          => 'pending',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/verification', ['document_s3_key' => 'doc-2.jpg'])
            ->assertStatus(400);

        expect(VerificationRequest::where('profile_id', $this->profile->id)->count())->toBe(1);
    });

    it('no permite una nueva solicitud si el perfil ya está verificado', function () {
        $this->profile->update(['verification_status' => 'verified']);

        $this->withToken($this->token)
            ->postJson('/api/verification', ['document_s3_key' => 'doc-2.jpg'])
            ->assertStatus(400);
    });

    it('tras un rechazo, el usuario puede subir un nuevo documento y se conserva el historial', function () {
        $admin = createAdmin();
        $first = VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_s3_key' => 'doc-1.jpg',
            'status'          => 'pending',
        ]);
        app(VerificationService::class)->reject($first, $admin, 'La foto no es legible.');

        $response = $this->withToken($this->token)
            ->postJson('/api/verification', ['document_s3_key' => 'doc-2.jpg']);

        $response->assertStatus(201);

        expect(VerificationRequest::where('profile_id', $this->profile->id)->count())->toBe(2);
        expect($first->fresh()->status)->toBe('rejected');
        expect($first->fresh()->rejection_reason)->toBe('La foto no es legible.');
        expect($this->profile->fresh()->verification_status)->toBe('pending');
    });

});

// ---------------------------------------------------------------------------
// GET /api/verification/status
// ---------------------------------------------------------------------------

describe('status', function () {

    it('requiere autenticación', function () {
        $this->getJson('/api/verification/status')->assertStatus(401);
    });

    it('retorna null cuando el usuario nunca ha solicitado verificación', function () {
        $this->withToken($this->token)
            ->getJson('/api/verification/status')
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    });

    it('retorna siempre la solicitud más reciente cuando hay varias', function () {
        VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_s3_key' => 'doc-old.jpg',
            'status'          => 'rejected',
            'rejection_reason'=> 'Foto borrosa',
            'created_at'      => now()->subDays(2),
        ]);

        $latest = VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_s3_key' => 'doc-new.jpg',
            'status'          => 'pending',
            'created_at'      => now(),
        ]);

        $this->withToken($this->token)
            ->getJson('/api/verification/status')
            ->assertStatus(200)
            ->assertJsonPath('data.id', (string) $latest->id)
            ->assertJsonPath('data.status', 'pending');
    });

    it('muestra el motivo de rechazo cuando la solicitud vigente fue rechazada', function () {
        VerificationRequest::create([
            'profile_id'       => $this->profile->id,
            'document_s3_key'  => 'doc.jpg',
            'status'           => 'rejected',
            'rejection_reason' => 'La foto no es legible.',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/verification/status')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'La foto no es legible.');
    });

});

// ---------------------------------------------------------------------------
// VerificationService — approve / reject
// ---------------------------------------------------------------------------

describe('VerificationService approve/reject', function () {

    it('aprobar sincroniza profiles.verification_status a verified y registra reviewed_by/reviewed_at', function () {
        $admin = createAdmin();
        $request = VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_s3_key' => 'doc.jpg',
            'status'          => 'pending',
        ]);

        app(VerificationService::class)->approve($request, $admin);

        expect($this->profile->fresh()->verification_status)->toBe('verified');
        expect($request->fresh()->status)->toBe('approved');
        expect($request->fresh()->reviewed_by)->toBe((string) $admin->id);
        expect($request->fresh()->reviewed_at)->not->toBeNull();
    });

    it('rechazar sincroniza profiles.verification_status a rejected y guarda el motivo', function () {
        $admin = createAdmin();
        $request = VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_s3_key' => 'doc.jpg',
            'status'          => 'pending',
        ]);

        app(VerificationService::class)->reject($request, $admin, 'La foto no es legible.');

        expect($this->profile->fresh()->verification_status)->toBe('rejected');
        expect($request->fresh()->status)->toBe('rejected');
        expect($request->fresh()->rejection_reason)->toBe('La foto no es legible.');
        expect($request->fresh()->reviewed_by)->toBe((string) $admin->id);
    });

});

// ---------------------------------------------------------------------------
// Aislamiento de guards: admin vs sanctum/users
// ---------------------------------------------------------------------------

describe('guard isolation', function () {

    it('un token de usuario final (sanctum) no autentica contra el guard admin', function () {
        Route::middleware('auth:admin')->get('/__test/admin-only', fn () => response()->json(['ok' => true]));

        $this->withToken($this->token)
            ->getJson('/__test/admin-only')
            ->assertStatus(401);
    });

    it('un admin autenticado en el guard admin no obtiene acceso a rutas de usuarios (sanctum)', function () {
        Route::middleware('auth:admin')->get('/__test/admin-only', fn () => response()->json(['ok' => true]));

        $admin = createAdmin();

        $this->actingAs($admin, 'admin')
            ->getJson('/__test/admin-only')
            ->assertStatus(200);

        // La sesión del guard `admin` no otorga acceso a rutas protegidas
        // por el guard de usuarios finales.
        $this->getJson('/api/auth/me')->assertStatus(401);
    });

    it('Admin no puede crear tokens de Sanctum (no expone HasApiTokens)', function () {
        $admin = createAdmin();

        expect(method_exists($admin, 'createToken'))->toBeFalse();
    });

});
