-- Transport Module v1.2 to v1.3 feature tables
-- Adds operational tables for vehicle tracking, incidents, pickup rules, billing, and audit logging.

CREATE TABLE IF NOT EXISTS `gibbonTransportVehicle` (
  `gibbonTransportVehicleID` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `vehicleNumber` VARCHAR(50) NOT NULL UNIQUE,
  `vehicleType` VARCHAR(50) DEFAULT 'Bus',
  `capacity` INT(11) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `insuranceExpiry` DATE NULL,
  `licenseExpiry` DATE NULL,
  `maintenanceDueDate` DATE NULL,
  `notes` TEXT NULL,
  `createdBy` INT(10) UNSIGNED ZEROFILL NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_vehicle_active` (`active`),
  INDEX `idx_vehicle_maintenance` (`maintenanceDueDate`),
  FOREIGN KEY (`createdBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gibbonTransportVehicleLocation` (
  `gibbonTransportVehicleLocationID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonTransportRouteID` INT(10) UNSIGNED NULL,
  `vehicleNumber` VARCHAR(50) NULL,
  `latitude` DECIMAL(10, 8) NOT NULL,
  `longitude` DECIMAL(11, 8) NOT NULL,
  `speedKph` DECIMAL(6, 2) NULL,
  `bearing` DECIMAL(6, 2) NULL,
  `accuracyMeters` DECIMAL(8, 2) NULL,
  `recordedBy` INT(10) UNSIGNED ZEROFILL NULL,
  `source` ENUM('mobile', 'hardware', 'manual') NOT NULL DEFAULT 'mobile',
  `recordedAt` DATETIME NOT NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_location_route_time` (`gibbonTransportRouteID`, `recordedAt`),
  INDEX `idx_location_vehicle_time` (`vehicleNumber`, `recordedAt`),
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE SET NULL,
  FOREIGN KEY (`recordedBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gibbonTransportIncident` (
  `gibbonTransportIncidentID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `incidentType` ENUM('missing_student', 'behavior', 'vehicle_breakdown', 'accident', 'medical', 'route_deviation', 'late_arrival', 'unauthorized_pickup', 'other') NOT NULL DEFAULT 'other',
  `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  `gibbonTransportRouteID` INT(10) UNSIGNED NULL,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NULL,
  `description` TEXT NOT NULL,
  `followUpRequired` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('open', 'investigating', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `reportedBy` INT(10) UNSIGNED ZEROFILL NULL,
  `occurredAt` DATETIME NOT NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_incident_status` (`status`),
  INDEX `idx_incident_route` (`gibbonTransportRouteID`),
  INDEX `idx_incident_student` (`gibbonPersonID`),
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE SET NULL,
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL,
  FOREIGN KEY (`reportedBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gibbonTransportPickupRule` (
  `gibbonTransportPickupRuleID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `authorisedName` VARCHAR(150) NOT NULL,
  `relationship` VARCHAR(100) NULL,
  `phone` VARCHAR(50) NULL,
  `ruleType` ENUM('authorised', 'blocked', 'note') NOT NULL DEFAULT 'authorised',
  `notes` TEXT NULL,
  `priority` INT(11) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `createdBy` INT(10) UNSIGNED ZEROFILL NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pickup_student` (`gibbonPersonID`, `active`),
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE CASCADE,
  FOREIGN KEY (`createdBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gibbonTransportBilling` (
  `gibbonTransportBillingID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NOT NULL,
  `gibbonTransportRouteID` INT(10) UNSIGNED NULL,
  `billingPeriod` VARCHAR(20) NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `discountAmount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `status` ENUM('pending', 'invoiced', 'paid', 'waived', 'overdue') NOT NULL DEFAULT 'pending',
  `notes` TEXT NULL,
  `createdBy` INT(10) UNSIGNED ZEROFILL NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `timestampModified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_billing_student_period` (`gibbonPersonID`, `billingPeriod`),
  INDEX `idx_billing_status` (`status`),
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE CASCADE,
  FOREIGN KEY (`gibbonTransportRouteID`) REFERENCES `gibbonTransportRoute`(`gibbonTransportRouteID`) ON DELETE SET NULL,
  FOREIGN KEY (`createdBy`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gibbonTransportAuditLog` (
  `gibbonTransportAuditLogID` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `gibbonPersonID` INT(10) UNSIGNED ZEROFILL NULL,
  `action` VARCHAR(100) NOT NULL,
  `entityType` VARCHAR(100) NOT NULL,
  `entityID` VARCHAR(100) NULL,
  `payloadJson` JSON NULL,
  `ipAddress` VARCHAR(64) NULL,
  `timestampCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_actor` (`gibbonPersonID`, `timestampCreated`),
  INDEX `idx_audit_entity` (`entityType`, `entityID`),
  FOREIGN KEY (`gibbonPersonID`) REFERENCES `gibbonPerson`(`gibbonPersonID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
