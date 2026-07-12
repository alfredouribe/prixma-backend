<?php

use App\Models\GenderIdentity;
use App\Models\Interest;
use App\Models\Profile;
use App\Models\ProfilePhoto;
use App\Models\Pronoun;
use App\Models\SexualOrientation;
use App\Models\User;
use Database\Seeders\GenderIdentitySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\OrientationSeeder;
use Database\Seeders\PronounSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');

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
        'bio'                  => 'Hola, soy Alicia.',
        'city'                 => 'CDMX',
        'intention'            => 'friendship',
        'onboarding_step'      => 6,
        'onboarding_completed' => true,
    ]);
});

// ---------------------------------------------------------------------------
// GET /api/profiles/me
// ---------------------------------------------------------------------------

describe('me', function () {

    it('retorna perfil propio con estadísticas', function () {
        $this->withToken($this->token)
            ->getJson('/api/profiles/me')
            ->assertStatus(200)
            ->assertJsonPath('data.display_name', 'Alicia')
            ->assertJsonPath('data.bio', 'Hola, soy Alicia.')
            ->assertJsonPath('data.city', 'CDMX')
            ->assertJsonStructure([
                'data' => ['id', 'display_name', 'bio', 'city', 'intention', 'photos', 'statistics'],
            ]);
    });

    it('incluye estadísticas con valor 0 cuando no hay tablas de matching', function () {
        $response = $this->withToken($this->token)
            ->getJson('/api/profiles/me')
            ->assertStatus(200);

        expect($response->json('data.statistics.likes_received'))->toBe(0);
        expect($response->json('data.statistics.matches_count'))->toBe(0);
        expect($response->json('data.statistics.events_count'))->toBe(0);
    });

    it('incluye verification_status con el valor por defecto unverified', function () {
        $this->withToken($this->token)
            ->getJson('/api/profiles/me')
            ->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonStructure(['data' => ['verification_status']]);
    });

    it('refleja verification_status pending/verified/rejected en el perfil propio', function () {
        $this->profile->update(['verification_status' => 'pending']);

        $this->withToken($this->token)
            ->getJson('/api/profiles/me')
            ->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'pending');

        $this->profile->update(['verification_status' => 'verified']);

        $this->withToken($this->token)
            ->getJson('/api/profiles/me')
            ->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'verified');

        $this->profile->update(['verification_status' => 'rejected']);

        $this->withToken($this->token)
            ->getJson('/api/profiles/me')
            ->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'rejected');
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/profiles/me')->assertStatus(401);
    });

});

// ---------------------------------------------------------------------------
// PUT /api/profiles/me
// ---------------------------------------------------------------------------

describe('update', function () {

    it('actualiza campos escalares del perfil', function () {
        $this->withToken($this->token)
            ->putJson('/api/profiles/me', [
                'display_name' => 'Alicia Updated',
                'bio'          => 'Nueva bio.',
                'city'         => 'Guadalajara',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.display_name', 'Alicia Updated')
            ->assertJsonPath('data.bio', 'Nueva bio.')
            ->assertJsonPath('data.city', 'Guadalajara');

        $this->assertDatabaseHas('profiles', [
            'user_id'      => $this->user->id,
            'display_name' => 'Alicia Updated',
            'bio'          => 'Nueva bio.',
            'city'         => 'Guadalajara',
        ]);
    });

    it('actualiza identidad de género sincronizando la tabla pivot', function () {
        $identity = GenderIdentity::first();

        $this->withToken($this->token)
            ->putJson('/api/profiles/me', [
                'gender_identity_ids' => [$identity->id],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('profile_gender_identities', [
            'profile_id'  => $this->profile->id,
            'identity_id' => $identity->id,
        ]);
    });

    it('permite limpiar la bio enviando null', function () {
        $this->withToken($this->token)
            ->putJson('/api/profiles/me', ['bio' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.bio', null);
    });

    it('falla si bio supera 300 caracteres', function () {
        $this->withToken($this->token)
            ->putJson('/api/profiles/me', ['bio' => str_repeat('A', 301)])
            ->assertStatus(422);
    });

    it('falla si display_name supera 50 caracteres', function () {
        $this->withToken($this->token)
            ->putJson('/api/profiles/me', ['display_name' => str_repeat('A', 51)])
            ->assertStatus(422);
    });

    it('falla si intention no es un valor válido', function () {
        $this->withToken($this->token)
            ->putJson('/api/profiles/me', ['intention' => 'invalid_value'])
            ->assertStatus(422);
    });

});

// ---------------------------------------------------------------------------
// GET /api/profiles/{uuid}
// ---------------------------------------------------------------------------

describe('show', function () {

    it('retorna perfil público sin estadísticas', function () {
        $otherUser    = User::factory()->withCompletedOnboarding()->create();
        $otherProfile = Profile::create([
            'user_id'      => $otherUser->id,
            'display_name' => 'Roberto',
            'bio'          => 'Hola.',
            'intention'    => 'community',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/profiles/{$otherProfile->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.display_name', 'Roberto');

        expect($response->json('data'))->not->toHaveKey('statistics');
        expect($response->json('data'))->not->toHaveKey('onboarding_step');
        expect($response->json('data'))->not->toHaveKey('onboarding_completed');
    });

    it('retorna 404 si el perfil no existe', function () {
        $this->withToken($this->token)
            ->getJson('/api/profiles/' . \Illuminate\Support\Str::uuid())
            ->assertStatus(404);
    });

    it('requiere autenticación', function () {
        $this->getJson("/api/profiles/{$this->profile->id}")->assertStatus(401);
    });

    it('incluye is_verified en true cuando verification_status es verified', function () {
        $otherUser    = User::factory()->withCompletedOnboarding()->create();
        $otherProfile = Profile::create([
            'user_id'              => $otherUser->id,
            'display_name'         => 'Roberto',
            'verification_status'  => 'verified',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/profiles/{$otherProfile->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_verified', true);
    });

    it('incluye is_verified en false cuando verification_status no es verified', function () {
        $otherUser    = User::factory()->withCompletedOnboarding()->create();
        $otherProfile = Profile::create([
            'user_id'              => $otherUser->id,
            'display_name'         => 'Roberto',
            'verification_status'  => 'pending',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/profiles/{$otherProfile->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_verified', false);
    });

});

// ---------------------------------------------------------------------------
// POST /api/profiles/me/photos
// ---------------------------------------------------------------------------

describe('storePhoto', function () {

    it('agrega una foto al perfil', function () {
        Storage::disk('s3')->put('photos/profiles/test/photo1.jpg', 'fake-image');

        $this->withToken($this->token)
            ->postJson('/api/profiles/me/photos', [
                's3_key' => 'photos/profiles/test/photo1.jpg',
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'url', 'position']]);

        $this->assertDatabaseHas('profile_photos', [
            'profile_id' => $this->profile->id,
            'key'        => 'photos/profiles/test/photo1.jpg',
            'position'   => 1,
        ]);
    });

    it('la primera foto se convierte en photo_url del perfil', function () {
        Storage::disk('s3')->put('photos/profiles/test/first.jpg', 'fake-image');

        $this->withToken($this->token)
            ->postJson('/api/profiles/me/photos', [
                's3_key' => 'photos/profiles/test/first.jpg',
            ])
            ->assertStatus(201);

        expect($this->profile->fresh()->photo_url)->not->toBeNull();
    });

    it('falla al agregar la foto número 7', function () {
        for ($i = 1; $i <= 6; $i++) {
            ProfilePhoto::create([
                'profile_id' => $this->profile->id,
                'url'        => "https://s3.example.com/photo{$i}.jpg",
                'key'        => "photos/photo{$i}.jpg",
                'position'   => $i,
            ]);
        }

        $this->withToken($this->token)
            ->postJson('/api/profiles/me/photos', [
                's3_key' => 'photos/photo7.jpg',
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Máximo 6 fotos permitidas.');
    });

    it('falla si s3_key está vacío', function () {
        $this->withToken($this->token)
            ->postJson('/api/profiles/me/photos', [])
            ->assertStatus(422);
    });

    // -----------------------------------------------------------------------
    // Retrofit Media Upload Pipeline: la foto llega como multipart/form-data
    // (campo `photo`, ver UploadPhotoRequest) y ProfileService::addPhoto()
    // la comprime con ffmpeg antes de subir a S3 (mismo patrón que
    // VerificationService::compressAndStore()). Si ffmpeg no puede procesar
    // el archivo (corrupto/dañado), el service debe lanzar BusinessException
    // -> 400 con mensaje legible, nunca un 500. Estos tests requieren el
    // binario `ffmpeg` disponible en el PATH del entorno donde corre
    // `php artisan test` (igual que los tests equivalentes de Verification).
    // -----------------------------------------------------------------------

    it('archivo corrupto (imagen inválida) retorna 400 y no crea la foto', function () {
        // mimeType forzado a image/jpeg para pasar la validación de Form
        // Request (mimes:jpeg,jpg,png,webp), pero el contenido no es una
        // imagen real — simula un archivo dañado/incompleto. ffmpeg debe
        // fallar al decodificarlo y el service debe convertir eso en un 400
        // legible, nunca un 500.
        $response = $this->withToken($this->token)
            ->post('/api/profiles/me/photos', [
                'photo' => UploadedFile::fake()->create('photo.jpg', 5, 'image/jpeg'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(400);
        expect($response->json('message'))->toBeString()->not->toBeEmpty();

        expect(ProfilePhoto::where('profile_id', $this->profile->id)->count())->toBe(0);
    });

});

// ---------------------------------------------------------------------------
// DELETE /api/profiles/me/photos/{uuid}
// ---------------------------------------------------------------------------

describe('destroyPhoto', function () {

    it('elimina la foto de la base de datos', function () {
        Storage::disk('s3')->put('photos/to-delete.jpg', 'fake-image');

        $photo = ProfilePhoto::create([
            'profile_id' => $this->profile->id,
            'url'        => Storage::disk('s3')->url('photos/to-delete.jpg'),
            'key'        => 'photos/to-delete.jpg',
            'position'   => 1,
        ]);

        $this->withToken($this->token)
            ->deleteJson("/api/profiles/me/photos/{$photo->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('profile_photos', ['id' => $photo->id]);
    });

    it('intenta eliminar el archivo de S3', function () {
        Storage::disk('s3')->put('photos/to-delete-s3.jpg', 'fake-image');

        $photo = ProfilePhoto::create([
            'profile_id' => $this->profile->id,
            'url'        => Storage::disk('s3')->url('photos/to-delete-s3.jpg'),
            'key'        => 'photos/to-delete-s3.jpg',
            'position'   => 1,
        ]);

        $this->withToken($this->token)
            ->deleteJson("/api/profiles/me/photos/{$photo->id}")
            ->assertStatus(200);

        Storage::disk('s3')->assertMissing('photos/to-delete-s3.jpg');
    });

    it('retorna 404 si la foto no pertenece al perfil', function () {
        $otherUser    = User::factory()->withCompletedOnboarding()->create();
        $otherProfile = Profile::create([
            'user_id'      => $otherUser->id,
            'display_name' => 'Otro',
        ]);
        $otherPhoto = ProfilePhoto::create([
            'profile_id' => $otherProfile->id,
            'url'        => 'https://s3.example.com/other.jpg',
            'key'        => 'photos/other.jpg',
            'position'   => 1,
        ]);

        $this->withToken($this->token)
            ->deleteJson("/api/profiles/me/photos/{$otherPhoto->id}")
            ->assertStatus(404);
    });

});

// ---------------------------------------------------------------------------
// PATCH /api/profiles/me/photos/reorder
// ---------------------------------------------------------------------------

describe('reorderPhotos', function () {

    it('actualiza positions correctamente', function () {
        $photo1 = ProfilePhoto::create([
            'profile_id' => $this->profile->id,
            'url'        => 'https://s3.example.com/photo1.jpg',
            'key'        => 'photos/photo1.jpg',
            'position'   => 1,
        ]);
        $photo2 = ProfilePhoto::create([
            'profile_id' => $this->profile->id,
            'url'        => 'https://s3.example.com/photo2.jpg',
            'key'        => 'photos/photo2.jpg',
            'position'   => 2,
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/photos/reorder', [
                'ordered_ids' => [$photo2->id, $photo1->id],
            ])
            ->assertStatus(200);

        expect(ProfilePhoto::find($photo2->id)->position)->toBe(1);
        expect(ProfilePhoto::find($photo1->id)->position)->toBe(2);
    });

    it('falla si los IDs no pertenecen al perfil', function () {
        $otherUser    = User::factory()->withCompletedOnboarding()->create();
        $otherProfile = Profile::create(['user_id' => $otherUser->id, 'display_name' => 'Otro']);
        $foreignPhoto = ProfilePhoto::create([
            'profile_id' => $otherProfile->id,
            'url'        => 'https://s3.example.com/foreign.jpg',
            'key'        => 'photos/foreign.jpg',
            'position'   => 1,
        ]);

        $this->withToken($this->token)
            ->patchJson('/api/profiles/me/photos/reorder', [
                'ordered_ids' => [$foreignPhoto->id],
            ])
            ->assertStatus(400);
    });

});
