<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Services\SkillmineAuthService;
use App\Services\LegacyUserSyncService;
use App\Services\Sso\AdapterFactory;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class SsoController extends Controller
{
    protected SkillmineAuthService $skillmineAuthService;
    protected LegacyUserSyncService $legacyUserSyncService;

    public function __construct(
        SkillmineAuthService $skillmineAuthService,
        LegacyUserSyncService $legacyUserSyncService
    ) {
        $this->skillmineAuthService = $skillmineAuthService;
        $this->legacyUserSyncService = $legacyUserSyncService;
    }

    // -------------------------------------------------------------------------
    // Skillmine Hosted OAuth 2.0 Flow
    // -------------------------------------------------------------------------

    // Step 1.1: Get Skillmine Hosted Login URL
    public function getSkillmineLoginUrl(Request $request)
    {
        $redirectUri = $request->input('redirect_uri');
        $state = $request->input('state'); // Only passed if client explicitly provides one

        $response = [
            'status'    => true,
            'login_url' => $this->skillmineAuthService->getAuthorizationUrl($state, $redirectUri),
        ];

        if (!empty($state)) {
            $response['state'] = $state;
        }

        return response()->json($response);
    }

    // Step 1.2: Handle Skillmine Callback (Token Exchange & Role Resolution)
    public function handleSkillmineCallback(Request $request)
    {
        $code = $request->input('code') ?? $request->query('code');

        if (empty($code)) {
            return response()->json([
                'status'  => false,
                'message' => 'Authorization code is required.',
                'code'    => 'MISSING_AUTH_CODE',
            ], 400);
        }

        // Manual testing mode (for EchoAPI / Postman manual step-by-step testing)
        if (config('services.skillmine.manual_mode') || $request->boolean('capture')) {
            return response()->json([
                'status'  => true,
                'message' => 'Authorization code captured successfully (manual testing mode).',
                'code'    => $code,
            ]);
        }

        try {
            // 1. Exchange code for Access Token & ID Token
            $tokens = $this->skillmineAuthService->exchangeCodeForTokens($code);
            $accessToken = $tokens['access_token'] ?? null;

            if (empty($accessToken)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Failed to obtain access token from Skillmine.',
                    'code'    => 'SKILLMINE_AUTH_FAILED',
                ], 401);
            }

            // 2. Introspect token validity (active: true requirement)
            $introspection = $this->skillmineAuthService->introspectToken($accessToken);
            if (!($introspection['active'] ?? false)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Skillmine access token is inactive or revoked.',
                    'code'    => 'SKILLMINE_TOKEN_INACTIVE',
                ], 401);
            }

            // 3. Fetch User Profile Claims (Phone number, sub, name)
            $userInfo = $this->skillmineAuthService->getUserInfo($accessToken);
            $phone = $this->skillmineAuthService->normalizePhoneNumber(
                $userInfo['phone_number'] ?? $userInfo['phone'] ?? null
            );

            if (empty($phone)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Verified phone number claim missing in Skillmine user profile.',
                    'code'    => 'SKILLMINE_MISSING_PHONE',
                ], 422);
            }

            // 4. Look up user in Central DB (with Just-In-Time discovery fallback)
            $user = User::with('productAccess.product')->where('mobile_number', $phone)->first();
            if (!$user || $user->productAccess->isEmpty()) {
                $user = $this->legacyUserSyncService->syncUserByMobile($phone);
            }

            if (!$user || $user->productAccess->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No active authorized application roles found for this account.',
                    'code'    => 'NO_AUTHORIZED_PRODUCTS',
                ], 403);
            }

            if (!$user->status) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User account is inactive. Please contact administrator.',
                    'code'    => 'USER_INACTIVE',
                ], 403);
            }

            $token = JWTAuth::fromUser($user);
            $allowedProducts = $this->resolveAllowedProducts($user);

            if ($allowedProducts->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No active authorized application roles found for this account.',
                    'code'    => 'NO_AUTHORIZED_PRODUCTS',
                ], 403);
            }

            return response()->json([
                'status'                 => true,
                'star_one_session_token' => $token,
                'user'                   => [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'mobile'   => $user->mobile_number,
                    'emp_code' => $user->emp_code,
                ],
                'allowed_products'       => $allowedProducts,
                'skillmine_access_token' => $accessToken,
                'skillmine_id_token'     => $tokens['id_token'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'code'    => 'SKILLMINE_ERROR',
            ], 500);
        }
    }

    // Step 1.3: Federated Logout
    public function logout(Request $request)
    {
        // 1. Invalidate StarOne JWT Session
        $rawToken = $request->bearerToken() 
            ?? $request->input('star_one_session_token') 
            ?? $request->input('token');

        $starOneTokenInvalidated = false;
        if (!empty($rawToken)) {
            try {
                JWTAuth::setToken($rawToken)->invalidate();
                $starOneTokenInvalidated = true;
            } catch (\Throwable $e) {
            }
        } else {
            try {
                JWTAuth::parseToken()->invalidate();
                $starOneTokenInvalidated = true;
            } catch (\Throwable $e) {
            }
        }

        $postLogoutRedirectUri = $request->input('post_logout_redirect_uri');
        $accessToken = $request->input('access_token') ?? $request->input('skillmine_access_token');
        $idToken = $request->input('id_token') ?? $request->input('skillmine_id_token');

        // 2. Call Skillmine IdP End Session API directly if access_token or id_token is provided
        $skillmineEndSession = null;
        if (!empty($accessToken) || !empty($idToken)) {
            $skillmineEndSession = $this->skillmineAuthService->endSession($accessToken, $idToken);
        }

        $endSessionUrl = $this->skillmineAuthService->getEndSessionUrl($accessToken, $idToken, $postLogoutRedirectUri);

        return response()->json([
            'status'                     => true,
            'message'                    => 'Logged out successfully.',
            'star_one_token_invalidated' => $starOneTokenInvalidated,
            'skillmine_end_session'      => $skillmineEndSession,
            'end_session_url'            => $endSessionUrl,
        ]);
    }

    // -------------------------------------------------------------------------
    // Standalone Local OTP Flow (Fallback / Offline)
    // -------------------------------------------------------------------------

    // Step 2.1: Legacy OTP stub (Identity verification now handled by Skillmine)
    public function sendOtp(Request $request)
    {
        return response()->json([
            'status'  => false,
            'message' => 'Standalone OTP verification is discontinued. Please authenticate via Skillmine OAuth2 flow at /api/v1/auth/skillmine/login-url.',
            'code'    => 'USE_SKILLMINE_AUTH',
        ], 400);
    }

    // Step 2.2: Legacy OTP stub (Identity verification now handled by Skillmine)
    public function verifyOtp(Request $request)
    {
        return response()->json([
            'status'  => false,
            'message' => 'Standalone OTP verification is discontinued. Please authenticate via Skillmine OAuth2 flow at /api/v1/auth/skillmine/login-url.',
            'code'    => 'USE_SKILLMINE_AUTH',
        ], 400);
    }

    // Step 3: Launch Product & Hand Off Legacy Payload
    public function launchProduct(Request $request, string $productKey)
    {
        /** @var User $user */
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $product = Product::where('slug', $productKey)->where('is_active', true)->firstOrFail();

        // Delegate response formatting directly to the corresponding adapter (queries product DB dynamically)
        $adapter = AdapterFactory::make($productKey);
        return $adapter->formatResponse($user);
    }

    /**
     * Resolve and deduplicate allowed product access list (Ship-to-Party filtering)
     */
    protected function resolveAllowedProducts(User $user)
    {
        $rawProducts = $user->productAccess->map(function ($access) {
            return [
                'product_id' => $access->product_id,   // used for filtering only
                'key'        => $access->product->slug,
                'name'       => $access->product->name,
                'role'       => $access->role_name,
            ];
        });

        return $rawProducts->filter(function ($item) use ($rawProducts) {
            $role = strtolower(trim($item['role'] ?? ''));

            $isShipToParty = str_contains($role, 'ship to party') ||
                             str_contains($role, 'shiptoparty');

            if (!$isShipToParty) {
                return true; // normal role — always keep
            }

            // Check whether a primary (non-Ship-to-Party) entry exists for the same product
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
            unset($item['product_id']);
            return $item;
        })->values();
    }
}