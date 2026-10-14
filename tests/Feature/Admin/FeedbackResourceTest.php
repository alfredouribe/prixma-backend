<?php

use App\Filament\Resources\FeedbackResource;
use App\Filament\Resources\FeedbackResource\Pages\ListFeedback;
use App\Models\Admin;
use App\Models\Feedback;
use App\Models\Profile;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

function createFeedbackForUser(User $user, array $state = []): Feedback
{
    return Feedback::create(array_merge([
        'user_id' => $user->id,
        'type' => 'comment',
        'message' => fake()->sentence(10),
        'app_version' => '1.0.0',
        'platform' => 'android',
    ], $state));
}

// ---------------------------------------------------------------------------
// Listado — tabla server-side, log de solo lectura
// ---------------------------------------------------------------------------

it('admin autenticado ve la lista paginada de feedback con los campos correctos', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $feedback = createFeedbackForUser($user, [
        'type' => 'bug',
        'message' => 'La app se cierra al abrir el chat',
        'app_version' => '2.3.1',
        'platform' => 'ios',
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->assertCanSeeTableRecords([$feedback])
        ->assertSee($user->profile->display_name)
        ->assertSee('Bug')
        ->assertSee('La app se cierra al abrir el chat')
        ->assertSee('2.3.1')
        ->assertSee('iOS');
});

it('muestra el email como fallback cuando el usuario no tiene perfil', function () {
    $user = User::factory()->withCompletedOnboarding()->create(['email' => 'sin-perfil-feedback@prixma.app']);
    createFeedbackForUser($user);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->assertSuccessful()
        ->assertSee('sin-perfil-feedback@prixma.app');
});

it('muestra "—" cuando app_version/platform vienen null', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    createFeedbackForUser($user, ['app_version' => null, 'platform' => null]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->assertSuccessful()
        ->assertSee('—');
});

it('el listado se ordena por fecha, más reciente primero por default', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $old = createFeedbackForUser($user, ['message' => 'Comentario viejo único']);
    $old->created_at = now()->subDays(3);
    $old->save();

    $recent = createFeedbackForUser($user, ['message' => 'Comentario reciente único']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->assertCanSeeTableRecords([$recent, $old], inOrder: true);
});

// ---------------------------------------------------------------------------
// Filtro server-side por tipo
// ---------------------------------------------------------------------------

it('el filtro por tipo se resuelve en la query', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $bugs = collect(range(1, 2))->map(fn () => createFeedbackForUser($user, ['type' => 'bug']));
    $comments = collect(range(1, 2))->map(fn () => createFeedbackForUser($user, ['type' => 'comment']));

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->assertCanSeeTableRecords([...$bugs, ...$comments])
        ->filterTable('type', 'bug')
        ->assertCanSeeTableRecords($bugs)
        ->assertCanNotSeeTableRecords($comments);
});

// ---------------------------------------------------------------------------
// Búsqueda server-side — por mensaje y por nombre/email del usuario
// ---------------------------------------------------------------------------

it('la búsqueda por mensaje se resuelve en la query', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();

    $target = createFeedbackForUser($user, ['message' => 'El video de verificación no carga nunca']);
    $other = createFeedbackForUser($user, ['message' => 'Me encanta el nuevo diseño']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->searchTable('verificación no carga')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

it('la búsqueda por nombre del usuario se resuelve en la query (SQL)', function () {
    $target = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $target->profile->update(['display_name' => 'Renata Única Feedback']);
    $targetFeedback = createFeedbackForUser($target);

    $others = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherFeedback = createFeedbackForUser($others);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->searchTable('Renata Única')
        ->assertCanSeeTableRecords([$targetFeedback])
        ->assertCanNotSeeTableRecords([$otherFeedback]);
});

it('la búsqueda por email del usuario se resuelve en la query', function () {
    $target = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create(['email' => 'unico-feedback@prixma.app']);
    $targetFeedback = createFeedbackForUser($target);

    $others = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $otherFeedback = createFeedbackForUser($others);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->searchTable('unico-feedback@prixma.app')
        ->assertCanSeeTableRecords([$targetFeedback])
        ->assertCanNotSeeTableRecords([$otherFeedback]);
});

// ---------------------------------------------------------------------------
// Es un log inmutable — sin create/update/delete, ni en Policy ni en la UI
// ---------------------------------------------------------------------------

it('la policy no permite crear, actualizar ni eliminar feedback', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $feedback = createFeedbackForUser($user);

    expect($this->admin->can('create', Feedback::class))->toBeFalse();
    expect($this->admin->can('update', $feedback))->toBeFalse();
    expect($this->admin->can('delete', $feedback))->toBeFalse();
    expect($this->admin->can('viewAny', Feedback::class))->toBeTrue();
    expect($this->admin->can('view', $feedback))->toBeTrue();
});

it('el recurso no registra páginas de crear/editar/ver — solo el listado', function () {
    expect(array_keys(FeedbackResource::getPages()))->toBe(['index']);
});

it('la lista no expone la acción de crear ni acciones de editar/eliminar por fila', function () {
    $user = User::factory()->withCompletedOnboarding()->has(Profile::factory())->create();
    $feedback = createFeedbackForUser($user);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListFeedback::class)
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit', record: $feedback)
        ->assertTableActionDoesNotExist('delete', record: $feedback);
});

// ---------------------------------------------------------------------------
// Guard — solo staff autenticado por el guard `admin` accede
// ---------------------------------------------------------------------------

it('sin autenticar, el listado de feedback redirige al login del panel', function () {
    $this->get(FeedbackResource::getUrl('index'))->assertRedirect();
});

it('un usuario final (guard web) no puede acceder al listado de feedback', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user) // guard 'web', nunca 'admin'
        ->get(FeedbackResource::getUrl('index'))
        ->assertRedirect();
});

it('un admin autenticado sí puede acceder al listado de feedback', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(FeedbackResource::getUrl('index'))
        ->assertSuccessful();
});

it('un superadmin también puede ver el listado de feedback', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    expect($superadmin->can('viewAny', Feedback::class))->toBeTrue();

    $this->actingAs($superadmin, 'admin')
        ->get(FeedbackResource::getUrl('index'))
        ->assertSuccessful();
});
