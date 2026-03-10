<?php
/**
 * Database Migration for Batch Document Support
 * Adds columns for batch processing and document attachments
 * 
 * Run this migration once to add support for:
 * - Batch uploads (10-15+ documents at once)
 * - Document attachments (parent-child relationships)
 * - Archive integrity verification
 */

require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json');

$migrations = [];
$errors = [];

// Helper function to check if column exists
function column_exists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Helper function to check if table exists
function table_exists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

try {
    // 1. Add batch_id column to tracking table
    if (!column_exists($conn, 'tracking', 'batch_id')) {
        $sql = "ALTER TABLE tracking ADD COLUMN batch_id VARCHAR(64) NULL DEFAULT NULL AFTER doc_hash";
        if ($conn->query($sql)) {
            $migrations[] = "Added batch_id column to tracking table";
            // Add index for batch lookups
            $conn->query("CREATE INDEX idx_tracking_batch_id ON tracking(batch_id)");
        } else {
            $errors[] = "Failed to add batch_id: " . $conn->error;
        }
    } else {
        $migrations[] = "batch_id column already exists";
    }

    // 2. Add page_number column for multi-page documents
    if (!column_exists($conn, 'tracking', 'page_number')) {
        $sql = "ALTER TABLE tracking ADD COLUMN page_number INT DEFAULT 1 AFTER batch_id";
        if ($conn->query($sql)) {
            $migrations[] = "Added page_number column to tracking table";
        } else {
            $errors[] = "Failed to add page_number: " . $conn->error;
        }
    } else {
        $migrations[] = "page_number column already exists";
    }

    // 3. Add parent_tracking_id for document attachments
    if (!column_exists($conn, 'tracking', 'parent_tracking_id')) {
        $sql = "ALTER TABLE tracking ADD COLUMN parent_tracking_id INT NULL DEFAULT NULL AFTER page_number";
        if ($conn->query($sql)) {
            $migrations[] = "Added parent_tracking_id column to tracking table";
            // Add index for attachment lookups
            $conn->query("CREATE INDEX idx_tracking_parent_id ON tracking(parent_tracking_id)");
        } else {
            $errors[] = "Failed to add parent_tracking_id: " . $conn->error;
        }
    } else {
        $migrations[] = "parent_tracking_id column already exists";
    }

    // 4. Add archive_hash column for integrity verification
    if (!column_exists($conn, 'tracking', 'archive_hash')) {
        $sql = "ALTER TABLE tracking ADD COLUMN archive_hash VARCHAR(128) NULL DEFAULT NULL AFTER parent_tracking_id";
        if ($conn->query($sql)) {
            $migrations[] = "Added archive_hash column to tracking table";
        } else {
            $errors[] = "Failed to add archive_hash: " . $conn->error;
        }
    } else {
        $migrations[] = "archive_hash column already exists";
    }

    // 5. Add archive_verified timestamp
    if (!column_exists($conn, 'tracking', 'archive_verified_at')) {
        $sql = "ALTER TABLE tracking ADD COLUMN archive_verified_at DATETIME NULL DEFAULT NULL AFTER archive_hash";
        if ($conn->query($sql)) {
            $migrations[] = "Added archive_verified_at column to tracking table";
        } else {
            $errors[] = "Failed to add archive_verified_at: " . $conn->error;
        }
    } else {
        $migrations[] = "archive_verified_at column already exists";
    }

    // 6. Add batch columns to archive table
    if (table_exists($conn, 'archive')) {
        if (!column_exists($conn, 'archive', 'batch_id')) {
            $sql = "ALTER TABLE archive ADD COLUMN batch_id VARCHAR(64) NULL DEFAULT NULL";
            if ($conn->query($sql)) {
                $migrations[] = "Added batch_id column to archive table";
            } else {
                $errors[] = "Failed to add batch_id to archive: " . $conn->error;
            }
        }

        if (!column_exists($conn, 'archive', 'source_tracking_id')) {
            $sql = "ALTER TABLE archive ADD COLUMN source_tracking_id INT NULL DEFAULT NULL";
            if ($conn->query($sql)) {
                $migrations[] = "Added source_tracking_id column to archive table";
            } else {
                $errors[] = "Failed to add source_tracking_id to archive: " . $conn->error;
            }
        }

        if (!column_exists($conn, 'archive', 'integrity_hash')) {
            $sql = "ALTER TABLE archive ADD COLUMN integrity_hash VARCHAR(128) NULL DEFAULT NULL";
            if ($conn->query($sql)) {
                $migrations[] = "Added integrity_hash column to archive table";
            } else {
                $errors[] = "Failed to add integrity_hash to archive: " . $conn->error;
            }
        }

        if (!column_exists($conn, 'archive', 'original_ocr_content')) {
            $sql = "ALTER TABLE archive ADD COLUMN original_ocr_content LONGTEXT NULL DEFAULT NULL";
            if ($conn->query($sql)) {
                $migrations[] = "Added original_ocr_content column to archive table";
            } else {
                $errors[] = "Failed to add original_ocr_content to archive: " . $conn->error;
            }
        }
    }

    // 7. Create document_attachments table for explicit attachment relationships
    if (!table_exists($conn, 'document_attachments')) {
        // Note: Foreign keys removed to avoid constraint issues with existing data
        $sql = "CREATE TABLE document_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_tracking_id INT NOT NULL,
            child_tracking_id INT NOT NULL,
            attachment_type ENUM('page', 'supplement', 'revision') DEFAULT 'page',
            page_order INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_attachment (parent_tracking_id, child_tracking_id),
            INDEX idx_parent (parent_tracking_id),
            INDEX idx_child (child_tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conn->query($sql)) {
            $migrations[] = "Created document_attachments table";
        } else {
            $errors[] = "Failed to create document_attachments: " . $conn->error;
        }
    } else {
        $migrations[] = "document_attachments table already exists";
    }

    // 8. Create batch_status table for tracking batch upload progress
    if (!table_exists($conn, 'batch_status')) {
        $sql = "CREATE TABLE batch_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(64) NOT NULL UNIQUE,
            total_documents INT DEFAULT 0,
            processed_documents INT DEFAULT 0,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            sender_name VARCHAR(255) NULL,
            sender_department VARCHAR(255) NULL,
            receiver_department VARCHAR(255) NULL,
            end_location VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            error_message TEXT NULL,
            INDEX idx_batch_id (batch_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conn->query($sql)) {
            $migrations[] = "Created batch_status table";
        } else {
            $errors[] = "Failed to create batch_status: " . $conn->error;
        }
    } else {
        $migrations[] = "batch_status table already exists";
    }

    $success = count($errors) === 0;
    
    echo json_encode([
        'success' => $success,
        'migrations' => $migrations,
        'errors' => $errors,
        'message' => $success 
            ? 'All migrations completed successfully' 
            : 'Some migrations failed',
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'migrations' => $migrations,
        'errors' => $errors,
    ]);
}

$conn->close();
