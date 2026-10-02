<?php

use App\Services\ExpoPushService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Igual que PresenceServiceTest: vive en Unit/ pero necesita la app booteada
// (Log/Http facades en las distintas ramas) — solo Feature/ obtiene
// Tests\TestCase automáticamente vía tests/Pest.php.
uses(Tests\TestCase::class);

const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

test('sendToDevices retorna false sin tocar la API de Expo cuando no hay tokens', function () {
    Http::fake();

    expect((new ExpoPushService())->sendToDevices([], ['title' => 't', 'body' => 'b']))->toBeFalse();

    Http::assertNothingSent();
});

test('sendToDevices retorna true cuando al menos un ticket viene ok', function () {
    Http::fake([
        EXPO_PUSH_URL => Http::response([
            'data' => [
                ['status' => 'ok', 'id' => 'ticket-1'],
                ['status' => 'error', 'message' => 'DeviceNotRegistered'],
            ],
        ]),
    ]);

    $result = (new ExpoPushService())->sendToDevices(['token-1', 'token-2'], [
        'title' => '¡Es un match! 🌟',
        'body' => 'Tú y Alex se gustaron mutuamente.',
        'data' => ['conversation_id' => 'uuid-123', 'type' => 'match'],
    ]);

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request) {
        $messages = $request->data();

        return $request->url() === EXPO_PUSH_URL
            && $messages[0]['to'] === 'token-1'
            && $messages[0]['title'] === '¡Es un match! 🌟'
            && $messages[0]['body'] === 'Tú y Alex se gustaron mutuamente.'
            && $messages[0]['data'] === ['conversation_id' => 'uuid-123', 'type' => 'match']
            && $messages[1]['to'] === 'token-2';
    });
});

test('sendToDevices retorna false cuando todos los tickets vienen con error', function () {
    Http::fake([
        EXPO_PUSH_URL => Http::response([
            'data' => [['status' => 'error', 'message' => 'DeviceNotRegistered']],
        ]),
    ]);

    expect((new ExpoPushService())->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices retorna false (no lanza) cuando la API responde con error HTTP', function () {
    Http::fake([
        EXPO_PUSH_URL => Http::response(['errors' => ['algo salió mal']], 500),
    ]);

    expect((new ExpoPushService())->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices retorna false (no lanza) cuando la petición falla por completo', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });

    expect((new ExpoPushService())->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices reparte en lotes de 100 cuando hay más tokens que el límite de la API de Expo', function () {
    Http::fake([
        EXPO_PUSH_URL => Http::sequence()
            ->push(['data' => array_fill(0, 100, ['status' => 'ok', 'id' => 'x'])])
            ->push(['data' => array_fill(0, 5, ['status' => 'ok', 'id' => 'x'])]),
    ]);

    $tokens = array_map(fn ($i) => "token-{$i}", range(1, 105));

    expect((new ExpoPushService())->sendToDevices($tokens, ['title' => 't', 'body' => 'b']))->toBeTrue();

    Http::assertSentCount(2);
});

test('sendToDevices no convierte el payload data a strings — la API de Expo acepta JSON arbitrario', function () {
    Http::fake([
        EXPO_PUSH_URL => Http::response(['data' => [['status' => 'ok', 'id' => 'x']]]),
    ]);

    (new ExpoPushService())->sendToDevices(['token-1'], [
        'title' => 't',
        'body' => 'b',
        'data' => ['count' => 3],
    ]);

    Http::assertSent(fn (Request $request) => $request->data()[0]['data']['count'] === 3);
});

test('sendToDevices agrega el header de autorización solo si hay access_token configurado', function () {
    config(['services.expo.access_token' => 'secreto-de-prueba']);
    Http::fake([EXPO_PUSH_URL => Http::response(['data' => [['status' => 'ok', 'id' => 'x']]])]);

    (new ExpoPushService())->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secreto-de-prueba'));
});

test('sendToDevices no manda header de autorización sin access_token configurado', function () {
    config(['services.expo.access_token' => null]);
    Http::fake([EXPO_PUSH_URL => Http::response(['data' => [['status' => 'ok', 'id' => 'x']]])]);

    (new ExpoPushService())->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']);

    Http::assertSent(fn (Request $request) => !$request->hasHeader('Authorization'));
});
