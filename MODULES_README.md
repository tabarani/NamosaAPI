# NamosaAPI Ecosystem - Complete Implementation Guide

## Overview

This implementation provides:
1. **SSO Integration** - Users login once via .NET IdentityProvider, access both Gibbon and Moodle
2. **RESTful APIs** - Secure endpoints for students, courses, staff, transport with JWT authentication
3. **Moodle Sync** - Automatic user/course/enrollment sync from Gibbon (source of truth) to Moodle
4. **Integration Dashboard** - Manual sync, batch operations, scheduling, and monitoring

---

## Architecture

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Moodle        │────▶│  .NET Identity   │◀────│   Gibbon Core   │
│   (Grades, etc) │ OIDC│   Provider       │ OIDC │   + NamosaAPI   │
└─────────────────┘     └──────────────────┘     └────────┬────────┘
                                                          │
                        ┌─────────────────────────────────┼─────────────────────────────────┐
                        │                                 │                                 │
                        ▼                                 ▼                                 ▼
              ┌──────────────────┐            ┌──────────────────┐            ┌──────────────────┐
              │ /api/v1/students │            │ /api/v1/courses  │            │ /transport/api/  │
              │ /api/v1/staff    │            │ /api/v1/classes  │            │ - routes         │
              │ /api/v1/families │            │ /api/v1/enrol    │            │ - students       │
              └──────────────────┘            └──────────────────┘            │ - events         │
                                                                              │ - alerts         │
                                                                              └──────────────────┘
```

---

## Module Structure

### 1. Gibbon_OIDC Module (`/modules/Gibbon_OIDC/`)
Handles SSO authentication flow.

**Files:**
- `lib/JWTValidator.php` - Validates JWT tokens using JWKS
- `lib/PermissionService.php` - Loads Gibbon permissions
- `src/OidcHelper.php` - OIDC helper utilities
- `login.php` - Redirects to IdP
- `callback.php` - Handles IdP callback
- `logout.php` - Session termination
- `settings.php` - Configuration UI

**Configuration:**
- IdP Base URL: `https://144.91.66.114`
- JWKS Endpoint: `{idpUrl}/.well-known/jwks.json`
- Authorization Endpoint: `{idpUrl}/connect/authorize`
- Token Endpoint: `{idpUrl}/connect/token`
- Client ID & Secret (from IdP)
- Scopes: `openid profile email gibbon_id`

---

### 2. NamosaAPI Module (`/modules/NamosaAPI/`)
Core REST API with Moodle integration.

**Files:**
- `lib/JWTValidator.php` - Reusable JWT validation
- `lib/PermissionService.php` - Permission checking
- `src/Moodle/MoodleSyncService.php` - Moodle sync logic
- `src/Moodle/SyncLogger.php` - Sync operation logging
- `api/v1/config.php` - Configuration loader
- `api/v1/students.php` - GET students endpoint
- `api/v1/staff.php` - GET staff endpoint
- `api/v1/courses.php` - GET courses endpoint
- `pages/moodle_dashboard.php` - Integration dashboard
- `pages/moodle_sync_users.php` - User sync UI
- `pages/moodle_sync_courses.php` - Course sync UI
- `pages/moodle_batch_sync.php` - Batch operations
- `pages/moodle_schedules.php` - Cron schedule management

**API Endpoints:**

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `/api/v1/students` | GET | `students_read` | List students (paginated, filterable) |
| `/api/v1/staff` | GET | `staff_read` | List staff members |
| `/api/v1/courses` | GET | `courses_read` | List courses with enrollment |
| `/api/v1/sync/user/{id}` | POST | `moodle_sync` | Sync single user to Moodle |
| `/api/v1/sync/course/{id}` | POST | `moodle_sync` | Sync course to Moodle |
| `/api/v1/sync/batch` | POST | `moodle_sync` | Batch sync users |

**Query Parameters:**
- `?limit=50&offset=0` - Pagination
- `?search=term` - Search by name/email
- `?status=Full` - Filter by status
- `?gibbonYearID=XX` - Filter by academic year

---

### 3. Transport Module (`/modules/Transport/`)
Bus transportation management with REST API.

**Files:**
- `api/v1/index.php` - Main API router
- `lib/TransportAPIHandler.php` - Request handler with dual auth

**API Endpoints:**

| Endpoint | Method | Permission | Description |
|----------|--------|------------|-------------|
| `/api/v1/routes` | GET | `transport_view` | List bus routes |
| `/api/v1/routes/{id}` | GET | `transport_view` | Get route details |
| `/api/v1/students` | GET | `transport_view` | Students on transport |
| `/api/v1/stops` | GET | `transport_view` | All bus stops |
| `/api/v1/events` | GET | `transport_view` | Today's check-in/out |
| `/api/v1/events` | POST | `transport_manage` | Record event |
| `/api/v1/alerts` | GET | `transport_manage` | Safety alerts |
| `/api/v1/alerts` | POST | `transport_manage` | Create alert |

**Authentication:**
- JWT Bearer Token (preferred)
- Legacy API Key (backward compatible)

---

## Installation Steps

### Step 1: Configure IdentityProvider (.NET Core)

Ensure your IdP issues tokens with:
```json
{
  "sub": "123",           // Gibbon Person ID
  "iss": "https://144.91.66.114",
  "aud": "namosa-api",
  "gibbon_person_id": "123",  // Custom claim (optional)
  "email": "user@school.com",
  "name": "John Doe"
}
```

JWKS endpoint must be accessible at: `https://144.91.66.114/.well-known/jwks.json`

---

### Step 2: Install Gibbon_OIDC Module

1. Copy `/modules/Gibbon_OIDC` to your Gibbon installation
2. In Gibbon Admin → Manage Modules → Install "Gibbon OIDC"
3. Configure settings:
   - **IdP Base URL**: `https://144.91.66.114`
   - **Client ID**: (from IdP registration)
   - **Client Secret**: (from IdP registration)
   - **Scopes**: `openid profile email gibbon_id`
   - **Auto-redirect**: Yes (for seamless SSO)

---

### Step 3: Install NamosaAPI Module

1. Copy `/modules/NamosaAPI` to your Gibbon installation
2. In Gibbon Admin → Manage Modules → Install "NamosaAPI"
3. Configure Moodle integration:
   - **Moodle URL**: `https://moodle.yourschool.com`
   - **Moodle Web Service Token**: (Create in Moodle: Site admin → Web services → Manage tokens)
   - **Default Role Mapping**: Map Gibbon roles to Moodle roles

**Required Moodle Web Service Functions:**
- `core_user_get_users_by_field`
- `core_user_create_users`
- `core_user_update_users`
- `core_course_get_courses_by_field`
- `core_course_create_courses`
- `core_course_update_courses`
- `enrol_manual_enrol_users`

---

### Step 4: Install Transport Module

1. Copy `/modules/Transport` to your Gibbon installation
2. In Gibbon Admin → Manage Modules → Install "Transport"
3. Enable API access in module settings
4. (Optional) Create API keys for legacy systems

---

### Step 5: Configure Moodle for SSO

1. Install **OpenID Connect Authentication Plugin** in Moodle
2. Configure:
   - **Issuer URL**: `https://144.91.66.114`
   - **Client ID**: (same as Gibbon)
   - **Client Secret**: (same as Gibbon)
   - **User ID Claim**: `sub` or `gibbon_person_id`
3. Map fields:
   - Email → email
   - First Name → firstname
   - Last Name → lastname
   - ID Number → idnumber (must match Gibbon Person ID)

---

## Usage Guide

### SSO Flow

**Scenario A: User logs into Gibbon first**
1. User visits `https://gibbon.school`
2. Redirected to IdP login (`https://144.91.66.114/connect/authorize`)
3. User authenticates
4. Redirected back to Gibbon with token
5. Gibbon creates session, user is logged in
6. User clicks Moodle icon → Moodle recognizes IdP session → auto-login

**Scenario B: User logs into Moodle first**
1. User visits Moodle
2. Redirected to same IdP login
3. User authenticates (session already exists if recently logged in elsewhere)
4. Moodle creates session
5. Later, user visits Gibbon → IdP session exists → auto-login to Gibbon

---

### API Usage Examples

#### 1. Get Students (with JWT)

```bash
# Get token from IdP first
TOKEN=$(curl -X POST https://144.91.66.114/connect/token \
  -d "grant_type=password" \
  -d "username=user@school.com" \
  -d "password=secret" \
  -d "client_id=namosa-api" \
  -d "client_secret=xxx" | jq -r '.access_token')

# Call API
curl -H "Authorization: Bearer $TOKEN" \
     "https://gibbon.school/modules/NamosaAPI/api/v1/students?limit=50&search=smith"
```

**Response:**
```json
{
  "data": [
    {
      "gibbonPersonID": 123,
      "surname": "Smith",
      "preferredName": "John",
      "email": "john.smith@school.com",
      "status": "Full",
      "gradeLevel": "Grade 10"
    }
  ],
  "pagination": {
    "total": 1,
    "limit": 50,
    "offset": 0
  }
}
```

#### 2. Sync User to Moodle

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
     "https://gibbon.school/modules/NamosaAPI/api/v1/sync/user/123"
```

**Response:**
```json
{
  "success": true,
  "message": "User synced successfully",
  "moodleUserId": 456,
  "action": "created"
}
```

#### 3. Batch Sync Users

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"personIDs": [123, 124, 125]}' \
     "https://gibbon.school/modules/NamosaAPI/api/v1/sync/batch"
```

#### 4. Transport API (with API Key)

```bash
curl "https://gibbon.school/modules/Transport/api/v1/students?api_key=YOUR_API_KEY"
```

---

### Integration Dashboard

Access: `https://gibbon.school/index.php?q=/modules/NamosaAPI/pages/moodle_dashboard.php`

**Features:**
1. **Statistics Panel** - View sync success/failure rates
2. **Quick Actions** - One-click sync for users, courses, enrollments
3. **Batch Operations** - Select multiple users/courses for sync
4. **Schedule Management** - Configure automated sync jobs (via cron)
5. **Activity Logs** - Real-time view of all sync operations

**Setting Up Scheduled Sync:**

Add to server crontab:
```bash
# Sync users every hour
0 * * * * curl "https://gibbon.school/modules/NamosaAPI/api/v1/sync/scheduled?type=users"

# Sync courses daily at 2 AM
0 2 * * * curl "https://gibbon.school/modules/NamosaAPI/api/v1/sync/scheduled?type=courses"

# Sync enrollments every 30 minutes
*/30 * * * * curl "https://gibbon.school/modules/NamosaAPI/api/v1/sync/scheduled?type=enrollments"
```

---

## Moodle Integration Use Cases

### 1. User Provisioning
- New student enrolled in Gibbon → Auto-created in Moodle via sync
- Staff member role changed → Updated in Moodle
- Student leaves (status ≠ Full) → Suspended in Moodle

### 2. Course Sync
- Course created in Gibbon → Created in Moodle with correct category
- Course name/description updated → Reflected in Moodle

### 3. Enrollment Sync
- Student added to course class in Gibbon → Enrolled in Moodle course
- Student removed from class → Unenrolled in Moodle

### 4. Grade Flow (Future)
- Grades entered in Moodle → Pushed to Gibbon via webhook/API
- Gibbon remains source of truth for student data
- Moodle remains source of truth for assessment data

---

## Security Considerations

1. **JWT Validation**
   - Always verify token signature using JWKS
   - Check `exp`, `iat`, `iss`, `aud` claims
   - Cache JWKS keys (1 hour TTL)

2. **Permissions**
   - Every API call checks Gibbon permissions
   - Minimum privilege principle

3. **CORS**
   - Configure allowed origins in production
   - Currently set to `*` for development

4. **Rate Limiting**
   - Implement rate limiting for API endpoints (future enhancement)

5. **HTTPS**
   - Always use HTTPS in production
   - Configure SSL verification for Moodle API calls

---

## Troubleshooting

### SSO Not Working
- Verify IdP URL is accessible from Gibbon server
- Check client credentials match IdP configuration
- Ensure token includes `sub` or `gibbon_person_id` claim
- Check browser console for redirect errors

### Moodle Sync Failing
- Verify Moodle web service token is valid
- Check required web service functions are enabled
- Review sync logs in dashboard for specific errors
- Ensure Moodle user field `idnumber` matches Gibbon Person ID

### API Returns 401
- Token may be expired (check `exp` claim)
- JWKS endpoint unreachable
- Incorrect issuer/audience configuration

### Transport API Issues
- Verify API key is active in database
- Check user has `transport_view` or `transport_manage` permission
- Review error messages in JSON response

---

## Next Steps / Future Enhancements

1. **Webhooks** - Notify Moodle of changes in real-time
2. **Bidirectional Sync** - Pull grades from Moodle to Gibbon
3. **Advanced Reporting** - Sync analytics and attendance
4. **Mobile App Support** - Dedicated endpoints for mobile clients
5. **API Documentation** - Swagger/OpenAPI spec generation
6. **Role Mapping UI** - Visual interface for Gibbon→Moodle role mapping
7. **Conflict Resolution** - Handle sync conflicts gracefully

---

## Support

For issues or questions:
1. Check sync logs in Integration Dashboard
2. Review JWT token contents at https://jwt.io
3. Verify IdP configuration matches settings
4. Ensure all required database tables exist

---

**Version:** 1.0  
**Last Updated:** 2024  
**Compatibility:** Gibbon v18+, Moodle 3.9+, .NET Core IdentityProvider