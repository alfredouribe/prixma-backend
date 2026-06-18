<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Onboarding\OnboardingController;
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
        Route::post('/video/presigned-url', [OnboardingController::class, 'videoPresignedUrl']);
    });
});
