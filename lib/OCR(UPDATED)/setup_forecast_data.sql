-- ============================================================
-- Setup script for TC-07 (Dashboard Analytics) and TC-08 (Forecasting)
-- Run this in phpMyAdmin SQL tab to populate test data
-- ============================================================

USE chrmo_db;

-- ============================================================
-- 1. Create predictions_cache table (if not exists)
-- ============================================================
CREATE TABLE IF NOT EXISTS predictions_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric VARCHAR(100) NOT NULL DEFAULT 'documents_per_day',
    forecast_date DATE NOT NULL,
    forecast_value FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_date (metric, forecast_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. Insert historical tracking data (30 days of documents)
--    This provides data for the "Historical Trend" line
-- ============================================================

-- First batch: 1 document per day for last 30 days (Memo type)
INSERT INTO tracking
(type, employee_name, date_submitted, current_holder, end_location, status, department,
 file_type_icon, ocr_content, mobile_timestamp, file_size, user_email, file_path, doc_hash, is_hidden, created_at)
SELECT
  'Memo' AS type,
  CONCAT('TestUser_Day', d.n, '_A') AS employee_name,
  DATE(CURDATE() - INTERVAL d.n DAY) AS date_submitted,
  'CHRMO' AS current_holder,
  '' AS end_location,
  CASE
    WHEN d.n % 5 = 0 THEN 'Completed'
    WHEN d.n % 4 = 0 THEN 'In Review'
    ELSE 'Pending'
  END AS status,
  CASE
    WHEN d.n % 3 = 0 THEN 'GSO'
    WHEN d.n % 3 = 1 THEN 'CMO'
    ELSE 'CBO'
  END AS department,
  'pdf' AS file_type_icon,
  CONCAT('Test OCR content for day ', d.n) AS ocr_content,
  NULL AS mobile_timestamp,
  '150KB' AS file_size,
  CONCAT('testuser', d.n, '@demo.local') AS user_email,
  CONCAT('uploads/tracking/test_', d.n, '_A.pdf') AS file_path,
  LPAD(HEX(d.n + 5000), 64, '0') AS doc_hash,
  0 AS is_hidden,
  (NOW() - INTERVAL d.n DAY) AS created_at
FROM (
  SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) d;

-- Second batch: Another document per day (Announcement type) to have 2 docs/day
INSERT INTO tracking
(type, employee_name, date_submitted, current_holder, end_location, status, department,
 file_type_icon, ocr_content, mobile_timestamp, file_size, user_email, file_path, doc_hash, is_hidden, created_at)
SELECT
  'Announcement' AS type,
  CONCAT('TestUser_Day', d.n, '_B') AS employee_name,
  DATE(CURDATE() - INTERVAL d.n DAY) AS date_submitted,
  'CHRMO' AS current_holder,
  '' AS end_location,
  CASE
    WHEN d.n % 6 = 0 THEN 'Approved'
    WHEN d.n % 3 = 0 THEN 'In Review'
    ELSE 'Pending'
  END AS status,
  CASE
    WHEN d.n % 2 = 0 THEN 'CTO'
    ELSE 'CPDO'
  END AS department,
  'pdf' AS file_type_icon,
  CONCAT('Test OCR content for day ', d.n, ' second document') AS ocr_content,
  NULL AS mobile_timestamp,
  '180KB' AS file_size,
  CONCAT('testuser2_', d.n, '@demo.local') AS user_email,
  CONCAT('uploads/tracking/test_', d.n, '_B.pdf') AS file_path,
  LPAD(HEX(d.n + 6000), 64, '0') AS doc_hash,
  0 AS is_hidden,
  (NOW() - INTERVAL d.n DAY) AS created_at
FROM (
  SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) d;

-- ============================================================
-- 3. Insert forecast predictions (next 14 days)
--    This provides data for the "Projected Trend" dashed line
-- ============================================================
INSERT INTO predictions_cache (metric, forecast_date, forecast_value)
VALUES
('documents_per_day', CURDATE() + INTERVAL 1 DAY,  3),
('documents_per_day', CURDATE() + INTERVAL 2 DAY,  4),
('documents_per_day', CURDATE() + INTERVAL 3 DAY,  3),
('documents_per_day', CURDATE() + INTERVAL 4 DAY,  5),
('documents_per_day', CURDATE() + INTERVAL 5 DAY,  4),
('documents_per_day', CURDATE() + INTERVAL 6 DAY,  6),
('documents_per_day', CURDATE() + INTERVAL 7 DAY,  5),
('documents_per_day', CURDATE() + INTERVAL 8 DAY,  4),
('documents_per_day', CURDATE() + INTERVAL 9 DAY,  5),
('documents_per_day', CURDATE() + INTERVAL 10 DAY, 6),
('documents_per_day', CURDATE() + INTERVAL 11 DAY, 5),
('documents_per_day', CURDATE() + INTERVAL 12 DAY, 4),
('documents_per_day', CURDATE() + INTERVAL 13 DAY, 5),
('documents_per_day', CURDATE() + INTERVAL 14 DAY, 6);

-- ============================================================
-- 4. Insert some archive records for dashboard archived count
-- ============================================================
INSERT INTO archive (document_name, department, type, status, date_archived, size, file_path, file_type_icon)
VALUES
('Archived Memo - Test 1', 'CMO',  'Memo',         'Archived', CURDATE() - INTERVAL 10 DAY, '200KB', 'uploads/archive/test_archived_1.pdf', 'pdf'),
('Archived Payroll - Test 2', 'GSO',  'Payroll',   'Archived', CURDATE() - INTERVAL 15 DAY, '350KB', 'uploads/archive/test_archived_2.pdf', 'pdf'),
('Archived Travel Order - Test 3', 'CBO', 'Travel Order', 'Archived', CURDATE() - INTERVAL 20 DAY, '280KB', 'uploads/archive/test_archived_3.pdf', 'pdf');

-- ============================================================
-- Done! Refresh the Stats/Reports page to see the forecast chart
-- ============================================================
SELECT 'Setup complete! Refresh stats.php to see the Predictive Document Volume chart.' AS message;
