<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
// use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Exception;
use Tymon\JWTAuth\Facades\JWTAuth;

class VerifySsoToken
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Statistically verifies signature using the shared JWT_SECRET
            $payload = JWTAuth::parseToken()->getPayload();
            
            // Set authenticated user context in request
            $request->attributes->add([
                'sso_user_id' => $payload->get('sub'),
                'mobile_number' => $payload->get('mobile_number'),
                'roles' => $payload->get('roles'),
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized or invalid SSO token.'
            ], 401);
        }

        return $next($request);
    }
}