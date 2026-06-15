-- =====================================================
-- Transport Module v1.1.0 Migration Script
-- Run this SQL if upgrading from v1.0.0 to v1.1.0
-- =====================================================

-- =====================================================
-- 1. ROUTE SUPERVISOR FIELDS
-- Adds supervisor toggle and staff person reference
-- =====================================================

-- Add supervisorEnabled field (Y/N toggle)
ALTER TABLE gibbonTransportRoute
ADD COLUMN supervisorEnabled ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER driverPhone;

-- Add supervisor person ID field
ALTER TABLE gibbonTransportRoute
ADD COLUMN gibbonPersonIDSupervisor INT(10) UNSIGNED ZEROFILL NULL AFTER supervisorEnabled;

-- Add index for supervisor lookups
ALTER TABLE gibbonTransportRoute
ADD INDEX idx_supervisor (gibbonPersonIDSupervisor);

-- Add foreign key constraint (recommended for data integrity)
ALTER TABLE gibbonTransportRoute
ADD CONSTRAINT fk_supervisor_person 
FOREIGN KEY (gibbonPersonIDSupervisor) 
REFERENCES gibbonPerson(gibbonPersonID) 
ON DELETE SET NULL;

-- =====================================================
-- 2. STUDENT-TO-STOP LINKAGE
-- Links students to specific pickup/dropoff stops
-- =====================================================

-- Add stop ID field to student assignments
ALTER TABLE gibbonTransportStudent
ADD COLUMN gibbonTransportStopID INT(10) UNSIGNED NULL AFTER gibbonTransportRouteID;

-- Add index for stop-based queries
ALTER TABLE gibbonTransportStudent
ADD INDEX idx_stop (gibbonTransportStopID);

-- Add foreign key constraint
ALTER TABLE gibbonTransportStudent
ADD CONSTRAINT fk_student_stop 
FOREIGN KEY (gibbonTransportStopID) 
REFERENCES gibbonTransportStop(gibbonTransportStopID) 
ON DELETE SET NULL;

-- =====================================================
-- VERIFICATION QUERIES
-- Run these to verify the migration was successful
-- =====================================================

-- Check route table structure
-- SHOW COLUMNS FROM gibbonTransportRoute;

-- Check student table structure  
-- SHOW COLUMNS FROM gibbonTransportStudent;

-- =====================================================
-- ROLLBACK (if needed)
-- Use these commands to undo the changes
-- =====================================================

/*
-- Remove foreign key and fields from routes
ALTER TABLE gibbonTransportRoute DROP FOREIGN KEY fk_supervisor_person;
ALTER TABLE gibbonTransportRoute DROP INDEX idx_supervisor;
ALTER TABLE gibbonTransportRoute DROP COLUMN gibbonPersonIDSupervisor;
ALTER TABLE gibbonTransportRoute DROP COLUMN supervisorEnabled;

-- Remove foreign key and field from students
ALTER TABLE gibbonTransportStudent DROP FOREIGN KEY fk_student_stop;
ALTER TABLE gibbonTransportStudent DROP INDEX idx_stop;
ALTER TABLE gibbonTransportStudent DROP COLUMN gibbonTransportStopID;
*/
