<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\OtpService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected SmsService $smsService,
        protected AccessScopeService $accessScopeService
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate([
            'mobile_number' => 'required|string|regex:/^\+[1-9]\d{6,14}$/',
        ]);

        $user = User::where('mobile_number', $request->mobile_number)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not registered.',
                'error_code' => 'USER_NOT_FOUND',
                'data' => null
            ], 404);
        }

        if (!$user->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Account is deactivated. Please contact support.',
                'error_code' => 'ACCOUNT_INACTIVE',
                'data' => null
            ], 403);
        }

        $otpCode = $this->otpService->generateOtp($user->mobile_number);
        $this->smsService->sendOtp($user->mobile_number, $otpCode);

        return response()->json([
            'status' => 'success',
            'message' => 'OTP sent successfully.',
            'data' => [
                'mobile_number' => $user->mobile_number,
                'expires_in' => 300,
                'retry_after' => 60,
                'otp_length' => 6,
            ]
        ], 200);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'mobile_number' => 'required|string|regex:/^\+[1-9]\d{6,14}$/',
            'otp_code' => 'required|string|size:6|regex:/^\d{6}$/',
        ]);

        $validation = $this->otpService->validateOtp($request->mobile_number, $request->otp_code);

        if (!$validation['status']) {
            $statusCode = match ($validation['code']) {
                'MAX_ATTEMPTS_EXCEEDED' => 429,
                default => 401,
            };

            return response()->json([
                'status' => 'error',
                'message' => $validation['message'],
                'error_code' => $validation['code'],
                'data' => isset($validation['attempts_remaining']) ? ['attempts_remaining' => $validation['attempts_remaining']] : null,
            ], $statusCode);
        }

        /** @var \App\Models\User $user */
        $user = User::where('mobile_number', $request->mobile_number)->first();

        // 1. Tell the IDE exactly what kind of Guard we are using
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');

        // 2. Issue JWT Token using the strongly typed guard
        $token = $guard->login($user);
        $user->update(['last_login_at' => Carbon::now()]);

        $accessScope = $this->accessScopeService->buildAccessScope($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Authentication successful.',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => $guard->factory()->getTTL() * 60,
                'user' => [
                    'id' => $user->id,
                    'mobile_number' => $user->mobile_number,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'access_scope' => $accessScope,
                ]
            ]
        ], 200);
    }

    public function me(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth('api')->user();
        $accessScope = $this->accessScopeService->buildAccessScope($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'roles' => $user->getRoleNames(),
                'access_scope' => $accessScope
            ]
        ]);
    }

    public function logout(): JsonResponse
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');
        
        $guard->logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out.'
        ]);
    }
}