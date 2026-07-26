<?php

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\Admin;
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
