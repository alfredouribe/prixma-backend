<?php

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfilePhoto;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// App\Events\MessageSent — transmite tanto al canal de la conversación
// (hilo abierto) como al canal privado del destinatario (toast in-app
// global, ver features/chat/specs/plan.md → "Toast in-app de mensaje nuevo").
// ---------------------------------------------------------------------------

function createConversationParticipant(string $displayName): User
{
    $user = User::factory()->withCompletedOnboarding()->create();

    Profile::create([
        'user_id' => $user->id,
        'display_name' => $displayName,
        'city' => 'CDMX',
        'intention' => 'friendship',
        'onboarding_step' => 6,
        'onboarding_completed' => true,
    ]);

    return $user;
}

it('transmite al canal de la conversación y al canal privado del destinatario, nunca al del emisor', function () {
    $sender = createConversationParticipant('Alex');
    $recipient = createConversationParticipant('Sam');

    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'content' => 'Hola, ¿cómo estás?',
    ]);

    $event = new MessageSent($message->fresh(), $conversation);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(2);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe("private-conversation.{$conversation->id}");
    expect($channels[1]->name)->toBe("private-App.Models.User.{$recipient->id}");
});

it('incluye conversation_id, nombre del emisor y preview truncado en el payload', function () {
    $sender = createConversationParticipant('Jordan');
    $recipient = createConversationParticipant('Casey');

    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $longContent = str_repeat('a', 80);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'content' => $longContent,
    ]);

    $payload = (new MessageSent($message->fresh(), $conversation))->broadcastWith();

    expect($payload['conversation_id'])->toBe($conversation->id);
    expect($payload['sender_name'])->toBe('Jordan');
    expect($payload['preview'])->toBe(Str::limit($longContent, 60));
    expect(mb_strlen($payload['preview']))->toBeLessThanOrEqual(63);
});

it('incluye la foto del emisor cuando tiene una', function () {
    $sender = createConversationParticipant('Robin');
    $recipient = createConversationParticipant('Morgan');

    ProfilePhoto::create([
        'profile_id' => $sender->profile->id,
        'url' => 'https://cdn.example.com/robin.jpg',
        'key' => 'photos/robin.jpg',
        'position' => 0,
    ]);

    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
    ]);

    $payload = (new MessageSent($message->fresh(), $conversation))->broadcastWith();

    expect($payload['sender_photo'])->toBe('https://cdn.example.com/robin.jpg');
});

it('sender_photo es null cuando el emisor no tiene fotos', function () {
    $sender = createConversationParticipant('Taylor');
    $recipient = createConversationParticipant('Drew');

    $conversation = Conversation::factory()->betweenUsers($sender, $recipient)->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
    ]);

    $payload = (new MessageSent($message->fresh(), $conversation))->broadcastWith();

    expect($payload['sender_photo'])->toBeNull();
});
