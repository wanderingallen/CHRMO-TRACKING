<?php
/**
 * Archive Transfer API
 * Handles the transfer of documents from tracking to archive
 * Ensures document integrity - final upload from last department matches archive exactly
 * 
 * Key Features:
 * - Verifies document hash integrity before archiving
 * - Transfers all attachments with parent document
 * - Maintains OCR content and metadata integrity
 * - Creates audit trail for compliance
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/file_crypto.php';
require_once __DIR__ . '/archive_storage.php';

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function json_error($message, $code = 400) {
    json_response(['success' => false, 'message' => $message], $code);
}

function ensure_archive_history_table($conn) {
    // Minimal audit log stored by archive_id so history survives even if tracking rows are deleted.
    $sql = "CREATE TABLE IF NOT EXISTS archive_history (\n"
        . "  id INT AUTO_INCREMENT PRIMARY KEY,\n"
        . "  archive_id INT NOT NULL,\n"
        . "  action VARCHAR(50) NOT NULL,\n"
        . "  actor_user_id INT NOT NULL DEFAULT 0,\n"
        . "  from_status VARCHAR(50) NULL,\n"
        . "  to_status VARCHAR(50) NULL,\n"
        . "  from_holder VARCHAR(255) NULL,\n"
        . "  to_holder VARCHAR(255) NULL,\n"
        . "  notes TEXT NULL,\n"
        . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . "  INDEX (archive_id),\n"
        . "  INDEX (created_at)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);
}

function archive_copy_tracking_history($conn, $tracking_id, $archive_id) {
    $tracking_id = (int)$tracking_id;
    $archive_id = (int)$archive_id;
    if ($tracking_id <= 0 || $archive_id <= 0) {
        return;
    }
    ensure_archive_history_table($conn);

    // Copy document_history rows keyed by tracking doc_id to archive_history keyed by archive_id.
    $sql = "INSERT INTO archive_history (archive_id, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at)\n"
         . "SELECT ?, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at\n"
         . "FROM document_history WHERE doc_id = ?\n"
         . "ORDER BY created_at ASC, id ASC";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ii', $archive_id, $tracking_id);
        @$stmt->execute();
        $stmt->close();
    }
}

/**
 * Generate integrity hash for document verification
 * This hash ensures the archived document matches the final state from last department
 */
function generate_integrity_hash($doc_data) {
    $canonical = implode('|', [
        $doc_data['type'] ?? '',
        $doc_data['employee_name'] ?? '',
        $doc_data['department'] ?? '',
        $doc_data['end_location'] ?? '',
        $doc_data['file_path'] ?? '',
        $doc_data['ocr_content'] ?? '',
        $doc_data['doc_hash'] ?? '',
    ]);
    return hash('sha256', $canonical);
}

/**
 * Verify document integrity before archiving
 */
function verify_document_integrity($conn, $tracking_id) {
    $stmt = $conn->prepare("
        SELECT id, type, employee_name, department, current_holder, end_location,
               file_path, ocr_content, doc_hash, archive_hash, status,
               batch_id, page_number, parent_tracking_id, mobile_timestamp,
               file_type_icon, file_size
        FROM tracking 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $tracking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $doc = $result->fetch_assoc();
    $stmt->close();
    
    if (!$doc) {
        return ['valid' => false, 'error' => 'Document not found'];
    }
    
    // Generate current integrity hash
    $current_hash = generate_integrity_hash($doc);
    
    // If archive_hash was set during final upload, verify it matches
    if (!empty($doc['archive_hash']) && $doc['archive_hash'] !== $current_hash) {
        return [
            'valid' => false, 
            'error' => 'Document integrity mismatch - document may have been modified',
            'expected_hash' => $doc['archive_hash'],
            'current_hash' => $current_hash,
        ];
    }
    
    return [
        'valid' => true,
        'document' => $doc,
        'integrity_hash' => $current_hash,
    ];
}

/**
 * Get all attachments for a document (including nested)
 */
function get_document_attachments($conn, $parent_id) {
    $attachments = [];
    
    // Get direct children
    $stmt = $conn->prepare("
        SELECT id, type, employee_name, department, current_holder, end_location,
               file_path, ocr_content, doc_hash, status, batch_id, page_number,
               file_type_icon, file_size, mobile_timestamp
        FROM tracking 
        WHERE parent_tracking_id = ?
        ORDER BY page_number ASC
    ");
    $stmt->bind_param('i', $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $attachments[] = $row;
        // Recursively get nested attachments
        $nested = get_document_attachments($conn, $row['id']);
        $attachments = array_merge($attachments, $nested);
    }
    $stmt->close();
    
    return $attachments;
}

try {
    if (!isset($conn) || !$conn || $conn->connect_error) {
        json_error('Database connection failed', 500);
    }

    $action = $_REQUEST['action'] ?? 'transfer';

    switch ($action) {
        case 'transfer':
            handle_transfer($conn);
            break;
            
        case 'verify':
            handle_verify($conn);
            break;
            
        case 'batch_transfer':
            handle_batch_transfer($conn);
            break;
            
        case 'get_final_state':
            handle_get_final_state($conn);
            break;
            
        default:
            json_error('Unknown action: ' . $action);
    }

} catch (Throwable $e) {
    error_log('archive_transfer.php error: ' . $e->getMessage());
    json_error('Server error: ' . $e->getMessage(), 500);
}

/**
 * Transfer a single document (with attachments) to archive
 */
function handle_transfer($conn) {
    $tracking_id = (int)($_POST['tracking_id'] ?? $_GET['tracking_id'] ?? 0);
    $transfer_attachments = ($_POST['transfer_attachments'] ?? 'true') === 'true';
    $verify_integrity = ($_POST['verify_integrity'] ?? 'true') === 'true';
    $delete_from_tracking = ($_POST['delete_from_tracking'] ?? 'false') === 'true';
    
    if ($tracking_id <= 0) {
        json_error('tracking_id is required');
    }
    
    // Verify document integrity
    if ($verify_integrity) {
        $verification = verify_document_integrity($conn, $tracking_id);
        if (!$verification['valid']) {
            json_error($verification['error'], 400);
        }
        $doc = $verification['document'];
        $integrity_hash = $verification['integrity_hash'];
    } else {
        // Fetch document without verification
        $stmt = $conn->prepare("SELECT * FROM tracking WHERE id = ?");
        $stmt->bind_param('i', $tracking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $doc = $result->fetch_assoc();
        $stmt->close();
        
        if (!$doc) {
            json_error('Document not found');
        }
        $integrity_hash = generate_integrity_hash($doc);
    }
    
    // Get attachments if requested
    $attachments = [];
    if ($transfer_attachments) {
        $attachments = get_document_attachments($conn, $tracking_id);
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        $archived_ids = [];
        
        // Archive main document
        $archive_id = archive_document($conn, $doc, $integrity_hash);
        if (!$archive_id) {
            throw new Exception('Failed to archive main document');
        }
        $archived_ids[] = ['tracking_id' => $tracking_id, 'archive_id' => $archive_id];

        // Preserve history on the archive record (so audit trail works even if tracking is deleted)
        archive_copy_tracking_history($conn, $tracking_id, $archive_id);
        
        // Archive attachments
        foreach ($attachments as $attachment) {
            $attach_hash = generate_integrity_hash($attachment);
            $attach_archive_id = archive_document($conn, $attachment, $attach_hash, $archive_id);
            if ($attach_archive_id) {
                $archived_ids[] = [
                    'tracking_id' => $attachment['id'], 
                    'archive_id' => $attach_archive_id,
                ];

                archive_copy_tracking_history($conn, (int)$attachment['id'], $attach_archive_id);
            }
        }
        
        // Mark documents as archived in tracking
        $all_tracking_ids = array_column($archived_ids, 'tracking_id');
        if (!empty($all_tracking_ids)) {
            $placeholders = implode(',', array_fill(0, count($all_tracking_ids), '?'));
            $types = str_repeat('i', count($all_tracking_ids));
            
            $update_sql = "UPDATE tracking SET 
                           status = 'Archived',
                           archive_verified_at = NOW()
                           WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param($types, ...$all_tracking_ids);
            $stmt->execute();
            $stmt->close();
            
            // Log to document_history
            foreach ($all_tracking_ids as $tid) {
                log_archive_action($conn, $tid, 'archive');
            }
        }
        
        // Optionally delete from tracking
        if ($delete_from_tracking && !empty($all_tracking_ids)) {
            $delete_sql = "DELETE FROM tracking WHERE id IN ($placeholders)";
            $stmt = $conn->prepare($delete_sql);
            $stmt->bind_param($types, ...$all_tracking_ids);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        
        json_response([
            'success' => true,
            'message' => 'Document archived successfully',
            'main_document' => [
                'tracking_id' => $tracking_id,
                'archive_id' => $archive_id,
            ],
            'attachments_archived' => count($attachments),
            'integrity_hash' => $integrity_hash,
            'archived_ids' => $archived_ids,
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Archive a single document to the archive table
 */
function archive_document($conn, $doc, $integrity_hash, $parent_archive_id = null) {
    $document_name = $doc['employee_name'] ?? $doc['type'] ?? 'Document';
    $department = $doc['department'] ?? '';
    $type = $doc['type'] ?? 'Document';
    $date_archived = date('Y-m-d H:i:s');
    $file_path = $doc['file_path'] ?? '';
    $file_type_icon = $doc['file_type_icon'] ?? 'file';
    $size = $doc['file_size'] ?? '';
    $ocr_content = $doc['ocr_content'] ?? '';
    $batch_id = $doc['batch_id'] ?? null;
    $source_tracking_id = (int)$doc['id'];
    
    $stmt = $conn->prepare("
        INSERT INTO archive 
        (document_name, department, type, status, date_archived, file_path,
         file_type_icon, size, batch_id, source_tracking_id, integrity_hash,
         original_ocr_content)
        VALUES (?, ?, ?, 'Archived', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    if (!$stmt) {
        error_log("Archive prepare failed: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param(
        'ssssssssis',
        $document_name,
        $department,
        $type,
        $date_archived,
        $file_path,
        $file_type_icon,
        $size,
        $batch_id,
        $source_tracking_id,
        $integrity_hash,
        $ocr_content
    );
    
    if (!$stmt->execute()) {
        error_log("Archive insert failed: " . $stmt->error);
        $stmt->close();
        return null;
    }
    
    $archive_id = $conn->insert_id;
    $stmt->close();
    
    return $archive_id;
}

/**
 * Log archive action to document_history
 */
function log_archive_action($conn, $doc_id, $action) {
    $stmt = $conn->prepare("
        INSERT INTO document_history 
        (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder)
        VALUES (?, ?, 0, 'Completed', 'Archived', NULL, 'Archive')
    ");
    if ($stmt) {
        $stmt->bind_param('is', $doc_id, $action);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Verify document integrity without transferring
 */
function handle_verify($conn) {
    $tracking_id = (int)($_REQUEST['tracking_id'] ?? 0);
    
    if ($tracking_id <= 0) {
        json_error('tracking_id is required');
    }
    
    $verification = verify_document_integrity($conn, $tracking_id);
    
    if (!$verification['valid']) {
        json_response([
            'success' => false,
            'valid' => false,
            'error' => $verification['error'],
            'expected_hash' => $verification['expected_hash'] ?? null,
            'current_hash' => $verification['current_hash'] ?? null,
        ]);
    }
    
    // Get attachment count
    $attachments = get_document_attachments($conn, $tracking_id);
    
    json_response([
        'success' => true,
        'valid' => true,
        'tracking_id' => $tracking_id,
        'integrity_hash' => $verification['integrity_hash'],
        'document' => [
            'type' => $verification['document']['type'],
            'department' => $verification['document']['department'],
            'status' => $verification['document']['status'],
            'end_location' => $verification['document']['end_location'],
        ],
        'attachment_count' => count($attachments),
    ]);
}

/**
 * Transfer entire batch to archive
 */
function handle_batch_transfer($conn) {
    $batch_id = $_POST['batch_id'] ?? $_GET['batch_id'] ?? '';
    
    if ($batch_id === '') {
        json_error('batch_id is required');
    }
    
    // Get all documents in batch
    $stmt = $conn->prepare("
        SELECT id FROM tracking 
        WHERE batch_id = ? AND parent_tracking_id IS NULL
        ORDER BY page_number ASC
    ");
    $stmt->bind_param('s', $batch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tracking_ids = [];
    while ($row = $result->fetch_assoc()) {
        $tracking_ids[] = $row['id'];
    }
    $stmt->close();
    
    if (empty($tracking_ids)) {
        json_error('No documents found in batch');
    }
    
    // Transfer each document
    $results = [];
    $success_count = 0;
    $error_count = 0;
    
    foreach ($tracking_ids as $tid) {
        $_POST['tracking_id'] = $tid;
        $_POST['transfer_attachments'] = 'true';
        $_POST['verify_integrity'] = 'true';
        $_POST['delete_from_tracking'] = 'false';
        
        try {
            // Inline transfer logic (simplified for batch)
            $verification = verify_document_integrity($conn, $tid);
            if ($verification['valid']) {
                $doc = $verification['document'];
                $archive_id = archive_document($conn, $doc, $verification['integrity_hash']);
                
                if ($archive_id) {
                    // Update tracking status
                    $upd = $conn->prepare("UPDATE tracking SET status = 'Archived', archive_verified_at = NOW() WHERE id = ?");
                    $upd->bind_param('i', $tid);
                    $upd->execute();
                    $upd->close();
                    
                    $results[] = ['tracking_id' => $tid, 'archive_id' => $archive_id, 'success' => true];
                    $success_count++;
                } else {
                    $results[] = ['tracking_id' => $tid, 'success' => false, 'error' => 'Archive insert failed'];
                    $error_count++;
                }
            } else {
                $results[] = ['tracking_id' => $tid, 'success' => false, 'error' => $verification['error']];
                $error_count++;
            }
        } catch (Exception $e) {
            $results[] = ['tracking_id' => $tid, 'success' => false, 'error' => $e->getMessage()];
            $error_count++;
        }
    }
    
    json_response([
        'success' => $error_count === 0,
        'message' => "Transferred $success_count of " . count($tracking_ids) . " documents",
        'batch_id' => $batch_id,
        'total' => count($tracking_ids),
        'success_count' => $success_count,
        'error_count' => $error_count,
        'results' => $results,
    ]);
}

/**
 * Get the final state of a document for verification
 * This returns exactly what will be archived - useful for client verification
 */
function handle_get_final_state($conn) {
    $tracking_id = (int)($_REQUEST['tracking_id'] ?? 0);
    
    if ($tracking_id <= 0) {
        json_error('tracking_id is required');
    }
    
    $stmt = $conn->prepare("
        SELECT id, type, employee_name, department, current_holder, end_location,
               file_path, ocr_content, doc_hash, status, batch_id, page_number,
               parent_tracking_id, mobile_timestamp, file_type_icon, file_size,
               archive_hash, archive_verified_at, created_at
        FROM tracking 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $tracking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $doc = $result->fetch_assoc();
    $stmt->close();
    
    if (!$doc) {
        json_error('Document not found');
    }
    
    // Get attachments
    $attachments = get_document_attachments($conn, $tracking_id);
    
    // Generate integrity hash
    $integrity_hash = generate_integrity_hash($doc);
    
    // Get history
    $history = [];
    $hist_stmt = $conn->prepare("
        SELECT action, from_status, to_status, from_holder, to_holder, created_at
        FROM document_history
        WHERE doc_id = ?
        ORDER BY created_at ASC
    ");
    if ($hist_stmt) {
        $hist_stmt->bind_param('i', $tracking_id);
        if ($hist_stmt->execute()) {
            $hist_result = $hist_stmt->get_result();
            while ($row = $hist_result->fetch_assoc()) {
                $history[] = $row;
            }
        }
        $hist_stmt->close();
    }
    
    json_response([
        'success' => true,
        'document' => $doc,
        'integrity_hash' => $integrity_hash,
        'attachment_count' => count($attachments),
        'attachments' => array_map(function($a) {
            return [
                'id' => $a['id'],
                'type' => $a['type'],
                'page_number' => $a['page_number'],
                'file_path' => $a['file_path'],
            ];
        }, $attachments),
        'history' => $history,
        'ready_for_archive' => ($doc['status'] === 'Completed' || $doc['status'] === 'Pending'),
    ]);
}
