<?php

use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// POST /api/broadcasting/auth
//
// Autoriza la suscripción de laravel-echo/pusher-js a canales privados de
// Reverb (ver routes/channels.php → canal `conversation.{conversationId}`).
//
// `phpunit.xml` configura BROADCAST_CONNECTION=reverb (con credenciales
// dummy, sin llamadas de red reales para auth — la firma se calcula vía
// HMAC local) igual que en producción (ver .env.example). Es necesario
// porque routes/channels.php se registra sobre el broadcaster que esté
// activo en el momento del boot de la aplicación: si el driver por default
// fuera `null` (su Broadcaster::auth() es un no-op que nunca aplica las
// reglas de routes/channels.php), estos tests no ejercerían la autorización
// real — pasarían aunque el gate estuviera roto.
// ---------------------------------------------------------------------------

function createBroadcastAuthUser(): array
{
    $user = User::factory()->withCompletedOnboarding()->create();

    Profile::create([
        'user_id' => $user->id,
        'display_name' => fake()->name(),
        'city' => 'CDMX',
        'intention' => 'friendship',
        'onboarding_step' => 6,
        'onboarding_completed' => true,
    ]);

    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'token');
}

function sortedConversationIds(string $a, string $b): array
{
    return $a < $b ? [$a, $b] : [$b, $a];
}

it('un participante de la conversación puede autorizar el canal privado', function () {
    ['user' => $user, 'token' => $token] = createBroadcastAuthUser();
    ['user' => $otherUser] = createBroadcastAuthUser();

    [$id1, $id2] = sortedConversationIds($user->id, $otherUser->id);
    $conversation = Conversation::create([
        'user_id_1' => $id1,
        'user_id_2' => $id2,
        'type' => 'match',
        'status' => 'active',
    ]);

    $response = $this->withToken($token)
        ->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-conversation.{$conversation->id}",
            'socket_id' => '123.456',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['auth']);
});

it('un usuario que no participa en la conversación recibe 403 al intentar autorizar el canal', function () {
    ['user' => $outsider, 'token' => $outsiderToken] = createBroadcastAuthUser();
    ['user' => $participantOne] = createBroadcastAuthUser();
    ['user' => $participantTwo] = createBroadcastAuthUser();

    [$id1, $id2] = sortedConversationIds($participantOne->id, $participantTwo->id);
    $conversation = Conversation::create([
        'user_id_1' => $id1,
        'user_id_2' => $id2,
        'type' => 'match',
        'status' => 'active',
    ]);

    $this->withToken($outsiderToken)
        ->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-conversation.{$conversation->id}",
            'socket_id' => '123.456',
        ])
        ->assertStatus(403);
});

it('requiere autenticación para autorizar cualquier canal', function () {
    ['user' => $participantOne] = createBroadcastAuthUser();
    ['user' => $participantTwo] = createBroadcastAuthUser();

    [$id1, $id2] = sortedConversationIds($participantOne->id, $participantTwo->id);
    $conversation = Conversation::create([
        'user_id_1' => $id1,
        'user_id_2' => $id2,
        'type' => 'match',
        'status' => 'active',
    ]);

    $this->postJson('/api/broadcasting/auth', [
        'channel_name' => "private-conversation.{$conversation->id}",
        'socket_id' => '123.456',
    ])->assertStatus(401);
});
