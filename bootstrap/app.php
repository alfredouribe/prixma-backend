<?php

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'onboarding.completed' => \App\Http\Middleware\EnsureOnboardingCompleted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (BusinessException $e): JsonResponse {
            return response()->json(['message' => $e->getMessage()], 400);
        });

        $exceptions->render(function (AuthorizationException $e): JsonResponse {
            return response()->json(['message' => $e->getMessage() ?: 'No tienes permiso para realizar esta acción.'], 403);
        });

        $exceptions->render(function (UnauthorizedException $e): JsonResponse {
            return response()->json(['message' => $e->getMessage()], 401);
        });

        $exceptions->render(function (ThrottleRequestsException $e): JsonResponse {
            return response()->json(['message' => 'Demasiados intentos. Espera un momento e intenta de nuevo.'], 429);
        });

        // Solo la API móvil (`/api/*` o requests que explícitamente esperan JSON)
        // responde 401 en JSON. El panel `/admin` (guard `session`) debe seguir
        // el comportamiento default de Laravel/Filament: redirigir al login.
        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }

            return null;
        });

        $exceptions->render(function (ValidationException $e): JsonResponse {
            return response()->json([
                'message' => 'Los datos enviados no son válidos.',
                'errors' => $e->errors(),
            ], 422);
        });

    })->create();
