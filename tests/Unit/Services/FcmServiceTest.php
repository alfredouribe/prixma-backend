<?php

use App\Services\FcmService;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;

// Igual que PresenceServiceTest: vive en Unit/ pero necesita la app booteada
// (Log facade en las ramas de info/warning/error, mocks vía el contenedor) —
// solo Feature/ obtiene Tests\TestCase automáticamente vía tests/Pest.php.
uses(Tests\TestCase::class);

function fcmTarget(string $token = 'token-abc'): MessageTarget
{
    return MessageTarget::with(MessageTarget::TOKEN, $token);
}

test('sendToDevices retorna false sin tocar Messaging cuando no hay tokens', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('sendMulticast');
    $this->app->instance(Messaging::class, $messaging);

    expect(app(FcmService::class)->sendToDevices([], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices retorna false sin lanzar cuando no hay credenciales configuradas (no-op)', function () {
    config(['services.fcm.credentials_path' => null]);

    // Sin binding de Messaging en el contenedor — FcmService debe resolver
    // por su cuenta y encontrar que no hay credenciales, sin reventar.
    expect((new FcmService())->sendToDevices(['token-abc'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices retorna false cuando credentials_path apunta a un archivo que no existe', function () {
    config(['services.fcm.credentials_path' => '/ruta/que/no/existe.json']);

    expect((new FcmService())->sendToDevices(['token-abc'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices retorna true cuando sendMulticast reporta al menos un éxito', function () {
    $report = MulticastSendReport::withItems([
        SendReport::success(fcmTarget('token-1'), []),
        SendReport::failure(fcmTarget('token-2'), new ServerError('fallo de prueba')),
    ]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')
        ->once()
        ->withArgs(fn ($message, $tokens) => $message instanceof CloudMessage && $tokens === ['token-1', 'token-2'])
        ->andReturn($report);

    $service = new FcmService($messaging);

    expect($service->sendToDevices(['token-1', 'token-2'], [
        'title' => '¡Es un match! 🌟',
        'body' => 'Tú y Alex se gustaron mutuamente.',
        'data' => ['conversation_id' => 'uuid-123', 'type' => 'match'],
    ]))->toBeTrue();
});

test('sendToDevices retorna false cuando todos los tokens fallan', function () {
    $report = MulticastSendReport::withItems([
        SendReport::failure(fcmTarget('token-1'), new ServerError('fallo de prueba')),
    ]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->andReturn($report);

    expect((new FcmService($messaging))->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices retorna false (no lanza) cuando Messaging lanza una excepción', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->andThrow(new ServerError('caído'));

    expect((new FcmService($messaging))->sendToDevices(['token-1'], ['title' => 't', 'body' => 'b']))->toBeFalse();
});

test('sendToDevices convierte el payload data a strings antes de enviarlo (FCM exige string=>string)', function () {
    $report = MulticastSendReport::withItems([SendReport::success(fcmTarget(), [])]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')
        ->once()
        ->withArgs(function (CloudMessage $message) {
            $serialized = $message->jsonSerialize();

            return $serialized['data']['count'] === '3';
        })
        ->andReturn($report);

    (new FcmService($messaging))->sendToDevices(['token-1'], [
        'title' => 't',
        'body' => 'b',
        'data' => ['count' => 3],
    ]);
});
