<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\MessageResource;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    public function __construct(private readonly ChatService $chatService)
    {
    }

    public function index(Request $request, string $uuid): AnonymousResourceCollection
    {
        $page = (int) $request->query('page', 1);
        $messages = $this->chatService->getMessages($request->user(), $uuid, $page);

        return MessageResource::collection($messages);
    }

    public function store(SendMessageRequest $request, string $uuid): JsonResponse
    {
        $message = $this->chatService->sendMessage($request->user(), $uuid, $request->input('content'));

        return response()->json([
            'data' => new MessageResource($message),
            'message' => 'Mensaje enviado.',
        ], 201);
    }
}
