<?php
/**
 * Migration Script: Create ocr_pages table for multi-page OCR storage
 * 
 * This table stores per-page OCR text for documents in tracking and archive.
 * It enables:
 * - Multi-page document support (5-10+ pages per document)
 * - Per-page search hits (show which page matched)
 * - Full-text search on OCR content
 * - Integrity verification per page
 */

header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$database = "chrmo_db";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

$migrations = [];
$errors = [];

// 1. Create ocr_pages table
$sql = "CREATE TABLE IF NOT EXISTS ocr_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('tracking', 'archive') NOT NULL DEFAULT 'tracking',
    doc_id INT NOT NULL,
    page_number INT NOT NULL DEFAULT 1,
    ocr_text LONGTEXT,
    ocr_keywords TEXT COMMENT 'Auto-extracted keywords for fast search',
    text_sha256 CHAR(64) COMMENT 'Integrity hash of ocr_text',
    confidence_score DECIMAL(5,2) COMMENT 'OCR confidence if available',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_page (scope, doc_id, page_number),
    INDEX idx_doc (scope, doc_id),
    INDEX idx_keywords (ocr_keywords(100)),
    FULLTEXT INDEX ft_ocr_text (ocr_text),
    FULLTEXT INDEX ft_keywords (ocr_keywords)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    $migrations[] = "Created ocr_pages table with FULLTEXT indexes";
} else {
    if (strpos($conn->error, 'already exists') !== false) {
        $migrations[] = "ocr_pages table already exists";
    } else {
        $errors[] = "Failed to create ocr_pages: " . $conn->error;
    }
}

// 2. Add ocr_summary column to tracking if not exists
$check = $conn->query("SHOW COLUMNS FROM tracking LIKE 'ocr_summary'");
if ($check && $check->num_rows === 0) {
    $sql = "ALTER TABLE tracking ADD COLUMN ocr_summary TEXT COMMENT 'Aggregated searchable keywords from all pages' AFTER ocr_content";
    if ($conn->query($sql)) {
        $migrations[] = "Added ocr_summary column to tracking table";
    } else {
        $errors[] = "Failed to add ocr_summary to tracking: " . $conn->error;
    }
} else {
    $migrations[] = "tracking.ocr_summary already exists";
}

// 3. Add ocr_summary column to archive if not exists
$check = $conn->query("SHOW COLUMNS FROM archive LIKE 'ocr_summary'");
if ($check && $check->num_rows === 0) {
    $sql = "ALTER TABLE archive ADD COLUMN ocr_summary TEXT COMMENT 'Aggregated searchable keywords from all pages' AFTER original_ocr_content";
    if ($conn->query($sql)) {
        $migrations[] = "Added ocr_summary column to archive table";
    } else {
        $errors[] = "Failed to add ocr_summary to archive: " . $conn->error;
    }
} else {
    $migrations[] = "archive.ocr_summary already exists";
}

// 4. Add total_pages column to tracking if not exists
$check = $conn->query("SHOW COLUMNS FROM tracking LIKE 'total_pages'");
if ($check && $check->num_rows === 0) {
    $sql = "ALTER TABLE tracking ADD COLUMN total_pages INT DEFAULT 1 AFTER ocr_summary";
    if ($conn->query($sql)) {
        $migrations[] = "Added total_pages column to tracking table";
    } else {
        $errors[] = "Failed to add total_pages to tracking: " . $conn->error;
    }
} else {
    $migrations[] = "tracking.total_pages already exists";
}

// 5. Add total_pages column to archive if not exists
$check = $conn->query("SHOW COLUMNS FROM archive LIKE 'total_pages'");
if ($check && $check->num_rows === 0) {
    $sql = "ALTER TABLE archive ADD COLUMN total_pages INT DEFAULT 1 AFTER ocr_summary";
    if ($conn->query($sql)) {
        $migrations[] = "Added total_pages column to archive table";
    } else {
        $errors[] = "Failed to add total_pages to archive: " . $conn->error;
    }
} else {
    $migrations[] = "archive.total_pages already exists";
}

// 6. Migrate existing ocr_content to ocr_pages (one-time migration)
$migrated_count = 0;
$res = $conn->query("SELECT id, ocr_content FROM tracking WHERE ocr_content IS NOT NULL AND ocr_content != '' AND id NOT IN (SELECT doc_id FROM ocr_pages WHERE scope='tracking')");
if ($res) {
    $stmt = $conn->prepare("INSERT IGNORE INTO ocr_pages (scope, doc_id, page_number, ocr_text, text_sha256) VALUES ('tracking', ?, 1, ?, ?)");
    while ($row = $res->fetch_assoc()) {
        $doc_id = (int)$row['id'];
        $ocr = $row['ocr_content'];
        $hash = hash('sha256', $ocr);
        $stmt->bind_param('iss', $doc_id, $ocr, $hash);
        if ($stmt->execute()) {
            $migrated_count++;
        }
    }
    $stmt->close();
    if ($migrated_count > 0) {
        $migrations[] = "Migrated $migrated_count existing tracking OCR records to ocr_pages";
    }
}

// Same for archive
$migrated_archive = 0;
$res = $conn->query("SELECT id, original_ocr_content FROM archive WHERE original_ocr_content IS NOT NULL AND original_ocr_content != '' AND id NOT IN (SELECT doc_id FROM ocr_pages WHERE scope='archive')");
if ($res) {
    $stmt = $conn->prepare("INSERT IGNORE INTO ocr_pages (scope, doc_id, page_number, ocr_text, text_sha256) VALUES ('archive', ?, 1, ?, ?)");
    while ($row = $res->fetch_assoc()) {
        $doc_id = (int)$row['id'];
        $ocr = $row['original_ocr_content'];
        $hash = hash('sha256', $ocr);
        $stmt->bind_param('iss', $doc_id, $ocr, $hash);
        if ($stmt->execute()) {
            $migrated_archive++;
        }
    }
    $stmt->close();
    if ($migrated_archive > 0) {
        $migrations[] = "Migrated $migrated_archive existing archive OCR records to ocr_pages";
    }
}

$conn->close();

echo json_encode([
    'success' => empty($errors),
    'migrations' => $migrations,
    'errors' => $errors,
], JSON_PRETTY_PRINT);
