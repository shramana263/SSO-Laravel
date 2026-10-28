# StarOne SSO API Specification

## 1. Overview & General Specifications

- **Base URL:** `http://localhost:8000/api` *(Adjust according to environment)*
- **API Version:** `v1`
- **Default Headers:**
  ```http
  Accept: application/json
  Content-Type: application/json
  ```
- **Authentication Scheme:** `Bearer <star_one_session_token>` (passed in the `Authorization` header for protected endpoints).

---

## 2. Authentication Endpoints

### 2.1 Get Skillmine Hosted Login URL
Generates the formatted Skillmine login URL with a cryptographically secure CSRF `state`.

- **Method:** `GET`
- **Path:** `/v1/auth/skillmine/login-url`
- **Auth Required:** No

#### Query Parameters:
| Field | Type | Required | Description |
|---|---|---|---|
| `state` | string | No | Optional CSRF state (auto-generated if omitted) |
| `redirect_uri` | string | No | Optional custom redirect URI |

#### Response — 200 OK (Success)
```json
{
  "status": true,
  "state": "8a7f9b1c2d3e4f5a6b7c8d9e0f1a2b3c",
  "login_url": "https://smtcauth-api.starcement.co.in/authz-srv/authz?client_id=6eddc6ea-26b2-462a-9385-476175ce0ec9&response_type=code&scope=openid%20profile%20user_info_all&redirect_uri=http%3A%2F%2Flocalhost%3A8000%2Fapi%2Fv1%2Fauth%2Fcallback&groups_info=0&response_mode=query&prompt=none&state=8a7f9b1c2d3e4f5a6b7c8d9e0f1a2b3c"
}
```

---

### 2.2 Skillmine Callback (Token Exchange & Role Resolution)
Exchanges Skillmine's authorization `code` for verified user claims, resolves multi-app persona roles, applies Ship-to-Party filtering, and issues the StarOne session token.

- **Method:** `POST` (or `GET`)
- **Path:** `/v1/auth/callback`
- **Auth Required:** No

#### Request Body
```json
{
  "code": "AUTH_CODE_FROM_SKILLMINE"
}
```

#### Response — 200 OK (Success)
```json
{
  "status": true,
  "star_one_session_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJzdGFyLW9uZSIsInN1YiI6MSwiaWF0IjoxNzI2OTA1NjAwLCJleHAiOjE3MjY5OTIwMDB9...",
  "user": {
    "id": 1,
    "name": "Ramesh Sharma",
    "mobile": "9000000004",
    "emp_code": "DLR-1049"
  },
  "allowed_products": [
    {
      "key": "star_saathi",
      "name": "Star Saathi",
      "role": "Dealer"
    },
    {
      "key": "star_steller",
      "name": "Star Stellar",
      "role": "Site Engineer"
    }
  ],
  "skillmine_access_token": "eyJhbGciOiJSUzI1Ni..."
}
```

---

### 2.3 Logout (Federated Session Termination)
Invalidates the internal StarOne JWT and returns the Skillmine End Session URL.

- **Method:** `POST`
- **Path:** `/v1/auth/logout`
- **Auth Required:** Optional / Recommended

#### Request Body
```json
{
  "post_logout_redirect_uri": "https://starone.starcement.co.in/login"
}
```

#### Response — 200 OK (Success)
```json
{
  "status": true,
  "message": "Logged out successfully",
  "end_session_url": "https://smtcauth-api.starcement.co.in/token-srv/end_session?post_logout_redirect_uri=https%3A%2F%2Fstarone.starcement.co.in%2Flogin"
}
```

---

## 3. Local Standalone OTP Endpoints (Development Fallback)

### 3.1 Send OTP (Local)
- **Method:** `POST`
- **Path:** `/v1/sso/send-otp`
- **Request Body:**
  ```json
  {
    "mobile_number": "9000000004"
  }
  ```
- **Response — 200 OK:**
  ```json
  {
    "status": true,
    "message": "OTP sent successfully",
    "debug_otp": "1234"
  }
  ```

### 3.2 Verify OTP (Local)
- **Method:** `POST`
- **Path:** `/v1/sso/verify-otp`
- **Request Body:**
  ```json
  {
    "mobile_number": "9000000004",
    "otp": "1234"
  }
  ```
- **Response — 200 OK:** Returns `star_one_session_token`, `user`, and `allowed_products`.

---

## 4. Product Launch Endpoints

- **Path:** `/v1/sso/launch/{productKey}`
- **Auth Required:** `Bearer <star_one_session_token>`
- **Supported `productKey` values:**
  - `star_saathi` ➔ Returns legacy AES-128 encrypted hex string.
  - `star_sfa` ➔ Returns legacy XML recordset (`emp_code`, `newpassword`, `deviceid`).
  - `star_link` ➔ Returns JSON with internal JWT `access_token`.
  - `star_steller` ➔ Returns legacy engineer JSON profile.