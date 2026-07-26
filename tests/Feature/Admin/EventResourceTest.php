<?php

use App\Filament\Resources\EventResource;
use App\Filament\Resources\EventResource\Pages\CreateEvent;
use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Filament\Resources\EventResource\Pages\ListEvents;
use App\Filament\Resources\EventResource\Pages\ViewEvent;
use App\Models\Admin;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create(['role' => 'admin']);
});

// ---------------------------------------------------------------------------
// CRUD — admin puede crear/editar/eliminar eventos
// ---------------------------------------------------------------------------

it('admin puede crear un evento desde el panel con los campos correctos', function () {
    $this->actingAs($this->admin, 'admin');

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'title' => 'Marcha del Orgullo CDMX',
            'description' => 'Celebración anual de la comunidad LGBTQ+ en la capital.',
            'category' => 'pride',
            'event_date' => '2026-08-15 12:00:00',
            'location_name' => 'Ángel de la Independencia, CDMX',
            'latitude' => 19.4270,
            'longitude' => -99.1677,
            'external_link' => 'https://example.com/marcha-orgullo',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::where('title', 'Marcha del Orgullo CDMX')->firstOrFail();

    expect($event->category)->toBe('pride');
    expect($event->event_date->format('Y-m-d H:i'))->toBe('2026-08-15 12:00');
    expect($event->location_name)->toBe('Ángel de la Independencia, CDMX');
    expect((float) $event->latitude)->toBe(19.427);
    expect((float) $event->longitude)->toBe(-99.1677);
    expect($event->external_link)->toBe('https://example.com/marcha-orgullo');

    // creator_id siempre es el admin autenticado, nunca un campo del
    // formulario (ver domain.md → Event, spec.md → "Creación de eventos").
    expect($event->creator_id)->toBe((string) $this->admin->id);
});

it('admin puede editar un evento existente', function () {
    $event = Event::factory()->create([
        'title' => 'Título original',
        'category' => 'social',
    ]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(EditEvent::class, ['record' => $event->id])
        ->fillForm([
            'title' => 'Título actualizado',
            'category' => 'art',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->title)->toBe('Título actualizado');
    expect($event->fresh()->category)->toBe('art');
});

it('admin puede eliminar un evento desde la lista', function () {
    $event = Event::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListEvents::class)
        ->callTableAction('delete', $event);

    expect(Event::find($event->id))->toBeNull();
});

it('la lista de eventos se ve server-side (tabla) y el filtro por categoría se resuelve en la query', function () {
    $prideEvents = Event::factory()->count(2)->create(['category' => 'pride']);
    $socialEvents = Event::factory()->count(2)->create(['category' => 'social']);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([...$prideEvents, ...$socialEvents])
        ->filterTable('category', 'pride')
        ->assertCanSeeTableRecords($prideEvents)
        ->assertCanNotSeeTableRecords($socialEvents);
});

it('admin puede subir una imagen para el evento — se guarda en el disco s3, sin ACL público', function () {
    Storage::fake('s3');

    $this->actingAs($this->admin, 'admin');

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'title' => 'Evento con imagen',
            'description' => 'Descripción de prueba.',
            'category' => 'social',
            'event_date' => '2026-09-01 18:00:00',
            'location_name' => 'Centro cultural',
            'image_key' => UploadedFile::fake()->image('portada.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::where('title', 'Evento con imagen')->firstOrFail();

    // Corrección 2026-07-26: `image_url` (columna) ya no se deriva aquí —
    // el bucket real es privado (ACLs deshabilitados, "Bucket owner
    // enforced"), pedir ACL público en el upload hacía fallar el PutObject
    // en silencio (Storage disk `s3` tiene `'throw' => false`). Solo se
    // persiste `image_key`; la URL se firma en cada respuesta de la API
    // móvil (`EventResource::toArray()`, ver EventTest.php).
    expect($event->image_key)->not->toBeNull();
    Storage::disk('s3')->assertExists($event->image_key);
    expect($event->image_url)->toBeNull();
});

it('la búsqueda por título se resuelve en la query', function () {
    $target = Event::factory()->create(['title' => 'Feria del libro diverso']);
    $others = Event::factory()->count(2)->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ListEvents::class)
        ->searchTable('Feria del libro')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords($others);
});

// ---------------------------------------------------------------------------
// Detalle — lista de asistentes y estadísticas
// ---------------------------------------------------------------------------

it('el detalle muestra la lista de asistentes con su estado', function () {
    $event = Event::factory()->create();

    $interestedUser = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($interestedUser)->create(['display_name' => 'Persona Interesada']);
    EventRsvp::factory()->for($event)->for($interestedUser, 'user')->interested()->create();

    $goingUser = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($goingUser)->create(['display_name' => 'Persona Confirmada']);
    EventRsvp::factory()->for($event)->for($goingUser, 'user')->going()->create();

    $notGoingUser = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($notGoingUser)->create(['display_name' => 'Persona Ausente']);
    EventRsvp::factory()->for($event)->for($notGoingUser, 'user')->notGoing()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertSuccessful()
        ->assertSee('Persona Interesada')
        ->assertSee('Me interesa')
        ->assertSee('Persona Confirmada')
        ->assertSee('Iré')
        ->assertSee('Persona Ausente')
        ->assertSee('No iré');
});

it('el detalle muestra el estado vacío cuando nadie ha confirmado asistencia', function () {
    $event = Event::factory()->create();

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertSuccessful()
        ->assertSee('Nadie ha confirmado asistencia todavía');
});

it('el detalle muestra la imagen a partir de image_key (URL firmada), no de la columna image_url', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('events/portada.jpg', 'contenido');

    // Corrección 2026-07-26: `image_url` (columna) ya no se persiste desde
    // CreateEvent/EditEvent — el bucket real es privado. `ViewEvent` debe
    // resolver la imagen a partir de `image_key`, igual que la API móvil.
    // Se deja `image_url` explícitamente null para probar que el detalle
    // NO depende de esa columna (bug real que se reportó: el panel
    // mostraba "Sin imagen" aunque `image_key` existiera).
    $event = Event::factory()->create(['image_key' => 'events/portada.jpg', 'image_url' => null]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertSuccessful()
        ->assertDontSee('Sin imagen');
});

it('el detalle muestra "Sin imagen" cuando el evento no tiene image_key', function () {
    $event = Event::factory()->create(['image_key' => null, 'image_url' => null]);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertSuccessful()
        ->assertSee('Sin imagen');
});

it('el detalle muestra las estadísticas totales por estado, incluyendo not_going', function () {
    $event = Event::factory()->create();

    // Conteos deliberadamente distintos y de dos dígitos para evitar falsos
    // positivos en assertSee (un solo dígito como "3" puede coincidir con
    // cualquier fecha, UUID o control de paginación en la página).
    EventRsvp::factory()->for($event)->interested()->count(17)->create();
    EventRsvp::factory()->for($event)->going()->count(11)->create();
    EventRsvp::factory()->for($event)->notGoing()->count(23)->create();

    // La fuente de verdad de lo que el infolist renderiza es el estado del
    // registro (withCount de EventResource::getEloquentQuery(), la misma
    // query que usa ViewRecord para cargar $this->record) — se verifica
    // aquí explícitamente antes de comprobar el render.
    $withCounts = EventResource::getEloquentQuery()->findOrFail($event->id);
    expect($withCounts->interested_count)->toBe(17);
    expect($withCounts->going_count)->toBe(11);
    expect($withCounts->not_going_count)->toBe(23);

    $this->actingAs($this->admin, 'admin');

    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->assertSuccessful()
        ->assertSee('17')
        ->assertSee('11')
        ->assertSee('23');
});

it('la tabla de lista muestra el contador de asistentes como interested + going, nunca not_going', function () {
    $event = Event::factory()->create(['title' => 'Evento con contador visible']);

    EventRsvp::factory()->for($event)->interested()->count(2)->create();
    EventRsvp::factory()->for($event)->going()->count(3)->create();
    EventRsvp::factory()->for($event)->notGoing()->count(10)->create();

    $this->actingAs($this->admin, 'admin');

    // Columna calculada (attendees_count) vía withCount, verificada por
    // estado real de la columna en la tabla — no por texto suelto en el
    // HTML (assertSee('5') sería un falso positivo: "25"/"50" del selector
    // de paginación también contienen el dígito "5"). Se pasa el UUID (no
    // el modelo) para que assertTableColumnStateSet resuelva el record
    // desde los registros ya cargados por la tabla (con los withCount de
    // EventResource::getEloquentQuery() aplicados) en vez de usar la
    // instancia de Eloquent "plana" recién creada por la factory, que no
    // trae esos atributos calculados.
    Livewire::test(ListEvents::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('attendees_count', 5, $event->id); // 2 interested + 3 going, nunca los 10 not_going
});

// ---------------------------------------------------------------------------
// Guard — solo staff autenticado por el guard `admin` accede
// ---------------------------------------------------------------------------

it('sin autenticar, la lista de eventos redirige al login del panel', function () {
    $this->get(EventResource::getUrl('index'))->assertRedirect();
});

it('un usuario final (guard web) no puede acceder al listado de eventos', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    $this->actingAs($user) // guard 'web', nunca 'admin'
        ->get(EventResource::getUrl('index'))
        ->assertRedirect();
});

it('un admin autenticado sí puede acceder al listado de eventos', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(EventResource::getUrl('index'))
        ->assertSuccessful();
});

it('un superadmin también puede crear eventos (policy no restringe por rol)', function () {
    $superadmin = Admin::factory()->superadmin()->create();

    expect($superadmin->can('create', Event::class))->toBeTrue();
    expect($superadmin->can('viewAny', Event::class))->toBeTrue();
});
