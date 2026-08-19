<?php

use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Models\Admin;
use App\Models\Package;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

// ---------------------------------------------------------------------------
// CRUD — admin puede crear/editar/eliminar paquetes
// ---------------------------------------------------------------------------

it('admin puede crear un paquete desde el panel con los campos correctos', function () {
    $this->actingAs($this->admin, 'admin');

    Livewire::test(CreatePackage::class)
        ->fillForm([
            'name' => 'Premium 7 días',
            'description' => 'Acceso Premium temporal por una semana.',
            'price' => 99.99,
            'grant_type' => 'premium_days',
            'grant_value' => 7,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $package = Package::where('name', 'Premium 7 días')->firstOrFail();

    expect((float) $package->price)->toBe(99.99);
    expect($package->grant_type)->toBe('premium_days');
    expect($package->grant_value)->toBe(7);
    expect($package->is_active)->toBeTrue();
});

it('admin puede editar un paquete existente', function () {
    $package = Package::factory()->premiumDays(7)->create(['name' => 'Nombre original']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(EditPackage::class, ['record' => $package->id])
        ->fillForm([
            'name' => 'Nombre actualizado',
            'grant_type' => 'boost_minutes',
            'grant_value' => 30,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($package->fresh()->name)->toBe('Nombre actualizado');
    expect($package->fresh()->grant_type)->toBe('boost_minutes');
    expect($package->fresh()->grant_value)->toBe(30);
});

it('admin puede eliminar un paquete desde la lista', function () {
    $package = Package::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackages::class)
        ->callTableAction('delete', $package);

    expect(Package::find($package->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Listado — tabla server-side, filtros
// ---------------------------------------------------------------------------

it('la lista de paquetes se ve server-side y el filtro por tipo se resuelve en la query', function () {
    $premiumPackages = Package::factory()->premiumDays()->count(2)->create();
    $boostPackages = Package::factory()->boostMinutes()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackages::class)
        ->assertCanSeeTableRecords([...$premiumPackages, ...$boostPackages])
        ->filterTable('grant_type', 'premium_days')
        ->assertCanSeeTableRecords($premiumPackages)
        ->assertCanNotSeeTableRecords($boostPackages);
});

it('el filtro por activo/inactivo se resuelve en la query', function () {
    $active = Package::factory()->count(2)->create(['is_active' => true]);
    $inactive = Package::factory()->inactive()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackages::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords($active)
        ->assertCanNotSeeTableRecords($inactive);
});

it('la búsqueda por nombre se resuelve en la query', function () {
    $target = Package::factory()->create(['name' => 'Paquete único de boost']);
    $others = Package::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListPackages::class)
        ->searchTable('único de boost')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

// ---------------------------------------------------------------------------
// Guard — solo staff autenticado por el guard `admin` accede
// ---------------------------------------------------------------------------

it('sin autenticar, la lista de paquetes redirige al login del panel', function () {
    $this->get(PackageResource::getUrl('index'))->assertRedirect();
});

it('un usuario final (guard web) no puede acceder al listado de paquetes', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user) // guard 'web', nunca 'admin'
        ->get(PackageResource::getUrl('index'))
        ->assertRedirect();
});

it('un admin autenticado sí puede acceder al listado de paquetes', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(PackageResource::getUrl('index'))
        ->assertSuccessful();
});

it('un superadmin también puede crear paquetes (policy no restringe por rol)', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    expect($superadmin->can('create', Package::class))->toBeTrue();
    expect($superadmin->can('viewAny', Package::class))->toBeTrue();
});
