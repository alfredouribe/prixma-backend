<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Chat\ConversationController;
use App\Http\Controllers\Api\Chat\MessageController;
use App\Http\Controllers\Api\Matching\MatchingController;
use App\Http\Controllers\Api\Onboarding\OnboardingController;
use App\Http\Controllers\Api\Profile\ProfileController;
use App\Http\Controllers\Api\Safety\SafetyController;
use App\Http\Controllers\Api\Verification\VerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Autorización de canales privados/presence de Reverb (laravel-echo) para
    // clientes Sanctum stateless. No usar Broadcast::routes() — esa registra
    // la ruta bajo el guard 'web'/sesión por defecto, no el guard 'sanctum'
    // que necesita la API móvil. routes/channels.php define las reglas de
    // autorización por canal (ej. conversation.{id}); no se tocan aquí.
    //
    // Nota: Broadcast::auth() resuelve el usuario vía $request->user() sin
    // especificar guard, pero eso sí funciona bajo auth:sanctum — el
    // middleware Authenticate llama a Auth::shouldUse('sanctum') tras
    // autenticar, dejando 'sanctum' como guard efectivo por default para el
    // resto del request.
    Route::post('/broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    });

    Route::prefix('onboarding')->group(function () {
        Route::get('/catalogs', [OnboardingController::class, 'catalogs']);
        Route::get('/status', [OnboardingController::class, 'status']);
        Route::post('/step/identity', [OnboardingController::class, 'stepIdentity']);
        Route::post('/step/pronouns', [OnboardingController::class, 'stepPronouns']);
        Route::post('/step/intention', [OnboardingController::class, 'stepIntention']);
        Route::post('/step/interests', [OnboardingController::class, 'stepInterests']);
        Route::post('/step/video', [OnboardingController::class, 'stepVideo']);
        Route::post('/step/safety', [OnboardingController::class, 'stepSafety']);
        Route::post('/video/upload', [OnboardingController::class, 'uploadVideo']);
        Route::post('/video/presigned-url', [OnboardingController::class, 'videoPresignedUrl']);
    });

    Route::prefix('profiles')->group(function () {
        Route::get('/me', [ProfileController::class, 'me']);
        Route::put('/me', [ProfileController::class, 'update']);
        Route::get('/me/settings', [ProfileController::class, 'settings']);
        Route::patch('/me/settings', [ProfileController::class, 'updateSettings']);
        Route::get('/{uuid}', [ProfileController::class, 'show']);

        Route::post('/me/photos', [ProfileController::class, 'storePhoto']);
        Route::delete('/me/photos/{uuid}', [ProfileController::class, 'destroyPhoto']);
        Route::patch('/me/photos/reorder', [ProfileController::class, 'reorderPhotos']);

        Route::post('/me/video', [ProfileController::class, 'storeVideo']);
        Route::delete('/me/video', [ProfileController::class, 'destroyVideo']);
    });

    Route::prefix('verification')->group(function () {
        Route::get('/status', [VerificationController::class, 'status']);
        Route::post('/', [VerificationController::class, 'submit']);
    });

    Route::prefix('matching')->group(function () {
        Route::get('/explore', [MatchingController::class, 'explore']);
        Route::post('/swipe', [MatchingController::class, 'swipe']);
        Route::get('/matches', [MatchingController::class, 'matches']);
        Route::get('/preferences', [MatchingController::class, 'getPreferences']);
        Route::put('/preferences', [MatchingController::class, 'updatePreferences']);
    });

    Route::prefix('chat')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/with/{userUuid}', [ConversationController::class, 'withUser']);
        Route::get('/conversations/{uuid}', [ConversationController::class, 'show']);
        Route::get('/conversations/{uuid}/messages', [MessageController::class, 'index']);
        Route::post('/conversations/{uuid}/messages', [MessageController::class, 'store']);
        Route::post('/conversations/{uuid}/read', [ConversationController::class, 'markAsRead']);
        Route::post('/requests', [ConversationController::class, 'storeRequest']);
        Route::patch('/requests/{uuid}/accept', [ConversationController::class, 'acceptRequest']);
        Route::patch('/requests/{uuid}/reject', [ConversationController::class, 'rejectRequest']);
    });

    Route::prefix('safety')->group(function () {
        Route::post('/reports', [SafetyController::class, 'storeReport']);
        Route::post('/blocks', [SafetyController::class, 'storeBlock']);
        Route::get('/blocks', [SafetyController::class, 'indexBlocks']);
        Route::delete('/blocks/{uuid}', [SafetyController::class, 'destroyBlock']);
        Route::get('/geo-blocks', [SafetyController::class, 'indexGeoBlocks']);
        Route::post('/geo-blocks', [SafetyController::class, 'storeGeoBlock']);
        Route::delete('/geo-blocks/{uuid}', [SafetyController::class, 'destroyGeoBlock']);
    });
});
