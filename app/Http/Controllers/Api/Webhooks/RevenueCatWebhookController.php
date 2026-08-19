<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recibe los eventos de suscripción de RevenueCat. La verificación del
 * secreto compartido vive en el middleware `VerifyRevenueCatWebhookSecret`
 * (aplicado en la ruta), no aquí — el controller solo delega. Ver
 * features/subscriptions/specs/plan.md.
 */
class RevenueCatWebhookController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptionService) {}

    public function handle(Request $request): JsonResponse
    {
        $this->subscriptionService->handleRevenueCatEvent($request->all());

        // Siempre 200 si el secreto fue válido — un tipo de evento no
        // reconocido o un app_user_id sin match no son errores del lado de
        // RevenueCat, no queremos que reintente indefinidamente por algo
        // que un 200 no va a resolver.
        return response()->json(['status' => 'ok']);
    }
}
