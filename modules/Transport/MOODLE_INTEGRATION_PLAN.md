# Moodle LMS Integration Plan

**Status:** Planning Phase  
**Date:** June 14, 2026  
**Priority:** Medium  
**Complexity:** High

---

## Executive Summary

You have **three integration approaches**. I recommend **Option 2 (Recommended)** as the best balance between capability and complexity.

| Option | Approach | Effort | Time | Best For |
|--------|----------|--------|------|----------|
| **1** | Direct API-to-API | Low | 1-2 weeks | Quick data sync |
| **2 (Recommended)** | Bidirectional REST Bridge | Medium | 2-3 weeks | Full integration |
| **3** | Moodle Plugin | High | 4-6 weeks | Deep Moodle integration |

---

## Option 1: Direct API-to-API Integration (Quick Path)

### What It Is
- Gibbon → Moodle via HTTP requests
- Moodle → Gibbon via HTTP requests
- Simple data synchronization

### Architecture
```
Gibbon Transport Module
        ↓ (HTTP API calls)
Moodle Web Services API
        ↓ (Webhooks/Callbacks)
Gibbon Transport Module
```

### Gibbon Side (What You Need to Build)
- REST endpoints to expose Transport data:
  - `GET /api/v1/students` - Student info
  - `GET /api/v1/routes` - Route info
  - `GET /api/v1/alerts` - Alert data
  - `GET /api/v1/attendance` - Boarding records
- Webhook receivers to accept Moodle data:
  - `POST /api/v1/webhooks/moodle/grade-updated`
  - `POST /api/v1/webhooks/moodle/user-enrolled`

### Moodle Side (Configuration Only)
- Enable Moodle Web Services
- Create service account
- Expose standard Moodle APIs:
  - User data (via standard API)
  - Course enrollments
  - Grades
  - Custom webhooks for events

### Use Cases Enabled
- ✅ Display Gibbon student transport status in Moodle course
- ✅ Sync Moodle grades to Gibbon (future)
- ✅ User enrollment sync
- ✅ Alert notifications to Moodle

### Pros
- **Low effort** - Only expose existing Gibbon data
- **Fast to implement** - 1-2 weeks
- **Good for read-heavy** - Mostly syncing data one way

### Cons
- ❌ **Limited two-way sync** - Mostly one-direction
- ❌ **No unified user experience** - Users see two systems
- ❌ **Manual data reconciliation** - If data gets out of sync
- ❌ **No real-time updates** - Polling-based only

---

## Option 2: Bidirectional REST Bridge (RECOMMENDED)

### What It Is
- **Bridge layer** between Gibbon and Moodle
- Unified REST API that translates between systems
- Real-time data sync with webhooks

### Architecture
```
┌─────────────────────────────────────────────┐
│         Unified REST API (Bridge)           │
│  - Normalizes data from both systems        │
│  - Handles authentication for both          │
│  - Manages bidirectional sync               │
└─────────────────────────────────────────────┘
         ↙                            ↖
    Gibbon API            ←→          Moodle Web Services API
   (Transport data)                    (User, Course, Grade data)
```

### Implementation Structure

#### 1. Create Unified API Endpoints
```
/api/v2/unified/
├── /students/          (GET/POST)      - Unified student data
├── /courses/           (GET)           - Unified course/route data
├── /attendance/        (GET/POST)      - Unified attendance/enrollment
├── /users/             (GET/POST)      - Unified user data
├── /sync/              (POST)          - Manual sync trigger
└── /webhooks/          (POST)          - Webhook receivers
    ├── /gibbon/*       - Gibbon events
    ├── /moodle/*       - Moodle events
    └── /transport/*    - Transport events
```

#### 2. Data Mapping Layer
```php
class GibbonMoodleBridge {
    // Converts Gibbon student format → Unified format → Moodle format
    public function normalizeStudent($gibbonStudent);
    public function mapToGibbon($unifiedStudent);
    public function mapToMoodle($unifiedStudent);
    
    // Similar for other entities
    public function normalizeRoute($route);
    public function normalizeAttendance($record);
}
```

#### 3. Sync Manager
```php
class SyncManager {
    // Automatic sync
    public function syncStudentsToMoodle();
    public function syncEnrollmentsToGibbon();
    
    // Conflict resolution
    public function resolveConflict($entity, $gibbonVersion, $moodleVersion);
    
    // Audit trail
    public function logSync($action, $data, $status);
}
```

### Gibbon Side (What You Build)

**New Files:**
1. `api/v2/index.php` - V2 API entry point
2. `lib/GibbonMoodleBridge.php` - Data mapping (400 lines)
3. `lib/MoodleSyncManager.php` - Sync logic (350 lines)
4. `lib/WebhookHandler.php` - Webhook receiver (200 lines)
5. `api/v2/unified/students.php` - Student endpoints
6. `api/v2/unified/courses.php` - Course/route endpoints
7. `api/v2/unified/attendance.php` - Attendance endpoints
8. `api/v2/webhooks/moodle.php` - Moodle webhook receiver
9. `cron_moodle_sync.php` - Periodic sync job
10. `settings_moodle_integration.php` - Integration settings page

**Total: ~1,500-2,000 lines of code**

### Moodle Side (Plugin)

**Create Moodle Plugin:**
- `local/gibbon_transport/` - Local plugin
  - `version.php` - Plugin definition
  - `lib.php` - Integration hooks
  - `settings.php` - Configuration
  - `lang/` - Language strings
  - `externallib.php` - External functions (if API wrapper needed)

**Configuration in Moodle:**
- Enable local plugin
- Set Gibbon bridge URL
- Set API key for authentication
- Enable webhook notifications

### Use Cases Enabled
- ✅ **Real-time student transport status in Moodle courses**
- ✅ **Display boarding alerts in Moodle dashboard**
- ✅ **Auto-enroll students in transport tracking courses**
- ✅ **Sync student data bidirectionally**
- ✅ **Transport attendance as course attendance**
- ✅ **SMS alerts integrated with Moodle notifications**
- ✅ **Unified user management**

### Data Flows

#### Flow 1: Student Status in Moodle Course
```
1. Moodle displays Transport course
2. Moodle calls: GET /api/v2/unified/students/{id}/status
3. Bridge queries Gibbon for latest boarding status
4. Returns: { boarded: true, route: "Route 5", lastUpdate: "14:30" }
5. Moodle displays: "✓ Boarded at 2:30 PM"
```

#### Flow 2: Alert Notification
```
1. Transport module detects missing boarding
2. Sends webhook: POST /api/v2/webhooks/transport/alert-created
3. Bridge processes alert
4. Creates Moodle notification for parent
5. Sends SMS (via TransportSMS)
6. Updates audit trail
```

#### Flow 3: Enrollment Sync
```
1. Student enrolls in course in Moodle
2. Webhook: POST /api/v2/webhooks/moodle/user-enrolled
3. Bridge identifies as transport-relevant
4. Auto-adds student to transport route in Gibbon
5. Syncs attendance between systems daily
```

### Pros
- ✅ **Full bidirectional integration** - Data flows both ways
- ✅ **Unified API** - Single endpoint for both systems
- ✅ **Real-time sync** - Webhook-based, not polling
- ✅ **Data normalization** - Consistent format regardless of source
- ✅ **Conflict resolution** - Handle duplicate/conflicting data
- ✅ **Audit trail** - Complete sync history
- ✅ **Extensible** - Easy to add more data types
- ✅ **Medium effort** - 2-3 weeks to implement

### Cons
- ⚠️ **More complex** than Option 1
- ⚠️ **Requires Moodle plugin** - Need to maintain both codebases
- ⚠️ **Sync conflicts** - Need conflict resolution logic
- ⚠️ **Testing complexity** - Must test both systems together

### Timeline (2-3 weeks)

| Week | Task | Deliverables |
|------|------|--------------|
| **Week 1** | Core bridge setup | GibbonMoodleBridge class, API v2 structure |
| **Week 2** | Endpoint implementation | Unified endpoints, sync manager, webhooks |
| **Week 3** | Testing & polish | Integration tests, error handling, documentation |

---

## Option 3: Full Moodle Plugin (Deep Integration)

### What It Is
- Build a native Moodle plugin that integrates Transport directly
- Moodle plugin handles all integration logic
- Gibbon provides data via API only

### Architecture
```
Moodle
├── Plugin: local_gibbon_transport
│   ├── API calls to Gibbon
│   ├── Cron jobs for sync
│   ├── UI blocks/reports
│   └── Event observers
└── Standards web services
```

### Effort & Timeline
- **Implementation:** 4-6 weeks
- **Maintenance:** Moodle + Gibbon codebases
- **Complexity:** Highest

### When to Choose This
- ✅ Moodle is your primary system
- ✅ Transport is secondary to Moodle
- ✅ Deeply integrated UI desired
- ✅ Large Moodle ecosystem focus

---

## Recommended Choice: Option 2

### Why Option 2?

1. **Balance:** Complexity vs capability
2. **Flexibility:** Works for both Gibbon-first and Moodle-first scenarios
3. **Reusable:** Bridge can integrate with other LMS platforms later
4. **Maintainable:** Single codebase for integration logic
5. **Scalable:** Easy to add more data types and use cases

### Implementation Priority

**Phase 1: Foundation (Week 1)**
- [ ] Create `/api/v2/` structure
- [ ] Build GibbonMoodleBridge class
- [ ] Implement student data mapping
- [ ] Create authentication/API keys for Moodle

**Phase 2: Endpoints (Week 2)**
- [ ] Implement `/api/v2/unified/students`
- [ ] Implement `/api/v2/unified/attendance`
- [ ] Build webhook receivers
- [ ] Create MoodleSyncManager

**Phase 3: Sync & Testing (Week 3)**
- [ ] Implement cron sync job
- [ ] Create settings page
- [ ] Integration testing
- [ ] Documentation

---

## Quick Comparison Table

```
┌─────────────────────┬────────────┬────────────┬─────────────┐
│ Feature             │ Option 1   │ Option 2   │ Option 3    │
├─────────────────────┼────────────┼────────────┼─────────────┤
│ Effort              │ ⭐ Low     │ ⭐⭐ Med   │ ⭐⭐⭐ High │
│ Time (weeks)        │ 1-2        │ 2-3        │ 4-6         │
│ Two-way sync        │ ❌ Limited │ ✅ Full    │ ✅ Full     │
│ Real-time updates   │ ❌ No      │ ✅ Yes     │ ✅ Yes      │
│ Unified API         │ ❌ No      │ ✅ Yes     │ ❌ No       │
│ Scalability         │ ⚠️ Medium  │ ✅ Good    │ ✅ Good     │
│ Maintenance         │ ⭐ Easy    │ ⭐⭐ Med   │ ⭐⭐⭐ Hard │
│ Moodle dependency   │ ⭐ Low     │ ⭐⭐ Med   │ ⭐⭐⭐ High │
│ Future-proof        │ ❌ No      │ ✅ Yes     │ ⚠️ Medium   │
└─────────────────────┴────────────┴────────────┴─────────────┘
```

---

## Data Entities to Sync

### 1. Students
```
Gibbon → Unified → Moodle
- gibbonPersonID → student_id
- firstName/surname → fullname
- email → email
- phone → phone
- route enrollment → course enrollment
```

### 2. Routes/Courses
```
Gibbon Route ↔ Moodle Course
- routeID → course context
- routeName → course name
- stops → course sections
- students → enrolled users
```

### 3. Attendance/Boarding
```
Gibbon Boarding Event → Moodle Activity
- boarded (yes/no) → attendance
- timestamp → time attended
- route stop → activity location
- driver → activity conductor
```

### 4. Alerts
```
Gibbon Transport Alert → Moodle Notification
- alert type → notification type
- severity → priority level
- recipients → parent users
- SMS also → Moodle message
```

### 5. Users/Parents
```
Gibbon Parent → Moodle Parent User
- gibbonFamilyAdultID → user_id
- email → email
- phone → phone
- associated students → enrolled courses
```

---

## Security Considerations

### API Key Management
```php
// Gibbon: Generate secure keys
- API key length: 32+ characters
- Rotation: Every 90 days
- Scoping: Different keys for different Moodle instances
- Rate limiting: 100 req/min per key
```

### Data Validation
```php
// Both sides
- Validate all incoming webhook data
- Sanitize database inputs
- Verify webhook signatures (HMAC)
- Log all sync operations
```

### Access Control
```
// Moodle can access:
- Student roster for enrolled courses
- Boarding status for enrolled students
- Parent contact info for family

// Gibbon can access:
- User enrollment data
- Course participation
- Grade data (if applicable)
```

---

## Testing Strategy

### Unit Tests
```
- GibbonMoodleBridge mapping functions
- Data normalization logic
- SyncManager conflict resolution
```

### Integration Tests
```
- Full student sync: Gibbon → Moodle → Gibbon
- Webhook handling: Event → API call → Data update
- Attendance sync: Boarding → Moodle attendance
```

### End-to-End Tests
```
1. Create student in Gibbon
2. Sync to Moodle
3. Enroll in course
4. Mark attendance
5. Sync back to Gibbon
6. Verify data integrity
```

---

## Success Metrics

- ✅ Data sync time < 1 second
- ✅ 99.9% webhook delivery success
- ✅ Zero data loss/corruption in sync
- ✅ Full audit trail of all sync operations
- ✅ <10ms API response time
- ✅ Zero manual data reconciliation needed

---

## Next Steps

### Immediate (This Week)
1. **Decision:** Approve Option 2 approach
2. **Setup:** Create `/api/v2/` directory structure
3. **Design:** Finalize data mapping schema
4. **Gibbon Setup:** Create integration settings page

### Short-term (Week 1-2)
1. Build GibbonMoodleBridge class
2. Implement student sync endpoints
3. Create webhook receivers
4. Build MoodleSyncManager

### Medium-term (Week 3)
1. Integration testing
2. Performance optimization
3. Security audit
4. Documentation

### Long-term (Post-MVP)
1. Real-time GPS/location sync
2. Grade/performance sync
3. Advanced analytics
4. Mobile app integration

---

## Questions to Answer Before Starting

1. **Primary System?** Is Gibbon or Moodle your primary LMS?
2. **Data Priority?** What data is most critical to sync?
3. **Frequency?** Real-time sync or periodic (daily)?
4. **Scale?** How many students and courses?
5. **Moodle Version?** Which Moodle version are you using?
6. **Custom Fields?** Any custom fields to sync?

---

## My Recommendation

**GO WITH OPTION 2** because:

1. **You already have** a basic API structure (api/index.php)
2. **Better ROI** - 2-3 weeks for full bidirectional integration
3. **More flexible** - Not locked into Moodle-first approach
4. **Reusable bridge** - Can integrate other LMS later
5. **Maintainable** - Single integration codebase
6. **Scalable** - Easy to add more features incrementally
7. **Best balance** - Not too simple (Option 1), not too complex (Option 3)

---

## File: Ready for Implementation

When you approve Option 2, I'll create:
1. `api/v2/index.php` - API router
2. `lib/GibbonMoodleBridge.php` - Data mapping
3. `lib/MoodleSyncManager.php` - Sync logic
4. All unified endpoints
5. Configuration and documentation

**Ready to proceed?**
