<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/../lib/OCR(UPDATED)/api/file_crypto.php';

$connection = new mysqli('localhost', 'root', '', 'chrmo_db');
if ($connection->connect_error) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Database connection failed']);
  exit();
}

function ensureExtractedContentTable($connection) {
  $sql = "CREATE TABLE IF NOT EXISTS extracted_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_ref VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    owner_user_id INT NOT NULL,
    owner_department VARCHAR(255) NULL,
    content_sha256 CHAR(64) NOT NULL,
    enc_blob LONGBLOB NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_doc (owner_user_id, doc_ref),
    KEY idx_doc_ref (doc_ref),
    KEY idx_owner_dept (owner_department),
    KEY idx_sha256 (content_sha256)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  @$connection->query($sql);
}

function json_exit($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit();
}

function get_bearer_token() {
  $hdr = '';
  if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'];
  } elseif (function_exists('getallheaders')) {
    $h = getallheaders();
    if (isset($h['Authorization'])) {
      $hdr = $h['Authorization'];
    } elseif (isset($h['authorization'])) {
      $hdr = $h['authorization'];
    }
  }
  $hdr = trim((string)$hdr);
  if ($hdr === '') return null;
  if (stripos($hdr, 'Bearer ') === 0) {
    return trim(substr($hdr, 7));
  }
  return null;
}

function load_token_identity($connection, $token) {
  if ($token === null || $token === '') {
    return null;
  }
  // Ensure table exists (login.php also ensures, but this API should be standalone)
  @$connection->query("CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    role VARCHAR(100) NULL,
    department VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_user_id (user_id),
    KEY idx_expires_at (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $tokenHash = hash('sha256', $token);
  $stmt = $connection->prepare('SELECT user_id, role, department, expires_at, revoked_at FROM api_tokens WHERE token_hash = ? LIMIT 1');
  if (!$stmt) return null;
  $stmt->bind_param('s', $tokenHash);
  if (!$stmt->execute()) {
    $stmt->close();
    return null;
  }
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if (!$row) return null;
  if (!empty($row['revoked_at'])) return null;

  $exp = strtotime((string)($row['expires_at'] ?? ''));
  if ($exp && $exp < time()) return null;

  // Update last_used_at best-effort
  try {
    $u = $connection->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?');
    if ($u) {
      $u->bind_param('s', $tokenHash);
      @$u->execute();
      $u->close();
    }
  } catch (Throwable $e) {
    // ignore
  }

  return [
    'user_id' => (int)$row['user_id'],
    'role' => (string)($row['role'] ?? 'user'),
    'department' => (string)($row['department'] ?? ''),
  ];
}

function is_admin_role($role) {
  $r = strtolower(trim((string)$role));
  return in_array($r, ['admin', 'administrator', 'superadmin', 'super_admin'], true);
}

ensureExtractedContentTable($connection);

$token = get_bearer_token();
$identity = load_token_identity($connection, $token);
if (!$identity) {
  $connection->close();
  json_exit(401, ['success' => false, 'message' => 'Unauthorized']);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $payload = json_decode($raw, true);
  if (!$payload) {
    $payload = $_POST;
  }

  $docRef = trim((string)($payload['doc_ref'] ?? ''));
  $title = trim((string)($payload['title'] ?? ''));
  $text = (string)($payload['extracted_text'] ?? '');

  if ($docRef === '' || trim($text) === '') {
    $connection->close();
    json_exit(400, ['success' => false, 'message' => 'doc_ref and extracted_text are required']);
  }

  $ownerUserId = (int)$identity['user_id'];
  $ownerDept = (string)$identity['department'];

  $sha = hash('sha256', $text);
  $enc = file_crypto_encrypt_string($text);
  if ($enc === false) {
    $connection->close();
    json_exit(500, ['success' => false, 'message' => 'Encryption failed']);
  }

  $stmt = $connection->prepare(
    'INSERT INTO extracted_content (doc_ref, title, owner_user_id, owner_department, content_sha256, enc_blob)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE title = VALUES(title), owner_department = VALUES(owner_department), content_sha256 = VALUES(content_sha256), enc_blob = VALUES(enc_blob), updated_at = CURRENT_TIMESTAMP'
  );
  if (!$stmt) {
    $connection->close();
    json_exit(500, ['success' => false, 'message' => 'Prepare failed']);
  }

  $stmt->bind_param('ssisss', $docRef, $title, $ownerUserId, $ownerDept, $sha, $enc);
  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    $err = $connection->error;
    $connection->close();
    json_exit(500, ['success' => false, 'message' => 'Insert failed', 'error' => $err]);
  }

  // Fetch the record id
  $id = null;
  $stmt2 = $connection->prepare('SELECT id, updated_at FROM extracted_content WHERE owner_user_id = ? AND doc_ref = ? LIMIT 1');
  if ($stmt2) {
    $stmt2->bind_param('is', $ownerUserId, $docRef);
    if ($stmt2->execute()) {
      $res2 = $stmt2->get_result();
      if ($res2 && $r2 = $res2->fetch_assoc()) {
        $id = (int)$r2['id'];
        $updatedAt = $r2['updated_at'] ?? null;
      }
      if ($res2) { $res2->free(); }
    }
    $stmt2->close();
  }

  $connection->close();
  echo json_encode([
    'success' => true,
    'data' => [
      'id' => $id,
      'doc_ref' => $docRef,
      'content_sha256' => $sha,
      'updated_at' => $updatedAt ?? null,
    ]
  ]);
  exit();
}

if ($method === 'GET') {
  $docRef = trim((string)($_GET['doc_ref'] ?? ''));
  if ($docRef === '') {
    $connection->close();
    json_exit(400, ['success' => false, 'message' => 'doc_ref is required']);
  }

  $isAdmin = is_admin_role($identity['role']);
  $uid = (int)$identity['user_id'];
  $dept = (string)$identity['department'];

  if ($isAdmin) {
    $stmt = $connection->prepare('SELECT id, doc_ref, title, owner_user_id, owner_department, content_sha256, enc_blob, updated_at FROM extracted_content WHERE doc_ref = ? ORDER BY updated_at DESC LIMIT 50');
    $stmt->bind_param('s', $docRef);
  } else {
    // Non-admin: owner OR same department
    $stmt = $connection->prepare('SELECT id, doc_ref, title, owner_user_id, owner_department, content_sha256, enc_blob, updated_at FROM extracted_content WHERE doc_ref = ? AND (owner_user_id = ? OR owner_department = ?) ORDER BY updated_at DESC LIMIT 50');
    $stmt->bind_param('sis', $docRef, $uid, $dept);
  }

  if (!$stmt) {
    $connection->close();
    json_exit(500, ['success' => false, 'message' => 'Prepare failed']);
  }

  if (!$stmt->execute()) {
    $stmt->close();
    $connection->close();
    json_exit(500, ['success' => false, 'message' => 'Query failed']);
  }

  $res = $stmt->get_result();
  $rows = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $plain = file_crypto_decrypt_blob($r['enc_blob']);
    if ($plain === false) {
      $plain = null;
    }
    $rows[] = [
      'id' => (int)$r['id'],
      'doc_ref' => (string)$r['doc_ref'],
      'title' => (string)($r['title'] ?? ''),
      'owner_user_id' => (int)$r['owner_user_id'],
      'owner_department' => (string)($r['owner_department'] ?? ''),
      'content_sha256' => (string)$r['content_sha256'],
      'updated_at' => (string)($r['updated_at'] ?? ''),
      'extracted_text' => $plain,
    ];
  }

  $stmt->close();
  $connection->close();
  echo json_encode(['success' => true, 'data' => $rows]);
  exit();
}

$connection->close();
json_exit(405, ['success' => false, 'message' => 'Method not allowed']);
