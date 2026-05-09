-- =============================================================================
-- ONE-TIME MIGRATION: Update old routes to new CACCO-based routing
-- Run this ONCE against your MySQL database to update existing records.
-- 
-- Old route: HR,CBO,ACCOUNTING,CAO,CTO (5 steps)
-- New route: HR,CBO,CACCO,CTO          (4 steps)
--
-- IMPORTANT: Back up your database before running this script.
-- =============================================================================

-- 1. Update routing_queue in tracking table
--    Change old 5-step payroll route to new 4-step route
UPDATE tracking
SET routing_queue = 'HR,CBO,CACCO,CTO'
WHERE routing_queue = 'HR,CBO,ACCOUNTING,CAO,CTO';

-- 2. Update current_holder references
--    ACCOUNTING → CACCO
UPDATE tracking
SET current_holder = 'CACCO'
WHERE UPPER(TRIM(current_holder)) IN ('ACCOUNTING', 'CAO');

-- 3. Update department references
UPDATE tracking
SET department = 'CACCO'
WHERE UPPER(TRIM(department)) IN ('ACCOUNTING', 'CAO');

-- 4. Update end_location references
UPDATE tracking
SET end_location = 'CACCO'
WHERE UPPER(TRIM(end_location)) IN ('ACCOUNTING', 'CAO');

-- 5. Fix route_step for documents currently at ACCOUNTING/CAO position
--    Old route: HR(0), CBO(1), ACCOUNTING(2), CAO(3), CTO(4)
--    New route: HR(0), CBO(1), CACCO(2), CTO(3)
--    Documents at step 2 (was ACCOUNTING) → stays step 2 (now CACCO)
--    Documents at step 3 (was CAO) → move to step 2 (now CACCO, since CAO merged into CACCO)
--    Documents at step 4 (was CTO) → move to step 3 (CTO is now step 3)
UPDATE tracking
SET route_step = 2
WHERE routing_queue = 'HR,CBO,CACCO,CTO'
  AND route_step = 3;

UPDATE tracking
SET route_step = 3
WHERE routing_queue = 'HR,CBO,CACCO,CTO'
  AND route_step = 4;

-- 6. Update document_history table if it exists
--    Update from_holder and to_holder references
UPDATE document_history
SET from_holder = 'CACCO'
WHERE UPPER(TRIM(from_holder)) IN ('ACCOUNTING', 'CAO');

UPDATE document_history
SET to_holder = 'CACCO'
WHERE UPPER(TRIM(to_holder)) IN ('ACCOUNTING', 'CAO');

-- 7. Update archive table if it exists
UPDATE archive
SET department = 'CACCO'
WHERE UPPER(TRIM(department)) IN ('ACCOUNTING', 'CAO');

UPDATE archive
SET last_department = 'CACCO'
WHERE UPPER(TRIM(last_department)) IN ('ACCOUNTING', 'CAO');

-- 8. Update department_archives table if it exists
UPDATE department_archives
SET department = 'CACCO'
WHERE UPPER(TRIM(department)) IN ('ACCOUNTING', 'CAO');

-- 9. Update control table (user accounts) — optional, do this if users
--    currently have ACCOUNTING or CAO as their department
-- UPDATE control
-- SET department = 'CACCO'
-- WHERE UPPER(TRIM(department)) IN ('ACCOUNTING', 'CAO');

-- UNCOMMENT line 9 above if you want to automatically reassign users.
-- Otherwise, manually update user departments in the admin panel.

-- Verification: Check remaining old department references
SELECT 'tracking.current_holder' AS source, current_holder AS value, COUNT(*) AS cnt
FROM tracking WHERE UPPER(TRIM(current_holder)) IN ('ACCOUNTING', 'CAO') GROUP BY current_holder
UNION ALL
SELECT 'tracking.department', department, COUNT(*)
FROM tracking WHERE UPPER(TRIM(department)) IN ('ACCOUNTING', 'CAO') GROUP BY department
UNION ALL
SELECT 'tracking.routing_queue', routing_queue, COUNT(*)
FROM tracking WHERE routing_queue LIKE '%ACCOUNTING%' OR routing_queue LIKE '%CAO%' GROUP BY routing_queue;

-- If the verification query returns 0 rows, the migration was successful.
