<?php
@ini_set('display_errors', 0);
@ini_set('html_errors', 0);
if (function_exists('ob_get_level')) { @ob_start(); }

// DELETE: delete single message (expects JSON or query: id, username)
if ($method === 'DELETE') {
  $raw = file_get_contents('php://input');
  parse_str($raw, $formFallback); // handle id=..&username=.. payload
  $data = [];
  if ($raw) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) { $data = $tmp; }
  }
  if (empty($data)) { $data = $formFallback; }
  $id = (int)($data['id'] ?? 0);
  $username = trim((string)($data['username'] ?? ''));
  if ($id <= 0 || $username === '') { http_response_code(400); json_flush(['success'=>false,'message'=>'id and username required']); }
  $stmt = $conn->prepare("DELETE FROM share_feed WHERE id = ? AND username = ?");
  $stmt->bind_param('is', $id, $username);
  $ok = $stmt->execute();
  json_flush(['success'=>$ok, 'deleted'=>$stmt->affected_rows]);
}

// (Moved DELETE handler below after method is defined)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } } echo json_encode(['success'=>true]); exit; }

require_once __DIR__ . '/../db_connect.php';

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS share_feed (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(128) NOT NULL,
  department VARCHAR(128) DEFAULT NULL,
  content TEXT NOT NULL,
  created_at INT NOT NULL,
  INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Reactions table (likes)
$conn->query("CREATE TABLE IF NOT EXISTS share_reactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  share_id INT NOT NULL,
  username VARCHAR(128) NOT NULL,
  created_at INT NOT NULL,
  UNIQUE KEY uniq_share_user (share_id, username),
  INDEX(share_id),
  CONSTRAINT fk_share_reactions_feed FOREIGN KEY (share_id) REFERENCES share_feed(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];

function json_flush($data){ if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } } echo json_encode($data); exit; }

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $payload = [];
  if ($raw) { $tmp = json_decode($raw, true); if (is_array($tmp)) { $payload = $tmp; } }
  if (empty($payload)) { $payload = $_POST; }
  // Handle action-based operations for clients that can't send DELETE
  $action = isset($payload['action']) ? strtolower(trim((string)$payload['action'])) : '';
  if ($action === 'delete') {
    $id = (int)($payload['id'] ?? 0);
    $username = trim((string)($payload['username'] ?? ''));
    if ($id <= 0 || $username === '') { http_response_code(400); json_flush(['success'=>false,'message'=>'id and username required']); }
    $stmt = $conn->prepare("DELETE FROM share_feed WHERE id = ? AND username = ?");
    $stmt->bind_param('is', $id, $username);
    $ok = $stmt->execute();
    json_flush(['success'=>$ok, 'deleted'=>$stmt->affected_rows]);
  } elseif ($action === 'clear_user') {
    $username = trim((string)($payload['username'] ?? ''));
    if ($username === '') { http_response_code(400); json_flush(['success'=>false,'message'=>'username required']); }
    $stmt = $conn->prepare("DELETE FROM share_feed WHERE username = ?");
    $stmt->bind_param('s', $username);
    $ok = $stmt->execute();
    json_flush(['success'=>$ok, 'deleted'=>$stmt->affected_rows]);
  } elseif ($action === 'clear_all') {
    // Danger: deletes entire conversation
    $ok = $conn->query("TRUNCATE TABLE share_feed");
    if (!$ok) { $ok = $conn->query("DELETE FROM share_feed"); }
    json_flush(['success'=>(bool)$ok]);
  } elseif ($action === 'like') {
    $id = (int)($payload['id'] ?? 0);
    $username = trim((string)($payload['username'] ?? ''));
    if ($id <= 0 || $username === '') { http_response_code(400); json_flush(['success'=>false,'message'=>'id and username required']); }
    $stmt = $conn->prepare("INSERT IGNORE INTO share_reactions (share_id, username, created_at) VALUES (?, ?, ?)");
    $now = time();
    $stmt->bind_param('isi', $id, $username, $now);
    $ok = $stmt->execute();
    json_flush(['success'=>$ok]);
  } elseif ($action === 'unlike') {
    $id = (int)($payload['id'] ?? 0);
    $username = trim((string)($payload['username'] ?? ''));
    if ($id <= 0 || $username === '') { http_response_code(400); json_flush(['success'=>false,'message'=>'id and username required']); }
    $stmt = $conn->prepare("DELETE FROM share_reactions WHERE share_id = ? AND username = ?");
    $stmt->bind_param('is', $id, $username);
    $ok = $stmt->execute();
    json_flush(['success'=>$ok]);
  }

  $username = trim((string)($payload['username'] ?? ''));
  $department = trim((string)($payload['department'] ?? ''));
  $content = trim((string)($payload['content'] ?? ''));

  if ($username === '' || $content === '') {
    http_response_code(400);
    json_flush(['success'=>false,'message'=>'username and content are required']);
  }

  $stmt = $conn->prepare("INSERT INTO share_feed (username, department, content, created_at) VALUES (?, ?, ?, ?)");
  $now = time();
  $stmt->bind_param('sssi', $username, $department, $content, $now);
  if (!$stmt->execute()) {
    http_response_code(500);
    json_flush(['success'=>false,'message'=>'Failed to share','error'=>$stmt->error]);
  }
  json_flush(['success'=>true,'id'=>$stmt->insert_id]);
}

// GET: list items (newest first)
$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
$reqUser = isset($_GET['user']) ? trim((string)$_GET['user']) : '';

if ($since > 0) {
  $stmt = $conn->prepare("SELECT id, username, department, content, created_at FROM share_feed WHERE created_at > ? ORDER BY created_at DESC LIMIT ?");
  $stmt->bind_param('ii', $since, $limit);
} else {
  $stmt = $conn->prepare("SELECT id, username, department, content, created_at FROM share_feed ORDER BY created_at DESC LIMIT ?");
  $stmt->bind_param('i', $limit);
}
$stmt->execute();
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
  // likes count
  $rid = (int)$row['id'];
  $likesCount = 0;
  $likedByMe = false;
  $q1 = $conn->prepare("SELECT COUNT(*) AS c FROM share_reactions WHERE share_id = ?");
  $q1->bind_param('i', $rid);
  if ($q1->execute()) { $rc = $q1->get_result()->fetch_assoc(); $likesCount = (int)($rc['c'] ?? 0); }
  if ($reqUser !== '') {
    $q2 = $conn->prepare("SELECT 1 FROM share_reactions WHERE share_id = ? AND username = ? LIMIT 1");
    $q2->bind_param('is', $rid, $reqUser);
    if ($q2->execute()) { $r2 = $q2->get_result(); $likedByMe = (bool)$r2->fetch_row(); }
  }

  $items[] = [
    'id' => (int)$row['id'],
    'username' => (string)$row['username'],
    'department' => (string)($row['department'] ?? ''),
    'content' => (string)$row['content'],
    'created_at' => (int)$row['created_at'],
    'likes_count' => $likesCount,
    'liked_by_me' => $likedByMe,
  ];
}
json_flush(['success'=>true,'items'=>$items]);
