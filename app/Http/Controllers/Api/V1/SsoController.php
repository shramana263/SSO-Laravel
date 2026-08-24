<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\UserProductMetadata;
use App\Services\OtpService;
use App\Services\SmsService;
use App\Services\Sso\AdapterFactory;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class SsoController extends Controller
{
    protected OtpService $otpService;
    protected SmsService $smsService;

    public function __construct(OtpService $otpService, SmsService $smsService)
    {
        $this->otpService = $otpService;
        $this->smsService = $smsService;
    }

    // Step 1: Send Central OTP
    public function sendOtp(Request $request)
    {
        $request->validate(['mobile_number' => 'required|string']);
        
        $user = User::where('mobile_number', $request->mobile_number)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        if (!$user->status) {
            return response()->json(['status' => false, 'message' => 'User account is inactive. Please contact administrator.'], 403);
        }

        $otp = $this->otpService->generateOtp($user->mobile_number);
        $this->smsService->sendOtp($user->mobile_number, $otp);

        return response()->json(['status' => true, 'message' => 'OTP sent successfully']);
    }

    // Step 2: Verify OTP & Return Accessible Products
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'mobile_number' => 'required|string',
            'otp' => 'required|string'
        ]);

        $verification = $this->otpService->validateOtp($request->mobile_number, $request->otp);
        if (!$verification['status']) {
            return response()->json([
                'status' => false,
                'message' => $verification['message'] ?? 'Invalid or expired OTP',
                'code' => $verification['code'] ?? 'INVALID_OTP'
            ], 401);
        }

        $user = User::with('productAccess.product')->where('mobile_number', $request->mobile_number)->first();
        $token = JWTAuth::fromUser($user);

        $allowedProducts = $user->productAccess->map(function ($access) {
            return [
                'key' => $access->product->slug,
                'name' => $access->product->name,
                'role' => $access->role_name
            ];
        })->values();

        return response()->json([
            'status' => true,
            'star_one_session_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile_number,
                'emp_code' => $user->emp_code
            ],
            'allowed_products' => $allowedProducts
        ]);
    }

    // Step 3: Launch Product & Hand Off Legacy Payload
    public function launchProduct(Request $request, string $productKey)
    {
        $user = auth('api')->user();
        $product = Product::where('slug', $productKey)->where('is_active', true)->firstOrFail();

        // Retrieve legacy attributes for this specific panel
        $metadata = UserProductMetadata::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        $attributes = $metadata ? $metadata->attributes : [];

        // Delegate response formatting to the corresponding adapter
        $adapter = AdapterFactory::make($productKey);
        return $adapter->formatResponse($user, $attributes);
    }
}