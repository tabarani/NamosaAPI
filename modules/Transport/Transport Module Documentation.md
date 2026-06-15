# Transport Management System - Comprehensive Documentation

## Project Overview

**Transport** is a comprehensive student transportation safety management system built for schools, particularly in developing regions. It's designed as a Gibbon LMS module that enables schools to manage routes, track student pickups/dropoffs, send SMS alerts to parents, and maintain detailed attendance records.

**Version:** 1.2.0  
**Author:** Yulpana Edutech / Mustafa  
**License:** Gibbon Foundation (per header)  
**Built For:** Gibbon LMS (Open School Platform)  
**Primary Use Case:** Congo Schools (adaptable to other regions)

---

## 1. Architecture Overview

### Design Pattern: Module-Based MVC with Multi-Tier Separation

The application follows a structured architecture with clear separation of concerns:

```
┌─────────────────────────────────────────────────────────────────┐
│                  PRESENTATION LAYER                              │
│  (PHP Server-Side Rendered Pages)                                │
│  ├─ Dashboard: index.php (quick stats, recent events)           │
│  ├─ Routes Management: routes_manage.php, routes_manage_*.php   │
│  ├─ Stops Management: stops_manage.php, stops_manage_*.php      │
│  ├─ Students: students_manage.php, students_manage_add.php      │
│  ├─ Boarding: boarding_start.php (real-time tracking)           │
│  ├─ Attendance: attendance_daily.php                             │
│  ├─ Reports: reports_routes.php                                  │
│  ├─ SMS: sms_broadcast.php, sms_test.php                         │
│  └─ Settings: settings.php, api_keys_manage.php                 │
└─────────────────────────────────────────────────────────────────┘
                            ↓↑
┌─────────────────────────────────────────────────────────────────┐
│              BUSINESS LOGIC & API LAYER                          │
│  ├─ RESTful APIs: /api/routes.php, /api/alerts.php             │
│  ├─ AJAX Handlers: /ajax/staffSearch.php, /ajax/getStopsByRoute│
│  └─ Direct SQL with Prepared Statements (MySQLi)               │
└─────────────────────────────────────────────────────────────────┘
                            ↓↑
┌─────────────────────────────────────────────────────────────────┐
│                    DATA LAYER                                    │
│  MySQL/MariaDB Database (InnoDB, UTF-8mb4)                       │
│  ├─ gibbonTransportRoute       (Routes with drivers/supervisors)│
│  ├─ gibbonTransportStop        (Pickup/dropoff locations)       │
│  ├─ gibbonTransportStudent     (Student assignments)            │
│  ├─ gibbonTransportEvent       (Boarding events - safety-critical)
│  ├─ gibbonTransportAlert       (Safety alerts)                  │
│  └─ gibbonTransportAPIKey      (API authentication)             │
└─────────────────────────────────────────────────────────────────┘
                            ↓↑
┌─────────────────────────────────────────────────────────────────┐
│              EXTERNAL INTEGRATIONS                               │
│  ├─ Gibbon LMS: Person system, roles, settings, form framework │
│  ├─ Infobip SMS Gateway: Parent notifications                   │
│  └─ Mobile App APIs: JSON endpoints for offline sync            │
└─────────────────────────────────────────────────────────────────┘
```

### Key Architectural Characteristics

- **Role-Based Access Control:** Driver, Supervisor, Admin roles with granular permissions
- **Safety-First Design:** Emergency flags, photo verification, supervisor escalation
- **Audit Trail:** All events timestamped, creator tracked, sync status monitored
- **Mobile-Ready:** JSON APIs with offline-capable sync mechanism (syncStatus field)
- **Scalable:** Proper indexing, foreign keys, prepared statements prevent SQL injection
- **Multi-Language Support:** Uses Gibbon's `__()` translation function throughout

---

## 2. Database Schema

### 2.1 gibbonTransportRoute
**Purpose:** Defines transport routes with driver and supervisor assignments

```sql
CREATE TABLE gibbonTransportRoute (
  gibbonTransportRouteID       INT PRIMARY KEY AUTO_INCREMENT
  name                          VARCHAR(100) NOT NULL
  routeType                     ENUM('to_school', 'from_school', 'both') DEFAULT 'both'
  nameShort                     VARCHAR(20) UNIQUE (for quick reference)
  vehicleNumber                 VARCHAR(20) (Bus license plate/ID)
  vehicleType                   VARCHAR(50) (e.g., "Bus", "Van", "Minibus")
  capacity                      INT DEFAULT 50 (passenger capacity)
  driverID                      INT FK→gibbonPerson (nullable)
  driverPhone                   VARCHAR(20) (direct contact)
  supervisorEnabled             ENUM('Y','N') DEFAULT 'N' (v1.1+)
  gibbonPersonIDSupervisor      INT FK→gibbonPerson (v1.1+, nullable)
  active                        TINYINT(1) DEFAULT 1
  comments                      TEXT
  timestampCreated              TIMESTAMP
  timestampModified             TIMESTAMP ON UPDATE
  
  INDEXES: idx_nameShort, idx_routeType, idx_active, idx_supervisor
  FOREIGN KEYS: driverID, supervisorEnabled
)
```

**Key Features:**
- Routes can be marked as `to_school`, `from_school`, or both
- Supervisor support added in v1.1.0 for enhanced safety
- Vehicle details stored for coordination and planning
- Driver contact info for emergency communication

---

### 2.2 gibbonTransportStop
**Purpose:** Defines pickup and dropoff locations for each route

```sql
CREATE TABLE gibbonTransportStop (
  gibbonTransportStopID         INT PRIMARY KEY AUTO_INCREMENT
  gibbonTransportRouteID        INT FK→gibbonTransportRoute NOT NULL
  name                          VARCHAR(100) (e.g., "Main Gate", "Market Center")
  sequenceNumber                INT (order of stops on the route)
  latitude                      DECIMAL(10,8) (GPS coordinates)
  longitude                     DECIMAL(11,8)
  address                       TEXT (full street address)
  landmark                      VARCHAR(100) (e.g., "Near Blue Hospital")
  estimatedArrivalTime          TIME (for scheduling)
  comments                      TEXT
  active                        TINYINT(1) DEFAULT 1
  timestampCreated              TIMESTAMP
  timestampModified             TIMESTAMP
  
  INDEXES: idx_route, idx_sequence (route+sequence for ordered retrieval)
  FOREIGN KEY: gibbonTransportRouteID (CASCADE DELETE)
)
```

**Key Features:**
- GPS coordinates enable location-based services and tracking
- Sequence number ensures proper route ordering
- Estimated times aid in parent communication
- Active flag allows archiving without deletion

---

### 2.3 gibbonTransportStudent
**Purpose:** Links students to routes and specific stops

```sql
CREATE TABLE gibbonTransportStudent (
  gibbonTransportStudentID      INT PRIMARY KEY AUTO_INCREMENT
  gibbonPersonID                INT FK→gibbonPerson NOT NULL
  gibbonTransportRouteID        INT FK→gibbonTransportRoute NOT NULL
  gibbonTransportStopID         INT FK→gibbonTransportStop (v1.1+, nullable)
  status                        ENUM('Active', 'Inactive', 'Pending')
  startDate                     DATE (when student starts using transport)
  endDate                       DATE (when student stops - nullable for ongoing)
  emergencyContactOverride      VARCHAR(255) (alternative contact if needed)
  specialNeeds                  TEXT (e.g., "Requires wheelchair access")
  comments                      TEXT
  timestampCreated              TIMESTAMP
  timestampModified             TIMESTAMP
  
  INDEXES: idx_student_route (UNIQUE), idx_student, idx_route, idx_stop, idx_status
  FOREIGN KEYS: gibbonPersonID, gibbonTransportRouteID, gibbonTransportStopID
)
```

**Key Features:**
- Unique constraint on (student, route) prevents duplicate assignments
- Status field allows pending approvals or temporary deactivation
- Stop linkage (v1.1) enables precise pickup/dropoff tracking per student
- Special needs field ensures accommodations are documented
- Date range allows historical tracking

---

### 2.4 gibbonTransportEvent (SAFETY-CRITICAL)
**Purpose:** Records every student boarding/alighting action with full audit trail

```sql
CREATE TABLE gibbonTransportEvent (
  gibbonTransportEventID        BIGINT PRIMARY KEY AUTO_INCREMENT
  gibbonTransportRouteID        INT FK→gibbonTransportRoute NOT NULL
  gibbonTransportStopID         INT FK→gibbonTransportStop (nullable)
  gibbonPersonID                INT FK→gibbonPerson NOT NULL
  type                          ENUM('pickup', 'dropoff')
  timestamp                     DATETIME (exact moment of boarding)
  status                        ENUM('Expected', 'OnTime', 'Late', 'Early', 'Absent', 'Verified')
  gibbonPersonIDRecorder        INT FK→gibbonPerson (who recorded - driver/supervisor)
  latitude                      DECIMAL(10,8) (actual GPS location at boarding)
  longitude                     DECIMAL(11,8)
  photoUrl                      VARCHAR(255) (evidence photo URL)
  photoVerified                 TINYINT(1) (manual verification by supervisor)
  comments                      TEXT
  emergencyFlag                 TINYINT(1) (triggers alert system)
  emergencyNotes                TEXT (description of emergency)
  syncStatus                    ENUM('pending', 'synced', 'failed') (mobile app sync)
  syncTimestamp                 TIMESTAMP (when sync occurred)
  timestampCreated              TIMESTAMP
  timestampModified             TIMESTAMP
  
  INDEXES: idx_route_date, idx_event_stop, idx_student_date, idx_type, idx_status, idx_emergency, idx_sync
  FOREIGN KEYS: All refs with CASCADE/SET NULL
)
```

**Key Features:**
- BIGINT for future scalability (supports billions of records)
- Complete audit trail (who recorded, when, where, photo evidence)
- Emergency flag triggers automatic SMS alerts
- Photo verification for regulatory compliance
- Sync status allows mobile app offline capability
- Multiple indexes enable fast queries by route, student, date, status

---

### 2.5 gibbonTransportAlert
**Purpose:** Safety alerts system for missing students, deviations, emergencies

```sql
CREATE TABLE gibbonTransportAlert (
  gibbonTransportAlertID        BIGINT PRIMARY KEY AUTO_INCREMENT
  alertType                     ENUM('missing_boarding', 'route_deviation', 'late_arrival', 'emergency', 'custom')
  severity                      ENUM('low', 'medium', 'high', 'critical')
  gibbonTransportRouteID        INT FK→gibbonTransportRoute (nullable)
  gibbonPersonID                INT FK→gibbonPerson (nullable)
  message                       TEXT (alert message for parents/staff)
  smsSent                       TINYINT(1) (whether SMS was successfully sent)
  smsRecipients                 TEXT (phone numbers that received SMS)
  resolved                      TINYINT(1) (alert status)
  resolvedBy                    INT FK→gibbonPerson (admin who resolved)
  resolvedAt                    TIMESTAMP
  resolvedNotes                 TEXT (explanation of resolution)
  timestampCreated              TIMESTAMP
  timestampModified             TIMESTAMP
  
  INDEXES: idx_type, idx_severity, idx_route, idx_student, idx_unresolved
  FOREIGN KEYS: All refs with SET NULL
)
```

**Key Features:**
- Multiple alert types enable smart notifications
- Severity levels prioritize critical incidents
- SMS tracking ensures parent notifications are sent
- Resolution audit trail for compliance/investigation
- Unresolved index enables quick dashboard alerts

---

### 2.6 gibbonTransportAPIKey
**Purpose:** API authentication for mobile apps

```sql
CREATE TABLE gibbonTransportAPIKey (
  apiKeyID                      INT PRIMARY KEY AUTO_INCREMENT
  name                          VARCHAR(100) (e.g., "Driver Mobile App", "Supervisor Tablet")
  apiKey                        VARCHAR(255) UNIQUE (hashed token)
  active                        TINYINT(1) DEFAULT 1
  lastUsed                      TIMESTAMP (audit/usage tracking)
  createdBy                     INT FK→gibbonPerson
  timestampCreated              TIMESTAMP
  timestampModified             TIMESTAMP
  
  INDEXES: idx_apiKey, idx_active
  FOREIGN KEY: createdBy SET NULL
)
```

**Key Features:**
- Unique API keys per application/device
- Last used tracking detects abandoned credentials
- Active flag allows disabling without deletion
- Audit trail shows who created the key

---

## 3. Core Modules & Features

### 3.1 Dashboard (`index.php`)
**Purpose:** Executive overview of transport operations

**Features:**
- Real-time stats cards: Active routes, assigned students, active stops, today's events
- Quick-link navigation to all major modules
- Recent boarding events table (last 7 days)
- Visual indicators with color-coded status

**Database Queries:**
```
- COUNT active routes
- COUNT active students
- COUNT active stops
- COUNT today's events
- Recent 10 boarding events with joins to route/person
```

---

### 3.2 Routes Management (`routes_manage*.php`)
**Purpose:** Create, edit, and manage transport routes

**Features:**
- List all routes with pagination
- Create new routes with vehicle details
- Edit existing routes (driver, supervisor, capacity, type)
- Mark routes as active/inactive
- Support for route types: to_school, from_school, or both
- Driver/supervisor assignment with phone contact

**Database Operations:**
```
- SELECT/INSERT/UPDATE on gibbonTransportRoute
- JOIN with gibbonPerson for driver/supervisor names
- Soft delete via active flag
```

---

### 3.3 Stops Management (`stops_manage*.php`)
**Purpose:** Define pickup and dropoff locations for routes

**Features:**
- Add/edit stops for each route
- Set stop sequence (order on route)
- GPS coordinates for location tracking
- Estimated arrival times for parent communication
- Landmark descriptions for easier identification

**Database Operations:**
```
- CRUD on gibbonTransportStop
- Ordered by sequence number
- Validates route exists before assigning stops
```

---

### 3.4 Student Assignments (`students_manage*.php`)
**Purpose:** Assign students to routes and specific stops

**Features:**
- Add students to transport routes
- Assign specific pickup/dropoff stops (v1.1+)
- Set student status: Active, Inactive, Pending
- Record start/end dates for tracking
- Emergency contact override
- Special needs documentation

**Database Operations:**
```
- CRUD on gibbonTransportStudent
- Foreign key validation
- Unique constraint prevents duplicates
- JOIN with gibbonPerson for student details
```

---

### 3.5 Boarding Registration (`boarding_start.php`)
**Purpose:** Real-time student pickup/dropoff tracking

**Features:**
- Record student boarding events with timestamps
- Capture GPS location at boarding
- Optional photo attachment for verification
- Status tracking: OnTime, Late, Early, Absent
- Emergency flag for urgent situations
- Supervisor override/verification

**Database Operations:**
```
- INSERT into gibbonTransportEvent
- UPDATE event status
- Track recorder (driver/supervisor)
- Store sync status for mobile app
```

---

### 3.6 Daily Attendance (`attendance_daily.php`)
**Purpose:** Review and verify daily boarding records

**Features:**
- View all events for a specific date
- Filter by route, student, event type
- Verify photo evidence
- Update event status
- View expected vs. actual boarding
- Export attendance reports

**Database Queries:**
```
- SELECT events filtered by date
- JOIN with student and route data
- Aggregate expected vs. actual counts
```

---

### 3.7 Reports (`reports_routes.php`)
**Purpose:** Analytics and compliance reporting

**Features:**
- Route performance metrics (on-time percentage, capacity utilization)
- Student attendance summaries
- Alert/incident reports
- Export to CSV/PDF formats
- Date-range filtering

---

### 3.8 SMS Broadcast (`sms_broadcast.php`, `sms_test.php`)
**Purpose:** Send notifications to parents

**Features:**
- Send bulk SMS to parents of students on a route
- Template-based messages with variable substitution
- Test SMS functionality
- Track delivery status
- Integration with Infobip SMS gateway

**Configuration:**
```php
Transport_smsProvider      // e.g., 'infobip'
Transport_smsApiKey        // API authentication
Transport_smsApiSecret     // API secret
Transport_smsBaseUrl       // Gateway endpoint
Transport_smsSenderID      // Sender name/ID
Transport_smsEnabled       // Enable/disable SMS
```

---

### 3.9 Settings (`settings.php`)
**Purpose:** Configure SMS gateway and other options

**Features:**
- Enable/disable SMS functionality
- Configure SMS provider (Infobip)
- API key management
- Custom sender ID configuration
- Settings stored in Gibbon's gibbonSetting table

---

### 3.10 API Keys Management (`api_keys_manage*.php`)
**Purpose:** Manage authentication for external apps

**Features:**
- Generate new API keys
- View active keys
- Disable compromised keys
- Track key usage (last used timestamp)
- Audit trail (created by, creation date)

---

## 4. API Endpoints

### 4.1 Routes API (`api/routes.php`)

#### GET /api/routes.php?action=list
**Purpose:** Retrieve all active routes

**Response:**
```json
{
  "success": true,
  "count": 5,
  "routes": [
    {
      "id": 1,
      "name": "North Pickup Route",
      "nameShort": "NRT",
      "routeType": "to_school",
      "vehicleNumber": "BUS-001",
      "vehicleType": "Bus",
      "capacity": 50,
      "driverID": 123,
      "driverName": "John Doe",
      "driverPhone": "+243123456789",
      "supervisorEnabled": true,
      "supervisorID": 456,
      "supervisorName": "Jane Smith",
      "active": true
    }
  ]
}
```

#### GET /api/routes.php?action=students&id=1
**Purpose:** Get students assigned to a specific route

**Response:**
```json
{
  "success": true,
  "count": 25,
  "students": [
    {
      "studentID": 789,
      "firstName": "Michael",
      "surname": "Johnson",
      "stopID": 10,
      "stopName": "Main Gate",
      "status": "Active",
      "specialNeeds": null
    }
  ]
}
```

---

### 4.2 Alerts API (`api/alerts.php`)
**Purpose:** Retrieve active safety alerts

**Endpoints (inferred from codebase):**
- `GET ?action=list` - All unresolved alerts
- `GET ?action=critical` - Critical severity alerts only
- `POST ?action=create` - Create new alert
- `POST ?action=resolve&id=X` - Mark alert as resolved

---

### 4.3 Events API (`api/events.php`)
**Purpose:** Manage boarding events

**Endpoints (inferred):**
- `POST ?action=record` - Record new boarding event
- `GET ?action=sync&since=<timestamp>` - Sync mobile app (for offline capability)
- `PUT ?action=update` - Update event status

---

## 5. AJAX Handlers

### 5.1 `ajax/getStopsByRoute.php`
**Purpose:** Get stops for a specific route (used in forms)

**Parameters:**
- `routeID` - Route identifier

**Returns:** JSON array of stops ordered by sequence

---

### 5.2 `ajax/staffSearch.php`
**Purpose:** Search for staff members (drivers, supervisors)

**Parameters:**
- `query` - Search term

**Returns:** JSON array of matching staff

---

## 6. Security Measures

### Implemented
✅ **Prepared Statements:** All SQL queries use MySQLi prepared statements  
✅ **Role-Based Access Control:** All actions check `isActionAccessible()`  
✅ **Foreign Key Constraints:** Data integrity enforced at DB level  
✅ **Audit Trail:** All events timestamped and attributed to users  
✅ **Input Validation:** Via Gibbon's form framework  
✅ **Output Escaping:** `htmlspecialchars()` used for display  

### Recommended
⚠️ **API Key Hashing:** API keys should be hashed (bcrypt/password_hash)  
⚠️ **HTTPS Enforcement:** All APIs should require HTTPS  
⚠️ **Rate Limiting:** API endpoints need rate limiting  
⚠️ **CORS Headers:** Define allowed origins for APIs  
⚠️ **Request Validation:** Add input length/type validation  

---

## 7. Configuration

### Database
Inherited from Gibbon's `config.php`:
```php
$databaseServer    // MySQL host
$databaseUsername  // DB user
$databasePassword  // DB password
$databaseName      // Database name
$databasePort      // Port (default 3306)
```

### SMS Settings
Stored in `gibbonSetting` table with scope `'Transport'`:
```php
'smsProvider'      // 'infobip' or other
'smsApiKey'        // Gateway API key
'smsApiSecret'     // Gateway secret
'smsBaseUrl'       // Gateway endpoint URL
'smsSenderID'      // From name/ID
'smsEnabled'       // 1 = enabled, 0 = disabled
```

---

## 8. Version History

### v1.0.0 (Initial Release)
- Basic routes, stops, and student assignment
- Simple boarding event recording
- Basic API endpoints

### v1.1.0
- Added supervisor support (toggle + person assignment)
- Added stop-level student assignments (from route-only)
- Improved event tracking

### v1.2.0 (Current)
- Route type differentiation (to_school, from_school, both)
- Enhanced stop tracking in events
- Improved API responses

### Migration Path
- v1.0 → v1.1: `sql/migrate_v1.0_to_v1.1.sql`
- v1.1 → v1.2: `sql/migrate_v1.1_to_v1.2.sql`

---

## 9. Dependencies & Integrations

### Gibbon LMS
- Uses `gibbonPerson` table for staff and students
- Leverages Gibbon's form framework and utilities
- Utilizes Gibbon's settings system
- Integrates with Gibbon's role/permission system
- Uses Gibbon's translation system (`__()`)

### External APIs
- **Infobip:** SMS gateway for parent notifications
- **Mobile Apps:** JSON APIs for offline-capable mobile clients

### Technologies
- **PHP:** Server-side logic (5.6+ / 7.x compatible)
- **MySQLi:** Database driver with prepared statements
- **JSON:** API responses
- **HTML/CSS:** Server-rendered UI with Gibbon styling

---

## 10. Testing Endpoints

### Manual API Testing
```bash
# Test routes list
curl http://localhost/index.php?q=/modules/Transport/api/routes.php?action=list

# Test SMS
curl -X POST http://localhost/index.php?q=/modules/Transport/sms_test.php \
  -d "recipients=+243123456789" \
  -d "message=Test message"
```

---

## 11. Performance Considerations

### Database Optimization
- ✅ Proper indexes on foreign keys and common query filters
- ✅ Composite index on (gibbonTransportRouteID, sequenceNumber) for ordered retrieval
- ✅ Separate indexes for filtering by date, status, emergency flag

### Scalability
- BIGINT for `gibbonTransportEventID` supports high-volume event logging
- Prepared statements prevent query optimization issues
- Proper indexing enables fast queries even with millions of records

### Recommendations
- ⚠️ Implement database archiving for events older than 1-2 years
- ⚠️ Add query caching for frequently accessed data (routes list)
- ⚠️ Implement pagination on large result sets
- ⚠️ Monitor slow query log and optimize as needed

---

## 12. File Structure

```
Transport Module Root/
├── index.php                    (Dashboard)
├── manifest.php                 (Module definition, DB schema)
├── routes_manage.php            (List routes)
├── routes_manage_add.php        (Create route)
├── routes_manage_edit.php       (Edit route)
├── stops_manage.php             (List stops)
├── stops_manage_add.php         (Create stop)
├── stops_manage_edit.php        (Edit stop)
├── students_manage.php          (Assign students)
├── students_manage_add.php      (Add student to route)
├── boarding_start.php           (Record boarding events)
├── attendance_daily.php         (View/verify daily attendance)
├── reports_routes.php           (Analytics/reports)
├── sms_broadcast.php            (Send SMS to parents)
├── sms_test.php                 (Test SMS functionality)
├── settings.php                 (Configure SMS settings)
├── api_keys_manage.php          (Manage API authentication)
├── api_keys_manage_add.php      (Create new API key)
├── api/
│   ├── index.php                (API gateway/documentation)
│   ├── routes.php               (Routes endpoints)
│   ├── alerts.php               (Alert management endpoints)
│   ├── events.php               (Boarding events endpoints)
│   └── sms_test.php             (SMS testing endpoint)
├── ajax/
│   ├── getStopsByRoute.php      (Fetch stops for route)
│   └── staffSearch.php          (Search staff members)
├── sql/
│   ├── migrate_v1.0_to_v1.1.sql (v1.0→v1.1 migration)
│   └── migrate_v1.1_to_v1.2.sql (v1.1→v1.2 migration)
└── DOCUMENTATION.md             (This file)
```

---

# Project Gaps & Recommendations

## Critical Gaps

### 1. **Missing Mobile App Integration**
**Status:** API structure exists but incomplete implementation

**Gaps:**
- Mobile app not included in repository
- Offline sync mechanism (syncStatus field) not fully implemented
- No mobile app authentication documentation
- Missing sync conflict resolution logic

**Impact:** Medium - Mobile offline capability not usable

**Recommended Action:** Develop React Native or Flutter mobile app with:
- Offline event recording
- Background sync on connection
- GPS tracking integration
- Photo capture and compression
- Biometric driver authentication

---

### 2. **No Real-Time GPS Tracking**
**Status:** Database fields exist but feature not implemented

**Gaps:**
- Latitude/longitude fields in events and stops but no tracking logic
- No map visualization
- No geofencing for automatic event triggering
- No route deviation detection

**Impact:** High - Safety feature incomplete

**Recommended Action:**
- Implement WebSocket-based real-time tracking
- Integrate Google Maps or OpenStreetMap
- Add geofencing alerts for stops
- Visualize route progress on dashboard

---

### 3. **Incomplete Alert System**
**Status:** Database schema exists but alert triggering logic missing

**Gaps:**
- Alert creation logic not visible in codebase
- No automatic alert triggering on missing students
- No escalation workflow (automatic SMS → admin notification)
- No alert resolution workflow UI

**Impact:** High - Safety system incomplete

**Recommended Action:**
- Implement automatic alert generation:
  - Student not boarded when expected
  - Vehicle deviates from route
  - Speed threshold exceeded
  - Late arrival beyond threshold
- Add admin dashboard for alert management
- Implement escalation: SMS → Email → System alert
- Create alert resolution workflow with notes

---

### 4. **SMS Integration Incomplete**
**Status:** Settings configured but actual sending not visible

**Gaps:**
- No visible SMS sending implementation (likely in external integration)
- No SMS delivery tracking
- No SMS history/log review
- No message template management
- No scheduled SMS support

**Impact:** Medium - Core notification feature questionable

**Recommended Action:**
- Implement Infobip API wrapper class
- Add message queue for reliable delivery
- Create SMS history viewer
- Build message template system
- Add delivery status tracking

---

### 5. **Missing Photo Verification System**
**Status:** Database fields exist but no upload/storage system

**Gaps:**
- `photoUrl` field exists but no upload mechanism
- No photo storage backend
- No image compression/optimization
- No privacy/GDPR compliance measures
- No bulk photo deletion

**Impact:** Medium - Evidence collection incomplete

**Recommended Action:**
- Implement secure file upload system
- Add image compression/optimization
- Store photos with access control
- Implement photo retention policy
- Add GDPR-compliant bulk deletion

---

### 6. **No Export/Report Generation**
**Status:** Reports module listed but not fully implemented

**Gaps:**
- No CSV export functionality
- No PDF report generation
- No scheduled report emails
- No custom report builder
- No analytics dashboard

**Impact:** Low-Medium - Business intelligence limited

**Recommended Action:**
- Add CSV/PDF export for:
  - Attendance records
  - Route performance
  - Alert logs
  - Student assignments
- Implement scheduled report emails
- Build dashboard with charts:
  - On-time percentage by route
  - Capacity utilization
  - Alert frequency
  - Student absence trends

---

### 7. **Missing Parent Portal**
**Status:** No parent-facing interface

**Gaps:**
- Parents can't view their child's boarding status
- No SMS notification links to portal
- No real-time tracking for parents
- No attendance history for parents

**Impact:** High - Parent communication incomplete

**Recommended Action:**
- Build parent portal with:
  - Real-time boarding status
  - Route tracking map
  - Attendance history
  - Alert notifications
  - Emergency contact updates
  - SMS notification preferences

---

### 8. **No Driver/Supervisor Mobile App**
**Status:** API structure for mobile but no actual app

**Gaps:**
- No offline event recording
- No in-vehicle experience
- No photo attachment UI
- No GPS integration

**Impact:** High - On-ground recording incomplete

**Recommended Action:**
- Native iOS/Android app or React Native/Flutter
- Core features:
  - Offline event recording
  - Camera integration for photos
  - GPS auto-location
  - Voice notes option
  - Background sync
  - Driver authentication

---

### 9. **Incomplete User/Permission Management**
**Status:** Relies on Gibbon roles, no custom role matrix

**Gaps:**
- No custom Transport roles (if needed beyond Gibbon roles)
- No granular permission matrix
- No staff user management UI within module
- No audit log viewer

**Impact:** Low - Relies on Gibbon system

**Recommended Action:**
- Document required Gibbon roles
- Create permission matrix
- Add audit log viewer for compliance
- Document role-based workflows

---

### 10. **Missing Emergency Response System**
**Status:** Emergency flag exists but response workflow missing

**Gaps:**
- No emergency contact escalation
- No emergency notification template
- No automated emergency response workflow
- No incident documentation system

**Impact:** High - Safety-critical gap

**Recommended Action:**
- Emergency response workflow:
  - Flag set → SMS to parent
  - No response → SMS to school admin
  - No response → SMS to emergency contact
  - Create incident report template
  - Assign incident to admin for follow-up

---

### 11. **No Analytics/Dashboard**
**Status:** Basic dashboard exists but no advanced analytics

**Gaps:**
- No performance metrics by route/driver
- No trend analysis
- No predictive analytics
- No data visualization

**Impact:** Low - Business intelligence missing

**Recommended Action:**
- Add dashboard charts:
  - On-time percentage trends
  - Capacity utilization trends
  - Alert frequency heatmaps
  - Driver performance scorecards
  - Student absence patterns

---

### 12. **Missing Compliance/Audit Features**
**Status:** Data structure supports audit but no UI/reports

**Gaps:**
- No user activity audit log viewer
- No data change history
- No regulatory report generation
- No data retention policy enforcement
- No GDPR compliance features

**Impact:** Medium - Regulatory compliance risk

**Recommended Action:**
- Audit log viewer for all operations
- Data retention policy settings
- Automated data purging
- GDPR compliance features:
  - Right to be forgotten
  - Data export
  - Consent tracking
- Regulatory report templates

---

## Enhancement Opportunities

### Priority 1 (Critical)
1. **Real-Time GPS Tracking** - Enable live vehicle monitoring
2. **Alert System Completion** - Automate missing student detection
3. **Mobile App (Driver)** - Enable on-ground event recording
4. **Parent Portal** - Enable parent engagement
5. **Emergency Response Workflow** - Safety critical

### Priority 2 (Important)
6. **SMS Integration Verification** - Ensure parent communication works
7. **Photo Upload System** - Enable visual verification
8. **Export/Reporting** - Enable business intelligence
9. **Audit Log Viewer** - Enable compliance
10. **Analytics Dashboard** - Enable performance monitoring

### Priority 3 (Nice-to-Have)
11. **Mobile App (Parents)** - Enhance parent engagement
12. **Advanced Reports** - Predictive analytics
13. **Automated Messaging** - Template-based notifications
14. **Supervisor Portal** - Role-specific interface
15. **Integration Tests** - API testing suite

---

# Implementation Plan

## Phase 1: Foundation (Weeks 1-3)
**Goal:** Complete core missing features

### Week 1: Gap Analysis & Code Cleanup
- [ ] Complete SMS integration implementation
- [ ] Verify Infobip API integration
- [ ] Add SMS history/tracking
- [ ] Unit tests for SMS module
- [ ] Documentation: SMS configuration guide

### Week 2: Photo Upload System
- [ ] Implement secure file upload
- [ ] Image compression/optimization
- [ ] Photo storage backend
- [ ] Photo verification workflow
- [ ] GDPR compliance settings
- [ ] Documentation: Photo upload guide

### Week 3: Alert System Completion
- [ ] Implement alert triggering logic
  - Missing student detection
  - Late arrival detection
  - Route deviation detection (if GPS available)
- [ ] Alert escalation workflow
- [ ] Admin alert dashboard
- [ ] Alert resolution workflow
- [ ] Documentation: Alert system guide

**Deliverables:**
- SMS fully functional
- Photo upload working
- Alert system operational
- Updated documentation

---

## Phase 2: Mobile Apps (Weeks 4-8)
**Goal:** Enable on-ground operations and parent engagement

### Week 4-5: Driver Mobile App (MVP)
**Tech:** React Native (cross-platform) or Flutter
- [ ] App structure and navigation
- [ ] Driver authentication (API key)
- [ ] Offline event recording
- [ ] Camera integration for photos
- [ ] GPS auto-location capture
- [ ] Background sync mechanism
- [ ] Real-time status indicators

### Week 6-7: Parent Portal/Mobile App
- [ ] Parent authentication
- [ ] Real-time boarding status
- [ ] Route map visualization
- [ ] Attendance history
- [ ] SMS notification links
- [ ] Alert notifications
- [ ] Contact management
- [ ] Notification preferences

### Week 8: Integration & Testing
- [ ] API endpoints testing
- [ ] Offline sync testing
- [ ] User acceptance testing
- [ ] Performance testing

**Deliverables:**
- Driver mobile app (iOS + Android)
- Parent web portal
- Parent mobile app
- API endpoints fully tested

---

## Phase 3: Real-Time Features (Weeks 9-11)
**Goal:** Enable live tracking and immediate response

### Week 9: GPS Real-Time Tracking
- [ ] WebSocket backend for real-time updates
- [ ] Map visualization (Google Maps/Leaflet)
- [ ] Live vehicle location updates
- [ ] Geofencing implementation
- [ ] Stop arrival/departure detection

### Week 10: Automated Alerts
- [ ] Student missing boarding alert
- [ ] Late arrival automatic detection
- [ ] Route deviation detection
- [ ] Speed threshold alerts
- [ ] SMS auto-trigger on alerts

### Week 11: Integration & Testing
- [ ] End-to-end testing
- [ ] Load testing
- [ ] UAT with real users

**Deliverables:**
- Real-time tracking system
- Automated alert system
- Admin dashboard with live updates

---

## Phase 4: Analytics & Compliance (Weeks 12-13)
**Goal:** Enable business intelligence and regulatory compliance

### Week 12: Analytics Dashboard
- [ ] Performance metrics by route
- [ ] Driver performance scorecard
- [ ] Student attendance trends
- [ ] Alert frequency analysis
- [ ] Capacity utilization tracking
- [ ] Charts and visualizations

### Week 13: Compliance & Audit
- [ ] Audit log viewer
- [ ] Data retention policies
- [ ] GDPR compliance features
- [ ] Regulatory report generation
- [ ] Automated data purging

**Deliverables:**
- Analytics dashboard
- Compliance tools
- Audit system

---

## Phase 5: Optimization & Documentation (Week 14-15)
**Goal:** Polish and document

### Week 14: Performance Optimization
- [ ] Database query optimization
- [ ] API caching
- [ ] Image optimization
- [ ] Load testing and tuning
- [ ] Mobile app optimization

### Week 15: Comprehensive Documentation
- [ ] API documentation (Swagger/OpenAPI)
- [ ] Mobile app user guides
- [ ] Parent portal guide
- [ ] Admin guide
- [ ] Installation guide
- [ ] Troubleshooting guide
- [ ] Architecture documentation

**Deliverables:**
- Optimized system
- Complete documentation
- User guides for all roles

---

## Technical Implementation Notes

### SMS Integration (Phase 1, Week 1)
```php
// Create TransportSMS class
class TransportSMS {
    private $apiKey;
    private $apiSecret;
    private $baseUrl;
    
    public function sendSMS($recipients, $message) {
        // Infobip API implementation
        // Return delivery status
    }
    
    public function getDeliveryStatus($messageId) {
        // Query delivery status
    }
}
```

### Photo Upload System (Phase 1, Week 2)
```php
// Directory structure
/uploads/transport/photos/YYYY/MM/
  - boarding_events_photos/
  - verification_photos/

// Image handling
- Compress to 1080px width
- Convert HEIC to JPG
- Store metadata (photographer, timestamp)
- Generate thumbnails
```

### Alert System (Phase 1, Week 3)
```php
// Alert triggers (cron job - every 5 minutes)
- For each route scheduled for today:
  - For each student assigned to route:
    - Check if boarding event exists for student
    - If not exists and time passed expected pickup: trigger alert
  - For each stop on route:
    - Check if next stop event was recorded
    - If not and time passed estimated arrival: trigger alert
```

### Mobile App Architecture (Phase 2)
```
React Native / Flutter
├── Screens
│   ├── Authentication
│   ├── EventRecording (Pickup/Dropoff)
│   ├── StudentList
│   ├── RouteMap
│   └── Dashboard
├── Local Storage (SQLite)
├── Sync Service (REST API)
├── GPS Service
└── Camera Service
```

### Real-Time System (Phase 3)
```
Backend:
- Node.js/Socket.IO server
- Pub/Sub for route updates
- Redis for caching

Frontend:
- WebSocket connections
- MapBox/Google Maps integration
- Real-time position updates
```

---

## Risk Mitigation

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Mobile app platform fragmentation | High | Medium | Use React Native or Flutter for code sharing |
| GPS accuracy issues | Medium | Medium | Implement geofencing buffer zones, test in deployment region |
| SMS delivery failures | Medium | High | Implement retry logic, fallback to email, delivery tracking |
| Data sync conflicts (offline) | Medium | Medium | Implement last-write-wins strategy, manual conflict resolution |
| Parent adoption low | Medium | Medium | Implement SMS link-based access, simple UI, local language support |
| Performance degradation at scale | Low | High | Implement caching, optimize queries, archive old data |
| GDPR compliance issues | Medium | High | Implement data deletion, consent tracking, audit logs |
| Integration with Gibbon breaking | Low | High | Version lock Gibbon version, regression testing |

---

## Success Metrics

### Phase 1 (Foundation)
- SMS delivery success rate > 95%
- Photo uploads working for 100% of events
- Alert system triggers correctly 99% of the time
- Zero data loss in sync

### Phase 2 (Mobile Apps)
- Driver app: > 90% uptime
- Parent app: > 50% adoption within 3 months
- Offline sync: 99.9% success
- API response time < 200ms

### Phase 3 (Real-Time)
- GPS accuracy within 20m in open area, 50m in urban
- Real-time updates < 2 second latency
- Alert notification SMS delivery < 1 minute
- Zero missed events

### Phase 4 (Analytics)
- Dashboard loads in < 3 seconds
- Analytics queries complete in < 5 seconds
- 100% compliance with data retention policy
- Audit log completeness > 99%

---

## Next Steps

1. **Immediate (This Week):**
   - Review and approve implementation plan
   - Set up development environment
   - Begin Phase 1, Week 1 (SMS verification)

2. **Short-term (Weeks 2-4):**
   - Complete Phase 1 (SMS, Photos, Alerts)
   - Conduct internal testing
   - Begin mobile app development

3. **Medium-term (Weeks 5-10):**
   - Complete mobile apps
   - Implement real-time tracking
   - User acceptance testing

4. **Long-term (Weeks 11-15):**
   - Analytics and compliance
   - Performance optimization
   - Documentation and release

---

## Appendix: Quick Reference

### Environment Setup
```bash
# Clone module into Gibbon
cp -r Transport /path/to/gibbon/modules/Transport

# Database
mysql -u root -p gibbon < manifest.php

# API Testing
curl http://localhost/index.php?q=/modules/Transport/api/routes.php?action=list
```

### Key Database Queries
```sql
-- Active routes with drivers
SELECT r.*, p.firstName, p.surname 
FROM gibbonTransportRoute r
LEFT JOIN gibbonPerson p ON r.driverID = p.gibbonPersonID
WHERE r.active = 1
ORDER BY r.name;

-- Today's boarding events
SELECT e.*, s.firstName, s.surname, r.name
FROM gibbonTransportEvent e
JOIN gibbonPerson s ON e.gibbonPersonID = s.gibbonPersonID
JOIN gibbonTransportRoute r ON e.gibbonTransportRouteID = r.gibbonTransportRouteID
WHERE DATE(e.timestamp) = CURDATE()
ORDER BY e.timestamp DESC;

-- Students missing boarding
SELECT ts.gibbonTransportStudentID, p.firstName, p.surname, tr.name
FROM gibbonTransportStudent ts
JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
JOIN gibbonTransportRoute tr ON ts.gibbonTransportRouteID = tr.gibbonTransportRouteID
WHERE ts.status = 'Active'
AND NOT EXISTS (
    SELECT 1 FROM gibbonTransportEvent 
    WHERE gibbonPersonID = ts.gibbonPersonID 
    AND DATE(timestamp) = CURDATE()
    AND type = 'pickup'
);
```

### Useful Settings Keys
```
Transport_smsEnabled        // 0 or 1
Transport_smsProvider       // 'infobip'
Transport_smsApiKey         // API key
Transport_smsApiSecret      // API secret
Transport_smsBaseUrl        // Gateway URL
Transport_smsSenderID       // From name
Transport_photoStoragePath  // Local path
Transport_gpsTrackingEnabled // 0 or 1
Transport_alertsEnabled     // 0 or 1
```

---

*Document Version: 1.0*  
*Last Updated: June 14, 2026*  
*Next Review: After Phase 1 Completion*
