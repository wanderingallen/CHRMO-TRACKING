-- Migration: Create department_archives table for per-department archive isolation
-- Each department independently archives documents from tracking.
-- The tracking row is NEVER deleted; this table records which departments have archived
-- which tracking documents so they can be hidden from that department's tracking view
-- and shown in that department's archive view.

CREATE TABLE IF NOT EXISTS `department_archives` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tracking_id` INT UNSIGNED NOT NULL COMMENT 'FK to tracking.id — the original tracking row stays',
  `department` VARCHAR(255) NOT NULL COMMENT 'Department that archived (uppercased). ADMIN for admin users.',
  `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived_by_user_id` INT DEFAULT NULL COMMENT 'Optional: user who performed the archive',
  UNIQUE KEY `uq_tracking_dept` (`tracking_id`, `department`),
  INDEX `idx_tracking_id` (`tracking_id`),
  INDEX `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
