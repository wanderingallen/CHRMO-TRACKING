<?php
// recent_activity.php - Lightweight recent activity feed for mobile dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$servername = 'localhost';
$username   = 'root';
$password   = '';
$database   = 'chrmo_db';

$connection = @new mysqli($servername, $username, $password, $database);
if ($connection->connect_error) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Database connection failed']);
  exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Delete a tracking row by id (used by mobile long-press delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
  $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id required']);
    $connection->close();
    exit();
  }

  $stmt = $connection->prepare('DELETE FROM tracking WHERE id = ?');
  $stmt->bind_param('i', $id);
  $ok = $stmt->execute();
  $stmt->close();
  $connection->close();
  echo json_encode(['success' => (bool)$ok]);
  exit();
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limit < 1 || $limit > 100) { $limit = 20; }

// Latest by created_at if present, else by date_submitted/id
// Do not show terminal statuses in the mobile dashboard feed.
// IMPORTANT: For announcement routing, some rows may rely on end_location (target dept)
// even when current_holder is empty. We treat current_holder as highest priority, then
// end_location, then department.
$sql = "SELECT id, employee_name, department, type, status, created_at, date_submitted, mobile_timestamp,
               current_holder, end_location, file_path, doc_hash, id AS tracking_id
  FROM tracking
  WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','archived','approved')
  ORDER BY COALESCE(created_at, date_submitted) DESC, id DESC
  LIMIT ?";

$items = [];
if ($stmt = $connection->prepare($sql)) {
  $stmt->bind_param('i', $limit);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $when = !empty($r['created_at']) ? $r['created_at'] : ($r['date_submitted'] ?? '');
      $isMobile = !empty($r['mobile_timestamp']);
      $type = $isMobile ? 'document_upload' : 'web_upload';

      $currentHolder = trim((string)($r['current_holder'] ?? ''));
      $endLocation = trim((string)($r['end_location'] ?? ''));
      $filePath = trim((string)($r['file_path'] ?? ''));
      $docHash = trim((string)($r['doc_hash'] ?? ''));

      // Resolve recipient department for filtering on mobile.
      // Some announcement rows may not set current_holder but will set end_location.
      $recipientDept = $currentHolder !== ''
        ? $currentHolder
        : ($endLocation !== '' ? $endLocation : (string)($r['department'] ?? ''));

      // time-ago
      $timeAgo = '';
      if ($when) {
        $ts = strtotime($when);
        $diff = time() - $ts;
        if ($diff < 60) $timeAgo = 'Just now';
        else if ($diff < 3600) $timeAgo = floor($diff/60) . ' min ago';
        else if ($diff < 86400) $timeAgo = floor($diff/3600) . ' hr ago';
        else $timeAgo = floor($diff/86400) . ' day(s) ago';
      }

      $items[] = [
        'id' => (int)$r['id'],
        'title' => ($r['type'] ?? 'Document') . ' - ' . ($r['employee_name'] ?? 'Unknown'),
        'content' => ($isMobile ? 'Mobile Upload' : 'Web Upload') . ' • ' . ($r['department'] ?? 'Department') . ' • ' . ($r['status'] ?? ''),
        'time_ago' => $timeAgo,
        'type' => $type,
        // Provide keys compatible with dashboard filters and end-location logic
        'dept' => $recipientDept,
        'recipient_department' => $recipientDept,
        'current_holder' => $currentHolder,
        'end_location' => $endLocation,
        'file_path' => $filePath,
        'file_url' => $filePath,
        'mobile_timestamp' => (string)($r['mobile_timestamp'] ?? ''),
        'doc_hash' => $docHash,
        'tracking_id' => (string)($r['tracking_id'] ?? $r['id'] ?? ''),
      ];
    }
    $res->free();
  }
  $stmt->close();
}

echo json_encode(['success' => true, 'notifications' => $items, 'count' => count($items)]);
$connection->close();
