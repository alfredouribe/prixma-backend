<?php

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Event;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function createChatUser(array $profileData = []): array
{
    $user = User::factory()->withCompletedOnboarding()->create();

    $profile = Profile::create(array_merge([
        'user_id' => $user->id,
        'display_name' => fake()->name(),
        'city' => 'CDMX',
        'intention' => 'friendship',
        'onboarding_step' => 6,
        'onboarding_completed' => true,
    ], $profileData));

    $token = $user->createToken('mobile')->plainTextToken;

    return compact('user', 'profile', 'token');
}

function sortedIds(string $a, string $b): array
{
    return $a < $b ? [$a, $b] : [$b, $a];
}

beforeEach(function () {
    ['user' => $this->user, 'profile' => $this->profile, 'token' => $this->token] = createChatUser();
});

// ---------------------------------------------------------------------------
// GET /api/chat/conversations
// ---------------------------------------------------------------------------

describe('GET /api/chat/conversations', function () {
    it('separa las conversaciones en matches y requests', function () {
        ['user' => $matchUser] = createChatUser();
        ['user' => $requesterUser] = createChatUser();

        [$id1, $id2] = sortedIds($this->user->id, $matchUser->id);
        $matchConversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        [$rid1, $rid2] = sortedIds($this->user->id, $requesterUser->id);
        $requestConversation = Conversation::create([
            'user_id_1' => $rid1,
            'user_id_2' => $rid2,
            'type' => 'request',
            'status' => 'pending',
        ]);
        Message::create([
            'conversation_id' => $requestConversation->id,
            'sender_id' => $requesterUser->id,
            'content' => 'Hola, quiero platicar contigo.',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/chat/conversations')
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data.matches')
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.matches.0.id', (string) $matchConversation->id)
            ->assertJsonPath('data.requests.0.id', (string) $requestConversation->id)
            ->assertJsonPath('data.requests.0.last_message.content', 'Hola, quiero platicar contigo.');
    });

    it('no incluye conversaciones rechazadas ni bloqueadas', function () {
        ['user' => $otherUser] = createChatUser();

        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'request',
            'status' => 'rejected',
        ]);

        $this->withToken($this->token)
            ->getJson('/api/chat/conversations')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.matches')
            ->assertJsonCount(0, 'data.requests');
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/chat/conversations')->assertStatus(401);
    });
});

// ---------------------------------------------------------------------------
// GET /api/chat/conversations/{id}
// ---------------------------------------------------------------------------

describe('GET /api/chat/conversations/{id}', function () {
    it('un participante puede ver la conversación', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', (string) $conversation->id);
    });

    it('retorna 403 si el usuario no participa en la conversación', function () {
        ['user' => $otherUser] = createChatUser();
        ['user' => $thirdUser] = createChatUser();
        [$id1, $id2] = sortedIds($otherUser->id, $thirdUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/chat/conversations/{$conversation->id}")
            ->assertStatus(403);
    });

    it('retorna 404 si la conversación no existe', function () {
        $this->withToken($this->token)
            ->getJson('/api/chat/conversations/' . \Illuminate\Support\Str::uuid())
            ->assertStatus(404);
    });
});

// ---------------------------------------------------------------------------
// GET /api/chat/conversations/with/{userUuid}
// ---------------------------------------------------------------------------

describe('GET /api/chat/conversations/with/{userUuid}', function () {
    it('retorna la conversación existente entre ambos usuarios', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/chat/conversations/with/{$otherUser->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', (string) $conversation->id);
    });

    it('retorna 404 con mensaje canónico si no existe conversación', function () {
        ['user' => $otherUser] = createChatUser();

        $this->withToken($this->token)
            ->getJson("/api/chat/conversations/with/{$otherUser->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    });
});

// ---------------------------------------------------------------------------
// GET /api/chat/conversations/{id}/messages
// ---------------------------------------------------------------------------

describe('GET /api/chat/conversations/{id}/messages', function () {
    it('retorna los mensajes paginados en orden cronológico inverso', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $first = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'content' => 'Primero',
        ]);
        $first->created_at = now()->subMinutes(10);
        $first->save();

        $second = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $otherUser->id,
            'content' => 'Segundo',
        ]);
        $second->created_at = now()->subMinutes(5);
        $second->save();

        $third = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'content' => 'Tercero',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertStatus(200);

        $response->assertJsonPath('data.0.content', 'Tercero')
            ->assertJsonPath('data.1.content', 'Segundo')
            ->assertJsonPath('data.2.content', 'Primero');
    });

    it('retorna 403 si el usuario no participa en la conversación', function () {
        ['user' => $otherUser] = createChatUser();
        ['user' => $thirdUser] = createChatUser();
        [$id1, $id2] = sortedIds($otherUser->id, $thirdUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertStatus(403);
    });
});

// ---------------------------------------------------------------------------
// POST /api/chat/conversations/{id}/messages
// ---------------------------------------------------------------------------

describe('POST /api/chat/conversations/{id}/messages', function () {
    it('envía un mensaje y dispara el evento MessageSent', function () {
        Event::fake([MessageSent::class]);

        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['content' => 'Hola!'])
            ->assertStatus(201)
            ->assertJsonPath('data.content', 'Hola!')
            ->assertJsonPath('data.sender_id', (string) $this->user->id);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'content' => 'Hola!',
        ]);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation) {
            return (string) $event->conversation->id === (string) $conversation->id;
        });
    });

    it('solo un participante puede enviar mensajes', function () {
        ['user' => $otherUser] = createChatUser();
        ['user' => $thirdUser] = createChatUser();
        [$id1, $id2] = sortedIds($otherUser->id, $thirdUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['content' => 'Hola!'])
            ->assertStatus(403);
    });

    it('422 con contenido vacío', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['content' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    });

    it('422 con contenido mayor a 500 caracteres', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['content' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    });

    it('400 si la conversación está bloqueada', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'blocked',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['content' => 'Hola!'])
            ->assertStatus(400);

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    });

    it('400 al intentar enviar un segundo mensaje en una solicitud pendiente', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'request',
            'status' => 'pending',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'content' => 'Primer mensaje',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['content' => 'Segundo mensaje'])
            ->assertStatus(400);

        $this->assertDatabaseCount('messages', 1);
    });
});

// ---------------------------------------------------------------------------
// POST /api/chat/conversations/{id}/read
// ---------------------------------------------------------------------------

describe('POST /api/chat/conversations/{id}/read', function () {
    it('marca como leídos los mensajes no leídos del otro usuario', function () {
        ['user' => $otherUser] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $otherUser->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $fromOther = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $otherUser->id,
            'content' => 'Mensaje del otro',
        ]);
        $ownMessage = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'content' => 'Mi propio mensaje',
        ]);

        $this->withToken($this->token)
            ->postJson("/api/chat/conversations/{$conversation->id}/read")
            ->assertStatus(200);

        expect($fromOther->fresh()->read_at)->not->toBeNull();
        expect($ownMessage->fresh()->read_at)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// POST /api/chat/requests
// ---------------------------------------------------------------------------

describe('POST /api/chat/requests', function () {
    it('crea una solicitud con el primer mensaje', function () {
        Event::fake([MessageSent::class]);
        ['user' => $receiver] = createChatUser();

        $response = $this->withToken($this->token)
            ->postJson('/api/chat/requests', [
                'receiver_id' => $receiver->id,
                'content' => 'Hola, me encantaría platicar.',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.type', 'request')
            ->assertJsonPath('data.status', 'pending');

        [$id1, $id2] = sortedIds($this->user->id, $receiver->id);
        $this->assertDatabaseHas('conversations', [
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'request',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->user->id,
            'content' => 'Hola, me encantaría platicar.',
        ]);

        Event::assertDispatched(MessageSent::class);
    });

    it('falla con 400 si ya existe una conversación entre ambos', function () {
        ['user' => $receiver] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $receiver->id);
        Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'match',
            'status' => 'active',
        ]);

        $this->withToken($this->token)
            ->postJson('/api/chat/requests', [
                'receiver_id' => $receiver->id,
                'content' => 'Hola de nuevo',
            ])
            ->assertStatus(400);
    });

    it('422 si receiver_id es el propio usuario', function () {
        $this->withToken($this->token)
            ->postJson('/api/chat/requests', [
                'receiver_id' => $this->user->id,
                'content' => 'Hola',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_id']);
    });

    it('requiere autenticación', function () {
        ['user' => $receiver] = createChatUser();

        $this->postJson('/api/chat/requests', [
            'receiver_id' => $receiver->id,
            'content' => 'Hola',
        ])->assertStatus(401);
    });
});

// ---------------------------------------------------------------------------
// PATCH /api/chat/requests/{id}/accept
// ---------------------------------------------------------------------------

describe('PATCH /api/chat/requests/{id}/accept', function () {
    it('el receptor puede aceptar la solicitud', function () {
        ['user' => $sender] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $sender->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'request',
            'status' => 'pending',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'content' => 'Hola',
        ]);

        $this->withToken($this->token)
            ->patchJson("/api/chat/requests/{$conversation->id}/accept")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'status' => 'active']);
    });

    it('quien envió la solicitud no puede aceptarla a sí mismo', function () {
        ['user' => $receiver] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $receiver->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'request',
            'status' => 'pending',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'content' => 'Hola',
        ]);

        $this->withToken($this->token)
            ->patchJson("/api/chat/requests/{$conversation->id}/accept")
            ->assertStatus(403);
    });
});

// ---------------------------------------------------------------------------
// PATCH /api/chat/requests/{id}/reject
// ---------------------------------------------------------------------------

describe('PATCH /api/chat/requests/{id}/reject', function () {
    it('rechaza la solicitud y deja de verse para ambos', function () {
        ['user' => $sender, 'token' => $senderToken] = createChatUser();
        [$id1, $id2] = sortedIds($this->user->id, $sender->id);
        $conversation = Conversation::create([
            'user_id_1' => $id1,
            'user_id_2' => $id2,
            'type' => 'request',
            'status' => 'pending',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'content' => 'Hola',
        ]);

        $this->withToken($this->token)
            ->patchJson("/api/chat/requests/{$conversation->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'status' => 'rejected']);
        // Los mensajes no se eliminan físicamente (auditoría)
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id]);

        $this->withToken($this->token)
            ->getJson('/api/chat/conversations')
            ->assertJsonCount(0, 'data.requests');

        $this->withToken($senderToken)
            ->getJson('/api/chat/conversations')
            ->assertJsonCount(0, 'data.requests');
    });
});
