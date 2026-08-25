# SSO Laravel Demo

## 📋 Project Overview

This demo showcases a complete SSO workflow designed for implementation in a multi-product ecosystem. It demonstrates how a central authentication service can issue JWT tokens containing user identity, roles, and product access scope — enabling seamless authentication across multiple applications sharing the same user base.

### Key Features

| Feature | Description |
|---------|-------------|
| **OTP-based Authentication** | Secure mobile-number + OTP login flow (no passwords) |
| **JWT Token Issuance** | RS256/HS256 signed tokens with custom claims |
| **Role-Based Access Control** | Spatie Laravel Permission integration |
| **Multi-Product Access Scope** | Dynamic product access based on user roles |
| **Global Admin Support** | Super-admin role with access to all products |
| **Token Verification Middleware** | Stateless SSO token validation for downstream services |
| **Rate Limiting** | Built-in throttling on auth endpoints |
| **Soft Deletes & Audit Trail** | User lifecycle management |

---

## 🏗 Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            SSO AUTH SERVICE (This Demo)                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────────────────┐  │
│  │  Client App  │───▶│  Request OTP │───▶│  Generate & Send OTP via SMS │  │
│  │  (SPA/Mobile)│    │  (POST)      │    │  (Twilio/Provider Integration)│  │
│  └──────────────┘    └──────────────┘    └──────────────────────────────┘  │
│         │                   │                        │                      │
│         │                   ▼                        │                      │
│         │            ┌──────────────┐                │                      │
│         └───────────▶│  Verify OTP  │◀───────────────┘                      │
│                      │  (POST)      │                                         │
│                      └──────┬───────┘                                         │
│                             │                                                 │
│                             ▼                                                 │
│                    ┌─────────────────┐                                       │
│                    │  Issue JWT Token│                                       │
│                    │  (with claims)  │                                       │
│                    └────────┬────────┘                                       │
│                             │                                                 │
│                             ▼                                                 │
│                    ┌─────────────────┐                                       │
│                    │  Return Access  │                                       │
│                    │  Token + Scope  │                                       │
│                    └─────────────────┘                                       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
            ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
            │ Product 1   │ │ Product 2   │ │ Product N   │
            │ (verifySso  │ │ (verifySso  │ │ (verifySso  │
            │  Token MW)  │ │  Token MW)  │ │  Token MW)  │
            └─────────────┘ └─────────────┘ └─────────────┘
```

### Token Flow

1. **User requests OTP** → `POST /api/auth/request-otp`
2. **User submits OTP** → `POST /api/auth/verify-otp`
3. **Server validates OTP** → Issues JWT with claims:
   - `sub` (user ID)
   - `mobile_number`
   - `roles` (array of role names)
4. **Client stores token** → Sends `Authorization: Bearer <token>` on subsequent requests
5. **Downstream services** → Use `VerifySsoToken` middleware to validate token & extract user context

---

## 🛠 Tech Stack

| Component | Version | Purpose |
|-----------|---------|---------|
| **PHP** | ^8.3 | Runtime |
| **Laravel** | ^13.8 | Framework |
| **JWT Auth** | php-open-source-saver/jwt-auth ^2.9 | JWT token management |
| **Permissions** | spatie/laravel-permission ^8.3 | RBAC |
| **Sanctum** | ^4.0 | SPA authentication (optional) |
| **Database** | SQLite (dev) / MySQL/PostgreSQL (prod) | Data persistence |
| **Cache/Queue/Session** | Database driver | Dev simplicity |

---

## 📦 Installation & Setup

### Prerequisites

- PHP 8.3+
- Composer 2.x
- Node.js 20+ & npm (for Vite frontend assets)
- SQLite (default) or MySQL/PostgreSQL

### Quick Start

```bash
# 1. Clone the repository
git clone <your-github-repo-url>
cd sso-laravel

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies & build assets
npm install
npm run build

# 4. Environment configuration
cp .env.example .env
php artisan key:generate
php artisan jwt:secret

# 5. Run migrations & seeders
php artisan migrate --seed

# 6. Start development server
php artisan serve
# Or use the full dev stack (server + queue + logs + vite)
composer run dev
```

### Environment Variables

```env
# Application
APP_NAME="SSO Demo"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database (SQLite for demo)
DB_CONNECTION=sqlite
# For MySQL:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=sso_demo
# DB_USERNAME=root
# DB_PASSWORD=

# JWT Configuration
JWT_SECRET=your-generated-secret        # Run: php artisan jwt:secret
JWT_TTL=60                              # Token lifetime (minutes)
JWT_REFRESH_TTL=20160                   # Refresh window (2 weeks)
JWT_ALGO=HS256                          # Or RS256 for asymmetric
JWT_BLACKLIST_ENABLED=true

# Sanctum (for SPA)
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:8000

# SMS Gateway (configure for production)
# SMS_PROVIDER=twilio
# TWILIO_SID=
# TWILIO_TOKEN=
# TWILIO_FROM=
```

---

## 🔐 API Endpoints

### Public Endpoints

| Method | Endpoint | Description | Rate Limit |
|--------|----------|-------------|------------|
| `POST` | `/api/auth/request-otp` | Request OTP for mobile number | 5/min |
| `POST` | `/api/auth/verify-otp` | Verify OTP & receive JWT | 10/min |

### Protected Endpoints (require `Authorization: Bearer <token>`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/auth/me` | Get authenticated user + access scope |
| `POST` | `/api/auth/logout` | Invalidate current token (blacklist) |

---

### Request/Response Examples

#### Request OTP
```bash
curl -X POST http://localhost:8000/api/auth/request-otp \
  -H "Content-Type: application/json" \
  -d '{"mobile_number": "+919876543210"}'
```

**Success Response (200):**
```json
{
  "status": "success",
  "message": "OTP sent successfully.",
  "data": {
    "mobile_number": "+919876543210",
    "expires_in": 300,
    "retry_after": 60,
    "otp_length": 6
  }
}
```

**Error - User Not Found (404):**
```json
{
  "status": "error",
  "message": "User not registered.",
  "error_code": "USER_NOT_FOUND",
  "data": null
}
```

---

#### Verify OTP
```bash
curl -X POST http://localhost:8000/api/auth/verify-otp \
  -H "Content-Type: application/json" \
  -d '{"mobile_number": "+919876543210", "otp_code": "<code-from-log>"}'
```

**Success Response (200):**
```json
{
  "status": "success",
  "message": "Authentication successful.",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user": {
      "id": 1,
      "mobile_number": "+919876543210",
      "name": "Global Admin",
      "email": "admin@company.com",
      "roles": ["global_admin"],
      "access_scope": {
        "is_global": true,
        "allowed_products": [
          {"slug": "product_1", "redirect_url": "https://product1.company.com/dashboard"},
          {"slug": "product_2", "redirect_url": "https://product2.company.com/dashboard"}
        ],
        "default_redirect": null
      }
    }
  }
}
```

**Error - Invalid OTP (401):**
```json
{
  "status": "error",
  "message": "Invalid OTP code.",
  "error_code": "INVALID_OTP",
  "data": {"attempts_remaining": 2}
}
```

---

#### Get Current User (Protected)
```bash
curl -X GET http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer <access_token>"
```

**Response (200):**
```json
{
  "status": "success",
  "data": {
    "user": {...},
    "roles": ["product_1_user"],
    "access_scope": {
      "is_global": false,
      "allowed_products": [
        {"slug": "product_1", "redirect_url": "https://product1.company.com/dashboard"}
      ],
      "default_redirect": "https://product1.company.com/dashboard"
    }
  }
}
```

---

#### Logout (Protected)
```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer <access_token>"
```

---

## 🗄 Database Schema

### Core Tables

| Table | Purpose |
|-------|---------|
| `users` | User accounts (mobile_number, name, email, is_active) |
| `otps` | OTP codes with expiry & attempt tracking |
| `products` | Registered products/services (slug, redirect_url) |
| `roles` | Spatie roles (global_admin, product_X_user) |
| `model_has_roles` | User ↔ Role mapping |
| `permissions` | Fine-grained permissions (optional) |
| `sessions` | Laravel session storage |
| `cache` / `jobs` | Framework internals |

### Key Relationships

```
users
  │
  ├── hasMany → otps (mobile_number)
  │
  └── belongsToMany → roles (via model_has_roles)
                        │
                        └── name pattern: "global_admin" | "product_{slug}_user" | "product_{slug}_admin"

products
  │
  └── slug matches role suffix (e.g., "product_1" ← "product_1_user")
```

---

## 🎭 Role & Access Scope System

### Role Naming Convention

| Role Pattern | Access Level | Description |
|--------------|--------------|-------------|
| `global_admin` | Global | Access to ALL products |
| `product_{slug}_user` | Product-specific | Standard user for a product |
| `product_{slug}_admin` | Product-specific | Admin for a product |

### Access Scope Response

The `access_scope` object in JWT and `/me` response:

```json
{
  "is_global": true|false,
  "allowed_products": [
    {"slug": "product_1", "redirect_url": "https://product1.company.com/dashboard"}
  ],
  "default_redirect": "https://product1.company.com/dashboard"  // null for global_admin
}
```

- **Global Admin**: `is_global: true`, `allowed_products` = all active products, `default_redirect: null`
- **Product User**: `is_global: false`, `allowed_products` = only permitted products, `default_redirect` = first product's URL

---

## 🛡 SSO Token Verification (For Downstream Services)

Other services in your ecosystem can verify SSO tokens using the included middleware.

### Middleware: `VerifySsoToken`

```php
// app/Http/Middleware/VerifySsoToken.php
public function handle(Request $request, Closure $next)
{
    try {
        $payload = JWTAuth::parseToken()->getPayload();
        
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
```

### Usage in Downstream Service

```php
// routes/api.php
Route::middleware('sso.token')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// In Controller
public function index(Request $request)
{
    $userId = $request->attributes->get('sso_user_id');
    $mobile = $request->attributes->get('mobile_number');
    $roles  = $request->attributes->get('roles');
    
    // Authorize based on roles
    if (in_array('global_admin', $roles)) { /* ... */ }
}
```

### Shared Secret Requirement

All services validating tokens **must share the same `JWT_SECRET`** (HS256) or have access to the public key (RS256).

---

## 🧪 Testing

### Run Tests

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage

# Run specific test file
php artisan test tests/Feature/AuthTest.php
```

### Demo Credentials (Seeded)

| User | Mobile | Role |
|------|--------|------|
| Global Admin | +919876543210 | global_admin |
| Product 1 User | +919123456789 | product_1_user |

> **Note**: OTPs are always dynamically generated (4-digit legacy algorithm) — no static codes exist in any environment. In `local`/`testing`, the SMS body with the code is logged to `storage/logs/laravel.log` instead of being sent (see `OtpService::generateOtp()`).

---

## 🚀 Production Deployment Checklist

### Security
- [ ] Use **RS256 asymmetric keys** instead of HS256
- [ ] Store keys in secure vault (AWS KMS, HashiCorp Vault)
- [ ] Enable HTTPS only (`APP_URL=https://...`)
- [ ] Set `JWT_BLACKLIST_ENABLED=true` with Redis cache
- [ ] Configure `SANCTUM_STATEFUL_DOMAINS` for your domains
- [ ] Implement real SMS gateway (Twilio, Vonage, etc.)

### Performance
- [ ] Use Redis for cache/session/queue
- [ ] Enable OPcache & JIT
- [ ] Configure queue workers (`php artisan queue:work --daemon`)
- [ ] Set up Horizon for queue monitoring

### Observability
- [ ] Configure logging (Laravel Pail, Sentry, Datadog)
- [ ] Set up health checks (`/up` endpoint)
- [ ] Monitor JWT token issuance/validation metrics

### Database
- [ ] Use MySQL/PostgreSQL with connection pooling
- [ ] Run migrations in CI/CD pipeline
- [ ] Set up automated backups

---

## 📁 Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── AuthController.php       # Auth endpoints
│   └── Middleware/
│       └── VerifySsoToken.php           # SSO token validation
├── Models/
│   ├── User.php                         # JWTSubject + HasRoles
│   ├── Otp.php                          # OTP model
│   └── Product.php                      # Product model
├── Services/
│   ├── OtpService.php                   # OTP generation/validation
│   ├── SmsService.php                   # SMS gateway abstraction
│   └── AccessScopeService.php           # Role → product mapping
└── Providers/
    └── AppServiceProvider.php

config/
├── auth.php                             # JWT guard configuration
├── jwt.php                              # JWT settings
├── permission.php                       # Spatie permissions
└── sanctum.php                          # Sanctum config

database/
├── migrations/
│   ├── create_users_table.php
│   ├── create_otps_table.php
│   ├── create_products_table.php
│   └── create_permission_tables.php
└── seeders/
    └── SsoInitialSeeder.php             # Demo data

routes/
├── api.php                              # API routes
└── web.php                              # Web routes
```

---

## 🔧 Extending the Demo

### Add New Product
```bash
# 1. Add product to database
php artisan tinker
>>> App\Models\Product::create(['slug' => 'product_5', 'redirect_url' => 'https://product5.com/dashboard']);

# 2. Create corresponding role
>>> Spatie\Permission\Models\Role::create(['name' => 'product_5_user', 'guard_name' => 'api']);

# 3. Assign to user
>>> $user = App\Models\User::find(2);
>>> $user->assignRole('product_5_user');
```

### Customize OTP Length/Expiry
```php
// In OtpService::generateOtp()
$code = (string) random_int(100000, 999999); // 6 digits
$expiryMinutes = 5; // Change as needed
```

### Add Permissions
```php
// Create permissions
Permission::create(['name' => 'view-dashboard', 'guard_name' => 'api']);

// Assign to role
$role = Role::findByName('product_1_user', 'api');
$role->givePermissionTo('view-dashboard');

// Check in controller
if ($user->can('view-dashboard')) { ... }
```

---

## 🤝 Integration Guide for Other Projects

### 1. Copy Core Components
- `app/Http/Middleware/VerifySsoToken.php` → Your service
- `config/jwt.php` → Your service (share `JWT_SECRET`)

### 2. Configure Auth Guard
```php
// config/auth.php
'guards' => [
    'api' => [
        'driver' => 'jwt',
        'provider' => 'users',
    ],
],
```

### 3. Apply Middleware
```php
// In your routes/api.php
Route::middleware('sso.token')->group(function () {
    // Your protected routes
});
```

### 4. Share User Provider (Optional)
If using same database, point `users` provider to same table. Otherwise, implement custom user provider that fetches from SSO service.

---

## 📝 License

MIT License - Feel free to use this demo as a starting point for your SSO implementation.

---

## 🙋 Support

For questions about this demo:
- Create an issue in the GitHub repository
- Review the [Laravel JWT Auth docs](https://jwt-auth.readthedocs.io/)
- Review [Spatie Laravel Permission docs](https://spatie.be/docs/laravel-permission)

---

**Built as a reference implementation for SSO architecture discussion.** 🚀