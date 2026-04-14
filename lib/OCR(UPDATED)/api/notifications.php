<?php
// Simple notifications API supporting create/list/delete with recipient_username filter
// Table auto-creation if missing
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// Enable output buffering so we can discard any stray echoes/warnings
ob_start();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

require_once __DIR__ . '/../db_connect.php'; // provides $conn (mysqli)
require_once __DIR__ . '/../firestore_client.php';

function respond($arr, $code = 200) {
    http_response_code($code);
    // Remove any previous output (warnings, stray echoes) so response is pure JSON
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($arr);
    exit;
}

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  content TEXT,
  type VARCHAR(64) DEFAULT 'mobile_message',
  recipient_username VARCHAR(128) NOT NULL,
  sender_username VARCHAR(128) DEFAULT NULL,
  department VARCHAR(128) DEFAULT NULL,
  recipient_department VARCHAR(128) DEFAULT NULL,
  status VARCHAR(32) DEFAULT 'new',
  file_url TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(recipient_username),
  INDEX(recipient_department),
  INDEX(type),
  INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Attempt lightweight migrations if table existed before
// Use IF NOT EXISTS to avoid duplicate column errors on repeated runs
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS file_url TEXT NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS recipient_department VARCHAR(128) NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS status VARCHAR(32) DEFAULT 'new'");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS tracking_id INT NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS mobile_timestamp VARCHAR(128) NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS doc_hash VARCHAR(128) NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS end_location VARCHAR(128) NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS current_holder VARCHAR(128) NULL");
@$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS doc_status VARCHAR(64) NULL");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Fetch a single notification by id
if ($method === 'GET' && $action === 'get') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'id required'], 400);
    }
    $stmt = $conn->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
    if (!$stmt) {
        respond(['success' => false, 'message' => 'Prepare failed'], 500);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        respond(['success' => false, 'message' => 'Query failed'], 500);
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        respond(['success' => false, 'message' => 'Not found'], 404);
    }
    respond(['success' => true, 'notification' => $row]);
}

// Update status (e.g., routed, confirmed)
if (($method === 'POST' && $action === 'update_status') || ($method === 'GET' && $action === 'update_status')) {
    $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? $_GET['status'] ?? ''));
    if ($id <= 0 || $status === '') {
        respond(['success' => false, 'message' => 'id and status required'], 400);
    }
    $stmt = $conn->prepare('UPDATE notifications SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $id);
    if ($stmt->execute()) {
        respond(['success' => true]);
    } else {
        respond(['success' => false, 'message' => 'Update failed'], 500);
    }
}

if (($method === 'POST' && ($action === 'create' || $action === '')) ||
    ($method === 'GET' && $action === 'create')) {
    $src = ($method === 'POST') ? $_POST : $_GET;
    $title = trim((string)($src['title'] ?? 'Attachment'));
    $content = trim((string)($src['content'] ?? ''));
    $type = trim((string)($src['type'] ?? 'mobile_message'));
    $recipient = trim((string)($src['recipient_username'] ?? ($src['recipient'] ?? '')));
    $recipientDept = trim((string)($src['recipient_department'] ?? ($src['dept'] ?? $src['department'] ?? '')));
    $sender = trim((string)($src['sender_username'] ?? ''));
    $department = trim((string)($src['department'] ?? ''));
    $fileUrl = trim((string)($src['file_url'] ?? ($src['url'] ?? ($src['attachment'] ?? ''))));
    $trackingId = isset($src['tracking_id']) ? intval($src['tracking_id']) : null;
    $mobileTimestamp = trim((string)($src['mobile_timestamp'] ?? ''));
    $docHash = trim((string)($src['doc_hash'] ?? ''));
    $endLocation = trim((string)($src['end_location'] ?? ''));
    $currentHolder = trim((string)($src['current_holder'] ?? ''));
    $docStatus = trim((string)($src['doc_status'] ?? ''));

    // If the client didn't send tracking_id/mobile_timestamp, try to infer from tracking.
    // This keeps routing update-only (Option A) by ensuring notifications carry identifiers.
    if (($trackingId === null || $trackingId <= 0) && $fileUrl !== '') {
        // Try exact match by server file_path
        if ($q = $conn->prepare("SELECT id, mobile_timestamp, doc_hash, end_location, current_holder, status FROM tracking WHERE file_path = ? ORDER BY id DESC LIMIT 1")) {
            $q->bind_param('s', $fileUrl);
            if ($q->execute()) {
                $res = $q->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trackingId = (int)$row['id'];
                    if ($mobileTimestamp === '') {
                        $mobileTimestamp = trim((string)($row['mobile_timestamp'] ?? ''));
                    }
                    if ($endLocation === '') {
                        $endLocation = trim((string)($row['end_location'] ?? ''));
                    }
                    if ($currentHolder === '') {
                        $currentHolder = trim((string)($row['current_holder'] ?? ''));
                    }
                    if ($docStatus === '') {
                        $docStatus = trim((string)($row['status'] ?? ''));
                    }
                    if ($docHash === '') {
                        $docHash = trim((string)($row['doc_hash'] ?? ''));
                    }
                }
            }
            $q->close();
        }
    }
    if (($trackingId === null || $trackingId <= 0) && $mobileTimestamp !== '') {
        // Infer by mobile_timestamp (works even if file_url is missing)
        if ($q = $conn->prepare("SELECT id, mobile_timestamp, doc_hash, end_location, current_holder, status, file_path FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1")) {
            $q->bind_param('s', $mobileTimestamp);
            if ($q->execute()) {
                $res = $q->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trackingId = (int)$row['id'];
                    if ($endLocation === '') {
                        $endLocation = trim((string)($row['end_location'] ?? ''));
                    }
                    if ($currentHolder === '') {
                        $currentHolder = trim((string)($row['current_holder'] ?? ''));
                    }
                    if ($docStatus === '') {
                        $docStatus = trim((string)($row['status'] ?? ''));
                    }
                    if ($fileUrl === '') {
                        $fileUrl = trim((string)($row['file_path'] ?? ''));
                    }
                    if ($docHash === '') {
                        $docHash = trim((string)($row['doc_hash'] ?? ''));
                    }
                }
            }
            $q->close();
        }
    }

    if ($recipient === '' && $recipientDept === '') {
        respond(['success' => false, 'message' => 'recipient_username or recipient_department required'], 400);
    }

    $stmt = $conn->prepare("INSERT INTO notifications (title, content, type, recipient_username, sender_username, department, recipient_department, file_url, tracking_id, mobile_timestamp, doc_hash, end_location, current_holder, doc_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // 14 params: 8 strings + 1 int + 5 strings
    $stmt->bind_param('ssssssssisssss', $title, $content, $type, $recipient, $sender, $department, $recipientDept, $fileUrl, $trackingId, $mobileTimestamp, $docHash, $endLocation, $currentHolder, $docStatus);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;

        // Mirror notification into Firestore 'notifications' collection (best-effort)
        try {
            firestore_upsert_document('notifications', $newId, [
                'id'                  => (int)$newId,
                'title'               => $title,
                'content'             => $content,
                'type'                => $type,
                'recipient_username'  => $recipient,
                'recipient_department'=> $recipientDept,
                'department'          => $department,
                'file_url'            => $fileUrl,
                'tracking_id'         => $trackingId,
                'mobile_timestamp'    => $mobileTimestamp,
                'doc_hash'            => $docHash,
                'end_location'        => $endLocation,
                'current_holder'      => $currentHolder,
                'createdAt'           => (int)round(microtime(true) * 1000),
            ]);
        } catch (Throwable $t) {
            error_log('firestore_upsert_document(notifications) failed: ' . $t->getMessage());
        }
        // Send FCM push
        @require_once __DIR__ . '/send_fcm.php';
        // Prefer direct recipient push; else by department topic
        if ($recipient !== '') {
            // Fetch tokens for this username
            $tokens = [];
            if ($q = $conn->prepare("SELECT token FROM fcm_tokens WHERE username = ?")) {
                $q->bind_param('s', $recipient);
                if ($q->execute()) {
                    $res2 = $q->get_result();
                    while ($row = $res2->fetch_assoc()) { $tokens[] = $row['token']; }
                }
            }
            if (!empty($tokens)) {
                @send_fcm_to_tokens($tokens, $title, $content, [
                    'type' => $type,
                    'notification_id' => (string)$newId,
                    'recipient_username' => $recipient,
                    'recipient_department' => $recipientDept,
                    'file_url' => $fileUrl,
                ]);
            }
        } elseif ($recipientDept !== '') {
            $topic = 'dept_' . strtolower(trim($recipientDept));
            @send_fcm_to_topic($topic, $title, $content, [
                'type' => $type,
                'notification_id' => (string)$newId,
                'recipient_department' => $recipientDept,
                'file_url' => $fileUrl,
            ]);
        }
        respond(['success' => true, 'id' => $newId]);
    } else {
        respond(['success' => false, 'message' => 'Insert failed'], 500);
    }
}

if ($method === 'GET' && ($action === 'list' || $action === '')) {
    $recipient = trim((string)($_GET['recipient_username'] ?? ($_GET['recipient'] ?? '')));
    $recipientDept = trim((string)($_GET['recipient_department'] ?? ($_GET['dept'] ?? '')));
    $type = trim($_GET['type'] ?? '');
    $includeCompleted = (string)($_GET['include_completed'] ?? '0');
    $limit = intval($_GET['limit'] ?? 20);
    if ($limit <= 0 || $limit > 100) $limit = 50;

    $conds = [];
    $params = [];
    $types = '';

    if ($recipient !== '' && $recipientDept !== '') {
        // When both are provided, match user-targeted OR department-targeted rows.
        // Some legacy rows store department code in recipient_username.
        $conds[] = '(LOWER(TRIM(recipient_username)) = LOWER(TRIM(?)) OR LOWER(TRIM(recipient_department)) = LOWER(TRIM(?)) OR LOWER(TRIM(recipient_username)) = LOWER(TRIM(?)))';
        $params[] = $recipient;
        $params[] = $recipientDept;
        $params[] = $recipientDept;
        $types .= 'sss';
    } elseif ($recipient !== '') {
        $conds[] = 'LOWER(TRIM(recipient_username)) = LOWER(TRIM(?))';
        $params[] = $recipient;
        $types .= 's';
    } elseif ($recipientDept !== '') {
        // Department-only query: accept explicit recipient_department or legacy dept key in recipient_username.
        $conds[] = '(LOWER(TRIM(recipient_department)) = LOWER(TRIM(?)) OR LOWER(TRIM(recipient_username)) = LOWER(TRIM(?)))';
        $params[] = $recipientDept;
        $params[] = $recipientDept;
        $types .= 'ss';
    }
    if ($type !== '') { $conds[] = 'type = ?'; $params[] = $type; $types .= 's'; }

    // By default, hide completed rows (we use status='completed' as a soft-delete for dashboards)
    if (!in_array(strtolower(trim($includeCompleted)), ['1', 'true', 'yes'], true)) {
        $conds[] = "(status IS NULL OR status <> 'completed')";
    }

    $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $sql = "SELECT id, title, content, type, recipient_username, recipient_department, sender_username, department, status, file_url, tracking_id, mobile_timestamp, doc_hash, end_location, current_holder, doc_status, UNIX_TIMESTAMP(created_at)*1000 as created_at FROM notifications $where ORDER BY id DESC LIMIT ?";
    $stmt = $conn->prepare($sql);

    if ($types === '') {
        $stmt->bind_param('i', $limit);
    } else {
        $types .= 'i';
        $params[] = $limit;
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        respond(['success' => false, 'message' => 'Query failed'], 500);
    }
    $res = $stmt->get_result();
    $rows = [];

    // Prepared statement for inferring identifiers from tracking by file_url/mobile_timestamp (best-effort)
    $inferStmt = null;
    if ($inferStmtTry = $conn->prepare("SELECT id, mobile_timestamp, doc_hash, end_location, current_holder, status FROM tracking WHERE file_path = ? ORDER BY id DESC LIMIT 1")) {
        $inferStmt = $inferStmtTry;
    }

    $inferByMobileStmt = null;
    if ($inferByMobileTry = $conn->prepare("SELECT id, mobile_timestamp, doc_hash, end_location, current_holder, status, file_path FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1")) {
        $inferByMobileStmt = $inferByMobileTry;
    }

    $byIdStmt = null;
    // Detect which column stores the document type in tracking (document_type vs type)
    $trackingTypeCol = null;
    try {
        if ($colRes = $conn->query("SHOW COLUMNS FROM tracking")) {
            $cols = [];
            while ($c = $colRes->fetch_assoc()) {
                $cols[] = strtolower((string)($c['Field'] ?? ''));
            }
            $colRes->free();
            if (in_array('document_type', $cols, true)) {
                $trackingTypeCol = 'document_type';
            } elseif (in_array('type', $cols, true)) {
                $trackingTypeCol = 'type';
            }
        }
    } catch (Throwable $_) {
        $trackingTypeCol = null;
    }

    $byIdSelect = "SELECT id, mobile_timestamp, doc_hash, end_location, current_holder, status";
    if ($trackingTypeCol !== null) {
        $byIdSelect .= ", {$trackingTypeCol} AS document_type";
    }
    $byIdSelect .= " FROM tracking WHERE id = ? LIMIT 1";
    if ($byIdTry = $conn->prepare($byIdSelect)) {
        $byIdStmt = $byIdTry;
    }

    while ($row = $res->fetch_assoc()) {
        // Backfill identifier fields for older notifications that were created without tracking_id/mobile_timestamp
        $tid = isset($row['tracking_id']) ? (int)$row['tracking_id'] : 0;
        $mts = trim((string)($row['mobile_timestamp'] ?? ''));
        $furl = trim((string)($row['file_url'] ?? ''));
        if (($tid <= 0 || $mts === '') && $furl !== '' && $inferStmt) {
            $inferStmt->bind_param('s', $furl);
            if ($inferStmt->execute()) {
                $ir = $inferStmt->get_result();
                if ($ir && ($tr = $ir->fetch_assoc())) {
                    if ($tid <= 0) {
                        $row['tracking_id'] = (int)$tr['id'];
                        $tid = (int)$tr['id'];
                    }
                    if ($mts === '') {
                        $row['mobile_timestamp'] = trim((string)($tr['mobile_timestamp'] ?? ''));
                        $mts = trim((string)($row['mobile_timestamp'] ?? ''));
                    }
                    if (empty($row['doc_hash'])) {
                        $row['doc_hash'] = trim((string)($tr['doc_hash'] ?? ''));
                    }
                    if (empty($row['end_location'])) {
                        $row['end_location'] = trim((string)($tr['end_location'] ?? ''));
                    }
                    if (empty($row['current_holder'])) {
                        $row['current_holder'] = trim((string)($tr['current_holder'] ?? ''));
                    }
                    if (empty($row['doc_status'])) {
                        $row['doc_status'] = trim((string)($tr['status'] ?? ''));
                    }
                }
            }
        }

        // If still no tracking_id but we have mobile_timestamp, infer by mobile_timestamp
        if ($tid <= 0 && $mts !== '' && $inferByMobileStmt) {
            $inferByMobileStmt->bind_param('s', $mts);
            if ($inferByMobileStmt->execute()) {
                $ir = $inferByMobileStmt->get_result();
                if ($ir && ($tr = $ir->fetch_assoc())) {
                    $row['tracking_id'] = (int)$tr['id'];
                    $tid = (int)$tr['id'];
                    if (empty($row['end_location'])) {
                        $row['end_location'] = trim((string)($tr['end_location'] ?? ''));
                    }
                    if (empty($row['current_holder'])) {
                        $row['current_holder'] = trim((string)($tr['current_holder'] ?? ''));
                    }
                    if (empty($row['doc_status'])) {
                        $row['doc_status'] = trim((string)($tr['status'] ?? ''));
                    }
                    if (empty($row['file_url'])) {
                        $row['file_url'] = trim((string)($tr['file_path'] ?? ''));
                    }
                    if (empty($row['doc_hash'])) {
                        $row['doc_hash'] = trim((string)($tr['doc_hash'] ?? ''));
                    }
                }
            }
        }

        // Always refresh doc_status/current_holder from tracking when tracking_id is known
        if ($tid > 0 && $byIdStmt) {
            $byIdStmt->bind_param('i', $tid);
            if ($byIdStmt->execute()) {
                $trr = $byIdStmt->get_result();
                if ($trr && ($tr2 = $trr->fetch_assoc())) {
                    $row['current_holder'] = trim((string)($tr2['current_holder'] ?? ($row['current_holder'] ?? '')));
                    $row['end_location'] = trim((string)($tr2['end_location'] ?? ($row['end_location'] ?? '')));
                    $row['mobile_timestamp'] = trim((string)($tr2['mobile_timestamp'] ?? ($row['mobile_timestamp'] ?? '')));
                    $row['doc_hash'] = trim((string)($tr2['doc_hash'] ?? ($row['doc_hash'] ?? '')));
                    $row['doc_status'] = trim((string)($tr2['status'] ?? ($row['doc_status'] ?? '')));
                    if (isset($tr2['document_type'])) {
                        $row['document_type'] = trim((string)$tr2['document_type']);
                    }
                }
            }
        }

        // Provide camelCase aliases for clients that expect them
        if (!isset($row['trackingId']) && $tid > 0) {
            $row['trackingId'] = $tid;
        }
        if (!isset($row['mobileTimestamp']) && $mts !== '') {
            $row['mobileTimestamp'] = $mts;
        }
        if (!isset($row['docHash']) && !empty($row['doc_hash'])) {
            $row['docHash'] = trim((string)$row['doc_hash']);
        }
        // Add camelCase aliases for end_location and current_holder
        if (!empty($row['end_location'])) {
            $row['endLocation'] = $row['end_location'];
        }
        if (!empty($row['current_holder'])) {
            $row['currentHolder'] = $row['current_holder'];
        }

        if (!empty($row['doc_status'])) {
            $row['docStatus'] = $row['doc_status'];
        }

        $row['time_ago'] = '';
        $rows[] = $row;
    }

    if ($inferStmt) {
        $inferStmt->close();
    }
    if ($inferByMobileStmt) {
        $inferByMobileStmt->close();
    }
    if ($byIdStmt) {
        $byIdStmt->close();
    }
    respond(['success' => true, 'notifications' => $rows]);
}

if ($method === 'DELETE' || ($method === 'POST' && $action === 'delete') || ($method === 'GET' && $action === 'delete')) {
    $id = $_GET['id'] ?? $_POST['id'] ?? '';
    if (!$id) respond(['success' => false, 'message' => 'id required'], 400);
    $stmt = $conn->prepare('DELETE FROM notifications WHERE id = ?');
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        respond(['success' => true]);
    } else {
        respond(['success' => false, 'message' => 'Delete failed'], 500);
    }
}

respond(['success' => false, 'message' => 'Unsupported request'], 400);
