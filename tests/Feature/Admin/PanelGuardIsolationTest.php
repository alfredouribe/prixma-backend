<?php

use App\Models\Admin;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// -----------------------------------------------------------------------
// Aislamiento de guards: un usuario final (guard `web`/`sanctum`) nunca
// debe poder alcanzar el panel `/admin` (guard `admin`), y viceversa.
// -----------------------------------------------------------------------

it('sin autenticar, /admin redirige al login del panel', function () {
    $this->get('/admin')->assertRedirect();
});

it('un usuario final autenticado con el guard por defecto no puede acceder al panel admin', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user) // guard 'web', nunca 'admin'
        ->get('/admin')
        ->assertRedirect(); // nunca 200 — Filament exige el guard 'admin'
});

it('un admin autenticado sí puede acceder al panel', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get('/admin')
        ->assertSuccessful();
});

it('el guard admin y el guard por defecto de usuarios son independientes', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin');

    expect(auth('admin')->check())->toBeTrue();
    expect(auth('admin')->user())->toBeInstanceOf(Admin::class);
    // Ninguna otra guard queda autenticada solo por loguear al admin.
    expect(auth('web')->check())->toBeFalse();
    expect(auth('sanctum')->check())->toBeFalse();
});

it('un token sanctum de usuario final no autentica contra el guard admin', function () {
    $user = User::factory()->withCompletedOnboarding()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withToken($token)
        ->get('/admin')
        ->assertRedirect(); // la ruta /admin no usa el guard sanctum en absoluto
});
