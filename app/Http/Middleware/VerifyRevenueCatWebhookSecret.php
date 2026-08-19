<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica el header `Authorization: Bearer {REVENUECAT_WEBHOOK_SECRET}`
 * antes de que el controller toque el payload — ver
 * features/subscriptions/specs/plan.md → "Webhook de RevenueCat". Si el
 * secreto no está configurado todavía (`.env` en placeholder, sin cuenta
 * real de RevenueCat aún) cualquier request se rechaza — nunca se abre el
 * webhook sin secreto configurado.
 */
class VerifyRevenueCatWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.revenuecat.webhook_secret');
        $provided = $request->bearerToken();

        if (!$expected || !hash_equals($expected, (string) $provided)) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        return $next($request);
    }
}
