-- =====================================================
-- Transport Module v1.2.0 Migration Script
-- Run this SQL if upgrading from v1.1.0 to v1.2.0
-- Adds Route Type (to_school / from_school / both)
-- =====================================================

-- =====================================================
-- 1. ROUTE TYPE FIELD
-- Determines if route is for pickups (to school) or dropoffs (from school)
-- =====================================================

-- Add routeType field to routes
ALTER TABLE gibbonTransportRoute
ADD COLUMN routeType ENUM('to_school', 'from_school', 'both') NOT NULL DEFAULT 'both' AFTER name;

-- Add index for route type queries
ALTER TABLE gibbonTransportRoute
ADD INDEX idx_routeType (routeType);

-- =====================================================
-- 2. ADD STOP REFERENCE TO EVENTS (optional enhancement)
-- Track which stop the boarding happened at
-- =====================================================

-- Add stop ID to events for more detailed tracking
ALTER TABLE gibbonTransportEvent
ADD COLUMN gibbonTransportStopID INT(10) UNSIGNED NULL AFTER gibbonTransportRouteID;

-- Add index for stop-based event queries
ALTER TABLE gibbonTransportEvent
ADD INDEX idx_event_stop (gibbonTransportStopID);

-- Add foreign key constraint
ALTER TABLE gibbonTransportEvent
ADD CONSTRAINT fk_event_stop 
FOREIGN KEY (gibbonTransportStopID) 
REFERENCES gibbonTransportStop(gibbonTransportStopID) 
ON DELETE SET NULL;

-- =====================================================
-- VERIFICATION QUERIES
-- Run these to verify the migration was successful
-- =====================================================

-- Check route table structure
-- SHOW COLUMNS FROM gibbonTransportRoute;

-- Check event table structure  
-- SHOW COLUMNS FROM gibbonTransportEvent;

-- =====================================================
-- ROLLBACK (if needed)
-- Use these commands to undo the changes
-- =====================================================

/*
-- Remove route type field
ALTER TABLE gibbonTransportRoute DROP INDEX idx_routeType;
ALTER TABLE gibbonTransportRoute DROP COLUMN routeType;

-- Remove stop reference from events
ALTER TABLE gibbonTransportEvent DROP FOREIGN KEY fk_event_stop;
ALTER TABLE gibbonTransportEvent DROP INDEX idx_event_stop;
ALTER TABLE gibbonTransportEvent DROP COLUMN gibbonTransportStopID;
*/
