<?php
/**
 * Document Actions API
 * Handles: Return, Comment, Attach actions for documents
 */

// Ensure we always respond with valid JSON (avoid HTML warnings like <br />)
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendJson($data) {
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($data);
    exit();
}

set_exception_handler(function ($e) {
    sendJson(['success' => false, 'error' => 'Server exception', 'details' => $e->getMessage()]);
});

set_error_handler(function ($severity, $message, $file, $line) {
    sendJson(['success' => false, 'error' => 'Server error', 'details' => $message, 'line' => $line]);
});

require_once '../config.php';
require_once __DIR__ . '/ocr_search_helper.php';
require_once '../firestore_client.php';

$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
    http_response_code(500);
    sendJson(['success' => false, 'error' => 'Database connection failed']);
}

// Ensure required tables exist
function ensureTablesExist($connection) {
    // Document comments table
    $connection->query("
        CREATE TABLE IF NOT EXISTS document_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(255),
            department VARCHAR(255),
            comment TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tracking_id (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Document attachments table
    $connection->query("
        CREATE TABLE IF NOT EXISTS document_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id INT NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(50),
            file_size INT DEFAULT 0,
            uploaded_by VARCHAR(255),
            department VARCHAR(255),
            remarks TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tracking_id (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Document versions table — stores previous versions of the main document
    $connection->query("
        CREATE TABLE IF NOT EXISTS document_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id INT NOT NULL,
            version_number INT NOT NULL DEFAULT 1,
            file_path VARCHAR(500) NOT NULL,
            file_size INT DEFAULT 0,
            uploaded_by VARCHAR(255),
            department VARCHAR(255),
            version_type ENUM('original','returned') NOT NULL DEFAULT 'original',
            ocr_content TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tracking_id (tracking_id),
            INDEX idx_version (tracking_id, version_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add return columns to tracking if not exist
    $cols = ['remarks', 'return_reason', 'returned_by', 'returned_at', 'returned_to_department'];
    foreach ($cols as $col) {
        $check = $connection->query("SHOW COLUMNS FROM tracking LIKE '$col'");
        if ($check && $check->num_rows === 0) {
            if ($col === 'returned_at') {
                $connection->query("ALTER TABLE tracking ADD COLUMN $col DATETIME DEFAULT NULL");
            } else {
                $connection->query("ALTER TABLE tracking ADD COLUMN $col TEXT DEFAULT NULL");
            }
        }
    }

    // Ensure document_history has columns expected by the app (best-effort)
    try {
        if ($res = $connection->query("SHOW TABLES LIKE 'document_history'")) {
            $has = ($res->num_rows > 0);
            $res->free();
            if ($has) {
                $need = [];
                $c = $connection->query("SHOW COLUMNS FROM document_history LIKE 'notes'");
                if ($c && $c->num_rows === 0) { $need[] = "ADD COLUMN notes TEXT NULL"; }
                if ($c) { $c->free(); }
                $c = $connection->query("SHOW COLUMNS FROM document_history LIKE 'created_at'");
                if ($c && $c->num_rows === 0) { $need[] = "ADD COLUMN created_at DATETIME NULL"; }
                if ($c) { $c->free(); }

                if (!empty($need)) {
                    $connection->query("ALTER TABLE document_history " . implode(', ', $need));
                }

                // If created_at exists but is NULL for some rows, let queries still sort deterministically.
                // Do not overwrite existing values.
                $connection->query("UPDATE document_history SET created_at = NOW() WHERE created_at IS NULL");
            }
        }
    } catch (Throwable $_) {
        // best-effort only
    }
}

ensureTablesExist($connection);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============ RETURN DOCUMENT ============
if ($action === 'return_document') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $returnedBy = trim($_POST['returned_by'] ?? '');
    $returnedByDept = trim($_POST['returned_by_department'] ?? '');
    $returnToDept = trim($_POST['return_to_department'] ?? '');

    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    // Get current document info
    // NOTE: tracking table commonly uses `type` (not `document_type`)
    $stmt = $connection->prepare("SELECT id, current_holder, type, status, end_location, file_path, mobile_timestamp FROM tracking WHERE id = ?");
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Prepare failed', 'details' => $connection->error]);
    }
    $stmt->bind_param('i', $trackingId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        sendJson(['success' => false, 'error' => 'Execute failed', 'details' => $err]);
    }
    $result = $stmt->get_result();
    $doc = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$doc) {
        sendJson(['success' => false, 'error' => 'Document not found']);
    }

    $previousHolder = $doc['current_holder'] ?? '';
    $docType = $doc['type'] ?? 'Document';
    $endLocation = $doc['end_location'] ?? '';
    $filePath = $doc['file_path'] ?? '';

    // Update tracking record
    $sql = "UPDATE tracking SET 
            return_reason = ?,
            remarks = CONCAT(COALESCE(remarks, ''), '\n[RETURNED] ', NOW(), ' by ', ?, ': ', ?),
            returned_by = ?,
            returned_at = NOW(),
            returned_to_department = ?,
            current_holder = ?,
            status = 'Returned'
            WHERE id = ?";
    
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Prepare failed', 'details' => $connection->error]);
    }
    $stmt->bind_param('ssssssi', $reason, $returnedBy, $reason, $returnedBy, $returnToDept, $returnToDept, $trackingId);
    $ok = $stmt->execute();
    $stmtErr = $stmt->error;
    $stmt->close();

    if (!$ok) {
        sendJson(['success' => false, 'error' => 'Failed to update document', 'details' => $stmtErr]);
    }

    // Log to document_history
    $histSql = "INSERT INTO document_history (doc_id, action, from_holder, to_holder, from_status, to_status, notes, created_at)
                VALUES (?, 'return', ?, ?, ?, 'Returned', ?, NOW())";
    $histStmt = $connection->prepare($histSql);
    if (!$histStmt) {
        sendJson(['success' => false, 'error' => 'Prepare failed', 'details' => $connection->error]);
    }
    $prevStatus = $doc['status'] ?? 'In Review';
    $histStmt->bind_param('issss', $trackingId, $previousHolder, $returnToDept, $prevStatus, $reason);
    if (!$histStmt->execute()) {
        $err = $histStmt->error;
        $histStmt->close();
        sendJson(['success' => false, 'error' => 'Failed to log history', 'details' => $err]);
    }
    $histStmt->close();

    // Remove/complete any existing notification for the returning department/user so it no longer shows.
    try {
        // best effort: notifications table may not have all columns in older installs
        if ($stmtN = $connection->prepare("UPDATE notifications SET status = 'completed' WHERE tracking_id = ? AND (LOWER(TRIM(recipient_department)) = LOWER(TRIM(?)) OR LOWER(TRIM(recipient_username)) = LOWER(TRIM(?)))")) {
            $rb = $returnedBy;
            $rbd = $returnedByDept;
            $stmtN->bind_param('iss', $trackingId, $rbd, $rb);
            $stmtN->execute();
            $stmtN->close();
        }
        if ($stmtD = $connection->prepare("DELETE FROM notifications WHERE tracking_id = ? AND status = 'completed' AND (LOWER(TRIM(recipient_department)) = LOWER(TRIM(?)) OR LOWER(TRIM(recipient_username)) = LOWER(TRIM(?)))")) {
            $rb = $returnedBy;
            $rbd = $returnedByDept;
            $stmtD->bind_param('iss', $trackingId, $rbd, $rb);
            $stmtD->execute();
            $stmtD->close();
        }

        // Fallback cleanup for older notifications missing tracking_id
        $mt = trim((string)($doc['mobile_timestamp'] ?? ''));
        $fp = trim((string)($doc['file_path'] ?? ''));
        if ($mt !== '' || $fp !== '') {
            if ($stmtF = $connection->prepare("DELETE FROM notifications WHERE (tracking_id IS NULL OR tracking_id = 0) AND ( (mobile_timestamp <> '' AND mobile_timestamp = ?) OR (file_url <> '' AND file_url = ?) ) AND (LOWER(TRIM(recipient_department)) = LOWER(TRIM(?)) OR LOWER(TRIM(recipient_username)) = LOWER(TRIM(?)))")) {
                $rb = $returnedBy;
                $rbd = $returnedByDept;
                $stmtF->bind_param('ssss', $mt, $fp, $rbd, $rb);
                $stmtF->execute();
                $stmtF->close();
            }
        }
    } catch (Throwable $t) {
        // ignore
    }

    // Notify receiving department so it appears on their dashboard
    try {
        $notifyUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') .
            $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/notifications.php';
        $payload = [
            'action' => 'create',
            'title' => $docType,
            'content' => $docType . ' • ' . $returnedBy,
            'type' => 'return',
            'recipient_department' => $returnToDept,
            'sender_username' => $returnedBy,
            'department' => $returnedByDept,
            'file_url' => $filePath,
            'tracking_id' => $trackingId,
            'end_location' => $endLocation,
            'current_holder' => $returnToDept,
            'doc_status' => 'Returned',
        ];
        $ch = curl_init($notifyUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $t) {
        // best-effort only
    }

    sendJson([
        'success' => true,
        'message' => 'Document returned successfully',
        'current_holder' => $returnToDept,
        'status' => 'Returned'
    ]);
}

// ============ ADD COMMENT ============
if ($action === 'add_comment') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }
    if ($comment === '') {
        sendJson(['success' => false, 'error' => 'Comment cannot be empty']);
    }

    $cols = [];
    try {
        if ($colRes = $connection->query("SHOW COLUMNS FROM document_comments")) {
            while ($c = $colRes->fetch_assoc()) {
                $cols[] = (string)($c['Field'] ?? '');
            }
            $colRes->free();
        }
    } catch (Throwable $_) {
    }
    $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
    $hasCol = function ($name) use ($colsLower) {
        return in_array(strtolower($name), $colsLower, true);
    };
    $trackingCol = $hasCol('tracking_id') ? 'tracking_id' : ($hasCol('doc_id') ? 'doc_id' : ($hasCol('trackingid') ? 'trackingId' : ($hasCol('docid') ? 'docId' : 'tracking_id')));
    $userIdCol = $hasCol('user_id') ? 'user_id' : ($hasCol('userid') ? 'userId' : 'user_id');
    $userCol = $hasCol('username') ? 'username' : ($hasCol('user') ? 'user' : 'username');
    $deptCol = $hasCol('department') ? 'department' : ($hasCol('dept') ? 'dept' : 'department');
    $commentCol = $hasCol('comment') ? 'comment' : ($hasCol('remarks') ? 'remarks' : 'comment');

    $stmt = $connection->prepare("INSERT INTO document_comments ({$trackingCol}, {$userIdCol}, {$userCol}, {$deptCol}, {$commentCol}) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisss', $trackingId, $userId, $username, $department, $comment);
    $ok = $stmt->execute();
    $insertId = $connection->insert_id;
    $stmt->close();

    if (!$ok) {
        sendJson(['success' => false, 'error' => 'Failed to add comment']);
    }

    // Also append to tracking remarks
    $remarkUpdate = $connection->prepare("UPDATE tracking SET remarks = CONCAT(COALESCE(remarks, ''), '\n[COMMENT] ', NOW(), ' by ', ?, ': ', ?) WHERE id = ?");
    $remarkUpdate->bind_param('ssi', $username, $comment, $trackingId);
    $remarkUpdate->execute();
    $remarkUpdate->close();

    sendJson(['success' => true, 'comment_id' => $insertId, 'message' => 'Comment added successfully']);
}

// ============ GET COMMENTS ============
if ($action === 'get_comments') {
    $trackingId = (int)($_GET['tracking_id'] ?? 0);

    // By default, return only explicit user comments.
    // Legacy "web" notes from history/remarks can be included explicitly via query params.
    $includeHistory = (int)($_GET['include_history'] ?? 0) === 1;
    $includeRemarks = (int)($_GET['include_remarks'] ?? 0) === 1;

    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    $cols = [];
    try {
        if ($colRes = $connection->query("SHOW COLUMNS FROM document_comments")) {
            while ($c = $colRes->fetch_assoc()) {
                $cols[] = (string)($c['Field'] ?? '');
            }
            $colRes->free();
        }
    } catch (Throwable $_) {
    }
    $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
    $hasCol = function ($name) use ($colsLower) {
        return in_array(strtolower($name), $colsLower, true);
    };
    $trackingCol = $hasCol('tracking_id') ? 'tracking_id' : ($hasCol('doc_id') ? 'doc_id' : ($hasCol('trackingid') ? 'trackingId' : ($hasCol('docid') ? 'docId' : 'tracking_id')));

    $selectCols = [];
    if ($hasCol('id')) $selectCols[] = 'id';
    if ($hasCol('user_id')) $selectCols[] = 'user_id';
    if (!$hasCol('user_id') && $hasCol('userid')) $selectCols[] = 'userId AS user_id';
    if ($hasCol('username')) $selectCols[] = 'username';
    if (!$hasCol('username') && $hasCol('user')) $selectCols[] = 'user AS username';
    if ($hasCol('department')) $selectCols[] = 'department';
    if (!$hasCol('department') && $hasCol('dept')) $selectCols[] = 'dept AS department';
    if ($hasCol('comment')) $selectCols[] = 'comment';
    if (!$hasCol('comment') && $hasCol('remarks')) $selectCols[] = 'remarks AS comment';
    if ($hasCol('created_at')) $selectCols[] = 'created_at';
    $sel = !empty($selectCols) ? implode(', ', $selectCols) : '*';
    $order = $hasCol('created_at') ? 'created_at' : 'id';

    $stmt = $connection->prepare("SELECT {$sel} FROM document_comments WHERE {$trackingCol} = ? ORDER BY {$order} DESC");
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $comments = [];
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }
    $stmt->close();

    // Optional: include history notes (e.g., return reason / web remarks)
    if ($includeHistory) try {
        $hcols = [];
        if ($colRes = $connection->query("SHOW COLUMNS FROM document_history")) {
            while ($c = $colRes->fetch_assoc()) {
                $hcols[] = (string)($c['Field'] ?? '');
            }
            $colRes->free();
        }
        $hLower = array_map(function ($v) { return strtolower($v); }, $hcols);
        $hHas = function ($name) use ($hLower) {
            return in_array(strtolower($name), $hLower, true);
        };
        if ($hHas('doc_id') && ($hHas('notes') || $hHas('note'))) {
            $notesCol = $hHas('notes') ? 'notes' : 'note';
            $createdCol = $hHas('created_at') ? 'created_at' : 'id';
            $actionCol = $hHas('action') ? 'action' : null;

            $sel = "{$notesCol} AS notes";
            if ($actionCol !== null) { $sel .= ", {$actionCol} AS action"; }
            if ($hHas('created_at')) { $sel .= ", created_at"; }
            $sql = "SELECT {$sel} FROM document_history WHERE doc_id = ? AND {$notesCol} IS NOT NULL AND TRIM({$notesCol}) <> '' ORDER BY {$createdCol} DESC LIMIT 100";
            $hs = $connection->prepare($sql);
            if ($hs) {
                $hs->bind_param('i', $trackingId);
                if ($hs->execute()) {
                    $res = $hs->get_result();
                    while ($res && ($r = $res->fetch_assoc())) {
                        $note = trim((string)($r['notes'] ?? ''));
                        if ($note === '') continue;
                        $act = isset($r['action']) ? trim((string)$r['action']) : '';
                        $msg = ($act !== '' ? ('[' . strtoupper($act) . '] ' . $note) : $note);
                        $comments[] = [
                            'id' => 0,
                            'user_id' => 0,
                            'username' => 'web',
                            'department' => '',
                            'comment' => $msg,
                            'created_at' => (string)($r['created_at'] ?? ''),
                        ];
                    }
                    if ($res) { $res->free(); }
                }
                $hs->close();
            }
        }
    } catch (Throwable $_) {
        // best-effort only
    }

    // Optional: include tracking.remarks (legacy web notes) when present
    if ($includeRemarks) try {
        $t = $connection->prepare("SELECT remarks FROM tracking WHERE id = ? LIMIT 1");
        if ($t) {
            $t->bind_param('i', $trackingId);
            if ($t->execute()) {
                $res = $t->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($res) { $res->free(); }
                $remarks = $row ? trim((string)($row['remarks'] ?? '')) : '';
                if ($remarks !== '') {
                    $comments[] = [
                        'id' => 0,
                        'user_id' => 0,
                        'username' => 'web',
                        'department' => '',
                        'comment' => '[REMARKS] ' . $remarks,
                        'created_at' => '',
                    ];
                }
            }
            $t->close();
        }
    } catch (Throwable $_) {
        // best-effort only
    }

    sendJson(['success' => true, 'comments' => $comments]);
}

// ============ EDIT COMMENT ============
if ($action === 'edit_comment') {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    $newComment = trim($_POST['comment'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($commentId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid comment_id']);
    }
    if ($newComment === '') {
        sendJson(['success' => false, 'error' => 'Comment cannot be empty']);
    }

    // Verify the comment exists and optionally check ownership
    $stmt = $connection->prepare("SELECT id, username, department FROM document_comments WHERE id = ?");
    $stmt->bind_param('i', $commentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        sendJson(['success' => false, 'error' => 'Comment not found']);
    }

    // Update the comment
    $cols = [];
    try {
        if ($colRes = $connection->query("SHOW COLUMNS FROM document_comments")) {
            while ($c = $colRes->fetch_assoc()) {
                $cols[] = (string)($c['Field'] ?? '');
            }
            $colRes->free();
        }
    } catch (Throwable $_) {
    }
    $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
    $hasCol = function ($name) use ($colsLower) {
        return in_array(strtolower($name), $colsLower, true);
    };
    $commentCol = $hasCol('comment') ? 'comment' : ($hasCol('remarks') ? 'remarks' : 'comment');
    $stmt = $connection->prepare("UPDATE document_comments SET {$commentCol} = ? WHERE id = ?");
    $stmt->bind_param('si', $newComment, $commentId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        sendJson(['success' => false, 'error' => 'Failed to update comment']);
    }

    sendJson(['success' => true, 'message' => 'Comment updated successfully']);
}

// ============ DELETE COMMENT ============
if ($action === 'delete_comment') {
    $commentId = (int)($_POST['comment_id'] ?? ($_GET['comment_id'] ?? 0));
    $username = trim($_POST['username'] ?? ($_GET['username'] ?? ''));
    $department = trim($_POST['department'] ?? ($_GET['department'] ?? ''));

    if ($commentId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid comment_id']);
    }

    // Verify the comment exists
    $stmt = $connection->prepare("SELECT id FROM document_comments WHERE id = ?");
    $stmt->bind_param('i', $commentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        sendJson(['success' => false, 'error' => 'Comment not found']);
    }

    // Delete the comment
    $stmt = $connection->prepare("DELETE FROM document_comments WHERE id = ?");
    $stmt->bind_param('i', $commentId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        sendJson(['success' => false, 'error' => 'Failed to delete comment']);
    }

    sendJson(['success' => true, 'message' => 'Comment deleted successfully']);
}

// ============ ADD ATTACHMENT ============
if ($action === 'add_attachment') {
    $debugAttach = (int)($_GET['debug'] ?? $_POST['debug'] ?? 0) === 1;
    $dbg = [
        'phase' => 'start',
        'tracking_id' => (int)($_POST['tracking_id'] ?? 0),
        'has_files' => isset($_FILES) && is_array($_FILES),
        'files_keys' => isset($_FILES) && is_array($_FILES) ? array_keys($_FILES) : [],
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
    ];

    try {
        $trackingId = (int)($_POST['tracking_id'] ?? 0);
        $uploadedBy = trim($_POST['uploaded_by'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $ocrText = trim($_POST['ocr_text'] ?? '');

        if ($trackingId <= 0) {
            $out = ['success' => false, 'error' => 'Invalid tracking_id'];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }

        if (!isset($_FILES['file'])) {
            $out = ['success' => false, 'error' => 'No file uploaded (missing field: file)'];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }
        if (!isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : -1;
            $dbg['phase'] = 'upload_error';
            $dbg['upload_error_code'] = $errCode;
            $dbg['upload_error_message'] = $errCode;
            $out = ['success' => false, 'error' => 'Upload error', 'code' => $errCode];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }

        $file = $_FILES['file'];
        $fileName = trim((string)($file['name'] ?? ''));
        $fileName = $fileName !== '' ? basename($fileName) : '';
        $fileType = trim((string)($file['type'] ?? ''));
        $fileSize = (int)($file['size'] ?? 0);
        $tmpName = (string)($file['tmp_name'] ?? '');

        // ============ ENFORCE 20MB MAX FILE SIZE ============
        $maxFileSizeBytes = 20 * 1024 * 1024; // 20MB
        if ($fileSize > $maxFileSizeBytes) {
            $dbg['phase'] = 'file_too_large';
            $dbg['file_size'] = $fileSize;
            $dbg['max_size'] = $maxFileSizeBytes;
            $out = ['success' => false, 'error' => 'File size exceeds 20MB limit', 'size' => $fileSize, 'max' => $maxFileSizeBytes];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }

        $dbg['phase'] = 'file_received';
        $dbg['file'] = [
            'name' => $fileName,
            'type' => $fileType,
            'size' => $fileSize,
            'tmp_name' => $tmpName,
            'is_uploaded_file' => ($tmpName !== '' ? (is_uploaded_file($tmpName) ? 1 : 0) : 0),
        ];

        // Create upload directory
        $uploadDir = __DIR__ . '/../uploads/attachments/' . $trackingId . '/';
        $dbg['upload_dir'] = $uploadDir;
        if (!is_dir($uploadDir)) {
            $mkOk = @mkdir($uploadDir, 0755, true);
            $dbg['mkdir_ok'] = $mkOk ? 1 : 0;
            if (!$mkOk && !is_dir($uploadDir)) {
                $out = ['success' => false, 'error' => 'Failed to create upload directory'];
                if ($debugAttach) $out['debug'] = $dbg;
                sendJson($out);
            }
        }

        // Generate unique filename
        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];
            if ($fileType !== '' && isset($mimeMap[$fileType])) {
                $ext = $mimeMap[$fileType];
            } else {
                $ext = 'bin';
            }
        }
        if ($fileName === '') {
            $fileName = 'attachment.' . $ext;
        }

        $uniqueName = 'attach_' . time() . '_' . uniqid() . '.' . $ext;
        $filePath = $uploadDir . $uniqueName;
        $relativePath = 'uploads/attachments/' . $trackingId . '/' . $uniqueName;
        $dbg['phase'] = 'move_upload';
        $dbg['dest_path'] = $filePath;
        $dbg['relative_path'] = $relativePath;

        if (!move_uploaded_file($tmpName, $filePath)) {
            $dbg['move_ok'] = 0;
            $out = ['success' => false, 'error' => 'Failed to save file'];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }
        $dbg['move_ok'] = 1;

        // ============ IMAGE COMPRESSION (Option A) ============
        // Compress images to reduce storage: resize to max 1920px, JPEG quality 80%
        $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
        if (in_array($ext, $imageExts, true) && function_exists('imagecreatefromjpeg')) {
            $dbg['phase'] = 'image_compression';
            $originalSize = filesize($filePath);
            $dbg['original_size'] = $originalSize;
            
            $maxDimension = 1920;
            $jpegQuality = 80;
            $compressed = false;
            
            try {
                $imageInfo = @getimagesize($filePath);
                if ($imageInfo !== false) {
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];
                    $mime = $imageInfo['mime'] ?? '';
                    $dbg['original_dimensions'] = ['width' => $width, 'height' => $height];
                    
                    // Load image based on type
                    $srcImage = null;
                    switch ($mime) {
                        case 'image/jpeg':
                            $srcImage = @imagecreatefromjpeg($filePath);
                            break;
                        case 'image/png':
                            $srcImage = @imagecreatefrompng($filePath);
                            break;
                        case 'image/gif':
                            $srcImage = @imagecreatefromgif($filePath);
                            break;
                        case 'image/webp':
                            if (function_exists('imagecreatefromwebp')) {
                                $srcImage = @imagecreatefromwebp($filePath);
                            }
                            break;
                        case 'image/bmp':
                            if (function_exists('imagecreatefrombmp')) {
                                $srcImage = @imagecreatefrombmp($filePath);
                            }
                            break;
                    }
                    
                    if ($srcImage !== null && $srcImage !== false) {
                        // Calculate new dimensions if needed
                        $newWidth = $width;
                        $newHeight = $height;
                        $needsResize = ($width > $maxDimension || $height > $maxDimension);
                        
                        if ($needsResize) {
                            if ($width > $height) {
                                $newWidth = $maxDimension;
                                $newHeight = (int)round($height * ($maxDimension / $width));
                            } else {
                                $newHeight = $maxDimension;
                                $newWidth = (int)round($width * ($maxDimension / $height));
                            }
                            $dbg['new_dimensions'] = ['width' => $newWidth, 'height' => $newHeight];
                        }
                        
                        // Create resized image or use original
                        if ($needsResize) {
                            $dstImage = imagecreatetruecolor($newWidth, $newHeight);
                            // Preserve transparency for PNG
                            if ($mime === 'image/png') {
                                imagealphablending($dstImage, false);
                                imagesavealpha($dstImage, true);
                                $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
                                imagefill($dstImage, 0, 0, $transparent);
                            }
                            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                            imagedestroy($srcImage);
                        } else {
                            $dstImage = $srcImage;
                        }
                        
                        // Save as JPEG for best compression (except PNG with transparency)
                        $compressedPath = $filePath;
                        if ($mime !== 'image/png') {
                            // Convert to JPEG
                            $jpgName = preg_replace('/\.[^.]+$/', '.jpg', $uniqueName);
                            $compressedPath = $uploadDir . $jpgName;
                            $relativePath = 'uploads/attachments/' . $trackingId . '/' . $jpgName;
                            imagejpeg($dstImage, $compressedPath, $jpegQuality);
                            // Remove original if different
                            if ($compressedPath !== $filePath && file_exists($filePath)) {
                                @unlink($filePath);
                            }
                            $filePath = $compressedPath;
                            $ext = 'jpg';
                            $compressed = true;
                        } else {
                            // Keep PNG but optimize
                            imagepng($dstImage, $filePath, 6); // compression level 6
                            $compressed = true;
                        }
                        
                        imagedestroy($dstImage);
                        
                        $newSize = filesize($filePath);
                        $dbg['compressed_size'] = $newSize;
                        $dbg['compression_ratio'] = $originalSize > 0 ? round((1 - $newSize / $originalSize) * 100, 1) . '%' : '0%';
                        $dbg['relative_path'] = $relativePath;
                        
                        // Update fileSize for DB
                        $fileSize = $newSize;
                    }
                }
            } catch (Throwable $compErr) {
                $dbg['compression_error'] = $compErr->getMessage();
                // Continue with original file if compression fails
            }
            
            $dbg['compressed'] = $compressed ? 1 : 0;
        }

        // Different deployments have different column names. Detect actual schema and map.
        $cols = [];
        try {
            if ($colRes = $connection->query("SHOW COLUMNS FROM document_attachments")) {
                while ($c = $colRes->fetch_assoc()) {
                    $cols[] = (string)($c['Field'] ?? '');
                }
                $colRes->free();
            }
        } catch (Throwable $_) {
        }
        $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
        $hasCol = function ($name) use ($colsLower) {
            return in_array(strtolower($name), $colsLower, true);
        };

        // If this deployment uses a legacy/lean document_attachments schema, add the metadata columns we need.
        // (Best-effort: if ALTER fails, we will try to fall back to existing columns.)
        $needAdd = [];
        if (!$hasCol('file_path') && !$hasCol('filepath') && !$hasCol('filePath') && !$hasCol('path')) {
            $needAdd[] = "ADD COLUMN file_path VARCHAR(500) NULL";
        }
        if (!$hasCol('file_name') && !$hasCol('filename') && !$hasCol('fileName') && !$hasCol('name')) {
            $needAdd[] = "ADD COLUMN file_name VARCHAR(255) NULL";
        }
        if (!$hasCol('file_type') && !$hasCol('filetype') && !$hasCol('fileType') && !$hasCol('mime_type') && !$hasCol('mimeType')) {
            $needAdd[] = "ADD COLUMN file_type VARCHAR(100) NULL";
        }
        if (!$hasCol('file_size') && !$hasCol('filesize') && !$hasCol('fileSize') && !$hasCol('size')) {
            $needAdd[] = "ADD COLUMN file_size INT NULL";
        }
        if (!$hasCol('uploaded_by') && !$hasCol('uploadedby') && !$hasCol('uploadedBy') && !$hasCol('uploader')) {
            $needAdd[] = "ADD COLUMN uploaded_by VARCHAR(100) NULL";
        }
        if (!$hasCol('department') && !$hasCol('dept') && !$hasCol('uploaded_department')) {
            $needAdd[] = "ADD COLUMN department VARCHAR(100) NULL";
        }
        if (!$hasCol('remarks') && !$hasCol('comment') && !$hasCol('note')) {
            $needAdd[] = "ADD COLUMN remarks TEXT NULL";
        }

        if (!empty($needAdd)) {
            try {
                $dbg['phase'] = 'schema_alter_attempt';
                $dbg['alter_ops'] = $needAdd;
                $alterSql = "ALTER TABLE document_attachments " . implode(', ', $needAdd);
                $connection->query($alterSql);
            } catch (Throwable $t) {
                $dbg['phase'] = 'schema_alter_failed';
                $dbg['schema_alter_error'] = $t->getMessage();
            }

            // Re-read columns after attempt
            $cols = [];
            try {
                if ($colRes = $connection->query("SHOW COLUMNS FROM document_attachments")) {
                    while ($c = $colRes->fetch_assoc()) {
                        $cols[] = (string)($c['Field'] ?? '');
                    }
                    $colRes->free();
                }
            } catch (Throwable $_) {
            }
            $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
            $hasCol = function ($name) use ($colsLower) {
                return in_array(strtolower($name), $colsLower, true);
            };
        }

        $map = [];
        // tracking id column
        if ($hasCol('tracking_id')) $map['tracking'] = 'tracking_id';
        else if ($hasCol('trackingid')) $map['tracking'] = 'trackingId';

        // file path column
        if ($hasCol('file_path')) $map['file_path'] = 'file_path';
        else if ($hasCol('filepath')) $map['file_path'] = 'filePath';
        else if ($hasCol('path')) $map['file_path'] = 'path';
        else if ($hasCol('attachment_type')) $map['file_path'] = 'attachment_type';

        // file name column
        if ($hasCol('file_name')) $map['file_name'] = 'file_name';
        else if ($hasCol('filename')) $map['file_name'] = 'fileName';
        else if ($hasCol('name')) $map['file_name'] = 'name';

        // file type column
        if ($hasCol('file_type')) $map['file_type'] = 'file_type';
        else if ($hasCol('filetype')) $map['file_type'] = 'fileType';
        else if ($hasCol('mime_type')) $map['file_type'] = 'mime_type';
        else if ($hasCol('mimetype')) $map['file_type'] = 'mimeType';

        // file size column
        if ($hasCol('file_size')) $map['file_size'] = 'file_size';
        else if ($hasCol('filesize')) $map['file_size'] = 'fileSize';
        else if ($hasCol('size')) $map['file_size'] = 'size';

        // uploaded by column
        if ($hasCol('uploaded_by')) $map['uploaded_by'] = 'uploaded_by';
        else if ($hasCol('uploadedby')) $map['uploaded_by'] = 'uploadedBy';
        else if ($hasCol('uploader')) $map['uploaded_by'] = 'uploader';

        // department column
        if ($hasCol('department')) $map['department'] = 'department';
        else if ($hasCol('dept')) $map['department'] = 'dept';
        else if ($hasCol('uploaded_department')) $map['department'] = 'uploaded_department';

        // remarks column
        if ($hasCol('remarks')) $map['remarks'] = 'remarks';
        else if ($hasCol('comment')) $map['remarks'] = 'comment';
        else if ($hasCol('note')) $map['remarks'] = 'note';

        if ($hasCol('parent_tracking_id')) $map['parent_tracking_id'] = 'parent_tracking_id';
        if ($hasCol('child_tracking_id')) $map['child_tracking_id'] = 'child_tracking_id';
        if ($hasCol('page_order')) $map['page_order'] = 'page_order';
        if ($hasCol('attachment_type')) $map['attachment_type'] = 'attachment_type';

        $dbg['attachment_columns'] = $cols;
        $dbg['attachment_column_map'] = $map;

        $required = ['tracking', 'file_path', 'file_name'];
        foreach ($required as $k) {
            if (!isset($map[$k]) || $map[$k] === '') {
                $dbg['phase'] = 'schema_missing_required';
                $out = ['success' => false, 'error' => 'Attachment table schema mismatch', 'details' => 'Missing required column mapping: ' . $k];
                if ($debugAttach) $out['debug'] = $dbg;
                sendJson($out);
            }
        }

        // Build INSERT dynamically for the columns that exist.
        $insertCols = [];
        $values = [];
        $types = '';

        $insertCols[] = $map['tracking']; $values[] = $trackingId; $types .= 'i';
        $insertCols[] = $map['file_path']; $values[] = $relativePath; $types .= 's';
        $insertCols[] = $map['file_name']; $values[] = $fileName; $types .= 's';

        if (isset($map['parent_tracking_id'])) { $insertCols[] = $map['parent_tracking_id']; $values[] = $trackingId; $types .= 'i'; }
        if (isset($map['child_tracking_id'])) { $insertCols[] = $map['child_tracking_id']; $values[] = 0; $types .= 'i'; }
        if (isset($map['page_order'])) {
            $pageOrder = 1;
            $maxCol = isset($map['parent_tracking_id']) ? $map['parent_tracking_id'] : $map['tracking'];
            try {
                if ($selMax = $connection->prepare("SELECT COALESCE(MAX(page_order), 0) AS m FROM document_attachments WHERE {$maxCol} = ?")) {
                    $selMax->bind_param('i', $trackingId);
                    if ($selMax->execute()) {
                        $res = $selMax->get_result();
                        if ($row = $res->fetch_assoc()) {
                            $pageOrder = ((int)($row['m'] ?? 0)) + 1;
                        }
                    }
                    $selMax->close();
                }
            } catch (Throwable $_) {
            }
            $insertCols[] = $map['page_order']; $values[] = $pageOrder; $types .= 'i';
        }
        if (isset($map['attachment_type'])) { $insertCols[] = $map['attachment_type']; $values[] = 'attachment'; $types .= 's'; }

        if (isset($map['file_type'])) { $insertCols[] = $map['file_type']; $values[] = $fileType; $types .= 's'; }
        if (isset($map['file_size'])) { $insertCols[] = $map['file_size']; $values[] = $fileSize; $types .= 'i'; }
        if (isset($map['uploaded_by'])) { $insertCols[] = $map['uploaded_by']; $values[] = $uploadedBy; $types .= 's'; }
        if (isset($map['department'])) { $insertCols[] = $map['department']; $values[] = $department; $types .= 's'; }
        if (isset($map['remarks'])) { $insertCols[] = $map['remarks']; $values[] = $remarks; $types .= 's'; }

        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $colList = implode(',', $insertCols);

        $stmt = $connection->prepare("INSERT INTO document_attachments ({$colList}) VALUES ({$placeholders})");
        if (!$stmt) {
            $dbg['phase'] = 'prepare_failed';
            $dbg['db_error'] = $connection->error;
            $out = ['success' => false, 'error' => 'Failed to prepare attachment insert', 'details' => $connection->error];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }

        // Bind params dynamically
        $bindParams = [];
        $bindParams[] = $types;
        for ($i = 0; $i < count($values); $i++) {
            $bindParams[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $ok = $stmt->execute();
        $insertId = $connection->insert_id;
        $stmt->close();

        if (!$ok) {
            $dbg['phase'] = 'insert_failed';
            $dbg['db_error'] = $connection->error;
            $out = ['success' => false, 'error' => 'Failed to save attachment record'];
            if ($debugAttach) $out['debug'] = $dbg;
            sendJson($out);
        }

        // Do not write attachment activity into tracking.remarks.
        // Attachments should appear only in the attachments list and (optionally) OCR pages.

        // Best-effort: store OCR text for this attachment into ocr_pages so search keeps working.
        // We store it as a new page under the same tracking doc_id.
        if ($ocrText !== '') {
            try {
                ocr_ensure_pages_table($connection);
                $nextPage = 1;
                if ($selPg = $connection->prepare("SELECT COALESCE(MAX(page_number), 0) AS m FROM ocr_pages WHERE scope = 'tracking' AND doc_id = ?")) {
                    $selPg->bind_param('i', $trackingId);
                    if ($selPg->execute()) {
                        $resPg = $selPg->get_result();
                        if ($rowPg = $resPg->fetch_assoc()) {
                            $nextPage = ((int)($rowPg['m'] ?? 0)) + 1;
                        }
                        if ($resPg) { $resPg->free(); }
                    }
                    $selPg->close();
                }

                $stored = "[ATTACHMENT: {$fileName}]\n" . $ocrText;
                ocr_store_page($connection, 'tracking', $trackingId, $nextPage, $stored);
            } catch (Throwable $_) {
                // ignore (best-effort)
            }
        }

        $out = ['success' => true, 'attachment_id' => $insertId, 'file_path' => $relativePath, 'message' => 'Attachment added successfully'];
        if ($debugAttach) {
            $dbg['phase'] = 'success';
            $out['debug'] = $dbg;
        }
        sendJson($out);
    } catch (Throwable $t) {
        $dbg['phase'] = 'throwable';
        $dbg['throwable'] = $t->getMessage();
        $out = ['success' => false, 'error' => 'Server exception', 'details' => $t->getMessage()];
        if ($debugAttach) $out['debug'] = $dbg;
        sendJson($out);
    }
}

// ============ REPLACE ATTACHMENT ============
// Replaces the file contents for an existing document_attachments row (keeps same row id).
if ($action === 'replace_attachment') {
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $uploadedBy = trim($_POST['uploaded_by'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($attachmentId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid attachment_id']);
    }
    if (!isset($_FILES['file'])) {
        sendJson(['success' => false, 'error' => 'No file uploaded (missing field: file)']);
    }
    if (!isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : -1;
        sendJson(['success' => false, 'error' => 'Upload error', 'code' => $errCode]);
    }

    // Schema-flexible mapping
    $cols = [];
    try {
        if ($colRes = $connection->query("SHOW COLUMNS FROM document_attachments")) {
            while ($c = $colRes->fetch_assoc()) {
                $cols[] = (string)($c['Field'] ?? '');
            }
            $colRes->free();
        }
    } catch (Throwable $_) {
    }
    $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
    $hasCol = function ($name) use ($colsLower) {
        return in_array(strtolower($name), $colsLower, true);
    };

    // tracking id column
    $trackingCol = $hasCol('tracking_id') ? 'tracking_id' : ($hasCol('trackingid') ? 'trackingId' : 'tracking_id');
    // file path/name
    $filePathCol = $hasCol('file_path') ? 'file_path' : ($hasCol('filepath') ? 'filePath' : ($hasCol('path') ? 'path' : 'file_path'));
    $fileNameCol = $hasCol('file_name') ? 'file_name' : ($hasCol('filename') ? 'fileName' : ($hasCol('name') ? 'name' : 'file_name'));
    $fileTypeCol = $hasCol('file_type') ? 'file_type' : ($hasCol('filetype') ? 'fileType' : ($hasCol('mime_type') ? 'mime_type' : ($hasCol('mimetype') ? 'mimeType' : null)));
    $fileSizeCol = $hasCol('file_size') ? 'file_size' : ($hasCol('filesize') ? 'fileSize' : ($hasCol('size') ? 'size' : null));
    $uploadedByCol = $hasCol('uploaded_by') ? 'uploaded_by' : ($hasCol('uploadedby') ? 'uploadedBy' : ($hasCol('uploader') ? 'uploader' : null));
    $deptCol = $hasCol('department') ? 'department' : ($hasCol('dept') ? 'dept' : ($hasCol('uploaded_department') ? 'uploaded_department' : null));
    $remarksCol = $hasCol('remarks') ? 'remarks' : ($hasCol('comment') ? 'comment' : ($hasCol('note') ? 'note' : null));

    // Read existing attachment
    $sel = "id, {$trackingCol} AS tracking_id";
    if ($filePathCol !== null) $sel .= ", {$filePathCol} AS file_path";
    if ($fileNameCol !== null) $sel .= ", {$fileNameCol} AS file_name";
    $stmt = $connection->prepare("SELECT {$sel} FROM document_attachments WHERE id = ? LIMIT 1");
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Failed to prepare attachment lookup', 'details' => $connection->error]);
    }
    $stmt->bind_param('i', $attachmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    if ($res) { $res->free(); }
    $stmt->close();

    if (!$existing) {
        sendJson(['success' => false, 'error' => 'Attachment not found']);
    }
    $trackingId = (int)($existing['tracking_id'] ?? 0);
    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Attachment missing tracking id']);
    }

    $oldRel = trim((string)($existing['file_path'] ?? ''));
    $oldAbs = '';
    if ($oldRel !== '') {
        // Stored relative paths are usually like uploads/attachments/<id>/<file>
        if (stripos($oldRel, 'uploads/') === 0) {
            $oldAbs = __DIR__ . '/../../' . $oldRel;
        } else if ($oldRel[0] === '/') {
            $oldAbs = $_SERVER['DOCUMENT_ROOT'] . $oldRel;
        }
    }

    $file = $_FILES['file'];
    $origName = trim((string)($file['name'] ?? ''));
    $origName = $origName !== '' ? basename($origName) : '';
    $fileType = trim((string)($file['type'] ?? ''));
    $fileSize = (int)($file['size'] ?? 0);
    $tmpName = (string)($file['tmp_name'] ?? '');

    $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '') {
        if (stripos($fileType, 'pdf') !== false) $ext = 'pdf';
        else if (stripos($fileType, 'png') !== false) $ext = 'png';
        else $ext = 'jpg';
    }
    $uniqueName = 'attach_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

    $uploadDir = __DIR__ . '/../uploads/attachments/' . $trackingId . '/';
    if (!is_dir($uploadDir)) {
        $mkOk = @mkdir($uploadDir, 0755, true);
        if (!$mkOk && !is_dir($uploadDir)) {
            sendJson(['success' => false, 'error' => 'Failed to create upload directory']);
        }
    }
    $newAbs = $uploadDir . $uniqueName;
    $newRel = 'uploads/attachments/' . $trackingId . '/' . $uniqueName;

    if (!@move_uploaded_file($tmpName, $newAbs)) {
        sendJson(['success' => false, 'error' => 'Failed to move uploaded file']);
    }

    // Update DB row
    $set = [];
    $types = '';
    $vals = [];
    if ($filePathCol !== null) { $set[] = "{$filePathCol} = ?"; $types .= 's'; $vals[] = $newRel; }
    if ($fileNameCol !== null) { $set[] = "{$fileNameCol} = ?"; $types .= 's'; $vals[] = ($origName !== '' ? $origName : $uniqueName); }
    if ($fileTypeCol !== null) { $set[] = "{$fileTypeCol} = ?"; $types .= 's'; $vals[] = $fileType; }
    if ($fileSizeCol !== null) { $set[] = "{$fileSizeCol} = ?"; $types .= 'i'; $vals[] = $fileSize; }
    if ($uploadedByCol !== null) { $set[] = "{$uploadedByCol} = ?"; $types .= 's'; $vals[] = $uploadedBy; }
    if ($deptCol !== null) { $set[] = "{$deptCol} = ?"; $types .= 's'; $vals[] = $department; }
    if ($remarksCol !== null) { $set[] = "{$remarksCol} = ?"; $types .= 's'; $vals[] = $remarks; }

    if (empty($set)) {
        // We already stored the new file; still delete old one if possible.
        if ($oldAbs !== '' && is_file($oldAbs)) { @unlink($oldAbs); }
        sendJson(['success' => true, 'message' => 'Attachment replaced (no DB columns to update)', 'attachment_id' => $attachmentId, 'file_path' => $newRel]);
    }

    $sql = "UPDATE document_attachments SET " . implode(', ', $set) . " WHERE id = ?";
    $types .= 'i';
    $vals[] = $attachmentId;
    $upd = $connection->prepare($sql);
    if (!$upd) {
        sendJson(['success' => false, 'error' => 'Failed to prepare attachment update', 'details' => $connection->error]);
    }
    $bind = [];
    $bind[] = $types;
    for ($i = 0; $i < count($vals); $i++) {
        $bind[] = &$vals[$i];
    }
    call_user_func_array([$upd, 'bind_param'], $bind);
    $ok = $upd->execute();
    $err = $upd->error;
    $upd->close();

    if (!$ok) {
        sendJson(['success' => false, 'error' => 'Failed to update attachment record', 'details' => $err]);
    }

    // Delete old file after DB update succeeds
    if ($oldAbs !== '' && is_file($oldAbs)) {
        @unlink($oldAbs);
    }

    sendJson(['success' => true, 'message' => 'Attachment replaced successfully', 'attachment_id' => $attachmentId, 'file_path' => $newRel]);
}

// ============ GET ATTACHMENTS ============
if ($action === 'get_attachments') {
    $trackingId = (int)($_GET['tracking_id'] ?? 0);

    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    // Schema-flexible attachment listing
    $cols = [];
    try {
        if ($colRes = $connection->query("SHOW COLUMNS FROM document_attachments")) {
            while ($c = $colRes->fetch_assoc()) {
                $cols[] = (string)($c['Field'] ?? '');
            }
            $colRes->free();
        }
    } catch (Throwable $_) {
    }
    $colsLower = array_map(function ($v) { return strtolower($v); }, $cols);
    $hasCol = function ($name) use ($colsLower) {
        return in_array(strtolower($name), $colsLower, true);
    };

    $trackingCol = $hasCol('tracking_id') ? 'tracking_id' : ($hasCol('trackingid') ? 'trackingId' : null);
    if ($trackingCol === null) {
        sendJson(['success' => false, 'error' => 'Attachment table schema mismatch', 'details' => 'Missing tracking id column']);
    }

    $selectCols = [];
    if ($hasCol('id')) $selectCols[] = 'id';
    if ($hasCol('file_path')) $selectCols[] = 'file_path';
    if ($hasCol('file_name')) $selectCols[] = 'file_name';
    if ($hasCol('file_type')) $selectCols[] = 'file_type';
    if ($hasCol('file_size')) $selectCols[] = 'file_size';
    if ($hasCol('uploaded_by')) $selectCols[] = 'uploaded_by';
    if ($hasCol('department')) $selectCols[] = 'department';
    if ($hasCol('remarks')) $selectCols[] = 'remarks';
    if ($hasCol('created_at')) $selectCols[] = 'created_at';

    // Fallback: legacy schema may store path in attachment_type
    if (!$hasCol('file_path') && $hasCol('attachment_type')) {
        $selectCols[] = 'attachment_type AS file_path';
    }
    if (!$hasCol('file_name') && $hasCol('attachment_type')) {
        $selectCols[] = "'' AS file_name";
    }

    $sel = !empty($selectCols) ? implode(', ', $selectCols) : '*';
    $sql = "SELECT {$sel} FROM document_attachments WHERE {$trackingCol} = ? ORDER BY " . ($hasCol('created_at') ? 'created_at' : 'id') . " DESC";
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Failed to prepare attachments query', 'details' => $connection->error]);
    }
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $result = $stmt->get_result();

    $attachments = [];
    while ($row = $result->fetch_assoc()) {
        // Build a web-accessible URL for the attachment.
        // Attachments are stored under lib/OCR(UPDATED)/uploads/attachments/...
        // but older records may have relative paths like "uploads/attachments/...".
        $pathRaw = '';
        if (isset($row['file_path'])) $pathRaw = (string)$row['file_path'];
        else if (isset($row['filePath'])) $pathRaw = (string)$row['filePath'];
        else if (isset($row['path'])) $pathRaw = (string)$row['path'];

        $path = trim($pathRaw);
        $isHttp = (stripos($path, 'http://') === 0) || (stripos($path, 'https://') === 0);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // Compute application root from SCRIPT_NAME.
        // Example SCRIPT_NAME: /flutter_application_7/lib/OCR(UPDATED)/api/document_actions.php
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['REQUEST_URI'] ?? '');
        $appRootPath = preg_replace('#/lib/OCR\(UPDATED\)/api/.*$#', '', $scriptName);
        if ($appRootPath === null || $appRootPath === '') {
            $appRootPath = '';
        }
        $appBase = ($host !== '') ? ($scheme . $host . $appRootPath) : '';

        // NOTE: Upload directories are protected (no direct access). Always serve via download_attachment.php.
        // We'll still do a best-effort existence check to avoid returning broken links.
        $exists = false;
        if ($path !== '' && !$isHttp) {
            $cleanPath = ltrim($path, '/');
            if (stripos($cleanPath, 'lib/OCR(UPDATED)/') === 0) {
                $cleanPath = substr($cleanPath, strlen('lib/OCR(UPDATED)/'));
            }
            if (stripos($cleanPath, 'lib/') === 0) {
                $cleanPath = substr($cleanPath, strlen('lib/'));
            }

            if (stripos($cleanPath, 'uploads/') === 0) {
                $newLocation = __DIR__ . '/../' . $cleanPath;        // lib/OCR(UPDATED)/uploads/...
                $legacyLocation = __DIR__ . '/../../' . $cleanPath;  // lib/uploads/...
                $exists = file_exists($newLocation) || file_exists($legacyLocation);
            } else {
                // Unknown format; assume it exists to avoid hiding it
                $exists = true;
            }
        } else if ($path !== '' && $isHttp) {
            $exists = true;
        }

        $row['file_url'] = ($exists && isset($row['id']))
            ? ($appBase . '/lib/OCR(UPDATED)/download_attachment.php?id=' . urlencode((string)$row['id']) . '&inline=1&t=' . time())
            : '';
        $attachments[] = $row;
    }
    $stmt->close();

    sendJson(['success' => true, 'attachments' => $attachments]);
}

// ============ GET DOCUMENT VERSIONS ============
if ($action === 'get_versions') {
    $trackingId = (int)($_GET['tracking_id'] ?? $_POST['tracking_id'] ?? 0);
    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    $versions = [];
    $stmt = $connection->prepare("SELECT id, tracking_id, version_number, file_path, file_size, uploaded_by, department, version_type, created_at FROM document_versions WHERE tracking_id = ? ORDER BY version_number ASC, id ASC");
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Failed to prepare versions query', 'details' => $connection->error]);
    }
    $stmt->bind_param('i', $trackingId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            // Build file_url for each version (encrypted files go through download_version.php)
            $path = trim((string)($row['file_path'] ?? ''));
            $row['file_url'] = '';
            if ($path !== '') {
                // Versions are encrypted .enc files stored under uploads/returned/ or uploads/final/
                // Serve them through a version-specific download endpoint
                $row['file_url'] = 'download_version.php?id=' . $row['id'] . '&inline=1';
            }
            $versions[] = $row;
        }
        if ($res) { $res->free(); }
    }
    $stmt->close();

    sendJson(['success' => true, 'tracking_id' => $trackingId, 'versions' => $versions]);
}

// ============ GET CURRENT DOCUMENT (live check) ============
if ($action === 'get_current_doc') {
    $trackingId = (int)($_GET['tracking_id'] ?? $_POST['tracking_id'] ?? 0);
    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }
    $stmt = $connection->prepare("SELECT id, file_path, file_size FROM tracking WHERE id = ? LIMIT 1");
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Query failed']);
    }
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res) ? $res->fetch_assoc() : null;
    if ($res) $res->free();
    $stmt->close();

    if (!$row || empty($row['file_path'])) {
        sendJson(['success' => true, 'has_file' => false, 'file_url' => '', 'file_path' => '']);
    }
    $fileUrl = 'download.php?id=' . $row['id'] . '&inline=1&t=' . time();
    sendJson([
        'success' => true,
        'has_file' => true,
        'file_url' => $fileUrl,
        'file_path' => $row['file_path'],
        'file_size' => (int)($row['file_size'] ?? 0),
    ]);
}

// ============ GET HISTORY (TIMELINE) ============
if ($action === 'get_history') {
    $trackingId = (int)($_GET['tracking_id'] ?? $_POST['tracking_id'] ?? 0);
    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    $history = [];
    $sql = "SELECT id, doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at FROM document_history WHERE doc_id = ? ORDER BY created_at ASC, id ASC";
    $stmt = $connection->prepare($sql);
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Failed to prepare history query', 'details' => $connection->error]);
    }
    $stmt->bind_param('i', $trackingId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $history[] = $row;
        }
        if ($res) { $res->free(); }
    }
    $stmt->close();

    sendJson(['success' => true, 'tracking_id' => $trackingId, 'history' => $history]);
}

// ============ UPDATE / EDIT DOCUMENT ============
// Allows updating core fields in tracking and logs an audit trail entry in document_history.
if ($action === 'update_document') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $updatedBy = trim($_POST['updated_by'] ?? '');

    // Optional fields
    $newType = isset($_POST['type']) ? trim((string)$_POST['type']) : null;
    $newStatus = isset($_POST['status']) ? trim((string)$_POST['status']) : null;
    $newHolder = isset($_POST['current_holder']) ? trim((string)$_POST['current_holder']) : null;
    $newEndLocation = isset($_POST['end_location']) ? trim((string)$_POST['end_location']) : null;
    $comment = trim((string)($_POST['comment'] ?? ''));

    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    // Read current state
    $stmt = $connection->prepare("SELECT id, type, status, department, current_holder, end_location FROM tracking WHERE id = ? LIMIT 1");
    if (!$stmt) {
        sendJson(['success' => false, 'error' => 'Prepare failed', 'details' => $connection->error]);
    }
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $res = $stmt->get_result();
    $doc = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$doc) {
        sendJson(['success' => false, 'error' => 'Document not found']);
    }

    $oldType = (string)($doc['type'] ?? '');
    $oldStatus = (string)($doc['status'] ?? '');
    $oldDepartment = (string)($doc['department'] ?? '');
    $oldHolder = (string)($doc['current_holder'] ?? '');
    $oldEndLocation = (string)($doc['end_location'] ?? '');

    $setParts = [];
    $types = '';
    $vals = [];

    if ($newType !== null && $newType !== '' && $newType !== $oldType) {
        $setParts[] = "type = ?";
        $types .= 's';
        $vals[] = $newType;
    }
    if ($newStatus !== null && $newStatus !== '' && $newStatus !== $oldStatus) {
        $setParts[] = "status = ?";
        $types .= 's';
        $vals[] = $newStatus;
    }
    if ($newHolder !== null && $newHolder !== '' && $newHolder !== $oldHolder) {
        $setParts[] = "current_holder = ?";
        $types .= 's';
        $vals[] = $newHolder;
    }
    if ($newEndLocation !== null && $newEndLocation !== '' && $newEndLocation !== $oldEndLocation) {
        $setParts[] = "end_location = ?";
        $types .= 's';
        $vals[] = $newEndLocation;
    }

    if (empty($setParts)) {
        sendJson(['success' => true, 'message' => 'No changes detected']);
    }

    // Always append to remarks for visibility in legacy UI
    $remarkMsg = trim($comment);
    if ($remarkMsg === '') {
        $remarkMsg = 'Document updated';
    }

    $setParts[] = "remarks = CONCAT(COALESCE(remarks, ''), '\n[UPDATED] ', NOW(), ' by ', ?, ': ', ?)";
    $types .= 'ss';
    $vals[] = ($updatedBy !== '' ? $updatedBy : 'system');
    $vals[] = $remarkMsg;

    $sql = "UPDATE tracking SET " . implode(', ', $setParts) . " WHERE id = ?";
    $types .= 'i';
    $vals[] = $trackingId;

    $upd = $connection->prepare($sql);
    if (!$upd) {
        sendJson(['success' => false, 'error' => 'Prepare failed', 'details' => $connection->error]);
    }
    $bind = [];
    $bind[] = $types;
    for ($i = 0; $i < count($vals); $i++) {
        $bind[] = &$vals[$i];
    }
    call_user_func_array([$upd, 'bind_param'], $bind);
    $ok = $upd->execute();
    $err = $upd->error;
    $upd->close();

    if (!$ok) {
        sendJson(['success' => false, 'error' => 'Failed to update document', 'details' => $err]);
    }

    // Keep notifications in sync so the mobile dashboard (which is notifications-driven)
    // reflects edits immediately.
    try {
        $syncType = ($newType !== null && $newType !== '' ? $newType : $oldType);
        $syncStatus = ($newStatus !== null && $newStatus !== '' ? $newStatus : $oldStatus);
        $syncHolder = ($newHolder !== null && $newHolder !== '' ? $newHolder : $oldHolder);
        $syncEnd = ($newEndLocation !== null && $newEndLocation !== '' ? $newEndLocation : $oldEndLocation);
        $syncContent = trim($syncType) !== '' ? (trim($syncType) . ' • ' . ($updatedBy !== '' ? $updatedBy : 'system')) : '';

        if ($stmtN = $connection->prepare("UPDATE notifications SET content = ?, doc_status = ?, current_holder = ?, end_location = ? WHERE tracking_id = ? AND (status IS NULL OR status <> 'completed')")) {
            $stmtN->bind_param('ssssi', $syncContent, $syncStatus, $syncHolder, $syncEnd, $trackingId);
            $stmtN->execute();
            $stmtN->close();
        }
    } catch (Throwable $_) {
    }

    // Mirror to Firestore for realtime web updates (best-effort)
    try {
        if (function_exists('firestore_upsert_tracking')) {
            $fsType = ($newType !== null && $newType !== '' ? $newType : $oldType);
            $fsStatus = ($newStatus !== null && $newStatus !== '' ? $newStatus : $oldStatus);
            $fsHolder = ($newHolder !== null && $newHolder !== '' ? $newHolder : $oldHolder);
            $fsEnd = ($newEndLocation !== null && $newEndLocation !== '' ? $newEndLocation : $oldEndLocation);
            firestore_upsert_tracking((string)$trackingId, [
                'id' => (int)$trackingId,
                'type' => (string)$fsType,
                'status' => (string)$fsStatus,
                'department' => (string)$oldDepartment,
                'current_holder' => (string)$fsHolder,
                'end_location' => (string)$fsEnd,
            ]);
        }
    } catch (Throwable $_) {
    }

    // Log to history (best-effort)
    $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $toStatus = ($newStatus !== null && $newStatus !== '' ? $newStatus : $oldStatus);
    $toHolder = ($newHolder !== null && $newHolder !== '' ? $newHolder : $oldHolder);
    $notes = $remarkMsg;
    $hist = $connection->prepare("INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at) VALUES (?, 'edit', ?, ?, ?, ?, ?, ?, NOW())");
    if ($hist) {
        $hist->bind_param('iisssss', $trackingId, $actorId, $oldStatus, $toStatus, $oldHolder, $toHolder, $notes);
        $hist->execute();
        $hist->close();
    }

    sendJson([
        'success' => true,
        'message' => 'Document updated successfully',
        'tracking_id' => $trackingId,
    ]);
}

// ============ UPDATE DOCUMENT TYPE ============
if ($action === 'update_document_type') {
    $debugType = (int)($_GET['debug'] ?? $_POST['debug'] ?? 0) === 1;
    $dbg = ['phase' => 'start'];

    try {
        $trackingId = (int)($_POST['tracking_id'] ?? 0);
        $newType = trim($_POST['document_type'] ?? '');
        $updatedBy = trim($_POST['updated_by'] ?? '');
        $dbg['tracking_id'] = $trackingId;
        $dbg['new_type'] = $newType;

        if ($trackingId <= 0) {
            $out = ['success' => false, 'error' => 'Invalid tracking_id'];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }
        if ($newType === '') {
            $out = ['success' => false, 'error' => 'Document type cannot be empty'];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }

        // Detect correct column name in tracking table
        $trackCols = [];
        try {
            if ($colRes = $connection->query("SHOW COLUMNS FROM tracking")) {
                while ($c = $colRes->fetch_assoc()) {
                    $trackCols[] = (string)($c['Field'] ?? '');
                }
                $colRes->free();
            }
        } catch (Throwable $_) {
        }
        $trackColsLower = array_map(function ($v) { return strtolower($v); }, $trackCols);
        $hasTrackCol = function ($name) use ($trackColsLower) {
            return in_array(strtolower($name), $trackColsLower, true);
        };

        $typeCol = $hasTrackCol('document_type') ? 'document_type' : ($hasTrackCol('type') ? 'type' : null);
        $dbg['tracking_columns'] = $trackCols;
        $dbg['type_column'] = $typeCol;
        if ($typeCol === null) {
            $out = ['success' => false, 'error' => 'Tracking table schema mismatch', 'details' => 'Missing document type column'];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }

        // Get current type
        $stmt = $connection->prepare("SELECT {$typeCol} AS document_type FROM tracking WHERE id = ?");
        if (!$stmt) {
            $out = ['success' => false, 'error' => 'Failed to prepare lookup', 'details' => $connection->error];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }
        $stmt->bind_param('i', $trackingId);
        $stmt->execute();
        $result = $stmt->get_result();
        $doc = $result->fetch_assoc();
        $stmt->close();

        if (!$doc) {
            $out = ['success' => false, 'error' => 'Document not found'];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }

        $oldType = $doc['document_type'] ?? '';
        $dbg['old_type'] = $oldType;

        // Update document type
        $stmt = $connection->prepare("UPDATE tracking SET {$typeCol} = ? WHERE id = ?");
        if (!$stmt) {
            $out = ['success' => false, 'error' => 'Failed to prepare update', 'details' => $connection->error];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }
        $stmt->bind_param('si', $newType, $trackingId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            $out = ['success' => false, 'error' => 'Failed to update document type', 'details' => $connection->error];
            if ($debugType) $out['debug'] = $dbg;
            sendJson($out);
        }

        // Log to history
        $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $actorDept = (string)($_SESSION['department'] ?? $_SESSION['user_department'] ?? '');

        $notes = "Type changed from '$oldType' to '$newType' by $updatedBy";
        $histSql = "INSERT INTO document_history (doc_id, action, actor_user_id, from_holder, to_holder, from_status, to_status, notes, created_at)
                    VALUES (?, 'edit_type', ?, ?, ?, ?, ?, ?, NOW())";
        $histStmt = $connection->prepare($histSql);
        if ($histStmt) {
            $histStmt->bind_param('iisssss', $trackingId, $actorId, $actorDept, $actorDept, $oldType, $newType, $notes);
            $histStmt->execute();
            $histStmt->close();
        } else {
            $histSql2 = "INSERT INTO document_history (doc_id, action, from_status, to_status, notes, created_at)
                        VALUES (?, 'edit_type', ?, ?, ?, NOW())";
            $histStmt2 = $connection->prepare($histSql2);
            if ($histStmt2) {
                $histStmt2->bind_param('isss', $trackingId, $oldType, $newType, $notes);
                $histStmt2->execute();
                $histStmt2->close();
            }
        }

        // Update remarks
        $remarkUpdate = $connection->prepare("UPDATE tracking SET remarks = CONCAT(COALESCE(remarks, ''), '\n[TYPE CHANGED] ', NOW(), ' by ', ?, ': ', ? , ' -> ', ?) WHERE id = ?");
        if ($remarkUpdate) {
            $remarkUpdate->bind_param('sssi', $updatedBy, $oldType, $newType, $trackingId);
            $remarkUpdate->execute();
            $remarkUpdate->close();
        }

        $out = ['success' => true, 'old_type' => $oldType, 'new_type' => $newType, 'message' => 'Document type updated successfully'];
        if ($debugType) {
            $dbg['phase'] = 'success';
            $out['debug'] = $dbg;
        }
        sendJson($out);
    } catch (Throwable $t) {
        $dbg['phase'] = 'throwable';
        $dbg['throwable'] = $t->getMessage();
        $out = ['success' => false, 'error' => 'Server exception', 'details' => $t->getMessage()];
        if ($debugType) $out['debug'] = $dbg;
        sendJson($out);
    }
}

// ============ GET DOCUMENT BUNDLE ============
// Single-source-of-truth endpoint returning main doc + attachments + history in one call
if ($action === 'get_document_bundle') {
    $trackingId = (int)($_GET['tracking_id'] ?? $_POST['tracking_id'] ?? 0);
    if ($trackingId <= 0) {
        sendJson(['success' => false, 'error' => 'Invalid tracking_id']);
    }

    $bundle = [
        'tracking_id' => $trackingId,
        'main' => null,
        'attachments' => [],
        'history' => [],
        'ocr' => null,
    ];

    // ---- MAIN DOCUMENT ----
    $mainSql = "SELECT id, type, employee_name, date_submitted, current_holder, end_location, status, department, file_path, file_size, file_type_icon, remarks, created_at, ocr_content, ocr_summary FROM tracking WHERE id = ? LIMIT 1";
    $stmt = $connection->prepare($mainSql);
    if ($stmt) {
        $stmt->bind_param('i', $trackingId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($res) $res->free();
            if ($row) {
                // Build file_url for main document
                $filePath = trim((string)($row['file_path'] ?? ''));
                $fileUrl = '';
                if ($filePath !== '') {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $host = $_SERVER['HTTP_HOST'] ?? '';
                    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                    $appRootPath = preg_replace('#/lib/OCR\(UPDATED\)/api/.*$#', '', $scriptName);
                    $appBase = ($host !== '') ? ($scheme . $host . $appRootPath) : '';
                    
                    if (stripos($filePath, 'http://') === 0 || stripos($filePath, 'https://') === 0) {
                        $fileUrl = $filePath;
                    } else if (stripos($filePath, 'uploads/') === 0) {
                        $fileUrl = $appBase . '/lib/OCR(UPDATED)/' . $filePath;
                    } else if (stripos($filePath, '/uploads/') === 0) {
                        $fileUrl = $appBase . '/lib/OCR(UPDATED)' . $filePath;
                    } else if (stripos($filePath, 'lib/OCR(UPDATED)/uploads/') === 0) {
                        $fileUrl = $appBase . '/' . $filePath;
                    } else if ($filePath[0] === '/') {
                        $fileUrl = $scheme . $host . $filePath;
                    } else {
                        $fileUrl = $appBase . '/lib/OCR(UPDATED)/' . $filePath;
                    }
                }
                $row['file_url'] = $fileUrl;
                $bundle['main'] = $row;
                
                // OCR: prefer ocr_content from main row
                $mainOcr = trim((string)($row['ocr_content'] ?? ''));
                if ($mainOcr !== '') {
                    $bundle['ocr'] = [
                        'source' => 'main',
                        'text' => $mainOcr,
                        'summary' => trim((string)($row['ocr_summary'] ?? '')),
                    ];
                }
            }
        }
        $stmt->close();
    }

    if ($bundle['main'] === null) {
        sendJson(['success' => false, 'error' => 'Document not found', 'tracking_id' => $trackingId]);
    }

    // ---- ATTACHMENTS (oldest first) ----
    $attSql = "SELECT id, tracking_id, file_path, file_name, file_type, file_size, uploaded_by, department, remarks, created_at FROM document_attachments WHERE tracking_id = ? ORDER BY created_at ASC, id ASC";
    $stmt = $connection->prepare($attSql);
    if ($stmt) {
        $stmt->bind_param('i', $trackingId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $path = trim((string)($row['file_path'] ?? ''));
                $fileUrl = '';
                if ($path !== '') {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $host = $_SERVER['HTTP_HOST'] ?? '';
                    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                    $appRootPath = preg_replace('#/lib/OCR\(UPDATED\)/api/.*$#', '', $scriptName);
                    $appBase = ($host !== '') ? ($scheme . $host . $appRootPath) : '';
                    
                    if (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0) {
                        $fileUrl = $path;
                    } else if (stripos($path, 'uploads/') === 0) {
                        $fileUrl = $appBase . '/lib/OCR(UPDATED)/' . $path;
                    } else if (stripos($path, '/uploads/') === 0) {
                        $fileUrl = $appBase . '/lib/OCR(UPDATED)' . $path;
                    } else if (stripos($path, 'lib/OCR(UPDATED)/uploads/') === 0) {
                        $fileUrl = $appBase . '/' . $path;
                    } else if ($path[0] === '/') {
                        $fileUrl = $scheme . $host . $path;
                    } else {
                        $fileUrl = $appBase . '/lib/OCR(UPDATED)/' . $path;
                    }
                }
                $row['file_url'] = $fileUrl;
                $bundle['attachments'][] = $row;
            }
            if ($res) $res->free();
        }
        $stmt->close();
    }

    // ---- HISTORY (oldest first) ----
    $histSql = "SELECT id, doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at FROM document_history WHERE doc_id = ? ORDER BY created_at ASC, id ASC";
    $stmt = $connection->prepare($histSql);
    if ($stmt) {
        $stmt->bind_param('i', $trackingId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $bundle['history'][] = $row;
            }
            if ($res) $res->free();
        }
        $stmt->close();
    }

    // ---- OCR PAGES (if main OCR is empty, try ocr_pages table) ----
    if ($bundle['ocr'] === null) {
        $ocrPagesSql = "SELECT page_number, ocr_text, ocr_keywords FROM ocr_pages WHERE scope = 'tracking' AND doc_id = ? ORDER BY page_number ASC";
        $stmt = $connection->prepare($ocrPagesSql);
        if ($stmt) {
            $stmt->bind_param('i', $trackingId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                $pages = [];
                $allText = '';
                while ($res && ($row = $res->fetch_assoc())) {
                    $pages[] = $row;
                    $allText .= trim((string)($row['ocr_text'] ?? '')) . "\n\n";
                }
                if ($res) $res->free();
                if (!empty($pages)) {
                    $bundle['ocr'] = [
                        'source' => 'ocr_pages',
                        'text' => trim($allText),
                        'pages' => $pages,
                    ];
                }
            }
            $stmt->close();
        }
    }

    // ---- ATTACHMENT OCR (supplemental, for search - not shown by default) ----
    $attOcrSql = "SELECT op.page_number, op.ocr_text, op.ocr_keywords, op.doc_id AS attachment_id
                  FROM ocr_pages op
                  INNER JOIN document_attachments da ON op.doc_id = da.id AND op.scope = 'attachment'
                  WHERE da.tracking_id = ?
                  ORDER BY da.created_at ASC, op.page_number ASC";
    $stmt = $connection->prepare($attOcrSql);
    if ($stmt) {
        $stmt->bind_param('i', $trackingId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $attOcrPages = [];
            while ($res && ($row = $res->fetch_assoc())) {
                $attOcrPages[] = $row;
            }
            if ($res) $res->free();
            if (!empty($attOcrPages)) {
                $bundle['attachment_ocr_pages'] = $attOcrPages;
            }
        }
        $stmt->close();
    }

    sendJson(['success' => true, 'bundle' => $bundle]);
}

// Default response
sendJson(['success' => false, 'error' => 'Invalid action']);
