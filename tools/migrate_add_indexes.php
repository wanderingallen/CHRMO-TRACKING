<?php
/**
 * Migration Script: Add Performance Indexes
 * 
 * Purpose: One-time execution to add indexes on frequently-used columns for WHERE, GROUP, ORDER.
 * These indexes significantly improve query performance on the tracking and archive tables.
 * 
 * Usage: Run this script once via CLI or browser:
 *   php tools/migrate_add_indexes.php
 *   or visit: http://yourserver/path/to/tools/migrate_add_indexes.php
 * 
 * After running, you can safely delete or archive this file.
 */

$servername = "localhost";
$username = "root";
$password = "";
$database = "chrmo_db";

$connection = new mysqli($servername, $username, $password, $database);

if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

echo "Starting migration to add performance indexes...\n\n";
$success = true;

// List of indexes to create: [table, columns, index_name]
$indexes = [
    // Tracking table indexes
    ['tracking', 'created_at', 'idx_tracking_created_at'],
    ['tracking', 'date_submitted', 'idx_tracking_date_submitted'],
    ['tracking', 'status', 'idx_tracking_status'],
    ['tracking', 'department', 'idx_tracking_department'],
    ['tracking', 'type', 'idx_tracking_type'],
    ['tracking', 'current_holder', 'idx_tracking_current_holder'],
    ['tracking', 'end_location', 'idx_tracking_end_location'],
    
    // Archive table indexes
    ['archive', 'date_archived', 'idx_archive_date_archived'],
    ['archive', 'status', 'idx_archive_status'],
    ['archive', 'department', 'idx_archive_department'],
    ['archive', 'type', 'idx_archive_type'],
    
    // Document attachments indexes (for bundle queries)
    ['document_attachments', 'tracking_id', 'idx_attachments_tracking_id'],
    ['document_attachments', 'created_at', 'idx_attachments_created_at'],
    
    // Document history indexes (for timeline/bundle queries)
    ['document_history', 'doc_id', 'idx_history_doc_id'],
    ['document_history', 'created_at', 'idx_history_created_at'],
    
    // Document comments indexes
    ['document_comments', 'tracking_id', 'idx_comments_tracking_id'],
    ['document_comments', 'created_at', 'idx_comments_created_at'],
    
    // OCR pages indexes (for search)
    ['ocr_pages', 'scope, doc_id', 'idx_ocr_pages_scope_doc'],
    ['ocr_pages', 'doc_id', 'idx_ocr_pages_doc_id'],
];

foreach ($indexes as [$table, $columns, $indexName]) {
    try {
        echo "Creating index $indexName on $table($columns)...\n";
        $sql = "CREATE INDEX IF NOT EXISTS $indexName ON $table ($columns)";
        
        if ($connection->query($sql)) {
            echo "✓ Index $indexName created successfully (or already exists).\n";
        } else {
            echo "✗ Error creating index $indexName: " . $connection->error . "\n";
            $success = false;
        }
    } catch (Throwable $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        $success = false;
    }
    echo "\n";
}

$connection->close();

if ($success) {
    echo "✓ Index migration completed successfully!\n";
    echo "Your database should now have significantly faster query performance.\n";
} else {
    echo "✗ Migration completed with errors. Check above for details.\n";
}
?>
