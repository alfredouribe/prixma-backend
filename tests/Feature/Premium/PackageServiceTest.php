<?php

use App\Models\Admin;
use App\Models\Package;
use App\Models\PackageGrant;
use App\Models\Profile;
use App\Models\User;
use App\Services\PackageService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createPackageTestUser(array $userState = []): User
{
    $user = User::factory()->withCompletedOnboarding()->create($userState);

    Profile::create([
        'user_id' => $user->id,
        'display_name' => fake()->name(),
        'city' => 'CDMX',
        'intention' => 'friendship',
        'onboarding_step' => 6,
        'onboarding_completed' => true,
    ]);

    return $user->fresh();
}

beforeEach(function () {
    $this->service = new PackageService();
});

// ---------------------------------------------------------------------------
// Los 4 tipos de grant
// ---------------------------------------------------------------------------

it('premium_days otorga premium_until N días desde ahora', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->premiumDays(10)->create();

    $this->service->grantToUser($user, $package);

    $user->refresh();
    expect($user->premium_until)->not->toBeNull();
    expect($user->premium_until->diffInDays(now()->addDays(10)))->toBeLessThan(1);
});

it('boost_minutes otorga boosted_until en el perfil del usuario', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->boostMinutes(45)->create();

    $this->service->grantToUser($user, $package);

    $user->profile->refresh();
    expect($user->profile->boosted_until)->not->toBeNull();
    expect($user->profile->boosted_until->diffInMinutes(now()->addMinutes(45)))->toBeLessThan(1);
});

it('see_likers_days otorga see_likers_until N días desde ahora', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->seeLikersDays(5)->create();

    $this->service->grantToUser($user, $package);

    $user->refresh();
    expect($user->see_likers_until)->not->toBeNull();
    expect($user->see_likers_until->diffInDays(now()->addDays(5)))->toBeLessThan(1);
});

it('rewind_credits suma créditos al usuario', function () {
    $user = createPackageTestUser(['rewind_credits' => 2]);
    $package = Package::factory()->rewindCredits(3)->create();

    $this->service->grantToUser($user, $package);

    expect($user->fresh()->rewind_credits)->toBe(5);
});

it('super_like_credits suma créditos de super like extra al usuario', function () {
    $user = createPackageTestUser(['extra_super_likes' => 1]);
    $package = Package::factory()->superLikeCredits(5)->create();

    $this->service->grantToUser($user, $package);

    expect($user->fresh()->extra_super_likes)->toBe(6);
});

// ---------------------------------------------------------------------------
// Stacking
// ---------------------------------------------------------------------------

it('premium_days suma a partir de la expiración actual cuando todavía no ha expirado', function () {
    $currentExpiry = now()->addDays(5);
    $user = createPackageTestUser(['premium_until' => $currentExpiry]);
    $package = Package::factory()->premiumDays(10)->create();

    $this->service->grantToUser($user, $package);

    $user->refresh();
    // 5 días restantes + 10 nuevos = ~15 días desde ahora
    expect($user->premium_until->diffInDays(now()->addDays(15)))->toBeLessThan(1);
});

it('premium_days reinicia desde now() cuando el premium anterior ya expiró', function () {
    $user = createPackageTestUser(['premium_until' => now()->subDay()]);
    $package = Package::factory()->premiumDays(10)->create();

    $this->service->grantToUser($user, $package);

    $user->refresh();
    expect($user->premium_until->diffInDays(now()->addDays(10)))->toBeLessThan(1);
});

it('premium_days reinicia desde now() cuando el usuario nunca tuvo premium_until', function () {
    $user = createPackageTestUser(['premium_until' => null]);
    $package = Package::factory()->premiumDays(7)->create();

    $this->service->grantToUser($user, $package);

    $user->refresh();
    expect($user->premium_until->diffInDays(now()->addDays(7)))->toBeLessThan(1);
});

it('boost_minutes suma a partir de la expiración actual cuando todavía no ha expirado', function () {
    $user = createPackageTestUser();
    $user->profile->update(['boosted_until' => now()->addMinutes(20)]);
    $package = Package::factory()->boostMinutes(30)->create();

    $this->service->grantToUser($user, $package);

    $user->profile->refresh();
    expect($user->profile->boosted_until->diffInMinutes(now()->addMinutes(50)))->toBeLessThan(1);
});

it('see_likers_days suma a partir de la expiración actual cuando todavía no ha expirado', function () {
    $user = createPackageTestUser(['see_likers_until' => now()->addDays(2)]);
    $package = Package::factory()->seeLikersDays(3)->create();

    $this->service->grantToUser($user, $package);

    $user->refresh();
    expect($user->see_likers_until->diffInDays(now()->addDays(5)))->toBeLessThan(1);
});

it('rewind_credits siempre suma, nunca reinicia (no es basado en tiempo)', function () {
    $user = createPackageTestUser(['rewind_credits' => 0]);
    $packageA = Package::factory()->rewindCredits(2)->create();
    $packageB = Package::factory()->rewindCredits(4)->create();

    $this->service->grantToUser($user, $packageA);
    $this->service->grantToUser($user, $packageB);

    expect($user->fresh()->rewind_credits)->toBe(6);
});

// ---------------------------------------------------------------------------
// Historial de paquetes otorgados (2026-08-17)
// ---------------------------------------------------------------------------

it('grantToUser deja un PackageGrant con el snapshot correcto del paquete', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->premiumDays(10)->create(['name' => 'Premium 10 días']);

    $this->service->grantToUser($user, $package);

    $this->assertDatabaseHas('package_grants', [
        'user_id' => $user->id,
        'package_id' => $package->id,
        'admin_id' => null,
        'package_name' => 'Premium 10 días',
        'grant_type' => 'premium_days',
        'grant_value' => 10,
    ]);
});

it('grantToUser registra el admin_id cuando se pasa un admin', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->rewindCredits(3)->create();
    $admin = Admin::factory()->create();

    $this->service->grantToUser($user, $package, $admin);

    $grant = PackageGrant::where('user_id', $user->id)->firstOrFail();
    expect($grant->admin_id)->toBe((string) $admin->id);
});

it('grantToUser deja admin_id en null cuando no se pasa admin (default)', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->boostMinutes(30)->create();

    $this->service->grantToUser($user, $package);

    $grant = PackageGrant::where('user_id', $user->id)->firstOrFail();
    expect($grant->admin_id)->toBeNull();
});

it('el snapshot del PackageGrant sobrevive si el Package original se borra después', function () {
    $user = createPackageTestUser();
    $package = Package::factory()->seeLikersDays(7)->create(['name' => 'Ver quién te dio like x7']);

    $this->service->grantToUser($user, $package);

    $package->delete();

    $grant = PackageGrant::where('user_id', $user->id)->firstOrFail();
    expect($grant->package_id)->toBeNull()
        ->and($grant->package_name)->toBe('Ver quién te dio like x7')
        ->and($grant->grant_type)->toBe('see_likers_days')
        ->and($grant->grant_value)->toBe(7);
});
