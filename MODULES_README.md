# NamosaAPI - Implementation Summary

## ✅ Completed Components

### 1. Gibbon_OIDC Module (Authentication)
**Location:** `/workspace/modules/Gibbon_OIDC/`

- **`lib/JWTValidator.php`**: Validates JWT tokens from external IdentityProvider using JWKS endpoint
  - Supports RS256 signature verification
  - Caches JWKS keys for 1 hour
  - Validates issuer and audience claims
  - Requires phpseclib3 for JWK to PEM conversion

- **`lib/PermissionService.php`**: Loads user roles and permissions from Gibbon database
  - Queries `gibbonRole`, `gibbonPermission`, and `gibbonPerson` tables
  - Provides `hasPermission()` and `hasRole()` methods

- **`settings.php`**: Configuration UI for OIDC settings in Gibbon Admin
  - Stores IdP URL, client credentials, scopes
  - Auto-redirect option for SSO

### 2. NamosaAPI Module (RESTful API)
**Location:** `/workspace/modules/NamosaAPI/`

- **`lib/AuthMiddleware.php`**: Authentication middleware for API endpoints
  - Validates Bearer tokens from Authorization header
  - Extracts user ID from token claims
  - Loads user context with roles and permissions
  - Integrates with JWTValidator and PermissionService

- **`api/v1/config.php`**: Configuration loader
  - Reads OIDC settings from Gibbon database
  - Supports local override via `config.local.php`

- **`api/v1/students.php`**: GET /api/v1/students endpoint
  - Requires `students_read` permission or Admin role
  - Supports pagination (limit, offset)
  - Supports search filtering
  - Returns student data with enrolment info

- **`api/v1/staff.php`**: GET /api/v1/staff endpoint
  - Requires `staff_read` permission
  - Returns staff data with type and dates

- **`api/v1/courses.php`**: GET /api/v1/courses endpoint
  - Requires `courses_read` permission
  - Returns courses with class counts

- **`manifest.php`**: Module definition with permissions

### 3. Transport Module Enhancement
**Location:** `/workspace/modules/Transport/`

- **`lib/TransportAPIHandler.php`**: Dual authentication handler
  - Tries JWT authentication first (via NamosaAPI)
  - Falls back to legacy API key authentication
  - Provides unified permission checking

- **`api/v1/students.php`**: GET /api/v1/students (transport-specific)
  - Returns students assigned to transport routes
  - Filterable by routeID and date
  - Shows pickup/dropoff areas and seat numbers

---

## 🔧 Configuration Required

### Step 1: Install Modules in Gibbon
1. Go to **Admin > Manage Modules**
2. Install **Gibbon_OIDC** module first
3. Install **NamosaAPI** module
4. Install/Update **Transport** module

### Step 2: Configure OIDC Settings
In Gibbon Admin, navigate to the Gibbon_OIDC module settings:
- **IdP Base URL**: `https://144.91.66.114` (your .NET IdentityProvider)
- **Client ID**: (from your IdP registration)
- **Client Secret**: (from your IdP registration)
- **Scopes**: `openid profile email gibbon_id`
- **User ID Claim**: `sub` or `gibbon_person_id`

### Step 3: Ensure Token Claims
Your .NET IdentityProvider must include these claims in the JWT:
```json
{
  "sub": "123",              // Gibbon Person ID (numeric)
  "iss": "https://144.91.66.114",
  "aud": "namosa-api",
  "email": "user@school.edu",
  "name": "John Doe"
}
```

### Step 4: Test Endpoints

#### Using JWT (Recommended):
```bash
curl -X GET "https://gibbon.yourschool.com/modules/NamosaAPI/api/v1/students" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

#### Using API Key (Transport only):
```bash
curl -X GET "https://gibbon.yourschool.com/modules/Transport/api/v1/students" \
  -H "X-API-Key: YOUR_API_KEY_HERE"
```

---

## 📋 API Endpoints Summary

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `/api/v1/students` | GET | `students_read` | List students with pagination |
| `/api/v1/staff` | GET | `staff_read` | List staff members |
| `/api/v1/courses` | GET | `courses_read` | List courses by school year |
| `/transport/api/v1/students` | GET | `transport_read` | Students on bus routes |

### Query Parameters (all endpoints):
- `limit`: Max results (default: 50, max: 200)
- `offset`: Pagination offset (default: 0)
- `search`: Search term for name/email
- `status`: Filter by status (default: 'Full')

---

## 🚀 Next Steps for Moodle Integration

1. **Configure Moodle OIDC Plugin**:
   - Use the same IdP settings (`https://144.91.66.114`)
   - Map `sub` claim to Moodle username or custom field

2. **Create Moodle Web Service Functions**:
   - Call NamosaAPI endpoints from Moodle
   - Sync students, courses, enrolments bidirectionally

3. **Data Flow**:
   - **Gibbon → Moodle**: Students, courses, enrolments, staff
   - **Moodle → Gibbon**: Grades, attendance, assignments

---

## 🛡️ Security Features

- ✅ JWT validation with JWKS (RS256)
- ✅ Token expiration checking
- ✅ Issuer and audience validation
- ✅ Gibbon permission enforcement
- ✅ SQL injection prevention (prepared statements)
- ✅ CORS headers configured
- ✅ Dual auth support (JWT + API Key fallback)

---

## 📝 Notes

- The `phpseclib3` library is required for JWK to PEM conversion (usually included with Gibbon)
- JWKS keys are cached in `/tmp/gibbon_jwks_cache.json` for 1 hour
- All endpoints return consistent JSON structure with `success`, `data`, and `meta` fields
- Transport module maintains backward compatibility with existing API key users
