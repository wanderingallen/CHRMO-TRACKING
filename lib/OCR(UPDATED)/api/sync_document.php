<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/archive_storage.php';
require_once __DIR__ . '/../firestore_client.php';

function stats_table_exists_mysqli($conn, $tableName) {
    $name = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$name}'");
    if (!$res) return false;
    $ok = ($res->num_rows > 0);
    $res->free();
    return $ok;
}

function stats_column_exists_mysqli($conn, $tableName, $columnName) {
    $t = $conn->real_escape_string($tableName);
    $c = $conn->real_escape_string($columnName);
    $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    if (!$res) return false;
    $ok = ($res->num_rows > 0);
    $res->free();
    return $ok;
}

function stats_insert_generation_row($conn, $type, $department, $status, $fileTypeIcon) {
    try {
        if (!stats_table_exists_mysqli($conn, 'stats')) return;
        $hasDate = stats_column_exists_mysqli($conn, 'stats', 'date');
        $hasDateArchived = stats_column_exists_mysqli($conn, 'stats', 'date_archived');
        $hasDocument = stats_column_exists_mysqli($conn, 'stats', 'document');
        $hasType = stats_column_exists_mysqli($conn, 'stats', 'type');
        $docCol = $hasDocument ? 'document' : ($hasType ? 'type' : null);
        $dateCol = $hasDate ? 'date' : ($hasDateArchived ? 'date_archived' : null);
        if (!$docCol || !$dateCol) return;

        $sql = "INSERT INTO stats (`{$docCol}`, `department`, `status`, `{$dateCol}`, `file_type_icon`) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return;
        $dateVal = date('Y-m-d');
        $typeVal = (string)$type;
        $deptVal = (string)$department;
        $statusVal = (string)$status;
        $iconVal = (string)$fileTypeIcon;
        $stmt->bind_param('sssss', $typeVal, $deptVal, $statusVal, $dateVal, $iconVal);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $t) {
        // ignore
    }
}

// Database connection details
$servername = "localhost";
$username = "root";
$password = "";
$database = "chrmo_db";

try {
    // Create connection
    $connection = new mysqli($servername, $username, $password, $database);
    
    // Ensure tracking.doc_hash exists (best-effort; ignore errors if lacking perms)
    try {
        $dbNameEsc = $connection->real_escape_string($database);
        $chk = $connection->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$dbNameEsc}' AND TABLE_NAME='tracking' AND COLUMN_NAME='doc_hash'");
        if ($chk && $chk->num_rows === 0) {
            $connection->query("ALTER TABLE tracking ADD COLUMN doc_hash CHAR(64) NULL");
            $connection->query("CREATE INDEX idx_tracking_doc_hash ON tracking (doc_hash)");
        }
        if ($chk) { $chk->free(); }
    } catch (Throwable $t) { /* noop */ }

    // Check connection
    if ($connection->connect_error) {
        throw new Exception("Connection failed: " . $connection->connect_error);
    }
    
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Only POST method allowed");
    }
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception("Invalid JSON data");
    }
    
    // Validate required fields
    $required_fields = ['type', 'employee_name', 'date_submitted', 'current_holder', 'status', 'department'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Prepare data for insertion
    $type = $connection->real_escape_string($data['type']);
    $employee_name = $connection->real_escape_string($data['employee_name']);
    $date_submitted = $connection->real_escape_string($data['date_submitted']);
    $department = $connection->real_escape_string($data['department']);
    $current_holder = $department;
    $end_location = isset($data['end_location']) ? $connection->real_escape_string($data['end_location']) : 'Mobile App Archive';
    $status = $connection->real_escape_string($data['status']);
    $file_type_icon = isset($data['file_type_icon']) ? $connection->real_escape_string($data['file_type_icon']) : 'file';
    $ocr_content = isset($data['ocr_content']) ? $connection->real_escape_string($data['ocr_content']) : '';
    $mobile_timestamp = isset($data['mobile_timestamp']) ? trim($data['mobile_timestamp']) : '';
    $file_size = isset($data['file_size']) ? $connection->real_escape_string($data['file_size']) : '0';
    $user_email = isset($data['user_email']) ? $connection->real_escape_string($data['user_email']) : '';
    $file_url = isset($data['file_url']) ? trim($data['file_url']) : '';
    
    // Get doc_hash from request if provided, otherwise compute it
    $doc_hash = isset($data['doc_hash']) && !empty($data['doc_hash']) ? trim($data['doc_hash']) : '';
    if (empty($doc_hash)) {
        // Compute stable document identity hash: type + employee_name + end_location
    $identity_key = strtolower(trim($type . '|' . $employee_name . '|' . $end_location));
    $doc_hash = hash('sha256', $identity_key);
    }
    
    // CRITICAL: Check if document already exists BEFORE inserting
    // Use multiple methods to prevent duplicates
    $existing_id = null;
    
    // Method 1: Check by type + employee_name + end_location (most reliable for preventing duplicates)
    // This catches documents with same type/employee/end_location even if mobile_timestamp differs
    if ($type !== '' && $employee_name !== '' && $end_location !== '') {
        $check_sql = "SELECT id FROM tracking WHERE type = ? AND employee_name = ? AND end_location = ? ORDER BY id ASC LIMIT 1";
        $check_stmt = $connection->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("sss", $type, $employee_name, $end_location);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result && $check_result->num_rows > 0) {
                $existing_row = $check_result->fetch_assoc();
                $existing_id = (int)$existing_row['id'];
            }
            $check_stmt->close();
        }
    }
    
    // Method 2: Check by mobile_timestamp + doc_hash (if both available)
    if ($existing_id === null && !empty($mobile_timestamp) && !empty($doc_hash)) {
        $check_sql = "SELECT id FROM tracking WHERE mobile_timestamp = ? AND doc_hash = ? LIMIT 1";
        $check_stmt = $connection->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("ss", $mobile_timestamp, $doc_hash);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result && $check_result->num_rows > 0) {
                $existing_row = $check_result->fetch_assoc();
                $existing_id = (int)$existing_row['id'];
            }
            $check_stmt->close();
        }
    }
    
    // Method 3: Fallback - Check by mobile_timestamp alone
    if ($existing_id === null && !empty($mobile_timestamp)) {
        $check_sql = "SELECT id FROM tracking WHERE mobile_timestamp = ? LIMIT 1";
        $check_stmt = $connection->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("s", $mobile_timestamp);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result && $check_result->num_rows > 0) {
                $existing_row = $check_result->fetch_assoc();
                $existing_id = (int)$existing_row['id'];
            }
            $check_stmt->close();
        }
    }
    
    // If document already exists, return success without creating duplicate
    if ($existing_id !== null) {
        echo json_encode([
            'success' => true,
            'message' => 'Document already exists in tracking system',
            'action' => 'skipped',
            'existing_document_id' => $existing_id
        ]);
        $connection->close();
        exit();
    }
    
    // Generate unique mobile_timestamp if not provided (for NEW documents only)
    if (empty($mobile_timestamp)) {
        $mobile_timestamp = 'AUTO_' . time() . '_' . uniqid() . '_' . mt_rand(1000, 9999);
    }
    
    // Escape mobile_timestamp for insertion
    $mobile_timestamp = $connection->real_escape_string($mobile_timestamp);

    // ---------------- GALLERY-ONLY PATH: write directly to archive table ----------------
    // Gallery uploads mark their mobile_timestamp with a GALLERY_ prefix. For these, we
    // bypass the tracking table and create an archive row so they show up only on
    // archive.php and not on tracking.php.
    if ($mobile_timestamp !== '' && strpos($mobile_timestamp, 'GALLERY_') === 0) {
        // Convert numeric byte size to human-readable like archive.php/upload_archive.php
        $archiveSize = '';
        $bytes = (int)$file_size;
        if ($bytes > 0) {
            if ($bytes < 1024) {
                $archiveSize = $bytes . 'B';
            } elseif ($bytes < 1024 * 1024) {
                $archiveSize = number_format($bytes / 1024, 1) . 'KB';
            } else {
                $archiveSize = number_format($bytes / (1024 * 1024), 1) . 'MB';
            }
        }

        // Insert directly into archive using the same columns archive.php expects
        $archiveSql = "INSERT INTO archive (
            document_name,
            department,
            type,
            status,
            date_archived,
            size,
            file_type_icon
        ) VALUES (?, ?, ?, 'Archived', ?, ?, ?)";

        $archiveStmt = $connection->prepare($archiveSql);
        if (!$archiveStmt) {
            throw new Exception("Archive prepare failed: " . $connection->error);
        }

        $archive_date = $date_submitted; // use submitted date as archived date
        $doc_name = $employee_name;      // match existing archive naming (user name)

        $archiveStmt->bind_param(
            'ssssss',
            $doc_name,
            $department,
            $type,
            $archive_date,
            $archiveSize,
            $file_type_icon
        );

        if (!$archiveStmt->execute()) {
            throw new Exception("Archive execute failed: " . $archiveStmt->error);
        }
        $newArchiveId = $archiveStmt->insert_id;
        $archiveStmt->close();

        // If the mobile client uploaded an encrypted file via upload_archive.php
        // and provided its relative path in file_url, rename it so that it uses
        // the canonical archive naming pattern: <id>_original.enc. This allows
        // archive_find_file_path() and archive.php to locate and preview it.
        if ($file_url !== '') {
            $sourcePath = __DIR__ . '/../' . ltrim($file_url, '/');
            if (is_file($sourcePath)) {
                $uploadDir = archive_uploads_dir();
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                $baseName = basename($sourcePath);
                // Keep the original base name but prefix with archive ID
                $encName = $newArchiveId . '_' . $baseName;
                $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/' . $encName;
                archive_delete_existing_files($newArchiveId, $targetPath);
                @rename($sourcePath, $targetPath);
            }
        }

        // Persist monthly/yearly reporting snapshot for gallery uploads too (best-effort)
        stats_insert_generation_row($connection, $type, $department, 'Archived', $file_type_icon);

        echo json_encode([
            'success' => true,
            'message' => 'Document added directly to archive from gallery upload',
            'action' => 'archived_only'
        ]);
        $connection->close();
        exit();
    }

    // Insert document into tracking table
    $sql = "INSERT INTO tracking (
        type, 
        employee_name, 
        date_submitted, 
        current_holder, 
        end_location, 
        status, 
        department, 
        file_type_icon,
        ocr_content,
        mobile_timestamp,
        file_size,
        user_email,
        file_path,
        doc_hash,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $connection->error);
    }
    
    // For JSON uploads, allow client to pass a web-accessible file URL (file_url)
    // If not provided, file_path remains NULL and only OCR/text metadata is stored.
    $file_path = ($file_url !== '') ? $connection->real_escape_string($file_url) : NULL;
    
    $stmt->bind_param(
        "ssssssssssssss",
        $type,
        $employee_name,
        $date_submitted,
        $current_holder,
        $end_location,
        $status,
        $department,
        $file_type_icon,
        $ocr_content,
        $mobile_timestamp,
        $file_size,
        $user_email,
        $file_path,
        $doc_hash
    );
    
    if ($stmt->execute()) {
        $document_id = $connection->insert_id;

        // Mirror this tracking row into Firestore for realtime dashboard updates
        try {
            firestore_upsert_tracking($document_id, [
                'id'               => (int)$document_id,
                'type'             => $type,
                'employee_name'    => $employee_name,
                'department'       => $department,
                'status'           => $status,
                'current_holder'   => $current_holder,
                'end_location'     => $end_location,
                'date_submitted'   => $date_submitted,
                'mobile_timestamp' => $mobile_timestamp,
                'file_type_icon'   => $file_type_icon,
                'updatedAt'        => (int)round(microtime(true) * 1000),
            ]);
        } catch (Throwable $t) {
            error_log('firestore_upsert_tracking failed in sync_document.php: ' . $t->getMessage());
        }
        
        // Persist monthly/yearly reporting snapshot (best-effort; supports old/new stats schema)
        // Use server "today" date so the current-month report matches actual uploads.
        stats_insert_generation_row($connection, $type, $department, $status, $file_type_icon);

        // Mirror this mobile-created document into mobile_archive table for mobile-only history
        try {
            $archive_name   = $type . ' - ' . $employee_name;
            $archive_size   = $file_size !== '' ? $file_size . 'B' : NULL;
            $archive_status = 'Archived';
            $archive_date   = $date_submitted; // use submitted date as archived date
            $archive_path   = $file_url !== '' ? $file_url : NULL;

            // Insert into mobile_archive with extended schema (doc_hash, mobile_timestamp, user_email, batch_id)
            $nullBatchId = null;
            $mobileArchiveSql = "INSERT INTO mobile_archive (
                document_name,
                department,
                type,
                status,
                date_archived,
                size,
                file_type_icon,
                file_path,
                doc_hash,
                mobile_timestamp,
                user_email,
                batch_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            if ($ma = $connection->prepare($mobileArchiveSql)) {
                $ma->bind_param(
                    "ssssssssssss",
                    $archive_name,
                    $department,
                    $type,
                    $archive_status,
                    $archive_date,
                    $archive_size,
                    $file_type_icon,
                    $archive_path,
                    $doc_hash,
                    $mobile_timestamp,
                    $user_email,
                    $nullBatchId
                );
                $ma->execute();
                $ma->close();
            }
        } catch (Throwable $t) {
            // Do not fail the main sync if mobile_archive is missing or insert fails,
            // but log the error for debugging.
            error_log('mobile_archive insert failed: ' . $t->getMessage());
        }

        // Create a notification for this mobile upload (best-effort)
        try {
            $notifUrl = 'http://localhost/flutter_application_7/lib/OCR(UPDATED)/api/notifications.php';
            $payload = [
                'action'               => 'create',
                'title'                => $type . ' - ' . $employee_name,
                'content'              => 'Mobile Upload  b7 ' . $department . '  b7 ' . $status,
                'type'                 => 'tracking',
                'recipient_username'   => '',
                'recipient_department' => $department,
                'file_url'             => $file_url,
            ];

            $ch = curl_init($notifUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($payload),
                CURLOPT_TIMEOUT        => 2,
            ]);
            $respBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode < 200 || $httpCode >= 300) {
                error_log('notifications.php call failed in sync_document.php: HTTP ' . $httpCode . ' body=' . $respBody);
            }
        } catch (Throwable $t) {
            error_log('notifications.php call threw in sync_document.php: ' . $t->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Document successfully added to tracking system',
            'document_id' => $document_id,
            'action' => 'inserted'
        ]);
    } else {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $stmt->close();
    $connection->close();
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
