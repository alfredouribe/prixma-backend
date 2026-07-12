<?php

use App\Models\Admin;
use App\Models\Profile;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\VerificationService;
use Illuminate\Http\UploadedFile;
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
    Storage::fake('local');

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
// POST /api/verification
// ---------------------------------------------------------------------------
//
// El endpoint recibe el archivo como multipart/form-data. El backend lo
// comprime con ffmpeg y lo guarda en el disco `local` privado del backend —
// nunca se genera una pre-signed URL de subida (ver constitution.md →
// "Media Upload Pipeline", excepción documentada para Verification: el
// documento de identidad no se sube a S3, ver plan.md → "Almacenamiento del
// documento"). El disco `local` está "fake" (Storage::fake en beforeEach),
// pero ffmpeg corre de verdad: estos tests requieren el binario `ffmpeg`
// disponible en el PATH del entorno donde corre `php artisan test`.
// ---------------------------------------------------------------------------

describe('submit', function () {

    it('requiere autenticación', function () {
        $this->postJson('/api/verification', [])->assertStatus(401);
    });

    it('document es obligatorio', function () {
        $this->withToken($this->token)
            ->post('/api/verification', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    });

    it('usuario sube documento y queda pending', function () {
        $response = $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->image('document.jpg', 1600, 1200),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $verificationRequest = VerificationRequest::where('profile_id', $this->profile->id)->sole();

        expect($this->profile->fresh()->verification_status)->toBe('pending');
        expect($verificationRequest->document_path)->toStartWith('verification/' . $this->profile->id . '/');
        expect($verificationRequest->selfie_path)->toBeNull();

        // El archivo realmente se guardó, ya comprimido, en el disco local —
        // esta es la garantía que el patrón viejo (pre-signed URL) no podía dar.
        Storage::disk('local')->assertExists($verificationRequest->document_path);
    });

    it('acepta selfie opcional', function () {
        $response = $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->image('document.jpg'),
                'selfie'   => UploadedFile::fake()->image('selfie.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $verificationRequest = VerificationRequest::first();

        expect($verificationRequest->selfie_path)->not->toBeNull();
        Storage::disk('local')->assertExists($verificationRequest->selfie_path);
    });

    it('rechaza un archivo que no es imagen', function () {
        $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    });

    it('archivo corrupto (imagen inválida) retorna 400 y no crea la solicitud', function () {
        // mimeType forzado a image/jpeg para pasar la validación de Form
        // Request (mimes:jpg), pero el contenido no es una imagen real —
        // simula el caso de un archivo dañado/incompleto. ffmpeg debe fallar
        // al decodificarlo y el service debe convertir eso en un 400 legible,
        // nunca un 500.
        $response = $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->create('document.jpg', 5, 'image/jpeg'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(400);
        expect($response->json('message'))->toBeString()->not->toBeEmpty();

        expect(VerificationRequest::where('profile_id', $this->profile->id)->count())->toBe(0);
        expect($this->profile->fresh()->verification_status)->toBe('unverified');
    });

    it('no permite una nueva solicitud si ya hay una pendiente', function () {
        VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_path' => 'doc-1.jpg',
            'status'          => 'pending',
        ]);

        $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->image('document.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(400);

        expect(VerificationRequest::where('profile_id', $this->profile->id)->count())->toBe(1);
    });

    it('no permite una nueva solicitud si el perfil ya está verificado', function () {
        $this->profile->update(['verification_status' => 'verified']);

        $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->image('document.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(400);
    });

    it('tras un rechazo, el usuario puede subir un nuevo documento y se conserva el historial', function () {
        $admin = createAdmin();
        $first = VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_path' => 'doc-1.jpg',
            'status'          => 'pending',
        ]);
        app(VerificationService::class)->reject($first, $admin, 'La foto no es legible.');

        $response = $this->withToken($this->token)
            ->post('/api/verification', [
                'document' => UploadedFile::fake()->image('document.jpg'),
            ], ['Accept' => 'application/json']);

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
            'document_path'   => 'doc-old.jpg',
            'status'          => 'rejected',
            'rejection_reason'=> 'Foto borrosa',
            'created_at'      => now()->subDays(2),
        ]);

        $latest = VerificationRequest::create([
            'profile_id'      => $this->profile->id,
            'document_path'   => 'doc-new.jpg',
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
            'document_path'    => 'doc.jpg',
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
            'document_path'   => 'doc.jpg',
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
            'document_path'   => 'doc.jpg',
            'status'          => 'pending',
        ]);

        app(VerificationService::class)->reject($request, $admin, 'La foto no es legible.');

        expect($this->profile->fresh()->verification_status)->toBe('rejected');
        expect($request->fresh()->status)->toBe('rejected');
        expect($request->fresh()->rejection_reason)->toBe('La foto no es legible.');
        expect($request->fresh()->reviewed_by)->toBe((string) $admin->id);
    });

    it('aprobar una solicitud cuyo archivo ya no existe en disco no lanza excepción', function () {
        $admin = createAdmin();
        $documentPath = 'verification/' . $this->profile->id . '/doc.jpg';

        Storage::disk('local')->put($documentPath, 'contenido de prueba');

        $request = VerificationRequest::create([
            'profile_id'    => $this->profile->id,
            'document_path' => $documentPath,
            'status'        => 'pending',
        ]);

        // Simula que el archivo ya fue borrado antes (ej. doble clic de un
        // admin aprobando la misma solicitud dos veces).
        Storage::disk('local')->delete($documentPath);
        expect(Storage::disk('local')->exists($documentPath))->toBeFalse();

        $result = app(VerificationService::class)->approve($request, $admin);

        expect($result->status)->toBe('approved');
        expect($this->profile->fresh()->verification_status)->toBe('verified');
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
