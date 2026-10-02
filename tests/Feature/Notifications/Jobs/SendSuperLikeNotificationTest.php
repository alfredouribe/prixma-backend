<?php

use App\Jobs\SendSuperLikeNotification;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('crea notificación in-app sin revelar quién dio el super like', function () {
    $recipient = User::factory()->withCompletedOnboarding()->create();

    app()->call([new SendSuperLikeNotification($recipient), 'handle']);

    $notification = Notification::where('user_id', $recipient->id)->first();
    expect($notification->type)->toBe('super_like');
    expect($notification->title)->toBe('A alguien le encantaste ⭐');
    expect($notification->body)->toBe('Explora para descubrir quién.');
});

test('marca sent_at cuando ExpoPushService confirma el envío', function () {
    $recipient = User::factory()->withCompletedOnboarding()->create();
    DeviceToken::factory()->for($recipient)->create();

    $this->mock(ExpoPushService::class)->shouldReceive('sendToDevices')->once()->andReturn(true);

    app()->call([new SendSuperLikeNotification($recipient), 'handle']);

    expect(Notification::where('user_id', $recipient->id)->first()->sent_at)->not->toBeNull();
});
