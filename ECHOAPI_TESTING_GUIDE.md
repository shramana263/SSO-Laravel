# StarOne SSO API — EchoAPI & Postman Complete Testing Guide

**Version:** 2.0 (Skillmine OAuth 2.0 + Multi-Product SSO)  
**Base URL:** `http://localhost:8000/api`  
**Authentication Header:** `Authorization: Bearer <star_one_session_token>`  
**Collection File:** [`echoapi-collection.json`](file:///d:/Acceleron/Star%20Cement/sso-laravel/echoapi-collection.json) *(Import directly via EchoAPI / Postman: Import → Postman v2.1 format)*

---

## 🧭 Testing Architecture Overview

StarOne supports **two testing flows**:
1. **Flow A (Production / Skillmine Flow):** Get Skillmine Login URL ➔ User verifies OTP on Skillmine ➔ StarOne Callback exchanges code & returns `allowed_products`.
2. **Flow B (Local Development / Mock Flow):** Local `send-otp` ➔ Local `verify-otp` (auto-extracts debug OTP) ➔ Returns `allowed_products`.
3. **Product Launch (Both Flows):** `POST /v1/sso/launch/{product_key}` with `star_one_session_token` ➔ Hand-off legacy payload.

```
                  ┌────────────────────────────────────────────────────────┐
                  │                1. AUTHENTICATION STAGE                 │
                  └────────────────────────────────────────────────────────┘
                       │                                      │
           [FLOW A: SKILLMINE OAUTH2]                 [FLOW B: LOCAL OTP]
                       │                                      │
            GET /v1/auth/skillmine/login-url           POST /v1/sso/send-otp
                       │                                      │
            Skillmine Hosted Mobile + OTP              Captures `debug_otp`
                       │                                      │
            POST /v1/auth/callback                     POST /v1/sso/verify-otp
                       │                                      │
                       └──────────────────┬───────────────────┘
                                          │
                                          ▼
                       Returns: `star_one_session_token`
                                + `allowed_products` [Saathi, SFA, Link, Stellar]
                                          │
                                          ▼
                  ┌────────────────────────────────────────────────────────┐
                  │                 2. PRODUCT LAUNCH STAGE                │
                  └────────────────────────────────────────────────────────┘
                                          │
                       POST /v1/sso/launch/star_saathi  (AES-128 Hex)
                       POST /v1/sso/launch/star_sfa     (XML Recordset)
                       POST /v1/sso/launch/star_link    (JWT + Link Data)
                       POST /v1/sso/launch/star_steller (Engineer JSON)
```

---

## 🧪 Collection Setup & Environment Variables in EchoAPI

When you import [`echoapi-collection.json`](file:///d:/Acceleron/Star%20Cement/sso-laravel/echoapi-collection.json), the following collection variables are automatically configured:

| Variable | Default Value | Description |
|---|---|---|
| `base_url` | `http://localhost:8000/api` | API Base URL |
| `auth_token` | *(auto-populated)* | Saved from Callback or Verify OTP response |
| `test_otp` | *(auto-populated)* | Captured automatically from `send-otp` debug response |
| `skillmine_code`| `test_auth_code` | Temporary authorization code from Skillmine |

---

# 🚀 Section 1: Skillmine SSO Authentication (Flow A)

### 1.1 Get Skillmine Hosted Login URL
Generates the formatted Skillmine login URL with dynamic CSRF `state` and client ID.

* **Method:** `GET`
* **URL:** `{{base_url}}/v1/auth/skillmine/login-url`
* **Headers:** `Accept: application/json`

#### Success Response (`200 OK`):
```json
{
  "status": true,
  "state": "8a7f9b1c2d3e4f5a6b7c8d9e0f1a2b3c",
  "login_url": "https://smtcauth-api.starcement.co.in/authz-srv/authz?client_id=6eddc6ea-26b2-462a-9385-476175ce0ec9&response_type=code&scope=openid%20profile%20user_info_all&redirect_uri=http%3A%2F%2Flocalhost%3A8000%2Fapi%2Fv1%2Fauth%2Fcallback&groups_info=0&response_mode=query&prompt=none&state=8a7f9b1c2d3e4f5a6b7c8d9e0f1a2b3c"
}
```

---

### 1.2 Handle Skillmine Callback (Token Exchange & Role Resolution)
Called by the StarOne mobile/web app after Skillmine verifies the OTP and returns the authorization `code`.

* **Method:** `POST` (or `GET`)
* **URL:** `{{base_url}}/v1/auth/callback`
* **Headers:** 
  ```http
  Content-Type: application/json
  Accept: application/json
  ```
* **Request Body:**
```json
{
  "code": "AUTH_CODE_FROM_SKILLMINE"
}
```

#### Success Response (`200 OK`):
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

### 1.3 Federated Logout
Logs the user out of StarOne and provides the Skillmine End Session URL.

* **Method:** `POST`
* **URL:** `{{base_url}}/v1/auth/logout`
* **Headers:** 
  ```http
  Authorization: Bearer {{auth_token}}
  Content-Type: application/json
  ```
* **Request Body:**
```json
{
  "post_logout_redirect_uri": "https://starone.starcement.co.in/login"
}
```

#### Success Response (`200 OK`):
```json
{
  "status": true,
  "message": "Logged out successfully",
  "end_session_url": "https://smtcauth-api.starcement.co.in/token-srv/end_session?post_logout_redirect_uri=https%3A%2F%2Fstarone.starcement.co.in%2Flogin"
}
```

---

# ⚡ Section 2: Local Standalone OTP Authentication (Flow B)

Use this flow for offline testing, local unit testing, or automated CI pipelines.

---

### 2.1 Send OTP (Local)
* **Method:** `POST`
* **URL:** `{{base_url}}/v1/sso/send-otp`
* **Headers:** `Content-Type: application/json`
* **Request Body:**
```json
{
  "mobile_number": "9000000004"
}
```

#### Success Response (`200 OK`):
```json
{
  "status": true,
  "message": "OTP sent successfully",
  "debug_otp": "1234"
}
```
> **EchoAPI Test Script:** Automatically captures `debug_otp` and stores it into `{{test_otp}}`.

---

### 2.2 Verify OTP (Local)
* **Method:** `POST`
* **URL:** `{{base_url}}/v1/sso/verify-otp`
* **Headers:** `Content-Type: application/json`
* **Request Body:**
```json
{
  "mobile_number": "9000000004",
  "otp": "{{test_otp}}"
}
```

#### Success Response (`200 OK`):
```json
{
  "status": true,
  "star_one_session_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
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
    }
  ]
}
```
> **EchoAPI Test Script:** Automatically saves `star_one_session_token` into `{{auth_token}}`.

---

# 📦 Section 3: Product Launch Endpoints (Stage 2)

All launch requests require the Header: `Authorization: Bearer {{auth_token}}`.

---

### 3.1 Launch Star Saathi (Dealers / Sub-dealers)
* **Method:** `POST`
* **URL:** `{{base_url}}/v1/sso/launch/star_saathi`
* **Response Content-Type:** `text/html; charset=UTF-8`
* **Response Body:** Legacy AES-128-CBC Encrypted Hex String
```text
4a7f289c0b31e84719280dca8721e9014bca9921e...
```
*(Decrypted shape: `{"process_status":"YES","process_message":"OTP IS SUCCESSFULLY VERIFIED.","customer_code":"CUST1001","emp_name":"Ramesh Sharma","sale_access":"PRIMARY","acedns":"Y"}`)*

---

### 3.2 Launch Star SFA (Employees / Sales Officers)
* **Method:** `POST`
* **URL:** `{{base_url}}/v1/sso/launch/star_sfa`
* **Response Content-Type:** `text/xml; charset=utf-8`
* **Response Body:**
```xml
<recordset>
  <data>
    <emp_code><![CDATA[EMP1001]]></emp_code>
    <emp_name><![CDATA[Ramesh Sharma]]></emp_name>
    <sale_access><![CDATA[Primary]]></sale_access>
    <newpassword><![CDATA[1234]]></newpassword>
    <deviceid><![CDATA[]]></deviceid>
    <acedns><![CDATA[Y]]></acedns>
  </data>
</recordset>
```

---

### 3.3 Launch Star Link (Influencers / Contractors / Masons)
* **Method:** `POST`
* **URL:** `{{base_url}}/v1/sso/launch/star_link`
* **Response Content-Type:** `application/json`
* **Response Body:**
```json
{
  "status": true,
  "data": {
    "id": 1,
    "name": "Ramesh Sharma",
    "phone": "9000000004",
    "email": "ramesh@starcement.co.in",
    "role": 1,
    "role_name": "BDE",
    "points": 0,
    "status": 1
  },
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.starlink_native_token...",
  "msg": "Log in successfull"
}
```

---

### 3.4 Launch Star Stellar (Engineers / Technical Executives)
* **Method:** `POST`
* **URL:** `{{base_url}}/v1/sso/launch/star_steller`
* **Response Content-Type:** `application/json`
* **Response Body:**
```json
{
  "process_status": "YES",
  "process_message": "Success.",
  "user_type": "ENGINEER",
  "the_engineer_id": "1",
  "e_name": "Ramesh Sharma",
  "e_mobile": "9000000004",
  "te_code": "",
  "e_email": "ramesh@starcement.co.in",
  "e_profile_image": ""
}
```

---

# ⚠️ Section 4: Error Scenarios & Response Matrix

| Scenario | HTTP Status | Error Code | Response Message |
|---|:---:|---|---|
| **Unregistered Mobile** | `403` | `NO_AUTHORIZED_PRODUCTS` | `"No active authorized application roles found for this account."` |
| **Inactive Account** | `403` | `USER_INACTIVE` | `"User account is inactive. Please contact administrator."` |
| **Invalid / Expired Token** | `401` | `UNAUTHORIZED` | `"Unauthenticated."` |
| **Missing Product Access** | `403` | `FORBIDDEN` | `"User is not authorized to access this product."` |
| **Invalid Product Key** | `404` | `NOT_FOUND` | `"Product not found."` |