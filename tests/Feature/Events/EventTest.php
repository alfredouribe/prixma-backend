<?php

use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\User;
use Illuminate\Support\Facades\Route;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createEventUser(): array
{
    $user = User::factory()->withCompletedOnboarding()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'token');
}

beforeEach(function () {
    ['user' => $this->user, 'token' => $this->token] = createEventUser();
});

// ---------------------------------------------------------------------------
// GET /api/events
// ---------------------------------------------------------------------------

describe('GET /api/events', function () {
    it('requiere autenticación', function () {
        $this->getJson('/api/events')->assertStatus(401);
    });

    it('lista eventos', function () {
        Event::factory()->count(3)->create();

        $this->withToken($this->token)
            ->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    });

    it('filtra por categoría correctamente', function () {
        Event::factory()->count(2)->category('pride')->create();
        Event::factory()->count(3)->category('social')->create();

        $response = $this->withToken($this->token)
            ->getJson('/api/events?category=pride')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        collect($response->json('data'))->each(
            fn ($event) => expect($event['category'])->toBe('pride')
        );
    });

    it('oculta el evento de la lista del usuario que lo marcó not_going', function () {
        $event = Event::factory()->create();

        EventRsvp::create([
            'event_id' => $event->id,
            'user_id'  => $this->user->id,
            'status'   => 'not_going',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    // Nota: deliberadamente en un test aparte (no reutiliza el mismo $this->token
    // que la prueba anterior en un segundo request) — Sanctum cachea el usuario
    // resuelto por guard dentro del mismo método de test cuando se hacen dos
    // llamadas HTTP con tokens distintos, así que la segunda llamada puede
    // devolver el usuario de la primera en vez de re-resolver el token nuevo.
    // Separar en tests independientes (cada uno con su propio boot de la app
    // vía RefreshDatabase) evita depender de ese comportamiento.
    it('sigue visible para otro usuario que no lo marcó not_going', function () {
        $event = Event::factory()->create();
        ['user' => $otherUser, 'token' => $otherToken] = createEventUser();

        EventRsvp::create([
            'event_id' => $event->id,
            'user_id'  => $this->user->id,
            'status'   => 'not_going',
        ]);

        $this->withToken($otherToken)
            ->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $event->id);
    });

    it('los contadores públicos solo suman interested y going, nunca not_going', function () {
        $event = Event::factory()->create();

        EventRsvp::factory()->count(2)->interested()->create(['event_id' => $event->id]);
        EventRsvp::factory()->count(3)->going()->create(['event_id' => $event->id]);
        EventRsvp::factory()->count(5)->notGoing()->create(['event_id' => $event->id]);

        $this->withToken($this->token)
            ->getJson('/api/events')
            ->assertStatus(200)
            ->assertJsonPath('data.0.interested_count', 2)
            ->assertJsonPath('data.0.going_count', 3)
            ->assertJsonMissingPath('data.0.not_going_count');
    });
});

// ---------------------------------------------------------------------------
// GET /api/events/{id}
// ---------------------------------------------------------------------------

describe('GET /api/events/{id}', function () {
    it('muestra el detalle del evento con my_rsvp_status null si nunca hizo rsvp', function () {
        $event = Event::factory()->create();

        $this->withToken($this->token)
            ->getJson("/api/events/{$event->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', (string) $event->id)
            ->assertJsonPath('data.my_rsvp_status', null);
    });

    it('retorna 404 si el evento no existe', function () {
        $this->withToken($this->token)
            ->getJson('/api/events/'.\Illuminate\Support\Str::uuid())
            ->assertStatus(404);
    });

    it('genera image_url firmada a partir de image_key cuando el evento tiene imagen', function () {
        \Illuminate\Support\Facades\Storage::fake('s3');
        \Illuminate\Support\Facades\Storage::disk('s3')->put('events/portada.jpg', 'contenido');

        $event = Event::factory()->create(['image_key' => 'events/portada.jpg']);

        $response = $this->withToken($this->token)
            ->getJson("/api/events/{$event->id}")
            ->assertStatus(200);

        // Corrección 2026-07-26: el bucket real es privado (ACLs
        // deshabilitados) — `image_url` ya no es la columna cruda, se firma
        // en cada respuesta a partir de `image_key` (mismo patrón que
        // `video_url` en ProfileResource). No debe ser null ni ser la key
        // cruda sin firmar.
        $url = $response->json('data.image_url');
        expect($url)->not->toBeNull();
        expect($url)->toContain('events/portada.jpg');
        expect($url)->not->toBe('events/portada.jpg');
    });

    it('image_url es null cuando el evento no tiene imagen', function () {
        $event = Event::factory()->create(['image_key' => null]);

        $this->withToken($this->token)
            ->getJson("/api/events/{$event->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.image_url', null);
    });
});

// ---------------------------------------------------------------------------
// POST /api/events/{id}/rsvp
// ---------------------------------------------------------------------------

describe('POST /api/events/{id}/rsvp', function () {
    it('crea el registro de rsvp la primera vez', function () {
        $event = Event::factory()->create();

        $this->withToken($this->token)
            ->postJson("/api/events/{$event->id}/rsvp", ['status' => 'interested'])
            ->assertStatus(200)
            ->assertJsonPath('data.my_rsvp_status', 'interested');

        $this->assertDatabaseHas('event_rsvps', [
            'event_id' => $event->id,
            'user_id'  => $this->user->id,
            'status'   => 'interested',
        ]);

        $this->assertDatabaseCount('event_rsvps', 1);
    });

    it('cambiar de estado reemplaza el registro anterior (upsert, no duplica filas)', function () {
        $event = Event::factory()->create();

        $this->withToken($this->token)
            ->postJson("/api/events/{$event->id}/rsvp", ['status' => 'interested'])
            ->assertStatus(200);

        $this->withToken($this->token)
            ->postJson("/api/events/{$event->id}/rsvp", ['status' => 'going'])
            ->assertStatus(200)
            ->assertJsonPath('data.my_rsvp_status', 'going');

        $this->assertDatabaseCount('event_rsvps', 1);
        $this->assertDatabaseHas('event_rsvps', [
            'event_id' => $event->id,
            'user_id'  => $this->user->id,
            'status'   => 'going',
        ]);
    });

    it('rechaza un status inválido con 422', function () {
        $event = Event::factory()->create();

        $this->withToken($this->token)
            ->postJson("/api/events/{$event->id}/rsvp", ['status' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    });

    it('requiere autenticación', function () {
        $event = Event::factory()->create();

        $this->postJson("/api/events/{$event->id}/rsvp", ['status' => 'interested'])
            ->assertStatus(401);
    });
});

// ---------------------------------------------------------------------------
// No existe creación/edición/borrado de eventos desde la API móvil
// ---------------------------------------------------------------------------

describe('sin endpoints de creación/edición/borrado', function () {
    it('POST /api/events no está registrado (404 o 405, nunca 200/201)', function () {
        // La URI 'api/events' sí existe (GET /api/events, la lista), pero no
        // hay ninguna ruta POST registrada para ella — Laravel responde 405
        // Method Not Allowed en vez de 404 (ver tasks.md: "404 o 405 en POST
        // /api/events" — ambos códigos confirman que no existe creación).
        $this->withToken($this->token)
            ->postJson('/api/events', ['title' => 'Evento pirata'])
            ->assertStatus(405);
    });

    it('no hay rutas PUT/PATCH/DELETE para events/{id} registradas', function () {
        $event = Event::factory()->create();

        $methods = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/events/'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()
            ->values()
            ->all();

        expect($methods)->not->toContain('PUT');
        expect($methods)->not->toContain('PATCH');
        expect($methods)->not->toContain('DELETE');

        // Confirmación adicional en runtime real: la URI existe (para
        // GET/show) pero el método PUT no está registrado para ella →
        // Laravel responde 405 Method Not Allowed, nunca 200.
        $this->withToken($this->token)
            ->putJson("/api/events/{$event->id}", ['title' => 'Hackeado'])
            ->assertStatus(405);
    });
});
