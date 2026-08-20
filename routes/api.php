<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SsoController;
use App\Http\Middleware\EnsureProductAccess;

Route::prefix('v1/sso')->group(function () {
    // Unauthenticated Routes
    Route::post('/send-otp', [SsoController::class, 'sendOtp']);
    Route::post('/verify-otp', [SsoController::class, 'verifyOtp']);

    // Authenticated SSO Launch Route
    Route::middleware(['auth:api', EnsureProductAccess::class])->group(function () {
        Route::post('/launch/{productKey}', [SsoController::class, 'launchProduct']);
    });
});