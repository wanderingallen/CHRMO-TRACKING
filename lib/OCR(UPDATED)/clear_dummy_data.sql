-- Clear all dummy data from tracking table
-- Run this in phpMyAdmin to remove existing dummy documents

DELETE FROM tracking WHERE id IN ('doc7', 'doc8', 'doc9');

-- Verify the table is empty
SELECT * FROM tracking;
