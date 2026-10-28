# StarOne & Skillmine SSO Integration Technical Response Document

**Document Version:** 1.0  
**Prepared For:** Star Cement Management / Project Steering Committee (Arijit, Antara, Debanik)  
**Prepared By:** StarOne Backend & Integration Team  
**Subject:** Technical Review, Integration Strategy, and Consolidated Feedback on Skillmine Auth APIs  

---

## Executive Summary

Following a review of the *Custom Authentication Integration with Skillmine Auth* documentation and subsequent architectural discussions, this document details the StarOne backend perspective on integrating with **Skillmine Auth (Identity Provider / IdP)**. 

The integration uses the standard **OAuth 2.0 / OpenID Connect (OIDC) Authorization Code Grant** flow. Skillmine handles centralized identity authentication (Mobile Number + OTP validation), while the **StarOne Backend (`sso-laravel`)** functions as the core Application Orchestrator—handling multi-persona role mapping (SFA, Star Saathi, Star Link, Star Stellar), Ship-to-Party filtering, business session tokens, and legacy application payload delivery.

---

## 1. Technical Queries & Dependencies for Skillmine

To finalize the integration and environment setup, the StarOne team requests clarification on the following items from Skillmine:

1. **Client Secret Provisioning:**
   * The API document specifies `client_id=6eddc6ea-26b2-462a-9385-476175ce0ec9` for the token endpoint (`POST /token-srv/token`), but the corresponding `client_secret` is required for server-to-server token exchange. Please provide the sandbox/UAT `client_secret`.
2. **Mobile Flow & PKCE Support:**
   * Given that StarOne is primarily a mobile application, does Skillmine Auth support **OAuth 2.0 Authorization Code with PKCE (Proof Key for Code Exchange - RFC 7636)**?
   * *StarOne Recommendation:* If PKCE is supported, the mobile client can initiate the exchange directly. Otherwise, the mobile app will route the authorization `code` through the StarOne backend, which securely holds the `client_secret` for the token exchange.
3. **Environment Segregation (Staging / UAT vs. Production):**
   * Please confirm if the endpoint `https://smtcauth-api.starcement.co.in/` is the live production URL or a dedicated staging/UAT tenant. We require a staging environment with whitelisted test mobile numbers and fixed OTPs (or bypass mechanisms) to allow automated CI/CD and regression testing without SMS costs.
4. **User Pre-provisioning vs. Dynamic JIT Provisioning:**
   * Will users be pre-seeded into Skillmine’s Identity Directory via bulk import from SAP / legacy DBs, or does Skillmine dynamically create/enable user records upon the first successful OTP validation?
5. **Rate Limiting & OTP Throttling Policies:**
   * What are Skillmine's throttling limits (resend cooldown, max attempts per 15 minutes, IP limits) to ensure we align frontend UI retry timers?

---

## 2. Proposed StarOne-Side Authentication Flow & Implementation Approach

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           AUTHENTICATION FLOW OVERVIEW                          │
└─────────────────────────────────────────────────────────────────────────────────┘

 1. User Clicks Login (Mobile App / Web)
    │
    ▼
 2. StarOne App opens Skillmine Hosted Login:
    GET https://smtcauth-api.starcement.co.in/authz-srv/authz?...
    │
    ▼
 3. User Enters Mobile Number + OTP on Skillmine UI -> Verified by Skillmine
    │
    ▼
 4. Skillmine redirects to StarOne Callback URI with Authorization Code:
    https://starone-api.starcement.co.in/api/v1/auth/callback?code={AUTH_CODE}
    │
    ▼
 5. StarOne Backend executes Server-to-Server Token Exchange:
    POST https://smtcauth-api.starcement.co.in/token-srv/token
    (grant_type=authorization_code, code, client_id, client_secret)
    ──► Receives Skillmine `access_token` & `id_token`
    │
    ▼
 6. StarOne Backend fetches Verified User Claims:
    GET https://smtcauth-api.starcement.co.in/users-srv/userinfo (Bearer Token)
    ──► Extracts verified `phone_number`
    │
    ▼
 7. StarOne Core Business Logic (Local DB):
    • Normalizes Phone Number (strips +91)
    • Finds User & checks active account status
    • Resolves all Persona Roles across the 4 applications:
        - Star SFA (Employee)
        - Star Saathi (Dealer / Sub-dealer)
        - Star Link (Influencer / Contractor)
        - Star Stellar (Engineer / TE)
    • Applies business rules (e.g., Ship-to-Party filtering)
    • Issues StarOne Session JWT (`star_one_session_token`)
    │
    ▼
 8. StarOne Frontend receives Session Token + `allowed_products` list:
    • Single Product User ──► Auto-launches target module
    • Multi-Product User  ──► Shows Role Selection Dashboard (Tiles)
```

---

## 3. Required API Changes / Development at StarOne End

The StarOne backend architecture has been upgraded with a dedicated **`SkillmineAuthService`** and controller layer:

### A. New / Updated Endpoints in StarOne:
1. **`GET /api/v1/auth/skillmine/login-url`**
   * *Purpose:* Helper endpoint for mobile/web frontends to retrieve the formatted Skillmine login URL with a cryptographically secure CSRF `state` and configured `redirect_uri`.
2. **`POST / GET /api/v1/auth/callback`**
   * *Purpose:* The primary OAuth2 callback handler. Accepts the authorization `code`, completes the token handshake with Skillmine, fetches `userinfo`, looks up the user in StarOne's central database, and returns the StarOne session token and accessible product list.
3. **`POST /api/v1/auth/logout`**
   * *Purpose:* Invalidates the StarOne internal JWT session and provides the Skillmine federated `end_session_url` to terminate the SSO session at the IdP level.

### B. Legacy SSO Endpoints:
* `POST /api/v1/sso/send-otp` & `POST /api/v1/sso/verify-otp` are retained as internal fallbacks for local test mock suites.
* `POST /api/v1/sso/launch/{productKey}` remains **100% active and unchanged**, delegating directly to the 4 legacy adapters (SFA XML, Saathi AES-128-CBC, Stellar JSON, Link JSON).

---

## 4. User Identification and Mapping Approach

1. **Primary Unique Identifier:**
   * The **10-digit mobile number** (normalized by stripping country codes `+91`, leading zeros, or spaces) extracted from Skillmine's `phone_number` claim is the **Golden Key** for user mapping.
2. **Multi-Role Mapping under a Single Phone Number:**
   * In Star Cement's ecosystem, a single individual may hold multiple personas (e.g., an Engineer in Stellar and a Dealer in Saathi).
   * StarOne resolves this via the central `user_product_access` relational table, returning all authorized products as selectable options in the `allowed_products` payload.
3. **Ship-to-Party Role Filtering:**
   * Customers having secondary *"Ship to Party"* records are automatically deduplicated so only their primary Dealer / Sub-dealer role is exposed on their dashboard.
4. **Unregistered / Inactive Mobile Numbers:**
   * If a user authenticates successfully with OTP on Skillmine but does not exist in the StarOne database, StarOne responds with `403 Forbidden` (`code: NO_AUTHORIZED_PRODUCTS`), prompting the user to contact administrator/onboarding.

---

## 5. Required User / Master Data Fields & Synchronization Approach

### A. Required Fields from Skillmine (IdP):
* `phone_number` *(Mandatory - must have `phone_number_verified: true`)*
* `name` *(Mandatory)*
* `email` *(Optional)*
* `sub` *(Skillmine Subject UUID - used for audit/logging)*
* `user_status` *(Must be `ENABLE`)*

### B. Synchronization Approach:
* **Source of Truth:**
  * **SAP:** Master for Dealers/Sub-dealers (Saathi) and Employees (SFA).
  * **Legacy Product Databases:** Masters for Influencers/Contractors (Link) and Engineers (Stellar).
* **Sync Strategy:**
  1. **Phase 1 (Go-Live Migration & Nightly Cron):**
     * Execute one-time data cleanup/deduplication (cleaning Excel artifact numbers, dual slash numbers).
     * StarOne's `php artisan sso:sync-legacy` command synchronizes clean records into StarOne `users` and `user_product_access` tables.
     * Export cleaned mobile numbers to Skillmine to pre-seed the Identity Directory.
     * Run nightly batch syncs to pull newly created users.
  2. **Phase 2 (Real-Time API Sync):**
     * StarOne will expose an onboarding webhook/API (`POST /api/v1/users/sync`) callable by SAP or admin portals upon user registration, updating StarOne and provisioning the identity to Skillmine via API.

---

## 6. Redirect / Callback URL Requirements

Skillmine must whitelist the following redirect URIs in the Skillmine Auth Client Configuration (`client_id: 6eddc6ea-26b2-462a-9385-476175ce0ec9`):

| Environment | Platform | Callback / Redirect URL |
|---|---|---|
| **Local Development** | Backend / Web | `http://localhost:8000/api/v1/auth/callback` |
| **Development Tunnel** | Mobile Testing | `https://*.trycloudflare.com/api/v1/auth/callback` |
| **Staging / UAT** | Backend API | `https://stage-starone-api.starcement.co.in/api/v1/auth/callback` |
| **Staging / UAT** | Mobile App Deep Link | `starone://auth/callback` |
| **Production** | Backend API | `https://starone-api.starcement.co.in/api/v1/auth/callback` |
| **Production** | Mobile App Deep Link / App Link | `starone://auth/callback` / `https://starone.starcement.co.in/auth/callback` |

---

## 7. Token / Session Management and Logout Approach

### A. Three-Tiered Token & Session Architecture:

To avoid altering legacy product backends, StarOne operates a decoupled **3-Tier Token Lifecycle**:

```
[Tier 1: Skillmine Tokens] ──► [Tier 2: StarOne Session JWT] ──► [Tier 3: Product Native Tokens]
  (OTP & Identity Proof)         (StarOne Hub / Menu Shell)        (SFA / Saathi / Link / Stellar)
```

1. **Tier 1: Identity Layer (Skillmine Tokens):**
   * `access_token` & `id_token` (Short-lived, ~3600s).
   * Used strictly for 5 seconds by the StarOne backend during the login handshake to fetch verified phone number claims from Skillmine UserInfo API.
2. **Tier 2: Application Hub Layer (StarOne JWT):**
   * `star_one_session_token` (Issued by `sso-laravel`).
   * Used exclusively within the unified StarOne application shell to manage user sessions, display allowed product tiles, and execute the `POST /api/v1/sso/launch/{productKey}` trigger.
3. **Tier 3: Legacy Product Native Layer (Unchanged Product Backends):**
   * When a user taps an application tile, the StarOne adapter performs a live native handshake and delivers the product's native authentication payload:
     * **Star Link:** Delivers Star Link's internal JWT `access_token` for subsequent influencer/mason APIs.
     * **Star Saathi:** Delivers the legacy AES-128 encrypted hex session for dealer APIs.
     * **Star SFA:** Delivers the legacy XML credentials (`emp_code`, `newpassword`, `deviceid`) for sales force APIs.
     * **Star Stellar:** Delivers the native engineer/TE JSON session for technical engineer APIs.
   * Subsequent module operations communicate directly with the respective legacy product backends using their native tokens without requiring changes to existing backend services.

### B. Federated Logout:
1. User triggers logout in StarOne app.
2. StarOne backend invalidates the local StarOne session JWT.
3. StarOne backend invokes Skillmine's End Session endpoint:
   ```text
   GET https://smtcauth-api.starcement.co.in/token-srv/end_session?access_token_hint={token}&post_logout_redirect_uri={redirect_url}
   ```
4. Terminates the SSO session at the IdP level, ensuring clean re-authentication on shared devices.

---

## 8. Security Considerations (Device & Account Handling)

1. **State Parameter Validation:**
   * Unique, non-guessable `state` tokens are generated per login request to prevent CSRF and replay attacks during authorization code exchange.
2. **Device Fingerprinting (Background Analysis):**
   * StarOne captures client device metadata (Device ID, Model, OS version, IP address) during product launch.
   * To prevent disrupting business operations for field contractors or dealers sharing devices, multi-account logins on the same device will be logged and monitored via background anomaly detection rather than being hard-blocked.
3. **Secure Credential Storage:**
   * Mobile apps will store the StarOne JWT in Android Keystore / iOS Keychain (encrypted SharedPreferences / Secure Storage).

---

## 9. Development Effort and Tentative Timeline

The backend integration layer (`SkillmineAuthService`, callback endpoints, role resolution, and automated test suite) is **already fully developed and validated in the `sso-laravel` codebase**.

The remaining timeline accounts for joint sandbox testing, staging environment setup, and mobile frontend integration:

| Milestone / Task | Scope | Estimated Duration | Status |
|---|---|---|:---:|
| **1. Backend Service & Adapter Setup** | Skillmine client, callback controller, token exchange, legacy adapter parity | 3 Days | ✅ **Completed** |
| **2. Test Suite & Schema Validation** | Unit/Feature test suite (21 tests green) | 2 Days | ✅ **Completed** |
| **3. Joint Staging & Sandbox Testing** | Skillmine client secret verification, test mobile/OTP validation | 2 Days | ⏳ Pending Skillmine Inputs |
| **4. Mobile Frontend Integration** | In-app browser / Deep link callback handling in StarOne App | 3 - 4 Days | ⏳ Scheduled |
| **5. Master Data Migration & UAT** | Clean phone deduplication sync, multi-role validation across 4 apps | 3 Days | ⏳ Scheduled |
| **Total Turnaround Time** | **From receipt of Skillmine sandbox credentials to UAT sign-off** | **~8 - 10 Working Days** | |

---

## Next Steps

1. **Skillmine Team:** Share the `client_secret` and confirm staging/UAT environment parameters.
2. **StarOne Team:** Whitelist callback URLs and schedule joint end-to-end sandbox testing.
3. **Management Review:** Approve the 2-tier session and phone-mapping approach outlined above.
