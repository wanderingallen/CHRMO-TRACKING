<?php
/**
 * Migration Script: Tracking Schema Setup
 * 
 * Purpose: One-time execution to add the doc_hash column, index, and document_history table.
 * This was removed from tracking.php to avoid running DDL on every page load (performance cost).
 * 
 * Usage: Run this script once via CLI or browser:
 *   php tools/migrate_tracking_schema.php
 *   or visit: http://yourserver/path/to/tools/migrate_tracking_schema.php
 * 
 * After running, you can safely delete or archive this file.
 */

// Database connection details
$servername = "localhost";
$username = "root";
$password = "";
$database = "chrmo_db";

$connection = new mysqli($servername, $username, $password, $database);

// Check database connection
if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

echo "Starting migration for tracking schema...\n";
$success = true;

function index_exists(mysqli $connection, string $database, string $table, string $indexName): bool {
    $db = $connection->real_escape_string($database);
    $tbl = $connection->real_escape_string($table);
    $idx = $connection->real_escape_string($indexName);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$tbl}' AND INDEX_NAME='{$idx}' LIMIT 1";
    $res = $connection->query($sql);
    if (!$res) {
        return false;
    }
    $exists = ($res->num_rows > 0);
    $res->free();
    return $exists;
}

// 1. Add doc_hash column if it doesn't exist
try {
    $colCheck = $connection->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                                     WHERE TABLE_SCHEMA='" . $connection->real_escape_string($database) . "' 
                                       AND TABLE_NAME='tracking' 
                                       AND COLUMN_NAME='doc_hash'");
    
    if ($colCheck && $colCheck->num_rows === 0) {
        echo "Adding doc_hash column...\n";
        if ($connection->query("ALTER TABLE tracking ADD COLUMN doc_hash CHAR(64) NULL AFTER file_path")) {
            echo "✓ doc_hash column added successfully.\n";
        } else {
            echo "✗ Error adding doc_hash column: " . $connection->error . "\n";
            $success = false;
        }
    } else {
        echo "✓ doc_hash column already exists.\n";
    }
    
    if ($colCheck) { $colCheck->free(); }
} catch (Throwable $e) {
    echo "✗ Error checking doc_hash column: " . $e->getMessage() . "\n";
    $success = false;
}

// 2. Create index on doc_hash
try {
    echo "Creating index on doc_hash...\n";
    if (!index_exists($connection, $database, 'tracking', 'idx_tracking_doc_hash')) {
        if ($connection->query("CREATE INDEX idx_tracking_doc_hash ON tracking (doc_hash)")) {
            echo "✓ Index idx_tracking_doc_hash created successfully.\n";
        } else {
            echo "✗ Error creating idx_tracking_doc_hash: " . $connection->error . "\n";
            $success = false;
        }
    } else {
        echo "✓ Index idx_tracking_doc_hash already exists.\n";
    }
} catch (Throwable $e) {
    echo "✗ Error creating index: " . $e->getMessage() . "\n";
    $success = false;
}

// 3. Create document_history table if it doesn't exist
try {
    echo "Creating document_history table...\n";
    $sql_history = "CREATE TABLE IF NOT EXISTS document_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        action VARCHAR(32) NOT NULL,
        actor_user_id INT NULL,
        from_status VARCHAR(100) NULL,
        to_status VARCHAR(100) NULL,
        from_holder VARCHAR(255) NULL,
        to_holder VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (doc_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if ($connection->query($sql_history)) {
        echo "✓ document_history table created successfully (or already exists).\n";
    } else {
        echo "✗ Error creating document_history table: " . $connection->error . "\n";
        $success = false;
    }
} catch (Throwable $e) {
    echo "✗ Error creating document_history table: " . $e->getMessage() . "\n";
    $success = false;
}

// 4. Performance indexes used by charts/analytics
try {
    echo "Creating performance indexes...\n";

    // document_history: speed up monthly counts and predictive history
    if (index_exists($connection, $database, 'document_history', 'idx_document_history_action_created_at') === false) {
        if ($connection->query("CREATE INDEX idx_document_history_action_created_at ON document_history (action, created_at)")) {
            echo "✓ Index idx_document_history_action_created_at created successfully.\n";
        } else {
            echo "ℹ Could not create idx_document_history_action_created_at (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_document_history_action_created_at already exists.\n";
    }

    // tracking: speed up common filters/groups
    if (index_exists($connection, $database, 'tracking', 'idx_tracking_status') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_status ON tracking (status)")) {
            echo "✓ Index idx_tracking_status created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_status (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_status already exists.\n";
    }

    if (index_exists($connection, $database, 'tracking', 'idx_tracking_created_at') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_created_at ON tracking (created_at)")) {
            echo "✓ Index idx_tracking_created_at created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_created_at (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_created_at already exists.\n";
    }

    if (index_exists($connection, $database, 'tracking', 'idx_tracking_date_submitted') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_date_submitted ON tracking (date_submitted)")) {
            echo "✓ Index idx_tracking_date_submitted created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_date_submitted (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_date_submitted already exists.\n";
    }

    // tracking: department column (used in filters, search, sidebar counts)
    if (index_exists($connection, $database, 'tracking', 'idx_tracking_department') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_department ON tracking (department)")) {
            echo "✓ Index idx_tracking_department created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_department (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_department already exists.\n";
    }

    // tracking: current_holder (used in filters, document routing)
    if (index_exists($connection, $database, 'tracking', 'idx_tracking_current_holder') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_current_holder ON tracking (current_holder(100))")) {
            echo "✓ Index idx_tracking_current_holder created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_current_holder (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_current_holder already exists.\n";
    }

    // tracking: end_location (used in filters, routing)
    if (index_exists($connection, $database, 'tracking', 'idx_tracking_end_location') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_end_location ON tracking (end_location(100))")) {
            echo "✓ Index idx_tracking_end_location created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_end_location (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_end_location already exists.\n";
    }

    // tracking: composite (status, created_at) — used by sidebar stats & date-range queries
    if (index_exists($connection, $database, 'tracking', 'idx_tracking_status_created') === false) {
        if ($connection->query("CREATE INDEX idx_tracking_status_created ON tracking (status, created_at)")) {
            echo "✓ Index idx_tracking_status_created created successfully.\n";
        } else {
            echo "ℹ Could not create idx_tracking_status_created (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_tracking_status_created already exists.\n";
    }

    // tracking: FULLTEXT on ocr_content + ocr_summary for fast OCR search
    if (index_exists($connection, $database, 'tracking', 'ft_tracking_ocr') === false) {
        // FULLTEXT requires InnoDB or MyISAM; MySQL 5.6+ supports InnoDB FULLTEXT
        if ($connection->query("ALTER TABLE tracking ADD FULLTEXT INDEX ft_tracking_ocr (ocr_content, ocr_summary)")) {
            echo "✓ FULLTEXT index ft_tracking_ocr created successfully.\n";
        } else {
            echo "ℹ Could not create ft_tracking_ocr (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ FULLTEXT index ft_tracking_ocr already exists.\n";
    }

    // notifications: (tracking_id) for fast lookup when deleting linked notifs
    if (index_exists($connection, $database, 'notifications', 'idx_notif_tracking_id') === false) {
        if ($connection->query("CREATE INDEX idx_notif_tracking_id ON notifications (tracking_id)")) {
            echo "✓ Index idx_notif_tracking_id created successfully.\n";
        } else {
            echo "ℹ Could not create idx_notif_tracking_id (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ Index idx_notif_tracking_id already exists.\n";
    }

    // departments: dept_type column for future growth (internal/external/custom)
    $colCheck = $connection->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                                     WHERE TABLE_SCHEMA='" . $connection->real_escape_string($database) . "' 
                                       AND TABLE_NAME='departments' 
                                       AND COLUMN_NAME='dept_type'");
    if ($colCheck && $colCheck->num_rows === 0) {
        if ($connection->query("ALTER TABLE departments ADD COLUMN dept_type ENUM('internal','external','custom') NOT NULL DEFAULT 'internal' AFTER is_default")) {
            echo "✓ dept_type column added to departments.\n";
            // Mark existing default departments as 'internal', non-defaults as 'custom'
            $connection->query("UPDATE departments SET dept_type = 'internal' WHERE is_default = 1");
            $connection->query("UPDATE departments SET dept_type = 'custom' WHERE is_default = 0");
            echo "✓ Existing departments classified (internal/custom).\n";
        } else {
            echo "ℹ Could not add dept_type column (non-fatal): " . $connection->error . "\n";
        }
    } else {
        echo "✓ dept_type column already exists in departments.\n";
    }
    if ($colCheck) { $colCheck->free(); }

} catch (Throwable $e) {
    echo "✗ Error creating performance indexes: " . $e->getMessage() . "\n";
    $success = false;
}

$connection->close();

if ($success) {
    echo "\n✓ Migration completed successfully!\n";
} else {
    echo "\n✗ Migration completed with errors. Check above for details.\n";
}
?>
