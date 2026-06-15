# Gibbon-to-Moodle Full Integration Architecture

**Scope:** Entire Gibbon eduCore system as API backend  
**Date:** June 14, 2026  
**Architecture:** Gibbon REST API → Moodle Plugin → Moodle Mobile App  
**SSO:** OIDC already implemented (.NET Core)

---

## Strategic Vision

```
┌─────────────────────────────────────────────────────────────┐
│                    OIDC Identity Provider                   │
│                     (.NET Core - Your SSO)                  │
└────────────┬────────────────────────────┬───────────────────┘
             │                            │
             ↓                            ↓
┌──────────────────────┐        ┌─────────────────────────┐
│   Gibbon System      │        │   Moodle LMS            │
│ (Backend API Server) │◄──────►│ (Plugin + Web Services) │
│                      │        │                         │
│ ✓ REST API v2        │        │ ✓ Local Plugin          │
│ ✓ All Core Modules   │        │ ✓ Mobile-optimized      │
│ ✓ Data Normalization │        │ ✓ Branded Mobile App    │
│ ✓ Auth via OIDC      │        │   (Future)              │
└──────────────────────┘        └─────────────────────────┘
             │                            │
             │                            ↓
             │                  ┌──────────────────────┐
             │                  │ Moodle Mobile Apps   │
             │                  │                      │
             │                  │ ✓ Official App       │
             │                  │   (Phase 1)          │
             │                  │ ✓ Branded App        │
             │                  │   (Phase 2)          │
             │                  │ ✓ Driver App         │
             │                  │   (Phase 3)          │
             │                  │ ✓ Parent App         │
             │                  │   (Phase 4)          │
             │                  └──────────────────────┘
             │
             └──────────────────────────────┐
                                            │
                                            ↓
                              ┌──────────────────────────┐
                              │   Future Helper Apps     │
                              │  (Built on Gibbon API)   │
                              │                          │
                              │ ✓ Driver App (Native)    │
                              │ ✓ Parent App (Native)    │
                              │ ✓ Analytics App          │
                              │ ✓ Attendance Tracking    │
                              └──────────────────────────┘
```

---

## Why This Architecture is Better

### Before (Transport-only integration)
- ❌ Limited to one module
- ❌ Tight coupling between systems
- ❌ Moodle-only access
- ❌ Can't build other apps easily

### After (Full Gibbon API + Moodle Plugin)
- ✅ **All Gibbon data available** via REST API
- ✅ **Loosely coupled** - Either system can change independently
- ✅ **Multiple access paths** - Moodle plugin + other apps
- ✅ **Scalable** - Easy to add future apps
- ✅ **Future-proof** - API-first architecture
- ✅ **OIDC integration** - SSO across all apps
- ✅ **Mobile-native** - Optimized for Moodle mobile app

---

## Three-Phase Implementation Plan

## PHASE 1: Build Comprehensive Gibbon REST API (Weeks 1-6)

### Goal
Create a production-ready REST API exposing **all** Gibbon core modules

### Architecture

```
Gibbon Core Modules
├── Students (gibbonStudent)
├── Users (gibbonPerson)
├── Courses (gibbonCourse)
├── Grades (gibbonGrade)
├── Attendance (gibbonAttendanceLog)
├── Timetable (gibbonTTDay)
├── Behavior (gibbonBehaviour)
├── Library (gibbonLibraryItem)
├── Files (gibbonFile)
├── Messages (gibbonMessenger)
├── Transport (Module + Events)
├── Activities/Tasks (gibbonActivity)
└── Reports (Custom queries)
    ↓
Unified REST API (v2)
├── Authentication (OIDC + API Keys)
├── Data Normalization
├── Webhook System
├── Caching Layer
└── Rate Limiting
    ↓
JSON Responses
(Consumed by Moodle & Future Apps)
```

### API Endpoints to Build

#### 1. Authentication & Users
```
POST   /api/v2/auth/validate-token       - Validate OIDC token
GET    /api/v2/users/{id}                - User profile
GET    /api/v2/users/{id}/permissions    - User permissions/roles
GET    /api/v2/users/{id}/settings       - User preferences
```

#### 2. Students & Enrollment
```
GET    /api/v2/students                  - List all students
GET    /api/v2/students/{id}             - Student details
GET    /api/v2/students/{id}/courses     - Enrolled courses
GET    /api/v2/students/{id}/grades      - Student grades
GET    /api/v2/students/{id}/attendance  - Attendance records
GET    /api/v2/students/{id}/timetable   - Personal timetable
GET    /api/v2/students/{id}/behavior    - Behavior records
```

#### 3. Courses & Classes
```
GET    /api/v2/courses                   - List courses
GET    /api/v2/courses/{id}              - Course details
GET    /api/v2/courses/{id}/students     - Enrolled students
GET    /api/v2/courses/{id}/grades       - Grade breakdown
GET    /api/v2/courses/{id}/activities   - Course activities
GET    /api/v2/courses/{id}/timetable    - Course schedule
```

#### 4. Grades & Assessment
```
GET    /api/v2/grades/my                 - My grades
GET    /api/v2/grades/course/{id}        - Course grades
GET    /api/v2/assessments               - Assessment schedule
POST   /api/v2/grades/assessment/{id}    - Submit assessment
```

#### 5. Attendance
```
GET    /api/v2/attendance/my             - My attendance
GET    /api/v2/attendance/course/{id}    - Course attendance
GET    /api/v2/attendance/date/{date}    - Date-specific
POST   /api/v2/attendance/mark           - Mark attendance
```

#### 6. Timetable
```
GET    /api/v2/timetable/my              - My timetable
GET    /api/v2/timetable/date/{date}     - Daily timetable
GET    /api/v2/timetable/week            - Weekly view
```

#### 7. Messages & Communications
```
GET    /api/v2/messages/inbox            - Inbox
GET    /api/v2/messages/{id}             - Read message
POST   /api/v2/messages/send             - Send message
GET    /api/v2/announcements             - School announcements
GET    /api/v2/notifications             - User notifications
```

#### 8. Library
```
GET    /api/v2/library/items             - Library catalog
GET    /api/v2/library/items/{id}        - Item details
GET    /api/v2/library/my-items          - My borrowed items
POST   /api/v2/library/reserve/{id}      - Reserve item
```

#### 9. Transport Module (Your Existing Work)
```
GET    /api/v2/transport/routes          - All routes
GET    /api/v2/transport/my-route        - My enrolled route
GET    /api/v2/transport/boarding-status - Am I boarded?
GET    /api/v2/transport/alerts          - My alerts
POST   /api/v2/transport/boarding        - Record boarding
POST   /api/v2/transport/photo/upload    - Upload photo
```

#### 10. Activities & Tasks
```
GET    /api/v2/activities                - Activities feed
GET    /api/v2/activities/my             - My activities
POST   /api/v2/activities/{id}/complete  - Mark complete
```

#### 11. Behavior & Conduct
```
GET    /api/v2/behavior/my               - My conduct records
GET    /api/v2/behavior/positives        - Positive behavior
GET    /api/v2/behavior/incidents        - Incidents
```

#### 12. Dashboard & Widgets
```
GET    /api/v2/dashboard/my              - My dashboard data
GET    /api/v2/dashboard/widgets         - Widget data
```

#### 13. Files & Media
```
GET    /api/v2/files/my                  - My files
GET    /api/v2/files/uploads/{type}      - Upload endpoints
POST   /api/v2/files/upload              - File upload
GET    /api/v2/files/{id}/download       - Download file
```

#### 14. Reports & Data
```
GET    /api/v2/reports/academic          - Academic report
GET    /api/v2/reports/progress          - Progress report
GET    /api/v2/reports/attendance        - Attendance summary
```

#### 15. Webhooks (For Real-time Updates)
```
POST   /api/v2/webhooks/subscribe        - Subscribe to events
POST   /api/v2/webhooks/events           - Receive events
POST   /api/v2/webhooks/test             - Test webhook

Webhook Events:
- grade.updated
- attendance.marked
- message.received
- announcement.published
- alert.created
- behavior.recorded
- transport.boarding
```

### Core Components to Build

#### 1. API Router & Middleware
```php
/api/v2/
├── index.php              - Main router
├── middleware/
│   ├── Auth.php           - OIDC token validation
│   ├── RateLimit.php      - Rate limiting
│   ├── CORS.php           - Cross-origin handling
│   └── ErrorHandler.php   - Error responses
└── routes.php             - Endpoint definitions
```

#### 2. Resource Classes
```php
/lib/API/v2/
├── UserResource.php       - User serialization
├── StudentResource.php    - Student serialization
├── CourseResource.php     - Course serialization
├── GradeResource.php      - Grade serialization
├── AttendanceResource.php - Attendance serialization
├── TransportResource.php  - Transport data
├── MessageResource.php    - Message serialization
└── ...more resources
```

#### 3. Service Layer
```php
/lib/API/v2/Services/
├── UserService.php        - User business logic
├── StudentService.php     - Student queries
├── CourseService.php      - Course queries
├── GradeService.php       - Grade calculations
├── AttendanceService.php  - Attendance logic
├── TransportService.php   - Transport queries
├── NotificationService.php - Notification logic
└── WebhookService.php     - Webhook delivery
```

#### 4. Caching Layer
```php
/lib/API/v2/
├── Cache.php              - Redis/Memcached wrapper
├── QueryCache.php         - Database query caching
└── CacheInvalidator.php   - Cache invalidation rules
```

#### 5. OIDC Integration
```php
/lib/API/v2/
├── OIDCValidator.php      - Validate OIDC tokens
├── TokenCache.php         - Cache token validation
└── PermissionMapper.php   - Map OIDC claims to Gibbon roles
```

#### 6. Webhook System
```php
/lib/API/v2/
├── WebhookManager.php     - Subscription management
├── EventEmitter.php       - Fire events
├── WebhookQueue.php       - Queue webhook deliveries
└── WebhookRetry.php       - Retry failed deliveries
```

### Database Considerations

**No changes needed!** The API reads from existing Gibbon tables:
- gibbonPerson (users)
- gibbonStudent (student records)
- gibbonCourse (courses)
- gibbonGrade (grades)
- gibbonAttendanceLog (attendance)
- gibbonTTDay/Slot (timetable)
- And all other existing tables

**Add one table for API management:**
```sql
CREATE TABLE gibbonAPIConfig (
  configID INT PRIMARY KEY AUTO_INCREMENT,
  apiKey VARCHAR(255) UNIQUE,
  name VARCHAR(255),
  clientID VARCHAR(255),  -- OIDC client ID
  apiSecret VARCHAR(255),
  permissions JSON,
  rateLimit INT DEFAULT 1000,
  active TINYINT DEFAULT 1,
  createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  lastUsed TIMESTAMP,
  INDEX idx_apiKey, idx_active
);

CREATE TABLE gibbonWebhook (
  webhookID INT PRIMARY KEY AUTO_INCREMENT,
  clientID VARCHAR(255),
  eventType VARCHAR(100),
  callbackURL VARCHAR(500),
  active TINYINT DEFAULT 1,
  deliveries INT DEFAULT 0,
  failures INT DEFAULT 0,
  lastDelivery TIMESTAMP,
  createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clientID, idx_eventType, idx_active
);
```

### File Structure

```
Gibbon/modules/Coreroot/
├── api/v2/
│   ├── index.php               - Main entry point
│   ├── routes.php              - Route definitions
│   ├── middleware/
│   │   ├── Auth.php
│   │   ├── RateLimit.php
│   │   ├── CORS.php
│   │   └── ErrorHandler.php
│   ├── endpoints/
│   │   ├── users.php
│   │   ├── students.php
│   │   ├── courses.php
│   │   ├── grades.php
│   │   ├── attendance.php
│   │   ├── timetable.php
│   │   ├── messages.php
│   │   ├── transport.php
│   │   ├── files.php
│   │   ├── activities.php
│   │   ├── behavior.php
│   │   ├── library.php
│   │   ├── dashboard.php
│   │   ├── reports.php
│   │   └── webhooks.php
│   └── settings.php            - API configuration page
├── lib/API/v2/
│   ├── resources/
│   │   ├── UserResource.php
│   │   ├── StudentResource.php
│   │   ├── CourseResource.php
│   │   └── ... (more resources)
│   ├── services/
│   │   ├── UserService.php
│   │   ├── StudentService.php
│   │   └── ... (more services)
│   ├── OIDCValidator.php
│   ├── Cache.php
│   ├── WebhookManager.php
│   └── RateLimiter.php
└── tests/api/
    ├── UserAPITest.php
    ├── StudentAPITest.php
    └── ... (API tests)
```

### Security Implementation

```php
// OIDC Token Validation
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    return error(401, 'Missing authorization header');
}

$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
$validator = new OIDCValidator($container['cache']);
$claims = $validator->validate($token);

if (!$claims) {
    return error(401, 'Invalid token');
}

// Permission Checking
$permissions = mapOIDCToGibbon($claims);
if (!hasPermission($action, $permissions)) {
    return error(403, 'Insufficient permissions');
}

// Rate Limiting
$rateLimiter->checkLimit($clientID, $endpoint);

// Input Validation & Sanitization
$validated = validateRequest($request, $schema);
```

### Response Format

#### Success
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@school.edu"
  },
  "meta": {
    "timestamp": "2026-06-14T14:30:00Z",
    "version": "2.0"
  }
}
```

#### Paginated
```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "page": 1,
    "per_page": 50,
    "total": 500,
    "pages": 10
  }
}
```

#### Error
```json
{
  "success": false,
  "error": "Invalid student ID",
  "code": "INVALID_STUDENT",
  "status": 400
}
```

### Testing Strategy

```php
// Unit tests for each resource/service
tests/api/resources/
tests/api/services/
tests/api/middleware/

// Integration tests
tests/api/integration/
├── StudentAPIIntegrationTest.php
├── CourseAPIIntegrationTest.php
└── WebhookIntegrationTest.php

// End-to-end tests
tests/api/e2e/
├── AuthenticationFlowTest.php
├── DataSyncTest.php
└── WebhookDeliveryTest.php
```

### Performance Optimization

```php
// Caching Strategy
- Token validation: Cache 15 minutes
- User data: Cache 30 minutes
- Course/Grade data: Cache 1 hour
- Student list: Cache 24 hours
- Invalidate on: Update/Delete operations

// Query Optimization
- Lazy load relationships
- Select only needed fields
- Paginate large result sets
- Use database indexes

// Response Optimization
- Gzip compression
- JSON minification
- CDN for static content
```

### Timeline: Weeks 1-6

| Week | Endpoints | Components |
|------|-----------|------------|
| **1** | Auth, Users, Students | Router, Middleware, OIDCValidator |
| **2** | Courses, Grades, Attendance | Resources, Services, Caching |
| **3** | Timetable, Messages, Transport | Additional Services |
| **4** | Library, Files, Activities, Behavior | Webhook System, Event Emitter |
| **5** | Dashboard, Reports, Webhooks | Webhook Manager, Testing |
| **6** | Polish, Testing, Documentation | Performance, Security Audit |

---

## PHASE 2: Build Moodle Plugin (Weeks 7-10)

### Goal
Create Moodle local plugin that bridges to Gibbon API

### Plugin Structure

```
moodle/local/gibbon/
├── version.php                - Plugin definition
├── settings.php               - Configuration UI
├── lang/
│   └── en/
│       └── local_gibbon.php  - Language strings
├── lib.php                    - Main integration hooks
├── classes/
│   ├── api/
│   │   ├── GibbonClient.php  - REST client
│   │   ├── StudentSync.php   - Sync logic
│   │   ├── GradeSync.php
│   │   ├── AttendanceSync.php
│   │   └── WebhookHandler.php
│   ├── task/
│   │   ├── sync_students.php - Scheduled task
│   │   ├── sync_grades.php
│   │   └── sync_attendance.php
│   ├── event/
│   │   └── gibbon_webhook_received.php
│   └── privacy/
│       └── provider.php       - GDPR compliance
├── db/
│   ├── install.xml           - Install script
│   └── upgrade.php           - Upgrade script
├── externallib.php           - External functions for mobile
├── tests/
│   ├── lib_test.php
│   └── sync_test.php
└── pix/
    └── icon.svg
```

### Key Features

#### 1. Moodle Web Services
```php
// externallib.php

// For Moodle Mobile App integration
$services = [
    'gibbon_mobile_service' => [
        'functions' => [
            'local_gibbon_get_student_data',
            'local_gibbon_get_grades',
            'local_gibbon_get_attendance',
            'local_gibbon_get_transport_status',
            'local_gibbon_get_messages',
            'local_gibbon_get_timetable',
            'local_gibbon_get_announcements',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];
```

#### 2. Student Sync
```php
// classes/api/StudentSync.php

public function syncStudents() {
    $gibbonStudents = $this->client->get('/api/v2/students');
    
    foreach ($gibbonStudents as $gibbonStudent) {
        $moodleUser = $this->mapStudent($gibbonStudent);
        
        // Create/Update user
        if ($existing = $DB->get_record('user', ['idnumber' => $gibbonStudent['id']])) {
            $moodleUser->id = $existing->id;
            $DB->update_record('user', $moodleUser);
        } else {
            $moodleUser->id = create_user_record(...);
        }
        
        // Enroll in appropriate courses
        $this->enrollStudent($moodleUser, $gibbonStudent);
    }
}
```

#### 3. Grade Sync
```php
// classes/api/GradeSync.php

public function syncGrades() {
    $grades = $this->client->get('/api/v2/grades');
    
    foreach ($grades as $grade) {
        $gradeItem = $this->mapGrade($grade);
        grade_update('local_gibbon', ...);
    }
}
```

#### 4. Webhook Receiver
```php
// classes/api/WebhookHandler.php

public function handle($event, $data) {
    switch($event) {
        case 'grade.updated':
            $this->syncGrade($data['gradeID']);
            break;
        case 'attendance.marked':
            $this->syncAttendance($data['studentID']);
            break;
        case 'message.received':
            $this->sendMoodleMessage($data);
            break;
    }
}
```

#### 5. Dashboard Block
```php
// blocks/gibbon_dashboard/
// Display student transport status, grades, messages in Moodle dashboard
```

---

## PHASE 3: Moodle Mobile App & Beyond (Weeks 11+)

### Phase 3A: Official Moodle Mobile App (Weeks 11-12)
- Install official Moodle mobile app
- Use web services from Phase 2
- Test all Gibbon features via mobile
- Create user guides

### Phase 3B: Branded Mobile App (Weeks 13-15)
```
Custom branded Moodle mobile app
├── Same features as official app
├── Custom branding (logo, colors)
├── Performance optimizations
└── App store distribution
```

### Phase 3C: Future Helper Apps (Weeks 16+)

#### Driver App
```
- GPS tracking
- Route optimization
- Boarding/dropping log
- Photo evidence capture
- Emergency alerts
- Offline mode

Technology: React Native
Connects to: Gibbon API + Transport module
```

#### Parent App
```
- Real-time boarding status
- Student grades
- Messages
- Announcements
- Transport alerts
- Emergency contact

Technology: React Native / Flutter
Connects to: Gibbon API
```

#### Analytics App
```
- School statistics
- Student progress tracking
- Attendance trends
- Performance reports
- Export capabilities

Technology: React + Recharts
Connects to: Gibbon API + Reports endpoint
```

---

## Summary of Phases

```
Phase 1 (Weeks 1-6):    Build Gibbon REST API v2
                        ↓
Phase 2 (Weeks 7-10):   Build Moodle Plugin
                        ↓
Phase 3A (Weeks 11-12): Official Moodle Mobile App
                        ↓
Phase 3B (Weeks 13-15): Branded Moodle Mobile App
                        ↓
Phase 3C (Weeks 16+):   Helper Apps (Driver, Parent, Analytics)
```

---

## Key Advantages of This Architecture

### 1. Separation of Concerns
- Gibbon = Data provider (REST API)
- Moodle = Frontend/Plugin (UI)
- Apps = Specialized clients (Mobile, Driver, etc.)
- Each can evolve independently

### 2. Scalability
- REST API can serve multiple consumers
- Caching layer prevents overload
- Rate limiting protects resources
- Webhook system handles real-time updates

### 3. Security
- OIDC SSO for all authentication
- API keys for app-to-API communication
- Token-based access control
- Permissions mapped to Gibbon roles

### 4. Flexibility
- Add new modules without changing API structure
- Easy to build new apps
- Support for multiple client types
- Future-proof design

### 5. User Experience
- Single login (OIDC)
- Single primary app (Moodle mobile)
- Consistent data across apps
- Real-time notifications via webhooks

---

## Technology Stack

```
Gibbon Backend:
├── PHP 7.4+
├── MySQL/MariaDB
├── Redis (caching)
└── OpenSSL (OIDC)

Moodle Plugin:
├── PHP 7.4+
├── Moodle 3.9+ / 4.x
└── Web Services API

Mobile Apps:
├── React Native (Cross-platform)
├── Moodle Mobile SDK
└── Native APIs (GPS, Camera, etc.)

Future Apps:
├── React.js (Web dashboards)
├── React Native (Mobile)
├── Node.js (Microservices)
└── Docker (Deployment)
```

---

## Cost-Benefit Analysis

### Investment Required
- **Phase 1:** 6 weeks dev time (Full API)
- **Phase 2:** 4 weeks dev time (Moodle Plugin)
- **Phase 3A:** 2 weeks (Official app testing)
- **Phase 3B:** 3 weeks (Branded app)
- **Total:** 15 weeks, ~3 developers

### Benefits Achieved
- ✅ Unified authentication (OIDC)
- ✅ Multiple access paths (API + apps)
- ✅ Scalable to support unlimited apps
- ✅ Future-proof architecture
- ✅ Decoupled systems (easier maintenance)
- ✅ Mobile-first user experience
- ✅ Ready for cloud/microservices migration

### ROI
- **Short-term:** Moodle mobile app for students/teachers
- **Medium-term:** Driver + Parent apps for transport
- **Long-term:** Extensible platform for future features

---

## Next Steps

### Immediate (This Week)
1. **Approve Phase 1 approach** - Build Gibbon REST API v2
2. **Plan API endpoints** - Prioritize most-needed features
3. **Setup development** - Create api/v2 directory structure
4. **Design data model** - JSON schemas for responses

### Week 1-2
1. Build core API router and middleware
2. Implement OIDC token validation
3. Create User/Student resource endpoints
4. Setup database tables for API management

### Weeks 3-6
1. Implement all remaining endpoints
2. Build caching and rate limiting
3. Create webhook system
4. Comprehensive testing and documentation

### Weeks 7-10
1. Build Moodle plugin
2. Create data sync mechanisms
3. Implement web services for mobile
4. Integration testing

### Weeks 11+
1. Deploy official Moodle mobile app
2. Create branded version (if needed)
3. Build helper apps (driver, parent)
4. Continuous monitoring and optimization

---

## Questions for You

1. **Which endpoints are most critical?** (Students? Grades? Transport?)
2. **OIDC details:** Is it hosted on your .NET Core system? How should API validate tokens?
3. **Timeline:** Can you allocate 15 weeks for full implementation?
4. **Team:** Do you have developers for parallel work (API + Moodle plugin)?
5. **Deployment:** Will this be on-premise or cloud?
6. **Moodle version:** Which Moodle version are you using?

---

## My Recommendation

**START WITH PHASE 1** - Build the comprehensive REST API first.

Why:
1. **API is foundation** - Everything else depends on it
2. **Most valuable** - Unlocks all future possibilities
3. **Parallel work** - While Phase 1 dev finishes, others can plan Phase 2
4. **De-risks** - Reduces errors/rework later
5. **Reusable** - API serves all future apps

**Ready to start Phase 1 implementation?**
