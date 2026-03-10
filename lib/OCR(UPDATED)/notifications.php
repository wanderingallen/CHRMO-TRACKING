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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

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

    if ($recipient === '' && $recipientDept === '') {
        respond(['success' => false, 'message' => 'recipient_username or recipient_department required'], 400);
    }

    $stmt = $conn->prepare("INSERT INTO notifications (title, content, type, recipient_username, sender_username, department, recipient_department, file_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssssss', $title, $content, $type, $recipient, $sender, $department, $recipientDept, $fileUrl);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
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
    $limit = intval($_GET['limit'] ?? 20);
    if ($limit <= 0 || $limit > 100) $limit = 50;

    $conds = [];
    $params = [];
    $types = '';

    if ($recipient !== '') { $conds[] = 'LOWER(TRIM(recipient_username)) = LOWER(TRIM(?))'; $params[] = $recipient; $types .= 's'; }
    if ($recipientDept !== '') { $conds[] = 'LOWER(TRIM(recipient_department)) = LOWER(TRIM(?))'; $params[] = $recipientDept; $types .= 's'; }
    if ($type !== '') { $conds[] = 'type = ?'; $params[] = $type; $types .= 's'; }

    $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $sql = "SELECT id, title, content, type, recipient_username, recipient_department, sender_username, department, status, file_url, UNIX_TIMESTAMP(created_at)*1000 as created_at FROM notifications $where ORDER BY id DESC LIMIT ?";
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
    while ($row = $res->fetch_assoc()) {
        $row['time_ago'] = '';
        $rows[] = $row;
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
