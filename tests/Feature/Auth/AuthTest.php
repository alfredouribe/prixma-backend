<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

// ---------------------------------------------------------------------------
// Registro
// ---------------------------------------------------------------------------

describe('registro', function () {

    it('crea una cuenta con datos válidos', function () {
        $response = $this->postJson('/api/auth/register', [
            'email'                 => 'nuevo@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '1990-05-15',
            'terms_accepted'        => true,
            'privacy_accepted'      => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'email', 'status'], 'token', 'message'])
            ->assertJsonPath('data.email', 'nuevo@example.com')
            ->assertJsonPath('message', 'Cuenta creada exitosamente.');

        $this->assertDatabaseHas('users', ['email' => 'nuevo@example.com']);
    });

    it('rechaza correo duplicado', function () {
        User::factory()->create(['email' => 'existente@example.com']);

        $this->postJson('/api/auth/register', [
            'email'                 => 'existente@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '1990-05-15',
            'terms_accepted'        => true,
            'privacy_accepted'      => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Este correo ya está registrado.');
    });

    it('rechaza usuarios menores de 18 años', function () {
        $this->postJson('/api/auth/register', [
            'email'                 => 'joven@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => now()->subYears(17)->format('Y-m-d'),
            'terms_accepted'        => true,
            'privacy_accepted'      => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.date_of_birth.0', 'Debes tener al menos 18 años para registrarte.');
    });

    it('rechaza si no se aceptan los términos de uso', function () {
        $this->postJson('/api/auth/register', [
            'email'                 => 'test@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '1990-05-15',
            'terms_accepted'        => false,
            'privacy_accepted'      => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.terms_accepted.0', 'Debes aceptar los Términos de Uso.');
    });

    it('rechaza si no se acepta la política de privacidad', function () {
        $this->postJson('/api/auth/register', [
            'email'                 => 'test@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'date_of_birth'         => '1990-05-15',
            'terms_accepted'        => true,
            'privacy_accepted'      => false,
        ])->assertStatus(422)
            ->assertJsonPath('errors.privacy_accepted.0', 'Debes aceptar la Política de Privacidad.');
    });

});

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

describe('login', function () {

    it('devuelve token con credenciales válidas', function () {
        $user = User::factory()->create([
            'email'    => 'usuario@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email'    => 'usuario@example.com',
            'password' => 'password123',
        ])->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'email'], 'token'])
            ->assertJsonPath('data.id', (string) $user->id);
    });

    it('rechaza contraseña incorrecta', function () {
        User::factory()->create(['email' => 'usuario@example.com']);

        $this->postJson('/api/auth/login', [
            'email'    => 'usuario@example.com',
            'password' => 'wrongpassword',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Correo o contraseña incorrectos.');
    });

    it('rechaza correo inexistente', function () {
        $this->postJson('/api/auth/login', [
            'email'    => 'noexiste@example.com',
            'password' => 'password123',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Correo o contraseña incorrectos.');
    });

});

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

describe('logout', function () {

    it('revoca el token del usuario', function () {
        $user = User::factory()->create();
        $created = $user->createToken('mobile');

        $this->withToken($created->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertStatus(204);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $created->accessToken->id,
        ]);
    });

});

// ---------------------------------------------------------------------------
// Me
// ---------------------------------------------------------------------------

describe('me', function () {

    it('devuelve el usuario autenticado', function () {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.id', (string) $user->id)
            ->assertJsonPath('data.email', $user->email);
    });

    it('devuelve 401 cuando no hay token', function () {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    });

});

// ---------------------------------------------------------------------------
// Recuperación de contraseña
// ---------------------------------------------------------------------------

describe('recuperación de contraseña', function () {

    it('siempre devuelve 200 independientemente de si el correo existe', function () {
        $this->postJson('/api/auth/forgot-password', [
            'email' => 'noexiste@example.com',
        ])->assertStatus(200)
            ->assertJsonPath('message', 'Si existe una cuenta con ese correo, recibirás instrucciones.');
    });

    it('devuelve 200 cuando el correo sí existe', function () {
        User::factory()->create(['email' => 'real@example.com']);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'real@example.com',
        ])->assertStatus(200)
            ->assertJsonPath('message', 'Si existe una cuenta con ese correo, recibirás instrucciones.');
    });

});

// ---------------------------------------------------------------------------
// Reset de contraseña
// ---------------------------------------------------------------------------

describe('reset de contraseña', function () {

    it('actualiza la contraseña con token válido', function () {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email'                 => 'reset@example.com',
            'token'                 => $token,
            'password'              => 'nuevapassword123',
            'password_confirmation' => 'nuevapassword123',
        ])->assertStatus(200)
            ->assertJsonPath('message', 'Contraseña actualizada correctamente.');

        $this->assertTrue(Hash::check('nuevapassword123', $user->fresh()->password));
    });

    it('rechaza token expirado o inválido', function () {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->postJson('/api/auth/reset-password', [
            'email'                 => 'reset@example.com',
            'token'                 => $token,
            'password'              => 'nuevapassword123',
            'password_confirmation' => 'nuevapassword123',
        ])->assertStatus(400)
            ->assertJsonPath('message', 'El enlace de recuperación ha expirado o ya fue usado.');
    });

});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

describe('rate limiting', function () {

    it('bloquea después de 5 intentos fallidos', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email'    => 'noexiste@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email'    => 'noexiste@example.com',
            'password' => 'wrongpassword',
        ])->assertStatus(429)
            ->assertJsonPath('message', 'Demasiados intentos. Espera un momento e intenta de nuevo.');
    });

});
