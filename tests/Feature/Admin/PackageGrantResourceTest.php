<?php

use App\Filament\Resources\PackageGrantResource;
use App\Filament\Resources\PackageGrantResource\Pages\ListPackageGrants;
use App\Models\Admin;
use App\Models\PackageGrant;
use App\Models\Profile;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

function createGrantForUser(User $user, array $state = [], ?Admin $admin = null): PackageGrant
{
    return PackageGrant::create(array_merge([
        'user_id' => $user->id,
        'admin_id' => $admin?->id,
        'package_name' => fake()->words(3, true),
        'grant_type' => 'premium_days',
        'grant_value' => 7,
    ], $state));
}

// ---------------------------------------------------------------------------
// Listado — tabla server-side, reporte general de solo lectura
// ---------------------------------------------------------------------------

it('admin autenticado ve la lista paginada de otorgamientos', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $grants = collect(range(1, 3))->map(fn () => createGrantForUser($user));

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->assertCanSeeTableRecords($grants);
});

it('el listado se ordena por fecha, más reciente primero por default', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $old = createGrantForUser($user, ['package_name' => 'Paquete Viejo Único']);
    $old->created_at = now()->subDays(3);
    $old->save();

    $recent = createGrantForUser($user, ['package_name' => 'Paquete Reciente Único']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->assertCanSeeTableRecords([$recent, $old], inOrder: true);
});

// ---------------------------------------------------------------------------
// Búsqueda server-side — por usuario (nombre/email) y por paquete
// ---------------------------------------------------------------------------

it('la búsqueda por nombre del usuario se resuelve en la query (SQL)', function () {
    $target = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $target->profile->update(['display_name' => 'Valentina Única Otorgada']);
    $targetGrant = createGrantForUser($target);

    $others = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherGrant = createGrantForUser($others);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->searchTable('Valentina Única')
        ->assertCanSeeTableRecords([$targetGrant])
        ->assertCanNotSeeTableRecords([$otherGrant]);
});

it('la búsqueda por email del usuario se resuelve en la query', function () {
    $target = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['email' => 'unico-otorgamiento@prixma.app']);
    $targetGrant = createGrantForUser($target);

    $others = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherGrant = createGrantForUser($others);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->searchTable('unico-otorgamiento@prixma.app')
        ->assertCanSeeTableRecords([$targetGrant])
        ->assertCanNotSeeTableRecords([$otherGrant]);
});

it('la búsqueda por nombre del paquete se resuelve en la query', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $targetGrant = createGrantForUser($user, ['package_name' => 'Paquete Buscable Único']);
    $otherGrant = createGrantForUser($user, ['package_name' => 'Otro paquete cualquiera']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->searchTable('Buscable Único')
        ->assertCanSeeTableRecords([$targetGrant])
        ->assertCanNotSeeTableRecords([$otherGrant]);
});

// ---------------------------------------------------------------------------
// Filtro server-side por tipo
// ---------------------------------------------------------------------------

it('el filtro por tipo se resuelve en la query', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $premiumGrants = collect(range(1, 2))->map(fn () => createGrantForUser($user, ['grant_type' => 'premium_days']));
    $boostGrants = collect(range(1, 2))->map(fn () => createGrantForUser($user, ['grant_type' => 'boost_minutes']));

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->assertCanSeeTableRecords([...$premiumGrants, ...$boostGrants])
        ->filterTable('grant_type', 'premium_days')
        ->assertCanSeeTableRecords($premiumGrants)
        ->assertCanNotSeeTableRecords($boostGrants);
});

// ---------------------------------------------------------------------------
// Contenido de fila — snapshot, "Sistema", cantidad con unidad
// ---------------------------------------------------------------------------

it('la fila muestra el label del tipo, la cantidad con unidad y "Sistema" sin admin', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    createGrantForUser($user, [
        'package_name' => 'Rewind x5 Único',
        'grant_type' => 'rewind_credits',
        'grant_value' => 5,
        'admin_id' => null,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->assertSuccessful()
        ->assertSee('Rewind x5 Único')
        ->assertSee('Deshacer swipe (rewind)')
        ->assertSee('5 créditos')
        ->assertSee('Sistema');
});

it('la fila muestra el nombre del admin que otorgó el paquete', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $admin = Admin::factory()->create(['name' => 'Staff Reportado Único']);
    createGrantForUser($user, ['package_name' => 'Paquete Con Admin Único'], $admin);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->assertSuccessful()
        ->assertSee('Paquete Con Admin Único')
        ->assertSee('Staff Reportado Único');
});

// ---------------------------------------------------------------------------
// Es un log inmutable — sin create/update/delete, ni en Policy ni en la UI
// ---------------------------------------------------------------------------

it('la policy no permite crear, actualizar ni eliminar otorgamientos', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $grant = createGrantForUser($user);

    expect($this->admin->can('create', PackageGrant::class))->toBeFalse();
    expect($this->admin->can('update', $grant))->toBeFalse();
    expect($this->admin->can('delete', $grant))->toBeFalse();
    expect($this->admin->can('viewAny', PackageGrant::class))->toBeTrue();
    expect($this->admin->can('view', $grant))->toBeTrue();
});

it('el recurso no registra páginas de crear/editar/ver — solo el listado', function () {
    expect(array_keys(PackageGrantResource::getPages()))->toBe(['index']);
});

it('la lista no expone la acción de crear ni acciones de editar/eliminar por fila', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $grant = createGrantForUser($user);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackageGrants::class)
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit', record: $grant)
        ->assertTableActionDoesNotExist('delete', record: $grant);
});

// ---------------------------------------------------------------------------
// Guard — solo staff autenticado por el guard `admin` accede
// ---------------------------------------------------------------------------

it('sin autenticar, el listado de otorgamientos redirige al login del panel', function () {
    $this->get(PackageGrantResource::getUrl('index'))->assertRedirect();
});

it('un usuario final (guard web) no puede acceder al listado de otorgamientos', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user) // guard 'web', nunca 'admin'
        ->get(PackageGrantResource::getUrl('index'))
        ->assertRedirect();
});

it('un admin autenticado sí puede acceder al listado de otorgamientos', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(PackageGrantResource::getUrl('index'))
        ->assertSuccessful();
});

it('un superadmin también puede ver el listado de otorgamientos', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    expect($superadmin->can('viewAny', PackageGrant::class))->toBeTrue();

    $this->actingAs($superadmin, 'admin')
        ->get(PackageGrantResource::getUrl('index'))
        ->assertSuccessful();
});
