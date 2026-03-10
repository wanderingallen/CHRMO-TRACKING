<?php
/**
 * Batch Document Upload API
 * Handles uploading and processing 10-15+ documents efficiently
 * Supports multi-document routing with attachments
 * Ensures document integrity from first department through archive
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/file_crypto.php';
require_once __DIR__ . '/archive_storage.php';
require_once __DIR__ . '/ocr_search_helper.php';

// Max batch size to prevent memory issues
define('MAX_BATCH_SIZE', 20);
define('MAX_SINGLE_FILE_MB', 10);

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function json_error($message, $code = 400, $details = null) {
    $response = ['success' => false, 'message' => $message];
    if ($details !== null) {
        $response['details'] = $details;
    }
    json_response($response, $code);
}

function generate_batch_id() {
    return 'BATCH_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function generate_document_hash($content, $timestamp) {
    return hash('sha256', $content . '|' . $timestamp . '|' . random_bytes(8));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Only POST allowed');
    }

    if (!isset($conn) || !$conn || $conn->connect_error) {
        json_error('Database connection failed', 500);
    }

    // Get request metadata
    $action = $_POST['action'] ?? 'upload_batch';
    $sender_name = trim($_POST['sender_name'] ?? '');
    $sender_department = trim($_POST['sender_department'] ?? '');
    $receiver_username = trim($_POST['receiver_username'] ?? '');
    $receiver_department = trim($_POST['receiver_department'] ?? '');
    $document_type = trim($_POST['document_type'] ?? 'Scanned Document');
    $end_location = trim($_POST['end_location'] ?? '');
    $parent_tracking_id = trim($_POST['parent_tracking_id'] ?? '');
    $batch_id = trim($_POST['batch_id'] ?? generate_batch_id());

    // Validate required fields
    if ($sender_name === '' || $sender_department === '') {
        json_error('Missing sender_name or sender_department');
    }

    switch ($action) {
        case 'upload_batch':
            handle_batch_upload($conn, $batch_id, $sender_name, $sender_department, 
                               $receiver_username, $receiver_department, 
                               $document_type, $end_location, $parent_tracking_id);
            break;
            
        case 'attach_to_document':
            handle_attach_documents($conn, $parent_tracking_id, $sender_name, $sender_department);
            break;
            
        case 'get_batch_status':
            handle_batch_status($conn, $batch_id);
            break;
            
        case 'finalize_batch':
            handle_finalize_batch($conn, $batch_id, $end_location);
            break;
            
        default:
            json_error('Unknown action: ' . $action);
    }

} catch (Throwable $e) {
    error_log('batch_upload.php error: ' . $e->getMessage());
    json_error('Server error: ' . $e->getMessage(), 500);
}

/**
 * Handle batch upload of multiple documents
 */
function handle_batch_upload($conn, $batch_id, $sender_name, $sender_department,
                             $receiver_username, $receiver_department,
                             $document_type, $end_location, $parent_tracking_id) {
    
    $results = [];
    $success_count = 0;
    $error_count = 0;
    
    // Process uploaded files
    $files = $_FILES['documents'] ?? [];
    $ocr_texts = $_POST['ocr_texts'] ?? [];
    $ocr_pages_data = $_POST['ocr_pages'] ?? []; // Per-page OCR arrays: ocr_pages[$docIndex][$pageNum]
    $mobile_timestamps = $_POST['mobile_timestamps'] ?? [];
    $doc_hashes = $_POST['doc_hashes'] ?? [];
    $page_numbers = $_POST['page_numbers'] ?? [];
    
    // Convert to arrays if single upload
    if (!is_array($files['name'] ?? null)) {
        $files = [
            'name' => [$files['name'] ?? ''],
            'tmp_name' => [$files['tmp_name'] ?? ''],
            'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$files['size'] ?? 0],
            'type' => [$files['type'] ?? ''],
        ];
    }
    
    $file_count = count($files['name'] ?? []);
    
    if ($file_count === 0) {
        json_error('No files provided');
    }
    
    if ($file_count > MAX_BATCH_SIZE) {
        json_error("Batch size exceeds maximum of " . MAX_BATCH_SIZE . " documents");
    }
    
    // Prepare upload directory
    $upload_dir = __DIR__ . '/../uploads/batch/' . $batch_id;
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            json_error('Failed to create upload directory', 500);
        }
    }
    
    // Current holder defaults to receiver department
    $current_holder = $receiver_department !== '' ? $receiver_department : $sender_department;
    
    // Process each file
    for ($i = 0; $i < $file_count; $i++) {
        $file_name = $files['name'][$i] ?? '';
        $tmp_path = $files['tmp_name'][$i] ?? '';
        $file_error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        $file_size = $files['size'][$i] ?? 0;
        
        $doc_result = [
            'index' => $i,
            'original_name' => $file_name,
            'success' => false,
            'message' => '',
            'tracking_id' => null,
            'document_hash' => null,
        ];
        
        // Check for upload errors
        if ($file_error !== UPLOAD_ERR_OK) {
            $doc_result['message'] = 'Upload error: ' . upload_error_message($file_error);
            $results[] = $doc_result;
            $error_count++;
            continue;
        }
        
        // Validate file size
        if ($file_size > MAX_SINGLE_FILE_MB * 1024 * 1024) {
            $doc_result['message'] = 'File exceeds ' . MAX_SINGLE_FILE_MB . 'MB limit';
            $results[] = $doc_result;
            $error_count++;
            continue;
        }
        
        // Generate unique identifiers
        $mobile_timestamp = $mobile_timestamps[$i] ?? ('BATCH_' . time() . '_' . $i);
        $doc_hash = $doc_hashes[$i] ?? generate_document_hash(file_get_contents($tmp_path), $mobile_timestamp);
        $page_number = (int)($page_numbers[$i] ?? ($i + 1));
        $ocr_content = $ocr_texts[$i] ?? '';
        
        // Determine file extension and icon
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $file_type_icon = get_file_icon($ext);
        
        // Generate target filename
        $safe_name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($file_name));
        $target_name = sprintf('%s_%03d_%s', $batch_id, $page_number, $safe_name);
        $target_path = $upload_dir . '/' . $target_name;
        
        // Encrypt and save file
        if (!file_crypto_encrypt_stream_to_path($tmp_path, $target_path . '.enc')) {
            // Fallback to unencrypted if encryption fails
            if (!move_uploaded_file($tmp_path, $target_path)) {
                $doc_result['message'] = 'Failed to save file';
                $results[] = $doc_result;
                $error_count++;
                continue;
            }
            $final_path = 'uploads/batch/' . $batch_id . '/' . $target_name;
        } else {
            $final_path = 'uploads/batch/' . $batch_id . '/' . $target_name . '.enc';
        }
        
        // Format file size for display
        $size_display = format_file_size($file_size);
        
        // Insert into tracking table
        $date_submitted = date('Y-m-d');
        $status = 'Pending';
        
        $stmt = $conn->prepare("
            INSERT INTO tracking 
            (type, employee_name, date_submitted, current_holder, end_location, status, 
             department, file_type_icon, ocr_content, mobile_timestamp, file_size, 
             file_path, doc_hash, batch_id, page_number, parent_tracking_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            $doc_result['message'] = 'Database prepare failed: ' . $conn->error;
            $results[] = $doc_result;
            $error_count++;
            continue;
        }
        
        // Convert parent_tracking_id to int or null
        $parent_id = ($parent_tracking_id !== '' && is_numeric($parent_tracking_id)) 
            ? (int)$parent_tracking_id 
            : null;
        
        $stmt->bind_param(
            'ssssssssssssssis',
            $document_type,
            $sender_name,
            $date_submitted,
            $current_holder,
            $end_location,
            $status,
            $sender_department,
            $file_type_icon,
            $ocr_content,
            $mobile_timestamp,
            $size_display,
            $final_path,
            $doc_hash,
            $batch_id,
            $page_number,
            $parent_id
        );
        
        if (!$stmt->execute()) {
            $doc_result['message'] = 'Database insert failed: ' . $stmt->error;
            $results[] = $doc_result;
            $error_count++;
            $stmt->close();
            continue;
        }
        
        $tracking_id = $conn->insert_id;
        $stmt->close();
        
        // Log to document_history
        log_document_action($conn, $tracking_id, 'create', $sender_name, 
                           null, 'Pending', null, $current_holder);
        
        // Store per-page OCR in ocr_pages table for smart search
        if (isset($ocr_pages_data[$i]) && is_array($ocr_pages_data[$i])) {
            // New format: per-page OCR array
            ocr_store_document_pages($conn, 'tracking', $tracking_id, $ocr_pages_data[$i]);
        } elseif (!empty($ocr_content)) {
            // Legacy format: single OCR string - store as page 1
            ocr_store_page($conn, 'tracking', $tracking_id, 1, $ocr_content);
        }
        
        // Success
        $doc_result['success'] = true;
        $doc_result['message'] = 'Uploaded successfully';
        $doc_result['tracking_id'] = $tracking_id;
        $doc_result['document_hash'] = $doc_hash;
        $doc_result['mobile_timestamp'] = $mobile_timestamp;
        $doc_result['file_path'] = $final_path;
        
        $results[] = $doc_result;
        $success_count++;
    }
    
    json_response([
        'success' => $error_count === 0,
        'message' => "Processed $file_count documents: $success_count succeeded, $error_count failed",
        'batch_id' => $batch_id,
        'total' => $file_count,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'documents' => $results,
    ]);
}

/**
 * Attach additional documents to an existing tracking record
 */
function handle_attach_documents($conn, $parent_tracking_id, $sender_name, $sender_department) {
    if ($parent_tracking_id === '' || !is_numeric($parent_tracking_id)) {
        json_error('parent_tracking_id is required');
    }
    
    $parent_id = (int)$parent_tracking_id;
    
    // Verify parent exists
    $stmt = $conn->prepare("SELECT id, batch_id, end_location, current_holder FROM tracking WHERE id = ?");
    $stmt->bind_param('i', $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $parent = $result->fetch_assoc();
    $stmt->close();
    
    if (!$parent) {
        json_error('Parent tracking document not found');
    }
    
    // Use parent's batch_id or create new one
    $batch_id = $parent['batch_id'] ?? generate_batch_id();
    
    // Delegate to batch upload with parent context
    handle_batch_upload(
        $conn, 
        $batch_id, 
        $sender_name, 
        $sender_department,
        '', // receiver_username
        $parent['current_holder'] ?? $sender_department,
        'Attachment',
        $parent['end_location'] ?? '',
        $parent_tracking_id
    );
}

/**
 * Get status of a batch upload
 */
function handle_batch_status($conn, $batch_id) {
    if ($batch_id === '') {
        json_error('batch_id is required');
    }
    
    $stmt = $conn->prepare("
        SELECT id, type, employee_name, status, current_holder, end_location, 
               file_path, doc_hash, page_number, parent_tracking_id, created_at
        FROM tracking 
        WHERE batch_id = ?
        ORDER BY page_number ASC
    ");
    $stmt->bind_param('s', $batch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
    
    json_response([
        'success' => true,
        'batch_id' => $batch_id,
        'document_count' => count($documents),
        'documents' => $documents,
    ]);
}

/**
 * Finalize batch and prepare for archive
 * This ensures all documents maintain integrity when moving to final department
 */
function handle_finalize_batch($conn, $batch_id, $final_department) {
    if ($batch_id === '') {
        json_error('batch_id is required');
    }
    
    // Update all documents in batch to point to final department
    $status = ($final_department !== '') ? 'Completed' : 'Finalized';
    
    $stmt = $conn->prepare("
        UPDATE tracking 
        SET status = ?, 
            current_holder = COALESCE(NULLIF(?, ''), current_holder)
        WHERE batch_id = ?
    ");
    $stmt->bind_param('sss', $status, $final_department, $batch_id);
    
    if (!$stmt->execute()) {
        json_error('Failed to finalize batch: ' . $stmt->error);
    }
    
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    // Log finalization to document_history for each document
    $sel = $conn->prepare("SELECT id FROM tracking WHERE batch_id = ?");
    $sel->bind_param('s', $batch_id);
    $sel->execute();
    $result = $sel->get_result();
    
    while ($row = $result->fetch_assoc()) {
        log_document_action($conn, $row['id'], 'finalize', 'System', 
                           'Pending', $status, null, $final_department);
    }
    $sel->close();
    
    json_response([
        'success' => true,
        'message' => "Finalized $affected documents in batch",
        'batch_id' => $batch_id,
        'documents_finalized' => $affected,
        'final_status' => $status,
    ]);
}

/**
 * Log action to document_history table
 */
function log_document_action($conn, $doc_id, $action, $actor_name, 
                             $from_status, $to_status, $from_holder, $to_holder) {
    // Resolve actor user ID if possible
    $actor_id = 0;
    $stmt = $conn->prepare("SELECT id FROM control WHERE user = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $actor_name);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $actor_id = (int)$row['id'];
            }
        }
        $stmt->close();
    }
    
    $hist = $conn->prepare("
        INSERT INTO document_history 
        (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    if ($hist) {
        $hist->bind_param('isissss', $doc_id, $action, $actor_id, 
                         $from_status, $to_status, $from_holder, $to_holder);
        $hist->execute();
        $hist->close();
    }
}

/**
 * Get file icon based on extension
 */
function get_file_icon($ext) {
    $icons = [
        'pdf' => 'pdf',
        'doc' => 'doc',
        'docx' => 'doc',
        'xls' => 'xls',
        'xlsx' => 'xls',
        'png' => 'png',
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'gif' => 'gif',
        'txt' => 'txt',
    ];
    return $icons[$ext] ?? 'file';
}

/**
 * Format file size for display
 */
function format_file_size($bytes) {
    if ($bytes < 1024) {
        return $bytes . 'B';
    } elseif ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . 'KB';
    } else {
        return number_format($bytes / (1024 * 1024), 1) . 'MB';
    }
}

/**
 * Human-readable upload error message
 */
function upload_error_message($code) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
        UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
    ];
    return $messages[$code] ?? 'Unknown error';
}
