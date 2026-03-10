-- Migration: Add archived_by_department column to archive table
-- Purpose: Store which department archived the document, not just which department the document belongs to.
-- This allows department users (e.g., CTO) to see documents they archived even if the document originated from another department (e.g., HR).
-- Run this once on the database before deploying the updated tracking.php and archive.php.

ALTER TABLE `archive`
  ADD COLUMN `archived_by_department` VARCHAR(255) DEFAULT NULL AFTER `department`;

-- Backfill existing rows: set archived_by_department = department for all existing archived documents
UPDATE `archive` SET `archived_by_department` = `department` WHERE `archived_by_department` IS NULL;
