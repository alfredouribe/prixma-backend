<?php

use App\Jobs\SendMatchNotification;
use App\Jobs\SendMessageNotification;
use App\Jobs\SendSuperLikeNotification;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Igual que PresenceServiceTest: este archivo vive en Unit/ pero necesita la
// app booteada (Queue::fake(), RefreshDatabase, mocks de container) — solo
// Feature/ obtiene Tests\TestCase automáticamente vía tests/Pest.php.
uses(Tests\TestCase::class, RefreshDatabase::class);

test('sendMatchNotification despacha el Job a la cola notifications', function () {
    Queue::fake();

    $user1 = User::factory()->withCompletedOnboarding()->create();
    $user2 = User::factory()->withCompletedOnboarding()->create();
    $conversationId = (string) Str::uuid();

    app(NotificationService::class)->sendMatchNotification($user1, $user2, $conversationId);

    Queue::assertPushed(SendMatchNotification::class, fn ($job) => $job->queue === 'notifications');
});

test('sendSuperLikeNotification despacha el Job', function () {
    Queue::fake();

    $recipient = User::factory()->withCompletedOnboarding()->create();

    app(NotificationService::class)->sendSuperLikeNotification($recipient);

    Queue::assertPushed(SendSuperLikeNotification::class);
});

test('sendMessageNotification despacha el Job cuando el receptor está offline', function () {
    Queue::fake();

    $sender = User::factory()->withCompletedOnboarding()->create();
    $recipient = User::factory()->withCompletedOnboarding()->create();
    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->for($conversation)->create(['sender_id' => $sender->id]);

    // Sin ->with() — comparar el id contra el objeto lazy UUID de Eloquent
    // (Ramsey\Uuid\Lazy\LazyUuidFromString) es frágil, ya visto en
    // SendMessageNotificationTest; solo hay una llamada en este test.
    $this->mock(PresenceService::class)->shouldReceive('isUserOnline')->once()->andReturn(false);

    app(NotificationService::class)->sendMessageNotification($recipient, $sender, $message);

    Queue::assertPushed(SendMessageNotification::class);
});

test('sendMessageNotification NO despacha el Job cuando el receptor está en línea', function () {
    Queue::fake();

    $sender = User::factory()->withCompletedOnboarding()->create();
    $recipient = User::factory()->withCompletedOnboarding()->create();
    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->for($conversation)->create(['sender_id' => $sender->id]);

    $this->mock(PresenceService::class)->shouldReceive('isUserOnline')->once()->andReturn(true);

    app(NotificationService::class)->sendMessageNotification($recipient, $sender, $message);

    Queue::assertNotPushed(SendMessageNotification::class);
});

test('registerDeviceToken crea el token', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    app(NotificationService::class)->registerDeviceToken($user, 'token-abc', 'ios');

    $this->assertDatabaseHas('device_tokens', [
        'user_id' => $user->id,
        'token' => 'token-abc',
        'platform' => 'ios',
    ]);
});

test('registerDeviceToken no duplica el mismo (user_id, token) — upsert', function () {
    $user = User::factory()->withCompletedOnboarding()->create();

    app(NotificationService::class)->registerDeviceToken($user, 'token-abc', 'ios');
    app(NotificationService::class)->registerDeviceToken($user, 'token-abc', 'android');

    expect(DeviceToken::where('user_id', $user->id)->count())->toBe(1);
    $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'token' => 'token-abc', 'platform' => 'android']);
});

test('removeDeviceToken elimina todos los tokens del usuario', function () {
    $user = User::factory()->withCompletedOnboarding()->create();
    DeviceToken::factory()->for($user)->count(2)->create();
    $other = User::factory()->withCompletedOnboarding()->create();
    DeviceToken::factory()->for($other)->create();

    app(NotificationService::class)->removeDeviceToken($user);

    expect(DeviceToken::where('user_id', $user->id)->count())->toBe(0);
    expect(DeviceToken::where('user_id', $other->id)->count())->toBe(1);
});
