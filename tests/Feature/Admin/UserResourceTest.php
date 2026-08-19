<?php

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\Admin;
use App\Models\Package;
use App\Models\PackageGrant;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

// ---------------------------------------------------------------------------
// Listado — tabla server-side
// ---------------------------------------------------------------------------

it('admin autenticado ve la lista paginada de usuarios', function () {
    $users = User::factory()->withCompletedOnboarding()->has(Profile::factory())->count(3)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('el filtro por estado de cuenta se resuelve en la query', function () {
    $active = User::factory()->withCompletedOnboarding()->has(Profile::factory())->count(2)->create(['status' => 'active']);
    $suspended = User::factory()->withCompletedOnboarding()->has(Profile::factory())->suspended()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->filterTable('status', 'suspended')
        ->assertCanSeeTableRecords($suspended)
        ->assertCanNotSeeTableRecords($active);
});

it('el filtro por verificación se resuelve en la query', function () {
    $verified = User::factory()->withCompletedOnboarding()->has(Profile::factory()->verified())->count(2)->create();
    $unverified = User::factory()->withCompletedOnboarding()->has(Profile::factory())->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->filterTable('verification_status', 'verified')
        ->assertCanSeeTableRecords($verified)
        ->assertCanNotSeeTableRecords($unverified);
});

it('la búsqueda por nombre se resuelve en la query (SQL)', function () {
    $target = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $target->profile->update(['display_name' => 'Valentina Única']);

    $others = User::factory()->withCompletedOnboarding()->has(Profile::factory())->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->searchTable('Valentina')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

it('la búsqueda por email se resuelve en la query', function () {
    $target = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['email' => 'unico-usuario@prixma.app']);
    $others = User::factory()->withCompletedOnboarding()->has(Profile::factory())->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->searchTable('unico-usuario@prixma.app')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

// ---------------------------------------------------------------------------
// Detalle
// ---------------------------------------------------------------------------

it('el detalle muestra los datos del perfil', function () {
    $user = User::factory()->withCompletedOnboarding()->create(['email' => 'detalle@prixma.app']);
    $profile = Profile::factory()->for($user)->create([
        'display_name' => 'Perfil Detallado',
        'bio' => 'Bio de prueba',
        'city' => 'Guadalajara',
        'intention' => 'friendship',
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Perfil Detallado')
        ->assertSee('detalle@prixma.app')
        ->assertSee('Bio de prueba')
        ->assertSee('Guadalajara');
});

it('el detalle muestra las fotos del perfil', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $photo = $user->profile->photos()->create([
        'url' => 'https://cdn.prixma.app/photos/foto-usuario-unica.jpg',
        'key' => 'photos/foto-usuario-unica.jpg',
        'position' => 0,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSeeHtml($photo->url);
});

it('el detalle muestra el reproductor de video cuando el video ya fue procesado', function () {
    Storage::fake('s3');

    $user = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($user)->create([
        'video_url' => 'videos/profiles/video-procesado-unico.mp4',
        'video_processed' => true,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSeeHtml('<video controls')
        ->assertDontSee('Video en proceso')
        ->assertDontSee('Sin video');
});

it('el detalle muestra "Video en proceso" cuando el video aún no ha sido procesado', function () {
    $user = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($user)->create([
        'video_url' => 'videos/profiles/video-en-proceso-unico.mp4',
        'video_processed' => false,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Video en proceso')
        ->assertDontSeeHtml('<video controls');
});

it('el detalle muestra "Sin video" cuando el usuario no tiene video', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Sin video')
        ->assertDontSeeHtml('<video controls');
});

it('el detalle muestra los reportes enviados por el usuario', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $reportedUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $reportedUser->profile->update(['display_name' => 'Reportado Único']);

    Report::factory()->create([
        'reporter_id' => $user->id,
        'reported_id' => $reportedUser->id,
        'reason' => 'harassment',
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Reportado Único')
        ->assertSee('Acoso');
});

it('el detalle muestra los reportes recibidos por el usuario', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $reporterUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $reporterUser->profile->update(['display_name' => 'Reportante Único']);

    Report::factory()->create([
        'reporter_id' => $reporterUser->id,
        'reported_id' => $user->id,
        'reason' => 'fake_profile',
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Reportante Único')
        ->assertSee('Perfil falso');
});

it('el detalle muestra los matches activos del usuario', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherUser = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherUser->profile->update(['display_name' => 'Match Único']);

    [$id1, $id2] = $user->id < $otherUser->id ? [$user->id, $otherUser->id] : [$otherUser->id, $user->id];
    UserMatch::create(['user_id_1' => $id1, 'user_id_2' => $id2]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Match Único');
});

it('un match cancelado por bloqueo (eliminado físicamente de matches) NO aparece en el detalle', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $cancelledPartner = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $cancelledPartner->profile->update(['display_name' => 'Match Cancelado']);

    [$id1, $id2] = $user->id < $cancelledPartner->id
        ? [$user->id, $cancelledPartner->id]
        : [$cancelledPartner->id, $user->id];
    $cancelledMatch = UserMatch::create(['user_id_1' => $id1, 'user_id_2' => $id2]);

    // Simula lo que SafetyService::blockUser() hace: delete físico, sin
    // soft delete (la tabla `matches` no lo tiene) — ver alcance confirmado
    // con el humano en features/profile/specs/plan.md.
    $cancelledMatch->delete();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertDontSee('Match Cancelado')
        ->assertSee('Sin matches activos');
});

it('el detalle de un usuario sin fotos, reportes o matches no rompe la vista', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Sin fotos')
        ->assertSee('Este usuario no ha reportado a nadie')
        ->assertSee('Este usuario no ha sido reportado')
        ->assertSee('Sin matches activos');
});

it('el detalle de un usuario sin perfil (onboarding incompleto) no rompe la vista', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee($user->email)
        ->assertSee('Sin fotos');
});

// ---------------------------------------------------------------------------
// Toggle de Premium (features/premium/specs/) — única excepción al
// "solo lectura" de este recurso, ver comentario en UserResource::table().
// ---------------------------------------------------------------------------

it('activa Premium para un usuario que no lo tiene', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['is_premium' => false]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->callTableAction('togglePremium', $user);

    expect($user->fresh()->is_premium)->toBeTrue();
});

it('quita Premium a un usuario que ya lo tiene', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['is_premium' => true]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->callTableAction('togglePremium', $user);

    expect($user->fresh()->is_premium)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Quitar ban (features/safety/specs/plan.md → "Banear desde un reporte") —
// segunda excepción al "solo lectura" de este recurso, mismo patrón que
// togglePremium. Delega en SafetyService::unbanUser(), ya probado a nivel
// de Service en tests/Feature/Safety/SafetyTest.php — aquí solo se cubre el
// wiring de Filament.
// ---------------------------------------------------------------------------

it('quita el ban a un usuario baneado', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['status' => 'banned']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->callTableAction('unban', $user);

    expect($user->fresh()->status)->toBe('active');
});

it('la acción de quitar ban no está visible para un usuario que no está baneado', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['status' => 'active']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unban', $user);
});

it('un superadmin también puede quitar el ban', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['status' => 'banned']);

    $this->actingAs($superadmin, 'admin');

    Livewire::test(ListUsers::class)
        ->callTableAction('unban', $user);

    expect($user->fresh()->status)->toBe('active');
});

// ---------------------------------------------------------------------------
// Otorgar paquete (features/premium/specs/ → "Catálogo de paquetes") —
// tercera excepción al "solo lectura" de este recurso, mismo patrón que
// togglePremium/unban. Delega en PackageService::grantToUser(), ya probado a
// nivel de Service en tests/Feature/Premium/PackageServiceTest.php — aquí
// solo se cubre el wiring de Filament (el select solo ofrece paquetes
// activos, y que el Service es el que realmente aplica el efecto).
// ---------------------------------------------------------------------------

it('otorga un paquete de premium_days a un usuario y extiende premium_until', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['premium_until' => null]);
    $package = Package::factory()->premiumDays(7)->create(['is_active' => true]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        // El PK UUID vuelve de Eloquent como Ramsey\Uuid\Lazy\LazyUuidFromString,
        // no un string plano — Livewire no tiene synthesizer para ese tipo al
        // serializar mountedTableActionsData, mismo patrón ya usado en el
        // resto de la suite (ver ChatTest.php/MatchingTest.php → (string) $x->id).
        ->callTableAction('grantPackage', $user, data: [
            'package_id' => (string) $package->id,
        ]);

    expect($user->fresh()->premium_until)->not->toBeNull();
    expect($user->fresh()->premium_until->isFuture())->toBeTrue();
    expect(now()->diffInDays($user->fresh()->premium_until))->toBeGreaterThanOrEqual(6);
});

it('el select de otorgar paquete solo ofrece paquetes activos', function () {
    // Nombres sin acentos a propósito: el select de Filament serializa sus
    // opciones como JSON embebido en un <script> (JSON.parse(...)), donde
    // los caracteres acentuados quedan escapados como \uXXXX — assertSee()
    // compara contra el HTML literal (con e()), así que un nombre con
    // acentos daría un falso negativo aquí aunque la opción sí esté presente.
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    Package::factory()->create(['is_active' => true, 'name' => 'Paquete Activo Unico']);
    Package::factory()->inactive()->create(['name' => 'Paquete Inactivo Unico']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListUsers::class)
        ->mountTableAction('grantPackage', $user)
        ->assertSee('Paquete Activo Unico')
        ->assertDontSee('Paquete Inactivo Unico');
});

it('un superadmin también puede otorgar un paquete', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['rewind_credits' => 0]);
    $package = Package::factory()->rewindCredits(3)->create(['is_active' => true]);

    $this->actingAs($superadmin, 'admin');

    Livewire::test(ListUsers::class)
        ->callTableAction('grantPackage', $user, data: [
            'package_id' => (string) $package->id,
        ]);

    expect($user->fresh()->rewind_credits)->toBe(3);
});

// ---------------------------------------------------------------------------
// Premium y perks (features/premium/specs/ → "Historial de paquetes
// otorgados") — nueva sección de ViewUser con el estado actual de perks del
// usuario y su historial de PackageGrant.
// ---------------------------------------------------------------------------

it('el detalle muestra el estado actual de perks del usuario', function () {
    $premiumUntil = now()->addDays(5);
    $seeLikersUntil = now()->addDays(3);
    $boostedUntil = now()->addMinutes(30);

    $user = User::factory()->withCompletedOnboarding()->create([
        'is_premium' => true,
        'premium_until' => $premiumUntil,
        'see_likers_until' => $seeLikersUntil,
        'rewind_credits' => 4,
        'extra_super_likes' => 2,
    ]);
    Profile::factory()->for($user)->create(['boosted_until' => $boostedUntil]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee($premiumUntil->format('d/m/Y H:i'))
        ->assertSee($seeLikersUntil->format('d/m/Y H:i'))
        ->assertSee($boostedUntil->format('d/m/Y H:i'))
        ->assertSee('4')
        ->assertSee('2');
});

it('el detalle muestra el historial de paquetes otorgados, más reciente primero', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $admin = Admin::factory()->create(['name' => 'Staff Otorgante Único']);

    // created_at se controla vía save() sobre un modelo ya existente (no
    // create()), porque PackageGrant::booted() pisa created_at con now()
    // dentro del evento `creating` — mismo criterio ya usado en el resto de
    // la suite para forzar orden cronológico (ver MatchingTest.php).
    $oldGrant = PackageGrant::create([
        'user_id' => $user->id,
        'admin_id' => $admin->id,
        'package_name' => 'Paquete Viejo Único',
        'grant_type' => 'rewind_credits',
        'grant_value' => 3,
    ]);
    $oldGrant->created_at = now()->subDays(2);
    $oldGrant->save();

    PackageGrant::create([
        'user_id' => $user->id,
        'admin_id' => $admin->id,
        'package_name' => 'Paquete Reciente Único',
        'grant_type' => 'premium_days',
        'grant_value' => 7,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Paquete Reciente Único')
        ->assertSee('Paquete Viejo Único')
        ->assertSee('Staff Otorgante Único')
        ->assertSeeInOrder(['Paquete Reciente Único', 'Paquete Viejo Único']);
});

it('el detalle muestra "Sistema" cuando el otorgamiento no tiene admin asociado', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    PackageGrant::create([
        'user_id' => $user->id,
        'admin_id' => null,
        'package_name' => 'Paquete Sin Admin Único',
        'grant_type' => 'boost_minutes',
        'grant_value' => 15,
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Paquete Sin Admin Único')
        ->assertSee('Sistema');
});

it('el detalle muestra el estado vacío cuando el usuario no tiene historial de paquetes', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSuccessful()
        ->assertSee('Sin paquetes otorgados');
});

// ---------------------------------------------------------------------------
// Autorización
// ---------------------------------------------------------------------------

it('un superadmin también puede ver la lista y el detalle', function () {
    $superadmin = Admin::factory()->superadmin()->create();
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $this->actingAs($superadmin, 'admin');

    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$user]);
    Livewire::test(ViewUser::class, ['record' => $user->id])->assertSuccessful();
});

it('un usuario final autenticado con el guard por defecto no puede acceder al listado de usuarios', function () {
    $endUser = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($endUser) // guard 'web', nunca 'admin'
        ->get('/admin/users')
        ->assertRedirect(); // nunca 200
});

it('sin autenticar, el listado de usuarios redirige al login del panel', function () {
    $this->get('/admin/users')->assertRedirect();
});
