<?php

use App\Jobs\SendMessageNotification;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('crea notificación in-app con título del remitente y preview truncado a 60 caracteres', function () {
    $sender = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($sender)->create(['display_name' => 'Jordan']);
    $recipient = User::factory()->withCompletedOnboarding()->create();

    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->for($conversation)->create([
        'sender_id' => $sender->id,
        'content' => str_repeat('a', 100),
    ]);

    app()->call([new SendMessageNotification($recipient, $sender, $message), 'handle']);

    $notification = Notification::where('user_id', $recipient->id)->first();
    expect($notification->type)->toBe('message');
    expect($notification->title)->toBe('Jordan');
    expect($notification->body)->toBe(str_repeat('a', 60) . '...');
    expect($notification->data)->toBe(['conversation_id' => (string) $conversation->id]);
});

test('no trunca ni agrega puntos suspensivos cuando el mensaje ya es corto', function () {
    $sender = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($sender)->create(['display_name' => 'Jordan']);
    $recipient = User::factory()->withCompletedOnboarding()->create();
    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->for($conversation)->create([
        'sender_id' => $sender->id,
        'content' => 'Hola!',
    ]);

    app()->call([new SendMessageNotification($recipient, $sender, $message), 'handle']);

    expect(Notification::where('user_id', $recipient->id)->first()->body)->toBe('Hola!');
});

test('marca sent_at cuando ExpoPushService confirma el envío', function () {
    $sender = User::factory()->withCompletedOnboarding()->create();
    Profile::factory()->for($sender)->create(['display_name' => 'Jordan']);
    $recipient = User::factory()->withCompletedOnboarding()->create();
    DeviceToken::factory()->for($recipient)->create();
    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->for($conversation)->create(['sender_id' => $sender->id]);

    $this->mock(ExpoPushService::class)->shouldReceive('sendToDevices')->once()->andReturn(true);

    app()->call([new SendMessageNotification($recipient, $sender, $message), 'handle']);

    expect(Notification::where('user_id', $recipient->id)->first()->sent_at)->not->toBeNull();
});
