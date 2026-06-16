-- =====================================================
-- Performance Index Migration Script
-- Adds recommended indexes for foreign keys and filter columns
-- 
-- This script safely adds indexes to existing installations.
-- It checks for index existence before creation to avoid errors.
-- =====================================================

-- =====================================================
-- 1. GIBBON TRANSPORT ROUTE INDEXES
-- =====================================================

-- Index on active column for filtering active routes
-- Used in: Transport module route queries, API endpoints
CREATE INDEX IF NOT EXISTS idx_gibbonTransportRoute_active 
ON gibbonTransportRoute(active);

-- =====================================================
-- 2. GIBBON TRANSPORT STUDENT INDEXES
-- =====================================================

-- Composite index on gibbonTransportRouteID and status
-- Optimizes queries that filter by route and student status
CREATE INDEX IF NOT EXISTS idx_gibbonTransportStudent_route_status 
ON gibbonTransportStudent(gibbonTransportRouteID, status);

-- Individual index on gibbonTransportRouteID for foreign key lookups
-- (May already exist as FK constraint, but explicit index ensures performance)
CREATE INDEX IF NOT EXISTS idx_gibbonTransportStudent_routeID 
ON gibbonTransportStudent(gibbonTransportRouteID);

-- =====================================================
-- 3. GIBBON PERSON INDEXES
-- =====================================================

-- Index on username for login and user lookup queries
CREATE INDEX IF NOT EXISTS idx_gibbonPerson_username 
ON gibbonPerson(username);

-- Index on email for authentication and notification queries
CREATE INDEX IF NOT EXISTS idx_gibbonPerson_email 
ON gibbonPerson(email);

-- Composite index for common lookup patterns (optional optimization)
-- Speeds up queries that filter by both username and status
CREATE INDEX IF NOT EXISTS idx_gibbonPerson_username_status 
ON gibbonPerson(username, status);

-- =====================================================
-- 4. GIBBON ROLE INDEXES
-- =====================================================

-- Note: gibbonRoleIDAll appears to be a composite/junction table field.
-- If gibbonRole has a gibbonRoleIDAll column, create index:
-- (This may need schema normalization if it's actually a junction table)

-- Check if column exists before attempting index (MySQL 8.0+ syntax)
-- For older MySQL, this will fail silently or you can manually verify
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'gibbonRole' 
    AND COLUMN_NAME = 'gibbonRoleIDAll'
);

-- Dynamic index creation for gibbonRoleIDAll if column exists
SET @sql = IF(
    @col_exists > 0,
    'CREATE INDEX IF NOT EXISTS idx_gibbonRole_roleIDAll ON gibbonRole(gibbonRoleIDAll)',
    'SELECT "Column gibbonRoleIDAll does not exist in gibbonRole table - skipping index" AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- VERIFICATION QUERIES
-- Run these to verify indexes were created successfully
-- =====================================================

-- List all indexes on transport tables
-- SHOW INDEX FROM gibbonTransportRoute;
-- SHOW INDEX FROM gibbonTransportStudent;

-- List all indexes on person table
-- SHOW INDEX FROM gibbonPerson;

-- List all indexes on role table
-- SHOW INDEX FROM gibbonRole;

-- =====================================================
-- ANALYZE TABLES AFTER INDEX CREATION
-- Updates query optimizer statistics for better performance
-- =====================================================

ANALYZE TABLE gibbonTransportRoute;
ANALYZE TABLE gibbonTransportStudent;
ANALYZE TABLE gibbonPerson;
ANALYZE TABLE gibbonRole;

-- =====================================================
-- ROLLBACK COMMANDS (if needed)
-- Use these to remove the indexes if issues occur
-- =====================================================

/*
-- Remove transport route indexes
DROP INDEX IF EXISTS idx_gibbonTransportRoute_active ON gibbonTransportRoute;

-- Remove transport student indexes
DROP INDEX IF EXISTS idx_gibbonTransportStudent_route_status ON gibbonTransportStudent;
DROP INDEX IF EXISTS idx_gibbonTransportStudent_routeID ON gibbonTransportStudent;

-- Remove person indexes
DROP INDEX IF EXISTS idx_gibbonPerson_username ON gibbonPerson;
DROP INDEX IF EXISTS idx_gibbonPerson_email ON gibbonPerson;
DROP INDEX IF EXISTS idx_gibbonPerson_username_status ON gibbonPerson;

-- Remove role index
DROP INDEX IF EXISTS idx_gibbonRole_roleIDAll ON gibbonRole;
*/
