<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SsoController;
use App\Http\Middleware\EnsureProductAccess;

// -----------------------------------------------------------------------------
// Skillmine SSO Authentication Routes (StarOne Unified Entry)
// -----------------------------------------------------------------------------
Route::prefix('v1/auth')->group(function () {
    // 1. Get Skillmine Login / Authorization URL
    Route::get('/skillmine/login-url', [SsoController::class, 'getSkillmineLoginUrl']);

    // 2. Skillmine OAuth2 Callback (Supports both GET redirect from browser and POST from mobile app)
    Route::match(['get', 'post'], '/callback', [SsoController::class, 'handleSkillmineCallback']);

    // 3. Logout
    Route::post('/logout', [SsoController::class, 'logout']);
});

// -----------------------------------------------------------------------------
// SSO Core & Legacy Product Launch Routes
// -----------------------------------------------------------------------------
Route::prefix('v1/sso')->group(function () {
    // Unauthenticated Routes (Kept for local standalone testing / fallback)
    Route::post('/send-otp', [SsoController::class, 'sendOtp']);
    Route::post('/verify-otp', [SsoController::class, 'verifyOtp']);

    // Authenticated SSO Launch Route
    Route::middleware(['auth:api', EnsureProductAccess::class])->group(function () {
        Route::post('/launch/{productKey}', [SsoController::class, 'launchProduct']);
    });
});