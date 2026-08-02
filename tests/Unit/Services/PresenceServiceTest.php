<?php

use App\Services\PresenceService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

// tests/Pest.php solo aplica Tests\TestCase (que arranca la app y setea el
// facade root) a la carpeta Feature/ — este test vive en Unit/ pero necesita
// la app booteada porque PresenceService::isUserOnline() usa Log::warning()
// en su rama de fallback, no solo el mock de Broadcast (que sí se auto-basta
// sin app vía Facade::shouldReceive()).
uses(Tests\TestCase::class);

test('isUserOnline retorna true cuando el usuario está en la lista de miembros del canal de presencia', function () {
    $userId = (string) Str::uuid();

    $pusher = Mockery::mock();
    $pusher->shouldReceive('getPresenceUsers')
        ->once()
        ->with('presence-online')
        ->andReturn((object) [
            'users' => [
                (object) ['id' => $userId],
                (object) ['id' => (string) Str::uuid()],
            ],
        ]);

    $broadcaster = Mockery::mock();
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);

    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    expect((new PresenceService())->isUserOnline($userId))->toBeTrue();
});

test('isUserOnline retorna false cuando el usuario no está en la lista de miembros', function () {
    $pusher = Mockery::mock();
    $pusher->shouldReceive('getPresenceUsers')
        ->once()
        ->with('presence-online')
        ->andReturn((object) ['users' => [(object) ['id' => (string) Str::uuid()]]]);

    $broadcaster = Mockery::mock();
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);

    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    expect((new PresenceService())->isUserOnline((string) Str::uuid()))->toBeFalse();
});

test('isUserOnline retorna false sin lanzar excepción cuando la consulta a Reverb falla', function () {
    Broadcast::shouldReceive('connection')
        ->once()
        ->with('reverb')
        ->andThrow(new Exception('No se pudo conectar con Reverb.'));

    expect((new PresenceService())->isUserOnline((string) Str::uuid()))->toBeFalse();
});

test('onlineUserIds retorna los IDs de todos los miembros del canal de presencia', function () {
    $userId1 = (string) Str::uuid();
    $userId2 = (string) Str::uuid();

    $pusher = Mockery::mock();
    $pusher->shouldReceive('getPresenceUsers')
        ->once()
        ->with('presence-online')
        ->andReturn((object) [
            'users' => [
                (object) ['id' => $userId1],
                (object) ['id' => $userId2],
            ],
        ]);

    $broadcaster = Mockery::mock();
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);

    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    expect((new PresenceService())->onlineUserIds())->toBe([$userId1, $userId2]);
});

test('onlineUserIds retorna un array vacío cuando no hay nadie conectado', function () {
    $pusher = Mockery::mock();
    $pusher->shouldReceive('getPresenceUsers')
        ->once()
        ->with('presence-online')
        ->andReturn((object) ['users' => []]);

    $broadcaster = Mockery::mock();
    $broadcaster->shouldReceive('getPusher')->once()->andReturn($pusher);

    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);

    expect((new PresenceService())->onlineUserIds())->toBe([]);
});

test('onlineUserIds retorna un array vacío sin lanzar excepción cuando la consulta a Reverb falla', function () {
    Broadcast::shouldReceive('connection')
        ->once()
        ->with('reverb')
        ->andThrow(new Exception('No se pudo conectar con Reverb.'));

    expect((new PresenceService())->onlineUserIds())->toBe([]);
});
