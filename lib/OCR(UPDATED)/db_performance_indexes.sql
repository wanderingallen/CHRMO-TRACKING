-- Database performance indexes for CHRMO app
-- Execute these in phpMyAdmin (SQL tab) while using database `chrmo_db`.
-- Safe to run once; if an index or column already exists, skip the failing statement.

-- ===== tracking table =====
-- Normalize COALESCE(created_at, date_submitted) into an indexable column
ALTER TABLE tracking
  ADD COLUMN effective_date DATETIME GENERATED ALWAYS AS (COALESCE(created_at, date_submitted)) STORED;

-- Fast recent lists and notifications (ORDER BY effective_date DESC, id DESC LIMIT N)
CREATE INDEX idx_tracking_effective_date ON tracking (effective_date DESC, id DESC);

-- Filters and searches commonly used
CREATE INDEX idx_tracking_status ON tracking (status);
CREATE INDEX idx_tracking_department ON tracking (department);
CREATE INDEX idx_tracking_type ON tracking (type);
CREATE INDEX idx_tracking_employee_name ON tracking (employee_name);
CREATE INDEX idx_tracking_current_holder ON tracking (current_holder);
CREATE INDEX idx_tracking_end_location ON tracking (end_location);

-- Composite indexes aligned with typical filters and date sorts
CREATE INDEX idx_tracking_status_effective ON tracking (status, effective_date DESC);
CREATE INDEX idx_tracking_dept_status_effective ON tracking (department, status, effective_date DESC);

-- ===== archive table =====
CREATE INDEX idx_archive_department ON archive (department);
CREATE INDEX idx_archive_type ON archive (type);
CREATE INDEX idx_archive_status ON archive (status);
CREATE INDEX idx_archive_date_archived ON archive (date_archived);

-- Composite for filter bar (department, type, status, date)
CREATE INDEX idx_archive_filters ON archive (department, type, status, date_archived);
