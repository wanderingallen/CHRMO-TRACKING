<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/config.php';

$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Database connection failed'
  ]);
  exit();
}

// Helper: detect which column stores the password in `control` table
function detectPasswordColumn($connection) {
  $candidates = ['password', 'pwd', 'pass', 'password_hash', 'pword'];
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
if (!$passwordColumn) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Password column missing in control table'
  ]);
  $connection->close();
  exit();
}

$statusColumn = detectStatusColumn($connection);
if ($statusColumn) {
  $sql = "SELECT id, user, email, role, department, {$statusColumn} AS status, {$passwordColumn} AS password FROM control WHERE email = ? OR user = ? LIMIT 1";
} else {
  // No status column: treat as active by default
  $sql = "SELECT id, user, email, role, department, 'active' AS status, {$passwordColumn} AS password FROM control WHERE email = ? OR user = ? LIMIT 1";
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
  $hashed = $row['password'];
  if ($hashed && password_verify($pwd, $hashed)) {
    $status = isset($row['status']) ? strtolower((string)$row['status']) : '';
    // Allow if status is missing/empty or equals 'active' or truthy values
    $isActive = ($status === '' || $status === 'active' || $status === '1' || $status === 'true' || $status === 'yes');
    if (!$isActive) {
      echo json_encode([
        'success' => false,
        'message' => 'Account is not active'
      ]);
    } else {
      echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
          'id' => (int)$row['id'],
          'user' => $row['user'],
          'email' => $row['email'],
          'role' => $row['role'],
          'department' => $row['department'],
          'status' => $row['status'],
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


