# SSO Laravel API Documentation

## 1. Overview & General Specifications

- **Base URL:** `http://localhost:8000/api` *(Adjust according to environment)*
- **API Version:** `v1`
- **Default Headers:**
  ```http
  Accept: application/json
  Content-Type: application/json
  ```
- **Authentication Scheme:** `Bearer <JWT_TOKEN>` (passed in the `Authorization` header for protected endpoints).

---

## 2. API Endpoints

### 2.1. Send OTP (Initiate Login)
Generates and sends a 4-digit numeric OTP to the registered mobile number.

- **Method:** `POST`
- **Path:** `/v1/sso/send-otp`
- **Auth Required:** No

#### Request Headers
```http
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "mobile_number": "9000000004"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `mobile_number` | string | Yes | 10-digit registered mobile number |

#### Response — 200 OK (Success)
```json
{
  "status": true,
  "message": "OTP sent successfully"
}
```

#### Error Responses
- **404 Not Found (User not registered or no products)**
  ```json
  {
    "status": false,
    "message": "User not registered or has no active product access."
  }
  ```
- **403 Forbidden (Inactive User)**
  ```json
  {
    "status": false,
    "message": "User account is inactive. Please contact administrator."
  }
  ```
- **422 Unprocessable Entity (Validation Error)**
  ```json
  {
    "message": "The mobile number field is required.",
    "errors": {
      "mobile_number": [
        "The mobile number field is required."
      ]
    }
  }
  ```

---

### 2.2. Verify OTP & Fetch Accessible Products
Validates the OTP and returns the central session token along with the list of applications/roles the user is authorized to access.

- **Method:** `POST`
- **Path:** `/v1/sso/verify-otp`
- **Auth Required:** No

#### Request Headers
```http
Content-Type: application/json
Accept: application/json
```

#### Request Body
```json
{
  "mobile_number": "9000000004",
  "otp": "1234"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `mobile_number` | string | Yes | Registered mobile number |
| `otp` | string | Yes | 4-digit OTP code |

#### Response — 200 OK (Success)
```json
{
  "status": true,
  "star_one_session_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOi...",
  "user": {
    "id": 1,
    "name": "Priya Sharma",
    "mobile": "9000000004",
    "emp_code": "EMP1001"
  },
  "allowed_products": [
    {
      "key": "star_link",
      "name": "Star Link",
      "role": "BDE"
    },
    {
      "key": "star_steller",
      "name": "Star Stellar",
      "role": "TE"
    },
    {
      "key": "star_sfa",
      "name": "Star SFA",
      "role": "Sales Team"
    },
    {
      "key": "star_saathi",
      "name": "Star Saathi",
      "role": "Dealer"
    }
  ]
}
```

#### Response Field Breakdown
- `star_one_session_token`: JWT token required for subsequent `/launch/{productKey}` API calls.
- `allowed_products`: Array of accessible apps.
  - `key`: The identifier slug to pass to the launch route (`star_link`, `star_steller`, `star_sfa`, `star_saathi`).
  - `name`: Display name of the product.
  - `role`: The authorized role assigned to the user for that product.

#### Error Responses
- **401 Unauthorized (Invalid OTP / Attempts Remaining)**
  ```json
  {
    "status": false,
    "message": "Invalid OTP code.",
    "code": "INVALID_OTP",
    "attempts_remaining": 2
  }
  ```
- **401 Unauthorized (Expired OTP)**
  ```json
  {
    "status": false,
    "message": "OTP has expired. Please request a new one.",
    "code": "OTP_EXPIRED"
  }
  ```
- **401 Unauthorized (Max Attempts Exceeded)**
  ```json
  {
    "status": false,
    "message": "Maximum OTP attempts exceeded. Please request a new OTP.",
    "code": "MAX_ATTEMPTS_EXCEEDED"
  }
  ```
- **403 Forbidden (No Active Product Roles)**
  ```json
  {
    "status": false,
    "message": "No active authorized application roles found for this account.",
    "code": "NO_AUTHORIZED_PRODUCTS"
  }
  ```

---

### 2.3. Launch Product (SSO Hand-off)
Authorizes and retrieves application-specific legacy session payload for seamless handoff / launch.

- **Method:** `POST`
- **Path:** `/v1/sso/launch/{productKey}`
- **Auth Required:** Yes (`Bearer <star_one_session_token>`)
- **Supported `productKey` values:**
  - `star_link`
  - `star_steller`
  - `star_sfa`
  - `star_saathi`

#### Request Headers
```http
Authorization: Bearer <star_one_session_token>
Content-Type: application/json
```

#### Request Body
*None (Empty body)*

---

#### 2.3.1 Product: Star Link (`star_link`)
- **Response Content-Type:** `application/json`
- **Status:** `200 OK`

```json
{
  "status": true,
  "data": {
    "id": 1,
    "name": "Priya Sharma",
    "phone": "9000000004",
    "email": "priya@starone.com",
    "emp_code": "EMP1001",
    "role": 1,
    "role_name": "BDE",
    "category_id": null,
    "points": 0,
    "city": "Guwahati",
    "state": "Assam",
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

#### 2.3.2 Product: Star Stellar (`star_steller`)
- **Response Content-Type:** `application/json`
- **Status:** `200 OK`

##### Schema for User Type `TE` (Technical Executive):
```json
{
  "process_status": "YES",
  "process_message": "Success.",
  "user_type": "TE",
  "the_te_id": "101",
  "the_te_name": "Priya Sharma",
  "the_te_code": "TE101",
  "the_te_mobile_no": "9000000004",
  "the_te_email": "priya@starone.com",
  "te_profile_image": ""
}
```

##### Schema for User Type `ENGINEER` (Site Engineer):
```json
{
  "process_status": "YES",
  "process_message": "Success.",
  "user_type": "ENGINEER",
  "the_engineer_id": "2",
  "e_name": "Amit Site Engineer",
  "e_mobile": "9000000002",
  "te_code": "",
  "e_email": "amit@starone.com",
  "e_dob": "1990-01-01",
  "e_dom": "",
  "e_address": "Site A, Kolkata",
  "e_pin": "700001",
  "e_state": "WB",
  "e_city_town": "Kolkata",
  "e_profile_image": ""
}
```

---

#### 2.3.3 Product: Star SFA (`star_sfa`)
- **Response Content-Type:** `text/xml; charset=utf-8`
- **Status:** `200 OK`

```xml
<?xml version='1.0' encoding='UTF-8'?>
<recordset>
  <data>
    <emp_code><![CDATA[EMP1001]]></emp_code>
    <emp_name><![CDATA[Priya Sharma]]></emp_name>
    <sale_access><![CDATA[Primary]]></sale_access>
    <newpassword><![CDATA[1234]]></newpassword>
    <deviceid><![CDATA[DEV_SFA_1]]></deviceid>
    <acedns><![CDATA[Y]]></acedns>
  </data>
</recordset>
```

---

#### 2.3.4 Product: Star Saathi (`star_saathi`)
- **Response Content-Type:** `text/plain; charset=utf-8`
- **Status:** `200 OK`
- **Response Body:** AES-128-CBC Encrypted Hex String

```text
7c0a9611f7c32bf7e0344d... (Hex-encoded string)
```

##### Decrypted JSON Structure:
```json
{
  "process_status": "YES",
  "process_message": "OTP IS SUCCESSFULLY VERIFIED.",
  "user_type": "dealer",
  "emp_code": "CUST1001",
  "customer_code": "CUST1001",
  "dns_emp_code": "DNS_1",
  "emp_name": "Priya Sharma",
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
  "token": "79bc8f42d2506e23b2075fc985da..."
}
```

##### Decryption Specification for Mobile/Web Client:
- **Cipher:** `AES-128-CBC`
- **Key (16 bytes):** `0123456789abcdef`
- **IV (16 bytes):** `fedcba9876543210`
- **Padding:** Zero padding (NULL byte `\0` padding)
- **Input:** Convert response hex string to binary, decrypt, and trim trailing `\0` bytes.

---

### 2.4. Common Launch Error Responses

- **401 Unauthorized (Missing / Expired Bearer Token)**
  ```json
  {
    "message": "Unauthenticated."
  }
  ```

- **403 Forbidden (Unauthorized Product Access)**
  ```json
  {
    "status": false,
    "message": "Unauthorized panel access"
  }
  ```

- **404 Not Found (Invalid Product Key)**
  ```json
  {
    "status": false,
    "message": "Invalid product key"
  }
  ```