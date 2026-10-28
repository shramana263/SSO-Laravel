# StarOne SSO & Skillmine Integration — Complete Context Handover

> **Purpose**: This document provides the full technical and architectural context of the **Star Cement StarOne SSO (`sso-laravel`)** backend, including its integration with **Skillmine Auth (IdP)**, current configuration, database state, key findings, and testing procedures. Copy or reference this document in any new chat thread to resume work seamlessly.

---

## 1. Project Overview & Architecture

- **Project Name**: `sso-laravel` (Star Cement Unified Single Sign-On Orchestrator)
- **Goal**: Create a central SSO business hub and authentication gateway for the unified **StarOne** mobile & web application, unifying 4 legacy products:
  1. **Star SFA** (Sales Force Automation – Employees)
  2. **Star Saathi** (Dealers, Sub-dealers, Brokers)
  3. **Star Link** (Contractors, Influencers, BDEs)
  4. **Star Stellar** (Engineers)
- **Identity Provider (IdP)**: **Skillmine Auth** (Hosted Mobile + OTP login via OAuth 2.0 / OpenID Connect).
- **Core Principle**: 
  - Skillmine is strictly responsible for identity verification (Mobile + OTP).
  - `sso-laravel` acts as the orchestrator: receives the auth code, exchanges it for tokens, verifies token introspection (`active: true`), fetches user claims from UserInfo, maps the phone number to local central DB roles, deduplicates Ship-to-Party aliases, issues a `star_one_session_token` (JWT), and formats handoff payloads for legacy product launch endpoints without modifying any legacy product backend.

---

## 2. Skillmine Credentials & Configuration

Configured in `config/services.php` and `.env`:

| Parameter | Value | Description |
|---|---|---|
| `SKILLMINE_BASE_URL` | `https://smtcauth-api.starcement.co.in` | Skillmine IdP base URL |
| `SKILLMINE_CLIENT_ID` | `6eddc6ea-26b2-462a-9385-476175ce0ec9` | Star Cement client ID |
| `SKILLMINE_CLIENT_SECRET`| `93483c6a-dc8c-4f2e-acdd-cea4e5b9ffdc` | Star Cement client secret |
| `SKILLMINE_REDIRECT_URI` | `http://localhost:8000/api/v1/auth/callback` | OAuth2 redirect URI |
| `SKILLMINE_MANUAL_MODE`  | `true` / `false` | When `true`, callback displays unconsumed code for manual Postman/EchoAPI testing |

---

## 3. Skillmine API Endpoints & Auth Lifecycle

1. **Hosted Login URL (`GET /authz-srv/authz`)**:
   - URL: `https://smtcauth-api.starcement.co.in/authz-srv/authz?client_id=6eddc6ea-26b2-462a-9385-476175ce0ec9&response_type=code&scope=openid%20profile%20user_info_all&redirect_uri=http://localhost:8000/api/v1/auth/callback&groups_info=0&response_mode=query`
   - *Note*: `prompt=none` must NOT be passed for user interactive login. State is managed internally by Skillmine.

2. **Token Exchange (`POST /token-srv/token`)**:
   - Request Body:
     ```json
     {
       "grant_type": "authorization_code",
       "client_id": "6eddc6ea-26b2-462a-9385-476175ce0ec9",
       "client_secret": "93483c6a-dc8c-4f2e-acdd-cea4e5b9ffdc",
       "code": "<AUTHORIZATION_CODE>",
       "redirect_uri": "http://localhost:8000/api/v1/auth/callback",
       "scope": "openid profile email"
     }
     ```
   - Response: Returns `{"access_token": "...", "id_token": "...", "token_type": "Bearer", "expires_in": 3600}`.

3. **Token Introspection (`POST /token-srv/introspect`) — Critical Verification Step**:
   - *Requirement*: As mandated by management and OAuth2 RFC 7662, this endpoint MUST return `{"active": true}` before calling UserInfo.
   - Header: `Authorization: <access_token>` or `Authorization: Bearer <access_token>`
   - Body:
     ```json
     {
       "token": "<access_token>",
       "token_type_hint": "access_token"
     }
     ```
   - Response: `{"active": true, "sub": "...", "scope": "..."}`.

4. **User Profile Claims (`GET /users-srv/userinfo`)**:
   - Header: `Authorization: Bearer <access_token>`
   - Response: `{"sub": "...", "phone_number": "+919007083566", "name": "ANTARA BANERJEE"}`.

5. **Federated Logout (`GET /token-srv/end_session`)**:
   - URL: `https://smtcauth-api.starcement.co.in/token-srv/end_session?access_token_hint=<access_token>`

---

## 4. Key Files & Implementation Details

- **`app/Services/SkillmineAuthService.php`**:
  - `getAuthorizationUrl(?string $redirectUri)`: Generates the Skillmine hosted login URL.
  - `exchangeCodeForTokens(string $code, ?string $redirectUri)`: Exchanges auth code for tokens.
  - `introspectToken(string $accessToken)`: Verifies token validity on `/token-srv/introspect`.
  - `getUserInfo(string $accessToken)`: Fetches user profile claims on `/users-srv/userinfo`.
  - `normalizePhoneNumber(?string $phone)`: Strips `+91` / `91` prefixes.
  - `getEndSessionUrl(...)`: Generates federated logout URL.
  - Uses `Http::withoutVerifying()` when debug/local is active to handle Skillmine's self-signed / expired SSL cert.

- **`app/Http/Controllers/Api/V1/SsoController.php`**:
  - `getSkillmineLoginUrl(Request $request)`: API helper returning the hosted login URL.
  - `handleSkillmineCallback(Request $request)`: 
    1. Checks if `SKILLMINE_MANUAL_MODE=true` or `?capture=true` — if so, returns unconsumed code for manual EchoAPI testing.
    2. Exchanges `code` for `access_token` and `id_token`.
    3. Calls `introspectToken($accessToken)` and strictly verifies `active === true` (returns 401 `SKILLMINE_TOKEN_INACTIVE` if false).
    4. Calls `getUserInfo($accessToken)` to extract verified phone number.
    5. Matches phone number in central `users` and `user_product_access` tables.
    6. Filters out `Ship-to-Party` alias roles when a primary Dealer role exists.
    7. Generates StarOne session JWT (`star_one_session_token`) and returns allowed product list.
  - `launchProduct(Request $request, string $productKey)`: Protected by `auth:api`. Resolves legacy adapter via `AdapterFactory::make($productKey)` and queries product DB dynamically to format legacy handoff payloads:
    - `star_saathi`: AES-128 Hex Encrypted string
    - `star_sfa`: XML Recordset
    - `star_link`: JSON payload with native contractor tokens
    - `star_stellar`: JSON payload with engineer attributes
  - `logout(Request $request)`: Invalidates JWT and returns Skillmine federated end-session URL.

- **`routes/api.php`**:
  - `GET /api/v1/auth/skillmine/login-url`
  - `GET|POST /api/v1/auth/callback`
  - `POST /api/v1/auth/logout`
  - `POST /api/v1/sso/launch/{productKey}` (Headers: `Authorization: Bearer <star_one_session_token>`)
  - `POST /api/v1/sso/send-otp` & `POST /api/v1/sso/verify-otp` (Standalone legacy fallback)

---

## 5. Database & Seeded Data

- **Database Sync**: Synced via `php artisan sso:sync-legacy` from live replica DBs:
  - 692 SFA employees
  - 14,296 Saathi dealers/subdealers
  - 41,875 Link contractors/dealers
- **Live Tested User**:
  - Mobile: `9007083566` (Antara Banerjee)
  - Authorized Products: `star_saathi` (role: `broker`), `star_link` (role: `BDE`)
  - Result: Successfully authenticated through live Skillmine IdP and mapped in StarOne central DB!

---

## 6. Key Learnings & Gotchas

1. **Skillmine SSL Certificate Issue**:
   - `smtcauth-api.starcement.co.in` has an invalid/expired SSL cert date. In Google Chrome, type `thisisunsafe` on the error page to bypass. In Laravel, `Http::withoutVerifying()` is active in local debug mode.
2. **Single-Use Authorization Codes**:
   - OAuth2 authorization codes are **single-use only**. If `php artisan serve` is running and the browser lands on `http://localhost:8000/api/v1/auth/callback`, Laravel immediately exchanges it. Attempting to re-exchange that code manually in Postman will return `{"error": "invalid_request", "error_description": "invalid code passed"}`.
   - Use `SKILLMINE_MANUAL_MODE=true` in `.env` to prevent auto-consumption when doing manual step-by-step Postman/EchoAPI testing.
3. **`active: false` on Introspection**:
   - Introspecting an `id_token` returns `{"active": false}`. You must introspect the `access_token`.
   - Calling `/token-srv/introspect` without the `Authorization` header returns `{"active": false}`.
   - Expired tokens or ended sessions return `{"active": false}`.
4. **Client ID & Secret**:
   - Client ID: `6eddc6ea-26b2-462a-9385-476175ce0ec9`
   - Client Secret: `93483c6a-dc8c-4f2e-acdd-cea4e5b9ffdc`
   - *(Do not use the SK Finance `24cf07cb...` client ID from the old Postman sample)*.

---

## 7. Current Project Status

- [x] Skillmine Hosted Login URL generation verified.
- [x] Skillmine token exchange and token introspection (`active: true`) strictly enforced before UserInfo.
- [x] Skillmine UserInfo phone number extraction and StarOne central user mapping verified.
- [x] Ship-to-Party role deduplication implemented and verified.
- [x] All 21 Feature tests (`SkillmineAuthTest` and `SsoFlowTest`) passing.
- [x] Live login flow with mobile `9007083566` fully verified end-to-end.
- [x] Manual testing mode (`SKILLMINE_MANUAL_MODE=true`) added for isolated EchoAPI testing.
- [x] EchoAPI collection (`echoapi-collection.json`) and technical response documentation (`SKILLMINE_INTEGRATION_RESPONSE.md`, `SSO_API_DOCUMENTATION.md`) updated.
