<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendRequestRequest;
use App\Http\Resources\ConversationResource;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private readonly ChatService $chatService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $conversations = $this->chatService->getConversations($request->user());

        return response()->json([
            'data' => [
                'matches' => ConversationResource::collection($conversations['matches']),
                'requests' => ConversationResource::collection($conversations['requests']),
            ],
        ]);
    }

    public function show(Request $request, string $uuid): ConversationResource
    {
        $conversation = $this->chatService->getConversation($request->user(), $uuid);

        return new ConversationResource($conversation);
    }

    public function withUser(Request $request, string $userUuid): JsonResponse
    {
        $conversation = $this->chatService->findConversationWithUser($request->user(), $userUuid);

        if (!$conversation) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        return response()->json(['data' => new ConversationResource($conversation)]);
    }

    public function markAsRead(Request $request, string $uuid): JsonResponse
    {
        $this->chatService->markAsRead($request->user(), $uuid);

        return response()->json(['message' => 'Mensajes marcados como leídos.']);
    }

    public function storeRequest(SendRequestRequest $request): JsonResponse
    {
        $conversation = $this->chatService->sendRequest(
            $request->user(),
            $request->input('receiver_id'),
            $request->input('content')
        );

        return response()->json([
            'data' => new ConversationResource($conversation),
            'message' => 'Solicitud enviada.',
        ], 201);
    }

    public function acceptRequest(Request $request, string $uuid): ConversationResource
    {
        $conversation = $this->chatService->acceptRequest($request->user(), $uuid);

        return new ConversationResource($conversation);
    }

    public function rejectRequest(Request $request, string $uuid): ConversationResource
    {
        $conversation = $this->chatService->rejectRequest($request->user(), $uuid);

        return new ConversationResource($conversation);
    }
}
