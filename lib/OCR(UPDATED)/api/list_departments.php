<?php
@ini_set('display_errors', 0);
@ini_set('html_errors', 0);
if (function_exists('ob_get_level')) { @ob_start(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(200);
  if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } }
  echo json_encode(['success'=>true]);
  exit();
}

require_once __DIR__ . '/../db_connect.php';

$out = [
  'success' => false,
  'departments' => [],
  'error' => null,
];

try {
  $exists = false;
  if ($r = $conn->query("SHOW TABLES LIKE 'departments'")) {
    $exists = ($r->num_rows > 0);
    $r->free();
  }

  if ($exists) {
    $col = null;
    if ($r = $conn->query("SHOW COLUMNS FROM `departments` LIKE 'name'")) {
      if ($r->num_rows > 0) { $col = 'name'; }
      $r->free();
    }
    if ($col === null) {
      if ($r = $conn->query("SHOW COLUMNS FROM `departments` LIKE 'dept_name'")) {
        if ($r->num_rows > 0) { $col = 'dept_name'; }
        $r->free();
      }
    }
    if ($col === null) {
      if ($r = $conn->query("SHOW COLUMNS FROM `departments` LIKE 'department'")) {
        if ($r->num_rows > 0) { $col = 'department'; }
        $r->free();
      }
    }

    if ($col !== null) {
      $sql = "SELECT DISTINCT TRIM(`{$col}`) AS d FROM `departments` WHERE `{$col}` IS NOT NULL AND TRIM(`{$col}`)<>'' ORDER BY TRIM(`{$col}`) ASC";
      if ($r = $conn->query($sql)) {
        while ($row = $r->fetch_assoc()) {
          $d = strtoupper(trim((string)($row['d'] ?? '')));
          if ($d !== '') { $out['departments'][] = $d; }
        }
        $r->free();
      }
    }
  }

  $out['departments'] = array_values(array_unique(array_filter($out['departments'], function($x){ return trim((string)$x) !== ''; })));
  $out['success'] = true;
} catch (Throwable $e) {
  http_response_code(500);
  $out['error'] = $e->getMessage();
}

if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } }
echo json_encode($out);
exit;
