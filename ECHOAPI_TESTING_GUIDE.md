# SSO Laravel API - EchoAPI Testing Guide

This guide provides complete endpoint configurations, request/response payloads, and test scenarios for testing the SSO API using EchoAPI.

> **Import ready**: `echoapi-collection.json` (Postman v2.1 format) can be imported directly into EchoAPI — Import → Postman → select the file. It contains all requests below with correct seeded mobile numbers and auto-saves the auth token after `verify-otp`.

---

## Base Configuration

| Setting | Value |
|---------|-------|
| **Base URL** | `http://localhost:8000/api` (adjust for your environment) |
| **Auth Type** | Bearer Token (JWT) |
| **Content-Type** | `application/json` (except launch endpoints which vary) |

---

## API Endpoints

### 1. Send OTP
**POST** `/v1/sso/send-otp`

#### Request
```json
{
  "mobile_number": "9000000004"
}
```

#### Success Response (200)
```json
{
  "status": true,
  "message": "OTP sent successfully"
}
```

#### Error Responses
| Status | Response |
|--------|----------|
| 404 | `{"status": false, "message": "User not found"}` |
| 403 | `{"status": false, "message": "User account is inactive. Please contact administrator."}` |
| 422 | `{"status": false, "message": "Validation error", "errors": {...}}` |

---

### 2. Verify OTP
**POST** `/v1/sso/verify-otp`

#### Request
```json
{
  "mobile_number": "9000000004",
  "otp": "123456"
}
```

> **Note**: In testing environment, OTP is always `123456` (configured in `OtpService`)

#### Success Response (200) - Multi-Product User (Priya BDE)
```json
{
  "status": true,
  "star_one_session_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "name": "Priya BDE",
    "mobile": "9000000004",
    "emp_code": "EMP1001"
  },
  "allowed_products": [
    {
      "key": "star_sfa",
      "name": "Star SFA",
      "role": "BDE"
    },
    {
      "key": "star_saathi",
      "name": "Star Saathi",
      "role": "BDE"
    },
    {
      "key": "star_link",
      "name": "Star Link",
      "role": "BDE"
    },
    {
      "key": "star_steller",
      "name": "Star Stellar",
      "role": "BDE"
    }
  ]
}
```

#### Success Response (200) - Single Product User (Vikram Mason)
```json
{
  "status": true,
  "star_one_session_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 2,
    "name": "Vikram Mason",
    "mobile": "9000000003",
    "emp_code": "EMP2002"
  },
  "allowed_products": [
    {
      "key": "star_link",
      "name": "Star Link",
      "role": "Mason"
    }
  ]
}
```

#### Error Responses
| Status | Response |
|--------|----------|
| 401 | `{"status": false, "message": "Invalid or expired OTP", "code": "INVALID_OTP"}` |
| 401 | `{"status": false, "message": "OTP has expired", "code": "OTP_EXPIRED"}` |
| 422 | `{"status": false, "message": "Validation error", "errors": {...}}` |

---

### 3. Launch Product
**POST** `/v1/sso/launch/{productKey}`

**Headers:**
```
Authorization: Bearer {star_one_session_token}
Content-Type: application/json
```

**Product Keys:** `star_sfa`, `star_saathi`, `star_link`, `star_steller`

#### Response Formats by Product

---

## Product-Specific Response Payloads

### A. Star SFA (`star_sfa`)
**Content-Type:** `text/xml; charset=utf-8`

#### Multi-Product User (Priya BDE - BDE Role)
```xml
<?xml version='1.0' encoding='UTF-8'?><recordset><data>
<emp_code><![CDATA[EMP1001]]></emp_code>
<emp_name><![CDATA[Priya BDE]]></emp_name>
<sale_access><![CDATA[Primary]]></sale_access>
<newpassword><![CDATA[1234]]></newpassword>
<deviceid><![CDATA[DEV_SFA_1]]></deviceid>
<acedns><![CDATA[Y]]></acedns>
</data></recordset>
```

#### Single Product User (Arjun Sales - Sales Team Role)
```xml
<?xml version='1.0' encoding='UTF-8'?><recordset><data>
<emp_code><![CDATA[EMPXXXX]]></emp_code>
<emp_name><![CDATA[Arjun Sales]]></emp_name>
<sale_access><![CDATA[Primary]]></sale_access>
<newpassword><![CDATA[1234]]></newpassword>
<deviceid><![CDATA[DEV_5]]></deviceid>
<acedns><![CDATA[Y]]></acedns>
</data></recordset>
```

---

### B. Star Link (`star_link`)
**Content-Type:** `application/json`

#### Multi-Product User (Priya BDE - BDE Role)
```json
{
  "status": true,
  "data": {
    "id": 1,
    "name": "Priya BDE",
    "phone": "9000000004",
    "email": "priya@starone.com",
    "emp_code": "EMP1001",
    "role": 1,
    "role_name": "BDE",
    "category_id": null,
    "points": 0,
    "city": "Guwahati",
    "state": "",
    "status": 1,
    "dealers": [],
    "te": null,
    "mason": null,
    "mason_category": {
      "name": "General"
    }
  },
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "msg": "Log in successfull"
}
```

#### Single Product User (Vikram Mason - Mason Role)
```json
{
  "status": true,
  "data": {
    "id": 2,
    "name": "Vikram Mason",
    "phone": "9000000003",
    "email": "vikram@starone.com",
    "emp_code": "EMP2002",
    "role": 2,
    "role_name": "Mason",
    "category_id": null,
    "points": 0,
    "city": "Kolkata",
    "state": "",
    "status": 1,
    "dealers": [],
    "te": null,
    "mason": null,
    "mason_category": {
      "name": "General"
    }
  },
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "msg": "Log in successfull"
}
```

#### Seeded User (Rajesh Dealer - Dealer Role)
```json
{
  "status": true,
  "data": {
    "id": 3,
    "name": "Rajesh Dealer",
    "phone": "9000000001",
    "email": "rajesh.dealer@starone.com",
    "emp_code": "EMPxxxx",
    "role": 2,
    "role_name": "Dealer",
    "category_id": null,
    "points": 0,
    "city": "Kolkata",
    "state": "",
    "status": 1,
    "dealers": [],
    "te": null,
    "mason": null,
    "mason_category": {
      "name": "General"
    }
  },
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "msg": "Log in successfull"
}
```

---

### C. Star Saathi (`star_saathi`)
**Content-Type:** `text/plain; charset=utf-8`
**Response:** Encrypted Hex String (AES-128-CBC, zero-padding)

#### Decrypted Payload (Multi-Product User - Priya BDE)
```json
{
  "process_status": "YES",
  "process_message": "OTP IS SUCCESSFULLY VERIFIED.",
  "user_type": "dealer",
  "emp_code": "CUST1001",
  "customer_code": "CUST1001",
  "dns_emp_code": "DNS_1",
  "emp_name": "Priya BDE",
  "sale_access": "PRIMARY",
  "newpassword": "1234",
  "deviceid": "DEV_SAATHI_1",
  "phonenumber": "9000000004",
  "acedns": "Y",
  "broker_id": "",
  "dns_broker_id": "",
  "contact_person": "",
  "mail_id": "priya@starone.com",
  "brokerage_cost": "",
  "state_code": "",
  "the_profile_image_url": "",
  "belong_dealer_code": "",
  "belong_dealer_dns_code": "",
  "belong_dealer_name": "",
  "is_survey_form_submitted": "NO",
  "token": "<sha256_hash>"
}
```

#### Decrypted Payload (Seeded User - Rajesh Dealer)
```json
{
  "process_status": "YES",
  "process_message": "OTP IS SUCCESSFULLY VERIFIED.",
  "user_type": "dealer",
  "emp_code": "CUST_3",
  "customer_code": "CUST_3",
  "dns_emp_code": "DNS_3",
  "emp_name": "Rajesh Dealer",
  "sale_access": "PRIMARY",
  "newpassword": "1234",
  "deviceid": "DEV_3",
  "phonenumber": "9000000001",
  "acedns": "Y",
  "broker_id": "",
  "dns_broker_id": "",
  "contact_person": "",
  "mail_id": "rajesh.dealer@starone.com",
  "brokerage_cost": "",
  "state_code": "",
  "the_profile_image_url": "",
  "belong_dealer_code": "",
  "belong_dealer_dns_code": "",
  "belong_dealer_name": "",
  "is_survey_form_submitted": "NO",
  "token": "<sha256_hash>"
}
```

> **Decryption Helper**: Use the StarSaathiAdapter's `decrypt()` method or the following PHP code:
> ```php
> $adapter = new \App\Services\Sso\Adapters\StarSaathiAdapter();
> $decryptedJson = $adapter->decrypt($hexResponse);
> $payload = json_decode($decryptedJson, true);
> ```

---

### D. Star Stellar (`star_steller`)
**Content-Type:** `application/json`

#### Multi-Product User (Priya BDE - TE Type)
```json
{
  "process_status": "YES",
  "process_message": "Success.",
  "user_type": "TE",
  "the_te_id": "TE_101",
  "the_te_name": "Priya BDE",
  "the_te_code": "TE101",
  "the_te_mobile_no": "9000000004",
  "the_te_email": "priya@starone.com",
  "te_profile_image": ""
}
```

#### Seeded User (Amit Site Engineer - ENGINEER Type)
```json
{
  "process_status": "YES",
  "process_message": "Success.",
  "user_type": "ENGINEER",
  "the_engineer_id": "EN_2",
  "e_name": "Amit Site Engineer",
  "e_mobile": "9000000002",
  "te_code": "",
  "e_email": "amit.site.engineer@starone.com",
  "e_dob": "",
  "e_dom": "",
  "e_address": "Site A, Kolkata",
  "e_pin": "700001",
  "e_state": "",
  "e_city_town": "",
  "e_profile_image": ""
}
```

#### Seeded User (Priya BDE - TE Type)
```json
{
  "process_status": "YES",
  "process_message": "Success.",
  "user_type": "TE",
  "the_te_id": "TE_4",
  "the_te_name": "Priya BDE",
  "the_te_code": "EMPxxxx",
  "the_te_mobile_no": "9000000004",
  "the_te_email": "priya.bde@starone.com",
  "te_profile_image": ""
}
```

---

## Test User Matrix

| User | Mobile | Role | Products Access | Test Scenarios |
|------|--------|------|-----------------|----------------|
| **Priya BDE** | 9000000004 | BDE | star_steller, star_link, star_sfa | Multi-product flow |
| **Vikram Mason** | 9000000003 | Mason | star_link | Single product flow + 403 test on star_sfa |
| **Rajesh Dealer** | 9000000001 | Dealer | star_saathi | Dealer only (star_saathi) |
| **Amit Site Engineer** | 9000000002 | Site Engineer | star_steller | Engineer only (star_steller) |
| **Arjun Sales** | 9000000005 | Sales Team | star_sfa | Sales only (star_sfa) |

---

## EchoAPI Collection Setup

### Environment Variables
Create an EchoAPI environment with:
| Variable | Value |
|----------|-------|
| `base_url` | `http://localhost:8000/api` |
| `test_otp` | `123456` |

### Request Chain (Run in Order)

#### Step 1: Send OTP
```
POST {{base_url}}/v1/sso/send-otp
Content-Type: application/json

{
  "mobile_number": "{{mobile_number}}"
}
```
> Save `mobile_number` as variable for next steps

#### Step 2: Verify OTP
```
POST {{base_url}}/v1/sso/verify-otp
Content-Type: application/json

{
  "mobile_number": "{{mobile_number}}",
  "otp": "{{test_otp}}"
}
```
> **Extract & Save**: `star_one_session_token` from response as `auth_token`

#### Step 3: Launch Product (repeat for each allowed product)
```
POST {{base_url}}/v1/sso/launch/{{productKey}}
Authorization: Bearer {{auth_token}}
Content-Type: application/json
```
> Replace `{{productKey}}` with: `star_sfa`, `star_saathi`, `star_link`, `star_steller`

---

## Expected Error Scenarios

### 1. Unauthorized Product Access (403)
**Request**: Launch `star_sfa` with Vikram Mason's token (only has star_link)
**Response**:
```json
{
  "status": false,
  "message": "Unauthorized panel access"
}
```

### 2. Invalid Product Key (404)
**Request**: Launch `invalid_product`
**Response**: Laravel 404 (Product not found)

### 3. Expired/Invalid Token (401)
**Request**: Launch with expired/invalid token
**Response**:
```json
{
  "message": "Unauthenticated."
}
```

### 4. Inactive User (403 on send-otp)
**Request**: Send OTP for inactive user
**Response**:
```json
{
  "status": false,
  "message": "User account is inactive. Please contact administrator."
}
```

---

## Quick Test Script (cURL)

```bash
# 1. Send OTP
curl -X POST http://localhost:8000/api/v1/sso/send-otp \
  -H "Content-Type: application/json" \
  -d '{"mobile_number": "9000000004"}'

# 2. Verify OTP (save token)
curl -X POST http://localhost:8000/api/v1/sso/verify-otp \
  -H "Content-Type: application/json" \
  -d '{"mobile_number": "9000000004", "otp": "123456"}'

# 3. Launch Star SFA (XML response)
curl -X POST http://localhost:8000/api/v1/sso/launch/star_sfa \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: text/xml"

# 4. Launch Star Link (JSON response)
curl -X POST http://localhost:8000/api/v1/sso/launch/star_link \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"

# 5. Launch Star Saathi (Encrypted hex response)
curl -X POST http://localhost:8000/api/v1/sso/launch/star_saathi \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: text/plain"

# 6. Launch Star Stellar (JSON response)
curl -X POST http://localhost:8000/api/v1/sso/launch/star_steller \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

## Database Seeding

To populate test data:
```bash
php artisan db:seed --class=SsoTestDataSeeder
```

This creates 5 test users with the access matrix shown above.

---

## Notes for Team

1. **OTP in Testing**: Always `123456` - check `OtpService` for production configuration
2. **Star Saathi Encryption**: Response is encrypted hex string. Use adapter's `decrypt()` method to verify payload
3. **Star SFA Response**: Returns XML, not JSON - set `Accept: text/xml` header
4. **Authorization Middleware**: `EnsureProductAccess` checks if user has access to requested product
5. **JWT Token**: Returned in `verify-otp` as `star_one_session_token`, used for all launch requests

---

## EchoAPI Import

You can import this collection by creating requests in EchoAPI with the above specifications. Each product launch should be a separate request in your collection for easy testing.