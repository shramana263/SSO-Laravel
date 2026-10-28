<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;
use RuntimeException;

class SkillmineAuthService
{
    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    public function __construct()
    {
        $this->baseUrl      = rtrim(config('services.skillmine.base_url', 'https://smtcauth-api.starcement.co.in'), '/');
        $this->clientId     = config('services.skillmine.client_id', '6eddc6ea-26b2-462a-9385-476175ce0ec9');
        $this->clientSecret = config('services.skillmine.client_secret', '');
        $this->redirectUri  = config('services.skillmine.redirect_uri', 'http://localhost:8000/api/v1/auth/callback');
    }

    /**
     * 1. Generate the Skillmine Hosted Authorization / Login URL.
     */
    public function getAuthorizationUrl(?string $state = null, ?string $redirectUri = null): string
    {
        $params = [
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'scope'         => 'openid profile user_info_all',
            'redirect_uri'  => $redirectUri ?: $this->redirectUri,
            'groups_info'   => 0,
            'response_mode' => 'query',
        ];

        if (!empty($state)) {
            $params['state'] = $state;
        }

        return "{$this->baseUrl}/authz-srv/authz?" . http_build_query($params);
    }

    /**
     * 2. Exchange authorization code for Access Token & ID Token.
     */
    public function exchangeCodeForTokens(string $code, ?string $redirectUri = null): array
    {
        $http = Http::asForm()->timeout(10);
        if (config('app.debug') || app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post("{$this->baseUrl}/token-srv/token", [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri ?: $this->redirectUri,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->failed()) {
            $err = $response->json('error_description') ?? $response->body();
            throw new RuntimeException("Skillmine token exchange failed ({$response->status()}): {$err}");
        }

        return $response->json();
    }

    /**
     * 3. Fetch User Profile Claims (Phone number, sub, name) using Bearer Access Token.
     */
    public function getUserInfo(string $accessToken): array
    {
        $http = Http::withToken($accessToken)->timeout(10);
        if (config('app.debug') || app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        $response = $http->get("{$this->baseUrl}/users-srv/userinfo");

        if ($response->failed()) {
            $err = $response->json('error_description') ?? $response->body();
            throw new RuntimeException("Skillmine UserInfo fetch failed ({$response->status()}): {$err}");
        }

        return $response->json();
    }

    /**
     * 4. Introspect token validity (active: true / false).
     */
    public function introspectToken(string $accessToken): array
    {
        $http = Http::withToken($accessToken)->timeout(10);
        if (config('app.debug') || app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post("{$this->baseUrl}/token-srv/introspect", [
            'token'           => $accessToken,
            'token_type_hint' => 'access_token',
        ]);

        if ($response->failed()) {
            $err = $response->json('error_description') ?? $response->body();
            throw new RuntimeException("Skillmine Introspect failed ({$response->status()}): {$err}");
        }

        return $response->json();
    }

    /**
     * 5. Direct HTTP call to End Session / Revoke token on Skillmine IdP.
     */
    public function endSession(?string $accessTokenHint = null, ?string $idTokenHint = null): array
    {
        if (empty($accessTokenHint) && empty($idTokenHint)) {
            return ['status' => false, 'message' => 'No access token or ID token provided'];
        }

        $http = Http::withoutRedirecting()->timeout(10);
        if (config('app.debug') || app()->environment('local')) {
            $http = $http->withoutVerifying();
        }

        $params = array_filter([
            'access_token_hint' => $accessTokenHint,
            'id_token_hint'     => $idTokenHint,
        ]);

        // Send direct revocation to Skillmine IdP without redirect URL in server-to-server call
        $url = "{$this->baseUrl}/token-srv/end_session?" . http_build_query($params);
        
        try {
            $response = $http->get($url);
            $isSuccess = $response->successful() || $response->redirect();
            return [
                'status'      => $isSuccess,
                'status_code' => $response->status(),
                'redirect_url'=> $response->header('Location'),
                'body'        => $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'status'  => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 6. Federated Logout / End Session URL Builder.
     */
    public function getEndSessionUrl(?string $accessTokenHint = null, ?string $idTokenHint = null, ?string $postLogoutRedirectUri = null, ?string $state = null): string
    {
        $params = array_filter([
            'access_token_hint'        => $accessTokenHint,
            'id_token_hint'            => $idTokenHint,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'state'                    => $state,
        ]);

        $query = http_build_query($params);

        return "{$this->baseUrl}/token-srv/end_session" . ($query ? "?{$query}" : '');
    }

    /**
     * Helper to clean / normalize phone numbers from Skillmine claim (e.g. "+919000000004" -> "9000000004")
     */
    public function normalizePhoneNumber(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        // Remove non-digit characters except leading plus
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));

        // Strip leading +91 or 91 if it's a 12-digit number starting with 91
        if (str_starts_with($cleaned, '+91')) {
            $cleaned = substr($cleaned, 3);
        } elseif (strlen($cleaned) === 12 && str_starts_with($cleaned, '91')) {
            $cleaned = substr($cleaned, 2);
        }

        return ltrim($cleaned, '0');
    }
}
