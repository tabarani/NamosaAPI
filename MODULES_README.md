# NamosaAPI Ecosystem - Setup & Usage Guide

## Overview

This repository contains three integrated modules for Gibbon:

1. **Gibbon_OIDC** - OpenID Connect authentication module for SSO
2. **NamosaAPI** - RESTful API for school data (students, staff, courses)
3. **Transport** - Transportation management with REST API

## Architecture

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────┐
│   Moodle        │     │   Gibbon Core    │     │   Mobile    │
│   (OIDC Client) │     │   + OIDC Module  │     │   Apps      │
└────────┬────────┘     └────────┬─────────┘     └──────┬──────┘
         │                       │                      │
         │          ┌────────────▼────────────┐         │
         │          │   .NET IdentityProvider │         │
         │          │   (OpenIddict/JWKS)     │         │
         │          └────────────┬────────────┘         │
         │                       │                      │
         ▼                       ▼                      ▼
┌────────────────────────────────────────────────────────────────┐
│                    NamosaAPI Module                             │
│  - JWT Validation (RS256 via JWKS)                             │
│  - Permission Enforcement                                       │
│  - REST Endpoints: /api/v1/students, /staff, /courses          │
└────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────┐
│                   Transport Module                              │
│  - Dual Auth: JWT + Legacy API Key                             │
│  - Endpoints: /transport/api/v1/students (bus routes)          │
└────────────────────────────────────────────────────────────────┘
```

## Installation

### 1. Install Gibbon_OIDC Module First

1. Copy `modules/Gibbon_OIDC` to your Gibbon installation's `modules/` folder
2. In Gibbon Admin → Manage Modules, install "Gibbon_OIDC"
3. Configure settings:
   - **IdP Base URL**: `https://144.91.66.114`
   - **Client ID**: Your registered client ID
   - **Client Secret**: Your registered client secret
   - **Scopes**: `openid profile email gibbon_id`
   - **Auto-redirect**: Enable for forced SSO

### 2. Install NamosaAPI Module

1. Copy `modules/NamosaAPI` to Gibbon's `modules/` folder
2. Install via Admin → Manage Modules
3. No additional config needed (uses Gibbon_OIDC settings)

### 3. Install/Update Transport Module

1. Update `modules/Transport` in Gibbon's `modules/` folder
2. Install/upgrade via Admin → Manage Modules
3. Configure API settings:
   - **Enable API**: Yes
   - **API Keys** (optional): Comma-separated list for legacy access

## Configuration

### IdentityProvider Requirements

Your .NET IdentityProvider must:

1. Issue JWT tokens with **RS256** signature
2. Expose JWKS endpoint at: `{idpUrl}/.well-known/jwks.json`
3. Include these claims in tokens:
   - `sub` or `gibbon_person_id`: Gibbon Person ID (integer)
   - `iss`: Issuer URL (must match IdP base URL)
   - `aud`: Audience (default: `namosa-api`)

### Environment Variables (Optional)

Override database settings with environment variables:

```bash
export IDP_JWKS_URL="https://144.91.66.114/.well-known/jwks.json"
export IDP_ISSUER="https://144.91.66.114"
export IDP_AUDIENCE="namosa-api"
export IDP_USER_CLAIM="sub"
```

## API Usage

### Authentication

All endpoints require a valid Bearer token from your IdentityProvider:

```bash
# Get token from IdP first (OAuth2 Authorization Code Flow)
TOKEN=$(curl -X POST https://144.91.66.114/connect/token \
  -d "grant_type=password" \
  -d "client_id=your-client-id" \
  -d "client_secret=your-secret" \
  -d "username=user@school.com" \
  -d "password=password" \
  -d "scope=openid profile namosa-api" | jq -r '.access_token')

# Use token in API requests
curl -H "Authorization: Bearer $TOKEN" \
     "https://gibbon.school/modules/NamosaAPI/api/v1/students"
```

### NamosaAPI Endpoints

#### GET /api/v1/students
List students with pagination and filtering.

**Parameters:**
- `limit` (default: 50, max: 200)
- `offset` (default: 0)
- `search` (surname, preferredName, email)
- `status` (Full, Left, Expected)
- `yearGroup` (gibbonYearGroupID)
- `house`

**Permission Required:** `students_read`

#### GET /api/v1/staff
List staff members.

**Parameters:**
- `limit`, `offset`, `search`
- `status` (Full, Left)
- `type` (Staff, etc.)

**Permission Required:** `staff_read`

#### GET /api/v1/courses
List courses with enrollment counts.

**Parameters:**
- `limit`, `offset`, `search`
- `schoolYear` (gibbonSchoolYearID)
- `department` (gibbonDepartmentID)

**Permission Required:** `courses_read`

### Transport API Endpoints

#### GET /transport/api/v1/students
List students assigned to bus routes.

**Parameters:**
- `limit`, `offset`, `page`
- `routeID` (filter by route)
- `stopID` (filter by stop)
- `studentID` (filter by student)
- `status` (Y, N)
- `search`

**Auth:** JWT with `transport_read` permission OR valid API key

**Example with API Key:**
```bash
curl "https://gibbon.school/modules/Transport/api/v1/students?api_key=your-api-key&routeID=5"
```

## Response Format

All endpoints return JSON:

```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "total": 150,
    "limit": 50,
    "offset": 0,
    "hasMore": true
  },
  "meta": {
    "requestedBy": 123,
    "timestamp": "2024-01-15T10:30:00+00:00"
  }
}
```

## Moodle Integration

To sync data with Moodle:

1. **Install Moodle OIDC Plugin** (auth_oidc)
2. **Configure same IdP** settings in Moodle
3. **Create Moodle Web Service** that calls NamosaAPI endpoints
4. **Schedule Sync** for:
   - Students → Moodle users
   - Courses → Moodle categories/courses
   - Enrolments → Moodle enrolments
   - Grades (from Moodle) → Write back to Gibbon (future feature)

## Troubleshooting

### Token Validation Fails
- Check JWKS endpoint is accessible: `curl https://144.91.66.114/.well-known/jwks.json`
- Verify token claims match configuration (iss, aud)
- Check server time is synchronized

### Permission Denied
- Ensure user has required permission in Gibbon (Admin → User Admin → Manage Permissions)
- Check token contains correct `sub` claim matching Gibbon Person ID

### CORS Issues
- All endpoints include CORS headers for cross-origin requests
- For production, restrict origins in web server config

## Security Notes

- JWT keys are cached for 1 hour (configurable)
- API keys should be rotated regularly
- All database queries use prepared statements
- Rate limiting should be configured at web server level

## Future Enhancements

- [ ] POST/PUT/DELETE endpoints for write operations
- [ ] Webhooks for real-time updates
- [ ] Grade sync with Moodle
- [ ] Attendance endpoints
- [ ] Fee/payment endpoints
- [ ] GraphQL support
