<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChatService
{
    /**
     * Relaciones necesarias para pintar `ConversationResource` (perfil
     * básico del otro usuario + último mensaje) sin N+1.
     */
    private const CONVERSATION_RELATIONS = [
        'user1.profile.photos',
        'user1.profile.pronouns',
        'user2.profile.photos',
        'user2.profile.pronouns',
        'latestMessage',
    ];

    /**
     * Retorna la bandeja separada en dos grupos:
     * - "matches": toda conversación con `status: active` (incluye tanto
     *   `type: match` como una solicitud ya aceptada — domain.md solo exige
     *   `match_id` cuando `type: match`, así que una solicitud aceptada se
     *   queda con `type: request` pero pasa a verse en el tab Matches, tal
     *   como pide spec.md → "Al aceptar → se convierte en conversación en
     *   el tab Matches" sin inventar un `match_id` que no existe).
     * - "requests": `type: request` con `status: pending` — solicitudes
     *   aún sin resolver, enviadas o recibidas.
     *
     * Conversaciones `rejected`/`blocked` no aparecen en ninguno de los dos
     * grupos (domain.md: "ninguno de los dos ve la conversación").
     */
    public function getConversations(User $user): array
    {
        $base = Conversation::forUser($user)
            ->with(self::CONVERSATION_RELATIONS)
            ->withCount(['messages as unread_count' => function ($query) use ($user) {
                $query->whereNull('read_at')->where('sender_id', '!=', $user->id);
            }]);

        $matches = (clone $base)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->get();

        $requests = (clone $base)
            ->where('type', 'request')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return ['matches' => $matches, 'requests' => $requests];
    }

    public function getConversation(User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::with(self::CONVERSATION_RELATIONS)->findOrFail($conversationId);
        $this->assertParticipant($conversation, $user);

        return $conversation;
    }

    /**
     * Busca una conversación existente (cualquier type/status) entre el
     * usuario autenticado y otro usuario. Usado por
     * GET /api/chat/conversations/with/{userUuid} — el frontend decide si
     * abre la conversación o el modal de solicitud según si existe o no.
     */
    public function findConversationWithUser(User $user, string $otherUserId): ?Conversation
    {
        [$id1, $id2] = $this->sortIds($user->id, $otherUserId);

        return Conversation::where('user_id_1', $id1)
            ->where('user_id_2', $id2)
            ->with(self::CONVERSATION_RELATIONS)
            ->first();
    }

    /**
     * Historial paginado, orden cronológico inverso (más reciente primero).
     */
    public function getMessages(User $user, string $conversationId, int $page = 1): LengthAwarePaginator
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->assertParticipant($conversation, $user);

        return Message::where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'page', $page);
    }

    public function sendMessage(User $user, string $conversationId, string $content): Message
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->assertParticipant($conversation, $user);
        $this->assertCanSend($user, $conversation);

        return DB::transaction(function () use ($user, $conversation, $content) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'content' => $content,
            ]);

            $conversation->touch();

            event(new MessageSent($message, $conversation));

            return $message;
        });
    }

    public function markAsRead(User $user, string $conversationId): int
    {
        $conversation = Conversation::findOrFail($conversationId);
        $this->assertParticipant($conversation, $user);

        return Message::where('conversation_id', $conversation->id)
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->update(['read_at' => now()]);
    }

    /**
     * Envía la primera solicitud de mensaje a un usuario sin conversación
     * previa. Falla si ya existe una conversación (de cualquier type/status)
     * entre ambos — domain.md/UNIQUE(user_id_1, user_id_2).
     */
    public function sendRequest(User $user, string $receiverId, string $content): Conversation
    {
        if ($user->id === $receiverId) {
            throw new BusinessException('No puedes enviarte una solicitud a ti mismo.');
        }

        if ($user->status !== 'active') {
            throw new AuthorizationException('No tienes permiso para realizar esta acción.');
        }

        [$id1, $id2] = $this->sortIds($user->id, $receiverId);

        if (Conversation::where('user_id_1', $id1)->where('user_id_2', $id2)->exists()) {
            throw new BusinessException('Ya existe una conversación con este usuario.');
        }

        return DB::transaction(function () use ($id1, $id2, $user, $content) {
            $conversation = Conversation::create([
                'user_id_1' => $id1,
                'user_id_2' => $id2,
                'type' => 'request',
                'status' => 'pending',
            ]);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'content' => $content,
            ]);

            event(new MessageSent($message, $conversation));

            return $conversation->fresh(self::CONVERSATION_RELATIONS);
        });
    }

    /**
     * Solo el receptor de la solicitud (no quien la envió) puede aceptarla.
     */
    public function acceptRequest(User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::where('type', 'request')->findOrFail($conversationId);
        $this->assertParticipant($conversation, $user);

        if ($conversation->status !== 'pending') {
            throw new BusinessException('Esta solicitud ya fue procesada.');
        }

        $this->assertIsReceiver($conversation, $user);

        $conversation->update(['status' => 'active']);

        return $conversation->fresh(self::CONVERSATION_RELATIONS);
    }

    public function rejectRequest(User $user, string $conversationId): Conversation
    {
        $conversation = Conversation::where('type', 'request')->findOrFail($conversationId);
        $this->assertParticipant($conversation, $user);

        if ($conversation->status !== 'pending') {
            throw new BusinessException('Esta solicitud ya fue procesada.');
        }

        // domain.md: los mensajes de una solicitud rechazada NO se borran
        // físicamente — solo cambia el status de la conversación, que deja
        // de listarse para ambos (ver getConversations()).
        $conversation->update(['status' => 'rejected']);

        return $conversation->fresh(self::CONVERSATION_RELATIONS);
    }

    private function assertParticipant(Conversation $conversation, User $user): void
    {
        if ($conversation->user_id_1 !== $user->id && $conversation->user_id_2 !== $user->id) {
            throw new AuthorizationException('No tienes permiso para realizar esta acción.');
        }
    }

    private function assertIsReceiver(Conversation $conversation, User $user): void
    {
        $firstMessage = $conversation->messages()->oldest('created_at')->first();

        if ($firstMessage && $firstMessage->sender_id === $user->id) {
            throw new AuthorizationException('No puedes aceptar tu propia solicitud.');
        }
    }

    /**
     * domain.md: `status: suspended` → "no puede enviar mensajes";
     * `status: blocked` en la conversación → SafetyService la marca así al
     * bloquear (ninguno de los dos puede seguir chateando); `type: request`
     * con `status: pending` solo permite el primer mensaje hasta que sea
     * aceptada.
     */
    private function assertCanSend(User $user, Conversation $conversation): void
    {
        if ($user->status !== 'active') {
            throw new AuthorizationException('No tienes permiso para realizar esta acción.');
        }

        if ($conversation->status === 'blocked') {
            throw new BusinessException('No puedes enviar mensajes en esta conversación.');
        }

        if ($conversation->status === 'rejected') {
            throw new BusinessException('Esta solicitud fue rechazada.');
        }

        if ($conversation->type === 'request' && $conversation->status === 'pending' && $conversation->messages()->exists()) {
            throw new BusinessException('Ya enviaste tu mensaje. Espera a que la solicitud sea aceptada para continuar la conversación.');
        }
    }

    /** @return array{0: string, 1: string} */
    private function sortIds(string $a, string $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}
