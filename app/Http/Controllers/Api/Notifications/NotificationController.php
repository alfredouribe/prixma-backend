<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\RegisterDeviceTokenRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $page = (int) $request->query('page', 1);

        return NotificationResource::collection(
            $this->notificationService->list($request->user(), $page)
        );
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json(['message' => 'Notificaciones marcadas como leídas.']);
    }

    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $notification = $this->notificationService->markAsRead($request->user(), $uuid);

        return response()->json(['data' => new NotificationResource($notification)]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->notificationService->unreadCount($request->user())]);
    }

    public function registerToken(RegisterDeviceTokenRequest $request): JsonResponse
    {
        $this->notificationService->registerDeviceToken(
            $request->user(),
            $request->input('token'),
            $request->input('platform'),
        );

        return response()->json(['message' => 'Token registrado.'], 201);
    }

    public function removeToken(Request $request): JsonResponse
    {
        $this->notificationService->removeDeviceToken($request->user());

        return response()->json(null, 204);
    }
}
