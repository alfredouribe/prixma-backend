<?php

use App\Filament\Resources\AdminResource;
use App\Filament\Resources\AdminResource\Pages\CreateAdmin;
use App\Filament\Resources\AdminResource\Pages\ListAdmins;
use App\Models\Admin;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Gestión de admins — restringida a superadmin vía AdminPolicy
// ---------------------------------------------------------------------------

it('superadmin puede ver el listado de administradores', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    Admin::factory()->count(2)->create();

    $this->actingAs($superadmin, 'admin')
        ->get(AdminResource::getUrl('index'))
        ->assertSuccessful();
});

it('un admin con role admin no puede acceder al listado de AdminResource', function () {
    $admin = Admin::factory()->create(['role' => 'admin']);

    $this->actingAs($admin, 'admin')
        ->get(AdminResource::getUrl('index'))
        ->assertForbidden();
});

it('un admin con role admin no tiene permiso de crear otros admins (policy)', function () {
    $admin = Admin::factory()->create(['role' => 'admin']);

    expect($admin->can('create', Admin::class))->toBeFalse();
    expect($admin->can('viewAny', Admin::class))->toBeFalse();
});

it('superadmin sí tiene permiso de crear otros admins (policy)', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    expect($superadmin->can('create', Admin::class))->toBeTrue();
    expect($superadmin->can('viewAny', Admin::class))->toBeTrue();
});

it('superadmin puede crear un nuevo admin desde el panel', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    $this->actingAs($superadmin, 'admin');

    Livewire::test(CreateAdmin::class)
        ->fillForm([
            'name'     => 'Nuevo Staff',
            'email'    => 'nuevo-staff@prixma.app',
            'password' => 'password123',
            'role'     => 'admin',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Admin::where('email', 'nuevo-staff@prixma.app')->exists())->toBeTrue();
});

it('la contraseña del nuevo admin queda hasheada, nunca en texto plano', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    $this->actingAs($superadmin, 'admin');

    Livewire::test(CreateAdmin::class)
        ->fillForm([
            'name'     => 'Otro Staff',
            'email'    => 'otro-staff@prixma.app',
            'password' => 'password123',
            'role'     => 'admin',
        ])
        ->call('create');

    $created = Admin::where('email', 'otro-staff@prixma.app')->firstOrFail();

    expect($created->password)->not->toBe('password123');
    expect(\Illuminate\Support\Facades\Hash::check('password123', $created->password))->toBeTrue();
});
