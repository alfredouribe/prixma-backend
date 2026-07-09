<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Matching\MatchingController;
use App\Http\Controllers\Api\Onboarding\OnboardingController;
use App\Http\Controllers\Api\Profile\ProfileController;
use App\Http\Controllers\Api\Verification\VerificationController;
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
        Route::get('/{uuid}', [ProfileController::class, 'show']);

        Route::post('/me/photos', [ProfileController::class, 'storePhoto']);
        Route::delete('/me/photos/{uuid}', [ProfileController::class, 'destroyPhoto']);
        Route::patch('/me/photos/reorder', [ProfileController::class, 'reorderPhotos']);

        Route::post('/me/video/presigned-url', [ProfileController::class, 'videoPresignedUrl']);
        Route::post('/me/video', [ProfileController::class, 'storeVideo']);
        Route::delete('/me/video', [ProfileController::class, 'destroyVideo']);
    });

    Route::prefix('verification')->group(function () {
        Route::get('/status', [VerificationController::class, 'status']);
        Route::post('/presigned-url', [VerificationController::class, 'presignedUrl']);
        Route::post('/', [VerificationController::class, 'submit']);
    });

    Route::prefix('matching')->group(function () {
        Route::get('/explore', [MatchingController::class, 'explore']);
        Route::post('/swipe', [MatchingController::class, 'swipe']);
        Route::get('/matches', [MatchingController::class, 'matches']);
        Route::get('/preferences', [MatchingController::class, 'getPreferences']);
        Route::put('/preferences', [MatchingController::class, 'updatePreferences']);
    });
});
