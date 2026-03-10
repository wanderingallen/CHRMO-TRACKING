<?php
// Ensure clean JSON only
@ini_set('display_errors', 0);
@ini_set('html_errors', 0);
if (function_exists('ob_get_level')) { @ob_start(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } }
  echo json_encode(['success'=>true]);
  exit();
}

require_once __DIR__ . '/../db_connect.php';

$out = [
  'success' => false,
  'users' => [],
  'departments' => [],
  'error' => null,
];

try {
  // Detect status column if present
  $statusCol = null;
  $res = $conn->query("SHOW COLUMNS FROM `control` LIKE 'status'");
  if ($res) {
    if ($res->num_rows > 0) { $statusCol = 'status'; }
    $res->free();
  }

  $where = '';
  if ($statusCol) {
    $where = "WHERE ($statusCol IS NULL OR $statusCol='' OR LOWER($statusCol)='active' OR $statusCol='1' OR LOWER($statusCol)='true' OR LOWER($statusCol)='yes')";
  }

  // Fetch users (id, user, email, role, department)
  $sqlUsers = "SELECT id, `user`, email, role, department FROM control $where ORDER BY `user` ASC";
  if ($r = $conn->query($sqlUsers)) {
    while ($row = $r->fetch_assoc()) {
      $out['users'][] = [
        'id' => (int)$row['id'],
        'user' => (string)($row['user'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'role' => (string)($row['role'] ?? ''),
        'department' => (string)($row['department'] ?? ''),
      ];
    }
    $r->free();
  }

  // Fetch distinct departments
  $sqlDept = "SELECT DISTINCT department FROM control WHERE department IS NOT NULL AND TRIM(department)<>'' ORDER BY department ASC";
  if ($rd = $conn->query($sqlDept)) {
    while ($row = $rd->fetch_assoc()) {
      $out['departments'][] = (string)$row['department'];
    }
    $rd->free();
  }

  $out['success'] = true;
} catch (Throwable $e) {
  http_response_code(500);
  $out['error'] = $e->getMessage();
}

// Flush only JSON
if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } }
echo json_encode($out);
exit;
