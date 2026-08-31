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
        
        $user = User::with('productAccess')->where('mobile_number', $request->mobile_number)->first();
        if (!$user || $user->productAccess->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'User not registered or has no active product access.'], 404);
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
        if (!$user || $user->productAccess->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No active authorized application roles found for this account.',
                'code' => 'NO_AUTHORIZED_PRODUCTS'
            ], 403);
        }

        $token = JWTAuth::fromUser($user);

        // Build the full access list first (we need it for the Ship-to-Party filter below)
        $rawProducts = $user->productAccess->map(function ($access) {
            return [
                'product_id' => $access->product_id,   // used for filtering only
                'key'        => $access->product->slug,
                'name'       => $access->product->name,
                'role'       => $access->role_name,
            ];
        });

        // -------------------------------------------------------------------
        // Ship-to-Party filter
        // A customer whose role_name is "Ship to Party-dealer" or
        // "ShiptoParty-Subdealer" already has a primary Dealer/Sub-dealer
        // entry for the same product. Remove the Ship-to-Party entry so the
        // app only ever presents the primary role tile to the user.
        // -------------------------------------------------------------------
        $allowedProducts = $rawProducts->filter(function ($item) use ($rawProducts) {
            $role = strtolower(trim($item['role'] ?? ''));

            $isShipToParty = str_contains($role, 'ship to party') ||
                             str_contains($role, 'shiptoparty');

            if (!$isShipToParty) {
                return true; // normal role — always keep
            }

            // Check whether a primary (non-Ship-to-Party) entry exists
            // for the same product
            $hasPrimary = $rawProducts->contains(function ($other) use ($item) {
                if ($other['product_id'] !== $item['product_id']) {
                    return false;
                }
                $otherRole = strtolower(trim($other['role'] ?? ''));
                return !str_contains($otherRole, 'ship to party') &&
                       !str_contains($otherRole, 'shiptoparty');
            });

            return !$hasPrimary;
        })->map(function ($item) {
            // Drop the internal product_id field before sending to client
            unset($item['product_id']);
            return $item;
        })->values();

        if ($allowedProducts->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No active authorized application roles found for this account.',
                'code' => 'NO_AUTHORIZED_PRODUCTS'
            ], 403);
        }

        return response()->json([
            'status' => true,
            'star_one_session_token' => $token,
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'mobile'    => $user->mobile_number,
                'emp_code'  => $user->emp_code
            ],
            'allowed_products' => $allowedProducts
        ]);
    }

    // Step 3: Launch Product & Hand Off Legacy Payload
    public function launchProduct(Request $request, string $productKey)
    {
        $user    = auth('api')->user();
        $product = Product::where('slug', $productKey)->where('is_active', true)->firstOrFail();

        // Retrieve legacy attributes for this specific panel.
        // When a user has multiple metadata rows for the same product (e.g. a
        // Ship-to-Party row AND a Dealer row) prefer the non-Ship-to-Party one
        // so the adapter always receives the primary role's attributes.
        $allMeta = UserProductMetadata::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->get();

        $metadata = null;

        // First pass: pick a non-Ship-to-Party row
        foreach ($allMeta as $meta) {
            $metaType = strtolower(trim(
                ($meta->attributes['user_type'] ?? $meta->attributes['cust_type'] ?? '')
            ));
            $isShipToParty = str_contains($metaType, 'ship to party') ||
                             str_contains($metaType, 'shiptoparty');
            if (!$isShipToParty) {
                $metadata = $meta;
                break;
            }
        }

        // Fallback: if only Ship-to-Party rows exist, use the first one
        // (StarSaathiAdapter::resolveAttributes() will attempt a redirect anyway)
        if ($metadata === null) {
            $metadata = $allMeta->first();
        }

        $attributes = $metadata ? $metadata->attributes : [];

        // Delegate response formatting to the corresponding adapter
        $adapter = AdapterFactory::make($productKey);
        return $adapter->formatResponse($user, $attributes);
    }
}