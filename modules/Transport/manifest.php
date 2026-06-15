<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)
*/

// Basic module info
$name = 'Transport';
$description = 'Student transportation management with safety tracking, SMS alerts, and mobile integration for Congo schools';
$entryURL = 'index.php';
$type = 'Additional';
$category = 'Students';
$version = '1.2.0';
$author = 'Yulpana Edutech / Mustafa';
$url = 'https://yulpana.com';

// ============================
// DATABASE TABLES
// ============================

// Routes (with Supervisor support)
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportRoute` (
  `gibbonTransportRouteID` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `routeType` ENUM('to_school', 'from_school', 'both') NOT NULL DEFAULT 'both',
  `nameShort` VARCHAR(20) NOT NULL UNIQUE,
  `vehicleNumber` VARCHAR(20) NOT NULL,
  `vehicleType` VARCHAR(50) DEFAULT 'Bus',
  `capacity` INT(11) DEFAULT 50,
  `driverID` INT(10) UNSIGNED ZEROFILL NULL,
  `driverPhone` VARCHAR(20) NULL,
  `supervisorEnabled` ENUM('Y','N') NOT NULL DEFAULT 'N',
  `gibbonPersonIDSupervisor` INT(10) UNSIGNED ZEROFILL NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `comments` TEXT NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_nameShort` (`nameShort`),
  INDEX `idx_routeType` (`routeType`),
  INDEX `idx_active` (`active`),
  INDEX `idx_supervisor` (`gibbonPersonIDSupervisor`),
  FOREIGN KEY (`driverID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL,
  FOREIGN KEY (`gibbonPersonIDSupervisor`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// Stops
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportStop` (
  `gibbonTransportStopID` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonTransportRouteID` INT(10) UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `sequenceNumber` INT(11) NOT NULL,
  `latitude` DECIMAL(10, 8) NULL,
  `longitude` DECIMAL(11, 8) NULL,
  `address` TEXT NULL,
  `landmark` VARCHAR(100) NULL,
  `estimatedArrivalTime` TIME NULL,
  `comments` TEXT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_route` (`gibbonTransportRouteID`),
  INDEX `idx_sequence` (`gibbonTransportRouteID`, `sequenceNumber`),
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// Student Assignments (with Stop linkage)
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportStudent` (
  `gibbonTransportStudentID` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `gibbonTransportRouteID` INT(10) UNSIGNED NOT NULL,
  `gibbonTransportStopID` INT(10) UNSIGNED NULL,
  `status` ENUM('Active', 'Inactive', 'Pending') DEFAULT 'Active',
  `startDate` DATE NULL,
  `endDate` DATE NULL,
  `emergencyContactOverride` VARCHAR(255) NULL,
  `specialNeeds` TEXT NULL,
  `comments` TEXT NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_student_route` (`gibbonPersonID`, `gibbonTransportRouteID`),
  INDEX `idx_student` (`gibbonPersonID`),
  INDEX `idx_route` (`gibbonTransportRouteID`),
  INDEX `idx_stop` (`gibbonTransportStopID`),
  INDEX `idx_status` (`status`),
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE CASCADE,
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE CASCADE,
  FOREIGN KEY (`gibbonTransportStopID`) REFERENCES `gibbonTransportStop`(`gibbonTransportStopID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// Boarding Events (SAFETY-CRITICAL)
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportEvent` (
  `gibbonTransportEventID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonTransportRouteID` INT(10) UNSIGNED NOT NULL,
  `gibbonTransportStopID` INT(10) UNSIGNED NULL,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `type` ENUM('pickup', 'dropoff') NOT NULL,
  `timestamp` DATETIME NOT NULL,
  `status` ENUM('Expected', 'OnTime', 'Late', 'Early', 'Absent', 'Verified') DEFAULT 'Expected',
  `gibbonPersonIDRecorder` INT(10) UNSIGNED ZEROFILL NULL,
  `latitude` DECIMAL(10, 8) NULL,
  `longitude` DECIMAL(11, 8) NULL,
  `photoUrl` VARCHAR(255) NULL,
  `photoVerified` TINYINT(1) DEFAULT 0,
  `comments` TEXT NULL,
  `emergencyFlag` TINYINT(1) DEFAULT 0,
  `emergencyNotes` TEXT NULL,
  `syncStatus` ENUM('pending', 'synced', 'failed') DEFAULT 'synced',
  `syncTimestamp` TIMESTAMP NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_route_date` (`gibbonTransportRouteID`, `timestamp`),
  INDEX `idx_event_stop` (`gibbonTransportStopID`),
  INDEX `idx_student_date` (`gibbonPersonID`, `timestamp`),
  INDEX `idx_type` (`type`),
  INDEX `idx_status` (`status`),
  INDEX `idx_emergency` (`emergencyFlag`),
  INDEX `idx_sync` (`syncStatus`),
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE CASCADE,
  FOREIGN KEY (`gibbonTransportStopID`) REFERENCES `gibbonTransportStop`(`gibbonTransportStopID`) ON DELETE SET NULL,
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE CASCADE,
  FOREIGN KEY (`gibbonPersonIDRecorder`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// Safety Alerts
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportAlert` (
  `gibbonTransportAlertID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `alertType` ENUM('missing_boarding', 'route_deviation', 'late_arrival', 'emergency', 'custom') NOT NULL,
  `severity` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  `gibbonTransportRouteID` INT(10) UNSIGNED NULL,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NULL,
  `message` TEXT NOT NULL,
  `smsSent` TINYINT(1) DEFAULT 0,
  `smsRecipients` TEXT NULL,
  `resolved` TINYINT(1) DEFAULT 0,
  `resolvedBy` INT(10) UNSIGNED ZEROFILL NULL,
  `resolvedAt` TIMESTAMP NULL,
  `resolvedNotes` TEXT NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_type` (`alertType`),
  INDEX `idx_severity` (`severity`),
  INDEX `idx_route` (`gibbonTransportRouteID`),
  INDEX `idx_student` (`gibbonPersonID`),
  INDEX `idx_unresolved` (`resolved`, `timestampCreated`),
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE SET NULL,
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL,
  FOREIGN KEY (`resolvedBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// API Keys for mobile app authentication
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportAPIKey` (
  `apiKeyID` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `apiKey` VARCHAR(255) NOT NULL UNIQUE,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `lastUsed` TIMESTAMP NULL,
  `createdBy` INT(10) UNSIGNED ZEROFILL NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_apiKey` (`apiKey`),
  INDEX `idx_active` (`active`),
  FOREIGN KEY (`createdBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// SMS History (for tracking and audit trail)
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportSMSHistory` (
  `smsHistoryID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `messageID` VARCHAR(100) NOT NULL UNIQUE,
  `recipients` TEXT NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
  `gibbonTransportRouteID` INT(10) UNSIGNED NULL,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NULL,
  `gibbonTransportAlertID` BIGINT(20) UNSIGNED NULL,
  `createdBy` INT(10) UNSIGNED ZEROFILL NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_messageID` (`messageID`),
  INDEX `idx_status` (`status`),
  INDEX `idx_route` (`gibbonTransportRouteID`),
  INDEX `idx_student` (`gibbonPersonID`),
  INDEX `idx_alert` (`gibbonTransportAlertID`),
  INDEX `idx_created` (`timestampCreated`),
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE SET NULL,
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL,
  FOREIGN KEY (`gibbonTransportAlertID`) REFERENCES `gibbonTransportAlert`(`gibbonTransportAlertID`) ON DELETE SET NULL,
  FOREIGN KEY (`createdBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// Photos for boarding events (evidence collection)
$moduleTables[] = "CREATE TABLE IF NOT EXISTS `gibbonTransportPhoto` (
  `photoID` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonTransportEventID` BIGINT(20) UNSIGNED NOT NULL,
  `photoUrl` VARCHAR(255) NOT NULL,
  `photoType` ENUM('boarding_event', 'verification') DEFAULT 'boarding_event',
  `fileSize` INT(11) NOT NULL,
  `uploadedBy` INT(10) UNSIGNED ZEROFILL NULL,
  `verified` TINYINT(1) DEFAULT 0,
  `verifiedBy` INT(10) UNSIGNED ZEROFILL NULL,
  `verifiedAt` TIMESTAMP NULL,
  `notes` TEXT,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_event_photo` (`gibbonTransportEventID`),
  INDEX `idx_photoType` (`photoType`),
  INDEX `idx_verified` (`verified`),
  FOREIGN KEY (`gibbonTransportEventID`) REFERENCES `gibbonTransportEvent`(`gibbonTransportEventID`) ON DELETE CASCADE,
  FOREIGN KEY (`uploadedBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL,
  FOREIGN KEY (`verifiedBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

// ============================
// MIGRATION SQL (for existing installations)
// Run these manually if upgrading from v1.0.0
// ============================
/*
-- Add supervisor fields to gibbonTransportRoute
ALTER TABLE gibbonTransportRoute
ADD COLUMN supervisorEnabled ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER driverPhone,
ADD COLUMN gibbonPersonIDSupervisor INT(10) UNSIGNED ZEROFILL NULL AFTER supervisorEnabled,
ADD INDEX idx_supervisor (gibbonPersonIDSupervisor),
ADD CONSTRAINT fk_supervisor_person FOREIGN KEY (gibbonPersonIDSupervisor) REFERENCES gibbonPerson(gibbonPersonID) ON DELETE SET NULL;

-- Add stop linkage to gibbonTransportStudent
ALTER TABLE gibbonTransportStudent
ADD COLUMN gibbonTransportStopID INT(10) UNSIGNED NULL AFTER gibbonTransportRouteID,
ADD INDEX idx_stop (gibbonTransportStopID),
ADD CONSTRAINT fk_student_stop FOREIGN KEY (gibbonTransportStopID) REFERENCES gibbonTransportStop(gibbonTransportStopID) ON DELETE SET NULL;
*/

// ============================
// ACTIONS (Permissions)
// ============================

$actionRows[] = [
    'name' => 'View Transport Dashboard',
    'precedence' => '0',
    'category' => 'Transport',
    'description' => 'View transport dashboard',
    'URLList' => 'index.php',
    'entryURL' => 'index.php',
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

$actionRows[] = [
    'name' => 'Manage Routes',
    'precedence' => '10',
    'category' => 'Transport',
    'description' => 'Manage transport routes',
    'URLList' => 'routes_manage.php,routes_manage_add.php,routes_manage_edit.php',
    'entryURL' => 'routes_manage.php',
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

$actionRows[] = [
    'name' => 'Manage Stops',
    'precedence' => '20',
    'category' => 'Transport',
    'description' => 'Manage transport stops',
    'URLList' => 'stops_manage.php,stops_manage_add.php,stops_manage_edit.php',
    'entryURL' => 'stops_manage.php',
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

$actionRows[] = [
    'name' => 'Manage Student Assignments',
    'precedence' => '30',
    'category' => 'Transport',
    'description' => 'Assign students to transport routes',
    'URLList' => 'students_manage.php,students_manage_add.php',
    'entryURL' => 'students_manage.php',
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

$actionRows[] = [
    'name' => 'Daily Attendance',
    'precedence' => '40',
    'category' => 'Transport',
    'description' => 'Record daily boarding/dropoff',
    'URLList' => 'attendance_daily.php',
    'entryURL' => 'attendance_daily.php',
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

$actionRows[] = [
    'name' => 'Boarding Registration',
    'precedence' => '45',
    'category' => 'Transport',
    'description' => 'Register student pickups and dropoffs at each stop',
    'URLList' => 'boarding_start.php',
    'entryURL' => 'boarding_start.php',
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

$actionRows[] = [
    'name' => 'View Reports',
    'precedence' => '50',
    'category' => 'Transport',
    'description' => 'View transport reports',
    'URLList' => 'reports_routes.php',
    'entryURL' => 'reports_routes.php',
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

// ============================
// SETTINGS & API ACTIONS
// ============================

$actionRows[] = [
    'name' => 'Transport Settings',
    'precedence' => '100',
    'category' => 'Settings',
    'description' => 'Configure SMS gateway, API keys, and system settings',
    'URLList' => 'settings.php',
    'entryURL' => 'settings.php',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'N',
    'defaultPermissionStudent' => 'N',
    'defaultPermissionParent' => 'N',
    'defaultPermissionSupport' => 'N',
    'categoryPermissionStaff' => 'N',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent' => 'N',
    'categoryPermissionOther' => 'N',
];

$actionRows[] = [
    'name' => 'Manage API Keys',
    'precedence' => '110',
    'category' => 'Settings',
    'description' => 'Generate and manage API keys for external integrations',
    'URLList' => 'api_keys_manage.php,api_keys_manage_add.php',
    'entryURL' => 'api_keys_manage.php',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'N',
    'defaultPermissionStudent' => 'N',
    'defaultPermissionParent' => 'N',
    'defaultPermissionSupport' => 'N',
    'categoryPermissionStaff' => 'N',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent' => 'N',
    'categoryPermissionOther' => 'N',
];

$actionRows[] = [
    'name' => 'SMS Broadcast',
    'precedence' => '120',
    'category' => 'Communication',
    'description' => 'Send bulk SMS messages to parents/guardians',
    'URLList' => 'sms_broadcast.php',
    'entryURL' => 'sms_broadcast.php',
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
