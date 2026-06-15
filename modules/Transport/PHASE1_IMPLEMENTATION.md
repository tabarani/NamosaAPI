# Transport Module - Phase 1 Implementation Guide

**Completed:** SMS Verification, Photo Upload System, Alert Triggering Logic  
**Date:** June 2026  
**Status:** Ready for Integration & Testing

---

## 1. New Components Overview

### 1.1 Core Libraries

#### `lib/TransportSMS.php` - SMS Service Class
**Purpose:** Centralized SMS handling with support for multiple providers

**Features:**
- ✅ Multi-provider support (Infobip, Twilio, Africa's Talking, Nexmo)
- ✅ SMS sending with delivery tracking
- ✅ SMS history logging for audit trail
- ✅ Phone number validation and normalization
- ✅ Message length validation (160 chars max)

**Key Methods:**
```php
$smsService = new TransportSMS($connection, $guid);

// Send SMS
$result = $smsService->sendSMS($recipients, $message, ['routeID' => 1]);
// Returns: ['success' => bool, 'messageID' => string, 'error' => string]

// Check if SMS is enabled
if ($smsService->isEnabled()) { ... }

// Get SMS history
$history = $smsService->getHistory($limit = 50, $filters = []);
```

**Usage in Code:**
```php
require_once __DIR__ . '/lib/TransportSMS.php';
$smsService = new TransportSMS($connection2, $guid);

// Send to single recipient
$smsService->sendSMS('+243123456789', 'Hello World');

// Send to multiple recipients
$smsService->sendSMS(['+243123456789', '+243987654321'], 'Hello World');

// Send with metadata for tracking
$smsService->sendSMS($phones, $message, [
    'routeID' => 1,
    'studentID' => 123,
    'alertID' => 456
]);
```

---

#### `lib/TransportPhotoUpload.php` - Photo Upload Handler
**Purpose:** Secure file upload, compression, and storage for boarding event evidence

**Features:**
- ✅ Secure file upload with MIME type validation
- ✅ Image compression (resize > 1920px, JPEG quality 75%)
- ✅ HEIC to JPEG conversion (requires ImageMagick)
- ✅ Thumbnail generation for previews
- ✅ Organized directory structure (YYYY/MM)
- ✅ Database record linking to events
- ✅ Photo metadata tracking (uploader, type, size)

**Key Methods:**
```php
$photoUpload = new TransportPhotoUpload($connection, $guid);

// Upload from POST request
$result = $photoUpload->uploadFromPost('photoFile', $eventID, 'boarding_event');
// Returns: ['success' => bool, 'photoUrl' => string, 'error' => string]

// Get photo info for event
$photo = $photoUpload->getPhotoByEvent($eventID);

// Delete photo by event
$photoUpload->deletePhotoByEvent($eventID);
```

**Directory Structure:**
```
/uploads/transport/photos/
├── 2026/
│   ├── 06/
│   │   ├── boarding_20260614120530_a1b2c3d4.jpg
│   │   ├── boarding_20260614120545_b2c3d4e5.jpg
│   │   └── thumbs/
│   │       ├── boarding_20260614120530_a1b2c3d4.jpg (200px)
│   │       └── boarding_20260614120545_b2c3d4e5.jpg (200px)
│   └── .htaccess (prevents execution)
```

**Allowed File Types:**
- JPEG (image/jpeg)
- PNG (image/png)
- HEIC (image/heic) - auto-converted to JPEG
- WebP (image/webp)

**Max File Size:** 5MB (configurable)

---

#### `lib/TransportAlerts.php` - Alert Triggering System
**Purpose:** Automatic detection and escalation of transportation safety issues

**Features:**
- ✅ Automatic missing student detection
- ✅ Late arrival detection
- ✅ Alert creation with SMS notifications
- ✅ Severity levels (critical, high, medium, low)
- ✅ Alert resolution workflow
- ✅ Audit trail (who resolved, when, why)
- ✅ SMS delivery tracking

**Key Methods:**
```php
$alerts = new TransportAlerts($connection, $guid, $smsService);

// Create alert manually
$alertID = $alerts->createAlert(
    TransportAlerts::ALERT_MISSING_BOARDING,
    TransportAlerts::SEVERITY_HIGH,
    'Message to display',
    ['routeID' => 1, 'studentID' => 123, 'smsRecipients' => [$phone]]
);

// Run automated checks
$missingCount = $alerts->checkMissingBoardings();
$lateCount = $alerts->checkLateArrivals(15); // 15 min threshold

// Get unresolved alerts
$unresolved = $alerts->getUnresolvedAlerts($limit = 50);

// Get critical alerts only
$critical = $alerts->getCriticalAlerts();

// Resolve alert
$alerts->resolveAlert($alertID, 'Notes about resolution', $resolvedBy);
```

**Alert Types:**
- `missing_boarding` - Student didn't board when expected
- `late_arrival` - Vehicle running late to destination
- `route_deviation` - Vehicle off planned route (requires GPS)
- `emergency` - Emergency flag set by driver/supervisor
- `custom` - Manual alerts

**Severity Levels:**
- `critical` - Requires immediate action
- `high` - Important, needs attention soon
- `medium` - Standard alert
- `low` - Informational

---

### 1.2 New Database Tables

#### `gibbonTransportSMSHistory` Table
Tracks all SMS sent through the system for audit and debugging

```sql
Fields:
- smsHistoryID: Unique identifier
- messageID: Unique message reference
- recipients: CSV of phone numbers
- message: SMS text content
- status: sent, failed, or pending
- gibbonTransportRouteID: Associated route (optional)
- gibbonPersonID: Associated student (optional)
- gibbonTransportAlertID: Associated alert (optional)
- createdBy: Staff member who sent SMS
- timestampCreated: When SMS was sent
```

---

#### `gibbonTransportPhoto` Table
Links photos to boarding events for evidence collection

```sql
Fields:
- photoID: Unique identifier
- gibbonTransportEventID: Associated boarding event
- photoUrl: URL/path to photo file
- photoType: 'boarding_event' or 'verification'
- fileSize: Size in bytes
- uploadedBy: Staff member who uploaded
- verified: Boolean flag (for QA)
- verifiedBy: Staff member who verified
- verifiedAt: Timestamp of verification
- notes: Verification notes
- timestampCreated: Upload time
```

---

### 1.3 New UI Pages

#### `alerts_manage.php` - Alert Dashboard
**URL:** `/modules/Transport/alerts_manage.php`

**Features:**
- ✅ Real-time alert display with color-coding by severity
- ✅ Filter by severity level
- ✅ Statistics panel (critical, high, medium, low counts)
- ✅ Alert details display
- ✅ One-click alert resolution modal
- ✅ Resolution notes capture
- ✅ SMS delivery status indicator

**Access:** Requires "View Transport Dashboard" permission

---

#### `sms_broadcast_updated.php` - Improved SMS Broadcast
**URL:** `/modules/Transport/sms_broadcast.php` (replacement)

**Features:**
- ✅ Quick message templates
- ✅ Real-time character count
- ✅ Message preview before sending
- ✅ Route-specific or broadcast-to-all
- ✅ Recent SMS history sidebar
- ✅ SMS configuration status check

---

#### `sms_history.php` - SMS Audit Log
**URL:** `/modules/Transport/sms_history.php`

**Features:**
- ✅ Complete SMS history with filters
- ✅ Filter by status (sent, failed, pending)
- ✅ Filter by route
- ✅ Filter by date range
- ✅ Recipient count display
- ✅ Pagination (50 per page)
- ✅ Statistics panel

---

### 1.4 Cron Job

#### `cron_alert_trigger.php` - Automatic Alert Detection
**Purpose:** Run periodically (every 5-10 minutes) to detect and trigger alerts

**Setup Instructions:**

1. **Add to system crontab:**
```bash
# Edit crontab
crontab -e

# Add this line to run every 5 minutes
*/5 * * * * /usr/bin/php /path/to/Transport/cron_alert_trigger.php

# Or every 10 minutes
*/10 * * * * /usr/bin/php /path/to/Transport/cron_alert_trigger.php
```

2. **Make executable:**
```bash
chmod +x /path/to/Transport/cron_alert_trigger.php
```

3. **View logs:**
```bash
tail -f /path/to/gibbon/logs/transport_alerts_cron.log
```

**What It Does:**
- Checks for students not boarded when expected
- Detects late arrivals
- Creates appropriate alerts
- Sends SMS notifications to parents
- Logs all activity for audit trail

---

## 2. Integration Guide

### 2.1 Update Manifest to Add New Pages

Add to `manifest.php` actions section:

```php
// Alert Management
$actionRows[] = [
    'name' => 'Manage Safety Alerts',
    'precedence' => '55',
    'category' => 'Transport',
    'description' => 'View and manage transportation safety alerts',
    'URLList' => 'alerts_manage.php',
    'entryURL' => 'alerts_manage.php',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'Y',
    'defaultPermissionStudent' => 'N',
    'defaultPermissionParent' => 'N',
    'defaultPermissionSupport' => 'Y',
    'categoryPermissionStaff' => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent' => 'N',
    'categoryPermissionOther' => 'N',
];

// SMS History
$actionRows[] = [
    'name' => 'View SMS History',
    'precedence' => '60',
    'category' => 'Transport',
    'description' => 'View SMS delivery history and audit logs',
    'URLList' => 'sms_history.php',
    'entryURL' => 'sms_history.php',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'N',
    'defaultPermissionStudent' => 'N',
    'defaultPermissionParent' => 'N',
    'defaultPermissionSupport' => 'Y',
    'categoryPermissionStaff' => 'N',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent' => 'N',
    'categoryPermissionOther' => 'N',
];
```

---

### 2.2 Update Dashboard to Include Alerts

In `index.php`, add alert display:

```php
// Load alert system
require_once __DIR__ . '/lib/TransportAlerts.php';
$alertSystem = new TransportAlerts($connection2, $guid);

// Get critical alerts
$criticalAlerts = $alertSystem->getCriticalAlerts();

// Add critical alerts section to dashboard
if (!empty($criticalAlerts)) {
    echo '<div style="background:#ffebee;border:2px solid #f44336;border-radius:8px;padding:20px;margin:30px 0;">';
    echo '<h2 style="color:#c62828;margin:0 0 15px 0;">🚨 Critical Alerts (' . count($criticalAlerts) . ')</h2>';
    // Display alerts...
    echo '</div>';
}
```

---

### 2.3 Integrate Photo Upload into Boarding Registration

In `boarding_start.php`, add photo upload:

```php
require_once __DIR__ . '/lib/TransportPhotoUpload.php';
$photoUpload = new TransportPhotoUpload($connection2, $guid);

// In the boarding form:
echo '<div style="margin-bottom:20px;">';
echo '<label>Upload Photo (Optional):</label>';
echo '<input type="file" name="photoFile" accept="image/*">';
echo '</div>';

// After recording event:
if (!empty($_FILES['photoFile'])) {
    $photoResult = $photoUpload->uploadFromPost('photoFile', $eventID, 'boarding_event');
    if ($photoResult['success']) {
        // Update event with photo URL
        $updateStmt = $connection2->prepare("UPDATE gibbonTransportEvent SET photoUrl = ? WHERE gibbonTransportEventID = ?");
        $updateStmt->bind_param('si', $photoResult['photoUrl'], $eventID);
        $updateStmt->execute();
    }
}
```

---

## 3. Configuration

### 3.1 SMS Settings (in `settings.php`)

```php
// Provider selection
Transport_smsProvider    // 'infobip', 'twilio', 'africastalking', 'nexmo'

// API Credentials
Transport_smsApiKey      // API key/username
Transport_smsApiSecret   // API secret/password
Transport_smsBaseUrl     // Gateway URL (for Infobip, etc.)
Transport_smsSenderID    // From name/ID for SMS
Transport_smsEnabled     // 0 = disabled, 1 = enabled
```

### 3.2 Photo Upload Settings

Edit `TransportPhotoUpload.php` to customize:
```php
private $maxFileSize = 5242880;        // 5MB (change to desired size)
private $allowedMimes = [...]          // Add/remove MIME types
private $allowedExtensions = [...]     // Add/remove extensions
```

### 3.3 Alert Thresholds

Edit `TransportAlerts.php` or pass as parameters:
```php
// Late arrival threshold (minutes)
$alerts->checkLateArrivals(15);  // 15 minutes

// Missing boarding check time window (set in cron frequency)
// Adjust cron job frequency to match your needs
```

---

## 4. Testing

### 4.1 Unit Tests

Run the included test suite:
```bash
php tests/transport_phase1_tests.php
```

**Test Coverage:**
- ✅ SMS service instantiation
- ✅ SMS validation (recipients, message length)
- ✅ Photo upload validation
- ✅ Alert system initialization
- ✅ Alert type and severity constants

---

### 4.2 Manual Testing

#### Test SMS Sending
1. Go to Transport Settings → SMS Configuration
2. Enter valid SMS credentials
3. Go to SMS Broadcast
4. Send test SMS to verify delivery
5. Check SMS History for delivery status

#### Test Photo Upload
1. Go to Boarding Registration
2. Record a boarding event with a photo
3. Verify photo appears in event details
4. Check `/uploads/transport/photos/` directory structure

#### Test Alert System
1. Verify cron job is running: `grep transport_alerts_cron /var/log/syslog`
2. Go to Alerts Management dashboard
3. Trigger a test scenario (mark student absent)
4. Verify alert appears with correct severity
5. Test alert resolution

---

### 4.3 Performance Testing

**Expected Performance:**
- SMS sending: < 2 seconds per batch
- Photo upload: < 5 seconds (including compression)
- Alert check: < 10 seconds (for 1000+ students)
- Dashboard load: < 3 seconds

**Monitoring:**
- Check database indexes are present
- Monitor server resources during cron jobs
- Use `EXPLAIN` on alert detection queries

---

## 5. Troubleshooting

### SMS Not Sending

**Problem:** SMS broadcast shows success but no messages received

**Solutions:**
1. Verify SMS credentials in Transport Settings
2. Test SMS gateway directly: `curl https://api.infobip.com/...`
3. Check SMS history table for failed status
4. Verify phone number format (should include country code)
5. Check SMS provider account balance/quota

### Photos Not Uploading

**Problem:** File upload fails or photos not stored

**Solutions:**
1. Check directory permissions: `/uploads/transport/photos/` (should be 755)
2. Verify disk space available
3. Check PHP max_upload_size setting
4. Check MIME type validation (try different image format)
5. Review error logs for specific error

### Alerts Not Triggering

**Problem:** Missing boarding alerts not appearing

**Solutions:**
1. Verify cron job is running: `crontab -l`
2. Check cron logs: `tail -f /path/to/logs/transport_alerts_cron.log`
3. Verify students have valid phone numbers in parent records
4. Check alert table for existing alerts (may not create duplicates same day)
5. Verify students marked with "Active" status in route assignment

---

## 6. Security Notes

### SMS Security
- ✅ All SMS content logged (check GDPR compliance)
- ⚠️ API credentials stored in database (consider encryption)
- ⚠️ Phone numbers visible in SMS history (implement access controls)

**Recommendations:**
- Encrypt SMS credentials at rest
- Implement row-level security on SMS history
- Audit SMS access logs
- Limit SMS to authenticated staff only

### Photo Security
- ✅ Photos stored outside web root (configurable)
- ✅ .htaccess prevents script execution
- ⚠️ Directory listing disabled (verify .htaccess)
- ⚠️ Consider adding password protection for photo URLs

**Recommendations:**
- Implement photo access logs
- Add expiration for old photos
- Consider AWS S3/cloud storage for photos
- Implement audit trail for photo access

---

## 7. Database Backup

### Critical Tables for Backup
```sql
-- SMS history (for audit trail)
BACKUP gibbonTransportSMSHistory;

-- Photos (important evidence)
BACKUP gibbonTransportPhoto;

-- Alerts (compliance records)
BACKUP gibbonTransportAlert;

-- Events (student records)
BACKUP gibbonTransportEvent;
```

---

## 8. Migration from v1.2.0 to v1.2.1

### Database Migration Script

```sql
-- Add SMS history table
-- (Already included in manifest.php - runs on module install)

-- Add photo table
-- (Already included in manifest.php - runs on module install)

-- Verify tables exist
SHOW TABLES LIKE 'gibbonTransport%';
```

---

## 9. Next Steps (Phase 2)

After Phase 1 is complete and tested:

1. **Mobile App Development** - Driver and parent apps with offline capability
2. **Real-Time GPS Tracking** - Live vehicle tracking with WebSockets
3. **Parent Portal** - Real-time boarding status for parents
4. **Advanced Reporting** - Analytics dashboard with charts

---

## 10. Support & Documentation

### File Organization
```
Transport/
├── lib/
│   ├── TransportSMS.php              (New - SMS service)
│   ├── TransportPhotoUpload.php       (New - Photo handler)
│   └── TransportAlerts.php            (New - Alert system)
├── alerts_manage.php                  (New - Alert dashboard)
├── sms_broadcast_updated.php          (New - Improved SMS broadcast)
├── sms_history.php                    (New - SMS audit log)
├── cron_alert_trigger.php             (New - Automatic checks)
├── tests/
│   └── transport_phase1_tests.php     (New - Unit tests)
├── manifest.php                       (Updated - new tables & actions)
└── PHASE1_IMPLEMENTATION.md           (This file)
```

### Support Resources
- Check error logs: `/path/to/gibbon/logs/`
- Review SQL queries: Enable slow query log
- Debug SMS: Use SMS gateway dashboard
- Debug photos: Check file permissions and disk space

---

**Implementation Complete!**

All Phase 1 components are ready for integration. Follow the integration guide above and test thoroughly before deploying to production.

Questions? Check the main DOCUMENTATION.md file or review the source code comments.
