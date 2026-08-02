<?php

use App\Jobs\SendMatchNotification;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createMatchNotificationUser(string $displayName): User
{
    $user = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($user)->create(['display_name' => $displayName]);

    return $user;
}

test('crea una notificación in-app para ambos usuarios, sin importar si tienen tokens', function () {
    $user1 = createMatchNotificationUser('Alex');
    $user2 = createMatchNotificationUser('Sam');
    $conversationId = (string) Str::uuid();

    app()->call([new SendMatchNotification($user1, $user2, $conversationId), 'handle']);

    expect(Notification::where('user_id', $user1->id)->count())->toBe(1);
    expect(Notification::where('user_id', $user2->id)->count())->toBe(1);

    $notification1 = Notification::where('user_id', $user1->id)->first();
    expect($notification1->type)->toBe('match');
    expect($notification1->title)->toBe('¡Es un match! 🌟');
    expect($notification1->body)->toBe('Tú y Sam se gustaron mutuamente.');
    expect($notification1->data)->toBe(['conversation_id' => $conversationId]);
    expect($notification1->sent_at)->toBeNull();

    $notification2 = Notification::where('user_id', $user2->id)->first();
    expect($notification2->body)->toBe('Tú y Alex se gustaron mutuamente.');
});

test('marca sent_at cuando FcmService confirma el envío', function () {
    $user1 = createMatchNotificationUser('Alex');
    $user2 = createMatchNotificationUser('Sam');
    DeviceToken::factory()->for($user1)->create();

    $this->mock(FcmService::class)->shouldReceive('sendToDevices')->once()->andReturn(true);

    app()->call([new SendMatchNotification($user1, $user2, (string) Str::uuid()), 'handle']);

    expect(Notification::where('user_id', $user1->id)->first()->sent_at)->not->toBeNull();
});

test('no marca sent_at cuando FCM no está configurado (best-effort)', function () {
    $user1 = createMatchNotificationUser('Alex');
    $user2 = createMatchNotificationUser('Sam');
    DeviceToken::factory()->for($user1)->create();

    $this->mock(FcmService::class)->shouldReceive('sendToDevices')->once()->andReturn(false);

    app()->call([new SendMatchNotification($user1, $user2, (string) Str::uuid()), 'handle']);

    expect(Notification::where('user_id', $user1->id)->first()->sent_at)->toBeNull();
});

test('no intenta enviar push si el usuario no tiene tokens de dispositivo', function () {
    $user1 = createMatchNotificationUser('Alex');
    $user2 = createMatchNotificationUser('Sam');

    $this->mock(FcmService::class)->shouldNotReceive('sendToDevices');

    app()->call([new SendMatchNotification($user1, $user2, (string) Str::uuid()), 'handle']);
});
