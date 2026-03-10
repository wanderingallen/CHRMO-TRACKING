<?php
// Enable error reporting for debugging
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

// Log request for debugging
$debug_log = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'timestamp' => date('Y-m-d H:i:s'),
    'post_data' => $_POST,
    'raw_input' => file_get_contents('php://input')
];
file_put_contents(__DIR__ . '/login_debug.log', json_encode($debug_log) . "\n", FILE_APPEND);

$servername = "localhost";
$username = "root";
$password = "";
$database = "chrmo_db";

$connection = new mysqli($servername, $username, $password, $database);
if ($connection->connect_error) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Database connection failed'
  ]);
  exit();
}

function ensureApiTokensTable($connection) {
  $sql = "CREATE TABLE IF NOT EXISTS api_tokens (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  @$connection->query($sql);
}

function issueApiToken($connection, $userId, $role, $department) {
  ensureApiTokensTable($connection);

  // Clean up expired tokens opportunistically
  @$connection->query("DELETE FROM api_tokens WHERE expires_at < NOW() OR revoked_at IS NOT NULL");

  $token = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $token);
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

  $stmt = $connection->prepare(
    "INSERT INTO api_tokens (user_id, token_hash, role, department, expires_at, ip_address, user_agent)
     VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
  );
  if (!$stmt) {
    return [null, null];
  }
  $stmt->bind_param('isssss', $userId, $tokenHash, $role, $department, $ip, $ua);
  $ok = $stmt->execute();
  $stmt->close();
  if (!$ok) {
    return [null, null];
  }

  $expiresAt = null;
  try {
    $res = $connection->query("SELECT expires_at FROM api_tokens WHERE token_hash = '" . $connection->real_escape_string($tokenHash) . "' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
      $expiresAt = $row['expires_at'] ?? null;
    }
    if ($res) { $res->free(); }
  } catch (Throwable $e) {
    $expiresAt = null;
  }

  return [$token, $expiresAt];
}

// Helper: detect which column stores the password in `control` table
function detectPasswordColumn($connection) {
  // Expanded candidate list to support various schemas
  $candidates = [
    'password', 'pwd', 'pass', 'password_hash', 'pword',
    'passwd', 'passcode', 'pin', 'secret', 'pwd_hash', 'passwd_hash'
  ];
  foreach ($candidates as $col) {
    $colSafe = $connection->real_escape_string($col);
    $res = $connection->query("SHOW COLUMNS FROM `control` LIKE '" . $colSafe . "'");
    if ($res && $res->num_rows > 0) {
      if ($res) { $res->free(); }
      return $col;
    }
    if ($res) { $res->free(); }
  }
  return null;
}

// Detect status column if any
function detectStatusColumn($connection) {
  $candidates = ['status', 'account_status', 'is_active'];
  foreach ($candidates as $col) {
    $res = $connection->query("SHOW COLUMNS FROM `control` LIKE '" . $connection->real_escape_string($col) . "'");
    if ($res && $res->num_rows > 0) {
      if ($res) { $res->free(); }
      return $col;
    }
    if ($res) { $res->free(); }
  }
  return null;
}

// Handle GET requests for testing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode([
    'success' => true,
    'message' => 'Login API is accessible',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion()
  ]);
  exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!$payload) {
  $payload = $_POST; // fallback for form-encoded
}

$identifier = isset($payload['identifier']) ? trim($payload['identifier']) : '';
$pwd = isset($payload['password']) ? (string)$payload['password'] : '';

if ($identifier === '' || $pwd === '') {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => 'Identifier and password are required'
  ]);
  $connection->close();
  exit();
}

// Try match by email or username (column `user`)
$passwordColumn = detectPasswordColumn($connection);
// If no obvious password column exists, we still proceed to fetch the user
// and later try to match against common legacy fields or fail gracefully.

$statusColumn = detectStatusColumn($connection);
if ($passwordColumn) {
  if ($statusColumn) {
    $sql = "SELECT id, user, email, role, department, {$statusColumn} AS status, {$passwordColumn} AS password FROM control WHERE email = ? OR user = ? LIMIT 1";
  } else {
    // No status column: treat as active by default
    $sql = "SELECT id, user, email, role, department, 'active' AS status, {$passwordColumn} AS password FROM control WHERE email = ? OR user = ? LIMIT 1";
  }
} else {
  // Fallback when password column is unknown: try to fetch with any plausible fields
  // We'll attempt comparison against a few legacy columns below.
  if ($statusColumn) {
    $sql = "SELECT id, user, email, role, department, {$statusColumn} AS status, * FROM control WHERE email = ? OR user = ? LIMIT 1";
  } else {
    $sql = "SELECT id, user, email, role, department, 'active' AS status, * FROM control WHERE email = ? OR user = ? LIMIT 1";
  }
}
$stmt = $connection->prepare($sql);
$stmt->bind_param('ss', $identifier, $identifier);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Query failed'
  ]);
  $stmt->close();
  $connection->close();
  exit();
}

$result = $stmt->get_result();
if ($result && $row = $result->fetch_assoc()) {
  // Derive the stored password value from detected column or common legacy names
  $stored = null;
  if ($passwordColumn && isset($row['password'])) {
    $stored = $row['password'];
  } else {
    // Try legacy column names in the fetched row
    foreach (['password','pwd','pass','pword','passwd','passcode','pin','secret','password_hash','pwd_hash','passwd_hash'] as $col) {
      if (isset($row[$col])) { $stored = $row[$col]; break; }
    }
  }

  $isValid = false;
  if (is_string($stored) && $stored !== '') {
    $storedTrim = trim($stored);
    // If value looks like bcrypt/argon hash, use password_verify
    if (strpos($storedTrim, '$2y$') === 0 || strpos($storedTrim, '$argon2') === 0) {
      $isValid = password_verify($pwd, $storedTrim);
    } else if (preg_match('/^[a-f0-9]{32}$/i', $storedTrim)) {
      // Legacy MD5 support
      $isValid = (md5($pwd) === strtolower($storedTrim));
    } else {
      // Plaintext fallback (legacy/unsafe but useful during migration)
      $isValid = hash_equals($storedTrim, $pwd);
    }
  }

  if ($isValid) {
    $status = isset($row['status']) ? strtolower((string)$row['status']) : '';
    // Consider active unless status is EXPLICITLY negative
    $negative = ['inactive','disabled','blocked','suspended','0','false','no'];
    $isActive = !in_array($status, $negative, true);
    if (!$isActive) {
      echo json_encode([
        'success' => false,
        'message' => 'Account is not active'
      ]);
    } else {
      $uid = (int)$row['id'];
      $roleVal = (string)($row['role'] ?? 'user');
      $deptVal = (string)($row['department'] ?? '');
      [$apiToken, $apiExpiresAt] = issueApiToken($connection, $uid, $roleVal, $deptVal);

      echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
          'id' => $uid,
          'user' => $row['user'],
          'email' => $row['email'],
          'role' => $roleVal,
          'department' => $deptVal,
          'status' => $row['status'],
          'api_token' => $apiToken,
          'api_token_expires_at' => $apiExpiresAt,
        ]
      ]);
    }
  } else {
    echo json_encode([
      'success' => false,
      'message' => 'Invalid credentials'
    ]);
  }
} else {
  echo json_encode([
    'success' => false,
    'message' => 'Invalid credentials'
  ]);
}

if ($result) { $result->free(); }
$stmt->close();
$connection->close();

?>


