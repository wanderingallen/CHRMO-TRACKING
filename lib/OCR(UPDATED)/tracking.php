<?php
// Detect if this is an API request; avoid emitting UI includes for JSON endpoints
$__api_action = isset($_GET['action']) ? $_GET['action'] : null;

require_once 'config.php';
// Include app helpers only for full-page (non-API) requests
if (!isset($__api_action) || $__api_action === null) {
    require_once 'user_profile_widget.php';
}

function __tracking_archive_has_source_tracking_id(mysqli $connection): bool {
    static $has = null;
    if ($has !== null) return $has;
    $res = @$connection->query("SHOW COLUMNS FROM archive LIKE 'source_tracking_id'");
    $has = ($res && $res->num_rows > 0);
    if ($res) { $res->free(); }
    return $has;
}

function __tracking_archive_has_archived_by_dept(mysqli $connection): bool {
    static $has = null;
    if ($has !== null) return $has;
    $res = @$connection->query("SHOW COLUMNS FROM archive LIKE 'archived_by_department'");
    $has = ($res && $res->num_rows > 0);
    if ($res) { $res->free(); }
    return $has;
}

function __tracking_document_history_has_doc_type(mysqli $connection): bool {
    static $has = null;
    if ($has !== null) return $has;
    $res = @$connection->query("SHOW COLUMNS FROM document_history LIKE 'doc_type'");
    $has = ($res && $res->num_rows > 0);
    if ($res) { $res->free(); }
    return $has;
}
require_once 'settings_util.php';
require_once 'security.php';
require_once 'api/archive_storage.php';
require_once 'firestore_client.php';
require_once 'api/ocr_search_helper.php';

function tracking_firestore_delete_linked_notifications($connection, $trackingId)
{
    if (!function_exists('firestore_delete_document')) {
        return;
    }
    $trackingId = (int)$trackingId;
    if ($trackingId <= 0) {
        return;
    }
    $sql = "SELECT id FROM notifications WHERE tracking_id = ?";
    if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $trackingId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $nid = $row['id'] ?? null;
                if ($nid === null) {
                    continue;
                }
                try {
                    firestore_delete_document('notifications', (string)$nid);
                } catch (Throwable $t) {
                    // best-effort only
                }
            }
            if ($res) {
                $res->free();
            }
        }
        $stmt->close();
    }
}

// Copy document_history rows from a tracking doc_id into archive_history keyed by archive_id.
// This preserves the full audit trail even after the tracking row is deleted.
function __tracking_copy_history_to_archive($conn, $trackingId, $archiveId) {
    $trackingId = (int)$trackingId;
    $archiveId = (int)$archiveId;
    if ($trackingId <= 0 || $archiveId <= 0) return;
    @$conn->query("CREATE TABLE IF NOT EXISTS archive_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        archive_id INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        actor_user_id INT NOT NULL DEFAULT 0,
        from_status VARCHAR(50) NULL,
        to_status VARCHAR(50) NULL,
        from_holder VARCHAR(255) NULL,
        to_holder VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (archive_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $sql = "INSERT INTO archive_history (archive_id, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at)
            SELECT ?, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes, created_at
            FROM document_history WHERE doc_id = ?
            ORDER BY created_at ASC, id ASC";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ii', $archiveId, $trackingId);
        @$stmt->execute();
        $stmt->close();
    }
}

// Ensure session is started before any $_SESSION access
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser caching for the full page so JS updates apply without hard refresh
if (!headers_sent() && (!isset($__api_action) || $__api_action === null)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}

$__isAdmin = Security::is_admin();

$__sw_path = __DIR__ . '/../../firebase-messaging-sw.js';
$__sw_ver = is_file($__sw_path) ? (string)filemtime($__sw_path) : (string)time();


// Database connection details
$connection = null;
try {
  $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
  $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
} catch (mysqli_sql_exception $e) {
  http_response_code(503);
  // API requests must return JSON
  if (isset($__api_action) && $__api_action !== null) {
    header('Content-Type: application/json');
    echo json_encode([
      'success' => false,
      'error' => 'Database unavailable',
      'message' => 'MySQL connection refused. Start MySQL in XAMPP or verify DB_HOST/DB_PORT in config.php.'
    ]);
    exit;
  }

  // Full page: show a friendly message instead of a fatal error.
  $safeHost = htmlspecialchars((string)DB_HOST, ENT_QUOTES);
  $safePort = htmlspecialchars((string)(defined('DB_PORT') ? DB_PORT : 3306), ENT_QUOTES);
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Database Unavailable</title>';
  echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f8fafc;margin:0;padding:40px;color:#0f172a}';
  echo '.card{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,.06)}';
  echo 'code{background:#f1f5f9;padding:2px 6px;border-radius:6px}ul{margin:10px 0 0 18px}';
  echo 'a.btn{display:inline-block;margin-top:14px;background:#2563eb;color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none}';
  echo '</style></head><body>';
  echo '<div class="card">';
  echo '<h2 style="margin:0 0 8px 0">Database Unavailable</h2>';
  echo '<div style="color:#475569">Tracking page cannot connect to MySQL right now.</div>';
  echo '<ul>';
  echo '<li>Open XAMPP Control Panel and start <b>MySQL</b>.</li>';
  echo '<li>Verify <code>DB_HOST</code> and <code>DB_PORT</code> in <code>lib/OCR(UPDATED)/config.php</code> (current: ' . $safeHost . ':' . $safePort . ').</li>';
  echo '<li>If MySQL uses another port (e.g. 3307), set <code>DB_PORT</code> accordingly.</li>';
  echo '</ul>';
  echo '<a class="btn" href="tracking.php">Retry</a>';
  echo '</div></body></html>';
  exit;
}

// Check database connection (when mysqli is not in strict exception mode)
if (!$connection || $connection->connect_error) {
  http_response_code(503);
  die('Database unavailable');
}

// Housekeeping: remove incomplete/dummy rows that have no file identity.
// These rows cannot be opened/previewed and pollute search/testing.
// Keep this narrow: only rows already at end_location='Archive'.
if (!isset($__api_action) || $__api_action === null) {
  $cleanupIds = [];
  if ($sel = $connection->prepare(
    "SELECT id FROM tracking\n" .
    "WHERE UPPER(TRIM(COALESCE(end_location,''))) = 'ARCHIVE'\n" .
    "  AND COALESCE(NULLIF(TRIM(file_path),''), NULLIF(TRIM(mobile_timestamp),''), NULLIF(TRIM(doc_hash),'')) IS NULL\n" .
    "LIMIT 50"
  )) {
    if ($sel->execute()) {
      $res = $sel->get_result();
      while ($res && ($row = $res->fetch_assoc())) {
        $cleanupIds[] = (int)($row['id'] ?? 0);
      }
      if ($res) { $res->free(); }
    }
    $sel->close();
  }

  if (!empty($cleanupIds)) {
    $placeholders = implode(',', array_fill(0, count($cleanupIds), '?'));
    $types = str_repeat('i', count($cleanupIds));
    $sql = "DELETE FROM tracking WHERE id IN ($placeholders)";
    if ($del = $connection->prepare($sql)) {
      $bind = [$types];
      foreach ($cleanupIds as $k => $v) { $bind[] = &$cleanupIds[$k]; }
      @call_user_func_array([$del, 'bind_param'], $bind);
      $del->execute();
      $del->close();
    }

    // Best-effort: keep Firestore in sync.
    if (function_exists('firestore_delete_tracking')) {
      foreach ($cleanupIds as $id) {
        if ($id > 0) {
          try { firestore_delete_tracking((string)$id); } catch (Throwable $t) { /* ignore */ }
        }
      }
    }
  }
}

// NOTE: DDL (schema) changes have been moved to tools/migrate_tracking_schema.php for one-time execution.
// Run that migration script once on your database to add the doc_hash column, index, and document_history table.

// Light helper to emit JSON + exit without rendering the rest of the page
function tracking_send_json($connection, array $payload) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode($payload);
    if ($connection && $connection->ping()) {
        $connection->close();
    }
    exit();
}

function __tracking_load_departments(mysqli $connection): array {
    $out = [];
    try {
        $has = @$connection->query("SHOW TABLES LIKE 'departments'");
        $exists = ($has && $has->num_rows > 0);
        if ($has) { $has->free(); }
        if ($exists) {
            $col = null;
            $c1 = @$connection->query("SHOW COLUMNS FROM departments LIKE 'name'");
            if ($c1 && $c1->num_rows > 0) { $col = 'name'; }
            if ($c1) { $c1->free(); }
            if ($col === null) {
                $c2 = @$connection->query("SHOW COLUMNS FROM departments LIKE 'dept_name'");
                if ($c2 && $c2->num_rows > 0) { $col = 'dept_name'; }
                if ($c2) { $c2->free(); }
            }
            if ($col === null) {
                $c3 = @$connection->query("SHOW COLUMNS FROM departments LIKE 'department'");
                if ($c3 && $c3->num_rows > 0) { $col = 'department'; }
                if ($c3) { $c3->free(); }
            }
            if ($col !== null) {
                $sql = "SELECT DISTINCT TRIM(`{$col}`) AS d FROM departments WHERE `{$col}` IS NOT NULL AND TRIM(`{$col}`) != '' ORDER BY TRIM(`{$col}`) ASC";
                if ($r = @$connection->query($sql)) {
                    while ($row = $r->fetch_assoc()) {
                        $d = strtoupper(trim((string)($row['d'] ?? '')));
                        if ($d !== '') $out[] = $d;
                    }
                    $r->free();
                }
            }
        }
    } catch (Throwable $t) {
        $out = [];
    }
    $out = array_values(array_unique(array_filter($out, fn($x) => $x !== '')));
    if (empty($out)) {
        $out = ['CACCO','CADO','CBO','CMO','CPDO','CTO','GSO','HR'];
    }
    return $out;
}

if (isset($_GET['action']) && $_GET['action'] === 'save_ocr_correction') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    tracking_send_json($connection, ['success' => false, 'error' => 'Invalid request method']);
  }

  $docId = (int)($_POST['doc_id'] ?? 0);
  $ocrText = (string)($_POST['ocr_text'] ?? '');
  $ocrText = trim($ocrText);

  if ($docId <= 0) {
    tracking_send_json($connection, ['success' => false, 'error' => 'Invalid doc_id']);
  }

  if ($ocrText === '') {
    tracking_send_json($connection, ['success' => false, 'error' => 'OCR text is empty']);
  }

  try {
    if (function_exists('ocr_ensure_parent_ocr_columns')) {
      ocr_ensure_parent_ocr_columns($connection);
    }
  } catch (Throwable $t) {
  }

  $summary = '';
  try {
    if (function_exists('ocr_extract_keywords')) {
      $keywords = ocr_extract_keywords($ocrText);
      if (is_array($keywords) && !empty($keywords)) {
        $summary = implode(' ', array_slice($keywords, 0, 50));
      }
    }
  } catch (Throwable $t) {
    $summary = '';
  }

  if ($stmt = $connection->prepare("UPDATE tracking SET ocr_content = ?, ocr_summary = ? WHERE id = ?")) {
    $stmt->bind_param('ssi', $ocrText, $summary, $docId);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) {
      tracking_send_json($connection, ['success' => false, 'error' => $err ?: 'Failed to save OCR correction']);
    }
    tracking_send_json($connection, ['success' => true, 'doc_id' => $docId]);
  }

  tracking_send_json($connection, ['success' => false, 'error' => $connection->error ?: 'Failed to prepare statement']);
}

if (false && isset($_GET['action']) && $_GET['action'] === 'debug_ocr_search') {
  $q = trim((string)($_GET['q'] ?? ''));
  $limit = (int)($_GET['limit'] ?? 25);
  if ($limit <= 0) $limit = 25;
  if ($limit > 200) $limit = 200;

  if ($q === '') {
    tracking_send_json($connection, ['success' => false, 'error' => 'Missing q']);
  }

  try {
    if (function_exists('ocr_ensure_pages_table')) {
      ocr_ensure_pages_table($connection);
    }
    if (function_exists('ocr_ensure_parent_ocr_columns')) {
      ocr_ensure_parent_ocr_columns($connection);
    }
  } catch (Throwable $t) {
    // best-effort only
  }

  $terms = preg_split('/\s+/', $q);
  $terms = array_values(array_filter(array_map('trim', $terms), function($t){ return $t !== '' && strlen($t) >= 2; }));
  if (empty($terms)) {
    $terms = [$q];
  }
  if (count($terms) > 6) {
    $terms = array_slice($terms, 0, 6);
  }

  $patterns = [];
  $patterns[] = $q;
  if (count($terms) >= 2) {
    $patterns[] = implode('%', $terms);
  }
  foreach ($terms as $t) {
    $patterns[] = $t;
  }
  $patterns = array_values(array_unique(array_filter(array_map('trim', $patterns), function($p){ return $p !== ''; })));
  if (count($patterns) > 8) {
    $patterns = array_slice($patterns, 0, 8);
  }

  $fieldClause = "(tracking.type LIKE ? OR tracking.employee_name LIKE ? OR tracking.current_holder LIKE ? OR tracking.end_location LIKE ? OR tracking.status LIKE ? OR tracking.department LIKE ? OR tracking.ocr_content LIKE ? OR tracking.ocr_summary LIKE ? OR EXISTS (SELECT 1 FROM ocr_pages op WHERE op.scope='tracking' AND op.doc_id = tracking.id AND (op.ocr_text LIKE ? OR op.ocr_keywords LIKE ?)))";
  $orClauses = [];
  $bindTypes = '';
  $bindParams = [];
  foreach ($patterns as $p) {
    $orClauses[] = $fieldClause;
    $like = '%' . $p . '%';
    $bindTypes .= str_repeat('s', 10);
    for ($i = 0; $i < 10; $i++) {
      $bindParams[] = $like;
    }
  }

  $where = 'WHERE (' . implode(' OR ', $orClauses) . ')';
  $sql = "SELECT tracking.id FROM tracking $where ORDER BY tracking.id DESC LIMIT ?";
  $ids = [];
  $error = null;

  if ($stmt = $connection->prepare($sql)) {
    $types = $bindTypes . 'i';
    $params = $bindParams;
    $params[] = $limit;
    tracking_bind_params($stmt, $types, $params);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      if ($res) {
        while ($row = $res->fetch_assoc()) {
          $ids[] = (int)$row['id'];
        }
        $res->free();
      }
    } else {
      $error = $stmt->error;
    }
    $stmt->close();
  } else {
    $error = $connection->error;
  }

  tracking_send_json($connection, [
    'success' => $error === null,
    'q' => $q,
    'terms' => $terms,
    'patterns' => $patterns,
    'limit' => $limit,
    'matched_ids' => $ids,
    'matched_count' => count($ids),
    'sql' => $sql,
    'where' => $where,
    'error' => $error,
  ]);
}

// Get OCR pages for a tracking document
// Usage: tracking.php?action=ocr_pages&doc_id=123
if (isset($_GET['action']) && $_GET['action'] === 'ocr_pages') {
  $docId = (int)($_GET['doc_id'] ?? 0);
  if ($docId <= 0) {
    tracking_send_json($connection, ['success' => false, 'error' => 'Invalid doc_id']);
  }

  $pages = [];
  try {
    if (function_exists('ocr_ensure_pages_table')) {
      ocr_ensure_pages_table($connection);
    }
    if (function_exists('ocr_get_pages')) {
      $pages = ocr_get_pages($connection, 'tracking', $docId);
    }
  } catch (Throwable $t) {
    $pages = [];
  }

  // Fallback: legacy single OCR content column (if present)
  if (empty($pages)) {
    try {
      if ($stmt = $connection->prepare("SELECT ocr_content FROM tracking WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $docId);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $text = trim((string)($row['ocr_content'] ?? ''));
            if ($text !== '') {
              $pages = [[
                'page_number' => 1,
                'ocr_text' => $text,
                'ocr_keywords' => null,
                'confidence_score' => null,
              ]];
            }
          }
          if ($res) { $res->free(); }
        }
        $stmt->close();
      }
    } catch (Throwable $t) {
      // best-effort only
    }
  }

  tracking_send_json($connection, [
    'success' => true,
    'doc_id' => $docId,
    'total_pages' => count($pages),
    'pages' => $pages,
  ]);
}

// Resolve a tracking row by stable identity (mobile_timestamp/doc_hash) to help mobile routing
// Usage: tracking.php?action=resolve_identity&mobile_timestamp=... or &doc_hash=... or &file_path=...
if (isset($_GET['action']) && $_GET['action'] === 'resolve_identity') {
  $mobileTs = isset($_GET['mobile_timestamp']) ? trim((string)$_GET['mobile_timestamp']) : '';
  $docHash = isset($_GET['doc_hash']) ? trim((string)$_GET['doc_hash']) : '';
  $filePath = isset($_GET['file_path']) ? trim((string)$_GET['file_path']) : '';

  $debug = [
    'mobile_timestamp' => $mobileTs,
    'doc_hash' => $docHash,
    'file_path' => $filePath,
    'matched_by' => null,
  ];

  $id = 0;
  if ($mobileTs !== '') {
    // Try exact and common variants
    $candidates = [$mobileTs];
    if (preg_match('/(\d{10,13})/', $mobileTs, $m)) {
      $digits = $m[1];
      $candidates[] = $digits;
      $candidates[] = 'PDF_' . $digits;
      $candidates[] = 'GALLERY_' . $digits;
    }
    foreach ($candidates as $cand) {
      $cand = trim((string)$cand);
      if ($cand === '') continue;
      if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('s', $cand);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $id = (int)$row['id'];
            $debug['matched_by'] = 'mobile_timestamp';
            $res->free();
            $stmt->close();
            break;
          }
          if ($res) $res->free();
        }
        $stmt->close();
      }
    }
  }

  if ($id <= 0 && $docHash !== '') {
    if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE doc_hash = ? ORDER BY id DESC LIMIT 1")) {
      $stmt->bind_param('s', $docHash);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $id = (int)$row['id'];
          $debug['matched_by'] = 'doc_hash';
        }
        if ($res) $res->free();
      }
      $stmt->close();
    }
  }

  // Fallback: resolve by file name / file_path pattern
  if ($id <= 0 && $filePath !== '') {
    $base = basename($filePath);
    $base = trim((string)$base);
    if ($base !== '') {
      // Match either exact file_path or anywhere in file_path (handles uploads/archive/...)
      $like = '%' . $base . '%';
      if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE file_path = ? OR file_path LIKE ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('ss', $base, $like);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $id = (int)$row['id'];
            $debug['matched_by'] = 'file_path_like';
            $debug['file_basename'] = $base;
          }
          if ($res) $res->free();
        }
        $stmt->close();
      }
    }
  }

  if ($id <= 0) {
    tracking_send_json($connection, ['success' => false, 'error' => 'Not found']);
  }

  $doc = null;
  if ($stmt = $connection->prepare("SELECT id,type,date_submitted,created_at,current_holder,end_location,status,department,mobile_timestamp,doc_hash FROM tracking WHERE id = ? LIMIT 1")) {
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      if ($res) {
        $doc = $res->fetch_assoc();
        $res->free();
      }
    }
    $stmt->close();
  }
  tracking_send_json($connection, ['success' => true, 'doc' => $doc]);
}

// debug_doc endpoint — see consolidated version below in the file

// ---------------- Payroll Document Detection Function ----------------
function isPayrollDocument($fileName, $ocrContent = '') {
    // Payroll keywords to check for
    $payrollKeywords = [
        'payroll', 'salary', 'payslip', 'pay slip', 'wage', 'compensation',
        'employee pay', 'staff salary', 'monthly pay', 'net pay', 'gross pay',
        'deduction', 'withholding', 'tax', 'sss', 'philhealth', 'pagibig',
        'employee id', 'pay period', 'pay date', 'income', 'earnings'
    ];
    
    // Check filename (case insensitive)
    $fileNameLower = strtolower($fileName);
    foreach ($payrollKeywords as $keyword) {
        if (strpos($fileNameLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Check OCR content if provided (case insensitive)
    if (!empty($ocrContent)) {
        $ocrContentLower = strtolower($ocrContent);
        foreach ($payrollKeywords as $keyword) {
            if (strpos($ocrContentLower, $keyword) !== false) {
                return true;
            }

        }
    }
    
    return false;
}

// ---------------- Notification Read State (DB-backed via notifications_read) ----------------
$notif_user_id = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : (isset($_SESSION['username']) ? (string)$_SESSION['username'] : '');

if (isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0 && $notif_user_id !== '') {
        // Insert into notifications_read if not already present
        $stmt = $connection->prepare("INSERT IGNORE INTO notifications_read (user_id, tracking_id, read_at) VALUES (?, ?, NOW())");
        $stmt->bind_param('si', $notif_user_id, $id);
        $stmt->execute();
        $stmt->close();
    }
    session_write_close();
    tracking_send_json($connection, ['ok' => true, 'id' => $id]);
}

if (isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
    if ($notif_user_id !== '') {
        // Mark all currently visible tracking ids as read in the DB
        $res = $connection->query("SELECT id FROM tracking ORDER BY id DESC LIMIT 500");
        if ($res) {
            $stmt = $connection->prepare("INSERT IGNORE INTO notifications_read (user_id, tracking_id, read_at) VALUES (?, ?, NOW())");
            while ($r = $res->fetch_assoc()) {
                $tid = (int)$r['id'];
                $stmt->bind_param('si', $notif_user_id, $tid);
                $stmt->execute();
            }
            $stmt->close();
            $res->free();
        }
    }
    session_write_close();
    tracking_send_json($connection, ['ok' => true]);
}

if (isset($_GET['action']) && $_GET['action'] === 'clear_all_notifications') {
    if ($notif_user_id !== '') {
        // Mark ALL notifications as cleared by inserting a special sentinel row (tracking_id = 0)
        $stmt = $connection->prepare("INSERT INTO notifications_read (user_id, tracking_id, read_at) VALUES (?, 0, NOW()) ON DUPLICATE KEY UPDATE read_at = NOW()");
        $stmt->bind_param('s', $notif_user_id);
        $stmt->execute();
        $stmt->close();
        // Also mark all current tracking ids as read
        $res = $connection->query("SELECT id FROM tracking ORDER BY id DESC LIMIT 500");
        if ($res) {
            $stmt = $connection->prepare("INSERT IGNORE INTO notifications_read (user_id, tracking_id, read_at) VALUES (?, ?, NOW())");
            while ($r = $res->fetch_assoc()) {
                $tid = (int)$r['id'];
                $stmt->bind_param('si', $notif_user_id, $tid);
                $stmt->execute();
            }
            $stmt->close();
            $res->free();
        }
    }
    session_write_close();
    tracking_send_json($connection, ['ok' => true]);
}

if (isset($_GET['action']) && $_GET['action'] === 'unread_clear_notifications') {
    // Remove the "clear all" sentinel so notifications appear again
    if ($notif_user_id !== '') {
        $stmt = $connection->prepare("DELETE FROM notifications_read WHERE user_id = ? AND tracking_id = 0");
        $stmt->bind_param('s', $notif_user_id);
        $stmt->execute();
        $stmt->close();
    }
    session_write_close();
    tracking_send_json($connection, ['ok' => true]);
}

// Release session lock early for all other requests (read-only)
session_write_close();

// ---------------- Chart API: recent uploads range (daily/weekly) ----------------
if (isset($_GET['action']) && $_GET['action'] === 'recent_uploads_range') {
    $range = isset($_GET['range']) ? strtolower($_GET['range']) : 'daily';
    $labels = [];
    $counts = [];
    if ($range === 'weekly') {
        // Last 8 weeks counts (group by YEARWEEK)
        $sql = "SELECT YEARWEEK(COALESCE(created_at, date_submitted), 1) as yw, 
                       DATE_FORMAT(DATE_ADD(STR_TO_DATE(CONCAT(YEARWEEK(COALESCE(created_at, date_submitted), 1), ' Monday'), '%X%V %W'), INTERVAL 0 DAY), '%b %e') as wk,
                       COUNT(*) as c
                FROM tracking
                WHERE COALESCE(created_at, date_submitted) >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
                GROUP BY yw
                ORDER BY yw ASC";
        if ($res = $connection->query($sql)) {
            while ($row = $res->fetch_assoc()) { $labels[] = $row['wk']; $counts[] = (int)$row['c']; }
            $res->free();
        }
    } else {
        // Default daily last 7 days
        $sql = "SELECT DATE(COALESCE(created_at, date_submitted)) as d, COUNT(*) as c
                FROM tracking
                WHERE DATE(COALESCE(created_at, date_submitted)) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY d ORDER BY d ASC";
        // Initialize 7-day map
        $map = [];
        for ($i = 6; $i >= 0; $i--) { $map[date('Y-m-d', strtotime("-{$i} day"))] = 0; }
        if ($res = $connection->query($sql)) {
            while ($row = $res->fetch_assoc()) { $map[$row['d']] = (int)$row['c']; }
            $res->free();
        }
        foreach ($map as $d => $c) { $labels[] = date('M j', strtotime($d)); $counts[] = $c; }
    }
    tracking_send_json($connection, ['labels' => $labels, 'counts' => $counts, 'range' => $range]);
}

// Department distribution by month endpoint: pie data for a given month (YYYY-MM)
if (isset($_GET['action']) && $_GET['action'] === 'dept_distribution_by_month') {
    $ym = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
  $debugMode = false;
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        tracking_send_json($connection, ['labels' => [], 'counts' => [], 'error' => 'Invalid month format']);
    }

  // Prefer stats table for long-term reporting (doesn't depend on keeping rows in tracking)
  $useStats = false;
  if ($chk = $connection->query("SHOW TABLES LIKE 'stats'")) {
    $useStats = ($chk->num_rows > 0);
    $chk->free();
  }

  $debug = [
    'month' => $ym,
    'used_source' => null,
    'db_name' => (defined('DB_NAME') ? DB_NAME : null),
    'history_table' => false,
    'history_prepare_error' => null,
    'history_total_rows' => null,
    'history_rows_in_month' => null,
    'history_sample_depts' => [],
    'stats_table' => $useStats,
    'stats_date_column' => null,
    'stats_prepare_error' => null,
    'tracking_error' => null,
    'tracking_total_rows' => null,
    'stats_total_rows' => null,
    'tracking_rows_in_month' => null,
    'tracking_rows_with_any_dept' => null,
    'tracking_sample_depts' => [],
    'tracking_date_minmax' => null,
    'total' => 0,
  ];

  $labels = [];
  $counts = [];
  $colors = [];
  $palette = ['#00BCD4', '#26A69A', '#FFB300', '#8E24AA', '#FF7043', '#E91E63', '#9C27B0', '#3F51B5', '#009688', '#FF5722', '#6D4C41', '#5C6BC0'];
  $colorIndex = 0;

  // Prefer document_history create events for long-term reporting.
  // Dept-like value is stored in from_holder (preferred) or to_holder depending on the upload path.
  $useHistory = false;
  if ($chk = $connection->query("SHOW TABLES LIKE 'document_history'")) {
    $useHistory = ($chk->num_rows > 0);
    $chk->free();
  }
  $debug['history_table'] = $useHistory;

  if ($useHistory) {
    $deptExpr = "UPPER(TRIM(COALESCE(NULLIF(TRIM(from_holder), ''), NULLIF(TRIM(to_holder), ''))))";
    $sql = "SELECT {$deptExpr} AS dept, COUNT(*) AS c\n" .
           "FROM document_history\n" .
           "WHERE action = 'create' AND DATE_FORMAT(created_at, '%Y-%m') = ?\n" .
           "  AND {$deptExpr} IS NOT NULL\n" .
           "  AND LENGTH(TRIM({$deptExpr})) > 1\n" .
           "  AND {$deptExpr} NOT IN (\n" .
           "    'UNKNOWN','UNKNOWN DEPARTMENT','(UNKNOWN)',\n" .
           "    'N/A','(N/A)','NA','N\\A',\n" .
           "    'NONE','NULL','UNASSIGNED','TBD','—','--','-'\n" .
           "  )\n" .
           "GROUP BY {$deptExpr}\n" .
           "ORDER BY c DESC";

    $stmt = $connection->prepare($sql);
    if (!$stmt) {
      $debug['history_prepare_error'] = $connection->error;
    } else {
      $stmt->bind_param('s', $ym);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
          $dept = $row['dept'] ?? '';
          if ($dept === '') continue;
          $labels[] = strtoupper($dept);
          $counts[] = (int)$row['c'];
          $colors[] = $palette[$colorIndex % count($palette)];
          $colorIndex++;
        }
        if ($res) { $res->free(); }
        if (!empty($labels)) {
          $debug['used_source'] = 'document_history';
        }
      } else {
        $debug['history_prepare_error'] = $stmt->error;
      }
      $stmt->close();
    }
  }

  if (empty($labels) && $useStats) {
    // Support both legacy schema (stats.date) and newer schema (stats.date_archived)
    $hasDateArchived = false;
    $hasDate = false;
    if ($col = $connection->query("SHOW COLUMNS FROM stats LIKE 'date_archived'")) {
      $hasDateArchived = ($col->num_rows > 0);
      $col->free();
    }
    if ($col = $connection->query("SHOW COLUMNS FROM stats LIKE 'date'")) {
      $hasDate = ($col->num_rows > 0);
      $col->free();
    }

    $dateExpr = null;
    if ($hasDateArchived) {
      $dateExpr = "DATE_FORMAT(COALESCE(STR_TO_DATE(date_archived, '%Y-%m-%d %H:%i:%s'), STR_TO_DATE(date_archived, '%Y-%m-%d')), '%Y-%m')";
      $debug['stats_date_column'] = 'date_archived';
    } elseif ($hasDate) {
      $dateExpr = "DATE_FORMAT(`date`, '%Y-%m')";
      $debug['stats_date_column'] = 'date';
    } else {
      $useStats = false;
      $debug['stats_date_column'] = null;
    }

    if ($useStats && $dateExpr) {
      $sql = "SELECT UPPER(TRIM(department)) AS dept, COUNT(*) AS c\n" .
             "FROM stats\n" .
             "WHERE {$dateExpr} = ?\n" .
             "  AND department IS NOT NULL\n" .
             "  AND LENGTH(TRIM(department)) > 1\n" .
             "  AND UPPER(TRIM(department)) NOT IN (\n" .
             "    'UNKNOWN','UNKNOWN DEPARTMENT','(UNKNOWN)',\n" .
             "    'N/A','(N/A)','NA','N\\A',\n" .
             "    'NONE','NULL','UNASSIGNED','TBD','—','--','-'\n" .
             "  )\n" .
             "GROUP BY UPPER(TRIM(department))\n" .
             "ORDER BY c DESC";

      $stmt = $connection->prepare($sql);
      if (!$stmt) {
        $debug['stats_prepare_error'] = $connection->error;
      } else {
        $stmt->bind_param('s', $ym);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          while ($res && ($row = $res->fetch_assoc())) {
            $dept = $row['dept'] ?? '';
            if ($dept === '') continue;
            $labels[] = strtoupper($dept);
            $counts[] = (int)$row['c'];
            $colors[] = $palette[$colorIndex % count($palette)];
            $colorIndex++;
          }
          if ($res) { $res->free(); }
        } else {
          $debug['stats_prepare_error'] = $stmt->error;
        }
        $stmt->close();
      }
    }
  }

  // Fallback: tracking table (legacy)
  if (empty($labels)) {
    // Many records store the department-like value in current_holder; fall back when department is empty.
    // dept_value = department if present else current_holder
    $deptExpr = "UPPER(TRIM(COALESCE(NULLIF(TRIM(department), ''), NULLIF(TRIM(current_holder), ''))))";
    $sql = "SELECT {$deptExpr} AS dept, COUNT(*) AS c
        FROM tracking
        WHERE DATE_FORMAT(COALESCE(created_at, date_submitted), '%Y-%m') = '" . $connection->real_escape_string($ym) . "'
          AND {$deptExpr} IS NOT NULL
          AND LENGTH(TRIM({$deptExpr})) > 1
          AND {$deptExpr} NOT IN (
          'UNKNOWN','UNKNOWN DEPARTMENT','(UNKNOWN)',
          'N/A','(N/A)','NA','N\\A',
          'NONE','NULL','UNASSIGNED','TBD','—','--','-'
          )
        GROUP BY {$deptExpr}
        ORDER BY c DESC";

    if ($res = $connection->query($sql)) {
      while ($row = $res->fetch_assoc()) {
        $dept = $row['dept'] ?? '';
        if ($dept === '') continue;
        $labels[] = strtoupper($dept);
        $counts[] = (int)$row['c'];
        $colors[] = $palette[$colorIndex % count($palette)];
        $colorIndex++;
      }
      $res->free();
      $debug['used_source'] = 'tracking';
    }
    if (!$res) {
      $debug['tracking_error'] = $connection->error;
    }
  }

  // Extra debug: explain why this month is empty
  if ($debugMode) {
    // Total rows sanity checks
    if ($useHistory && ($r = $connection->query("SELECT COUNT(*) AS c FROM document_history"))) {
      $row = $r->fetch_assoc();
      $debug['history_total_rows'] = (int)($row['c'] ?? 0);
      $r->free();
    }
    if ($r = $connection->query("SELECT COUNT(*) AS c FROM tracking")) {
      $row = $r->fetch_assoc();
      $debug['tracking_total_rows'] = (int)($row['c'] ?? 0);
      $r->free();
    }
    if ($useStats && ($r = $connection->query("SELECT COUNT(*) AS c FROM stats"))) {
      $row = $r->fetch_assoc();
      $debug['stats_total_rows'] = (int)($row['c'] ?? 0);
      $r->free();
    }

    // Count total rows in month by created_at primarily
    $ymEsc = $connection->real_escape_string($ym);
    if ($useHistory && ($r = $connection->query("SELECT COUNT(*) AS c FROM document_history WHERE action='create' AND DATE_FORMAT(created_at, '%Y-%m') = '{$ymEsc}'"))) {
      $row = $r->fetch_assoc();
      $debug['history_rows_in_month'] = (int)($row['c'] ?? 0);
      $r->free();
    }
    if ($useHistory && ($r = $connection->query("SELECT DISTINCT UPPER(TRIM(COALESCE(NULLIF(TRIM(from_holder), ''), NULLIF(TRIM(to_holder), '')))) AS d FROM document_history WHERE action='create' AND DATE_FORMAT(created_at, '%Y-%m') = '{$ymEsc}' AND COALESCE(NULLIF(TRIM(from_holder), ''), NULLIF(TRIM(to_holder), '')) IS NOT NULL LIMIT 8"))) {
      while ($row = $r->fetch_assoc()) {
        if (!empty($row['d'])) $debug['history_sample_depts'][] = $row['d'];
      }
      $r->free();
    }
    if ($r = $connection->query("SELECT COUNT(*) AS c FROM tracking WHERE DATE_FORMAT(COALESCE(created_at, date_submitted), '%Y-%m') = '{$ymEsc}'")) {
      $row = $r->fetch_assoc();
      $debug['tracking_rows_in_month'] = (int)($row['c'] ?? 0);
      $r->free();
    }
    if ($r = $connection->query("SELECT COUNT(*) AS c FROM tracking WHERE DATE_FORMAT(COALESCE(created_at, date_submitted), '%Y-%m') = '{$ymEsc}' AND COALESCE(NULLIF(TRIM(department),''), NULLIF(TRIM(current_holder),'')) IS NOT NULL AND LENGTH(TRIM(COALESCE(NULLIF(TRIM(department),''), NULLIF(TRIM(current_holder),'')))) > 1")) {
      $row = $r->fetch_assoc();
      $debug['tracking_rows_with_any_dept'] = (int)($row['c'] ?? 0);
      $r->free();
    }
    if ($r = $connection->query("SELECT MIN(COALESCE(created_at, date_submitted)) AS min_d, MAX(COALESCE(created_at, date_submitted)) AS max_d FROM tracking")) {
      $row = $r->fetch_assoc();
      $debug['tracking_date_minmax'] = ['min' => $row['min_d'] ?? null, 'max' => $row['max_d'] ?? null];
      $r->free();
    }
    if ($r = $connection->query("SELECT DISTINCT UPPER(TRIM(COALESCE(NULLIF(TRIM(department),''), NULLIF(TRIM(current_holder),'')))) AS d FROM tracking WHERE DATE_FORMAT(COALESCE(created_at, date_submitted), '%Y-%m') = '{$ymEsc}' AND COALESCE(NULLIF(TRIM(department),''), NULLIF(TRIM(current_holder),'')) IS NOT NULL LIMIT 8")) {
      while ($row = $r->fetch_assoc()) {
        if (!empty($row['d'])) $debug['tracking_sample_depts'][] = $row['d'];
      }
      $r->free();
    }
  }

  if (!empty($labels)) {
    $debug['used_source'] = $debug['used_source'] ?? ($useStats ? 'stats' : 'tracking');
  }
  $debug['total'] = array_sum($counts);

  $payload = ['labels' => $labels, 'counts' => $counts, 'colors' => $colors, 'month' => $ym];
  if ($debugMode) {
    $payload['debug'] = $debug;
  }
  tracking_send_json($connection, $payload);
}

// Recent uploads by month endpoint: returns daily counts for a given month (YYYY-MM)
if (isset($_GET['action']) && $_GET['action'] === 'recent_uploads_by_month') {
    $ym = isset($_GET['month']) ? $_GET['month'] : date('Y-m'); // expected format YYYY-MM

    // Validate format YYYY-MM
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        tracking_send_json($connection, [ 'labels' => [], 'counts' => [], 'error' => 'Invalid month format' ]);
    }

    // Build map of all days in month with 0 counts
    $startDate = DateTime::createFromFormat('Y-m-d', $ym . '-01');
    if (!$startDate) {
        tracking_send_json($connection, [ 'labels' => [], 'counts' => [], 'error' => 'Invalid month' ]);
    }
    $daysInMonth = (int)$startDate->format('t');
    $dateMap = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $key = sprintf('%s-%02d', $ym, $d);
        $dateMap[$key] = 0;
    }

    // Prefer document_history create events for persistence (works even if tracking rows are later archived/removed)
    $useHistory = false;
    if ($chk = $connection->query("SHOW TABLES LIKE 'document_history'")) {
      $useHistory = ($chk->num_rows > 0);
      $chk->free();
    }

    if ($useHistory) {
      $sql = "SELECT DATE(created_at) AS d, COUNT(*) AS c\n" .
           "FROM document_history\n" .
           "WHERE action = 'create' AND DATE_FORMAT(created_at, '%Y-%m') = ?\n" .
           "GROUP BY DATE(created_at)";
      if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('s', $ym);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          while ($res && ($row = $res->fetch_assoc())) {
            $key = $row['d'];
            if (isset($dateMap[$key])) {
              $dateMap[$key] = (int)$row['c'];
            }
          }
          if ($res) { $res->free(); }
        }
        $stmt->close();
      }

      // If history table exists but doesn't have create events, fall back to tracking
      $sum = array_sum($dateMap);
      if ($sum === 0) {
        $sql = "SELECT DATE(COALESCE(created_at, date_submitted)) AS d, COUNT(*) AS c
            FROM tracking
            WHERE DATE_FORMAT(COALESCE(created_at, date_submitted), '%Y-%m') = '" . $connection->real_escape_string($ym) . "'
            GROUP BY d";
        if ($res = $connection->query($sql)) {
          while ($row = $res->fetch_assoc()) {
            $key = $row['d'];
            if (isset($dateMap[$key])) {
              $dateMap[$key] = (int)$row['c'];
            }
          }
          $res->free();
        }
      }
    } else {
      // Fallback: tracking table
      $sql = "SELECT DATE(COALESCE(created_at, date_submitted)) AS d, COUNT(*) AS c
          FROM tracking
          WHERE DATE_FORMAT(COALESCE(created_at, date_submitted), '%Y-%m') = '" . $connection->real_escape_string($ym) . "'
          GROUP BY d";
      if ($res = $connection->query($sql)) {
        while ($row = $res->fetch_assoc()) {
          $key = $row['d'];
          if (isset($dateMap[$key])) {
            $dateMap[$key] = (int)$row['c'];
          }
        }
        $res->free();
      }
    }

    // Build labels like 'Jul 5'
    $labels = [];
    $counts = [];
    foreach ($dateMap as $dateStr => $count) {
        $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
        $labels[] = $dt ? $dt->format('M j') : $dateStr;
        $counts[] = $count;
    }

    tracking_send_json($connection, [ 'labels' => $labels, 'counts' => $counts, 'month' => $ym ]);
}

// Sidebar stats endpoint: total documents count and archived today count
if (isset($_GET['action']) && $_GET['action'] === 'sidebar_stats') {
    
    // Department-scoped count: non-admin users should see only documents relevant to their department
    $__ssDeptWhere = '';
    $__ssDeptTypes = '';
    $__ssDeptParams = [];
    if (!$__isAdmin && !empty($_SESSION['user_department'])) {
        $__ssud = strtoupper(trim($_SESSION['user_department']));
        $__ssDeptWhere = ' WHERE (UPPER(TRIM(department)) = ? OR UPPER(TRIM(current_holder)) = ? OR UPPER(TRIM(end_location)) = ? OR (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(routing_queue, \' \', \'\'))) > 0 AND CAST(COALESCE(route_step, 0) AS UNSIGNED) >= (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(routing_queue, \' \', \'\'))) - 1)))';
        $__ssDeptTypes = 'sssss';
        $__ssDeptParams = [$__ssud, $__ssud, $__ssud, $__ssud, $__ssud];
    }

    $pendingCount = 0;
    $sqlPending = "SELECT COUNT(*) as count FROM tracking" . $__ssDeptWhere;
    if ($__ssDeptTypes !== '') {
        if ($stmtP = $connection->prepare($sqlPending)) {
            $bindP = [$__ssDeptTypes];
            foreach ($__ssDeptParams as &$vp) { $bindP[] = &$vp; }
            call_user_func_array([$stmtP, 'bind_param'], $bindP);
            if ($stmtP->execute()) {
                $resP = $stmtP->get_result();
                if ($rowP = $resP->fetch_assoc()) $pendingCount = (int)$rowP['count'];
                if ($resP) $resP->free();
            }
            $stmtP->close();
        }
    } else {
        if ($resPending = $connection->query($sqlPending)) {
            $row = $resPending->fetch_assoc();
            $pendingCount = (int)$row['count'];
            $resPending->free();
        }
    }
    
    // Count archived today
    $today = date('Y-m-d');
    $sqlArchived = "SELECT COUNT(*) as count FROM tracking WHERE status = 'Archived' AND DATE(COALESCE(created_at, date_submitted)) = '$today'";
    $archivedToday = 0;
    if ($resArchived = $connection->query($sqlArchived)) {
        $row = $resArchived->fetch_assoc();
        $archivedToday = (int)$row['count'];
        $resArchived->free();
    }
    
    tracking_send_json($connection, ['pending_count' => $pendingCount, 'archived_today' => $archivedToday]);
}

// Debug endpoint: inspect tracking + latest history + latest notifications for a document id
if (false && isset($_GET['action']) && $_GET['action'] === 'debug_doc') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid id']);
    }

  $out = [
    'success' => true,
    'id' => $id,
    'tracking' => null,
    'latest_history' => null,
    'notifications' => [],
    'debug_context' => [
      'is_admin' => (bool)$__isAdmin,
      'session_user_department' => (string)($_SESSION['user_department'] ?? ''),
      'session_role' => (string)($_SESSION['role'] ?? ''),
    ],
    'visibility_eval' => null,
  ];

  if ($stmt = $connection->prepare("SELECT id,type,employee_name,department,current_holder,end_location,status,date_submitted,created_at,mobile_timestamp,file_path,doc_hash,routing_queue,route_step FROM tracking WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res) {
                $out['tracking'] = $res->fetch_assoc();
                $res->free();
            }
        }
        $stmt->close();
    }

  if (is_array($out['tracking'])) {
    $dbgDeptRaw = (string)($_SESSION['user_department'] ?? '');
    $dbgDept = strtoupper(trim($dbgDeptRaw));

    $rowDept   = strtoupper(trim((string)($out['tracking']['department'] ?? '')));
    $rowHolder = strtoupper(trim((string)($out['tracking']['current_holder'] ?? '')));
    $rowEndLoc = strtoupper(trim((string)($out['tracking']['end_location'] ?? '')));
    $rowQueue  = strtoupper(trim((string)($out['tracking']['routing_queue'] ?? '')));
    $rowStep   = (int)($out['tracking']['route_step'] ?? 0);

    $queueParts = [];
    if ($rowQueue !== '') {
      $queueParts = array_values(array_filter(array_map(function ($s) {
        return strtoupper(trim((string)$s));
      }, explode(',', $rowQueue)), function ($s) {
        return $s !== '';
      }));
    }

    $pos = ($dbgDept !== '') ? array_search($dbgDept, $queueParts, true) : false;
    $inQueue = ($pos !== false);
    $stepAllows = $inQueue ? ($rowStep >= (int)$pos) : false;

    $matchDepartment = ($dbgDept !== '' && $rowDept === $dbgDept);
    $matchHolder = ($dbgDept !== '' && $rowHolder === $dbgDept);
    $matchEndLocation = ($dbgDept !== '' && $rowEndLoc === $dbgDept);
    $visibleByRule = ($matchDepartment || $matchHolder || $matchEndLocation || ($inQueue && $stepAllows));

    $out['visibility_eval'] = [
      'debug_department' => $dbgDept,
      'row_department' => $rowDept,
      'row_current_holder' => $rowHolder,
      'row_end_location' => $rowEndLoc,
      'row_routing_queue' => $rowQueue,
      'row_routing_queue_list' => $queueParts,
      'row_route_step' => $rowStep,
      'match_department' => $matchDepartment,
      'match_current_holder' => $matchHolder,
      'match_end_location' => $matchEndLocation,
      'in_routing_queue' => $inQueue,
      'routing_queue_position_zero_based' => ($pos !== false) ? (int)$pos : null,
      'route_step_allows_visibility' => $stepAllows,
      'visible_by_department_rule' => $visibleByRule,
      'visible_for_current_session' => ((bool)$__isAdmin || $visibleByRule),
    ];
  }

    // Latest history (if table exists)
    try {
        if ($stmtH = $connection->prepare("SELECT id,doc_id,action,from_holder,to_holder,from_status,to_status,notes,created_at FROM document_history WHERE doc_id = ? ORDER BY id DESC LIMIT 1")) {
            $stmtH->bind_param('i', $id);
            if ($stmtH->execute()) {
                $resH = $stmtH->get_result();
                if ($resH) {
                    $out['latest_history'] = $resH->fetch_assoc();
                    $resH->free();
                }
            }
            $stmtH->close();
        }
    } catch (Throwable $t) {
        $out['latest_history_error'] = $t->getMessage();
    }

    // Recent notifications for this tracking_id (if table exists)
    try {
        if ($stmtN = $connection->prepare("SELECT id,type,recipient_username,recipient_department,sender_username,department,status,file_url,tracking_id,mobile_timestamp,end_location,current_holder,doc_status,created_at FROM notifications WHERE tracking_id = ? ORDER BY id DESC LIMIT 10")) {
            $stmtN->bind_param('i', $id);
            if ($stmtN->execute()) {
                $resN = $stmtN->get_result();
                while ($resN && ($r = $resN->fetch_assoc())) {
                    $out['notifications'][] = $r;
                }
                if ($resN) $resN->free();
            }
            $stmtN->close();
        }
    } catch (Throwable $t) {
        $out['notifications_error'] = $t->getMessage();
    }

    tracking_send_json($connection, $out);
}

// Notifications JSON endpoint: latest uploaded/updated documents
if (isset($_GET['action']) && $_GET['action'] === 'notifications') {
    $items = [];
    $unreadCount = 0;
    $notif_uid = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : (isset($_SESSION['username']) ? (string)$_SESSION['username'] : '');

    // Check if user has "cleared all" (sentinel row with tracking_id = 0)
    $isCleared = false;
    $clearedAt = null;
    if ($notif_uid !== '') {
        $stmtC = $connection->prepare("SELECT read_at FROM notifications_read WHERE user_id = ? AND tracking_id = 0 LIMIT 1");
        $stmtC->bind_param('s', $notif_uid);
        $stmtC->execute();
        $stmtC->bind_result($clearedAt);
        if ($stmtC->fetch()) { $isCleared = true; }
        $stmtC->close();
    }

    // Build lookup of read tracking IDs from DB
    $readIds = [];
    if ($notif_uid !== '') {
        $stmtR = $connection->prepare("SELECT tracking_id FROM notifications_read WHERE user_id = ? AND tracking_id > 0");
        $stmtR->bind_param('s', $notif_uid);
        $stmtR->execute();
        $resR = $stmtR->get_result();
        while ($rr = $resR->fetch_assoc()) { $readIds[(int)$rr['tracking_id']] = true; }
        $stmtR->close();
    }

    // Latest by created_at if present, else by id - include mobile_timestamp to detect mobile uploads
    // Department-scoped: non-admin users only see notifications for their department
    if (!$__isAdmin && !empty($_SESSION['user_department'])) {
        $__notifDept = strtoupper(trim($_SESSION['user_department']));
        $__notifDeptEsc = $connection->real_escape_string($__notifDept);
        $sqlN = "SELECT id, employee_name, department, type, status, created_at, date_submitted, mobile_timestamp, current_holder FROM tracking WHERE UPPER(TRIM(department)) = '$__notifDeptEsc' ORDER BY COALESCE(created_at, date_submitted) DESC, id DESC LIMIT 15";
    } else {
        $sqlN = "SELECT id, employee_name, department, type, status, created_at, date_submitted, mobile_timestamp, current_holder FROM tracking ORDER BY COALESCE(created_at, date_submitted) DESC, id DESC LIMIT 15";
    }
    if ($resN = $connection->query($sqlN)) {
        while ($r = $resN->fetch_assoc()) {
            $when = !empty($r['created_at']) ? $r['created_at'] : ($r['date_submitted'] ?? '');
            $isMobile = !empty($r['mobile_timestamp']);
            $icon = $isMobile ? 'fa-mobile-alt' : 'fa-file-alt';
            $source = $isMobile ? 'Mobile Upload' : 'Web Upload';

            $tid = (int)($r['id'] ?? 0);
            // If cleared, only show items created AFTER the cleared time as unread
            $isRead = isset($readIds[$tid]);
            if ($isCleared && !$isRead) {
                $itemTime = $when ? strtotime($when) : 0;
                $clearTime = $clearedAt ? strtotime($clearedAt) : 0;
                if ($itemTime <= $clearTime) {
                    $isRead = true;
                }
            }
            $unread = !$isRead;
            if ($unread) { $unreadCount++; }
            
            // Format time as relative (e.g., "2 min ago")
            $timeAgo = '';
            if ($when) {
                $timestamp = strtotime($when);
                $diff = time() - $timestamp;
                if ($diff < 60) {
                    $timeAgo = 'Just now';
                } elseif ($diff < 3600) {
                    $timeAgo = floor($diff / 60) . ' min ago';
                } elseif ($diff < 86400) {
                    $timeAgo = floor($diff / 3600) . ' hr ago';
                } else {
                    $timeAgo = floor($diff / 86400) . ' day(s) ago';
                }
            }
            
            $items[] = [
                'id' => $r['id'],
                'title' => $r['type'] . ' - ' . $r['employee_name'],
                'content' => $source . ' • ' . $r['department'] . ' • ' . $r['status'],
                'time' => $timeAgo,
                'icon' => $icon,
                'isMobile' => $isMobile,
                'unread' => $unread,
                'department' => $r['department'] ?? '',
                'doc_status' => $r['status'] ?? '',
                'current_holder' => $r['current_holder'] ?? '',
            ];
        }
        $resN->free();
    }

    // If cleared and no new unread items, return empty list
    if ($isCleared && $unreadCount === 0) {
        tracking_send_json($connection, ['notifications' => [], 'count' => 0, 'cleared' => true]);
    } else {
        // If cleared but there are new items, remove the sentinel so full list shows
        if ($isCleared && $unreadCount > 0) {
            $stmtDel = $connection->prepare("DELETE FROM notifications_read WHERE user_id = ? AND tracking_id = 0");
            $stmtDel->bind_param('s', $notif_uid);
            $stmtDel->execute();
            $stmtDel->close();
        }
        tracking_send_json($connection, ['notifications' => $items, 'count' => $unreadCount]);
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'tracking_latest') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    if ($limit <= 0) $limit = 200;
    if ($limit > 500) $limit = 500;
    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;

    // ── ETag / 304 Not Modified optimisation ──
    // Build a lightweight fingerprint from max-id + row-count + latest status change
    // so polling clients skip the full payload when nothing changed.
    $etagParts = [];
    $etagRow = null;
    if ($etagRes = $connection->query("SELECT MAX(id) AS max_id, COUNT(*) AS cnt FROM tracking")) {
        $etagRow = $etagRes->fetch_assoc();
        $etagParts[] = ($etagRow['max_id'] ?? '0') . '-' . ($etagRow['cnt'] ?? '0');
        $etagRes->free();
    }
    // Also hash on the latest updated timestamp so status changes invalidate the ETag
    if ($etagTs = $connection->query("SELECT MAX(COALESCE(created_at, date_submitted)) AS latest FROM tracking")) {
        $tsRow = $etagTs->fetch_assoc();
        $etagParts[] = ($tsRow['latest'] ?? '');
        $etagTs->free();
    }
    // Include latest document_history id — every status change/forward/receive logs a history row,
    // so this ensures the ETag invalidates even when only the status of an existing row changes.
    try {
        if ($etagH = $connection->query("SELECT MAX(id) AS hmax FROM document_history")) {
            $hRow = $etagH->fetch_assoc();
            $etagParts[] = 'h' . ($hRow['hmax'] ?? '0');
            $etagH->free();
        }
    } catch (Throwable $t) { /* table might not exist yet */ }
    $etag = '"trk-' . md5(implode('|', $etagParts) . '|' . $limit . '|' . $sinceId) . '"';
    if (!headers_sent()) {
        header('ETag: ' . $etag);
        header('Cache-Control: no-cache'); // allow conditional requests
    }
    $clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($clientEtag === $etag) {
        http_response_code(304);
        if ($connection && $connection->ping()) { $connection->close(); }
        exit();
    }

    $calcOverdue = function ($status, $createdAt, $dateSubmitted) {
        $statusNorm = strtolower(trim((string)$status));
        if (in_array($statusNorm, ['archived', 'completed', 'approved'], true)) {
            return ['label' => 'Cleared', 'full' => 'Cleared', 'state' => 'cleared', 'seconds' => 0];
        }

        $source = $createdAt ?: $dateSubmitted;
        $ts = $source ? strtotime((string)$source) : false;
        if (!$ts) {
            return ['label' => '—', 'full' => 'No timestamp available', 'state' => 'na', 'seconds' => 0];
        }

        $diffSeconds = max(0, time() - (int)$ts);
        $minutes = intdiv($diffSeconds, 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        $shortParts = [];
        if ($days > 0) { $shortParts[] = $days . 'd'; }
        if ($hours > 0 && count($shortParts) < 2) { $shortParts[] = $hours . 'h'; }
        if ($days === 0 && $mins > 0 && count($shortParts) < 2) { $shortParts[] = $mins . 'm'; }
        if (empty($shortParts)) { $shortParts[] = '<1m'; }

        $fullParts = [];
        if ($days > 0) { $fullParts[] = $days . ' day' . ($days === 1 ? '' : 's'); }
        if ($hours > 0) { $fullParts[] = $hours . ' hour' . ($hours === 1 ? '' : 's'); }
        if ($mins > 0) { $fullParts[] = $mins . ' minute' . ($mins === 1 ? '' : 's'); }
        if (empty($fullParts)) { $fullParts[] = 'Less than a minute'; }

        $state = 'ok';
        if ($diffSeconds >= 5 * 86400) {
            $state = 'late';
        } elseif ($diffSeconds >= 4 * 86400) {
            $state = 'warn';
        }

        return [
            'label' => implode(' ', $shortParts),
            'full' => implode(' ', $fullParts),
            'state' => $state,
            'seconds' => $diffSeconds,
        ];
    };

    // Department-scoped filtering for tracking_latest (mirrors main query logic)
    // Uses route_step gating: dept must be in routing_queue AND route_step >= dept position
    $__latestDeptWhere = '';
    $__latestDeptTypes = '';
    $__latestDeptParams = [];
    if (!$__isAdmin && !empty($_SESSION['user_department'])) {
        $__lud = strtoupper(trim($_SESSION['user_department']));
        $__latestDeptWhere = ' AND (UPPER(TRIM(department)) = ? OR UPPER(TRIM(current_holder)) = ? OR UPPER(TRIM(end_location)) = ? OR (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(routing_queue, \' \', \'\'))) > 0 AND CAST(COALESCE(route_step, 0) AS UNSIGNED) >= (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(routing_queue, \' \', \'\'))) - 1)))';
        $__latestDeptTypes = 'sssss';
        $__latestDeptParams = [$__lud, $__lud, $__lud, $__lud, $__lud];
    }

    $docs = [];
    if ($sinceId > 0) {
        $sql = "SELECT id,type,employee_name,department,current_holder,end_location,status,date_submitted,created_at,mobile_timestamp,file_type_icon,file_size,file_path,doc_hash,routing_queue,route_step FROM tracking WHERE id > ?" . $__latestDeptWhere . " ORDER BY id DESC LIMIT ?";
        if ($stmt = $connection->prepare($sql)) {
            $types = 'i' . $__latestDeptTypes . 'i';
            $params = array_merge([$sinceId], $__latestDeptParams, [$limit]);
            $bind = [$types];
            foreach ($params as $k => &$v) { $bind[] = &$v; }
            call_user_func_array([$stmt, 'bind_param'], $bind);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $meta = $calcOverdue($r['status'] ?? '', $r['created_at'] ?? null, $r['date_submitted'] ?? null);
                    $r['overdue_label'] = $meta['label'];
                    $r['overdue_full_label'] = $meta['full'];
                    $r['overdue_state'] = $meta['state'];
                    $r['overdue_seconds'] = $meta['seconds'];
                    $docs[] = $r;
                }
                if ($res) $res->free();
            }
            $stmt->close();
        }
    } else {
        $sql = "SELECT id,type,employee_name,department,current_holder,end_location,status,date_submitted,created_at,mobile_timestamp,file_type_icon,file_size,file_path,doc_hash,routing_queue,route_step FROM tracking WHERE 1=1" . $__latestDeptWhere . " ORDER BY id DESC LIMIT ?";
        if ($stmt = $connection->prepare($sql)) {
            $types = $__latestDeptTypes . 'i';
            $params = array_merge($__latestDeptParams, [$limit]);
            if ($types === 'i') {
                $stmt->bind_param('i', $params[0]);
            } else {
                $bind = [$types];
                foreach ($params as $k => &$v) { $bind[] = &$v; }
                call_user_func_array([$stmt, 'bind_param'], $bind);
            }
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($res && ($r = $res->fetch_assoc())) {
                    $meta = $calcOverdue($r['status'] ?? '', $r['created_at'] ?? null, $r['date_submitted'] ?? null);
                    $r['overdue_label'] = $meta['label'];
                    $r['overdue_full_label'] = $meta['full'];
                    $r['overdue_state'] = $meta['state'];
                    $r['overdue_seconds'] = $meta['seconds'];
                    $docs[] = $r;
                }
                if ($res) $res->free();
            }
            $stmt->close();
        }
    }

    tracking_send_json($connection, ['success' => true, 'docs' => $docs]);
}

// Lightweight endpoint: mark a tracking row as "Received" when a receiver
// acknowledges receipt of a document from Recent Activity or dashboard.
// Supports: ?action=mark_received&id=123 OR ?action=mark_received&type=Memo&end_location=CTO
// Also supports legacy ?action=mark_in_review for backwards compatibility
if (isset($_GET['action']) && ($_GET['action'] === 'mark_received' || $_GET['action'] === 'mark_in_review')) {
    // Set to true to enable verbose error_log output for debugging
    $debug_logging = false;
    
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $notification_id = isset($_GET['notification_id']) ? (int)$_GET['notification_id'] : 0;
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $end_location = isset($_GET['end_location']) ? trim($_GET['end_location']) : '';
    $receiver_department = isset($_GET['receiver_department']) ? trim($_GET['receiver_department']) : '';
    
    // If the client only has a notification id, resolve the tracking id from notifications.
    // This is the most reliable fallback because notifications store tracking_id.
    if ($id <= 0 && $notification_id > 0) {
        if ($debug_logging) error_log("[mark_in_review] Resolving tracking_id from notification_id=$notification_id");
        $nsel = $connection->prepare("SELECT tracking_id, title, type, end_location, current_holder, mobile_timestamp FROM notifications WHERE id = ? LIMIT 1");
        if ($nsel) {
            $nsel->bind_param('i', $notification_id);
            if ($nsel->execute()) {
                $nres = $nsel->get_result();
                if ($nres && ($nrow = $nres->fetch_assoc())) {
                    $tid = isset($nrow['tracking_id']) ? (int)$nrow['tracking_id'] : 0;
                    if ($debug_logging) error_log("[mark_in_review] notification_id=$notification_id found: tracking_id=$tid, title=" . ($nrow['title'] ?? '') . ", type=" . ($nrow['type'] ?? '') . ", end_location=" . ($nrow['end_location'] ?? '') . ", current_holder=" . ($nrow['current_holder'] ?? ''));
                    if ($tid > 0) {
                        $id = $tid;
                        if ($debug_logging) error_log("[mark_in_review] Resolved id=$id from notification_id=$notification_id");
                    } else {
                        if ($debug_logging) error_log("[mark_in_review] notification tracking_id is NULL, trying to resolve from notification fields");
                        // tracking_id is NULL — try to find tracking row using notification fields
                        $nTitle = trim($nrow['title'] ?? '');
                        $nEnd = trim($nrow['end_location'] ?? '');
                        $nHolder = trim($nrow['current_holder'] ?? '');
                        $nMobileTs = trim($nrow['mobile_timestamp'] ?? '');
                        
                        // Try by mobile_timestamp first (most unique)
                        if ($id <= 0 && $nMobileTs !== '') {
                            $fq = $connection->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1");
                            if ($fq) {
                                $fq->bind_param('s', $nMobileTs);
                                if ($fq->execute()) {
                                    $fr = $fq->get_result();
                                    if ($fr && ($frow = $fr->fetch_assoc())) {
                                        $id = (int)$frow['id'];
                                        if ($debug_logging) error_log("[mark_in_review] Resolved id=$id from notification mobile_timestamp=$nMobileTs");
                                    }
                                }
                                $fq->close();
                            }
                        }
                        
                        // Try by title (doc type) + current_holder
                        if ($id <= 0 && $nTitle !== '' && $nHolder !== '') {
                            $fq2 = $connection->prepare("SELECT id FROM tracking WHERE type = ? AND current_holder = ? ORDER BY id DESC LIMIT 1");
                            if ($fq2) {
                                $fq2->bind_param('ss', $nTitle, $nHolder);
                                if ($fq2->execute()) {
                                    $fr2 = $fq2->get_result();
                                    if ($fr2 && ($frow2 = $fr2->fetch_assoc())) {
                                        $id = (int)$frow2['id'];
                                        if ($debug_logging) error_log("[mark_in_review] Resolved id=$id from notification title=$nTitle + current_holder=$nHolder");
                                    }
                                }
                                $fq2->close();
                            }
                        }
                        
                        // Try by title (doc type) + end_location
                        if ($id <= 0 && $nTitle !== '' && $nEnd !== '') {
                            $fq3 = $connection->prepare("SELECT id FROM tracking WHERE type = ? AND end_location = ? ORDER BY id DESC LIMIT 1");
                            if ($fq3) {
                                $fq3->bind_param('ss', $nTitle, $nEnd);
                                if ($fq3->execute()) {
                                    $fr3 = $fq3->get_result();
                                    if ($fr3 && ($frow3 = $fr3->fetch_assoc())) {
                                        $id = (int)$frow3['id'];
                                        if ($debug_logging) error_log("[mark_in_review] Resolved id=$id from notification title=$nTitle + end_location=$nEnd");
                                    }
                                }
                                $fq3->close();
                            }
                        }
                        
                        // Backfill tracking_id in the notification for future calls
                        if ($id > 0) {
                            $nfix = $connection->prepare("UPDATE notifications SET tracking_id = ? WHERE id = ? AND (tracking_id IS NULL OR tracking_id = 0)");
                            if ($nfix) {
                                $nfix->bind_param('ii', $id, $notification_id);
                                $nfix->execute();
                                $nfix->close();
                                if ($debug_logging) error_log("[mark_in_review] Backfilled tracking_id=$id into notification_id=$notification_id");
                            }
                        } else {
                            if ($debug_logging) error_log("[mark_in_review] WARNING: Could not resolve tracking row from notification fields");
                        }
                    }
                } else {
                    if ($debug_logging) error_log("[mark_in_review] WARNING: notification_id=$notification_id not found in notifications table");
                }
            }
            $nsel->close();
        }
    }

    // If no ID but have type+end_location, find the tracking row
    // Try multiple search strategies: end_location, current_holder, or department
    // IMPORTANT: Some notification types are generic (document_upload, upload, mobile_message)
    // and do NOT match the actual document type in the tracking table. Treat these as empty.
    $genericNotifTypes = ['document_upload', 'upload', 'mobile_message', 'notification', 'system_update'];
    $effectiveType = in_array(strtolower($type), $genericNotifTypes) ? '' : $type;
    
    if ($id <= 0 && $effectiveType !== '' && $end_location !== '') {
        // Mobile batch uploads store type as "<Type> (File X of Y)".
        // Accept either the exact type or a prefixed match.
        $typeLike = $effectiveType . ' (File%';

        // Strategy 1: Match by type + end_location
        $find = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND end_location = ? ORDER BY id DESC LIMIT 1");
        if ($find) {
            $find->bind_param('sss', $effectiveType, $typeLike, $end_location);
            if ($find->execute()) {
                $res = $find->get_result();
                if ($row = $res->fetch_assoc()) {
                    $id = (int)$row['id'];
                }
            }
            $find->close();
        }
        
        // Strategy 2: Match by type + current_holder (department receiving the doc)
        // IMPORTANT: use receiver_department if provided; end_location is the FINAL destination and may differ.
        if ($id <= 0) {
            $matchDept = $receiver_department !== '' ? $receiver_department : $end_location;
            $find2 = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND current_holder = ? ORDER BY id DESC LIMIT 1");
            if ($find2) {
                $find2->bind_param('sss', $effectiveType, $typeLike, $matchDept);
                if ($find2->execute()) {
                    $res2 = $find2->get_result();
                    if ($row2 = $res2->fetch_assoc()) {
                        $id = (int)$row2['id'];
                    }
                }
                $find2->close();
            }
        }
        
        // Strategy 3: Match by type + department (legacy)
        if ($id <= 0) {
            $matchDept = $receiver_department !== '' ? $receiver_department : $end_location;
            $find3 = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND department = ? ORDER BY id DESC LIMIT 1");
            if ($find3) {
                $find3->bind_param('sss', $effectiveType, $typeLike, $matchDept);
                if ($find3->execute()) {
                    $res3 = $find3->get_result();
                    if ($row3 = $res3->fetch_assoc()) {
                        $id = (int)$row3['id'];
                    }
                }
                $find3->close();
            }
        }
    }
    
    // Fallback strategy: when type was generic (document_upload etc.) and we still have no id,
    // try finding by current_holder = receiver_department with status 'Pending' (most recent routed doc)
    if ($id <= 0 && $receiver_department !== '') {
        if ($debug_logging) error_log("[mark_in_review] Trying broad fallback: current_holder='$receiver_department' with status Pending");
        $fb = $connection->prepare("SELECT id FROM tracking WHERE current_holder = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1");
        if ($fb) {
            $fb->bind_param('s', $receiver_department);
            if ($fb->execute()) {
                $res = $fb->get_result();
                if ($row = $res->fetch_assoc()) {
                    $id = (int)$row['id'];
                    if ($debug_logging) error_log("[mark_in_review] Broad fallback found id=$id for current_holder=$receiver_department");
                }
            }
            $fb->close();
        }
    }
    
    // Last resort: if notification_id was given but lookup returned no MySQL row,
    // try to find a tracking row whose notification had that notification_id via Firestore backfill
    // or by matching notification fields from the original routing notification.
    if ($id <= 0 && $notification_id > 0 && $receiver_department !== '') {
        if ($debug_logging) error_log("[mark_in_review] Last resort: searching tracking by notification fields for receiver=$receiver_department");
        // Find ANY pending document for this receiver department
        $lr = $connection->prepare("SELECT id FROM tracking WHERE (current_holder = ? OR department = ?) AND status IN ('Pending', 'Sent') ORDER BY id DESC LIMIT 1");
        if ($lr) {
            $lr->bind_param('ss', $receiver_department, $receiver_department);
            if ($lr->execute()) {
                $res = $lr->get_result();
                if ($row = $res->fetch_assoc()) {
                    $id = (int)$row['id'];
                    if ($debug_logging) error_log("[mark_in_review] Last resort found id=$id");
                }
            }
            $lr->close();
        }
    }
    
    if ($id <= 0) {
        if ($debug_logging) error_log("[mark_in_review] FAIL: id=$id not found. GET params: " . json_encode($_GET));
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid id or type/end_location not found']);
        exit;
    }

    if ($debug_logging) error_log("[mark_in_review] START: resolved id=$id, receiver_department=$receiver_department, type=$type, end_location=$end_location");

    // Get previous status, holder and type for history logging
    $prev_status = '';
    $prev_holder = '';
    $current_holder = '';
    $doc_type_row = '';
    $prev_end_location = '';

    $loadPrevRow = function(int $rowId) use (&$connection, &$prev_status, &$prev_holder, &$doc_type_row, &$prev_end_location, &$debug_logging) {
        $selPrev = $connection->prepare("SELECT status, current_holder, type, department, end_location FROM tracking WHERE id = ? LIMIT 1");
        if ($selPrev) {
            $selPrev->bind_param('i', $rowId);
            if ($selPrev->execute()) {
                $resPrev = $selPrev->get_result();
                if ($rowPrev = $resPrev->fetch_assoc()) {
                    $prev_status = trim($rowPrev['status'] ?? '');
                    $prev_holder = trim($rowPrev['current_holder'] ?? '');
                    $doc_type_row = trim($rowPrev['type'] ?? '');
                    $prev_end_location = trim($rowPrev['end_location'] ?? '');
                    if ($debug_logging) error_log("[mark_in_review] DB row id=$rowId: status='$prev_status', current_holder='$prev_holder', type='$doc_type_row', department='" . trim($rowPrev['department'] ?? '') . "', end_location='$prev_end_location'");
                } else {
                    if ($debug_logging) error_log("[mark_in_review] WARNING: id=$rowId exists but SELECT returned no row");
                }
            }
            $selPrev->close();
        }
    };

    $loadPrevRow($id);

    // The receiving department should be the department that pressed Receive.
    // If the client passes receiver_department, prefer it; else fallback to the stored current_holder.
    $current_holder = $receiver_department !== '' ? $receiver_department : $prev_holder;

    // IMPORTANT: Some announcements are duplicated across departments (one row per department).
    // Mobile notifications can point to a different department's row (e.g., HR) while the receiver is GSO.
    // For announcements, if receiver_department differs from the resolved row's holder, re-resolve the correct
    // Pending row for the receiver department before updating.
    $typeLowerPre = strtolower($doc_type_row);
    $isAnnouncementPre = (strpos($typeLowerPre, 'announcement') !== false);
    if ($isAnnouncementPre && $receiver_department !== '' && $prev_holder !== '' && strcasecmp($prev_holder, $receiver_department) !== 0) {
        if ($debug_logging) error_log("[mark_in_review] Announcement receive row mismatch: id=$id holder='$prev_holder' receiver_department='$receiver_department'. Re-resolving...");
        $lookupType = $doc_type_row;
        $lookupLike = $lookupType . ' (File%';
        $fix = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND current_holder = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1");
        if ($fix) {
            $fix->bind_param('sss', $lookupType, $lookupLike, $receiver_department);
            if ($fix->execute()) {
                $fixRes = $fix->get_result();
                if ($fixRow = $fixRes->fetch_assoc()) {
                    $newId = (int)($fixRow['id'] ?? 0);
                    if ($newId > 0) {
                        $id = $newId;
                        if ($debug_logging) error_log("[mark_in_review] Announcement re-resolve succeeded. Using id=$id for receiver_department='$receiver_department'");
                        // Reload prev state for the corrected row id
                        $loadPrevRow($id);
                        $current_holder = $receiver_department;
                    }
                }
            }
            $fix->close();
        }
    }

    if ($debug_logging) error_log("[mark_in_review] Will set: current_holder='$current_holder' (receiver_department='$receiver_department', prev_holder='$prev_holder')");

    // Announcements: a single "Received" acknowledgement should immediately complete.
    // Also ensure end_location matches the receiving department (current_holder)
    // so web + mobile show consistent completed holder/end destination.
    // Memos/regular docs: received => In Review (sequential routing continues).
    $typeLower = strtolower($doc_type_row);
    $isAnnouncement = (strpos($typeLower, 'announcement') !== false);
    $target_status = $isAnnouncement ? 'Completed' : 'In Review';

    if ($debug_logging) error_log("[mark_in_review] UPDATE tracking SET status='$target_status', current_holder='$current_holder'" . ($isAnnouncement ? ", end_location='$current_holder'" : "") . " WHERE id=$id (prev_status='$prev_status')");

    if ($isAnnouncement) {
        $sql = "UPDATE tracking SET status = ?, current_holder = ?, end_location = ?, department = ? WHERE id = ?";
        if ($stmt = $connection->prepare($sql)) {
            $stmt->bind_param('ssssi', $target_status, $current_holder, $current_holder, $current_holder, $id);
        }
    } else {
        $sql = "UPDATE tracking SET status = ?, current_holder = ? WHERE id = ?";
        if ($stmt = $connection->prepare($sql)) {
            $stmt->bind_param('ssi', $target_status, $current_holder, $id);
        }
    }

    if (isset($stmt) && $stmt) {
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            if ($debug_logging) error_log("[mark_in_review] UPDATE OK: affected_rows=$affected");
            $stmt->close();
            
            // LOG RECEIVE ACTION TO document_history TABLE
            // This creates a node in the timeline showing the department received the document
            $actor_user_id = 0;
            if ($current_holder !== '') {
                $selActor = $connection->prepare("SELECT id FROM control WHERE department = ? LIMIT 1");
                if ($selActor) {
                    $selActor->bind_param('s', $current_holder);
                    if ($selActor->execute()) {
                        $resActor = $selActor->get_result();
                        if ($rowActor = $resActor->fetch_assoc()) {
                            $actor_user_id = (int)$rowActor['id'];
                        }
                    }
                    $selActor->close();
                }
            }
            
            // Insert receive history record
            $new_status = $target_status;
            $hasDocType = __tracking_document_history_has_doc_type($connection);
            $hist = $connection->prepare($hasDocType
                ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, ?, 'receive', ?, ?, ?, ?, ?)"
                : "INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, 'receive', ?, ?, ?, ?, ?)"
            );
            if ($hist) {
              if ($hasDocType) {
                $hist->bind_param('isissss', $id, $doc_type_row, $actor_user_id, $prev_status, $new_status, $prev_holder, $current_holder);
              } else {
                $hist->bind_param('iissss', $id, $actor_user_id, $prev_status, $new_status, $prev_holder, $current_holder);
              }
              $hist->execute();
              $hist->close();
            }
            
            // Update linked notification fields in MySQL so PHP API returns correct status
            try {
                $nUpd = $connection->prepare("UPDATE notifications SET doc_status = ?, current_holder = ?, end_location = ? WHERE tracking_id = ?");
                if ($nUpd) {
                    $nUpd->bind_param('sssi', $target_status, $current_holder, $current_holder, $id);
                    $nUpd->execute();
                    $nUpd->close();
                }
            } catch (Throwable $nErr) {
                // best-effort
            }
            
            // Sync updated status to Firestore so mobile real-time listeners reflect the change
            try {
                if (function_exists('firestore_upsert_tracking')) {
                    firestore_upsert_tracking((string)$id, [
                        'id' => (int)$id,
                        'status' => (string)$target_status,
                        'current_holder' => (string)$current_holder,
                        'updatedAt' => (int)round(microtime(true) * 1000),
                    ]);
                }
                // Also update linked notification doc_status in Firestore
                if (function_exists('firestore_upsert_document')) {
                    $nSel = $connection->prepare("SELECT id FROM notifications WHERE tracking_id = ? ORDER BY id DESC LIMIT 1");
                    if ($nSel) {
                        $nSel->bind_param('i', $id);
                        if ($nSel->execute()) {
                            $nRes = $nSel->get_result();
                            if ($nRes && ($nRow = $nRes->fetch_assoc())) {
                                $nid = (int)$nRow['id'];
                                firestore_upsert_document('notifications', (string)$nid, [
                                    'doc_status' => (string)$target_status,
                                    'updatedAt' => (int)round(microtime(true) * 1000),
                                ]);
                            }
                        }
                        $nSel->close();
                    }
                }
            } catch (Throwable $fsErr) {
                // best-effort only
            }
            
            // Verify the update actually took effect
            $verify_status = '';
            $verify_holder = '';
            $verSel = $connection->prepare("SELECT status, current_holder FROM tracking WHERE id = ? LIMIT 1");
            if ($verSel) {
                $verSel->bind_param('i', $id);
                if ($verSel->execute()) {
                    $verRes = $verSel->get_result();
                    if ($verRow = $verRes->fetch_assoc()) {
                        $verify_status = trim($verRow['status'] ?? '');
                        $verify_holder = trim($verRow['current_holder'] ?? '');
                        if ($debug_logging) error_log("[mark_in_review] VERIFY after update: id=$id status='$verify_status' current_holder='$verify_holder'");
                    }
                }
                $verSel->close();
            }

            tracking_send_json($connection, [
                'success' => true,
                'id' => $id,
                'affected' => $affected,
                'prev_status' => $prev_status,
                'new_status' => $target_status,
                'current_holder' => $current_holder,
                'verified_status' => $verify_status,
            ]);
            exit;
        }
        $stmt->close();
    }
    if ($debug_logging) error_log("[mark_in_review] UPDATE FAILED for id=$id");
    tracking_send_json($connection, ['success' => false, 'error' => 'Update failed']);
    exit;
}

// Lightweight endpoint: mark a tracking row as "Pending" after successfully routing
// to another department (resets status similar to mark_in_review but sets Pending instead)
// Supports: ?action=mark_pending&id=123 OR ?action=mark_pending&type=Memo&end_location=CTO
if (isset($_GET['action']) && $_GET['action'] === 'mark_pending') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $end_location = isset($_GET['end_location']) ? trim($_GET['end_location']) : '';
    
    // If no ID but have type+end_location, find the tracking row
    // Try multiple search strategies: end_location, current_holder, or department
    if ($id <= 0 && $type !== '' && $end_location !== '') {
        // Mobile batch uploads store type as "<Type> (File X of Y)".
        // Accept either the exact type or a prefixed match.
        $typeLike = $type . ' (File%';

        // Strategy 1: Match by type + end_location
        $find = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND end_location = ? ORDER BY id DESC LIMIT 1");
        if ($find) {
            $find->bind_param('sss', $type, $typeLike, $end_location);
            if ($find->execute()) {
                $res = $find->get_result();
                if ($row = $res->fetch_assoc()) {
                    $id = (int)$row['id'];
                }
            }
            $find->close();
        }
        
        // Strategy 2: Match by type + current_holder (department receiving the doc)
        if ($id <= 0) {
            $find2 = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND current_holder = ? ORDER BY id DESC LIMIT 1");
            if ($find2) {
                $find2->bind_param('sss', $type, $typeLike, $end_location);
                if ($find2->execute()) {
                    $res2 = $find2->get_result();
                    if ($row2 = $res2->fetch_assoc()) {
                        $id = (int)$row2['id'];
                    }
                }
                $find2->close();
            }
        }
        
        // Strategy 3: Match by type + department
        if ($id <= 0) {
            $find3 = $connection->prepare("SELECT id FROM tracking WHERE (type = ? OR type LIKE ?) AND department = ? ORDER BY id DESC LIMIT 1");
            if ($find3) {
                $find3->bind_param('sss', $type, $typeLike, $end_location);
                if ($find3->execute()) {
                    $res3 = $find3->get_result();
                    if ($row3 = $res3->fetch_assoc()) {
                        $id = (int)$row3['id'];
                    }
                }
                $find3->close();
            }
        }
    }
    
    if ($id <= 0) {
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid id or type/end_location not found']);
        exit;
    }

    $sql = "UPDATE tracking SET status = 'Pending' WHERE id = ?";
    if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            tracking_send_json($connection, ['success' => true, 'id' => $id, 'affected' => $affected]);
            exit;
        }
        $stmt->close();
    }
    tracking_send_json($connection, ['success' => false, 'error' => 'Update failed']);
    exit;
}

// Lightweight endpoint: mark a tracking row as "Completed" when document reaches end location
// Supports: ?action=mark_completed&id=123
if (isset($_GET['action']) && $_GET['action'] === 'mark_completed') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id <= 0) {
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid id']);
        exit;
    }

    $sql = "UPDATE tracking SET status = 'Completed' WHERE id = ?";
    if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            tracking_send_json($connection, ['success' => true, 'id' => $id, 'affected' => $affected]);
            exit;
        }
        $stmt->close();
    }
    tracking_send_json($connection, ['success' => false, 'error' => 'Update failed']);
    exit;
}

// Lightweight endpoint: mark a tracking row as "Archived" for final storage
// Supports: ?action=mark_archived&id=123
if (isset($_GET['action']) && $_GET['action'] === 'mark_archived') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id <= 0) {
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid id']);
        exit;
    }

    $sql = "UPDATE tracking SET status = 'Archived' WHERE id = ?";
    if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();

            // Log to document_history so timeline remains visible after archiving
            $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $mark_arch_type = '';
            if ($selT = $connection->prepare("SELECT type FROM tracking WHERE id=? LIMIT 1")) { $selT->bind_param('i', $id); if ($selT->execute()) { $rT = $selT->get_result(); if ($rT && ($rowT = $rT->fetch_assoc())) $mark_arch_type = $rowT['type'] ?? ''; } $selT->close(); }
            $hasDocType = __tracking_document_history_has_doc_type($connection);
            $h = $connection->prepare($hasDocType
                ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder, notes) VALUES (?, ?, 'archive', ?, 'Archived', 'Digital Archive', 'Archived')"
                : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, to_holder, notes) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive', 'Archived')"
            );
            if ($h) {
                if ($hasDocType) {
                  $h->bind_param('isi', $id, $mark_arch_type, $actor_id);
                } else {
                  $h->bind_param('ii', $id, $actor_id);
                }
                $h->execute();
                $h->close();
            }

            tracking_send_json($connection, ['success' => true, 'id' => $id, 'affected' => $affected]);
            exit;
        }
        $stmt->close();
    }
    tracking_send_json($connection, ['success' => false, 'error' => 'Update failed']);
    exit;
}

// Handle final document capture/update from web interface
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_final_document') {
  $docId = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
  $debugMode = (isset($_POST['debug']) && (string)$_POST['debug'] === '1');

  // Mobile may not have doc_id on local archive items; allow resolving by identity.
  if ($docId <= 0) {
    $mobileTs = isset($_POST['mobile_timestamp']) ? trim((string)$_POST['mobile_timestamp']) : '';
    $docHash = isset($_POST['doc_hash']) ? trim((string)$_POST['doc_hash']) : '';

    $resolveByMobileTs = function($value) use ($connection) {
      $v = trim((string)$value);
      if ($v === '') return 0;
      if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('s', $v);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $stmt->close();
            return (int)$row['id'];
          }
        }
        $stmt->close();
      }
      return 0;
    };

    if ($mobileTs !== '') {
      // Try exact first
      $docId = $resolveByMobileTs($mobileTs);

      // Try common variants (e.g. PDF_123..., GALLERY_123..., or digits-only)
      if ($docId <= 0 && preg_match('/(\d{10,13})/', $mobileTs, $m)) {
        $digits = $m[1];
        $docId = $resolveByMobileTs($digits);
        if ($docId <= 0) $docId = $resolveByMobileTs('PDF_' . $digits);
        if ($docId <= 0) $docId = $resolveByMobileTs('GALLERY_' . $digits);
      }
    }

    if ($docId <= 0 && $docHash !== '') {
      if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE doc_hash = ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('s', $docHash);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $docId = (int)$row['id'];
          }
        }
        $stmt->close();
      }
    }

    if ($docId <= 0) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'Invalid document ID (missing doc_id and identity)']);
      exit;
    }
  }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
        exit;
    }
    
    $file = $_FILES['file'];
    $filename = basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp'];
    
    if (!in_array($ext, $allowedExts)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExts)]);
        exit;
    }
    
    // Create upload directory if not exists
    $uploadDir = __DIR__ . '/uploads/final/';
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }

    // Compute hash of the uploaded (plain) file so we can verify identity through archiving
    $plainHash = @hash_file('sha256', $file['tmp_name']) ?: null;

    // Encrypt and store the file (keeps archive/preview consistent with existing encrypted storage)
    // Build a user-friendly base name: Final_<Type>_<YYYY-MM-DD>
    $docType = '';
    $docDate = '';
    if ($stmtMeta = $connection->prepare("SELECT type, COALESCE(date_submitted, created_at) AS d FROM tracking WHERE id=? LIMIT 1")) {
      $stmtMeta->bind_param('i', $docId);
      if ($stmtMeta->execute()) {
        $r = $stmtMeta->get_result();
        if ($r && ($meta = $r->fetch_assoc())) {
          $docType = trim((string)($meta['type'] ?? ''));
          $docDate = trim((string)($meta['d'] ?? ''));
        }
        if ($r) $r->free();
      }
      $stmtMeta->close();
    }

    // Allow client override for display naming (mobile can pass doc_type/doc_date)
    $postedType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : '';
    $postedDate = isset($_POST['doc_date']) ? trim((string)$_POST['doc_date']) : '';
    if ($postedType !== '') $docType = $postedType;
    if ($postedDate !== '') $docDate = $postedDate;

    $stampTs = $docDate !== '' ? strtotime($docDate) : false;
    $stamp = $stampTs ? date('Y-m-d', $stampTs) : date('Y-m-d');
    $safeType = $docType !== '' ? $docType : 'Document';
    $safeType = preg_replace('/\s+/', ' ', $safeType);
    $safeType = preg_replace('/[^A-Za-z0-9_\-\.\(\) ]/', '_', $safeType);
    $safeType = trim(str_replace(' ', '_', $safeType), '_');
    if ($safeType === '') $safeType = 'Document';
    $base = 'Final_' . $safeType . '_' . $stamp;

    $newFilename = 'final_' . $docId . '_' . time() . '_' . $base . '.' . $ext . '.enc';
    $targetPath = $uploadDir . $newFilename;

    if (file_crypto_encrypt_stream_to_path($file['tmp_name'], $targetPath)) {
      // Store as a path relative to lib/OCR(UPDATED)/ so both tracking/download and archive/download can resolve it.
      $relativePath = 'uploads/final/' . $newFilename;
      $fileSize = filesize($targetPath);

      $sql = "UPDATE tracking SET file_path = ?, file_size = ?, file_type_icon = ?, status = 'Completed' WHERE id = ?";
      if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('sisi', $relativePath, $fileSize, $ext, $docId);
        if ($stmt->execute()) {
                // Store per-page OCR for multi-page search support
                $ocrPages = isset($_POST['ocr_pages']) ? $_POST['ocr_pages'] : [];
                $ocrContent = isset($_POST['ocr_content']) ? trim((string)$_POST['ocr_content']) : '';
                if (is_array($ocrPages) && !empty($ocrPages)) {
                    // New format: per-page OCR array
                    ocr_store_document_pages($connection, 'tracking', $docId, $ocrPages);
                } elseif ($ocrContent !== '') {
                    // Legacy format: single OCR string - store as page 1
                    ocr_store_page($connection, 'tracking', $docId, 1, $ocrContent);
                }
                
                // Log to document_history
                $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
                $hist = $connection->prepare($hasDocTypeCol
                    ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, notes) VALUES (?, ?, 'complete', ?, 'Final document captured and marked Completed')"
                    : "INSERT INTO document_history (doc_id, action, actor_user_id, notes) VALUES (?, 'complete', ?, 'Final document captured and marked Completed')"
                );
                if ($hist) {
                    if ($hasDocTypeCol) {
                      $hist->bind_param('isi', $docId, $docType, $actor_id);
                    } else {
                      $hist->bind_param('ii', $docId, $actor_id);
                    }
                    $hist->execute();
                    $hist->close();
                }
                
                header('Content-Type: application/json');
                $payload = ['success' => true, 'message' => 'Document completed successfully'];
                if ($debugMode) {
                  $payload['debug'] = [
                    'doc_id' => $docId,
                    'uploaded_name' => $filename,
                    'stored_relative_path' => $relativePath,
                    'stored_size_bytes' => $fileSize,
                    'plain_hash' => $plainHash,
                    'type' => $docType,
                    'date' => $docDate,
                    'base' => $base,
                  ];
                }
                echo json_encode($payload);
                exit;
            }
            $stmt->close();
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database update failed']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to encrypt and save file']);
    exit;
}

// Handle returned document capture/update — replaces file + OCR but does NOT mark Completed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_returned_document') {
  $docId = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
  $debugMode = (isset($_POST['debug']) && (string)$_POST['debug'] === '1');

  // Resolve by identity if doc_id missing (same logic as update_final_document)
  if ($docId <= 0) {
    $mobileTs = isset($_POST['mobile_timestamp']) ? trim((string)$_POST['mobile_timestamp']) : '';
    $docHash  = isset($_POST['doc_hash']) ? trim((string)$_POST['doc_hash']) : '';

    $resolveByMobileTs = function($value) use ($connection) {
      $v = trim((string)$value);
      if ($v === '') return 0;
      if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('s', $v);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) { $stmt->close(); return (int)$row['id']; }
        }
        $stmt->close();
      }
      return 0;
    };

    if ($mobileTs !== '') {
      $docId = $resolveByMobileTs($mobileTs);
      if ($docId <= 0 && preg_match('/(\d{10,13})/', $mobileTs, $m)) {
        $digits = $m[1];
        $docId = $resolveByMobileTs($digits);
        if ($docId <= 0) $docId = $resolveByMobileTs('PDF_' . $digits);
        if ($docId <= 0) $docId = $resolveByMobileTs('GALLERY_' . $digits);
      }
    }

    if ($docId <= 0 && $docHash !== '') {
      if ($stmt = $connection->prepare("SELECT id FROM tracking WHERE doc_hash = ? ORDER BY id DESC LIMIT 1")) {
        $stmt->bind_param('s', $docHash);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          if ($res && ($row = $res->fetch_assoc())) { $docId = (int)$row['id']; }
        }
        $stmt->close();
      }
    }

    if ($docId <= 0) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'error' => 'Invalid document ID (missing doc_id and identity)']);
      exit;
    }
  }

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
  }

  $file = $_FILES['file'];
  $filename = basename($file['name']);
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp'];

  if (!in_array($ext, $allowedExts)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExts)]);
    exit;
  }

  $uploadDir = __DIR__ . '/uploads/returned/';
  if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

  $plainHash = @hash_file('sha256', $file['tmp_name']) ?: null;

  // Build filename
  $docType = ''; $docDate = '';
  if ($stmtMeta = $connection->prepare("SELECT type, COALESCE(date_submitted, created_at) AS d FROM tracking WHERE id=? LIMIT 1")) {
    $stmtMeta->bind_param('i', $docId);
    if ($stmtMeta->execute()) {
      $r = $stmtMeta->get_result();
      if ($r && ($meta = $r->fetch_assoc())) {
        $docType = trim((string)($meta['type'] ?? ''));
        $docDate = trim((string)($meta['d'] ?? ''));
      }
      if ($r) $r->free();
    }
    $stmtMeta->close();
  }
  $postedType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : '';
  $postedDate = isset($_POST['doc_date']) ? trim((string)$_POST['doc_date']) : '';
  if ($postedType !== '') $docType = $postedType;
  if ($postedDate !== '') $docDate = $postedDate;

  $stampTs = $docDate !== '' ? strtotime($docDate) : false;
  $stamp = $stampTs ? date('Y-m-d', $stampTs) : date('Y-m-d');
  $safeType = $docType !== '' ? $docType : 'Document';
  $safeType = preg_replace('/\s+/', ' ', $safeType);
  $safeType = preg_replace('/[^A-Za-z0-9_\-\.\(\) ]/', '_', $safeType);
  $safeType = trim(str_replace(' ', '_', $safeType), '_');
  if ($safeType === '') $safeType = 'Document';
  $base = 'Returned_' . $safeType . '_' . $stamp;

  $newFilename = 'returned_' . $docId . '_' . time() . '_' . $base . '.' . $ext . '.enc';
  $targetPath = $uploadDir . $newFilename;

  if (file_crypto_encrypt_stream_to_path($file['tmp_name'], $targetPath)) {
    $relativePath = 'uploads/returned/' . $newFilename;
    $fileSize = filesize($targetPath);

    // --- Save old file_path to document_versions before replacing ---
    // Ensure document_versions table exists
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

    // Read current file_path before we overwrite it
    $oldFilePath = '';
    $oldFileSize = 0;
    $oldDept = '';
    $oldCreatedAt = null;
    if ($stmtOld = $connection->prepare("SELECT file_path, file_size, department, date_submitted FROM tracking WHERE id = ? LIMIT 1")) {
      $stmtOld->bind_param('i', $docId);
      if ($stmtOld->execute()) {
        $rOld = $stmtOld->get_result();
        if ($rOld && ($rowOld = $rOld->fetch_assoc())) {
          $oldFilePath = trim((string)($rowOld['file_path'] ?? ''));
          $oldFileSize = (int)($rowOld['file_size'] ?? 0);
          $oldDept = trim((string)($rowOld['department'] ?? ''));
          $oldCreatedAt = $rowOld['date_submitted'] ?? null;
        }
        if ($rOld) $rOld->free();
      }
      $stmtOld->close();
    }

    // Check how many versions already exist
    $maxVer = 0;
    if ($stmtVer = $connection->prepare("SELECT COALESCE(MAX(version_number), 0) AS mv FROM document_versions WHERE tracking_id = ?")) {
      $stmtVer->bind_param('i', $docId);
      if ($stmtVer->execute()) {
        $rVer = $stmtVer->get_result();
        if ($rVer && ($rowVer = $rVer->fetch_assoc())) {
          $maxVer = (int)$rowVer['mv'];
        }
        if ($rVer) $rVer->free();
      }
      $stmtVer->close();
    }

    // If no versions yet, save the original document as version 1
    if ($maxVer === 0 && $oldFilePath !== '') {
      $v1 = $connection->prepare("INSERT INTO document_versions (tracking_id, version_number, file_path, file_size, uploaded_by, department, version_type, created_at) VALUES (?, 1, ?, ?, ?, ?, 'original', ?)");
      if ($v1) {
        $origUploader = $oldDept; // best-effort: use department as uploader for original
        $origCreated = $oldCreatedAt ?: date('Y-m-d H:i:s');
        $v1->bind_param('isisss', $docId, $oldFilePath, $oldFileSize, $origUploader, $oldDept, $origCreated);
        $v1->execute();
        $v1->close();
        $maxVer = 1;
      }
    }

    // Save the NEW returned capture as the next version
    $nextVer = $maxVer + 1;
    $actorName = isset($_SESSION['username']) ? (string)$_SESSION['username'] : (isset($_SESSION['user']) ? (string)$_SESSION['user'] : '');
    $actorDept = isset($_SESSION['department']) ? (string)$_SESSION['department'] : (isset($_SESSION['user_department']) ? (string)$_SESSION['user_department'] : '');
    $vIns = $connection->prepare("INSERT INTO document_versions (tracking_id, version_number, file_path, file_size, uploaded_by, department, version_type, created_at) VALUES (?, ?, ?, ?, ?, ?, 'returned', NOW())");
    if ($vIns) {
      $vIns->bind_param('iisiss', $docId, $nextVer, $relativePath, $fileSize, $actorName, $actorDept);
      $vIns->execute();
      $vIns->close();
    }
    // --- End version saving ---

    // Update file_path and file_size but do NOT change status
    $sql = "UPDATE tracking SET file_path = ?, file_size = ?, file_type_icon = ? WHERE id = ?";
    if ($stmt = $connection->prepare($sql)) {
      $stmt->bind_param('sisi', $relativePath, $fileSize, $ext, $docId);
      if ($stmt->execute()) {
        // Store per-page OCR
        $ocrPages = isset($_POST['ocr_pages']) ? $_POST['ocr_pages'] : [];
        $ocrContent = isset($_POST['ocr_content']) ? trim((string)$_POST['ocr_content']) : '';
        if (is_array($ocrPages) && !empty($ocrPages)) {
          ocr_store_document_pages($connection, 'tracking', $docId, $ocrPages);
        } elseif ($ocrContent !== '') {
          ocr_store_page($connection, 'tracking', $docId, 1, $ocrContent);
        }

        // Log to document_history
        $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
        $hist = $connection->prepare($hasDocTypeCol
            ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, notes) VALUES (?, ?, 'update_returned', ?, 'Returned document re-captured and updated')"
            : "INSERT INTO document_history (doc_id, action, actor_user_id, notes) VALUES (?, 'update_returned', ?, 'Returned document re-captured and updated')"
        );
        if ($hist) {
          if ($hasDocTypeCol) {
            $hist->bind_param('isi', $docId, $docType, $actor_id);
          } else {
            $hist->bind_param('ii', $docId, $actor_id);
          }
          $hist->execute();
          $hist->close();
        }

        header('Content-Type: application/json');
        $payload = ['success' => true, 'message' => 'Returned document updated successfully'];
        if ($debugMode) {
          $payload['debug'] = [
            'doc_id' => $docId,
            'uploaded_name' => $filename,
            'stored_relative_path' => $relativePath,
            'stored_size_bytes' => $fileSize,
            'plain_hash' => $plainHash,
          ];
        }
        echo json_encode($payload);
        exit;
      }
      $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database update failed']);
    exit;
  }

  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => 'Failed to encrypt and save file']);
  exit;
}

// Single document detail endpoint for realtime enrichment (used by Firestore listener)
if (isset($_GET['action']) && $_GET['action'] === 'doc_detail') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid id']);
    }

    $tryLoadLatestExtractedOcr = function($connection, array $row) {
        try {
            $hasTable = false;
            if ($chk = $connection->query("SHOW TABLES LIKE 'extracted_content'")) {
                $hasTable = ($chk->num_rows > 0);
                $chk->free();
            }
            if (!$hasTable) {
                return null;
            }

            $candidates = [];
            $docHash = trim((string)($row['doc_hash'] ?? ''));
            $mobileTs = trim((string)($row['mobile_timestamp'] ?? ''));
            $docId = (int)($row['id'] ?? 0);
            if ($docHash !== '') $candidates[] = $docHash;
            if ($mobileTs !== '') $candidates[] = $mobileTs;
            if ($docId > 0) {
                $candidates[] = 'tracking:' . $docId;
                $candidates[] = 'TRACKING_' . $docId;
            }

            foreach ($candidates as $ref) {
                $stmt = $connection->prepare('SELECT enc_blob FROM extracted_content WHERE doc_ref = ? ORDER BY updated_at DESC LIMIT 1');
                if (!$stmt) continue;
                $stmt->bind_param('s', $ref);
                if (!$stmt->execute()) {
                    $stmt->close();
                    continue;
                }
                $res = $stmt->get_result();
                $found = $res ? $res->fetch_assoc() : null;
                if ($res) { $res->free(); }
                $stmt->close();
                if ($found && isset($found['enc_blob'])) {
                    $plain = file_crypto_decrypt_blob($found['enc_blob']);
                    if ($plain !== false && trim((string)$plain) !== '') {
                        return (string)$plain;
                    }
                }
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    };

    $sqlDoc = "SELECT 
                  tracking.id,
                  tracking.type,
                  tracking.employee_name,
                  tracking.date_submitted,
                  tracking.current_holder,
                  tracking.end_location,
                  tracking.status,
                  tracking.department,
                  tracking.file_type_icon,
                  tracking.mobile_timestamp,
                  tracking.doc_hash,
                  tracking.ocr_content,
                  tracking.file_size,
                  tracking.user_email,
                  tracking.file_path,
                  tracking.created_at,
                  tracking.routing_queue,
                  tracking.route_step,
                  control.department AS employee_department
                FROM tracking
                LEFT JOIN control ON control.user = tracking.employee_name
                WHERE tracking.id = ?
                LIMIT 1";

    if ($stmtDoc = $connection->prepare($sqlDoc)) {
        $stmtDoc->bind_param('i', $id);
        if ($stmtDoc->execute()) {
            $resDoc = $stmtDoc->get_result();
            if ($row = $resDoc->fetch_assoc()) {
                $overdueMeta = tracking_calculate_overdue_meta($row['status'] ?? '', $row['created_at'] ?? null, $row['date_submitted'] ?? null);
                $row['overdue_label'] = $overdueMeta['label'];
                $row['overdue_full_label'] = $overdueMeta['full'];
                $row['overdue_state'] = $overdueMeta['state'];
                $row['overdue_seconds'] = $overdueMeta['seconds'];

                // For Completed documents, prefer the latest secure OCR text (if available)
                // so OCR can still be refreshed/updated after completion.
                if (strcasecmp((string)($row['status'] ?? ''), 'Completed') === 0) {
                    $latest = $tryLoadLatestExtractedOcr($connection, $row);
                    if ($latest !== null) {
                        $row['ocr_content'] = $latest;
                    }
                }

              // Determine the original submitter department for the "create" node
              // (compute BEFORE reading history so create/upload nodes can use it)
              $submittedDept = '';
              if (!empty($row['employee_department'])) {
                $submittedDept = $row['employee_department'];
              } elseif (!empty($row['department'])) {
                $submittedDept = $row['department'];
              } elseif (!empty($row['current_holder'])) {
                $submittedDept = $row['current_holder'];
              }

                // Fetch REAL history from document_history table
                $history = [];
                $docId = $row['id'];
                
                $sqlHistory = "SELECT 
                    dh.action,
                    dh.from_holder,
                    dh.to_holder,
                    dh.from_status,
                    dh.to_status,
                    dh.notes,
                    dh.created_at,
                    c.department AS actor_department
                FROM document_history dh
                LEFT JOIN control c ON c.id = dh.actor_user_id
                WHERE dh.doc_id = ?
                ORDER BY dh.created_at ASC, dh.id ASC";
                
                if ($stmtHist = $connection->prepare($sqlHistory)) {
                    $stmtHist->bind_param('i', $docId);
                    if ($stmtHist->execute()) {
                        $resHist = $stmtHist->get_result();
                        $nodeIndex = 0;
                        while ($hrow = $resHist->fetch_assoc()) {
                            $actionType = $hrow['action'];
                            $actionTime = date('Y-m-d - h:i A', strtotime($hrow['created_at']));
                            
                            // Determine department for this node
                            $nodeDept = '';
                            $actionText = '';
                            $nodeStatus = 'completed';
                            
                            switch ($actionType) {
                                case 'create':
                                case 'upload':
                                // Creation should attribute to the creator's department (e.g., CMO),
                                // not the first receiver (e.g., CACCO).
                              $nodeDept = ($hrow['from_holder'] ?: '') !== ''
                                ? $hrow['from_holder']
                                : (($submittedDept ?: '') !== ''
                                  ? $submittedDept
                                  : (($hrow['actor_department'] ?: '') !== ''
                                    ? $hrow['actor_department']
                                    : ($hrow['to_holder'] ?: 'System')));
                                    $actionText = 'Document Created/Uploaded';
                                    $nodeStatus = 'completed';
                                    break;
                                case 'route':
                                    // For routing, node shows sender department with "Routed to [destination]"
                                    $fromDept = $hrow['from_holder'] ?: '';
                                    $toDept = $hrow['to_holder'] ?: 'Unknown';
                                    // Skip self-routing entries (same dept routed to itself)
                                    if ($fromDept !== '' && $toDept !== '' && strcasecmp(trim($fromDept), trim($toDept)) === 0) {
                                        continue 2; // skip this history row entirely
                                    }
                                    $nodeDept = $fromDept ?: $toDept; // Show sender as node owner
                                    $actionText = "Routed to {$toDept}";
                                    $nodeStatus = 'completed'; // Routing is a completed action
                                    break;
                                case 'update':
                                    $nodeDept = $hrow['to_holder'] ?: ($hrow['actor_department'] ?: 'System');
                                    $actionText = 'Document Updated';
                                    $nodeStatus = 'completed';
                                    break;
                                case 'edit':
                                    $nodeDept = $hrow['actor_department'] ?: ($hrow['to_holder'] ?: ($hrow['from_holder'] ?: 'System'));
                                    $actionText = '';
                                    $nodeStatus = 'completed';
                                    break;
                                case 'receive':
                                    // When a department clicks "Received" button - confirms receipt
                                    $nodeDept = $hrow['to_holder'] ?: ($hrow['actor_department'] ?: 'System');
                                    $actionText = "Received by {$nodeDept}";
                                    $nodeStatus = 'review'; // In Review status
                                    break;
                                case 'archive':
                                    $nodeDept = 'Digital Archive';
                                    $actionText = 'Document Archived';
                                    $nodeStatus = 'completed';
                                    break;
                                case 'complete':
                                  // Mobile final upload writes actor_user_id=0 which resolves to no department;
                                  // show the end_location as the department for completion node.
                                  $nodeDept = $row['end_location'] ?: ($hrow['to_holder'] ?: 'Final');
                                  $actionText = 'Completed';
                                  $nodeStatus = 'completed';
                                  break;
                                case 'file_update':
                                    $nodeDept = $hrow['actor_department'] ?: 'System';
                                    $actionText = 'File Updated';
                                    $nodeStatus = 'completed';
                                    break;
                                case 'edit_type':
                                    // Type change should attribute to the editor's department.
                                    // from_status/to_status store old/new type.
                                    $nodeDept = $hrow['actor_department']
                                        ?: ($hrow['from_holder'] ?: ($hrow['to_holder'] ?: 'System'));
                                    $newType = $hrow['to_status'] ?: '';
                                    $actionText = $newType !== ''
                                        ? ('Changed document type to "' . $newType . '"')
                                        : 'Changed document type';
                                    $nodeStatus = 'completed';
                                    break;
                                default:
                                    $nodeDept = $hrow['to_holder'] ?: ($hrow['actor_department'] ?: 'System');
                                    $actionText = ucfirst($actionType);
                                    $nodeStatus = 'completed';
                            }
                            
                            // Only add if we have a valid department
                            if (!empty($nodeDept)) {
                                $history[] = [
                                    'user' => $nodeDept,
                                    'action' => $actionText,
                                    'time' => $actionTime,
                                    'rawTime' => $hrow['created_at'] ?? '',
                                    'status' => $nodeStatus,
                                    'actionType' => $actionType,
                                    'fromHolder' => $hrow['from_holder'] ?? '',
                                    'toHolder' => $hrow['to_holder'] ?? '',
                                    'notes' => $hrow['notes'] ?? ''
                                ];
                            }
                            $nodeIndex++;
                        }
                    }
                    $stmtHist->close();
                }
                
                // If no history found OR if the first entry isn't a create/upload, prepend a create node
                // This ensures documents created before history tracking always show their origin
                $needsCreateNode = empty($history);
                if (!$needsCreateNode && !empty($history)) {
                    $firstAction = $history[0]['actionType'] ?? '';
                    if (!in_array($firstAction, ['create', 'upload'], true)) {
                        $needsCreateNode = true;
                    }
                }
                
                if ($needsCreateNode) {
                    $createNode = [
                        'user' => $submittedDept ?: 'System',
                        'action' => 'Document Submitted',
                        'time' => $row['date_submitted'] . ' - ' . date('h:i A', strtotime($row['created_at'] ?? 'now')),
                        'rawTime' => $row['created_at'] ?? '',
                        'status' => 'completed',
                        'actionType' => 'create',
                        'fromHolder' => '',
                        'toHolder' => $submittedDept ?: 'System',
                        'notes' => ''
                    ];
                    // Prepend the create node at the beginning
                    array_unshift($history, $createNode);
                }
                
                // ============ ARRIVED / SENT TIMES PER DEPARTMENT ============
                // Rules:
                // - Arrived time is ONLY for 'receive' actions (use that event's time).
                // - Sent time is ONLY for 'route' actions (use that event's time).
                // - For the first upload/create node, show Sent time using its own timestamp.
                // - Do NOT auto-fill from next/previous events.
                foreach ($history as $idx => &$entry) {
                    $actionType = (string)($entry['actionType'] ?? '');
                    $rawTime = (string)($entry['rawTime'] ?? '');
                    if ($rawTime === '') {
                        continue;
                    }

                    if ($actionType === 'receive') {
                        $entry['arrivedAt'] = date('h:i A', strtotime($rawTime));
                        $entry['arrivedAtFull'] = $rawTime;
                    }

                    if ($actionType === 'route' || $actionType === 'create' || $actionType === 'upload' || $actionType === 'return' || $actionType === 'returned') {
                        $entry['sentAt'] = date('h:i A', strtotime($rawTime));
                        $entry['sentAtFull'] = $rawTime;
                    }
                }
                unset($entry); // break reference
                
                $row['history'] = $history;

                // ── Fixed-route: append pending placeholder nodes for departments not yet reached ──
                $rq = trim((string)($row['routing_queue'] ?? ''));
                if ($rq !== '') {
                    $routeDepts = array_map('trim', explode(',', $rq));
                  // Collect departments that already acted on the document.
                  // Important: do not treat toHolder as visited yet, because routing to a
                  // department does not mean that department has received/acted on it.
                    $visitedDepts = [];
                    foreach ($history as $h) {
                        $dept = strtoupper(trim((string)($h['user'] ?? '')));
                        if ($dept !== '') $visitedDepts[$dept] = true;
                    $from = strtoupper(trim((string)($h['fromHolder'] ?? '')));
                    if ($from !== '') $visitedDepts[$from] = true;
                    }
                    // Append placeholder nodes for departments not yet visited
                    foreach ($routeDepts as $idx => $dept) {
                        $deptUpper = strtoupper($dept);
                        if (!isset($visitedDepts[$deptUpper])) {
                            $row['history'][] = [
                                'user' => $dept,
                                'action' => 'Awaiting document',
                                'time' => '',
                                'rawTime' => '',
                                'status' => 'pending_route',
                                'actionType' => 'pending_route',
                                'fromHolder' => '',
                                'toHolder' => $dept,
                                'notes' => '',
                                'isPendingRoute' => true
                            ];
                        }
                    }
                    $row['routing_queue_list'] = $routeDepts;
                }

                tracking_send_json($connection, ['success' => true, 'doc' => $row]);
            } else {
                tracking_send_json($connection, ['success' => false, 'error' => 'Document not found']);
            }
        } else {
            tracking_send_json($connection, ['success' => false, 'error' => 'Failed to load document']);
        }
        $stmtDoc->close();
    } else {
        tracking_send_json($connection, ['success' => false, 'error' => 'Prepare failed']);
    }
}

// OCR Smart Search endpoint: uses FULLTEXT search on ocr_pages for natural language queries
// Usage: tracking.php?action=ocr_search&q=payroll+dave&limit=20
if (isset($_GET['action']) && $_GET['action'] === 'ocr_search') {
    $q = trim($_GET['q'] ?? '');
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    
    if ($q === '') {
        tracking_send_json($connection, [
            'success' => true,
            'query' => '',
            'results' => [],
            'total' => 0,
        ]);
    }
    
    // Best-effort: ensure OCR storage tables/columns exist
    try {
        if (function_exists('ocr_ensure_pages_table')) {
            ocr_ensure_pages_table($connection);
        }
        if (function_exists('ocr_ensure_parent_ocr_columns')) {
            ocr_ensure_parent_ocr_columns($connection);
        }
    } catch (Throwable $t) {
        // ignore
    }

    // Primary: OCR pages table (best quality snippets/page numbers)
    $results = ocr_smart_search($connection, 'tracking', $q, $limit);
    
    // Enrich with snippets
    foreach ($results as &$result) {
        if (!empty($result['matching_pages'])) {
            $pageNum = (int)$result['matching_pages'][0];
            $stmt = $connection->prepare("SELECT ocr_text FROM ocr_pages WHERE scope = 'tracking' AND doc_id = ? AND page_number = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $result['id'], $pageNum);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $result['snippet'] = ocr_get_match_snippet($row['ocr_text'], $q);
                }
                $stmt->close();
            }
        }
    }

    // Fallback: include docs that have OCR text stored directly on tracking row.
    // This is important for non-Completed documents (Pending/Received/etc.) where ocr_pages may not exist yet.
    // Uses FULLTEXT index (ft_tracking_ocr) when the query is long enough (≥3 chars),
    // otherwise falls back to LIKE for very short queries.
    $seenIds = [];
    foreach ($results as $r0) {
        $seenIds[(string)($r0['id'] ?? '')] = true;
    }
    if (count($results) < $limit) {
        $appendRows = function($stmt) use (&$results, &$seenIds, $limit, $q) {
            if (!$stmt) {
                return false;
            }
            if (!$stmt->execute()) {
                return false;
            }
            $res2 = $stmt->get_result();
            if ($res2) {
                while ($row2 = $res2->fetch_assoc()) {
                    $id2 = (int)($row2['id'] ?? 0);
                    if ($id2 <= 0) continue;
                    if (isset($seenIds[(string)$id2])) continue;

                    $text2 = (string)($row2['ocr_content'] ?? '');
                    $results[] = [
                        'id' => $id2,
                        'matching_pages' => [1],
                        'score' => 0,
                        'confidence' => null,
                        'snippet' => function_exists('ocr_get_match_snippet') ? ocr_get_match_snippet($text2, $q) : null,
                    ];
                    $seenIds[(string)$id2] = true;
                    if (count($results) >= $limit) break;
                }
                $res2->free();
            }
            return true;
        };

        $usedFulltext = false;
        $remaining = $limit - count($results);
        $useFulltext = (mb_strlen($q) >= 3);
        if ($useFulltext && $remaining > 0) {
            $ftBase = trim(preg_replace('/[^a-zA-Z0-9\s]+/', ' ', $q));
            $ftBase = preg_replace('/\s+/', ' ', $ftBase);
            if ($ftBase !== '') {
                $ftQuery = '+' . str_replace(' ', ' +', $ftBase) . '*';
                $stmt2 = $connection->prepare(
                    "SELECT id, ocr_content, MATCH(ocr_content, ocr_summary) AGAINST(? IN BOOLEAN MODE) AS ft_score " .
                    "FROM tracking WHERE MATCH(ocr_content, ocr_summary) AGAINST(? IN BOOLEAN MODE) " .
                    "ORDER BY ft_score DESC, id DESC LIMIT ?"
                );
                if ($stmt2) {
                    try {
                        $stmt2->bind_param('ssi', $ftQuery, $ftQuery, $remaining);
                        $usedFulltext = $appendRows($stmt2);
                    } catch (Throwable $t) {
                        $usedFulltext = false;
                    }
                    $stmt2->close();
                }
            }
        }

        // Fallback to LIKE if FULLTEXT was skipped/failed (e.g., missing index)
        if (!$usedFulltext && count($results) < $limit) {
            $remaining = $limit - count($results);
            $like = '%' . $q . '%';
            $stmt2 = $connection->prepare(
                "SELECT id, ocr_content FROM tracking WHERE (ocr_content LIKE ? OR ocr_summary LIKE ?) ORDER BY id DESC LIMIT ?"
            );
            if ($stmt2) {
                $stmt2->bind_param('ssi', $like, $like, $remaining);
                $appendRows($stmt2);
                $stmt2->close();
            }
        }
    }
    
    tracking_send_json($connection, [
        'success' => true,
        'query' => $q,
        'results' => $results,
        'total' => count($results),
    ]);
}

// Get OCR pages for a document
// Usage: tracking.php?action=ocr_pages&doc_id=123
if (isset($_GET['action']) && $_GET['action'] === 'ocr_pages') {
    $docId = (int)($_GET['doc_id'] ?? 0);
    if ($docId <= 0) {
        tracking_send_json($connection, ['success' => false, 'error' => 'Invalid doc_id']);
    }
    
    $pages = ocr_get_pages($connection, 'tracking', $docId);
    
    tracking_send_json($connection, [
        'success' => true,
        'doc_id' => $docId,
        'total_pages' => count($pages),
        'pages' => $pages,
    ]);
}

// Search suggestions endpoint: returns up to 10 suggestions across key fields
if (isset($_GET['action']) && $_GET['action'] === 'search_suggest') {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($q === '' || strlen($q) < 2) { tracking_send_json($connection, ['suggestions' => []]); }
    $like = '%' . $connection->real_escape_string($q) . '%';

    $suggestions = [];
    $seen = [];
    // Fetch distinct values from multiple columns, unioned; include more fields and OCR snippet
    $sql = "(
              SELECT DISTINCT type AS label, 'type' AS field FROM tracking WHERE type LIKE ?
            ) UNION ALL (
              SELECT DISTINCT employee_name AS label, 'employee_name' AS field FROM tracking WHERE employee_name LIKE ?
            ) UNION ALL (
              SELECT DISTINCT department AS label, 'department' AS field FROM tracking WHERE department LIKE ?
            ) UNION ALL (
              SELECT DISTINCT status AS label, 'status' AS field FROM tracking WHERE status LIKE ?
            ) UNION ALL (
              SELECT DISTINCT current_holder AS label, 'current_holder' AS field FROM tracking WHERE current_holder LIKE ?
            ) UNION ALL (
              SELECT DISTINCT end_location AS label, 'end_location' AS field FROM tracking WHERE end_location LIKE ?
            )";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $label = trim($row['label']);
                if ($label === '') continue;
                $key = strtolower($row['field'] . '|' . $label);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $suggestions[] = ['label' => $label, 'field' => $row['field']];
                    if (count($suggestions) >= 12) break; // hard cap
                }
            }
        }
        $stmt->close();
    }

    // Add OCR snippet suggestions separately (lighter weight, limit remaining slots)
    // Uses FULLTEXT when query is long enough (≥3 chars), falls back to LIKE
    if (count($suggestions) < 12) {
        $limit = 12 - count($suggestions);
        $qEsc = $connection->real_escape_string($q);
        $resO = null;
        if (mb_strlen($q) >= 3) {
            $ftq = '+' . preg_replace('/\s+/', ' +', $qEsc) . '*';
            $resO = $connection->query("SELECT ocr_content FROM tracking WHERE MATCH(ocr_content, ocr_summary) AGAINST('" . $connection->real_escape_string($ftq) . "' IN BOOLEAN MODE) LIMIT " . (int)$limit);
        }
        if (!$resO) {
            $resO = $connection->query("SELECT ocr_content FROM tracking WHERE ocr_content LIKE '%" . $qEsc . "%' LIMIT " . (int)$limit);
        }
        if ($resO) {
            while ($r = $resO->fetch_assoc()) {
                $snippet = trim($r['ocr_content']);
                if ($snippet === '') continue;
                $snippet = preg_replace('/\s+/', ' ', $snippet);
                if (strlen($snippet) > 80) { $snippet = substr($snippet, 0, 80) . '…'; }
                $key = 'ocr_content|' . strtolower($snippet);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $suggestions[] = ['label' => $snippet, 'field' => 'ocr_content'];
                    if (count($suggestions) >= 12) break;
                }
            }
            $resO->free();
        }
    }

    tracking_send_json($connection, ['suggestions' => $suggestions]);
}

// Handle Multi-File Upload from Mobile App
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['document_files'])) {
    
    // --- 1. Gather all POST data common to all documents in the batch
    $type = mysqli_real_escape_string($connection, $_POST['type'] ?? 'Mobile Scan');
    $employee = mysqli_real_escape_string($connection, $_POST['employee'] ?? 'Unknown Employee');
    $date = mysqli_real_escape_string($connection, $_POST['date'] ?? date('Y-m-d'));
    $holder = mysqli_real_escape_string($connection, $_POST['holder'] ?? 'Reception');
    $endLocation = mysqli_real_escape_string($connection, $_POST['endLocation'] ?? 'Filing');
    // Force initial status to Pending on first upload to allow workflow to progress
    // Even if the client sends another status, we normalize here
    $status = 'Pending';
    $department = mysqli_real_escape_string($connection, $_POST['department'] ?? 'General');
    $routing_queue = mysqli_real_escape_string($connection, $_POST['routing_queue'] ?? '');
    $user_email = mysqli_real_escape_string($connection, $_POST['user_email'] ?? '');
    $batch_size = mysqli_real_escape_string($connection, $_POST['batch_size'] ?? '1');

    // Ensure fixed-route columns exist for timeline placeholder rendering
    @$connection->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS routing_queue TEXT DEFAULT NULL");
    @$connection->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS route_step INT DEFAULT 0");
    
    // --- 2. PHP reformats the $_FILES array structure when '[]' is used.
    $file_array = $_FILES['document_files'];
    $file_count = count($file_array['name']);
    $upload_dir = 'uploads/'; // Create this directory and ensure it has write permissions!

    // Ensure upload directory exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $uploaded_successfully = 0;
    $inserted_document_ids = [];

    // --- Pre-loop: ensure extracted_content table exists (once, not per file)
    @$connection->query("CREATE TABLE IF NOT EXISTS extracted_content (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Collect Firestore upsert payloads to batch after the loop (one pass instead of per-file)
    $firestore_queue = [];

    // --- 3. Loop through each uploaded file
    $maxFileSizeBytes = 20 * 1024 * 1024; // 20MB limit
    for ($i = 0; $i < $file_count; $i++) {
        // Only process files that were uploaded without error
        if ($file_array['error'][$i] == UPLOAD_ERR_OK) {
            
            $file_tmp_name = $file_array['tmp_name'][$i];
            $file_name = mysqli_real_escape_string($connection, $file_array['name'][$i]);
            $file_size = $file_array['size'][$i];
            
            // Enforce 20MB max file size limit
            if ($file_size > $maxFileSizeBytes) {
                error_log("File rejected - exceeds 20MB limit: " . $file_name . " (" . $file_size . " bytes)");
                continue; // Skip this file but continue processing others
            }
            
            // Compute SHA-256 hash of plaintext file for content-identity
            $file_hash = @hash_file('sha256', $file_tmp_name) ?: '';
            
            // Generate a unique file name to prevent overwriting
            $target_file = $upload_dir . uniqid() . '_' . basename($file_name);
            
            // Check if this is a payroll document - skip encryption for payroll documents
            $isPayrollDocument = isPayrollDocument($file_name, '');
            
            if ($isPayrollDocument) {
                // For payroll documents, store without encryption
                $wrote = @move_uploaded_file($file_tmp_name, $target_file);
                if ($wrote) {
                    @unlink($file_tmp_name);
                }
            } else {
                // Encrypt plaintext file and write to target .enc for non-payroll documents
                $enc_iv = random_bytes(12);
                $raw = @file_get_contents($file_tmp_name);
                $target_file .= '.enc';
                $cipher = 'aes-256-gcm';
                $key = defined('FILE_ENC_KEY') ? FILE_ENC_KEY : '';
                $keyBin = ctype_xdigit($key) ? hex2bin($key) : (strlen($key) === 32 ? $key : hash('sha256', (string)$key, true));
                $tag = '';
                $ciphertext = $raw !== false ? @openssl_encrypt($raw, $cipher, $keyBin, OPENSSL_RAW_DATA, $enc_iv, $tag) : false;
                $wrote = false;
                if ($ciphertext !== false && $tag !== '') {
                    // File format: ENC1 | iv(12) | tag(16) | ciphertext
                    $encPayload = "ENC1" . $enc_iv . $tag . $ciphertext;
                    $wrote = @file_put_contents($target_file, $encPayload) !== false;
                    @unlink($file_tmp_name);
                }
            }
            if ($wrote) {
                
                $uploaded_successfully++;

                // Retrieve document-specific data from POST arrays
                $ocr_content = mysqli_real_escape_string($connection, $_POST['ocr_content'][$i] ?? '');
                $document_id = mysqli_real_escape_string($connection, $_POST['document_id'][$i] ?? '');
                $confidence = mysqli_real_escape_string($connection, $_POST['confidence'][$i] ?? '0');
                $capture_time = mysqli_real_escape_string($connection, $_POST['capture_time'][$i] ?? date('Y-m-d H:i:s'));
                $mobile_timestamp = mysqli_real_escape_string($connection, $_POST['mobile_timestamp'][$i] ?? '');
                
                // Determine file type icon based on file extension
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $fileTypeIcon = 'file'; // default
                if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
                    $fileTypeIcon = 'jpg';
                } elseif ($file_extension === 'pdf') {
                    $fileTypeIcon = 'pdf';
                } elseif (in_array($file_extension, ['doc', 'docx'])) {
                    $fileTypeIcon = 'doc';
                } elseif (in_array($file_extension, ['txt'])) {
                    $fileTypeIcon = 'txt';
                }
                
                // Keep the document type clean (no "File X of Y" suffix)
                $document_type = $type;

                // If client provides an existing tracking row id, UPDATE that row instead of inserting a new one.
                // This is the simplest way to avoid duplicates when a department re-uploads/re-photos a routed document.
                $tracking_id_raw = '';
                if (isset($_POST['tracking_id'][$i])) {
                  $tracking_id_raw = (string)$_POST['tracking_id'][$i];
                } elseif (isset($_POST['tracking_id'])) {
                  $tracking_id_raw = (string)$_POST['tracking_id'];
                }
                $tracking_id = 0;
                $tracking_id_raw = trim($tracking_id_raw);
                if ($tracking_id_raw !== '' && ctype_digit($tracking_id_raw)) {
                  $tracking_id = (int)$tracking_id_raw;
                }

                // Prefer explicit doc_hash from client if provided; else fall back to server hash of the temp file.
                $posted_doc_hash = '';
                if (isset($_POST['doc_hash'][$i])) {
                  $posted_doc_hash = mysqli_real_escape_string($connection, (string)$_POST['doc_hash'][$i]);
                } elseif (isset($_POST['doc_hash'])) {
                  $posted_doc_hash = mysqli_real_escape_string($connection, (string)$_POST['doc_hash']);
                }
                $effective_doc_hash = $posted_doc_hash !== '' ? $posted_doc_hash : $file_hash;

                // Guarantee doc_hash is not empty (some legacy uploads may omit doc_hash and file hashing may fail)
                if (trim((string)$effective_doc_hash) === '') {
                  $canonical = strtolower(trim(
                    (string)$document_type . '|' .
                    (string)$employee . '|' .
                    (string)$date . '|' .
                    (string)$department . '|' .
                    (string)$holder . '|' .
                    (string)$endLocation . '|' .
                    (string)$mobile_timestamp . '|' .
                    (string)$file_name
                  ));
                  // Include tracking_id when present for uniqueness; still deterministic.
                  $effective_doc_hash = hash('sha256', $canonical . '|' . (string)$tracking_id);
                }

                // Update existing row (file/OCR fields only) when tracking_id is provided.
                // We intentionally do NOT overwrite end_location/current_holder here; routing is handled by route_document.php.
                if ($tracking_id > 0) {
                  $sql_upd = "UPDATE tracking SET file_type_icon=?, ocr_content=?, mobile_timestamp=?, file_size=?, user_email=?, file_path=?, doc_hash=? WHERE id=?";
                  $stmt_upd = $connection->prepare($sql_upd);
                  if ($stmt_upd) {
                    $stmt_upd->bind_param(
                      'sssssssi',
                      $fileTypeIcon,
                      $ocr_content,
                      $mobile_timestamp,
                      $file_size,
                      $user_email,
                      $target_file,
                      $effective_doc_hash,
                      $tracking_id
                    );

                    if ($stmt_upd->execute()) {
                      $inserted_document_ids[] = $tracking_id;

                      // Queue Firestore upsert for after the loop (avoid HTTP round-trip per file)
                      $firestore_queue[] = ['id' => (string)$tracking_id, 'data' => [
                        'id' => (int)$tracking_id,
                        'type' => (string)$document_type,
                        'employee_name' => (string)$employee,
                        'department' => (string)$department,
                        'current_holder' => (string)$holder,
                        'end_location' => (string)$endLocation,
                        'status' => (string)$status,
                        'date_submitted' => (string)$date,
                        'file_type_icon' => (string)$fileTypeIcon,
                        'file_size' => (string)$file_size,
                        'file_path' => (string)$target_file,
                        'mobile_timestamp' => (string)$mobile_timestamp,
                        'doc_hash' => (string)$effective_doc_hash,
                        'updatedAt' => (int)round(microtime(true) * 1000),
                      ]];

                      // Best-effort: store/update OCR into secure extracted_content for this tracking row,
                      // so OCR can still be refreshed even if the document is later marked Completed.
                      try {
                        $ocrPlain = trim((string)($_POST['ocr_content'][$i] ?? ''));
                        if ($ocrPlain !== '') {
                          $docRef = '';
                          if (trim((string)$effective_doc_hash) !== '') {
                            $docRef = (string)$effective_doc_hash;
                          } elseif (trim((string)$mobile_timestamp) !== '') {
                            $docRef = (string)$mobile_timestamp;
                          } else {
                            $docRef = 'tracking:' . (string)$tracking_id;
                          }

                          $ownerUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                          $ownerDept = isset($_SESSION['department']) ? (string)$_SESSION['department'] : (string)($_SESSION['user_department'] ?? '');
                          $title = (string)$document_type;

                          $sha = hash('sha256', $ocrPlain);
                          $enc = file_crypto_encrypt_string($ocrPlain);
                          if ($enc !== false && $ownerUserId > 0) {
                            $stmtEC = $connection->prepare(
                              'INSERT INTO extracted_content (doc_ref, title, owner_user_id, owner_department, content_sha256, enc_blob)
                               VALUES (?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE title = VALUES(title), owner_department = VALUES(owner_department), content_sha256 = VALUES(content_sha256), enc_blob = VALUES(enc_blob), updated_at = CURRENT_TIMESTAMP'
                            );
                            if ($stmtEC) {
                              $stmtEC->bind_param('ssisss', $docRef, $title, $ownerUserId, $ownerDept, $sha, $enc);
                              @$stmtEC->execute();
                              $stmtEC->close();
                            }
                          }
                        }
                      } catch (Throwable $e) {
                        // ignore
                      }

                      $actor_id = $_SESSION['user_id'] ?? null;
                      $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
                      $h = $connection->prepare($hasDocTypeCol
                        ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, notes) VALUES (?, ?, 'file_update', ?, ?)"
                        : "INSERT INTO document_history (doc_id, action, actor_user_id, notes) VALUES (?, 'file_update', ?, ?)"
                      );
                      if ($h) {
                        $note = 'Mobile re-upload updated file_path/ocr_content';
                        if ($hasDocTypeCol) {
                          $h->bind_param('isis', $tracking_id, $document_type, $actor_id, $note);
                        } else {
                          $h->bind_param('iis', $tracking_id, $actor_id, $note);
                        }
                        $h->execute();
                        $h->close();
                      }

                      error_log("Updated existing tracking row: ID {$tracking_id}, File: {$file_name}");
                    } else {
                      error_log("DB Update Error for ID {$tracking_id}, file {$file_name}: " . $stmt_upd->error);
                    }
                    $stmt_upd->close();
                  } else {
                    error_log("Failed to prepare update statement for tracking_id={$tracking_id}");
                  }
                } else {
                  // --- Insert a new record (only when this is truly a new document)
                  $routeStep = 0;
                  if ($routing_queue !== '') {
                    $rqParts = array_map('trim', explode(',', $routing_queue));
                    $holderIdx = array_search(strtoupper($holder), array_map('strtoupper', $rqParts));
                    $routeStep = ($holderIdx !== false) ? max(0, (int)$holderIdx) : 0;
                  }

                  $sql_insert = "INSERT INTO tracking 
                     (type, employee_name, date_submitted, current_holder, end_location, status, department, file_type_icon, ocr_content, mobile_timestamp, file_size, user_email, file_path, doc_hash, routing_queue, route_step, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

                  $stmt_insert = $connection->prepare($sql_insert);
                  if ($stmt_insert) {
                    $stmt_insert->bind_param(
                      "ssssssssssssssss",
                      $document_type,
                      $employee,
                      $date,
                      $holder,
                      $endLocation,
                      $status,
                      $department,
                      $fileTypeIcon,
                      $ocr_content,
                      $mobile_timestamp,
                      $file_size,
                      $user_email,
                      $target_file,
                      $effective_doc_hash,
                      $routing_queue,
                      $routeStep
                    );

                    if ($stmt_insert->execute()) {
                      $inserted_id = $connection->insert_id;
                      $inserted_document_ids[] = $inserted_id;

                      // Queue Firestore upsert for after the loop (avoid HTTP round-trip per file)
                      $firestore_queue[] = ['id' => (string)$inserted_id, 'data' => [
                        'id' => (int)$inserted_id,
                        'type' => (string)$document_type,
                        'employee_name' => (string)$employee,
                        'department' => (string)$department,
                        'current_holder' => (string)$holder,
                        'end_location' => (string)$endLocation,
                        'status' => (string)$status,
                        'date_submitted' => (string)$date,
                        'file_type_icon' => (string)$fileTypeIcon,
                        'file_size' => (string)$file_size,
                        'file_path' => (string)$target_file,
                        'mobile_timestamp' => (string)$mobile_timestamp,
                        'doc_hash' => (string)$effective_doc_hash,
                        'createdAt' => (int)round(microtime(true) * 1000),
                        'updatedAt' => (int)round(microtime(true) * 1000),
                      ]];

                      // Log history: create
                      $actor_id = $_SESSION['user_id'] ?? null;
                      $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
                      $h = $connection->prepare($hasDocTypeCol
                        ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, from_holder, to_holder) VALUES (?, ?, 'create', ?, ?, ?, ?)"
                        : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, from_holder, to_holder) VALUES (?, 'create', ?, ?, ?, ?)"
                      );
                      if ($h) {
                        $fromDept = $department;
                        $toDept = $holder;
                        if ($hasDocTypeCol) {
                          $h->bind_param('isssss', $inserted_id, $document_type, $actor_id, $status, $fromDept, $toDept);
                        } else {
                          $h->bind_param('issss', $inserted_id, $actor_id, $status, $fromDept, $toDept);
                        }
                        $h->execute();
                        $h->close();
                      }

                      // Also insert into stats table for analytics
                      $stats_sql = "INSERT INTO stats (
                        name,
                        department,
                        status,
                        date_archived,
                        document_type,
                        source
                      ) VALUES (?, ?, ?, ?, ?, 'Mobile App Batch')";

                      $stats_stmt = $connection->prepare($stats_sql);
                      if ($stats_stmt) {
                        $document_name = $document_type . ' - ' . $employee;
                        $stats_stmt->bind_param("sssss", $document_name, $department, $status, $date, $document_type);
                        $stats_stmt->execute();
                        $stats_stmt->close();
                      }

                      error_log("Successfully inserted batch document: ID {$inserted_id}, File: {$file_name}");
                      
                      // Store OCR in per-page table for multi-page search
                      if (trim($ocr_content) !== '' && function_exists('ocr_store_page')) {
                          ocr_store_page($connection, 'tracking', $inserted_id, $i + 1, $ocr_content);
                      }
                    } else {
                      error_log("DB Insert Error for file {$file_name}: " . $stmt_insert->error);
                    }
                    $stmt_insert->close();
                  } else {
                    error_log("Failed to prepare statement for file: " . $file_name);
                  }
                }
            } else {
                error_log("Failed to move uploaded file: " . $file_name);
            }
        } else {
            error_log("File upload error for index {$i}: " . $file_array['error'][$i]);
        }
    }

    // --- 4. Deferred Firestore sync: send all queued upserts after DB inserts are done
    //    This avoids blocking per-file HTTP round-trips during the upload loop.
    foreach ($firestore_queue as $fsItem) {
      try {
        firestore_upsert_tracking($fsItem['id'], $fsItem['data']);
      } catch (Throwable $t) {
        error_log("Deferred Firestore upsert failed for ID {$fsItem['id']}: " . $t->getMessage());
      }
    }

    // --- 5. Return success response to the Dart app
    if ($uploaded_successfully > 0) {
        // Since this is an API call, return a JSON response instead of a redirect
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'message' => "Successfully processed {$uploaded_successfully} document(s) out of {$file_count} uploaded.",
            'uploaded_count' => $uploaded_successfully,
            'total_files' => $file_count,
            'document_ids' => $inserted_document_ids,
            'batch_info' => [
                'employee' => $employee,
                'department' => $department,
                'type' => $type
            ]
        ]);
        exit();
    } else {
        header('Content-Type: application/json');
        http_response_code(400); // Bad Request
        echo json_encode([
            'status' => 'error', 
            'message' => 'No files were successfully processed.',
            'total_files' => $file_count,
            'uploaded_count' => 0
        ]);
        exit();
    }
}

// Handle Add/Edit Document Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = isset($_POST['id']) ? mysqli_real_escape_string($connection, $_POST['id']) : ''; // Document ID for editing, empty for new
    $type = mysqli_real_escape_string($connection, $_POST['type']);
    $employee = mysqli_real_escape_string($connection, $_POST['employee']);
    $date = mysqli_real_escape_string($connection, $_POST['date']);
    $holder = mysqli_real_escape_string($connection, $_POST['holder']);
    $endLocation = mysqli_real_escape_string($connection, $_POST['endLocation']);
    $status = mysqli_real_escape_string($connection, $_POST['status']);
    $department = mysqli_real_escape_string($connection, $_POST['department']);
    $fileTypeIcon = mysqli_real_escape_string($connection, $_POST['fileTypeIcon']);

    // Compute canonical hash for non-file submissions
    $canonical = strtolower(trim($type.'|'.$employee.'|'.$date.'|'.$department.'|'.$holder.'|'.$endLocation));
    $doc_hash = hash('sha256', $canonical);

    // For simplicity, history is not directly updated via this POST,
    // but in a real system, you'd log actions here.

    if (!empty($id)) {
        // Update existing document record
        $sql = "UPDATE tracking SET type=?, employee_name=?, date_submitted=?, current_holder=?, end_location=?, status=?, department=?, file_type_icon=?, doc_hash=? WHERE id=?";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("ssssssssss", $type, $employee, $date, $holder, $endLocation, $status, $department, $fileTypeIcon, $doc_hash, $id);
    } else {
      $isAnnouncement = (strpos(strtolower(trim((string)$type)), 'announcement') === 0);

      // Preserve history: archive old rows instead of deleting them.
      // (Deleting rows destroys long-term reporting and can remove document_history if FK cascades.)
      $dup_ids = [];
      if ($sel_dup = $connection->prepare("SELECT id FROM tracking WHERE type = ? AND end_location = ?")) {
        $sel_dup->bind_param('ss', $type, $endLocation);
        if ($sel_dup->execute()) {
          $r = $sel_dup->get_result();
          while ($r && ($row = $r->fetch_assoc())) {
            if (isset($row['id'])) { $dup_ids[] = (string)$row['id']; }
          }
          if ($r) { $r->free(); }
        }
        $sel_dup->close();
      }

      if (!empty($dup_ids)) {
        if ($arch_stmt = $connection->prepare("UPDATE tracking SET status='Archived', end_location='Digital Archive' WHERE type = ? AND end_location = ?")) {
          $arch_stmt->bind_param('ss', $type, $endLocation);
          $arch_stmt->execute();
          $arch_stmt->close();
        }
        // Log archive in history for each affected doc
        $actor_id = $_SESSION['user_id'] ?? null;
        foreach ($dup_ids as $dupId) {
          $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
          if ($h2 = $connection->prepare($hasDocTypeCol
            ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder) VALUES (?, ?, 'archive', ?, 'Archived', 'Digital Archive')"
            : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, to_holder) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive')"
          )) {
            if ($hasDocTypeCol) {
              $h2->bind_param('ssi', $dupId, $type, $actor_id);
            } else {
              $h2->bind_param('si', $dupId, $actor_id);
            }
            $h2->execute();
            $h2->close();
          }
        }
      }
        
        if ($isAnnouncement) {
          $deptList = __tracking_load_departments($connection);
          $groupKey = strtolower(trim('announcement|' . $employee . '|' . $date));
          $groupHash = hash('sha256', $groupKey);

          // Archive any existing announcement broadcast for the same employee+date
          if ($selOld = $connection->prepare("SELECT id FROM tracking WHERE (LOWER(TRIM(type)) = 'announcement' OR LOWER(TRIM(type)) LIKE 'announcement %') AND doc_hash = ?")) {
            $selOld->bind_param('s', $groupHash);
            if ($selOld->execute()) {
              $r = $selOld->get_result();
              while ($r && ($row = $r->fetch_assoc())) {
                if (isset($row['id'])) $dup_ids[] = (string)$row['id'];
              }
              if ($r) { $r->free(); }
            }
            $selOld->close();
          }
          if (!empty($dup_ids)) {
            foreach (array_unique($dup_ids) as $dupId) {
              if ($u = $connection->prepare("UPDATE tracking SET status='Archived', end_location='Digital Archive' WHERE id=?")) {
                $u->bind_param('s', $dupId);
                $u->execute();
                $u->close();
              }
            }
          }

          $insSql = "INSERT INTO tracking (type, employee_name, date_submitted, current_holder, end_location, status, department, file_type_icon, doc_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $stmt = $connection->prepare($insSql);
          if (!$stmt) {
            header("Location: tracking.php?status=error");
            exit();
          }

          $allOk = true;
          $newFirstId = null;
          foreach ($deptList as $deptName) {
            $deptName = strtoupper(trim((string)$deptName));
            if ($deptName === '') continue;
            $broadcastHolder = $deptName;
            $broadcastEnd = $deptName;
            $broadcastStatus = 'Pending';
            $broadcastDept = $deptName;
            $stmt->bind_param('sssssssss', $type, $employee, $date, $broadcastHolder, $broadcastEnd, $broadcastStatus, $broadcastDept, $fileTypeIcon, $groupHash);
            if (!$stmt->execute()) {
              $allOk = false;
              break;
            }
            if ($newFirstId === null) {
              $newFirstId = $connection->insert_id;
            }
          }
          if (!$allOk) {
            error_log('Announcement broadcast insert failed: ' . $stmt->error);
            $stmt->close();
            header("Location: tracking.php?status=error");
            exit();
          }
          $stmt->close();
          header("Location: tracking.php?status=added");
          exit();
        } else {
          // Add new document record
          $sql = "INSERT INTO tracking (type, employee_name, date_submitted, current_holder, end_location, status, department, file_type_icon, doc_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $stmt = $connection->prepare($sql);
          $stmt->bind_param("sssssssss", $type, $employee, $date, $holder, $endLocation, $status, $department, $fileTypeIcon, $doc_hash);
        }
    }

    if ($stmt->execute()) {
        // Log history for add/update
        $actor_id = $_SESSION['user_id'] ?? null;
        $affected_id = null;
        if (!empty($id)) {
            $affected_id = $id;
            $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
            $h = $connection->prepare($hasDocTypeCol
              ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder) VALUES (?, ?, 'update', ?, ?, ?)"
              : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, to_holder) VALUES (?, 'update', ?, ?, ?)"
            );
            if ($h) {
              if ($hasDocTypeCol) {
                $h->bind_param('issss', $affected_id, $type, $actor_id, $status, $holder);
              } else {
                $h->bind_param('isss', $affected_id, $actor_id, $status, $holder);
              }
              $h->execute();
              $h->close();
            }
        } else {
            $affected_id = $connection->insert_id;
            $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
            $h = $connection->prepare($hasDocTypeCol
              ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, from_holder, to_holder) VALUES (?, ?, 'create', ?, ?, ?, ?)"
              : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, from_holder, to_holder) VALUES (?, 'create', ?, ?, ?, ?)"
            );
            if ($h) {
              $fromDept = $department;
              $toDept = $holder;
              if ($hasDocTypeCol) {
                $h->bind_param('isssss', $affected_id, $type, $actor_id, $status, $fromDept, $toDept);
              } else {
                $h->bind_param('issss', $affected_id, $actor_id, $status, $fromDept, $toDept);
              }
              $h->execute();
              $h->close();
            }

          // Also insert into stats table for long-term reporting (if available)
          $statsExists = false;
          if ($chk = $connection->query("SHOW TABLES LIKE 'stats'")) {
            $statsExists = ($chk->num_rows > 0);
            $chk->free();
          }
          if ($statsExists) {
            $stats_sql = "INSERT INTO stats (name, department, status, date_archived, document_type, source) VALUES (?, ?, ?, ?, ?, 'Manual Entry')";
            if ($stats_stmt = $connection->prepare($stats_sql)) {
              $document_name = $type . ' - ' . $employee;
              $stats_stmt->bind_param('sssss', $document_name, $department, $status, $date, $type);
              $stats_stmt->execute();
              $stats_stmt->close();
            }
          }
        }

        // If a document is saved as Completed, auto-archive it
        if (strcasecmp($status, 'Completed') === 0 && $affected_id) {
            $stmtA = $connection->prepare("UPDATE tracking SET status='Archived', end_location='Digital Archive' WHERE id=?");
            if ($stmtA) {
                $stmtA->bind_param('s', $affected_id);
                if ($stmtA->execute()) {
                    $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
                    $h2 = $connection->prepare($hasDocTypeCol
                      ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder) VALUES (?, ?, 'archive', ?, 'Archived', 'Digital Archive')"
                      : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, to_holder) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive')"
                    );
                    if ($h2) {
                      if ($hasDocTypeCol) {
                        $h2->bind_param('iss', $affected_id, $type, $actor_id);
                      } else {
                        $h2->bind_param('is', $affected_id, $actor_id);
                      }
                      $h2->execute();
                      $h2->close();
                    }
                    header("Location: tracking.php?status=archived_auto");
                    exit();
                }
            }
        }

        header("Location: tracking.php?status=" . (empty($id) ? "added" : "updated"));
        exit();
    } else {
        error_log("Error: " . $stmt->error);
        header("Location: tracking.php?status=error");
        exit();
    }
    
}

// Handle Delete Document Request
if (isset($_GET['delete_id'])) {
    Security::require_login();
    Security::require_role(['admin']);
    $delete_id = mysqli_real_escape_string($connection, $_GET['delete_id']);
    // capture state
    $prev_holder = $prev_status = null;
    $prev_type = '';
    if ($sel = $connection->prepare("SELECT current_holder, status, type FROM tracking WHERE id=?")) {
        $sel->bind_param('s', $delete_id);
        if ($sel->execute()) {
            $r = $sel->get_result();
            if ($r && ($row = $r->fetch_assoc())) { $prev_holder = $row['current_holder']; $prev_status = $row['status']; $prev_type = $row['type'] ?? ''; }
        }
        $sel->close();
    }
    $sql = "DELETE FROM tracking WHERE id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("s", $delete_id); // 's' for string id

    if ($stmt->execute()) {
        if (function_exists('firestore_delete_tracking')) {
            try {
                firestore_delete_tracking((string)$delete_id);
            } catch (Throwable $t) {
                // best-effort only
            }
        }
        try {
            tracking_firestore_delete_linked_notifications($connection, (int)$delete_id);
        } catch (Throwable $t) {
            // best-effort only
        }
        $actor_id = $_SESSION['user_id'] ?? null;
        $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
        $h = $connection->prepare($hasDocTypeCol
          ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, from_status, from_holder) VALUES (?, ?, 'delete', ?, ?, ?)"
          : "INSERT INTO document_history (doc_id, action, actor_user_id, from_status, from_holder) VALUES (?, 'delete', ?, ?, ?)"
        );
        if ($h) {
          if ($hasDocTypeCol) {
            $h->bind_param('issss', $delete_id, $prev_type, $actor_id, $prev_status, $prev_holder);
          } else {
            $h->bind_param('isss', $delete_id, $actor_id, $prev_status, $prev_holder);
          }
          $h->execute();
          $h->close();
        }
        header("Location: tracking.php?status=deleted");
        exit();
    } else {
        error_log("Error deleting record: " . $stmt->error);
        header("Location: tracking.php?status=delete_error");
        exit();
    }
    
}

// Handle Route Document Request (via GET for simplicity, but POST is better for real apps)
if (isset($_GET['route_id']) && isset($_GET['new_holder']) && isset($_GET['new_status'])) {
    Security::require_login();
    Security::require_role(['admin']);
    $route_id = mysqli_real_escape_string($connection, $_GET['route_id']);
    $new_holder = mysqli_real_escape_string($connection, $_GET['new_holder']);
    $new_status = mysqli_real_escape_string($connection, $_GET['new_status']);

    // In a real system, you'd also insert a record into a 'document_history' table here
    // For this example, we'll just update the main 'tracking' table.

    // Capture previous values for history
    $prev_holder = $prev_status = null;
    $route_doc_type = '';
    if ($sel = $connection->prepare("SELECT current_holder, status, type FROM tracking WHERE id=?")) {
        $sel->bind_param('s', $route_id);
        if ($sel->execute()) {
            $r = $sel->get_result();
            if ($r && ($row = $r->fetch_assoc())) { $prev_holder = $row['current_holder']; $prev_status = $row['status']; $route_doc_type = $row['type'] ?? ''; }
        }
        $sel->close();
    }

    // Route to Completed: just update status, do NOT auto-archive.
    // Each department/admin archives independently via the manual archive button.
    if (strcasecmp($new_status, 'Completed') === 0) {
        $actor_id = $_SESSION['user_id'] ?? null;
        // Log routing to Completed
        $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
        $h1 = $connection->prepare($hasDocTypeCol
          ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, ?, 'route', ?, ?, 'Completed', ?, ?)"
          : "INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, 'route', ?, ?, 'Completed', ?, ?)"
        );
        if ($h1) {
          if ($hasDocTypeCol) {
            $h1->bind_param('isssss', $route_id, $route_doc_type, $actor_id, $prev_status, $prev_holder, $new_holder);
          } else {
            $h1->bind_param('issss', $route_id, $actor_id, $prev_status, $prev_holder, $new_holder);
          }
          $h1->execute();
          $h1->close();
        }

        // Update tracking row to Completed status (no archive, no delete)
        if ($upd = $connection->prepare("UPDATE tracking SET status='Completed', current_holder=?, end_location=? WHERE id=?")) {
            $upd->bind_param('sss', $new_holder, $new_holder, $route_id);
            $upd->execute();
            $upd->close();
        }

        header("Location: tracking.php?status=completed");
        exit();
    } else {
        // Regular route update

        // ── Payroll Fixed Routing: HR → CBO → ACCOUNTING ──
        $payrollFixedRoute = ['HR', 'CBO', 'ACCOUNTING', 'CAO', 'CTO'];
        if (stripos($route_doc_type, 'payroll') !== false && $prev_holder) {
          $prevIdx = array_search(strtoupper($prev_holder), $payrollFixedRoute);
          if ($prevIdx !== false && ($prevIdx + 1) < count($payrollFixedRoute)) {
            $new_holder = $payrollFixedRoute[$prevIdx + 1];
          }
          // Also store routing_queue on the tracking row for sequential auto-advance
          @$connection->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS routing_queue TEXT DEFAULT NULL");
          @$connection->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS route_step INT DEFAULT 0");
          $rq = implode(',', $payrollFixedRoute);
          $curStep = ($prevIdx !== false) ? $prevIdx + 1 : 0;
          $qUpd = $connection->prepare("UPDATE tracking SET routing_queue = ?, route_step = ? WHERE id = ?");
          if ($qUpd) { $qUpd->bind_param('sis', $rq, $curStep, $route_id); $qUpd->execute(); $qUpd->close(); }
        }

        // Prevent self-routing: if prev_holder equals new_holder, skip the route entirely
        if (strcasecmp(trim($prev_holder ?? ''), trim($new_holder)) === 0) {
            header("Location: tracking.php?status=route_same_dept");
            exit();
        }

        $sql = "UPDATE tracking SET current_holder=?, status=? WHERE id=?";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("sss", $new_holder, $new_status, $route_id);

        if ($stmt->execute()) {
            // ── Build / update routing_queue so every department that has handled
            //    this document stays in the queue.  This is the key to department-
            //    scoped visibility: once a dept processes a doc it remains visible
            //    in their tracking view until the doc is archived. ──
            $__curRQ  = null;
            $__rid    = (int)$route_id;
            $__rqSel  = $connection->prepare("SELECT routing_queue FROM tracking WHERE id = ?");
            if ($__rqSel) {
                $__rqSel->bind_param('i', $__rid);
                $__rqSel->execute();
                $__rqSel->bind_result($__curRQ);
                $__rqSel->fetch();
                $__rqSel->close();
            }

            $__rqParts = [];
            if (!empty($__curRQ)) {
                // Existing routing_queue – normalise to uppercase
                $__rqParts = array_map(function ($s) { return strtoupper(trim($s)); }, explode(',', $__curRQ));
            } else {
                // No routing_queue yet – back-fill from document_history so that
                // departments which already routed the document are captured.
                $__bfQ = $connection->prepare(
                    "SELECT from_holder, to_holder FROM document_history WHERE doc_id = ? AND action = 'route' ORDER BY id ASC"
                );
                if ($__bfQ) {
                    $__bfQ->bind_param('i', $__rid);
                    $__bfQ->execute();
                    $__bfRes = $__bfQ->get_result();
                    while ($__bfRes && ($__bfRow = $__bfRes->fetch_assoc())) {
                        $__fh = strtoupper(trim($__bfRow['from_holder'] ?? ''));
                        $__th = strtoupper(trim($__bfRow['to_holder'] ?? ''));
                        if ($__fh !== '' && !in_array($__fh, $__rqParts)) $__rqParts[] = $__fh;
                        if ($__th !== '' && !in_array($__th, $__rqParts)) $__rqParts[] = $__th;
                    }
                    if ($__bfRes) $__bfRes->free();
                    $__bfQ->close();
                }
                // Also include the document's origin department if missing
                $__origDept = null;
                $__odSel = $connection->prepare("SELECT department FROM tracking WHERE id = ?");
                if ($__odSel) {
                    $__odSel->bind_param('i', $__rid);
                    $__odSel->execute();
                    $__odSel->bind_result($__origDept);
                    $__odSel->fetch();
                    $__odSel->close();
                }
                $__origUp = strtoupper(trim($__origDept ?? ''));
                if ($__origUp !== '' && !in_array($__origUp, $__rqParts)) {
                    array_unshift($__rqParts, $__origUp); // origin goes first
                }
            }

            // Append prev_holder and new_holder if not already present
            $__prevUp = strtoupper(trim($prev_holder ?? ''));
            $__newUp  = strtoupper(trim($new_holder));
            if ($__prevUp !== '' && !in_array($__prevUp, $__rqParts)) {
                $__rqParts[] = $__prevUp;
            }
            if ($__newUp !== '' && !in_array($__newUp, $__rqParts)) {
                $__rqParts[] = $__newUp;
            }

            // route_step = 0-based index of new_holder in the queue
            $__newRQ      = implode(',', $__rqParts);
            $__newStepIdx = array_search($__newUp, $__rqParts);
            $__newStep    = ($__newStepIdx !== false) ? (int)$__newStepIdx : max(0, count($__rqParts) - 1);

            // Persist; GREATEST prevents route_step from ever going backwards
            $__rqUpd = $connection->prepare("UPDATE tracking SET routing_queue = ?, route_step = GREATEST(COALESCE(route_step, 0), ?) WHERE id = ?");
            if ($__rqUpd) {
                $__rqUpd->bind_param('sii', $__newRQ, $__newStep, $__rid);
                $__rqUpd->execute();
                $__rqUpd->close();
            }

            $actor_id = $_SESSION['user_id'] ?? null;
            $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
            $h = $connection->prepare($hasDocTypeCol
              ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, ?, 'route', ?, ?, ?, ?, ?)"
              : "INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, 'route', ?, ?, ?, ?, ?)"
            );
            if ($h) {
              if ($hasDocTypeCol) {
                $h->bind_param('issssss', $route_id, $route_doc_type, $actor_id, $prev_status, $new_status, $prev_holder, $new_holder);
              } else {
                $h->bind_param('isssss', $route_id, $actor_id, $prev_status, $new_status, $prev_holder, $new_holder);
              }
              $h->execute();
              $h->close();
            }

        // IMPORTANT: When routing from the web UI, also create a notification for
        // the receiving department so the mobile dashboard gets a real tracking_id.
        try {
          $doc = null;
          if ($sdoc = $connection->prepare("SELECT type, employee_name, end_location, file_path, mobile_timestamp FROM tracking WHERE id=? LIMIT 1")) {
            $sdoc->bind_param('s', $route_id);
            if ($sdoc->execute()) {
              $res = $sdoc->get_result();
              $doc = $res ? $res->fetch_assoc() : null;
            }
            $sdoc->close();
          }
          if ($doc) {
            $notifUrl = rtrim((string)(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI'] ?? '')), '/') . '/api/notifications.php';
            // tracking.php lives in lib/OCR(UPDATED)/, so ensure we point to lib/OCR(UPDATED)/api/notifications.php
            // If the computed URL isn't correct, fall back to localhost default.
            if (strpos($notifUrl, '/lib/OCR(UPDATED)/') === false) {
              $notifUrl = 'http://localhost/flutter_application_7/lib/OCR(UPDATED)/api/notifications.php';
            }
            $docType = trim((string)($doc['type'] ?? 'Document'));
            $employee = trim((string)($doc['employee_name'] ?? ''));
            $filePath = trim((string)($doc['file_path'] ?? ''));
            $mobileTs = trim((string)($doc['mobile_timestamp'] ?? ''));
            $endLoc = trim((string)($doc['end_location'] ?? ''));
            $senderName = trim((string)($_SESSION['user_name'] ?? ($_SESSION['username'] ?? '')));
            if ($senderName === '') { $senderName = 'Admin'; }

            $payload = [
              'action' => 'create',
              'recipient_department' => $new_holder,
              'recipient_username' => '',
              'type' => $docType,
              'title' => $docType,
              'content' => ($employee !== '' ? ($docType . ' • ' . $employee) : ($docType . ' • Routed')),
              'message' => ($employee !== '' ? ($docType . ' • ' . $employee) : ($docType . ' • Routed')),
              'sender_username' => $senderName,
              'department' => $new_holder,
              'file_url' => $filePath,
              'tracking_id' => (int)$route_id,
              'mobile_timestamp' => $mobileTs,
              'end_location' => $endLoc,
              'current_holder' => $new_holder,
            ];
            @file_get_contents($notifUrl, false, stream_context_create([
              'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
                'timeout' => 2,
              ],
            ]));
          }
        } catch (Throwable $t) {
          // best-effort only
        }

        // Sync routing change to Firestore so other open browsers see it in real-time
        try {
            if (function_exists('firestore_upsert_tracking')) {
                $__fsData = [
                    'id'             => (int)$route_id,
                    'current_holder' => (string)$new_holder,
                    'status'         => (string)$new_status,
                    'updatedAt'      => (int)round(microtime(true) * 1000),
                ];
                if (isset($__newRQ) && $__newRQ !== '') {
                    $__fsData['routing_queue'] = (string)$__newRQ;
                }
                if (isset($__newStep)) {
                    $__fsData['route_step'] = (int)$__newStep;
                }
                firestore_upsert_tracking((string)$route_id, $__fsData);
            }
        } catch (Throwable $__fsErr) {
            // best-effort only
        }

            header("Location: tracking.php?status=routed&routed_id=" . urlencode((string)$route_id));
            exit();
        } else {
            error_log("Error routing document: " . $stmt->error);
            header("Location: tracking.php?status=route_error");
            exit();
        }
    }
}

// Handle Archive Document Request (via GET for simplicity)
// Group-archive for multi-department announcements: per-department archive isolation.
// Each department/admin archives independently; tracking rows are never deleted.
// Supports: ?action=archive_group&id=123 (id is any member of the group)
if (isset($_GET['action']) && $_GET['action'] === 'archive_group') {
  Security::require_login();
  // Allow any authenticated user to group-archive (per-department isolation)

  $seed_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($seed_id <= 0) {
    header("Location: tracking.php?status=archive_error");
    exit();
  }

  // Load the seed row to infer group identity.
  $seed = null;
  if ($s = $connection->prepare("SELECT id, employee_name, type, file_size, file_type_icon, file_path, doc_hash, mobile_timestamp, date_submitted, created_at FROM tracking WHERE id=?")) {
    $s->bind_param('i', $seed_id);
    if ($s->execute()) {
      $res = $s->get_result();
      $seed = $res ? $res->fetch_assoc() : null;
    }
    $s->close();
  }
  if (!$seed) {
    header("Location: tracking.php?status=archive_error");
    exit();
  }

  // Only announcements are group-archived.
  $seedType = trim((string)($seed['type'] ?? ''));
  $seedTypeNorm = strtolower($seedType);
  if ($seedTypeNorm !== 'announcement' && strpos($seedTypeNorm, 'announcement ') !== 0 && strpos($seedTypeNorm, 'announcement(') !== 0) {
    error_log('[archive_group] Seed type is not announcement: ' . $seedType);
    header("Location: tracking.php?status=archive_error");
    exit();
  }

  $docHash = trim((string)($seed['doc_hash'] ?? ''));
  $filePath = trim((string)($seed['file_path'] ?? ''));
  $mobileTs = trim((string)($seed['mobile_timestamp'] ?? ''));
  $empName = trim((string)($seed['employee_name'] ?? ''));
  $dateSubmitted = trim((string)($seed['date_submitted'] ?? ''));
  if ($dateSubmitted === '') {
    $dateSubmitted = trim((string)($seed['created_at'] ?? ''));
  }

  // Handle suffix like "Announcement (File 1 of 3)".
  $typeWhere = "(LOWER(TRIM(type)) = 'announcement' OR LOWER(TRIM(type)) LIKE 'announcement %' OR LOWER(TRIM(type)) LIKE 'announcement(%')";

  // Build a group query: same announcement identity on the same submitted date.
  // Try multiple strategies in order: doc_hash, file_path, mobile_timestamp, employee_name+date.
  $groupRows = [];
  $identityStrategies = [];

  if ($docHash !== '') {
    $identityStrategies[] = ['where' => 'doc_hash = ?', 'types' => 's', 'vals' => [$docHash]];
  }
  if ($filePath !== '') {
    $identityStrategies[] = ['where' => 'file_path = ?', 'types' => 's', 'vals' => [$filePath]];
  }
  if ($mobileTs !== '') {
    $identityStrategies[] = ['where' => 'mobile_timestamp = ?', 'types' => 's', 'vals' => [$mobileTs]];
  }
  if ($empName !== '') {
    $identityStrategies[] = ['where' => 'employee_name = ?', 'types' => 's', 'vals' => [$empName]];
  }
  // Last resort: just the seed row itself
  $identityStrategies[] = ['where' => 'id = ?', 'types' => 'i', 'vals' => [$seed_id]];

  foreach ($identityStrategies as $strategy) {
    $whereId = $strategy['where'];
    $bindTypes = $strategy['types'];
    $bindVals = $strategy['vals'];

    // date_submitted constraint (optional)
    $dateWhere = '';
    if ($dateSubmitted !== '' && $whereId !== 'id = ?') {
      $dateWhere = " AND DATE(COALESCE(created_at, date_submitted)) = DATE(?)";
      $bindTypes .= 's';
      $bindVals[] = $dateSubmitted;
    }

    $sql = "SELECT id, employee_name, department, type, status, file_size, file_type_icon, file_path, doc_hash, mobile_timestamp, ocr_content FROM tracking WHERE $typeWhere AND $whereId $dateWhere";
    if ($stmt = $connection->prepare($sql)) {
      if ($bindTypes !== '') {
        tracking_bind_params($stmt, $bindTypes, $bindVals);
      }
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res) {
          $groupRows = [];
          while ($row = $res->fetch_assoc()) {
            $groupRows[] = $row;
          }
        }
      }
      $stmt->close();
    }
    if (count($groupRows) > 0) break; // Found group members
  }

  if (count($groupRows) <= 0) {
    error_log('[archive_group] No group rows found for seed_id=' . $seed_id . ' type=' . $seedType);
    header("Location: tracking.php?status=archive_error");
    exit();
  }

  // Only allow archiving once ALL recipient departments are Completed or already Archived.
  foreach ($groupRows as $r) {
    $st = strtolower(trim((string)($r['status'] ?? '')));
    if ($st !== 'completed' && $st !== 'archived') {
      header("Location: tracking.php?status=archive_wait");
      exit();
    }
  }

  // Insert a SINGLE archive row for the whole announcement group.
  // Department is set to 'Multiple' to avoid per-department duplicates.
  $archiveRowId = null;
  $doc_name = (string)($seed['employee_name'] ?? '');
  $dtype = $seedType;
  $dept = 'Multiple';

  if ($chk = $connection->prepare("SELECT id FROM archive WHERE document_name = ? AND department = ? AND type = ? AND status = 'Archived' LIMIT 1")) {
    $chk->bind_param('sss', $doc_name, $dept, $dtype);
    if ($chk->execute()) {
      $cres = $chk->get_result();
      if ($cres && ($crow = $cres->fetch_assoc())) {
        $archiveRowId = (int)$crow['id'];
      }
    }
    $chk->close();
  }

  $date_archived = date('Y-m-d H:i:s');
  $fsize = (string)($seed['file_size'] ?? '');
  $ftype = (string)($seed['file_type_icon'] ?? 'file');
  $fpath = (string)($seed['file_path'] ?? '');
  $ocr_text = (string)($seed['ocr_content'] ?? '');

  if (!$archiveRowId) {
    $nextIdResult = $connection->query("SELECT MAX(id) as max_id FROM archive");
    $nextId = 1;
    if ($nextIdResult && ($row = $nextIdResult->fetch_assoc())) {
      $nextId = ((int)($row['max_id'] ?? 0)) + 1;
    }
    $hasSourceTrackingId = __tracking_archive_has_source_tracking_id($connection);
    $sqlIns = $hasSourceTrackingId
        ? "INSERT INTO archive (id, document_name, department, type, status, date_archived, size, file_type_icon, file_path, ocr_content, source_tracking_id) VALUES (?, ?, ?, ?, 'Archived', ?, ?, ?, ?, ?, ?)"
        : "INSERT INTO archive (id, document_name, department, type, status, date_archived, size, file_type_icon, file_path, ocr_content) VALUES (?, ?, ?, ?, 'Archived', ?, ?, ?, ?, ?)";
    $ins = $connection->prepare($sqlIns);
    if (!$ins) {
      header("Location: tracking.php?status=archive_error");
      exit();
    }
    $seedIdInt = (int)$seed_id;
    if ($hasSourceTrackingId) {
      $ins->bind_param('issssssssi', $nextId, $doc_name, $dept, $dtype, $date_archived, $fsize, $ftype, $fpath, $ocr_text, $seedIdInt);
    } else {
      $ins->bind_param('issssssss', $nextId, $doc_name, $dept, $dtype, $date_archived, $fsize, $ftype, $fpath, $ocr_text);
    }
    if (!$ins->execute()) {
      error_log('Group archive insert failed: ' . $ins->error);
      header("Location: tracking.php?status=archive_error");
      exit();
    }
    $ins->close();
    $archiveRowId = $nextId;

    // Store which department performed the archive action
    if (__tracking_archive_has_archived_by_dept($connection)) {
        $archiverDept = $_SESSION['user_department'] ?? $_SESSION['department'] ?? '';
        if ($archiverDept !== '') {
            $upAbd = $connection->prepare("UPDATE archive SET archived_by_department = ? WHERE id = ?");
            if ($upAbd) {
                $upAbd->bind_param('si', $archiverDept, $archiveRowId);
                $upAbd->execute();
                $upAbd->close();
            }
        }
    }
  } else {
    if ($upd = $connection->prepare("UPDATE archive SET status='Archived', date_archived=?, size=?, file_type_icon=?, file_path=?, ocr_content=COALESCE(NULLIF(?, ''), ocr_content) WHERE id=?")) {
      $upd->bind_param('sssssi', $date_archived, $fsize, $ftype, $fpath, $ocr_text, $archiveRowId);
      $upd->execute();
      $upd->close();
    }
    // Also update archived_by_department on existing archive row
    if (__tracking_archive_has_archived_by_dept($connection)) {
        $archiverDept = $_SESSION['user_department'] ?? $_SESSION['department'] ?? '';
        if ($archiverDept !== '') {
            $upAbd = $connection->prepare("UPDATE archive SET archived_by_department = ? WHERE id = ?");
            if ($upAbd) {
                $upAbd->bind_param('si', $archiverDept, $archiveRowId);
                $upAbd->execute();
                $upAbd->close();
            }
        }
    }
  }

  // Copy OCR from seed tracking row into archive (best-effort).
  if (function_exists('ocr_copy_to_archive') && $archiveRowId > 0) {
    ocr_copy_to_archive($connection, (int)$seed_id, (int)$archiveRowId);
  }

  // Copy document_history to archive_history so audit trail survives in archive.php
  if (function_exists('__tracking_copy_history_to_archive') && $archiveRowId > 0) {
    __tracking_copy_history_to_archive($connection, (int)$seed_id, (int)$archiveRowId);
  }

  // Per-department archive isolation: record this department's archive action
  // for each group row. The tracking rows are NOT deleted — other departments
  // still see them until they independently archive.
  $actor_id = $_SESSION['user_id'] ?? null;
  $archivingDept = strtoupper(trim($__isAdmin ? 'ADMIN' : ($_SESSION['user_department'] ?? $_SESSION['department'] ?? '')));
  $archUserId = (int)($_SESSION['user_id'] ?? 0);
  foreach ($groupRows as $r) {
    $rid = (int)($r['id'] ?? 0);
    if ($rid <= 0) continue;

    try { tracking_firestore_delete_linked_notifications($connection, (int)$rid); } catch (Throwable $t) {}

    // Record department archive
    if ($archivingDept !== '') {
        $daStmt = $connection->prepare("INSERT IGNORE INTO department_archives (tracking_id, department, archived_by_user_id) VALUES (?, ?, ?)");
        if ($daStmt) {
            $daStmt->bind_param('isi', $rid, $archivingDept, $archUserId);
            $daStmt->execute();
            $daStmt->close();
        }
    }

    $rType = $r['type'] ?? '';
    $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
    $h = $connection->prepare($hasDocTypeCol
      ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder, notes) VALUES (?, ?, 'archive', ?, 'Archived', 'Digital Archive', 'Group archived announcement')"
      : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, to_holder, notes) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive', 'Group archived announcement')"
    );
    if ($h) {
      if ($hasDocTypeCol) {
        $h->bind_param('iss', $rid, $rType, $actor_id);
      } else {
        $h->bind_param('is', $rid, $actor_id);
      }
      $h->execute();
      $h->close();
    }
  }

  // Also log the final archive event into archive_history
  if ($archiveRowId > 0) {
    $ah = @$connection->prepare("INSERT INTO archive_history (archive_id, action, actor_user_id, to_status, to_holder, notes, created_at) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive', 'Document Archived', NOW())");
    if ($ah) { $actorInt = (int)($actor_id ?? 0); $ah->bind_param('ii', $archiveRowId, $actorInt); @$ah->execute(); $ah->close(); }
  }

  header("Location: archive.php?status=archived");
  exit();
}

if (isset($_GET['archive_id'])) {
    Security::require_login();
    // Allow any authenticated user (not just admin) to archive documents
    $archive_id = mysqli_real_escape_string($connection, $_GET['archive_id']);
    
    // Validate archive_id - must be a positive integer
    if (!is_numeric($archive_id) || $archive_id <= 0) {
        header("Location: tracking.php?status=archive_error");
        exit();
    }

    // Move from tracking to archive table (or mobile-only handling for gallery docs)
    $doc = null;
    if ($sdoc = $connection->prepare("SELECT employee_name, department, type, file_size, file_type_icon, file_path, doc_hash, mobile_timestamp, ocr_content FROM tracking WHERE id=?")) {
        $sdoc->bind_param('s', $archive_id);
        if ($sdoc->execute()) { $res = $sdoc->get_result(); $doc = $res ? $res->fetch_assoc() : null; }
        $sdoc->close();
    }
    if (!$doc) { header("Location: tracking.php?status=archive_error"); exit(); }

    // If this document originated from the mobile gallery, do not create a web archive row.
    // It is already mirrored into mobile_archive via sync_document.php; here we only
    // retire it from the active tracking list and log the action.
    $isGalleryDoc = !empty($doc['mobile_timestamp']) && strpos((string)$doc['mobile_timestamp'], 'GALLERY_') === 0;
    if ($isGalleryDoc) {
        if ($del = $connection->prepare("DELETE FROM tracking WHERE id=?")) {
            $del->bind_param('s', $archive_id);
            $del->execute();
            $del->close();
        }
        if (function_exists('firestore_delete_tracking')) {
            try {
                firestore_delete_tracking((string)$archive_id);
            } catch (Throwable $t) {
                // best-effort only
            }
        }
        try {
            tracking_firestore_delete_linked_notifications($connection, (int)$archive_id);
        } catch (Throwable $t) {
            // best-effort only
        }
        $actor_id = $_SESSION['user_id'] ?? null;
        $galleryDocType = $doc['type'] ?? '';
        $h = $connection->prepare("INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder) VALUES (?, ?, 'archive', ?, 'Archived', 'Digital Archive')");
        if ($h) { $h->bind_param('sss', $archive_id, $galleryDocType, $actor_id); $h->execute(); $h->close(); }
        header("Location: tracking.php?status=archived");
        exit();
    }
    
    // Try to avoid inserting obvious duplicates into archive using only existing columns.
    // We check for an existing row with the same document_name, department, type and status.
    $existingArchiveId = null;
    if ($chk = $connection->prepare("SELECT id FROM archive WHERE document_name = ? AND department = ? AND type = ? AND status = 'Archived' LIMIT 1")) {
        $doc_name = $doc['employee_name'];
        $dept = $doc['department'];
        $dtype = $doc['type'];
        $chk->bind_param('sss', $doc_name, $dept, $dtype);
        if ($chk->execute()) {
            $cres = $chk->get_result();
            if ($cres && ($crow = $cres->fetch_assoc())) {
                $existingArchiveId = $crow['id'];
            }
        }
        $chk->close();
    }

    $archiveRowId = $existingArchiveId;
    if ($existingArchiveId === null) {
        // Get next available ID for archive table
        $nextIdResult = $connection->query("SELECT MAX(id) as max_id FROM archive");
        $nextId = 1;
        if ($nextIdResult && ($row = $nextIdResult->fetch_assoc())) {
            $nextId = ($row['max_id'] ?? 0) + 1;
        }
        
        // Insert archive row including file_path, ocr_content (and source_tracking_id when available) so preview, search, and history work
        $hasSourceTrackingId = __tracking_archive_has_source_tracking_id($connection);
        $sqlIns = $hasSourceTrackingId
            ? "INSERT INTO archive (id, document_name, department, type, status, date_archived, size, file_type_icon, file_path, ocr_content, source_tracking_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            : "INSERT INTO archive (id, document_name, department, type, status, date_archived, size, file_type_icon, file_path, ocr_content) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $ins = $connection->prepare($sqlIns);
        if ($ins) {
            $doc_name = $doc['employee_name'];
            $dept = $doc['department'];
            $dtype = $doc['type'];
            $status = 'Archived';
            $date_archived = date('Y-m-d H:i:s');
            $fsize = (string)($doc['file_size'] ?? '');
            $ftype = (string)($doc['file_type_icon'] ?? 'file');
            $fpath = (string)($doc['file_path'] ?? '');
            $ocr_text = (string)($doc['ocr_content'] ?? '');
            $srcTrackId = (int)$archive_id;
            if ($hasSourceTrackingId) {
                $ins->bind_param('isssssssssi', $nextId, $doc_name, $dept, $dtype, $status, $date_archived, $fsize, $ftype, $fpath, $ocr_text, $srcTrackId);
            } else {
                $ins->bind_param('isssssssss', $nextId, $doc_name, $dept, $dtype, $status, $date_archived, $fsize, $ftype, $fpath, $ocr_text);
            }
            if (!$ins->execute()) {
                error_log('Archive insert failed: ' . $ins->error);
                header("Location: tracking.php?status=archive_error");
                exit();
            }
            $archiveRowId = $nextId;

            // Store which department performed the archive action
            if (__tracking_archive_has_archived_by_dept($connection)) {
                $archiverDept = $_SESSION['user_department'] ?? $_SESSION['department'] ?? '';
                if ($archiverDept !== '') {
                    $upAbd = $connection->prepare("UPDATE archive SET archived_by_department = ? WHERE id = ?");
                    if ($upAbd) {
                        $upAbd->bind_param('si', $archiverDept, $archiveRowId);
                        $upAbd->execute();
                        $upAbd->close();
                    }
                }
            }
        } else {
            error_log('Archive insert prepare failed: ' . $connection->error);
            header("Location: tracking.php?status=archive_error");
            exit();
        }
    } else {
        // Refresh existing archive row to keep file_path and ocr_content aligned with the tracking record
        if ($upd = $connection->prepare("UPDATE archive SET status='Archived', date_archived=?, size=?, file_type_icon=?, file_path=?, ocr_content=COALESCE(NULLIF(?, ''), ocr_content) WHERE id=?")) {
            $date_archived = date('Y-m-d H:i:s');
            $fsize = (string)($doc['file_size'] ?? '');
            $ftype = (string)($doc['file_type_icon'] ?? 'file');
            $fpath = (string)($doc['file_path'] ?? '');
            $ocr_upd = (string)($doc['ocr_content'] ?? '');
            $upd->bind_param('sssssi', $date_archived, $fsize, $ftype, $fpath, $ocr_upd, $existingArchiveId);
            $upd->execute();
            $upd->close();
        }
        // Also update archived_by_department on existing archive row
        if (__tracking_archive_has_archived_by_dept($connection)) {
            $archiverDept = $_SESSION['user_department'] ?? $_SESSION['department'] ?? '';
            if ($archiverDept !== '') {
                $upAbd = $connection->prepare("UPDATE archive SET archived_by_department = ? WHERE id = ?");
                if ($upAbd) {
                    $upAbd->bind_param('si', $archiverDept, $existingArchiveId);
                    $upAbd->execute();
                    $upAbd->close();
                }
            }
        }
    }

    // Always remove from tracking and log history once archived (new or existing)
    
    // Copy OCR pages from tracking to archive before deleting
    if (function_exists('ocr_copy_to_archive') && $archiveRowId > 0) {
        ocr_copy_to_archive($connection, (int)$archive_id, $archiveRowId);
    }

    // Copy document_history to archive_history (tracking row stays, but history is copied for archive.php)
    if (function_exists('__tracking_copy_history_to_archive') && $archiveRowId > 0) {
        __tracking_copy_history_to_archive($connection, (int)$archive_id, (int)$archiveRowId);
    }
    
    // Per-department archive isolation: record that this department has archived
    // this document. The tracking row is NOT deleted — other departments and admin
    // still see it in tracking.php until they independently archive it.
    $archivingDept = strtoupper(trim($__isAdmin ? 'ADMIN' : ($_SESSION['user_department'] ?? $_SESSION['department'] ?? '')));
    if ($archivingDept !== '') {
        $daStmt = $connection->prepare("INSERT IGNORE INTO department_archives (tracking_id, department, archived_by_user_id) VALUES (?, ?, ?)");
        if ($daStmt) {
            $archUserId = (int)($_SESSION['user_id'] ?? 0);
            $trackIdInt = (int)$archive_id;
            $daStmt->bind_param('isi', $trackIdInt, $archivingDept, $archUserId);
            $daStmt->execute();
            $daStmt->close();
        }
    }

    // Gallery docs are a special case — they were already handled above with DELETE.
    // For normal docs, do NOT delete from tracking. Just clear notifications for this user.
    try {
        tracking_firestore_delete_linked_notifications($connection, (int)$archive_id);
    } catch (Throwable $t) {
        // best-effort only
    }
    $actor_id = $_SESSION['user_id'] ?? null;
    $archDocType = $doc['type'] ?? '';
    $hasDocTypeCol = __tracking_document_history_has_doc_type($connection);
    $h = $connection->prepare($hasDocTypeCol
      ? "INSERT INTO document_history (doc_id, doc_type, action, actor_user_id, to_status, to_holder) VALUES (?, ?, 'archive', ?, 'Archived', 'Digital Archive')"
      : "INSERT INTO document_history (doc_id, action, actor_user_id, to_status, to_holder) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive')"
    );
    if ($h) {
      if ($hasDocTypeCol) {
        $h->bind_param('sss', $archive_id, $archDocType, $actor_id);
      } else {
        $h->bind_param('ss', $archive_id, $actor_id);
      }
      $h->execute();
      $h->close();
    }
    // Also log the final archive event into archive_history so it appears in archive.php timeline
    if ($archiveRowId > 0) {
        $ah = @$connection->prepare("INSERT INTO archive_history (archive_id, action, actor_user_id, to_status, to_holder, notes, created_at) VALUES (?, 'archive', ?, 'Archived', 'Digital Archive', 'Document Archived', NOW())");
        if ($ah) { $actorInt = (int)($actor_id ?? 0); $ah->bind_param('ii', $archiveRowId, $actorInt); @$ah->execute(); $ah->close(); }
    }
    header("Location: tracking.php?status=archived");
    exit();
}

// ------- Server-side pagination (5 per page) for main table -------
$documents = [];
$perPage = 5;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $perPage;

// Optional filters (keyword/type/status/department/date range) are applied server-side so
// search works across ALL records (not just the current page).
function tracking_bind_params($stmt, string $types, array &$params): void {
  if ($types === '' || empty($params)) {
    return;
  }
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => &$v) {
    $bind[] = &$v;
  }
  @call_user_func_array([$stmt, 'bind_param'], $bind);
}

$clauses = [
  // Hide placeholder/demo rows that don't have an actual uploaded/captured file.
  // Real documents should have at least one of: file_path (web upload),
  // mobile_timestamp (mobile capture), or doc_hash (sha-256).
  "COALESCE(NULLIF(TRIM(tracking.file_path),''), NULLIF(TRIM(tracking.mobile_timestamp),''), NULLIF(TRIM(tracking.doc_hash),'')) IS NOT NULL",
  // Legacy dummy seeds used string ids like 'doc7'. Production rows are numeric.
  "tracking.id REGEXP '^[0-9]+$'",
];
$bindTypes = '';
$bindParams = [];

// Optional overdue filter (approximated: 4+ calendar days, active statuses)
if (isset($_GET['filter']) && strtolower((string)$_GET['filter']) === 'overdue') {
  $clauses[] = "COALESCE(status,'') NOT IN ('Completed','Approved','Archived') AND DATEDIFF(CURDATE(), DATE(COALESCE(created_at, date_submitted))) >= 4";
}

// Keyword search (includes OCR content in SQL, but we do NOT fetch full OCR text for list view)
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
  // Best-effort: ensure OCR storage tables/columns exist before referencing them in SQL
  try {
    if (function_exists('ocr_ensure_pages_table')) {
      ocr_ensure_pages_table($connection);
    }
    if (function_exists('ocr_ensure_parent_ocr_columns')) {
      ocr_ensure_parent_ocr_columns($connection);
    }
  } catch (Throwable $t) {
    // ignore
  }

  // Split query into terms so OCR text with newlines/extra spaces can still match.
  // Build multiple patterns so OCR text with newlines/extra spaces can still match.
  $terms = preg_split('/\s+/', $q);
  $terms = array_values(array_filter(array_map('trim', $terms), function($t){ return $t !== '' && strlen($t) >= 2; }));
  if (empty($terms)) {
    $terms = [$q];
  }
  // Prevent excessively large SQL for very long queries.
  if (count($terms) > 6) {
    $terms = array_slice($terms, 0, 6);
  }

  // Patterns:
  // - Full query as-is
  // - Wildcard query that tolerates newlines/spaces between words
  // - Individual word terms
  $patterns = [];
  $patterns[] = $q;
  if (count($terms) >= 2) {
    $patterns[] = implode('%', $terms);
  }
  foreach ($terms as $t) {
    $patterns[] = $t;
  }
  $patterns = array_values(array_unique(array_filter(array_map('trim', $patterns), function($p){ return $p !== ''; })));
  if (count($patterns) > 8) {
    $patterns = array_slice($patterns, 0, 8);
  }

  $orClauses = [];
  foreach ($patterns as $p) {
    $like = '%' . $p . '%';
    $orClauses[] = "(tracking.type LIKE ? OR tracking.employee_name LIKE ? OR tracking.current_holder LIKE ? OR tracking.end_location LIKE ? OR tracking.status LIKE ? OR tracking.department LIKE ? OR tracking.ocr_content LIKE ? OR tracking.ocr_summary LIKE ? OR EXISTS (SELECT 1 FROM ocr_pages op WHERE op.scope='tracking' AND op.doc_id = tracking.id AND (op.ocr_text LIKE ? OR op.ocr_keywords LIKE ?)))";
    $bindTypes .= str_repeat('s', 10);
    for ($i = 0; $i < 10; $i++) {
      $bindParams[] = $like;
    }
  }
  $clauses[] = '(' . implode(' OR ', $orClauses) . ')';
}

// Type filter
$type = trim((string)($_GET['type'] ?? ''));
if ($type !== '' && $type !== 'All Types') {
  $typeNorm = strtoupper(trim((string)$type));
  if ($typeNorm === 'ADVISORY' || $typeNorm === 'ADVISORIES') {
    $clauses[] = "UPPER(TRIM(tracking.type)) IN ('ADVISORY','ADVISORIES')";
  } else {
    $clauses[] = 'tracking.type = ?';
    $bindTypes .= 's';
    $bindParams[] = $type;
  }
}

// Status filter (Returned maps to archived)
$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '' && $status !== 'All Statuses') {
  if (strcasecmp($status, 'Returned') === 0) {
    $clauses[] = "tracking.status = 'Archived'";
  } else {
    $clauses[] = 'tracking.status = ?';
    $bindTypes .= 's';
    $bindParams[] = $status;
  }
}

// Department filter helper (matches department OR holder OR end_location, using LIKE for flexibility)
$dept = trim((string)($_GET['dept'] ?? ''));
if ($dept !== '' && $dept !== 'All Departments') {
  $deptLike = '%' . $dept . '%';
  $clauses[] = '(tracking.department = ? OR tracking.current_holder LIKE ? OR tracking.end_location LIKE ?)';
  $bindTypes .= 'sss';
  $bindParams[] = $dept;
  $bindParams[] = $deptLike;
  $bindParams[] = $deptLike;
}

// Department isolation: non-admin users see only documents that involve their department.
// A document is visible to a department user when:
//   1. It originated from their department (tracking.department = dept)
//   2. It is currently held by their department (tracking.current_holder = dept) — ingoing
//   3. It is destined for their department (tracking.end_location = dept)
//   4. Their department is in routing_queue AND route_step has reached or passed
//      their position (route_step >= FIND_IN_SET - 1).  routing_queue is populated
//      on every routing action, so once a dept routes a doc it stays visible.
if (!$__isAdmin && !empty($_SESSION['user_department'])) {
  $ud = strtoupper(trim($_SESSION['user_department']));
  $clauses[] = '(UPPER(TRIM(tracking.department)) = ? OR UPPER(TRIM(tracking.current_holder)) = ? OR UPPER(TRIM(tracking.end_location)) = ? OR (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(tracking.routing_queue, \' \', \'\'))) > 0 AND CAST(COALESCE(tracking.route_step, 0) AS UNSIGNED) >= (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(tracking.routing_queue, \' \', \'\'))) - 1)))';
  $bindTypes .= 'sssss';
  $bindParams[] = $ud;
  $bindParams[] = $ud;
  $bindParams[] = $ud;
  $bindParams[] = $ud;
  $bindParams[] = $ud;
}

// Per-department archive isolation: hide documents that this user/department has
// already archived. Each department archives independently; the tracking row stays
// until every department has archived it.
{
  $archDept = $__isAdmin
      ? 'ADMIN'
      : strtoupper(trim($_SESSION['user_department'] ?? $_SESSION['department'] ?? ''));
  if ($archDept !== '') {
    $clauses[] = 'NOT EXISTS (SELECT 1 FROM department_archives da WHERE da.tracking_id = tracking.id AND UPPER(TRIM(da.department)) = ?)';
    $bindTypes .= 's';
    $bindParams[] = $archDept;
  }
}

// Date range filter
$dr = trim((string)($_GET['dr'] ?? ''));
$drcustom = trim((string)($_GET['drcustom'] ?? ''));
$startDateStr = null;
$endDateStr = null;
try {
  $today = new DateTime('today');
  if ($dr === 'Today') {
    $startDateStr = $today->format('Y-m-d');
    $endDateStr = $today->format('Y-m-d');
  } elseif ($dr === 'Last 7 Days') {
    $start = (clone $today)->modify('-6 days');
    $startDateStr = $start->format('Y-m-d');
    $endDateStr = $today->format('Y-m-d');
  } elseif ($dr === 'Last 30 Days') {
    $start = (clone $today)->modify('-29 days');
    $startDateStr = $start->format('Y-m-d');
    $endDateStr = $today->format('Y-m-d');
  } elseif ($dr === 'This Month') {
    $start = new DateTime(date('Y-m-01'));
    $end = new DateTime(date('Y-m-t'));
    $startDateStr = $start->format('Y-m-d');
    $endDateStr = $end->format('Y-m-d');
  } elseif ($dr === 'Custom' && $drcustom !== '') {
    $parts = preg_split('/\s+to\s+/i', $drcustom);
    $a = $parts[0] ?? '';
    $b = $parts[1] ?? ($parts[0] ?? '');
    $tsa = strtotime($a);
    $tsb = strtotime($b);
    if ($tsa !== false && $tsb !== false) {
      $startDateStr = date('Y-m-d', $tsa);
      $endDateStr = date('Y-m-d', $tsb);
    }
  }
} catch (Exception $e) {
  $startDateStr = null;
  $endDateStr = null;
}
if ($startDateStr && $endDateStr) {
  $clauses[] = 'DATE(COALESCE(tracking.created_at, tracking.date_submitted)) BETWEEN ? AND ?';
  $bindTypes .= 'ss';
  $bindParams[] = $startDateStr;
  $bindParams[] = $endDateStr;
}

$where = 'WHERE ' . implode(' AND ', $clauses);

function tracking_format_overdue_duration($seconds) {
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) {
        return ['short' => '<1m', 'full' => 'Less than a minute'];
    }
    $minutes = intdiv($seconds, 60);
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;

    $shortParts = [];
    if ($days > 0) { $shortParts[] = $days . 'd'; }
    if ($hours > 0 && count($shortParts) < 2) { $shortParts[] = $hours . 'h'; }
    if ($days === 0 && $mins > 0 && count($shortParts) < 2) { $shortParts[] = $mins . 'm'; }
    if (empty($shortParts)) { $shortParts[] = '<1m'; }

    $fullParts = [];
    if ($days > 0) { $fullParts[] = $days . ' day' . ($days === 1 ? '' : 's'); }
    if ($hours > 0) { $fullParts[] = $hours . ' hour' . ($hours === 1 ? '' : 's'); }
    if ($mins > 0) { $fullParts[] = $mins . ' minute' . ($mins === 1 ? '' : 's'); }
    if (empty($fullParts)) { $fullParts[] = 'Less than a minute'; }

    return ['short' => implode(' ', $shortParts), 'full' => implode(' ', $fullParts)];
}

function formatDuration($seconds) {
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) {
        return '<1 min';
    }
    $minutes = intdiv($seconds, 60);
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;

    $parts = [];
    if ($days > 0) { $parts[] = $days . 'd'; }
    if ($hours > 0) { $parts[] = $hours . 'h'; }
    if ($mins > 0 && $days === 0) { $parts[] = $mins . 'm'; }
    if (empty($parts)) { $parts[] = '<1 min'; }

    return implode(' ', $parts);
}

 function tracking_calculate_overdue_meta($status, $createdAt, $dateSubmitted) {
     $statusNorm = strtolower((string)$status);
     if (in_array($statusNorm, ['archived', 'completed', 'approved'], true)) {
         return ['label' => 'Cleared', 'full' => 'Cleared', 'state' => 'cleared', 'seconds' => 0];
     }

    $source = $createdAt ?: $dateSubmitted;
    if (empty($source)) {
        return ['label' => '—', 'full' => 'No timestamp available', 'state' => 'na', 'seconds' => 0];
    }

    try {
        $start = new DateTime($source);
    } catch (Exception $e) {
        return ['label' => '—', 'full' => 'Invalid timestamp', 'state' => 'na', 'seconds' => 0];
    }

    $now = new DateTime();
    $diffSeconds = max(0, $now->getTimestamp() - $start->getTimestamp());
     $duration = tracking_format_overdue_duration($diffSeconds);

     $state = 'ok';
     if ($diffSeconds >= 5 * 86400) {
         $state = 'late';
     } elseif ($diffSeconds >= 4 * 86400) {
         $state = 'warn';
     }

     return [
         'label' => $duration['short'],
         'full' => $duration['full'],
         'state' => $state,
        'seconds' => $diffSeconds,
    ];
}

// Total count for page calculation
$totalCount = 0;
$sqlCnt = "SELECT COUNT(*) AS cnt FROM tracking $where";
if ($stmtCnt = $connection->prepare($sqlCnt)) {
  tracking_bind_params($stmtCnt, $bindTypes, $bindParams);
  if ($stmtCnt->execute()) {
    $resCnt = $stmtCnt->get_result();
    if ($resCnt && ($r = $resCnt->fetch_assoc())) {
      $totalCount = (int)($r['cnt'] ?? 0);
    }
    if ($resCnt) { $resCnt->free(); }
  } else {
    error_log('Error counting documents: ' . $stmtCnt->error);
  }
  $stmtCnt->close();
} else {
  error_log('Error preparing count query: ' . $connection->error);
}
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($currentPage > $totalPages) { $currentPage = $totalPages; $offset = ($currentPage - 1) * $perPage; }

$sqlPage = "SELECT 
              tracking.id,
              tracking.type,
              tracking.employee_name,
              tracking.date_submitted,
              tracking.current_holder,
              tracking.end_location,
              tracking.status,
              tracking.department,
              tracking.file_type_icon,
              tracking.mobile_timestamp,
              tracking.file_size,
              tracking.user_email,
              tracking.file_path,
              tracking.created_at,
              control.department AS employee_department
            FROM tracking
            LEFT JOIN control ON control.user = tracking.employee_name
            $where
            ORDER BY tracking.date_submitted DESC
            LIMIT ? OFFSET ?";
$stmtPage = $connection->prepare($sqlPage);
if ($stmtPage) {
  $pageBindTypes = $bindTypes . 'ii';
  $pageBindParams = $bindParams;
  $pageBindParams[] = $perPage;
  $pageBindParams[] = $offset;
  tracking_bind_params($stmtPage, $pageBindTypes, $pageBindParams);
    if ($stmtPage->execute()) {
        $res = $stmtPage->get_result();
        while ($row = $res->fetch_assoc()) {
            $overdueMeta = tracking_calculate_overdue_meta($row['status'] ?? '', $row['created_at'] ?? null, $row['date_submitted'] ?? null);
            $row['overdue_label'] = $overdueMeta['label'];
            $row['overdue_full_label'] = $overdueMeta['full'];
            $row['overdue_state'] = $overdueMeta['state'];
            $row['overdue_seconds'] = $overdueMeta['seconds'];

          // Performance: do not load full history/OCR for the list view.
          // Detail/timeline views fetch `tracking.php?action=doc_detail&id=...` on demand.
          $row['history'] = [];
          $row['ocr_content'] = '';
            
            // Generate file URL for tracking documents if file_path exists
            if (!empty($row['file_path'])) {
                $row['file_url'] = 'download.php?id=' . $row['id'] . '&inline=1&t=' . time();
                // Strip .enc suffix for encrypted files to get the real extension
                $fpForExt = $row['file_path'];
                if (str_ends_with(strtolower($fpForExt), '.enc')) {
                    $fpForExt = substr($fpForExt, 0, -4);
                }
                $row['file_ext'] = strtolower(pathinfo($fpForExt, PATHINFO_EXTENSION));
            } else {
                $row['file_url'] = null;
                $row['file_ext'] = null;
            }
            
            $documents[] = $row;
        }
    }
    $stmtPage->close();
}

// Close connection before rendering HTML
$connection->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CHRMO Document Management</title> 
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <script src="assets/smooth-interactions.js" defer></script>
  <style>
    :root {
      --primary: #6868AC;
      --primary-light: #e8e8f4;
      --primary-dark: #52528a;
      --secondary: #8585c0;
      --text-dark: #263238;
      --text-light: #78909C;
      --white: #FFFFFF;
      --light-bg: #F5F7FA;
      --border: #E0E0E0;
      --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      --pending: #FFC107;
      --completed: #4CAF50;
      --review: #2196F3;
      --approved: #8BC34A;
      --archived: #9E9E9E;
      --rejected: #F44336;
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Open Sans', Arial, sans-serif;
    }
    body {
      background-color: var(--light-bg);
      color: var(--text-dark);
    }
    .container {
      display: flex;
      min-height: 100vh;
    }
    /* Sidebar */
    .sidebar {
      width: 80px;
      background: linear-gradient(180deg, #2e2e5e 0%, #3d3d7a 50%, #2e2e5e 100%);
      color: #fff;
      padding: 0;
      box-shadow: 4px 0 24px rgba(0,0,0,0.18);
      position: fixed;
      height: 100vh;
      overflow: hidden;
      overflow-y: auto;
      transition: width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
      z-index: 100;
      transform: translateZ(0);
      backface-visibility: hidden;
      contain: layout style;
      display: flex;
      flex-direction: column;
    }
    .sidebar::-webkit-scrollbar { width: 0; }
    .sidebar:hover {
      width: 260px;
    }
    .sidebar-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 16px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.10);
      margin-bottom: 8px;
      background: rgba(0,0,0,0.10);
    }
    .sidebar-header img {
      height: 48px;
      width: 48px;
      object-fit: contain;
      margin-bottom: 8px;
      border-radius: 8px;
      background: rgba(255,255,255,0.08);
      padding: 4px;
      transition: height 0.25s ease, width 0.25s ease;
    }
    .sidebar:hover .sidebar-header img {
      height: 64px;
      width: 64px;
    }
    .sidebar:not(:hover) .sidebar-header img { margin-bottom: 0; }
    .sidebar-header h2 {
      font-size: 15px;
      font-weight: 700;
      margin: 0;
      opacity: 0;
      white-space: nowrap;
      transition: opacity 0.2s ease 0.1s;
      letter-spacing: 0.5px;
    }
    .sidebar:hover .sidebar-header h2 { opacity: 1; }
    .sidebar-header .sidebar-subtitle {
      font-size: 11px;
      color: rgba(255,255,255,0.5);
      opacity: 0;
      transition: opacity 0.2s ease 0.15s;
      margin-top: 2px;
      letter-spacing: 0.3px;
    }
    .sidebar:hover .sidebar-header .sidebar-subtitle { opacity: 1; }
    .sidebar-menu { padding: 0 12px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .sidebar-section-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 2.5px;
      color: rgba(255,255,255,0.35);
      text-transform: uppercase;
      padding: 16px 14px 6px;
      opacity: 0;
      transition: opacity 0.2s ease 0.1s;
      white-space: nowrap;
    }
    .sidebar:hover .sidebar-section-label { opacity: 1; }
    .sidebar:not(:hover) .sidebar-section-label { height: 0; padding: 4px 0; overflow: hidden; }
    .sidebar-section-divider {
      height: 1px;
      background: rgba(255,255,255,0.06);
      margin: 4px 14px 0;
    }
    .sidebar:not(:hover) .sidebar-section-divider { margin: 2px auto; width: 32px; }
    .sidebar:not(:hover) .sidebar-header { justify-content: center; }
    .sidebar:not(:hover) .sidebar-header h2 { display: none; }
    .sidebar:not(:hover) .sidebar-header .sidebar-subtitle { display: none; }
    .sidebar:not(:hover) .menu-item { justify-content: center; }
    .menu-item {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 11px 14px;
      min-height: 44px;
      color: var(--white);
      text-decoration: none;
      transition: background-color .18s ease, color .18s ease, box-shadow .18s ease, transform .12s ease;
      border-radius: 12px;
      position: relative;
    }
    .menu-item:hover { background: rgba(255,255,255,0.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
    .menu-item.active {
      background: rgba(255,255,255,0.13);
      color: #fff;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15), inset 0 0 0 1px rgba(255,255,255,0.08);
      border-left: 3px solid #64b5f6;
    }
    .menu-item.active i, .menu-item.active span { color: #fff; }
    .menu-item i {
      font-size: 20px;
      width: 28px; min-width: 28px; text-align: center; color: rgba(255,255,255,0.85); transition: color .18s ease, transform .18s ease;
    }
    .menu-item.active i { color: #90caf9; }
    .menu-item:hover i { color: #fff; }
    .menu-item span { font-size: 14px; opacity: 0; white-space: nowrap; transition: opacity .2s ease; }
    .sidebar:hover .menu-item span { opacity: 1; }
    .sidebar:not(:hover) .menu-item {
      justify-content: center;
      width: 52px;
      height: 52px;
      padding: 0;
      margin: 3px auto;
      display: grid;
      place-items: center;
      overflow: visible;
      border-left: none;
    }
    .sidebar:not(:hover) .menu-item.active { border-left: none; border-bottom: 2px solid #64b5f6; }
    .sidebar:not(:hover) .menu-item i {
      width: 24px; height: 24px; display: inline-grid; place-items: center; color: #fff;
    }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display: none; }
    .menu-badge { position: absolute; right: 12px; top: 50%; transform: translateY(-52%); }
    .menu-item.active .menu-badge { right: 12px; top: 50%; transform: translateY(-54%); }
    .sidebar:not(:hover) .menu-badge { right: 4px; top: 4px; transform: none; display: inline-flex !important; font-size: 10px; padding: 1px 5px; }
    .menu-badge {
      background-color: #FF5252;
      color: white;
      font-size: 11px;
      height: 20px;
      min-width: 20px;
      padding: 0 6px;
      border-radius: 999px;
      margin-left: auto;
      font-weight: 700;
      line-height: 20px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      opacity: 1;
      transition: opacity 0.2s ease;
      z-index: 2;
      pointer-events: none;
    }
    .menu-badge.success {
      background-color: #4CAF50;
    }
    .sidebar-footer {
      padding: 14px 16px;
      border-top: 1px solid rgba(255,255,255,0.08);
      text-align: center;
      margin-top: auto;
    }
    .sidebar-footer span {
      font-size: 10px;
      color: rgba(255,255,255,0.3);
      display: block;
      opacity: 0;
      transition: opacity 0.2s ease 0.1s;
      white-space: nowrap;
    }
    .sidebar:hover .sidebar-footer span { opacity: 1; }
    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: 80px;
      padding: 30px;
      background-color: #f9fafb;
      min-width: 0;
      transition: margin-left 0.3s ease;
    }
    .sidebar:hover ~ .main-content { margin-left: 260px; }
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      background-color: var(--white);
      padding: 20px 25px;
      border-radius: 12px;
      box-shadow: var(--shadow-lg);
      position: relative;
      overflow: visible;
      z-index: 20;
    }
    .top-bar::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 4px;
      height: 100%;
      background: linear-gradient(to bottom, var(--primary), var(--secondary));
    }
    .top-bar h2 {
      font-size: 26px;
      font-weight: 700;
      color: var(--text-dark);
    }
    /* New styles for centering search bar */
    .search-bar-container {
        flex-grow: 1; /* Allows it to take available available space */
        display: flex;
        justify-content: center; /* Centers its child (search-bar) */
    }
    .top-bar-actions { /* New class for the right-side action group */
        display: flex;
        align-items: center;
        gap: 10px; /* Reduced gap */
    }
    .search-bar {
      display: flex;
      align-items: center;
      background-color: var(--white);
      border-radius: 8px;
      padding: 12px 18px;
      width: 280px;
      transition: width 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
      height: 44px;
    }
    .search-bar:hover { border-color: var(--primary); }
    .search-bar:focus-within { box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.15); }
    .search-bar:focus-within { width: 360px; }
    @media (max-width: 1024px) { .search-bar:focus-within { width: 100%; } }
    .search-bar input {
      border: none;
      background: transparent;
      outline: none;
      padding: 0 8px;
      width: 100%;
      font-size: 14px;
      color: var(--text-dark);
    }
    .search-bar input::placeholder { color: var(--text-light); }
    
    /* OCR Search Toggle Button */
    .ocr-search-toggle {
      background: transparent;
      border: none;
      color: var(--text-light);
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      transition: background-color 0.2s ease, color 0.2s ease;
      font-size: 14px;
    }
    .ocr-search-toggle:hover { background: var(--bg-light); color: var(--primary); }
    .ocr-search-toggle.active { background: var(--primary); color: white; }
    
    /* OCR Search Results Dropdown */
    .ocr-search-results {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: white;
      border: 1px solid var(--border);
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
      max-height: 400px;
      overflow-y: auto;
      z-index: 1000;
      margin-top: 4px;
    }
    .ocr-search-results .ocr-result-item {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background 0.2s;
    }
    .ocr-search-results .ocr-result-item:hover { background: var(--bg-light); }
    .ocr-search-results .ocr-result-item:last-child { border-bottom: none; }
    .ocr-search-results .ocr-result-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
    }
    .ocr-search-results .ocr-result-type {
      font-weight: 600;
      color: var(--text-dark);
    }
    .ocr-search-results .ocr-result-meta {
      font-size: 12px;
      color: var(--text-light);
    }
    .ocr-search-results .ocr-result-snippet {
      font-size: 13px;
      color: var(--text-dark);
      background: #FFFDE7;
      padding: 8px;
      border-radius: 4px;
      margin-top: 6px;
      line-height: 1.4;
    }
    .ocr-search-results .ocr-result-snippet mark {
      background: #FFD54F;
      padding: 0 2px;
      border-radius: 2px;
    }
    .ocr-search-results .ocr-result-pages {
      font-size: 11px;
      color: var(--primary);
      margin-top: 4px;
    }
    .ocr-search-results .ocr-no-results {
      padding: 20px;
      text-align: center;
      color: var(--text-light);
    }
    .ocr-search-results .ocr-searching {
      padding: 20px;
      text-align: center;
      color: var(--text-light);
    }
    
    /* OCR search result row highlight animation */
    @keyframes ocr-row-highlight {
      0%, 100% { background-color: inherit; }
      25%, 75% { background-color: rgba(37, 99, 235, 0.2); }
    }
    .ocr-highlight-row {
      animation: ocr-row-highlight 3s ease;
      box-shadow: 0 0 10px rgba(37, 99, 235, 0.5);
    }
    .ocr-highlight-row td {
      position: relative;
    }
    
    /* Extracted OCR Keys Chips */
    .ocr-key-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 500;
      line-height: 1.3;
      max-width: 260px;
    }
    .ocr-key-chip .ocr-key-icon {
      font-size: 11px;
      opacity: 0.8;
      flex-shrink: 0;
    }
    .ocr-key-chip .ocr-key-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      opacity: 0.6;
      flex-shrink: 0;
    }
    .ocr-key-chip .ocr-key-value {
      font-weight: 600;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .ocr-key-chip.type { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
    .ocr-key-chip.name { background: #f0fdfa; color: #0f766e; border: 1px solid #99f6e4; }
    .ocr-key-chip.date { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .ocr-key-chip.amount { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .ocr-key-chip.dept { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }
    .ocr-key-chip.ref { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

    .user-profile {
      display: flex;
      align-items: center;
      cursor: pointer;
      position: relative;
      padding: 8px 12px;
      border-radius: 30px;
      transition: background-color 0.2s ease;
      color: var(--text-dark);
      z-index: 102;
      background: none;
    }
    .user-profile:hover {
      background-color: var(--light-bg);
    }
    .user-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 10px;
      object-fit: cover;
      border: 2px solid var(--primary-light);
    }
    .notification-icon {
      position: relative;
      cursor: pointer;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 102;
      background: none;
      margin-right: 20px;
      border-radius: 50%;
      transition: none;
    }

    .notification-icon i {
        font-size: 1.25rem;
    }
    .notification-badge {
      position: absolute;
      top: 2px;
      right: 2px;
      background-color: #FF5252;
      color: white;
      font-size: 10px;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--white);
      pointer-events: none;
      animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.1); opacity: 0.8; }
    }
    .notification-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 8px;
      box-shadow: var(--shadow-lg);
      width: 350px;
      z-index: 200;
      display: none;
      margin-top: 8px;
      max-height: 400px;
      overflow-y: auto;
      border: 1px solid var(--border);
      /* Animations for filter dropdowns */
      opacity: 0;
      transform: translateY(-10px);
      transition: opacity 0.2s ease-out, transform 0.2s ease-out;
    }
    .notification-dropdown.show {
      display: block;
      opacity: 1;
      transform: translateY(0);
    }
    .notification-header {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .notification-header h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 600;
      color: var(--text-dark);
    }
    .notification-clear {
      color: var(--primary);
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
    }
    .notification-item {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      color: var(--text-dark);
    }
    .notification-item.unread {
      background-color: rgba(0, 188, 212, 0.1);
    }
    .notification-title {
      font-weight: 600;
      margin-bottom: 5px;
      display: flex;
      justify-content: space-between;
      font-size: 14px;
    }
    .notification-time {
      color: var(--text-light);
      font-size: 12px;
    }
    .notification-content {
      font-size: 14px;
      color: var(--text-dark);
    }
    .dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 8px;
      box-shadow: var(--shadow-lg);
      min-width: 200px;
      z-index: 200;
      display: none;
      margin-top: 10px;
      border: 1px solid var(--border);
      animation: fadeIn 0.2s ease-out;
    }
    .dropdown-menu.show {
      display: block;
    }
    .dropdown-item {
      padding: 12px 16px;
      font-size: 14px;
      color: var(--text-dark);
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 10px;
      transition: background-color 0.2s;
      font-weight: 500;
    }
    .dropdown-item:hover {
      background-color: var(--primary-light);
      color: var(--primary-dark);
    }
    /* Filters */
    .filters-container {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      background: var(--white);
      padding: 14px 16px;
      border: 1px solid #e6eef5;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .filters-title {
      margin: 10px 0 8px 4px;
      color: var(--text-light);
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 0.4px;
      text-transform: uppercase;
    }
    .filters-header-row {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      margin-bottom: 10px;
    }
    .filters-header-row .filters-open-btn { margin-left: 12px; }
    /* Search suggestions */
    .search-wrap { position: relative; display:flex; justify-content:center; }
    .search-suggestions {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: #fff;
      border: 1px solid var(--border);
      border-top: none;
      border-radius: 0 0 8px 8px;
      box-shadow: var(--shadow-lg);
      z-index: 400;
      display: none;
      max-height: 260px;
      overflow-y: auto;
    }
    .search-suggestions.show { display: block; }
    .suggest-section { padding: 8px 12px; font-size: 12px; font-weight: 700; color: var(--text-light); text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-bottom: 1px solid var(--border); }
    .suggest-clear { border: 1px solid var(--border); background: #fff; border-radius: 6px; padding: 4px 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .search-suggestion-item { padding: 10px 14px; cursor: pointer; font-size: 14px; }
    .search-suggestion-item:hover { background: var(--primary-light); }
    .filters-open-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 14px;
      font-weight: 600;
      box-shadow: var(--shadow);
      cursor: pointer;
    }
    .filters-count {
      background: var(--primary);
      color: #fff;
      font-size: 12px;
      padding: 2px 8px;
      border-radius: 999px;
      font-weight: 700;
    }

    /* Filters button when placed beside view toggles */
    .view-options .filters-open-btn { margin-left: 0; }
    .action-button.filters-open-btn { box-shadow: none; font-weight: 600; }
    .view-options .action-button.filters-open-btn { position: relative; }
    .view-options .action-button.filters-open-btn .filters-count {
      position: absolute;
      top: -6px;
      right: -6px;
      padding: 1px 5px;
      font-size: 10px;
      line-height: 14px;
      min-width: 16px;
      text-align: center;
    }
    /* Panel modal */
    .filters-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.25);
      display: none;
      z-index: 300;
    }
    .filters-backdrop.show { display: block; }
    .filters-panel {
      position: fixed;
      top: 10%;
      left: 50%;
      transform: translateX(-50%);
      width: min(1100px, 92%);
      z-index: 301;
      display: none;
      overflow: visible; /* allow dropdowns to extend outside */
    }
    .filters-panel.show { display: block; }
    .filters-panel .filters-container { max-height: 70vh; overflow: visible; }
    .panel-footer {
      position: sticky;
      bottom: 0;
      background: var(--white);
      padding-top: 12px;
      margin-top: 8px;
    }
    /* Chips */
    .filters-chips { display:flex; flex-wrap:wrap; gap:8px; margin: 6px 0 18px 0; }
    .chip { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:999px; font-size:12px; }
    .chip .remove { cursor:pointer; color:#64748b; }
    .chip-clear-all { background:#fee2e2; border-color:#fca5a5; color:#dc2626; font-weight:600; cursor:pointer; }
    .chip-clear-all:hover { background:#fecaca; }
    .filter-group {
      position: relative;
    }
    .filter-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .filter-btn-clear, .filter-btn-apply {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 18px;
      height: 44px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid var(--border);
      background: var(--white);
      box-shadow: var(--shadow);
      transition: transform 0.1s ease, border-color 0.2s ease;
    }
    .filter-btn-clear:hover { border-color: #ef4444; }
    .filter-btn-apply {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }
    .filter-btn-apply:hover { transform: translateY(-1px); }
    .filter-btn {
      display: flex;
      align-items: center;
      height: 44px;
      background-color: var(--white);
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 16px;
      cursor: pointer;
      font-weight: 600;
      font-size: 13.5px;
      color: var(--text-dark);
      min-width: 160px;
      white-space: nowrap;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,.04);
      gap: 6px;
    }
    .filter-btn:hover {
      border-color: var(--primary);
      box-shadow: 0 2px 8px rgba(0,151,167,.12);
    }
    .filter-btn span {
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .filter-btn i {
      margin-left: auto;
      transition: transform 0.25s ease;
      color: #94a3b8;
      font-size: 12px;
    }
    .filter-btn.open {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0,151,167,.1);
    }
    .filter-btn.open i {
      transform: rotate(180deg);
      color: var(--primary);
    }
    /* Dropdown menus for filters */
    .filter-dropdown-menu {
      position: absolute;
      top: 100%;
      left: 0;
      background-color: var(--white);
      border-radius: 12px;
      box-shadow: 0 16px 40px rgba(0,0,0,.14), 0 2px 8px rgba(0,0,0,.06);
      min-width: 100%;
      z-index: 100;
      display: none;
      margin-top: 8px;
      max-height: 340px;
      overflow-y: auto;
      border: 1px solid #eef3f7;
      opacity: 0;
      transform: translateY(-10px);
      transition: opacity 0.22s ease-out, transform 0.22s ease-out;
      padding: 6px 0;
    }
    .filter-dropdown-menu.show {
      display: block;
      opacity: 1;
      transform: translateY(0);
    }
    .filter-dropdown-menu::-webkit-scrollbar { width: 5px; }
    .filter-dropdown-menu::-webkit-scrollbar-track { background: transparent; }
    .filter-dropdown-menu::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .filter-dropdown-item {
      padding: 10px 16px;
      font-size: 13.5px;
      cursor: pointer;
      transition: background-color 0.15s ease;
      display: flex;
      align-items: center;
      gap: 10px;
      position: relative;
      margin: 2px 6px;
      border-radius: 8px;
      color: #334155;
    }
    .filter-dropdown-item:hover {
      background: linear-gradient(135deg, rgba(0,188,212,.06) 0%, rgba(14,165,233,.08) 100%);
    }
    .filter-dropdown-item.selected {
      background: linear-gradient(135deg, rgba(0,151,167,.1) 0%, rgba(6,182,212,.12) 100%);
      color: #0e7490;
      font-weight: 600;
    }
    .filter-dropdown-item.selected::after {
      content: '\f00c';
      font-family: 'Font Awesome 5 Free';
      font-weight: 900;
      font-size: 11px;
      color: #0891b2;
      margin-left: auto;
      flex-shrink: 0;
    }
    .filter-dropdown-item .check-icon { display: none; }
    .filter-dropdown-item .fdi-icon {
      width: 28px;
      height: 28px;
      border-radius: 7px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      flex-shrink: 0;
    }
    .filter-dropdown-item .fdi-label { flex: 1; }
    .filter-dropdown-item .fdi-badge {
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 10px;
      font-weight: 600;
      line-height: 1;
      margin-left: auto;
      flex-shrink: 0;
    }
    /* Type colors */
    .fdi-icon.fdi-all { background: #f1f5f9; color: #64748b; }
    .fdi-icon.fdi-payroll { background: #dcfce7; color: #16a34a; }
    .fdi-icon.fdi-memo { background: #ede9fe; color: #7c3aed; }
    .fdi-icon.fdi-travel { background: #fef3c7; color: #d97706; }
    .fdi-icon.fdi-activity { background: #fce7f3; color: #db2777; }
    .fdi-icon.fdi-purchase { background: #dbeafe; color: #2563eb; }
    .fdi-icon.fdi-advisory { background: #ffedd5; color: #ea580c; }
    .fdi-icon.fdi-announce { background: #fef9c3; color: #ca8a04; }
    /* Status colors */
    .fdi-icon.fdi-pending { background: #fef3c7; color: #d97706; }
    .fdi-icon.fdi-review { background: #dbeafe; color: #2563eb; }
    .fdi-icon.fdi-completed { background: #dcfce7; color: #16a34a; }
    .fdi-icon.fdi-archived { background: #f1f5f9; color: #64748b; }
    .fdi-badge.fdi-pending { background: #fef3c7; color: #92400e; }
    .fdi-badge.fdi-review { background: #dbeafe; color: #1e40af; }
    .fdi-badge.fdi-completed { background: #dcfce7; color: #166534; }
    .fdi-badge.fdi-archived { background: #f1f5f9; color: #475569; }
    /* Dept colors */
    .fdi-icon.fdi-dept { background: #f0f9ff; color: #0891b2; }
    /* Date colors */
    .fdi-icon.fdi-date { background: #faf5ff; color: #9333ea; }
    .fdi-icon.fdi-calendar { background: #ecfdf5; color: #059669; }
    .filter-actions {
      justify-self: end;
      margin-left: 0;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .filter-btn-clear {
      background-color: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 18px;
      font-size: 14px;
      cursor: pointer;
      transition: background-color 0.2s ease, color 0.2s ease;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .filter-btn-clear:hover {
      background-color: var(--light-bg);
      color: var(--primary-dark);
    }
    .filter-btn-apply {
      background-color: var(--primary);
      color: var(--white);
      border: none;
      border-radius: 8px;
      padding: 12px 20px;
      font-size: 14px;
      cursor: pointer;
      transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 2px 5px rgba(0, 188, 212, 0.2);
    }
    .filter-btn-apply:hover {
      background-color: var(--primary-dark);
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 188, 212, 0.3);
    }
    /* Table */
    .table-container {
      background-color: var(--white);
      border-radius: 12px;
      box-shadow: var(--shadow-lg);
      margin-bottom: 30px;
      overflow: hidden;
      border: 1px solid var(--border);
      max-height: 70vh;
      overflow-y: auto;
      overflow-x: auto;
      contain: layout style paint;
    }
    
    /* Custom scrollbar styling for table container */
    .table-container::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }
    
    .table-container::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 4px;
    }
    
    .table-container::-webkit-scrollbar-thumb {
      background: var(--primary);
      border-radius: 4px;
    }
    
    .table-container::-webkit-scrollbar-thumb:hover {
      background: var(--primary-dark);
    }
    .document-table {
      width: 100%;
      border-collapse: collapse; /* simplify baseline */
    }
    .document-table th {
      background: #fff;
      font-weight: 600;
      font-size: 13.5px;
      position: sticky;
      top: 0;
      text-align: left;
      padding: 14px 12px;
      border-bottom: 1px solid var(--border);
      z-index: 10;
      cursor: pointer;
    }
    /* Column width distribution for proper alignment */
    .document-table th:nth-child(1) { width: 15%; } /* Document Type */
    .document-table th:nth-child(2) { width: 12%; } /* Current Holder */
    .document-table th:nth-child(3) { width: 12%; } /* End Location */
    .document-table th:nth-child(4) { width: 12%; } /* Date Submitted */
    .document-table th:nth-child(5) { width: 10%; } /* Status */
    .document-table th:nth-child(6) { width: 10%; } /* Time in Dept */
    .document-table th:nth-child(7) { width: 22%; text-align: center; } /* Actions - centered */
    .document-table td:nth-child(7) { text-align: center; } /* Actions cell - centered */
    .document-table th .sort-icon {
        margin-left: 5px;
        font-size: 0.8em;
        color: var(--text-light);
    }
    .document-table td {
      padding: 14px 12px;
      font-size: 14px;
      border-bottom: 1px solid #eceff1; /* thin horizontal dividers only */
      background-color: #fff;
      transition: background-color 0.15s ease;
      color: var(--text-dark);
    }
    .document-table tr:last-child td {
      border-bottom: none;
    }
    /* Row Fade-in Animation */
    .document-table tbody tr {
        animation: fadeInRow 0.5s ease-out forwards;
    }
    @keyframes fadeInRow {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Grouped rows for multi-department memo/announcement uploads */
    .dept-toggle-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid var(--border);
      background: #f8fafc;
      color: var(--text-dark);
      padding: 6px 10px;
      border-radius: 10px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease, transform 0.15s ease;
      white-space: nowrap;
    }
    .dept-toggle-btn:hover {
      background: #eef6fb;
      transform: translateY(-1px);
    }
    .dept-toggle-btn i {
      transition: transform 0.15s ease;
      font-size: 12px;
      color: var(--text-light);
    }
    .dept-toggle-btn[aria-expanded="true"] i {
      transform: rotate(180deg);
    }
    tr.dept-subrow td {
      padding: 0;
      background: #f8fafc;
      border-bottom: 1px solid #eceff1;
    }
    .dept-subpanel {
      padding: 10px 12px 14px;
    }
    .dept-subtable {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    }
    .dept-subtable th {
      position: static;
      background: #f8fafc;
      cursor: default;
      font-size: 12px;
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      color: var(--text-light);
      text-transform: uppercase;
      letter-spacing: .3px;
    }
    .dept-subtable td {
      padding: 10px 12px;
      font-size: 13px;
      border-bottom: 1px solid #eceff1;
      background: #fff;
    }
    .dept-subtable tr:last-child td {
      border-bottom: none;
    }
    .dept-subtable td:last-child,
    .dept-subtable th:last-child {
      text-align: center;
    }

    /* Status Pills */
    .status-pill { display:inline-flex; align-items:center; padding:6px 12px; border-radius:9999px; font-size:12px; font-weight:600; letter-spacing: .2px; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
    .status-pill::before {
      content: "";
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      margin-right: 8px;
    }
    .status-pending { background:#FFF7E6; color:#AD6800; }
    .status-pending::before { background:#FAAD14; }
    .status-returned { background:#FFF7E6; color:#AD6800; }
    .status-returned::before { background:#FA8C16; }
    .status-review { background:#E6F7FF; color:#0050b3; }
    .status-review::before { background:#1890ff; }
    .status-completed { background:#F6FFED; color:#237804; }
    .status-completed::before { background:#52c41a; }
    .status-approved { background:#ECFFFB; color:#006d6f; }
    .status-approved::before { background:#13c2c2; }
    .status-archived { background:#F5F5F5; color:#595959; }
    .status-archived::before { background:#bfbfbf; }
    .status-rejected { background:#FFF1F0; color:#a8071a; }
    .status-rejected::before { background:#f5222d; }
    .status-review::before {
      background-color: var(--review);
    }
    .status-review.pulse {
        animation: pulse-blue 1.5s infinite;
    }
    @keyframes pulse-blue {
        0% { box-shadow: 0 0 0 0 rgba(33, 150, 243, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(33, 150, 243, 0); }
        100% { box-shadow: 0 0 0 0 rgba(33, 150, 243, 0); }
    }

    .status-approved {
      background-color: rgba(139, 195, 74, 0.15);
      color: #558B2F;
    }
    .status-approved::before {
      background-color: var(--approved);
    }
    .status-archived {
      background-color: rgba(158, 158, 158, 0.15);
      color: #616161;
    }
    .status-archived::before {
      background-color: var(--archived);
    }
    .status-rejected {
      background-color: rgba(244, 67, 54, 0.15);
      color: #D32F2F;
    }
    .status-rejected::before {
      background-color: var(--rejected);
    }

    /* File Type Icon */
    .file-type-icon {
        margin-right: 8px;
        font-size: 1.1em;
        color: var(--text-light);
    }
    .file-type-icon.pdf { color: #FF5252; }
    .file-type-icon.doc, .file-type-icon.docx { color: #2196F3; }
    .file-type-icon.xls, .file-type-icon.xlsx { color: #4CAF50; }
    .file-type-icon.img, .file-type-icon.png, .file-type-icon.jpg { color: #FF9800; }
    .file-type-icon.txt { color: #9E9E9E; }

    /* ── T5: Document Type Chip Badge ── */
    .doc-type-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      letter-spacing: .2px;
    }
    .doc-type-chip i { font-size: 13px; }
    .doc-type-chip.chip-memo         { background: #EDE9FE; color: #5B21B6; }
    .doc-type-chip.chip-payroll      { background: #DBEAFE; color: #1E40AF; }
    .doc-type-chip.chip-leave        { background: #D1FAE5; color: #065F46; }
    .doc-type-chip.chip-advisory,
    .doc-type-chip.chip-advisories   { background: #FEF3C7; color: #92400E; }
    .doc-type-chip.chip-announcement { background: #FFE4E6; color: #9F1239; }
    .doc-type-chip.chip-appointment  { background: #E0E7FF; color: #3730A3; }
    .doc-type-chip.chip-order        { background: #CFFAFE; color: #155E75; }
    .doc-type-chip.chip-default      { background: #F1F5F9; color: #475569; }



    /* ── T5: Overdue Pills (were missing!) ── */
    .overdue-pill {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 11.5px;
      font-weight: 600;
      letter-spacing: .2px;
      white-space: nowrap;
    }
    .overdue-late  { background: #FEE2E2; color: #991B1B; }
    .overdue-warn  { background: #FEF3C7; color: #92400E; }
    .overdue-ok    { background: #DCFCE7; color: #166534; }
    .overdue-cleared { background: #F0FDF4; color: #15803D; }
    .overdue-na    { background: #F1F5F9; color: #94A3B8; }

    /* ── T5: Alternating Row Stripes ── */
    .document-table tbody tr:nth-child(even) td {
      background-color: #FAFBFC;
    }
    .document-table tbody tr:hover td {
      background-color: #EEF2FF !important;
    }

    /* Action Buttons */
    .action-buttons-group {
      display: flex;
      gap: 4px;
      justify-content: center;
      flex-wrap: nowrap;
    }
    .action-button {
      background: transparent;
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 6px 10px;
      font-size: 11px;
      cursor: pointer;
      transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      position: relative;
    }
    .action-button:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .action-button:disabled {
      background: transparent;
      color: var(--text-light);
      cursor: not-allowed;
      opacity: 0.5;
      transform: none !important;
      box-shadow: none !important;
    }
    .action-button i {
        font-size: 1.1em;
    }
    /* Action button hover fills */
    .action-button.info:hover    { background: #E0F7FA; border-color: #00ACC1; }
    .action-button.timeline:hover { background: #FFF3E0; border-color: #FB8C00; }
    .action-button.archive:hover { background: #E8F5E9; border-color: #43A047; }

    /* Keep icon colors only */
    .action-button.info i { color: #00838F; }
    .action-button.timeline i { color: #E65100; }
    .action-button.receive i { color: #1976D2; }
    .action-button.camera i { color: #C2185B; }
    .action-button.route i { color: #388E3C; }
    /* Requested: archive icon should be green */
    .action-button.archive i { color: #388E3C; }
    .action-button.debug i { color: #6D28D9; }

    /* Modals - Base Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.6);
      z-index: 1000;
      justify-content: center;
      align-items: center;
      opacity: 0;
      transition: opacity 0.3s ease-out;
    }
    .modal.show {
        display: flex;
        opacity: 1;
    }
    .modal-content {
      background-color: var(--white);
      border-radius: 12px;
      width: 600px;
      max-width: 90%;
      max-height: 80vh;
      overflow-y: auto;
      box-shadow: var(--shadow-lg);
      padding: 25px;
      position: relative;
      transform: translateY(-20px);
      transition: transform 0.3s ease-out;
      border: 1px solid var(--border);
    }
    .modal.show .modal-content {
        transform: translateY(0);
    }
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 1px solid var(--border);
    }
    .modal-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--text-dark);
    }
    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: var(--text-light);
      transition: color 0.2s ease;
    }
    .modal-close:hover {
        color: var(--text-dark);
    }

    /* Routing History Modal Specific Styles */
    .route-history {
      margin-top: 15px;
    }
    .route-item {
      display: flex;
      padding: 12px 0;
      border-bottom: 1px solid var(--border);
    }
    .route-item:last-child {
      border-bottom: none;
    }
    .route-status {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 15px;
      flex-shrink: 0;
      position: relative;
    }
    .route-status.completed {
      background-color: var(--completed);
    }
    .route-status.pending {
      background-color: var(--pending);
    }
    .route-status.review {
      background-color: var(--review);
    }
    .route-status::after {
      content: '';
      position: absolute;
      top: 100%;
      left: 50%;
      transform: translateX(-50%);
      width: 2px;
      height: 100%;
      background-color: var(--border);
    }
    .route-item:last-child .route-status::after {
      display: none;
    }
    .route-details {
      flex: 1;
    }
    .route-user {
      font-weight: 600;
      margin-bottom: 5px;
    }
    .route-action {
      color: var(--text-light);
      font-size: 13px;
      margin-bottom: 5px;
    }
    .route-time {
      color: var(--text-light);
      font-size: 12px;
    }

    /* Route Document Modal Specific Styles */
    .route-form-group {
        margin-bottom: 15px;
    }
    .route-form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--text-dark);
    }
    .route-form-group select,
    .route-form-group textarea,
    .route-form-group input[type="text"],
    .route-form-group input[type="date"] {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14px;
        color: var(--text-dark);
        background-color: var(--light-bg);
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .route-form-group select:focus,
    .route-form-group textarea:focus,
    .route-form-group input[type="text"]:focus,
    .route-form-group input[type="date"]:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.2);
    }
    .route-form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
    .route-form-actions button {
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: background-color 0.2s ease;
    }
    .route-form-actions .cancel-btn {
        background-color: var(--light-bg);
        color: var(--text-dark);
        border: 1px solid var(--border);
    }
    .route-form-actions .cancel-btn:hover {
        background-color: var(--border);
    }
    .route-form-actions .confirm-btn {
        background-color: var(--primary);
        color: var(--white);
        border: none;
    }
    .route-form-actions .confirm-btn:hover {
        background-color: var(--primary-dark);
        transform: translateY(-2px);
    }

    /* View Document Detail Modal Specific Styles */
    .modal-body {
        padding-top: 10px;
    }
    .document-info-container {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid #e2e8f0;
    }
    .document-info-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 2px solid #e2e8f0;
    }
    .document-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
    }
    .document-icon i {
        font-size: 24px;
        color: white;
    }
    .document-title {
        flex: 1;
    }
    .document-title h4 {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0 0 8px 0;
    }
    .document-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }
    .info-section {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid #f1f5f9;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .info-section:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }
    .info-section h5 {
        font-size: 16px;
        font-weight: 600;
        color: var(--primary-dark);
        margin: 0 0 16px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .info-section h5 i {
        font-size: 14px;
        color: var(--primary);
    }
    .info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .info-item:last-child {
        border-bottom: none;
    }
    .info-label {
        font-weight: 500;
        color: var(--text-light);
        font-size: 14px;
    }
    .info-value {
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
        text-align: right;
        max-width: 60%;
        word-wrap: break-word;
    }
    .activity-timeline {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid #f1f5f9;
    }
    .activity-timeline h5 {
        font-size: 16px;
        font-weight: 600;
        color: var(--primary-dark);
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .activity-timeline h5 i {
        font-size: 14px;
        color: var(--primary);
    }
    .timeline-container {
        position: relative;
        padding-left: 24px;
    }
    .timeline-container::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, var(--primary) 0%, var(--primary-light) 100%);
        border-radius: 1px;
    }
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
        padding: 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .timeline-item:hover {
        transform: translateX(4px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -16px;
        top: 20px;
        width: 12px;
        height: 12px;
        background: var(--primary);
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 0 0 2px var(--primary-light);
    }
    .timeline-item:last-child {
        margin-bottom: 0;
    }
    .timeline-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
    }
    .timeline-user {
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
    }
    .timeline-time {
        color: var(--text-light);
        font-size: 12px;
        font-weight: 500;
    }
    .timeline-action {
        color: var(--text-dark);
        font-size: 14px;
        line-height: 1.4;
    }
    .timeline-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }
    .timeline-status.completed {
        background: rgba(76, 175, 80, 0.1);
        color: #2e7d32;
    }
    .timeline-status.pending {
        background: rgba(255, 193, 7, 0.1);
        color: #f57c00;
    }
    .timeline-status.review {
        background: rgba(33, 150, 243, 0.1);
        color: #1565c0;
    }
    .timeline-action-btn {
        margin-top: 12px;
        text-align: right;
    }
    .btn-view-doc {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .btn-view-doc:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
    }
    .btn-view-doc:active {
        transform: translateY(0);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .timeline-document-details {
        margin-top: 16px;
        padding: 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }
    .timeline-info-section h6,
    .timeline-ocr-section h6 {
        font-size: 13px;
        font-weight: 600;
        color: var(--primary-dark);
        margin: 0 0 12px 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .timeline-info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .timeline-info-item:last-child {
        border-bottom: none;
    }
    .timeline-info-item .info-label {
        font-size: 12px;
        color: var(--text-light);
        font-weight: 500;
    }
    .timeline-info-item .info-value {
        font-size: 12px;
        color: var(--text-dark);
        font-weight: 600;
        text-align: right;
        max-width: 60%;
        word-wrap: break-word;
    }
    .timeline-ocr-section {
        margin-top: 16px;
    }
    .timeline-ocr-content {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 12px;
        max-height: 200px;
        overflow: auto;
        white-space: pre-wrap;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        font-size: 0.8rem;
        line-height: 1.4;
        color: var(--text-dark);
    }
    .timeline-document-preview-section {
        margin-top: 16px;
    }
    .timeline-document-preview {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 6px;
        overflow: hidden;
    }
    .preview-placeholder {
        padding: 20px;
        text-align: center;
        color: var(--text-light);
        background: #f8fafc;
    }
    .preview-placeholder i {
        font-size: 24px;
        margin-bottom: 8px;
        display: block;
    }
    .preview-placeholder p {
        margin: 8px 0 12px 0;
        font-size: 12px;
    }
    .btn-download {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--primary);
        color: white;
        padding: 6px 12px;
        border-radius: 4px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 500;
        transition: background 0.2s ease;
    }
    .btn-download:hover {
        background: var(--primary-dark);
    }

    /* Horizontal Timeline Styles */
    .horizontal-timeline {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 12px;
        padding: 20px;
        border: 1px solid #e2e8f0;
    }
    /* Vertical Timeline Container - Changed from horizontal */
    .timeline-container-horizontal,
    #timelineActivityLog {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 20px;
        overflow-y: auto;
        overflow-x: hidden;
        max-height: 500px;
        padding: 20px 10px;
        scrollbar-width: thin;
        scrollbar-color: var(--primary) #e2e8f0;
    }
    .timeline-container-horizontal::-webkit-scrollbar,
    #timelineActivityLog::-webkit-scrollbar {
        width: 8px;
    }
    .timeline-container-horizontal::-webkit-scrollbar-track,
    #timelineActivityLog::-webkit-scrollbar-track {
        background: #e2e8f0;
        border-radius: 4px;
    }
    .timeline-container-horizontal::-webkit-scrollbar-thumb,
    #timelineActivityLog::-webkit-scrollbar-thumb {
        background: var(--primary);
        border-radius: 4px;
    }
    /* Vertical Timeline Item - 3 column layout: Status | Details | Department */
    .timeline-item-horizontal {
        display: grid;
      /* 3 column layout: Department (left) | Details (center) | Status (right) */
      grid-template-columns: 140px 1fr 120px;
        gap: 16px;
        align-items: flex-start;
        position: relative;
        margin-bottom: 0;
        padding-bottom: 28px;
      padding-right: 20px;
        min-height: 80px;
    }
    .timeline-item-horizontal:hover {
        z-index: 5;
    }
    .timeline-item-horizontal:last-child {
        margin-bottom: 0;
        padding-bottom: 0;
    }
    /* Vertical line connector is handled by the status column to avoid overlap */
    .timeline-item-horizontal::before {
        display: none;
    }
    /* Right column - Status with icon */
    .timeline-status-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        grid-column: 3;
        position: relative;
        z-index: 2; /* Ensure icon is above the connecting line */
        width: 96px;
        justify-self: end;
    }
    .timeline-status-col::after {
        content: '';
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        top: 52px;
        bottom: -14px;
        width: 3px;
        background: linear-gradient(180deg, var(--primary) 0%, var(--primary-light) 100%);
        border-radius: 2px;
        z-index: 1;
    }
    .timeline-item-horizontal:last-child .timeline-status-col::after {
        display: none;
    }
    /* Center column - Details */
    .timeline-details-col {
        flex: 1;
        padding: 12px 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        grid-column: 2;
    }
    .timeline-details-col:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
    }
    /* Right column - Department */
    .timeline-dept-col {
        display: flex;
        flex-direction: column;
      align-items: flex-start;
        justify-content: flex-start;
        /* Align with the top edge of the details card */
        padding-top: 12px;
        padding-bottom: 0;
      /* Department column is on the left */
      grid-column: 1;
    }
    .timeline-dept-badge {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        color: white;
        padding: 8px 14px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 6px rgba(0, 151, 167, 0.3);
    }
    .timeline-dot {
        position: relative;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 14px;
        z-index: 2;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        transition: transform 0.2s ease;
        flex-shrink: 0;
    }
    .timeline-dot:hover {
        transform: scale(1.1);
    }
    .timeline-dot.completed {
        background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
    }
    .timeline-dot.pending {
        background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    }
    .timeline-dot.review {
        background: linear-gradient(135deg, #2196f3 0%, #1565c0 100%);
    }
    .timeline-dot.returned {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    }
    /* Pending fixed-route department: faded / grayed appearance */
    .timeline-dot.pending-route {
        background: linear-gradient(135deg, #cbd5e1 0%, #94a3b8 100%);
        opacity: 0.5;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .timeline-item-horizontal.pending-route-item {
        opacity: 0.45;
        filter: grayscale(0.6);
        pointer-events: none;
    }
    .timeline-item-horizontal.pending-route-item .timeline-dept-badge {
        background: linear-gradient(135deg, #94a3b8 0%, #cbd5e1 100%);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .timeline-item-horizontal.pending-route-item .timeline-details-col {
        color: #94a3b8;
    }
    .timeline-item-horizontal.pending-route-item .timeline-date-time,
    .timeline-item-horizontal.pending-route-item .timeline-description {
        color: #94a3b8;
    }
    .timeline-status-pill.pending-route {
        background: #f1f5f9;
        color: #94a3b8;
    }
    .timeline-status-pill.returned {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
    }
    .timeline-status-pill {
        position: relative;
        z-index: 3;
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.96);
        border-radius: 999px;
        width: 96px;
        text-align: center;
        justify-content: center;
    }
    .timeline-status-pill::before {
        content: '';
        position: absolute;
        inset: -6px;
        background: #ffffff;
        border-radius: 999px;
        z-index: -1;
    }
    .timeline-connector {
        /* Hidden - using ::before pseudo-element for vertical line instead */
        display: none;
    }
    .timeline-content-horizontal {
        /* Kept for backward compatibility but deprecated */
        flex: 1;
        margin-top: 0;
        margin-left: 0;
        padding: 12px 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        text-align: left;
        width: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: none; /* Hide old layout */
    }
    .timeline-content-horizontal:hover {
        transform: translateX(4px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
    }
    /* Details text styling */
    .timeline-date-time {
        font-size: 13px;
        color: var(--text-dark);
        font-weight: 500;
        margin-bottom: 6px;
    }
    .timeline-description {
        font-size: 13px;
        color: var(--text-light);
        line-height: 1.5;
    }
    .timeline-notes {
        margin-top: 10px;
        margin-bottom: 12px;
    }
    .timeline-notes details {
        background: #fef3c7;
        border-left: 3px solid #f59e0b;
        border-radius: 6px;
        padding: 0;
        overflow: hidden;
    }
    .timeline-notes summary {
        list-style: none;
        cursor: pointer;
        padding: 10px 12px;
        font-size: 12px;
        color: #92400e;
        display: flex;
        align-items: center;
        gap: 8px;
        user-select: none;
    }
    .timeline-notes summary::-webkit-details-marker {
        display: none;
    }
    .timeline-notes .timeline-notes-body {
        padding: 10px 12px 12px;
        font-size: 12px;
        color: #92400e;
        border-top: 1px solid rgba(146, 64, 14, 0.18);
        white-space: pre-wrap;
    }
    .timeline-dept {
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
        margin-bottom: 4px;
        white-space: normal;
        overflow: visible;
    }
    .timeline-time-h {
        color: var(--text-light);
        font-size: 11px;
        font-weight: 500;
        margin-bottom: 6px;
        display: inline-block;
        margin-right: 10px;
    }
    .timeline-action-h {
        color: var(--text-dark);
        font-size: 12px;
        line-height: 1.4;
        margin-bottom: 8px;
        display: block;
        overflow: visible;
    }
    .timeline-status-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0;
        margin-left: 8px;
        vertical-align: middle;
    }
    .timeline-status-pill.completed {
        background: #e8f5e9;
        color: #2e7d32;
    }
    .timeline-status-pill.pending {
        background: #fff3e0;
        color: #e65100;
    }
    .timeline-status-pill.review {
        background: #e3f2fd;
        color: #1565c0;
    }
    .btn-view-doc-small {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        border: none;
        padding: 4px 10px;
        border-radius: 5px;
        font-size: 10px;
        font-weight: 500;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .btn-view-doc-small:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }


    /* Mobile Badge Styles */
    .mobile-badge {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 2px;
        box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
        animation: pulse-mobile 2s infinite;
    }
    
    .mobile-badge i {
        font-size: 8px;
    }
    
    @keyframes pulse-mobile {
        0% { box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3); }
        50% { box-shadow: 0 2px 8px rgba(102, 126, 234, 0.6); }
        100% { box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3); }
    }

    /* Toast Notifications */
    #toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .toast {
        background-color: var(--text-dark);
        color: var(--white);
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: var(--shadow-lg);
        opacity: 0;
        transform: translateY(-20px);
        animation: toastIn 0.3s forwards, toastOut 0.3s forwards 2.7s;
        min-width: 250px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .toast.success { background-color: var(--completed); }
    .toast.error { background-color: var(--rejected); }
    .toast.info { background-color: var(--primary); }
    .toast.warning { background-color: var(--pending); }

    @keyframes toastIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes toastOut {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(-20px); }
    }

    /* Pagination Styles */
    .pagination-controls {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 20px;
        padding: 10px;
        background-color: var(--white);
        border-radius: 12px;
        box-shadow: var(--shadow);
    }
    .pagination-button {
        background-color: var(--primary);
        color: var(--white);
        border: none;
        padding: 10px 16px;
        border-radius: 8px;
        margin: 0 8px;
        cursor: pointer;
        transition: background-color 0.2s, transform 0.2s;
        text-decoration: none; /* remove underline for anchor */
        display: inline-block;
    }
    .pagination-button:hover {
        background-color: var(--primary-dark);
        transform: translateY(-2px);
    }
    .pagination-button:disabled {
        background-color: var(--border);
        color: var(--text-light);
        cursor: not-allowed;
        transform: none;
    }
    .pagination-button.disabled { background-color: var(--border); color: var(--text-light); cursor: not-allowed; pointer-events: none; transform: none; text-decoration: none; }
    .page-info {
        font-size: 14px;
        color: var(--text-dark);
        font-weight: 500;
    }

    /* Smooth page loading */
    body { opacity: 0; transition: opacity .2s ease; }
    body.page-ready { opacity: 1; }

    /* Skeleton Loader (simple version) */
    .skeleton-row {
        display: flex;
        gap: 10px;
        height: 20px;
        margin-bottom: 10px;
        animation: pulse-skeleton 1.5s infinite ease-in-out;
    }
    .skeleton-cell {
        height: 100%;
        background-color: #e0e0e0;
        border-radius: 4px;
    }
    @keyframes pulse-skeleton {
        0% { opacity: 0.5; }
        50% { opacity: 1; }
        100% { opacity: 0.5; }
    }

    /* Confirmation Modal Specific Styles (Enhanced) */
    .confirm-modal-content {
        background-color: var(--white);
        margin: auto;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        width: 90%;
        max-width: 450px;
        position: relative;
        transform: translateY(-20px);
        transition: transform 0.3s ease-out;
        border: 1px solid var(--primary-light);
    }
    .confirm-modal-content h3 {
        margin-bottom: 15px;
        color: var(--primary-dark);
        font-size: 22px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 10px;
    }
    .confirm-modal-content p {
        font-size: 16px;
        color: var(--text-dark);
        margin-bottom: 25px;
        line-height: 1.5;
    }
    .confirm-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
    }
    .confirm-modal-footer .filter-btn {
        padding: 10px 20px;
        font-size: 15px;
    }
    .confirm-modal-footer .filter-btn.danger {
        background-color: var(--rejected);
        color: var(--white);
        border-color: var(--rejected);
    }
    .confirm-modal-footer .filter-btn.danger:hover {
        background-color: #C62828;
        border-color: #C62828;
    }

    /* Responsive */
    @media (max-width: 992px) {
      .main-content { margin-left: 70px; }
      .top-bar .search-bar { width: 200px; }
    }
    @media (max-width: 768px) {
      .filters-container { flex-direction: column; align-items: stretch; }
      .filter-group { width: 100%; }
      .filter-actions { margin-left: 0; margin-top: 10px; display: flex; justify-content: space-between; width: 100%; }
      .filter-btn-clear, .filter-btn-apply { width: 48%; }
      .table-container { overflow-x: auto; }
      .document-table { min-width: 900px; }
      .top-bar { flex-direction: column; gap: 15px; }
      .search-bar { width: 100%; }
      .user-profile { align-self: flex-end; }
      .archive-actions { flex-direction: column; align-items: flex-start; }
      .modal-content, .confirm-modal-content {
          width: 95%;
          padding: 20px;
      }
      .modal-info-grid {
          grid-template-columns: 1fr;
      }
      .action-buttons-group {
          flex-wrap: wrap;
          gap: 4px;
      }
      .action-button {
          padding: 4px 8px;
          font-size: 10px;
      }
      .action-button i {
          font-size: 0.9em;
      }
    }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
  </style>
  <style>
    /* Animation/transition overrides for deployment: disable motion on this page (scoped) */
    .menu-badge,
    .stat-card:hover,
    .action-btn:hover,
    .docs-table tr:hover td,
    .dropdown-item,
    .chart-filter.active i,
    .docs-table td { transform: none !important; box-shadow: none !important; transition: none !important; animation: none !important; }
  </style>
</head>
<body class="no-page-anim">
  <div class="container">
    <div class="sidebar">
      <div class="sidebar-header">
        <img src="<?php echo htmlspecialchars(getAppSetting('logo_url','hr.png')); ?>" alt="CHRMO Logo" />
        <h2>CHRMO Document Management</h2>
        <span class="sidebar-subtitle">Document Tracking System</span>
      </div>
      <div class="sidebar-menu">
        <div class="sidebar-section-label">WORKSPACE</div>
        <a href="dashboard.php" class="menu-item">
          <i class="fas fa-tachometer-alt"></i>
          <span>Dashboard</span>
        </a>
        <a href="tracking.php" class="menu-item active">
          <i class="fas fa-file-signature"></i>
          <span>Document Status</span>
          <span class="menu-badge" id="trackingBadge">0</span>
        </a>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">ANALYTICS</div>
        <a href="stats.php" class="menu-item">
          <i class="fas fa-chart-bar"></i>
          <span>Status Reports</span>
        </a>
        <a href="archive.php" class="menu-item">
          <i class="fas fa-archive"></i>
          <span>Archive Storage</span>
          <span class="menu-badge success" id="archiveBadge">0</span>
        </a>
        <?php if ($__isAdmin): ?>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">MANAGEMENT</div>
        <a href="usercontrol.php" class="menu-item">
          <i class="fas fa-users-cog"></i>
          <span>User Control</span>
        </a>
        <?php endif; ?>
      </div>
      <div class="sidebar-footer">
        <span>v2.1.0 &bull; CHRMO &copy; 2026</span>
      </div>
    </div>

    <div class="main-content">
      <div class="top-bar">
        <h2>Document Status Overview</h2>
        <div class="top-bar-actions">
          <?php include __DIR__ . '/partials/notifications.php'; ?>
          <div class="user-profile" id="userProfile" title="User Profile" role="button" aria-expanded="false" aria-controls="userDropdown">
            <?php 
            $userInfo = getUserDisplayInfo();
            $initials = $userInfo ? getUserInitials($userInfo['name']) : 'U';
            $displayName = $userInfo ? $userInfo['name'] : 'User';
            ?>
            <img src="https://placehold.co/40x40/B2EBF2/0097A7?text=<?php echo urlencode($initials); ?>" alt="User Profile" />
            <div>
              <div><?php echo htmlspecialchars($displayName); ?></div>
              <small style="color: var(--text-light);"><?php echo htmlspecialchars(formatUserRole($userInfo ? $userInfo['role'] : 'user')); ?></small>
            </div>
            <i class="fas fa-chevron-down" style="margin-left: 10px;"></i>
            <div class="dropdown-menu" id="userDropdown">
              <div class="dropdown-item" style="border-top: 1px solid var(--border); background: transparent; padding: 12px; display: flex; justify-content: center;">
                <a href="logout.php" class="logout-ghost">
                  <i class="fas fa-sign-out-alt"></i>
                  <span class="label">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="filters-header-row">
        <div class="search-wrap">
          <div class="search-bar" style="width:480px; max-width: 70vw;">
            <i class="fas fa-search" style="color: var(--text-light);"></i>
            <input type="text" placeholder="Search documents..." id="searchInput" aria-label="Search documents" autocomplete="off" />
            <button type="button" id="ocrSearchBtn" class="ocr-search-toggle" title="Search in document content (OCR)">
              <i class="fas fa-file-alt"></i>
            </button>
          </div>
          <div id="searchSuggestions" class="search-suggestions"></div>
          <div id="ocrSearchResults" class="ocr-search-results" style="display:none;"></div>
        </div>
      </div>
      <div id="filtersChips" class="filters-chips"></div>
      <div id="filtersBackdrop" class="filters-backdrop"></div>
      <div id="filtersPanel" class="filters-panel">
        <div class="filters-container">
        <div class="filter-group">
          <div class="filter-btn" id="documentTypeFilterBtn" role="button" aria-haspopup="true" aria-expanded="false" aria-controls="documentTypeDropdown">
            <span>Document Type</span>
            <i class="fas fa-chevron-down"></i>
          </div>
          <div class="filter-dropdown-menu" id="documentTypeDropdown" role="menu">
            <div class="filter-dropdown-item selected" data-value="All Types" role="menuitem"><span class="fdi-icon fdi-all"><i class="fas fa-layer-group"></i></span><span class="fdi-label">All Types</span></div>
            <div class="filter-dropdown-item" data-value="Payroll" role="menuitem"><span class="fdi-icon fdi-payroll"><i class="fas fa-money-check-alt"></i></span><span class="fdi-label">Payroll</span></div>
            <div class="filter-dropdown-item" data-value="Memo" role="menuitem"><span class="fdi-icon fdi-memo"><i class="fas fa-file-alt"></i></span><span class="fdi-label">Memo</span></div>
            <div class="filter-dropdown-item" data-value="Travel Order" role="menuitem"><span class="fdi-icon fdi-travel"><i class="fas fa-plane"></i></span><span class="fdi-label">Travel Order</span></div>
            <div class="filter-dropdown-item" data-value="Activity Design" role="menuitem"><span class="fdi-icon fdi-activity"><i class="fas fa-palette"></i></span><span class="fdi-label">Activity Design</span></div>
            <div class="filter-dropdown-item" data-value="Purchase Request" role="menuitem"><span class="fdi-icon fdi-purchase"><i class="fas fa-shopping-cart"></i></span><span class="fdi-label">Purchase Request</span></div>
            <div class="filter-dropdown-item" data-value="Purchase Order" role="menuitem"><span class="fdi-icon fdi-purchase"><i class="fas fa-file-invoice-dollar"></i></span><span class="fdi-label">Purchase Order</span></div>
            <div class="filter-dropdown-item" data-value="Advisories" role="menuitem"><span class="fdi-icon fdi-advisory"><i class="fas fa-exclamation-circle"></i></span><span class="fdi-label">Advisories</span></div>
            <div class="filter-dropdown-item" data-value="Announcement" role="menuitem"><span class="fdi-icon fdi-announce"><i class="fas fa-bullhorn"></i></span><span class="fdi-label">Announcement</span></div>
          </div>
        </div>
        <div class="filter-group">
          <div class="filter-btn" id="statusFilterBtn" role="button" aria-haspopup="true" aria-expanded="false" aria-controls="statusDropdown">
            <span>Document Status</span>
            <i class="fas fa-chevron-down"></i>
          </div>
          <div class="filter-dropdown-menu" id="statusDropdown" role="menu">
            <div class="filter-dropdown-item selected" data-value="All Statuses" role="menuitem"><span class="fdi-icon fdi-all"><i class="fas fa-th-list"></i></span><span class="fdi-label">All Statuses</span></div>
            <div class="filter-dropdown-item" data-value="Pending" role="menuitem"><span class="fdi-icon fdi-pending"><i class="fas fa-clock"></i></span><span class="fdi-label">Pending</span><span class="fdi-badge fdi-pending">Waiting</span></div>
            <div class="filter-dropdown-item" data-value="In Review" role="menuitem"><span class="fdi-icon fdi-review"><i class="fas fa-search"></i></span><span class="fdi-label">In Review</span><span class="fdi-badge fdi-review">Active</span></div>
            <div class="filter-dropdown-item" data-value="Completed" role="menuitem"><span class="fdi-icon fdi-completed"><i class="fas fa-check-circle"></i></span><span class="fdi-label">Completed</span><span class="fdi-badge fdi-completed">Done</span></div>
            <div class="filter-dropdown-item" data-value="Returned" role="menuitem"><span class="fdi-icon fdi-archived"><i class="fas fa-archive"></i></span><span class="fdi-label">Returned</span><span class="fdi-badge fdi-archived">Returned</span></div>
          </div>
        </div>
        <div class="filter-group">
          <div class="filter-btn" id="departmentFilterBtn" role="button" aria-haspopup="true" aria-expanded="false" aria-controls="departmentDropdown">
            <span>Department</span>
            <i class="fas fa-chevron-down"></i>
          </div>
          <div class="filter-dropdown-menu" id="departmentDropdown" role="menu">
            <div class="filter-dropdown-item selected" data-value="All Departments" role="menuitem"><span class="fdi-icon fdi-all"><i class="fas fa-building"></i></span><span class="fdi-label">All Departments</span></div>
            <?php
              $deptListUi = __tracking_load_departments($connection);
              foreach ($deptListUi as $d) {
                $d = strtoupper(trim((string)$d));
                if ($d === '') continue;
                echo '<div class="filter-dropdown-item" data-value="' . htmlspecialchars($d) . '" role="menuitem"><span class="fdi-icon fdi-dept"><i class="fas fa-building"></i></span><span class="fdi-label">' . htmlspecialchars($d) . '</span></div>';
              }
            ?>
          </div>
        </div>
        <div class="filter-group">
          <div class="filter-btn" id="dateRangeFilterBtn" role="button" aria-haspopup="true" aria-expanded="false" aria-controls="dateRangeDropdown">
            <span>Date Range</span>
            <i class="fas fa-chevron-down"></i>
          </div>
          <div class="filter-dropdown-menu" id="dateRangeDropdown" role="menu">
            <div class="filter-dropdown-item selected" data-value="All Dates" role="menuitem"><span class="fdi-icon fdi-all"><i class="fas fa-calendar"></i></span><span class="fdi-label">All Dates</span></div>
            <div class="filter-dropdown-item" data-value="Today" role="menuitem"><span class="fdi-icon fdi-calendar"><i class="fas fa-calendar-day"></i></span><span class="fdi-label">Today</span></div>
            <div class="filter-dropdown-item" data-value="Last 7 Days" role="menuitem"><span class="fdi-icon fdi-date"><i class="fas fa-calendar-week"></i></span><span class="fdi-label">Last 7 Days</span></div>
            <div class="filter-dropdown-item" data-value="Last 30 Days" role="menuitem"><span class="fdi-icon fdi-date"><i class="fas fa-calendar-alt"></i></span><span class="fdi-label">Last 30 Days</span></div>
            <div class="filter-dropdown-item" data-value="This Month" role="menuitem"><span class="fdi-icon fdi-date"><i class="fas fa-calendar-check"></i></span><span class="fdi-label">This Month</span></div>
            <div class="filter-dropdown-item" data-value="This Year" role="menuitem"><span class="fdi-icon fdi-date"><i class="fas fa-calendar"></i></span><span class="fdi-label">This Year</span></div>
            <div class="filter-dropdown-item" data-value="Custom" role="menuitem"><span class="fdi-icon fdi-date"><i class="fas fa-sliders-h"></i></span><span class="fdi-label">Custom Range</span>
                <input type="text" id="dateRange" placeholder="Select dates" style="margin-left: 8px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 5px 10px; font-size: 12px; width: 140px; transition: border-color .2s;" onfocus="this.style.borderColor='#0891b2'" onblur="this.style.borderColor='#e2e8f0'" />
            </div>
          </div>
        </div>
        <div class="filter-actions">
          <button class="filter-btn-clear" id="clearFiltersBtn">
            <i class="fas fa-times"></i> Clear Filters
          </button>
          <button class="filter-btn-apply" id="applyFiltersBtn">
            <i class="fas fa-check"></i> Apply Filters
          </button>
        </div>
        </div>
      </div>

      <div class="view-options" style="display:flex; gap:10px; margin: 0 0 12px 0;">
        <button class="action-button" id="viewTableBtn" title="Table View" aria-pressed="true"><i class="fas fa-table"></i></button>
        <button class="action-button" id="viewListBtn" title="List View" aria-pressed="false"><i class="fas fa-list"></i></button>
        <button class="action-button" id="viewGridBtn" title="Grid View" aria-pressed="false"><i class="fas fa-th-large"></i></button>
        <button class="action-button filters-open-btn" id="openFiltersBtn" title="Filters" aria-haspopup="dialog" aria-expanded="false">
          <i class="fas fa-filter"></i>
          <span class="filters-count" id="activeFiltersCount" style="display:none;">0</span>
        </button>
      </div>

      <div class="table-container" id="docTableContainer" style="overflow: visible;">
        <table class="document-table" aria-label="Document Tracking Table" id="documentTable">
          <thead>
            <tr role="row">
              <th data-sortable="true" data-sort-by="type" role="columnheader" aria-sort="none">Document Type <span class="sort-icon"></span></th>
              <th data-sortable="true" data-sort-by="current_holder" role="columnheader" aria-sort="none">Current Holder <span class="sort-icon"></span></th>
              <th data-sortable="true" data-sort-by="end_location" role="columnheader" aria-sort="none">End Location <span class="sort-icon"></span></th>
              <th data-sortable="true" data-sort-by="date_submitted" role="columnheader" aria-sort="none">Date Submitted <span class="sort-icon"></span></th>
              <th data-sortable="true" data-sort-by="status" role="columnheader" aria-sort="none">Status <span class="sort-icon"></span></th>
              <th data-sortable="true" data-sort-by="overdue_seconds" role="columnheader" aria-sort="none">Time in Dept <span class="sort-icon"></span></th>
              <th data-sortable="false" role="columnheader">Actions</th>
            </tr>
          </thead>
          <tbody id="documentTableBody">
            <?php
            // Define helper functions in PHP
            function getPhpFileTypeIcon($fileType) {
              switch (strtolower((string)$fileType)) {
                    case 'pdf': return 'fas fa-file-pdf pdf';
                    case 'doc':
                    case 'docx': return 'fas fa-file-word doc';
                    case 'xls':
                    case 'xlsx': return 'fas fa-file-excel xls';
                    case 'img':
                    case 'png':
                    case 'jpg':
                    case 'jpeg': return 'fas fa-file-image img';
                    case 'txt': return 'fas fa-file-alt txt';
                    default: return 'fas fa-file';
                }
            }

            function getPhpStatusPillClass($status) {
              switch (strtolower((string)$status)) {
                    case 'pending': return 'status-pill status-pending pulse';
                    case 'returned': return 'status-pill status-returned';
                    case 'completed': return 'status-pill status-completed';
                    case 'in review': return 'status-pill status-review pulse';
                    case 'approved': return 'status-pill status-approved';
                    case 'archived': return 'status-pill status-archived';
                    case 'rejected': return 'status-pill status-rejected';
                    default: return 'status-pill';
                }
            }

            function getPhpOverduePillClass($state) {
                $state = strtolower((string)$state);
                switch ($state) {
                    case 'late': return 'overdue-pill overdue-late';
                    case 'warn': return 'overdue-pill overdue-warn';
                    case 'cleared': return 'overdue-pill overdue-cleared';
                    case 'ok': return 'overdue-pill overdue-ok';
                    case 'na':
                    default: return 'overdue-pill overdue-na';
                }
            }

            function formatDepartmentHolder($department, $holder) {
                $holderVal = trim((string)$holder);
                if ($holderVal !== '') return strtoupper($holderVal);
                $dept = trim((string)$department);
                return $dept !== '' ? strtoupper($dept) : '—';
            }

            function getDocTypeChipClass($type) {
                $t = strtolower(trim((string)$type));
                $map = [
                    'memo' => 'chip-memo',
                    'payroll' => 'chip-payroll',
                    'leave' => 'chip-leave',
                    'advisory' => 'chip-advisory',
                    'advisories' => 'chip-advisories',
                    'announcement' => 'chip-announcement',
                    'appointment' => 'chip-appointment',
                    'order' => 'chip-order',
                ];
                return 'doc-type-chip ' . ($map[$t] ?? 'chip-default');
            }

            foreach ($documents as $doc) {
                $fileTypeIconClass = getPhpFileTypeIcon($doc['file_type_icon']);
                $statusPillClass = getPhpStatusPillClass($doc['status']);
                // Check if document is at final department
                $currentHolder = strtoupper(trim($doc['current_holder'] ?? ''));
                $endLocation = strtoupper(trim($doc['end_location'] ?? ''));
                $isFinalDepartment = ($currentHolder !== '' && $endLocation !== '' && $currentHolder === $endLocation);
                $status = $doc['status'] ?? '';
                ?>
                <tr data-id="<?= htmlspecialchars($doc['id']) ?>" data-type="<?= htmlspecialchars($doc['type']) ?>" data-status="<?= htmlspecialchars($doc['status']) ?>" data-employee="<?= htmlspecialchars($doc['employee_name']) ?>">
                    <td>
                      <span class="<?= htmlspecialchars(getDocTypeChipClass($doc['type'])) ?>">
                        <i class="<?= htmlspecialchars($fileTypeIconClass) ?>"></i> <?= htmlspecialchars($doc['type']) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars(formatDepartmentHolder($doc['department'] ?? '', $doc['current_holder'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($doc['end_location']) ?></td>
                    <td><?= htmlspecialchars($doc['date_submitted']) ?></td>
                    <td><span class="<?= htmlspecialchars($statusPillClass) ?>"><?= htmlspecialchars($doc['status']) ?></span></td>
                    <td>
                        <span class="<?= htmlspecialchars(getPhpOverduePillClass($doc['overdue_state'] ?? 'na')) ?>" title="<?= htmlspecialchars($doc['overdue_full_label'] ?? '') ?>">
                            <?= htmlspecialchars($doc['overdue_label'] ?? '—') ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons-group">
                            <button class="action-button info" title="View Info" onclick="viewDocumentInfo('<?= htmlspecialchars($doc['id']) ?>')">
                                <i class="fas fa-info-circle"></i> Info
                            </button>
                            <button class="action-button timeline" title="View Timeline" onclick="viewDocumentTimeline('<?= htmlspecialchars($doc['id']) ?>')">
                                <i class="fas fa-history"></i> Timeline
                            </button>
                            <button class="action-button archive" title="Archive" <?= ($status === 'Completed') ? "onclick=\"archiveDocumentConfirm('" . htmlspecialchars($doc['id']) . "', '" . htmlspecialchars($doc['type']) . "')\"" : '' ?>
                                <?= ($status !== 'Completed') ? 'disabled' : '' ?>>
                                <i class="fas fa-archive"></i> Archive
                            </button>
                        </div>
                    </td>
                </tr>
                <?php
            }
            ?>
          </tbody>
        </table>
      </div>
      <div id="docListView" style="display:none;"></div>
      <div id="docGridView" style="display:none; grid-template-columns: repeat(auto-fill,minmax(320px,1fr)); gap:16px;"></div>

      <div class="pagination-controls">
        <?php
          // Build pagination links and preserve query params
          $qs = $_GET; unset($qs['page']);
          function pageLink($p, $qs){ $qs['page']=$p; return 'tracking.php?'.http_build_query($qs); }
          $prevHref = '#'; $nextHref = '#';
          $prevClass = 'pagination-button'; $nextClass = 'pagination-button';
          $prevAria = 'true'; $nextAria = 'true';
          if ($currentPage > 1) { $prevHref = pageLink($currentPage-1, $qs); $prevAria = 'false'; }
          else { $prevClass .= ' disabled'; }
          if ($currentPage < $totalPages) { $nextHref = pageLink($currentPage+1, $qs); $nextAria = 'false'; }
          else { $nextClass .= ' disabled'; }
        ?>
        <a class="<?= $prevClass ?>" role="button" aria-disabled="<?= $prevAria ?>" href="<?= htmlspecialchars($prevHref) ?>">Previous</a>
        <span id="pageInfo" class="page-info" data-serverside="1" aria-live="polite">Page <?= $currentPage ?> of <?= $totalPages ?></span>
        <a class="<?= $nextClass ?>" role="button" aria-disabled="<?= $nextAria ?>" href="<?= htmlspecialchars($nextHref) ?>">Next</a>
      </div>
    </div>
  </div>

  <div id="addEditDocumentModal" class="modal" aria-modal="true" role="dialog" aria-labelledby="addEditDocModalTitle">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title" id="addEditDocModalTitle">Add New Document</h3>
        <button class="modal-close" onclick="closeModal('addEditDocumentModal')" aria-label="Close add/edit document modal">&times;</button>
      </div>
      <form id="documentForm" method="POST" action="tracking.php">
        <input type="hidden" id="documentId" name="id" value="">
        <div class="route-form-group">
          <label for="docType">Document Type</label>
          <select id="docType" name="type" required>
            <option value="">Select Type</option>
            <option value="Payroll">Payroll</option>
            <option value="Memo">Memo</option>
            <option value="Travel Order">Travel Order</option>
            <option value="Activity Design">Activity Design</option>
            <option value="Purchase Request">Purchase Request</option>
            <option value="Purchase Order">Purchase Order</option>
            <option value="Advisories">Advisories</option>
            <option value="Announcement">Announcement</option>
          </select>
        </div>
        <div class="route-form-group">
          <label for="docEmployee">Employee Name</label>
          <input type="text" id="docEmployee" name="employee" placeholder="Enter employee name" required>
        </div>
        <div class="route-form-group">
          <label for="docDate">Date Submitted</label>
          <input type="date" id="docDate" name="date" required>
        </div>
        <div class="route-form-group">
          <label for="docHolder">Current Holder</label>
          <input type="text" id="docHolder" name="holder" placeholder="Enter current holder" required>
        </div>
        <div class="route-form-group">
          <label for="docEndLocation">End Location</label>
          <input type="text" id="docEndLocation" name="endLocation" placeholder="Enter end location" required>
        </div>
        <div class="route-form-group">
          <label for="docStatus">Status</label>
          <select id="docStatus" name="status" required>
            <option value="">Select Status</option>
            <option value="Pending">Pending</option>
            <option value="In Review">In Review</option>
            <option value="Completed">Completed</option>
            <option value="Archived">Archived</option>
          </select>
        </div>
        <div class="route-form-group">
          <label for="docFileTypeIcon">File Type (e.g., pdf, doc, xls)</label>
          <input type="text" id="docFileTypeIcon" name="fileTypeIcon" placeholder="e.g., pdf, doc, xls" required>
        </div>
        <div class="route-form-actions">
          <button type="button" class="cancel-btn" onclick="closeModal('addEditDocumentModal')">Cancel</button>
          <button type="submit" class="confirm-btn">Save Document</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal" id="routingHistoryModal" aria-modal="true" role="dialog" aria-labelledby="modalTitle">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Routing History</h3>
        <button class="modal-close" onclick="closeModal('routingHistoryModal')" aria-label="Close routing history modal">&times;</button>
      </div>
      <div class="route-history" id="routeHistoryContent">
        </div>
    </div>
  </div>

  <!-- Document Info Modal -->
  <div class="modal" id="viewDocumentInfoModal" aria-modal="true" role="dialog" aria-labelledby="infoModalTitle">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title" id="infoModalTitle"><i class="fas fa-info-circle" style="color:#00ACC1;"></i> Document Information</h3>
        <button class="modal-close" onclick="closeModal('viewDocumentInfoModal')" aria-label="Close document info modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="document-info-container">
          <div class="document-info-header">
            <div class="document-icon">
              <i id="infoFileTypeIcon" class="fas fa-file"></i>
            </div>
            <div class="document-title">
              <h4 id="infoDocTypeTitle"></h4>
              <span id="infoStatusPill" class="status-pill"></span>
            </div>
          </div>
          
          <div class="document-info-grid">
            <div class="info-section">
              <h5><i class="fas fa-info-circle"></i> Document Information</h5>
              <div class="info-item">
                <span class="info-label">Date Submitted:</span>
                <span class="info-value" id="infoDateSubmitted"></span>
              </div>
              <div class="info-item">
                <span class="info-label">Time Submitted:</span>
                <span class="info-value" id="infoTimeSubmitted"></span>
              </div>
              <div class="info-item">
                <span class="info-label">File Type:</span>
                <span class="info-value" id="infoFileType"></span>
              </div>
              <div class="info-item">
                <span class="info-label">Current Holder:</span>
                <span class="info-value" id="infoCurrentHolder"></span>
              </div>
              <div class="info-item">
                <span class="info-label">End Location:</span>
                <span class="info-value" id="infoEndLocation"></span>
              </div>
              <div class="info-item">
                <span class="info-label">Document File:</span>
                <button id="infoViewDocumentBtn" type="button" class="filter-btn" style="padding:6px 12px; font-size:0.85rem;">
                  <i class="fas fa-download"></i>&nbsp;View Document
                </button>
              </div>

              <!-- Extracted Information Keys -->
              <div id="infoExtractedKeysSection" class="info-item" style="align-items: flex-start; display: none;">
                <span class="info-label">Extracted Info:</span>
                <div id="infoExtractedKeysContainer" class="info-value" style="width: 100%;">
                  <div id="infoExtractedKeysGrid" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
                </div>
              </div>

              <div class="info-item" style="align-items: flex-start;">
                <span class="info-label">OCR Content:</span>
                <div class="info-value" style="width: 100%;">
                  <textarea id="infoOcrContent" style="width:100%; background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:10px; max-height:220px; height:220px; overflow:auto; resize:vertical; white-space:pre-wrap; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; font-size:12px; line-height:1.5; color:#0f172a; text-align:justify; text-justify:inter-word; text-align-last:left; hyphens:auto; word-break:break-word;" spellcheck="false">Loading OCR...</textarea>
                  <div style="margin-top:8px; display:flex; justify-content:flex-end; gap:8px;">
                    <button id="infoSaveOcrBtn" type="button" class="filter-btn" style="padding:6px 12px; font-size:0.85rem;">
                      <i class="fas fa-save"></i>&nbsp;Save OCR Correction
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Comments Section -->
          <div class="info-section" style="margin-top: 20px;">
            <h5><i class="fas fa-comments" style="color: #0ea5e9;"></i> Comments & Notes</h5>
            <div id="infoCommentsContainer" style="max-height: 200px; overflow-y: auto; margin-bottom: 12px;">
              <div class="comments-loading" style="text-align: center; padding: 20px; color: #64748b;">
                <i class="fas fa-spinner fa-spin"></i> Loading comments...
              </div>
            </div>
            <div class="add-comment-form" style="display: flex; gap: 8px; flex-wrap: wrap;">
              <textarea id="infoNewCommentInput" placeholder="Add a comment..." style="flex: 1; min-width: 200px; padding: 10px; border: 1px solid var(--border); border-radius: 8px; resize: vertical; min-height: 60px; font-family: inherit; font-size: 13px;"></textarea>
              <button id="infoAddCommentBtn" type="button" class="filter-btn" style="padding: 10px 16px; align-self: flex-end;">
                <i class="fas fa-paper-plane"></i>&nbsp;Add Comment
              </button>
            </div>
          </div>

          <!-- Documents & Attachments Tabbed Section -->
          <div class="info-section" style="margin-top: 20px;">
            <h5 style="margin:0 0 12px 0;"><i class="fas fa-folder-open" style="color: #0ea5e9;"></i> Documents & Attachments</h5>
            <!-- Tabs -->
            <div id="infoDocTabs" style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:14px;">
              <button class="info-doc-tab active" data-tab="versions" style="padding:8px 16px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:#0ea5e9;border-bottom:2px solid #0ea5e9;margin-bottom:-2px;transition:all 0.2s;">
                <i class="fas fa-history"></i>&nbsp;Versions <span id="tabCountVersions" style="background:#fef3c7;color:#b45309;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:4px;">0</span>
              </button>
              <button class="info-doc-tab" data-tab="attachments" style="padding:8px 16px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:#94a3b8;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all 0.2s;">
                <i class="fas fa-paperclip"></i>&nbsp;Attachments <span id="tabCountAttachments" style="background:#ede9fe;color:#7c3aed;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:4px;">0</span>
              </button>
            </div>
            <!-- Tab content panels -->
            <div id="infoAttachmentsContainer" style="min-height:80px;max-height:320px;overflow-y:auto;">
              <div style="text-align:center;padding:20px;color:#64748b;">
                <i class="fas fa-spinner fa-spin"></i> Loading documents...
              </div>
            </div>
            <!-- Action buttons at bottom -->
            <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;">
              <button id="infoCompileAllBtn" type="button" style="padding:7px 16px;font-size:0.8rem;background:linear-gradient(135deg,#6366f1 0%,#4f46e5 100%);color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600;display:none;" title="Download all documents as a single ZIP file">
                <i class="fas fa-file-archive"></i>&nbsp;Compile All
              </button>
              <div style="position:relative;display:inline-block;">
                <button id="infoDownloadAllBtn" type="button" style="width:32px;height:32px;padding:0;border:1px solid #e2e8f0;background:#f8fafc;border-radius:50%;cursor:pointer;display:none;align-items:center;justify-content:center;color:#64748b;font-size:14px;transition:all 0.2s;" title="Download individual files" onmouseover="this.style.background='#e2e8f0';this.style.color='#10b981'" onmouseout="this.style.background='#f8fafc';this.style.color='#64748b'">
                  <i class="fas fa-download"></i>
                </button>
                <div id="downloadAllPanel" style="display:none;position:absolute;bottom:100%;right:0;margin-bottom:6px;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);padding:10px;min-width:280px;max-height:320px;overflow-y:auto;z-index:9999;">
                  <div id="downloadAllList" style="display:flex;flex-direction:column;gap:6px;"></div>
                </div>
              </div>
              <button id="infoRefreshAttachmentsBtn" type="button" style="width:32px;height:32px;padding:0;border:1px solid #e2e8f0;background:#f8fafc;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:14px;transition:all 0.2s;" title="Refresh documents" onmouseover="this.style.background='#e2e8f0';this.style.color='#0ea5e9'" onmouseout="this.style.background='#f8fafc';this.style.color='#64748b'">
                <i class="fas fa-sync-alt"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Document Timeline Modal -->
  <div class="modal" id="viewDocumentTimelineModal" aria-modal="true" role="dialog" aria-labelledby="timelineModalTitle">
    <div class="modal-content" style="width: 900px; max-width: 95%;">
      <div class="modal-header">
        <h3 class="modal-title" id="timelineModalTitle"><i class="fas fa-history" style="color:#FF9800;"></i> Document Timeline: <span id="timelineDocumentName"></span></h3>
        <button class="modal-close" onclick="closeModal('viewDocumentTimelineModal')" aria-label="Close document timeline modal">&times;</button>
      </div>
      <div class="modal-body">
        <!-- Current Status Display -->
        <div class="timeline-status-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding: 12px 16px; background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border-radius: 10px; border: 1px solid #e2e8f0;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-weight: 600; color: #334155;">Current Status:</span>
            <span id="timelineCurrentStatus" class="status-pill"></span>
          </div>
          <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-weight: 600; color: #334155;">Current Holder:</span>
            <span id="timelineCurrentHolder" style="font-weight: 500; color: #0ea5e9;"></span>
          </div>
        </div>
        
        <div class="document-info-container">
          <div class="activity-timeline horizontal-timeline">
            <div class="timeline-container-horizontal" id="timelineActivityLog">
              <!-- Timeline items will be populated here -->
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Legacy View Modal (kept for compatibility) -->
  <div class="modal" id="viewDocumentDetailModal" aria-modal="true" role="dialog" aria-labelledby="viewDetailModalTitle">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title" id="viewDetailModalTitle">Document Details: <span id="detailDocumentName"></span></h3>
        <button class="modal-close" onclick="closeModal('viewDocumentDetailModal')" aria-label="Close document details modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="document-info-container">
          <div class="document-info-header">
            <div class="document-icon">
              <i id="detailFileTypeIcon" class="fas fa-file"></i>
            </div>
            <div class="document-title">
              <h4 id="detailDocTypeTitle"></h4>
              <span id="detailStatusPill" class="status-pill"></span>
            </div>
          </div>
          
          <div class="document-info-grid">
            <div class="info-section">
              <h5><i class="fas fa-info-circle"></i> Document Information</h5>
              <div class="info-item">
                <span class="info-label">Employee Name:</span>
                <span class="info-value" id="detailEmployeeName"></span>
              </div>
              <div class="info-item">
                <span class="info-label">Date Submitted:</span>
                <span class="info-value" id="detailDateSubmitted"></span>
              </div>
              <div class="info-item">
                <span class="info-label">File Type:</span>
                <span class="info-value" id="detailFileType"></span>
              </div>
              <div class="info-item">
                <span class="info-label">Current Holder:</span>
                <span class="info-value" id="detailCurrentHolder"></span>
              </div>
              <div class="info-item">
                <span class="info-label">End Location:</span>
                <span class="info-value" id="detailEndLocation"></span>
              </div>
              <div class="info-item">
                <span class="info-label">Document File:</span>
                <button id="detailViewDocumentBtn" type="button" class="filter-btn" style="padding:6px 12px; font-size:0.85rem;">
                  <i class="fas fa-eye"></i>&nbsp;View Document
                </button>
              </div>
            </div>
          </div>
          
          
          <div class="activity-timeline">
            <h5><i class="fas fa-history"></i> Document Timeline</h5>
            <div class="timeline-container" id="detailActivityLog">
              <!-- Timeline items will be populated here -->
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="confirmActionModal" class="modal">
    <div class="confirm-modal-content">
      <span class="close-button" id="closeConfirmModalBtn">&times;</span>
      <h3 id="confirmModalTitle">Confirm Action</h3>
      <p id="confirmModalMessage">Are you sure you want to proceed with this action?</p>
      <div class="confirm-modal-footer">
        <button type="button" class="filter-btn secondary" id="cancelConfirmBtn">Cancel</button>
        <button type="button" class="filter-btn danger" id="proceedConfirmBtn">Proceed</button>
      </div>
    </div>
  </div>

  <div id="toast-container"></div>

  <div id="realtimeSyncIndicator" style="position:fixed; bottom:10px; right:12px; z-index:9999; background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:6px 10px; font-size:12px; color:#334155; box-shadow:0 10px 18px rgba(0,0,0,0.08); display:none;">
    Last sync: <span id="realtimeSyncTime">—</span>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr">

  </script>
  <script type="module">
    import { initializeApp, getApps, getApp } from "https://www.gstatic.com/firebasejs/12.6.0/firebase-app.js";
    import { getFirestore, collection, onSnapshot } from "https://www.gstatic.com/firebasejs/12.6.0/firebase-firestore.js";
    import { getMessaging, getToken, onMessage } from "https://www.gstatic.com/firebasejs/12.6.0/firebase-messaging.js";

    console.log('[Firebase] module loaded');

    const firebaseConfig = {
      apiKey: "AIzaSyB3QZlJM50peeGc126ZcmRrpJsVK3qEmxQ",
      authDomain: "chrmo-21269.firebaseapp.com",
      projectId: "chrmo-21269",
      storageBucket: "chrmo-21269.firebasestorage.app",
      messagingSenderId: "1037241739258",
      appId: "1:1037241739258:web:28ad395cae1cd9fb4be643",
      measurementId: "G-RVK37NKG1W"
    };

    const currentUser = <?= json_encode($_SESSION['user'] ?? $_SESSION['username'] ?? '') ?>;
    const currentDepartment = <?= json_encode($_SESSION['department'] ?? $_SESSION['user_department'] ?? '') ?>;
    window.currentUser = window.currentUser ?? currentUser;
    window.currentDepartment = window.currentDepartment ?? currentDepartment;

    const app = getApps().length ? getApp() : initializeApp(firebaseConfig);
    const db = getFirestore(app);

    // Web push (FCM) registration (best-effort)
    async function initWebPush() {
      try {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
          return;
        }
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
          return;
        }

        const reg = await navigator.serviceWorker.register(`/flutter_application_7/firebase-messaging-sw.js?v=<?= $__sw_ver ?>`);
        const messaging = getMessaging(app);

        // TODO: replace with your Firebase Console -> Cloud Messaging -> Web Push certificates -> Key pair
        const VAPID_PUBLIC_KEY = 'BH36aZirUfps2gyj3IEnI5d42m5pC7VPNf176XNcKSfYrmlSr6nZMrctdZAvOQmMFfK4zl32BDGpOKVQgNX368Q';
        if (!VAPID_PUBLIC_KEY || VAPID_PUBLIC_KEY === 'PASTE_YOUR_VAPID_PUBLIC_KEY_HERE') {
          return;
        }

        const token = await getToken(messaging, {
          vapidKey: VAPID_PUBLIC_KEY,
          serviceWorkerRegistration: reg,
        });

        if (!token) {
          return;
        }

        const username = <?= json_encode($_SESSION['user'] ?? $_SESSION['username'] ?? '') ?>;
        const department = <?= json_encode($_SESSION['department'] ?? $_SESSION['user_department'] ?? '') ?>;
        if (!username) {
          return;
        }

        await fetch('api/register_token.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            username: String(username),
            department: String(department || ''),
            token: String(token),
            platform: 'web',
          }).toString(),
        });

        // Foreground messages
        onMessage(messaging, (payload) => {
          try {
            const title = payload?.notification?.title || 'Notification';
            const body = payload?.notification?.body || '';
            new Notification(title, { body });
          } catch (_) {
            // ignore
          }
        });
      } catch (_) {
        // ignore
      }
    }

    initWebPush();

    function startRealtimeTrackingListener() {
      if (!Array.isArray(window.trackingDocuments) || typeof window.applyFiltersAndSearch !== 'function') {
        console.warn('[Firestore] listener not started: globals not ready');
        return;
      }

      console.log('[Firestore] listener started');

      // Firestore listener for realtime updates.
      // This updates the shared `trackingDocuments` array (defined in the non-module script)
      // and then calls the existing filtering/rendering pipeline.
      onSnapshot(collection(db, 'tracking'), (snapshot) => {
        console.log('[Firestore] tracking changes:');

        snapshot.docChanges().forEach((change) => {
          const data = change.doc.data() || {};
          const id = change.doc.id;

          console.log(change.type.toUpperCase(), 'id =', id, 'data =', data);

        // Ignore test / placeholder documents that don't look like real tracking rows
        if (!data.type && !data.employee_name && !data.department) {
          console.log('[Firestore] skipping non-tracking doc id =', id);
          return;
        }

        // Department-scoped filtering: department_user only sees documents involving their department
        // route_step gating: dept must be in routing_queue AND step >= dept's 0-based position
        if (!window.__trackingIsAdmin && window.__trackingUserDept) {
          const ud = window.__trackingUserDept.toUpperCase().trim();
          const dept   = (data.department    || '').toUpperCase().trim();
          const holder = (data.current_holder || '').toUpperCase().trim();
          const endLoc = (data.end_location   || '').toUpperCase().trim();
          // Check if dept appears in routing_queue AND route_step has reached that position
          let inRoute = false;
          const queue = (data.routing_queue || '');
          if (queue) {
            const qParts = queue.split(',').map(s => s.trim().toUpperCase());
            const pos = qParts.indexOf(ud); // 0-based
            const step = parseInt(data.route_step || '0', 10);
            inRoute = (pos >= 0 && step >= pos);
          }
          if (dept !== ud && holder !== ud && endLoc !== ud && !inRoute) {
            console.log('[Firestore] dept filter: skipping doc id =', id, '(not relevant to', ud, ')');
            return;
          }
        }

        // Prefer numeric tracking table id (data.id) if present so MySQL and Firestore share the same key
        const documentId = (data.id !== undefined && data.id !== null) ? String(data.id) : String(id);

        // Use the shared trackingDocuments array exposed by the main script
          const docs = window.trackingDocuments;
          if (!Array.isArray(docs)) {
            return;
          }

        // Map Firestore fields into the shape used by tracking.php
        // Prefer numeric tracking table id (data.id) if present so
        // MySQL- and Firestore-originated rows share the same id.
        const mapped = {
          id: (data.id !== undefined && data.id !== null) ? data.id : id,
          type: data.type || '',
          employee_name: data.employee_name || '',
          department: data.department || data.current_holder || '',
          current_holder: data.current_holder || data.department || '',
          end_location: data.end_location || '',
          status: data.status || 'Pending',
          date_submitted: data.date_submitted || '',
          file_type_icon: data.file_type_icon || 'file',
          mobile_timestamp: data.mobile_timestamp || null,
          overdue_state: data.overdue_state || 'na',
          overdue_label: data.overdue_label || '—',
          overdue_full_label: data.overdue_full_label || '',
          file_path: data.file_path || '',
          ocr_content: data.ocr_content || '',
          history: data.history || [],
        };

          const mergeIntoArray = (payload) => {
            const docData = payload || mapped;
            const idx = docs.findIndex(d => String(d.id) === String(documentId));
            if (change.type === 'added') {
              if (idx === -1) {
                docs.push(docData);
              } else {
                docs[idx] = { ...docs[idx], ...docData };
              }
            } else if (change.type === 'modified') {
              if (idx !== -1) {
                docs[idx] = { ...docs[idx], ...docData };
              }
            } else if (change.type === 'removed') {
              if (idx !== -1) {
                docs.splice(idx, 1);
              }
            }

            // Keep legacy alias pointing to the same array
            window.documents = docs;

            // Always re-render after merge
            if (typeof window.applyFiltersAndSearch === 'function') {
              try {
                window.applyFiltersAndSearch();
              } catch (_) {
                // ignore
              }
            }
          };

        // For added/modified docs, call server to enrich with overdue + file + history
          if (change.type === 'added' || change.type === 'modified') {
            fetch(`tracking.php?action=doc_detail&id=${encodeURIComponent(documentId)}`, { cache: 'no-store' })
              .then(r => r.json())
              .then(payload => {
                if (payload && payload.success && payload.doc) {
                  mergeIntoArray(payload.doc);
                } else {
                  mergeIntoArray(mapped);
                }
              })
              .catch(() => {
                mergeIntoArray(mapped);
              });
          } else {
            // removed
            mergeIntoArray(mapped);
          }
        });

        console.log('[Firestore] documents length after update =', Array.isArray(window.trackingDocuments) ? window.trackingDocuments.length : 'n/a');

        // Re-run existing filtering + rendering logic, if available
        if (typeof window.applyFiltersAndSearch === 'function') {
          try {
            window.applyFiltersAndSearch();
          } catch (e) {
            console.error('Error applying filters after Firestore update:', e);
          }
        }
      }, (err) => {
        console.error('[Firestore] onSnapshot error:', err);
      });
    }

    // Start after full page init
    window.addEventListener('load', startRealtimeTrackingListener);
  </script>
  <script>
    // Smooth fade-in on load and fade-out on pagination click
    document.addEventListener('DOMContentLoaded', function(){
      document.body.classList.add('page-ready');
      document.querySelectorAll('.pagination-controls a.pagination-button').forEach(a => {
        if (a.classList.contains('disabled') || a.getAttribute('href') === '#') return;
        a.addEventListener('click', function(e){
          // allow normal navigation but add a quick fade-out for perceived smoothness
          document.body.classList.remove('page-ready');
        });
      });
    });
    // The 'documents' array can be initialized from the PHP-provided data,
    // especially if you want client-side sorting/filtering/pagination to work
    // without re-fetching from the server on every action.
    const documents = <?php echo json_encode($documents); ?>; // Pass PHP data to JavaScript
    // Expose to other scripts (like the Firebase module) via clear globals
    window.trackingDocuments = documents;
    window.documents = documents; // legacy helpers (viewDocument, etc.) read from this

    // Department scope for client-side Firestore listener filtering
    window.__trackingIsAdmin = <?php echo $__isAdmin ? 'true' : 'false'; ?>;
    window.__trackingUserDept = <?php echo json_encode(!empty($_SESSION['user_department']) ? strtoupper(trim($_SESSION['user_department'])) : ''); ?>;

    let currentSortColumn = null;
    let currentSortDirection = 'asc'; // 'asc' or 'desc'
    let filteredDocuments = []; // To store documents after filtering/searching for pagination
    let currentPage = 1;
    const itemsPerPage = 9999; // Server handles 5-per-page; client won't paginate further
    // View state
    const viewTableBtn = document.getElementById('viewTableBtn');
    const viewListBtn = document.getElementById('viewListBtn');
    const viewGridBtn = document.getElementById('viewGridBtn');
    const docTableContainer = document.getElementById('docTableContainer');
    const docListView = document.getElementById('docListView');
    const docGridView = document.getElementById('docGridView');
    let currentView = 'table'; // 'table' | 'list' | 'grid'
    let lastPaginatedDocs = [];
    let currentPreviewDocId = null; // Track which document's preview is currently loaded

    // Fallback: compute current page slice if lastPaginatedDocs is not yet set
    function getCurrentPageSliceFallback() {
      if (filteredDocuments && filteredDocuments.length) {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        return filteredDocuments.slice(startIndex, endIndex);
      }
      // Parse from current table DOM as a last resort
      const rows = Array.from(document.getElementById('documentTableBody').querySelectorAll('tr'));
      return rows.map(r => ({
        id: r.getAttribute('data-id'),
        type: r.cells[0]?.innerText?.trim() || '',
        current_holder: r.cells[1]?.innerText?.trim() || '',
        end_location: r.cells[2]?.innerText?.trim() || '',
        date_submitted: r.cells[3]?.innerText?.trim() || '',
        status: r.cells[4]?.innerText?.trim() || '',
        time_in_dept: r.cells[5]?.innerText?.trim() || '',
        employee_name: r.getAttribute('data-employee') || '',
        file_type_icon: 'file',
      }));
    }

    // User dropdown toggle
    const userProfile = document.getElementById('userProfile');
    const userDropdown = document.getElementById('userDropdown');
    userProfile.addEventListener('click', e => {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
      userProfile.setAttribute('aria-expanded', userDropdown.classList.contains('show'));
      notificationDropdown.classList.remove('show');
      notificationIcon.setAttribute('aria-expanded', 'false');
    });

    // Notifications handled by partials/notifications.php

    // Filter Dropdowns
    const filterBtns = document.querySelectorAll('.filter-btn');
    const filterDropdowns = document.querySelectorAll('.filter-dropdown-menu'); // Renamed to avoid conflict

    function setupFilterDropdown(filterBtnId, dropdownId) {
      const filterBtn = document.getElementById(filterBtnId);
      const dropdown = document.getElementById(dropdownId);
      const filterLabel = filterBtn.querySelector('span');
      const items = dropdown.querySelectorAll('.filter-dropdown-item');

      filterBtn.addEventListener('click', e => {
        e.stopPropagation();
        filterDropdowns.forEach(d => { if (d !== dropdown) d.classList.remove('show'); });
        filterBtns.forEach(btn => { if (btn !== filterBtn) btn.classList.remove('open'); });
        dropdown.classList.toggle('show');
        filterBtn.classList.toggle('open');
        filterBtn.setAttribute('aria-expanded', dropdown.classList.contains('show'));
      });

      items.forEach(item => {
        item.addEventListener('click', () => {
          // Use fdi-label text if available, otherwise data-value
          const labelEl = item.querySelector('.fdi-label');
          filterLabel.textContent = labelEl ? labelEl.textContent : (item.dataset.value || item.textContent);
          items.forEach(i => i.classList.remove('selected'));
          item.classList.add('selected');
          dropdown.classList.remove('show');
          filterBtn.classList.remove('open');
          filterBtn.setAttribute('aria-expanded', 'false');
          // Update chips + URL (no page refresh — user clicks Apply to navigate)
          renderFilterChips();
          persistFiltersToUrl();
          updateActiveFiltersCount();
        });
      });
    }

    setupFilterDropdown('documentTypeFilterBtn', 'documentTypeDropdown');
    setupFilterDropdown('statusFilterBtn', 'statusDropdown');
    setupFilterDropdown('departmentFilterBtn', 'departmentDropdown');
    setupFilterDropdown('dateRangeFilterBtn', 'dateRangeDropdown');

    // Setup for date range filter
    // Close all dropdowns when clicking outside
    document.addEventListener('click', () => {
      userDropdown.classList.remove('show');
      userProfile.setAttribute('aria-expanded', 'false');
      notificationDropdown.classList.remove('show');
      notificationIcon.setAttribute('aria-expanded', 'false');
      filterDropdowns.forEach(d => d.classList.remove('show'));
      filterBtns.forEach(btn => btn.classList.remove('open'));
      filterBtns.forEach(btn => btn.setAttribute('aria-expanded', 'false')); // ARIA
    });

    // Prevent dropdown clicks from closing dropdowns
    userDropdown.addEventListener('click', e => e.stopPropagation());
    notificationDropdown.addEventListener('click', e => e.stopPropagation()); // FIX: Added stopPropagation for notification dropdown
    filterDropdowns.forEach(d => {
      d.addEventListener('click', e => e.stopPropagation());
    });

    // Initialize flatpickr date range picker
    flatpickr("#dateRange", {
      mode: "range",
      dateFormat: "Y-m-d",
      onClose: function(selectedDates, dateStr, instance) {
        const dateRangeFilterBtn = document.getElementById('dateRangeFilterBtn');
        const filterLabel = dateRangeFilterBtn.querySelector('span');
        const customOption = document.querySelector('#dateRangeDropdown .filter-dropdown-item[data-value="Custom"]');
        const allDatesOption = document.querySelector('#dateRangeDropdown .filter-dropdown-item[data-value="All Dates"]');

        document.querySelectorAll('#dateRangeDropdown .filter-dropdown-item').forEach(item => {
            item.classList.remove('selected');
        });

        if (selectedDates.length >= 1) {
          filterLabel.textContent = dateStr;
          customOption.classList.add('selected');
        } else {
            filterLabel.textContent = 'Date Range';
            allDatesOption.classList.add('selected');
        }
        // Update chips + URL only (no page refresh — user clicks Apply)
        renderFilterChips();
        persistFiltersToUrl();
        updateActiveFiltersCount();
      }
    });

    const routingHistoryModal = document.getElementById('routingHistoryModal');
    const routingHistoryContent = document.getElementById('routeHistoryContent');
    const documentTableBody = document.getElementById('documentTableBody');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const pageInfoSpan = document.getElementById('pageInfo');

    // View Document button inside details modal
    const detailViewDocumentBtn = document.getElementById('detailViewDocumentBtn');

    // New modal elements for view document details
    const viewDocumentDetailModal = document.getElementById('viewDocumentDetailModal');
    const detailDocumentName = document.getElementById('detailDocumentName');
    const detailDocTypeTitle = document.getElementById('detailDocTypeTitle');
    const detailFileTypeIcon = document.getElementById('detailFileTypeIcon');
    const detailEmployeeName = document.getElementById('detailEmployeeName');
    const detailDateSubmitted = document.getElementById('detailDateSubmitted');
    const detailCurrentHolder = document.getElementById('detailCurrentHolder');
    const detailEndLocation = document.getElementById('detailEndLocation');
    const detailStatusPill = document.getElementById('detailStatusPill');
    const detailActivityLog = document.getElementById('detailActivityLog');
    const detailFileType = document.getElementById('detailFileType'); // New element for file type
    const detailOcrSection = document.getElementById('detailOcrSection');
    const detailOcrContent = document.getElementById('detailOcrContent');

    // Confirmation Modal Elements
    const confirmActionModal = document.getElementById('confirmActionModal');
    const closeConfirmModalBtn = document.getElementById('closeConfirmModalBtn');
    const confirmModalTitle = document.getElementById('confirmModalTitle');
    const confirmModalMessage = document.getElementById('confirmModalMessage');
    const cancelConfirmBtn = document.getElementById('cancelConfirmBtn');
    const proceedConfirmBtn = document.getElementById('proceedConfirmBtn');

    let currentConfirmAction = null; // To store the action to be performed after confirmation


    function getFileTypeIcon(fileType) {
      const ft = (fileType || 'file').toString().toLowerCase();
      switch (ft) {
        case 'pdf': return 'fas fa-file-pdf pdf';
        case 'doc':
        case 'docx': return 'fas fa-file-word doc';
        case 'xls':
        case 'xlsx': return 'fas fa-file-excel xls';
        case 'img':
        case 'png':
        case 'jpg':
        case 'jpeg': return 'fas fa-file-image img';
        case 'txt': return 'fas fa-file-alt txt';
        default: return 'fas fa-file';
      }
    }
    
    // Get icon and color for document type (not file type)
    function getDocumentTypeIcon(docType) {
      const type = (docType || '').toString().toLowerCase();
      let icon = 'fas fa-file-alt';
      let color = '#6B7280'; // default gray
      
      switch (type) {
        case 'payroll':
          icon = 'fas fa-money-bill-wave';
          color = '#10B981'; // green
          break;
        case 'memo':
          icon = 'fas fa-sticky-note';
          color = '#F59E0B'; // amber
          break;
        case 'travel order':
          icon = 'fas fa-plane-departure';
          color = '#EF4444'; // red
          break;
        case 'activity design':
          icon = 'fas fa-calendar-check';
          color = '#8B5CF6'; // purple
          break;
        case 'purchase request':
          icon = 'fas fa-shopping-cart';
          color = '#3B82F6'; // blue
          break;
        case 'purchase order':
          icon = 'fas fa-file-invoice-dollar';
          color = '#06B6D4'; // cyan
          break;
        case 'advisories':
          icon = 'fas fa-exclamation-triangle';
          color = '#F97316'; // orange
          break;
        case 'announcement':
          icon = 'fas fa-bullhorn';
          color = '#EF4444'; // red
          break;
      }
      
      return { icon, color };
    }

    function getStatusPillClass(status) {
      const s = (status || '').toString().toLowerCase();
      switch (s) {
        case 'pending': return 'status-pill status-pending pulse';
        case 'returned': return 'status-pill status-returned';
        case 'completed': return 'status-pill status-completed';
        case 'in review': return 'status-pill status-review pulse';
        case 'approved': return 'status-pill status-approved';
        case 'archived': return 'status-pill status-returned'; // display as Returned per requirement
        case 'rejected': return 'status-pill status-rejected';
        default: return 'status-pill';
      }
    }

    function getOverduePillClass(state) {
      switch ((state || '').toLowerCase()) {
        case 'late': return 'overdue-pill overdue-late';
        case 'warn': return 'overdue-pill overdue-warn';
        case 'cleared': return 'overdue-pill overdue-cleared';
        case 'ok': return 'overdue-pill overdue-ok';
        case 'na':
        default:
          return 'overdue-pill overdue-na';
      }
    }

    function formatDepartmentHolderJS(department, holder) {
      const h = (holder || '').toString().trim();
      if (h) return h.toUpperCase();
      const dept = (department || '').toString().trim();
      return dept ? dept.toUpperCase() : '—';
    }

    function getDocTypeChipClassJS(docType) {
      const t = (docType || '').toString().trim().toLowerCase();
      const map = {memo:'chip-memo',payroll:'chip-payroll',leave:'chip-leave',advisory:'chip-advisory',advisories:'chip-advisories',announcement:'chip-announcement',appointment:'chip-appointment',order:'chip-order'};
      return 'doc-type-chip ' + (map[t] || 'chip-default');
    }

    function formatFileSize(bytes) {
      if (!bytes || bytes === '0') return 'Unknown';
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(1024));
      return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
    }

    function normalizeDocType(docType) {
      const s = (docType || '').toString().trim();
      // Strip mobile batch suffix like "Memo (File 1 of 3)"
      return s.replace(/\s*\(\s*file\s*\d+\s*of\s*\d+\s*\)\s*$/i, '').trim();
    }

    function isGroupableDocType(docType) {
      const t = normalizeDocType(docType).toLowerCase();
      return t === 'memo' || t === 'announcement';
    }

    function groupKeyForDoc(doc) {
      const ds = (doc?.date_submitted || '').toString().trim();
      const hash = (doc?.doc_hash || '').toString().trim();
      if (hash) return 'hash:' + hash + (ds ? '|date:' + ds : '');
      const path = (doc?.file_path || '').toString().trim();
      if (path) return 'path:' + path + (ds ? '|date:' + ds : '');
      const mobile = (doc?.mobile_timestamp || '').toString().trim();
      if (mobile) return 'mobile:' + mobile + (ds ? '|date:' + ds : '');
      return 'id:' + String(doc?.id ?? '');
    }

    function summarizeGroupStatus(items) {
      const statuses = items
        .map(i => (i?.status || '').toString().trim())
        .filter(Boolean);
      const uniq = Array.from(new Set(statuses));
      if (uniq.length === 0) return 'Pending';
      if (uniq.length === 1) return uniq[0];
      // Prefer a meaningful single label if mixed
      const lower = new Set(uniq.map(s => s.toLowerCase()));
      if (lower.has('in review')) return 'In Review';
      if (lower.has('pending')) return 'Pending';
      return 'Mixed';
    }

    function groupDocsForMultiUpload(docs) {
      const out = [];
      const map = new Map();
      for (const doc of (docs || [])) {
        const displayType = normalizeDocType(doc?.type);
        if (!isGroupableDocType(displayType)) {
          out.push(doc);
          continue;
        }
        const key = displayType.toLowerCase() + '|' + groupKeyForDoc(doc);
        if (!map.has(key)) {
          const group = {
            __group: true,
            key,
            type: displayType,
            id: doc?.id,
            employee_name: doc?.employee_name,
            date_submitted: doc?.date_submitted,
            file_type_icon: doc?.file_type_icon,
            file_path: doc?.file_path,
            doc_hash: doc?.doc_hash,
            mobile_timestamp: doc?.mobile_timestamp,
            items: [],
          };
          map.set(key, group);
          out.push(group);
        }
        map.get(key).items.push(doc);
      }
      // Replace 1-item groups with the original item to avoid unnecessary dropdowns
      return out.flatMap(x => (x && x.__group && x.items.length === 1) ? [x.items[0]] : [x]);
    }

    function renderDocuments(docsToRender) {
      const displayStatus = (s) => {
        const norm = (s || '').toString().toLowerCase();
        return (norm === 'archived') ? 'Returned' : (s || '');
      };
      documentTableBody.innerHTML = ''; // Clear existing rows
      // Group memo/announcement multi-department uploads into a single main row with a dropdown subtable.
      filteredDocuments = groupDocsForMultiUpload(docsToRender); // Update filteredDocuments for pagination
      const totalPages = Math.ceil(filteredDocuments.length / itemsPerPage);

      // Display skeleton loader while rendering (simulated for static data)
      if (filteredDocuments.length === 0) {
        documentTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px; color: var(--text-light);"><i class="fas fa-folder-open" style="margin-right: 10px; font-size: 1.2em;"></i>No documents found matching your criteria.</td></tr>`;
        if (pageInfoSpan && pageInfoSpan.dataset.serverside !== '1') pageInfoSpan.textContent = `Page 0 of 0`;
        if (prevPageBtn) prevPageBtn.disabled = true;
        if (nextPageBtn) nextPageBtn.disabled = true;
        return;
      }

      // Simulate loading for a brief moment
      documentTableBody.innerHTML = `
        <tr><td colspan="7">
          <div style="padding: 20px; text-align: center;">
            <div class="skeleton-row" style="width: 90%;"></div>
            <div class="skeleton-row" style="width: 80%;"></div>
            <div class="skeleton-row" style="width: 95%;"></div>
            <div class="skeleton-row" style="width: 70%;"></div>
          </div>
        </td></tr>
      `;

      setTimeout(() => {
        documentTableBody.innerHTML = ''; // Clear skeleton after timeout
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        const paginatedDocs = filteredDocuments.slice(startIndex, endIndex);
        // For list/grid views, use a representative doc for grouped entries.
        lastPaginatedDocs = paginatedDocs.map(d => {
          if (d && d.__group) {
            const items = Array.isArray(d.items) ? d.items : [];
            const first = items[0] || {};
            return {
              ...first,
              type: d.type || normalizeDocType(first.type),
              status: summarizeGroupStatus(items),
              department: 'Multiple',
              current_holder: 'Multiple',
              end_location: `Multiple (${items.length})`,
            };
          }
          return d;
        });

        paginatedDocs.forEach((docOrGroup, idx) => {
          // Grouped multi-department memo/announcement row
          if (docOrGroup && docOrGroup.__group) {
            const group = docOrGroup;
            const items = Array.isArray(group.items) ? group.items : [];
            const first = items[0] || {};
            const status = summarizeGroupStatus(items);
            const isMobileDocument = items.some(d => d && d.mobile_timestamp);
            const mobileIndicator = isMobileDocument ? '<span class="mobile-badge" title="Uploaded from Mobile App"><i class="fas fa-mobile-alt"></i></span>' : '';
            const displayType = group.type || normalizeDocType(first.type);
            const normalizedType = normalizeDocType(displayType).toLowerCase();
            const isAnnouncement = normalizedType === 'announcement';
            const allCompleted = items.length > 0 && items.every(d => ((d?.status || '').toString().trim().toLowerCase() === 'completed'));
            const archiveDisabled = (status === 'Archived' || status === 'Rejected') || (isAnnouncement && !allCompleted);
            const archiveTitle = (isAnnouncement && !allCompleted)
              ? 'Archive is available once all departments are Completed'
              : 'Archive';
            const archiveDocId = first.id || group.id;
            const safeDisplayType = String(displayType).replace(/'/g, "\\'");
            const archiveOnClick = (isAnnouncement && items.length > 1)
              ? `archiveAnnouncementGroupConfirm('${archiveDocId}', '${safeDisplayType}')`
              : `archiveDocumentConfirm('${archiveDocId}', '${safeDisplayType}')`;
            const groupRow = document.createElement('tr');
            groupRow.setAttribute('data-id', String(group.id ?? first.id ?? ''));
            groupRow.setAttribute('data-type', displayType);
            groupRow.setAttribute('data-status', status);
            groupRow.setAttribute('data-group', group.key);
            groupRow.style.opacity = '0';
            groupRow.style.transform = 'translateY(10px)';
            groupRow.style.transition = 'opacity 0.3s ease, transform 0.3s ease';

            const deptLabel = items.length === 1 ? (first.end_location || '—') : `Multiple (${items.length})`;
            groupRow.innerHTML = `
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <i class="${getFileTypeIcon(first.file_type_icon)} file-type-icon"></i>
                  <span>${displayType}</span>
                  ${mobileIndicator}
                </div>
              </td>
              <td>${items.length > 1 ? 'Multiple' : formatDepartmentHolderJS(first.department, first.current_holder)}</td>
              <td>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                  <span>${deptLabel}</span>
                  ${items.length > 1 ? `<button type="button" class="dept-toggle-btn" data-group="${group.key}" aria-expanded="false" aria-label="Show departments">
                    Departments <span style="opacity:.7;">(${items.length})</span>
                    <i class="fas fa-chevron-down"></i>
                  </button>` : ''}
                </div>
              </td>
              <td>${first.date_submitted || group.date_submitted || ''}</td>
              <td><span class="${getStatusPillClass(status)}">${displayStatus(status)}</span></td>
              <td><span class="${getOverduePillClass('na')}">—</span></td>
              <td>
                <div class="action-buttons-group">
                  <button class="action-button info" onclick="viewDocumentInfo('${first.id || group.id}')">
                    <i class="fas fa-info-circle"></i> Info
                  </button>
                  <button class="action-button timeline" onclick="viewDocumentTimeline('${first.id || group.id}')">
                    <i class="fas fa-history"></i> Timeline
                  </button>
                  <button class="action-button archive" title="${archiveTitle}" onclick="${archiveOnClick}" ${archiveDisabled ? 'disabled' : ''}>
                    <i class="fas fa-archive"></i> Archive
                  </button>
                </div>
              </td>
            `;
            documentTableBody.appendChild(groupRow);

            if (items.length > 1) {
              const subRow = document.createElement('tr');
              subRow.className = 'dept-subrow';
              subRow.setAttribute('data-group', group.key);
              subRow.style.display = 'none';
              const subBody = items.map(it => {
                const itType = normalizeDocType(it.type);
                const itIsAnnouncement = normalizeDocType(it.type).toLowerCase() === 'announcement';
                return `
                  <tr>
                    <td>${formatDepartmentHolderJS(it.department, it.current_holder)}</td>
                    <td>${(it.end_location || '').toString()}</td>
                    <td>${(it.date_submitted || '').toString()}</td>
                    <td><span class="${getStatusPillClass(it.status)}">${(it.status || '').toString()}</span></td>
                    <td><span class="${getOverduePillClass(it.overdue_state)}" title="${it.overdue_full_label || ''}">${it.overdue_label || '—'}</span></td>
                    <td>
                      <div class="action-buttons-group">
                        <button class="action-button info" onclick="viewDocumentInfo('${it.id}')">
                          <i class="fas fa-info-circle"></i> Info
                        </button>
                        <button class="action-button timeline" onclick="viewDocumentTimeline('${it.id}')">
                          <i class="fas fa-history"></i> Timeline
                        </button>
                        ${itIsAnnouncement ? '' : `
                        <button class="action-button archive" onclick="archiveDocumentConfirm('${it.id}', '${itType}')" ${(it.status === 'Archived' || it.status === 'Rejected') ? 'disabled' : ''}>
                          <i class="fas fa-archive"></i> Archive
                        </button>
                        `}
                      </div>
                    </td>
                  </tr>`;
              }).join('');

              subRow.innerHTML = `
                <td colspan="7">
                  <div class="dept-subpanel">
                    <table class="dept-subtable" aria-label="Departments">
                      <thead>
                        <tr>
                          <th>Current Holder</th>
                          <th>End Location</th>
                          <th>Date Submitted</th>
                          <th>Status</th>
                          <th>Time in Dept</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        ${subBody}
                      </tbody>
                    </table>
                  </div>
                </td>`;
              documentTableBody.appendChild(subRow);
            }

            setTimeout(() => {
              groupRow.style.opacity = '1';
              groupRow.style.transform = 'translateY(0)';
            }, idx * 30);
            return;
          }

          // Standard single row
          const doc = docOrGroup;
          const displayType = normalizeDocType(doc.type);
          const row = document.createElement('tr');
          row.setAttribute('data-id', doc.id); // Set data-id for easy lookup
          row.setAttribute('data-type', displayType);
          row.setAttribute('data-status', doc.status);
          row.style.opacity = '0';
          row.style.transform = 'translateY(10px)';
          row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
          // Check if document is from mobile app
          const isMobileDocument = doc.mobile_timestamp && doc.mobile_timestamp !== null;
          const mobileIndicator = isMobileDocument ? '<span class="mobile-badge" title="Uploaded from Mobile App"><i class="fas fa-mobile-alt"></i></span>' : '';

          row.innerHTML = `
            <td>
              <span class="${getDocTypeChipClassJS(displayType)}">
                <i class="${getFileTypeIcon(doc.file_type_icon)}"></i>
                ${displayType}
                ${mobileIndicator}
              </span>
            </td>
            <td>${formatDepartmentHolderJS(doc.department, doc.current_holder)}</td>
            <td>${doc.end_location}</td>
            <td>${doc.date_submitted}</td>
            <td><span class="${getStatusPillClass(doc.status)}">${doc.status}</span></td>
            <td>
              <span class="${getOverduePillClass(doc.overdue_state)}" title="${doc.overdue_full_label || ''}">${doc.overdue_label || '—'}</span>
            </td>
            <td>
              <div class="action-buttons-group">
                <button class="action-button info" title="View Info" onclick="viewDocumentInfo('${doc.id}')">
                  <i class="fas fa-info-circle"></i> Info
                </button>
                <button class="action-button timeline" title="View Timeline" onclick="viewDocumentTimeline('${doc.id}')">
                  <i class="fas fa-history"></i> Timeline
                </button>
                <button class="action-button archive" title="Archive" onclick="archiveDocumentConfirm('${doc.id}', '${displayType}')"
                  ${(doc.status === 'Archived' || doc.status === 'Rejected') ? 'disabled' : ''}>
                  <i class="fas fa-archive"></i> Archive
                </button>
              </div>
            </td>
          `;
          documentTableBody.appendChild(row);
          // Trigger fade-in animation with stagger
          setTimeout(() => {
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
          }, idx * 30); // 30ms stagger per row
        });

        // Update pagination controls (client-side only if not server-managed)
        if (pageInfoSpan && pageInfoSpan.dataset.serverside !== '1') {
          pageInfoSpan.textContent = `Page ${currentPage} of ${totalPages}`;
          if (prevPageBtn) prevPageBtn.disabled = currentPage === 1;
          if (nextPageBtn) nextPageBtn.disabled = currentPage === totalPages || totalPages === 0;
        }

        // If current view is not table, render alternative view now
        if (currentView === 'list') {
          renderListView(lastPaginatedDocs);
        } else if (currentView === 'grid') {
          renderGridView(lastPaginatedDocs);
        } else {
          showTableView();
        }
      }, 300); // Simulate network delay
    }

    // Toggle grouped department subtable rows
    documentTableBody.addEventListener('click', (e) => {
      const btn = e.target.closest('.dept-toggle-btn');
      if (!btn) return;
      const groupKey = btn.getAttribute('data-group');
      const mainRow = btn.closest('tr');
      let subRow = mainRow ? mainRow.nextElementSibling : null;
      if (!subRow || !subRow.classList.contains('dept-subrow') || subRow.getAttribute('data-group') !== groupKey) {
        subRow = documentTableBody.querySelector(`tr.dept-subrow[data-group="${groupKey.replace(/\\/g, '\\\\').replace(/\"/g, '\\"')}"]`);
      }
      if (!subRow) return;
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      subRow.style.display = expanded ? 'none' : '';
    });

    function showTableView() {
      currentView = 'table';
      docTableContainer.style.display = 'block';
      docListView.style.display = 'none';
      docGridView.style.display = 'none';
      viewTableBtn.setAttribute('aria-pressed', 'true');
      viewListBtn.setAttribute('aria-pressed', 'false');
      viewGridBtn.setAttribute('aria-pressed', 'false');
    }

    function renderListView(list) {
      currentView = 'list';
      docListView.innerHTML = '';
      
      if (list.length === 0) {
        docListView.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-light);">No documents to display</div>';
      } else {
        list.forEach(d => {
          const row = document.createElement('div');
          row.className = 'doc-list-item';
          row.dataset.id = String(d.id);
          row.style.cssText = 'background:#fff;border:1px solid var(--border);border-radius:8px;padding:15px;margin-bottom:12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);transition:all 0.2s ease;';
          row.onmouseenter = function() { this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)'; this.style.transform = 'translateY(-2px)'; };
          row.onmouseleave = function() { this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.05)'; this.style.transform = 'translateY(0)'; };
          
          const isMobile = d.mobile_timestamp && d.mobile_timestamp !== null;
          const mobileIndicator = isMobile ? '<i class="fas fa-mobile-alt" style="color:#4CAF50;margin-left:6px;" title="Mobile Upload"></i>' : '';
          const docIcon = getDocumentTypeIcon(d.type);
          
          row.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:15px;">
              <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;">
                <i class="${docIcon.icon} file-type-icon" style="font-size:24px;color:${docIcon.color};"></i>
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:600;color:var(--text-dark);margin-bottom:4px;">${d.type}${mobileIndicator}</div>
                  <div style="color:var(--text-light);font-size:13px;">${d.employee_name} • ${d.department || d.current_holder} • ${d.date_submitted}</div>
                  <div style="margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span class="${getStatusPillClass(d.status)}" style="flex-shrink:0;">${d.status}</span>
                    <span class="${getOverduePillClass(d.overdue_state)}" title="${d.overdue_full_label || ''}">${d.overdue_label || '—'}</span>
                  </div>
                </div>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0;">
                <button class="action-button info" onclick="viewDocumentInfo('${d.id}')" title="Document Info"><i class="fas fa-info-circle"></i></button>
                <button class="action-button timeline" onclick="viewDocumentTimeline('${d.id}')" title="Document Timeline"><i class="fas fa-history"></i></button>
                <button class="action-button archive" onclick="archiveDocumentConfirm('${d.id}', '${d.type}')" ${(d.status === 'Archived' || d.status === 'Rejected') ? 'disabled' : ''} title="Archive Document"><i class="fas fa-archive"></i></button>
              </div>
            </div>`;
          docListView.appendChild(row);
        });
      }
      docTableContainer.style.display = 'none';
      docGridView.style.display = 'none';
      docListView.style.display = 'block';
      viewTableBtn.setAttribute('aria-pressed', 'false');
      viewListBtn.setAttribute('aria-pressed', 'true');
      viewGridBtn.setAttribute('aria-pressed', 'false');
    }

    function renderGridView(list) {
      currentView = 'grid';
      docGridView.innerHTML = '';
      
      if (list.length === 0) {
        docGridView.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-light);">No documents to display</div>';
      } else {
        list.forEach(d => {
          const card = document.createElement('div');
          card.className = 'doc-grid-card';
          card.dataset.id = String(d.id);
          card.style.cssText = 'background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px;box-shadow:0 2px 6px rgba(0,0,0,0.06);transition:all 0.3s ease;display:flex;flex-direction:column;gap:12px;';
          card.onmouseenter = function() { this.style.boxShadow = '0 6px 12px rgba(0,0,0,0.12)'; this.style.transform = 'translateY(-4px)'; };
          card.onmouseleave = function() { this.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)'; this.style.transform = 'translateY(0)'; };
          
          const isMobile = d.mobile_timestamp && d.mobile_timestamp !== null;
          const mobileIndicator = isMobile ? '<i class="fas fa-mobile-alt" style="color:#4CAF50;margin-left:6px;" title="Mobile Upload"></i>' : '';
          const docIcon = getDocumentTypeIcon(d.type);
          
          card.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;padding-bottom:12px;border-bottom:1px solid var(--border);">
              <div style="width:50px;height:50px;border-radius:50%;background:${docIcon.color};color:white;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                <i class="${docIcon.icon}"></i>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:var(--text-dark);margin-bottom:4px;font-size:15px;">${d.type}${mobileIndicator}</div>
                <div style="font-size:13px;color:var(--text-light);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${d.employee_name}</div>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <div style="display:flex;justify-content:space-between;font-size:13px;">
                <span style="color:var(--text-light);font-weight:500;">Status:</span>
                <span class="${getStatusPillClass(d.status)}">${d.status}</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:13px;">
                <span style="color:var(--text-light);font-weight:500;">Overdue:</span>
                <span class="${getOverduePillClass(d.overdue_state)}" title="${d.overdue_full_label || ''}">${d.overdue_label || '—'}</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:13px;">
                <span style="color:var(--text-light);font-weight:500;">Department:</span>
                <span style="color:var(--text-dark);font-weight:600;">${d.department || d.current_holder}</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:13px;">
                <span style="color:var(--text-light);font-weight:500;">Date:</span>
                <span style="color:var(--text-dark);font-weight:600;">${d.date_submitted}</span>
              </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:8px;border-top:1px solid var(--border);">
              <button class="action-button info" onclick="viewDocumentInfo('${d.id}')" title="Document Info"><i class="fas fa-info-circle"></i></button>
              <button class="action-button timeline" onclick="viewDocumentTimeline('${d.id}')" title="Document Timeline"><i class="fas fa-history"></i></button>
              <button class="action-button archive" onclick="archiveDocumentConfirm('${d.id}', '${d.type}')" ${(d.status === 'Archived' || d.status === 'Rejected') ? 'disabled' : ''} title="Archive Document"><i class="fas fa-archive"></i></button>
            </div>`;
          docGridView.appendChild(card);
        });
      }
      docTableContainer.style.display = 'none';
      docListView.style.display = 'none';
      docGridView.style.display = 'grid';
      viewTableBtn.setAttribute('aria-pressed', 'false');
      viewListBtn.setAttribute('aria-pressed', 'false');
      viewGridBtn.setAttribute('aria-pressed', 'true');
    }

    // Bind view buttons
    viewTableBtn.addEventListener('click', () => showTableView());
    viewListBtn.addEventListener('click', () => {
      const slice = (lastPaginatedDocs && lastPaginatedDocs.length) ? lastPaginatedDocs : getCurrentPageSliceFallback();
      renderListView(slice);
    });
    viewGridBtn.addEventListener('click', () => {
      const slice = (lastPaginatedDocs && lastPaginatedDocs.length) ? lastPaginatedDocs : getCurrentPageSliceFallback();
      renderGridView(slice);
    });

    // --- Modals ---
    function openModal(modalId) {
      document.getElementById(modalId).classList.add('show');
      document.getElementById(modalId).setAttribute('aria-hidden', 'false');
      // Collapsible sections in View Details modal (ensure they are collapsed on open)
      document.querySelectorAll(`#${modalId} .modal-section h4.collapsible`).forEach(header => {
        header.classList.add('collapsed');
        header.nextElementSibling.classList.add('collapsed');
      });
    }

    function closeModal(modalId) {
      document.getElementById(modalId).classList.remove('show');
      document.getElementById(modalId).setAttribute('aria-hidden', 'true');
    }

    // Click outside modal to close
    window.addEventListener('click', e => {
      if (e.target.classList.contains('modal')) {
        e.target.classList.remove('show');
      }
    });

    const detailDocumentPreviewWrapper = document.getElementById('detailDocumentPreviewWrapper');
    const detailDocumentPreviewFrame = document.getElementById('detailDocumentPreviewFrame');

    function resizePreviewFrameToContent() {
        if (!detailDocumentPreviewFrame) return;
        try {
            const doc = detailDocumentPreviewFrame.contentWindow?.document;
            if (!doc) return;
            const body = doc.body;
            const html = doc.documentElement;
            
            // Reset height to allow content to dictate size
            detailDocumentPreviewFrame.style.height = ''; 

            // Check for specific content types and apply appropriate scaling
            const img = body.querySelector('img');
            if (img) {
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                img.style.display = 'block';
                img.style.margin = '0 auto';
            }

            const pdfViewer = body.querySelector('embed[type="application/pdf"]');
            if (pdfViewer) {
                pdfViewer.style.width = '100%';
                pdfViewer.style.height = '100%';
            }

            // Calculate the height based on the content
            const height = Math.max(
                body ? body.scrollHeight : 0,
                html ? html.scrollHeight : 0
            );
            
            // Set a maximum height to prevent excessively tall iframes
            const maxHeight = window.innerHeight * 0.7; // 70% of viewport height

            if (height > 0 && height < 10000) { // Arbitrary upper limit to prevent runaway heights
                detailDocumentPreviewFrame.style.height = Math.min(height, maxHeight) + 'px';
            } else if (pdfViewer) {
                // If it's a PDF and height calculation is tricky, ensure it takes available space
                detailDocumentPreviewFrame.style.height = maxHeight + 'px';
            }

        } catch (e) {
            // ignore cross-origin or other access errors
            console.warn("Error resizing iframe: ", e);
        }
    }

    async function loadDocumentPreviewInTracking(fileUrl, doc) {
        if (!detailDocumentPreviewWrapper || !detailDocumentPreviewFrame) return;
        
        detailDocumentPreviewWrapper.style.display = 'block';
        const frame = document.getElementById('detailDocumentPreviewFrame');
        if (!frame) return;

        // For iframes, we need to set src, not innerHTML
        // Create a blob URL for the content and set it as iframe src
        
        try {
            const previewUrl = fileUrl + (fileUrl.includes('?') ? '&' : '?') + '_=' + Date.now();
            const resp = await fetch(previewUrl, { credentials: 'include' });
            if (!resp.ok) {
                const errText = await resp.text().catch(() => '');
                throw new Error(errText || `Server responded with ${resp.status}`);
            }
            const contentType = (resp.headers.get('Content-Type') || '').toLowerCase();
            const blob = await resp.blob();

            // Get file extension from doc.file_ext or fallback to file_type_icon
            const docExt = (doc.file_ext || doc.file_type_icon || '').toString().toLowerCase();
            const isDocx = docExt === 'docx' || contentType.includes('officedocument.wordprocessingml.document');
            
            if (isDocx && window.mammoth && blob) {
                try {
                    const arrayBuffer = await blob.arrayBuffer();
                    const result = await window.mammoth.convertToHtml({ arrayBuffer });
                    // For DOCX, create HTML blob and set as iframe src
                    const htmlBlob = new Blob([result.value], { type: 'text/html' });
                    const htmlUrl = URL.createObjectURL(htmlBlob);
                    frame.onload = () => {
                        resizePreviewFrameToContent();
                        URL.revokeObjectURL(htmlUrl); // Clean up
                    };
                    frame.src = htmlUrl;
                    return;
                } catch (mammothError) {
                    console.error('Mammoth preview failed', mammothError);
                    frame.srcdoc = `<div style="text-align:center;color:#b91c1c;padding:20px;font-family:sans-serif;">Preview failed, but you can <a href="${previewUrl}" target="_blank" style="color:#0ea5e9;">download the file</a>.</div>`;
                    return;
                }
            }

            // For other file types, create appropriate content
            if (contentType.startsWith('image/')) {
                const objectUrl = URL.createObjectURL(blob);
                frame.onload = () => {
                    resizePreviewFrameToContent();
                    URL.revokeObjectURL(objectUrl); // Clean up
                };
                frame.src = objectUrl;
            } else if (contentType.includes('pdf')) {
                const objectUrl = URL.createObjectURL(blob);
                frame.onload = () => {
                    resizePreviewFrameToContent();
                    URL.revokeObjectURL(objectUrl); // Clean up
                };
                frame.src = objectUrl;
            } else if (contentType.startsWith('text/')) {
                const text = await blob.text();
                const htmlContent = `<html><head><style>
                    body { 
                        margin: 0; 
                        padding: 12px; 
                        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
                        font-size: 14px; 
                        line-height: 1.4; 
                        background: #f8fafc; 
                        white-space: pre-wrap; 
                        word-wrap: break-word;
                    }
                </style></head><body>${escapeHtml(text)}</body></html>`;
                const textBlob = new Blob([htmlContent], { type: 'text/html' });
                const textUrl = URL.createObjectURL(textBlob);
                frame.onload = () => {
                    resizePreviewFrameToContent();
                    URL.revokeObjectURL(textUrl); // Clean up
                };
                frame.src = textUrl;
            } else {
                const safeUrl = escapeHtml(previewUrl);
                frame.srcdoc = `<div style="text-align:center;color:#475569;padding:20px;font-family:sans-serif;height:100vh;display:flex;flex-direction:column;justify-content:center;">Preview not supported for this file type.<br/><a href="${safeUrl}" target="_blank" style="color:#0ea5e9;">Open file in new tab</a></div>`;
            }
        } catch (err) {
            frame.srcdoc = `<div style="text-align:center;color:#b91c1c;padding:20px;font-family:sans-serif;height:100vh;display:flex;flex-direction:column;justify-content:center;">Unable to load preview.<br/><small>${escapeHtml(err.message || 'Unknown error')}</small></div>`;
        }
    }

    function escapeHtml(str) {
        if (str === undefined || str === null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Load document in preview container when download button is clicked
    async function loadDocumentInPreview(event, downloadUrl, docId) {
        event.preventDefault();
        
        // Find the document data
        const doc = documents.find(d => String(d.id) === String(docId));
        if (!doc) return;
        
        // Find the preview container in the same card
        const previewContainer = event.target.closest('.timeline-document-preview');
        if (!previewContainer) return;
        
        // Show loading state
        previewContainer.innerHTML = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading document...</div>';
        
        try {
            // Fetch the document content
            const response = await fetch(downloadUrl, { credentials: 'include' });
            if (!response.ok) throw new Error('Failed to load document');
            
            const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            const blob = await response.blob();
            
            // Create appropriate preview based on file type
            let previewContent = '';
            
            if (contentType.startsWith('image/')) {
                const imageUrl = URL.createObjectURL(blob);
                previewContent = `<img src="${imageUrl}" alt="Document Preview" style="max-width: 100%; height: auto; border-radius: 4px; border: 1px solid var(--border);">`;
            } else if (contentType.includes('pdf')) {
                const pdfUrl = URL.createObjectURL(blob);
                previewContent = `<iframe src="${pdfUrl}" style="width: 100%; height: 400px; border: 1px solid var(--border); border-radius: 4px;"></iframe>`;
            } else if (contentType.startsWith('text/') || doc.file_ext === 'txt') {
                const text = await blob.text();
                const escapedText = escapeHtml(text);
                previewContent = `<div style="background: #f8fafc; padding: 12px; border-radius: 4px; border: 1px solid var(--border); max-height: 400px; overflow-y: auto; white-space: pre-wrap; font-family: monospace; font-size: 12px; line-height: 1.4;">${escapedText}</div>`;
            } else {
                // For unsupported file types, show download option within container
                previewContent = `<div class="preview-placeholder">
                    <i class="fas fa-file"></i>
                    <p>Document loaded successfully</p>
                    <a href="${downloadUrl}" class="btn-download" target="_blank">
                        <i class="fas fa-external-link-alt"></i> Open in New Tab
                    </a>
                    <a href="${downloadUrl}" class="btn-download" style="margin-left: 8px;" download>
                        <i class="fas fa-download"></i> Download File
                    </a>
                </div>`;
            }
            
            // Update the preview container with the loaded content
            previewContainer.innerHTML = previewContent;
            
        } catch (error) {
            previewContainer.innerHTML = `<div class="preview-placeholder">
                <i class="fas fa-exclamation-triangle" style="color: #b91c1c;"></i>
                <p style="color: #b91c1c;">Failed to load document</p>
                <a href="${downloadUrl}" class="btn-download" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Try in New Tab
                </a>
            </div>`;
        }
    }

    // Track current preview doc for Info modal
    let currentInfoPreviewDocId = null;

    // View Document Info (displays only document information in a modal)
    function viewDocumentInfo(docId) {
        const doc = documents.find(d => String(d.id) === String(docId));
        if (!doc) return;

        // Get modal elements
        const infoModal = document.getElementById('viewDocumentInfoModal');
        const infoFileTypeIcon = document.getElementById('infoFileTypeIcon');
        const infoDocTypeTitle = document.getElementById('infoDocTypeTitle');
        const infoStatusPill = document.getElementById('infoStatusPill');
        const infoDateSubmitted = document.getElementById('infoDateSubmitted');
        const infoTimeSubmitted = document.getElementById('infoTimeSubmitted');
        const infoFileType = document.getElementById('infoFileType');
        const infoCurrentHolder = document.getElementById('infoCurrentHolder');
        const infoEndLocation = document.getElementById('infoEndLocation');
        const infoViewDocumentBtn = document.getElementById('infoViewDocumentBtn');
        const infoDocumentPreviewWrapper = document.getElementById('infoDocumentPreviewWrapper');
        const infoDocumentPreviewFrame = document.getElementById('infoDocumentPreviewFrame');
        const infoOcrContent = document.getElementById('infoOcrContent');
        const infoSaveOcrBtn = document.getElementById('infoSaveOcrBtn');

        // Reset preview area only when switching to a different document
        if (infoDocumentPreviewWrapper && infoDocumentPreviewFrame && currentInfoPreviewDocId !== String(doc.id)) {
            infoDocumentPreviewWrapper.style.display = 'none';
            infoDocumentPreviewFrame.src = '';
        }
        currentInfoPreviewDocId = String(doc.id);

        // Set file type icon
        const fileTypeIcon = getFileTypeIcon(doc.file_type_icon);
        infoFileTypeIcon.className = fileTypeIcon;
        
        // Set document title
        infoDocTypeTitle.textContent = doc.type;
        
        // Set status pill
        infoStatusPill.innerHTML = `<span class="${getStatusPillClass(doc.status)}">${doc.status}</span>`;
        
        // Set basic information
        infoDateSubmitted.textContent = doc.date_submitted || 'N/A';
        
        // Extract time from date_submitted or use time_submitted if available
        let timeSubmitted = 'N/A';
        if (doc.time_submitted) {
            timeSubmitted = doc.time_submitted;
        } else if (doc.date_submitted && doc.date_submitted.includes(' ')) {
            // If date includes time (e.g., "2025-12-27 14:30:00")
            const parts = doc.date_submitted.split(' ');
            if (parts.length > 1) {
                timeSubmitted = parts[1];
            }
        } else if (doc.created_at) {
            // Try created_at timestamp
            const createdDate = new Date(doc.created_at);
            if (!isNaN(createdDate.getTime())) {
                timeSubmitted = createdDate.toLocaleTimeString();
            }
        }
        infoTimeSubmitted.textContent = timeSubmitted;
        
        // Determine file type - use file_type_icon or extract from file_path
        let fileType = doc.file_type_icon || '';
        if (!fileType && doc.file_path) {
            const pathParts = doc.file_path.split('.');
            if (pathParts.length > 1) {
                fileType = pathParts[pathParts.length - 1];
            }
        }
        infoFileType.textContent = fileType ? fileType.toUpperCase() : 'Document';
        infoCurrentHolder.textContent = doc.current_holder || 'N/A';
        infoEndLocation.textContent = doc.end_location || 'N/A';

        if (infoOcrContent) {
          infoOcrContent.value = 'Loading OCR...';
          loadTrackingOcrIntoInfoModal(doc.id, infoOcrContent);
        }

        if (infoSaveOcrBtn && infoOcrContent) {
          infoSaveOcrBtn.disabled = false;
          infoSaveOcrBtn.onclick = async () => {
            const originalLabel = infoSaveOcrBtn.innerHTML;
            infoSaveOcrBtn.disabled = true;
            infoSaveOcrBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>&nbsp;Saving...';
            try {
              const fd = new FormData();
              fd.append('doc_id', String(doc.id));
              fd.append('ocr_text', infoOcrContent.value || '');
              const r = await fetch('tracking.php?action=save_ocr_correction', { method: 'POST', body: fd, credentials: 'include' });
              const payload = await r.json();
              if (payload && payload.success) {
                infoSaveOcrBtn.innerHTML = '<i class="fas fa-check"></i>&nbsp;Saved';
                setTimeout(() => {
                  infoSaveOcrBtn.innerHTML = originalLabel;
                  infoSaveOcrBtn.disabled = false;
                }, 900);
                return;
              }
              infoSaveOcrBtn.innerHTML = originalLabel;
              infoSaveOcrBtn.disabled = false;
              alert((payload && payload.error) ? payload.error : 'Failed to save OCR correction');
            } catch (e) {
              infoSaveOcrBtn.innerHTML = originalLabel;
              infoSaveOcrBtn.disabled = false;
              alert('Failed to save OCR correction');
            }
          };
        }

        // Configure View Document button
        infoViewDocumentBtn.style.display = 'inline-flex';
        const autoPreview = async () => {
            // Prefer server-generated URL (tracking.php already sets download.php?id=...&inline=1)
            if (doc.file_url) {
                await loadDocumentPreviewInInfo(doc.file_url, doc, infoDocumentPreviewWrapper, infoDocumentPreviewFrame);
                return;
            }

            let rawPath = (doc.file_path || '').trim();

            // Ignore Android local paths which are not accessible from the web
            if (rawPath.startsWith('/data/user/')) {
                return;
            }

            if (!rawPath) {
                return;
            }

            // Encrypted files should be previewed via server-side decrypt preview
            if (rawPath.toLowerCase().endsWith('.enc')) {
                const href = 'preview.php?id=' + encodeURIComponent(doc.id);
                if (infoDocumentPreviewWrapper && infoDocumentPreviewFrame) {
                    infoDocumentPreviewWrapper.style.display = 'block';
                    infoDocumentPreviewFrame.srcdoc = '';
                    infoDocumentPreviewFrame.src = href;
                }
                return;
            }

            // If rawPath is already an absolute URL
            if (rawPath.startsWith('http://') || rawPath.startsWith('https://')) {
                await loadDocumentPreviewInInfo(rawPath, doc, infoDocumentPreviewWrapper, infoDocumentPreviewFrame);
                return;
            }

            // Otherwise treat it as a path under the XAMPP web root
            rawPath = rawPath.replace(/^\.\//, '');
            let webPath = rawPath;
            if (!webPath.startsWith('flutter_application_7/')) {
                webPath = 'flutter_application_7/' + webPath.replace(/^\//, '');
            }
            const href = '/' + webPath;
            await loadDocumentPreviewInInfo(href, doc, infoDocumentPreviewWrapper, infoDocumentPreviewFrame);
        };

        // Always use download.php with a cache-busting timestamp so the latest file is served
        const buildFreshMainDocUrl = () => 'download.php?id=' + encodeURIComponent(doc.id) + '&inline=1&t=' + Date.now();

        // Keep the button as a download/open action — fetch live URL then open
        infoViewDocumentBtn.onclick = async () => {
          try {
            const r = await fetch('api/document_actions.php?action=get_current_doc&tracking_id=' + encodeURIComponent(doc.id) + '&_=' + Date.now(), { cache: 'no-store', credentials: 'include' });
            const d = await r.json();
            const liveUrl = (d && d.success && d.has_file && d.file_url) ? d.file_url : ('download.php?id=' + encodeURIComponent(doc.id) + '&inline=1&t=' + Date.now());
            loadDocumentAttachments(doc.id, liveUrl, doc.type || 'Main Document');
            window.open(liveUrl, '_blank');
          } catch (_) {
            const fallback = 'download.php?id=' + encodeURIComponent(doc.id) + '&inline=1&t=' + Date.now();
            window.open(fallback, '_blank');
          }
        };

        // Load comments
        loadDocumentComments(doc.id);

        // Load attachments (main document + attachments)
        loadDocumentAttachments(doc.id, buildFreshMainDocUrl(), doc.type || 'Main Document');

        // Setup refresh attachments button
        const infoRefreshAttachmentsBtn = document.getElementById('infoRefreshAttachmentsBtn');
        if (infoRefreshAttachmentsBtn) {
          infoRefreshAttachmentsBtn.onclick = () => {
            loadDocumentAttachments(doc.id, buildFreshMainDocUrl(), doc.type || 'Main Document');
          };
        }

        // Setup add comment button
        const infoAddCommentBtn = document.getElementById('infoAddCommentBtn');
        const infoNewCommentInput = document.getElementById('infoNewCommentInput');
        if (infoAddCommentBtn && infoNewCommentInput) {
          infoAddCommentBtn.onclick = async () => {
            const commentText = infoNewCommentInput.value.trim();
            if (!commentText) {
              alert('Please enter a comment');
              return;
            }
            infoAddCommentBtn.disabled = true;
            infoAddCommentBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>&nbsp;Adding...';
            try {
              const fd = new FormData();
              fd.append('action', 'add_comment');
              fd.append('tracking_id', String(doc.id));
              fd.append('comment', commentText);
              fd.append('username', (window.currentUser || '') || 'Unknown');
              fd.append('department', (window.currentDepartment || '') || 'Unknown');
              const r = await fetch('api/document_actions.php', { method: 'POST', body: fd, credentials: 'include' });
              const payload = await r.json();
              if (payload && payload.success) {
                infoNewCommentInput.value = '';
                loadDocumentComments(doc.id);
              } else {
                alert(payload.error || 'Failed to add comment');
              }
            } catch (e) {
              alert('Failed to add comment');
            }
            infoAddCommentBtn.disabled = false;
            infoAddCommentBtn.innerHTML = '<i class="fas fa-paper-plane"></i>&nbsp;Add Comment';
          };
        }

        openModal('viewDocumentInfoModal');
        // Auto-show preview as soon as the modal opens
        autoPreview();
    }

    // Current document ID for comments
    let currentInfoDocId = null;

    // Stored tab content so switching is instant
    let _docTabData = { versions: '', attachments: '' };
    let _activeDocTab = 'versions';
    let _currentCompileDocId = null;

    function _switchDocTab(tabName) {
      _activeDocTab = tabName;
      const container = document.getElementById('infoAttachmentsContainer');
      if (container) container.innerHTML = _docTabData[tabName] || '';

      // Update tab button styles
      document.querySelectorAll('#infoDocTabs .info-doc-tab').forEach(btn => {
        const t = btn.getAttribute('data-tab');
        if (t === tabName) {
          btn.style.color = '#0ea5e9';
          btn.style.borderBottom = '2px solid #0ea5e9';
        } else {
          btn.style.color = '#94a3b8';
          btn.style.borderBottom = '2px solid transparent';
        }
      });
    }

    // Wire up tab clicks (once)
    document.querySelectorAll('#infoDocTabs .info-doc-tab').forEach(btn => {
      btn.addEventListener('click', () => _switchDocTab(btn.getAttribute('data-tab')));
    });

    async function loadDocumentAttachments(docId, mainUrl = '', mainLabel = 'Main Document') {
      const container = document.getElementById('infoAttachmentsContainer');
      if (!container) return;
      _currentCompileDocId = docId;

      container.innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading documents...</div>';

      // Fetch current doc (live from DB), versions, and attachments in parallel
      let versions = [];
      let attachments = [];
      let liveMainUrl = mainUrl; // fallback
      try {
        const [curRes, verRes, attRes] = await Promise.all([
          fetch(`api/document_actions.php?action=get_current_doc&tracking_id=${encodeURIComponent(String(docId))}&_=${Date.now()}`, { cache: 'no-store', credentials: 'include' }),
          fetch(`api/document_actions.php?action=get_versions&tracking_id=${encodeURIComponent(String(docId))}&_=${Date.now()}`, { cache: 'no-store', credentials: 'include' }),
          fetch(`api/document_actions.php?action=get_attachments&tracking_id=${encodeURIComponent(String(docId))}&_=${Date.now()}`, { cache: 'no-store', credentials: 'include' })
        ]);
        try {
          const d = JSON.parse(await curRes.text());
          if (d && d.success && d.has_file && d.file_url) {
            // Use the live URL from the server (always points to the actual current file_path)
            liveMainUrl = d.file_url;
          }
        } catch (_) {}
        try { const d = JSON.parse(await verRes.text()); if (d && d.success) versions = d.versions || []; } catch (_) {}
        try { const d = JSON.parse(await attRes.text()); if (d && d.success) attachments = d.attachments || []; } catch (_) {}
      } catch (e) {
        container.innerHTML = '<div style="padding:12px;border:1px solid #fecaca;background:#fef2f2;border-radius:8px;color:#b91c1c;">Error: ' + escapeHtml(String(e.message || e)) + '</div>';
        return;
      }
      // Use the live URL for the current document
      mainUrl = liveMainUrl;

      // Update tab badge counts
      const cVer = document.getElementById('tabCountVersions');
      const cAtt = document.getElementById('tabCountAttachments');
      if (cVer) cVer.textContent = String(versions.length);
      if (cAtt) cAtt.textContent = String(attachments.length);

      // Show/hide Download All + Compile All buttons (only if there's more than 1 document total)
      const downloadAllBtn = document.getElementById('infoDownloadAllBtn');
      const compileAllBtn = document.getElementById('infoCompileAllBtn');
      const totalDocs = (mainUrl ? 1 : 0) + versions.length + attachments.length;
      if (downloadAllBtn) downloadAllBtn.style.display = totalDocs > 1 ? 'flex' : 'none';
      if (compileAllBtn) compileAllBtn.style.display = totalDocs > 1 ? '' : 'none';

      // Build the download list for the Download All panel
      const dlList = document.getElementById('downloadAllList');
      if (dlList) {
        let dlHtml = '';
        // Versions
        versions.forEach(v => {
          const vUrl = (v.file_url || '').toString();
          const vNum = v.version_number || '?';
          const vType = (v.version_type || '').toString();
          const isOrig = vType === 'original';
          const label = 'Version ' + vNum + ' (' + (isOrig ? 'Original' : 'Returned') + ')';
          const bgColor = isOrig ? '#f5f3ff' : '#fffbeb';
          const borderColor = isOrig ? '#ddd6fe' : '#fde68a';
          const iconColor = isOrig ? '#6366f1' : '#f59e0b';
          if (vUrl) {
            dlHtml += '<a href="' + encodeURI(vUrl) + '" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;text-decoration:none;color:#0f172a;background:' + bgColor + ';border:1px solid ' + borderColor + ';font-size:12px;font-weight:500;transition:background 0.15s;">' +
              '<i class="fas fa-file-pdf" style="color:' + iconColor + ';font-size:16px;"></i>' +
              '<div style="flex:1;"><div style="font-weight:600;">' + escapeHtml(label) + '</div><div style="font-size:10px;color:#64748b;">' + escapeHtml(v.created_at || '') + '</div></div>' +
              '<i class="fas fa-external-link-alt" style="color:#94a3b8;font-size:11px;"></i></a>';
          }
        });
        // Attachments
        attachments.forEach((a, idx) => {
          const fu = (a.file_url || a.fileUrl || '').toString();
          const fn = (a.file_name || a.fileName || '').toString() || ('Attachment #' + (idx + 1));
          if (fu) {
            dlHtml += '<a href="' + encodeURI(fu) + '" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;text-decoration:none;color:#0f172a;background:#faf5ff;border:1px solid #e9d5ff;font-size:12px;font-weight:500;transition:background 0.15s;">' +
              '<i class="fas fa-paperclip" style="color:#8b5cf6;font-size:16px;"></i>' +
              '<div style="flex:1;"><div style="font-weight:600;">' + escapeHtml(fn) + '</div><div style="font-size:10px;color:#64748b;">' + escapeHtml(a.created_at || a.createdAt || '') + '</div></div>' +
              '<i class="fas fa-external-link-alt" style="color:#94a3b8;font-size:11px;"></i></a>';
          }
        });
        if (!dlHtml) dlHtml = '<div style="text-align:center;padding:12px;color:#94a3b8;font-size:12px;">No files available</div>';
        dlList.innerHTML = dlHtml;
      }

      // Helper: normalize stored paths to a web-accessible URL
      function toWebUrl(fileUrl, filePath) {
        const u = (fileUrl || '').toString().trim();
        if (u) return u;
        const p = (filePath || '').toString().trim();
        if (!p) return '';
        if (p.startsWith('uploads/')) return p;
        if (p.startsWith('/uploads/')) return '.' + p;
        return p;
      }

      // Build a card HTML
      function buildCard(label, openUrl, by, dept, when, remarks, badgeText, badgeColor) {
        let card = '<div style="border:1px solid #e2e8f0;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.05);transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow=\'0 4px 12px rgba(0,0,0,0.1)\'" onmouseout="this.style.boxShadow=\'0 2px 4px rgba(0,0,0,0.05)\'">';
        card += '<div style="height:70px;background:linear-gradient(135deg,#f8fafc 0%,#e2e8f0 100%);display:flex;align-items:center;justify-content:center;position:relative;">'
          + '<i class="fas fa-file-pdf" style="font-size:28px;color:#64748b;"></i>';
        if (badgeText) {
          card += '<span style="position:absolute;top:8px;right:8px;background:' + (badgeColor || '#0ea5e9') + ';color:white;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;">' + escapeHtml(badgeText) + '</span>';
        }
        card += '</div><div style="padding:12px;">'
          + '<div style="font-weight:600;color:#0f172a;font-size:13px;word-break:break-word;line-height:1.3;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">'
          + '<i class="fas fa-file-alt" style="color:#0ea5e9;margin-right:6px;"></i>' + escapeHtml(label) + '</div>'
          + '<div style="font-size:11px;color:#64748b;margin-bottom:6px;">'
          + (by ? '<i class="fas fa-user" style="margin-right:4px;"></i>' + escapeHtml(by) : '')
          + (dept ? (by ? ' &bull; ' : '') + '<i class="fas fa-building" style="margin-right:4px;"></i>' + escapeHtml(dept) : '')
          + '</div>'
          + (when ? '<div style="font-size:10px;color:#94a3b8;margin-bottom:6px;"><i class="fas fa-clock" style="margin-right:4px;"></i>' + escapeHtml(when) + '</div>' : '')
          + (remarks ? '<div style="font-size:11px;color:#475569;background:#f8fafc;padding:6px 8px;border-radius:6px;margin-bottom:6px;"><i class="fas fa-sticky-note" style="margin-right:4px;color:#94a3b8;"></i>' + escapeHtml(remarks) + '</div>' : '')
          + '<div style="display:flex;gap:6px;">'
          + (openUrl ? '<a style="flex:1;padding:7px 12px;font-size:0.75rem;text-decoration:none;text-align:center;background:linear-gradient(135deg,#0ea5e9 0%,#0284c7 100%);color:white;border:none;border-radius:6px;font-weight:500;" href="' + encodeURI(openUrl) + '" target="_blank"><i class="fas fa-external-link-alt"></i> View</a>' : '')
          + '</div></div></div>';
        return card;
      }

      // ── Tab 1: Version History (Timeline) ──
      if (versions.length > 0) {
        let vHtml = '<div style="position:relative;padding-left:32px;">';
        // Vertical timeline line
        vHtml += '<div style="position:absolute;left:14px;top:0;bottom:0;width:2px;background:linear-gradient(to bottom,#e2e8f0,#cbd5e1);"></div>';
        versions.forEach((v, idx) => {
          const vType = (v.version_type || 'original').toString();
          const vNum = v.version_number || (idx + 1);
          const vUrl = (v.file_url || '').toString().trim();
          const vBy = (v.uploaded_by || '').toString();
          const vDept = (v.department || '').toString();
          const vWhen = (v.created_at || '').toString();
          const isOriginal = vType === 'original';
          const dotColor = isOriginal ? '#6366f1' : '#f59e0b';
          const typeLabel = isOriginal ? 'Original Upload' : 'Returned Capture';
          const typeIcon = isOriginal ? 'fa-upload' : 'fa-redo-alt';

          vHtml += '<div style="position:relative;margin-bottom:20px;">';
          // Timeline dot
          vHtml += '<div style="position:absolute;left:-25px;top:4px;width:12px;height:12px;border-radius:50%;background:' + dotColor + ';border:2px solid white;box-shadow:0 0 0 2px ' + dotColor + '40;"></div>';
          // Card
          vHtml += '<div style="background:white;border:1px solid #e2e8f0;border-radius:10px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
          vHtml += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">';
          vHtml += '<div style="display:flex;align-items:center;gap:8px;">'
            + '<span style="background:' + dotColor + ';color:white;font-size:11px;padding:3px 10px;border-radius:12px;font-weight:600;">v' + escapeHtml(String(vNum)) + '</span>'
            + '<span style="font-weight:600;font-size:13px;color:#0f172a;"><i class="fas ' + typeIcon + '" style="color:' + dotColor + ';margin-right:4px;"></i>' + escapeHtml(typeLabel) + '</span>'
            + '</div>';
          if (vUrl) {
            vHtml += '<a style="padding:5px 14px;font-size:0.75rem;text-decoration:none;background:linear-gradient(135deg,#0ea5e9 0%,#0284c7 100%);color:white;border-radius:6px;font-weight:500;" href="' + encodeURI(vUrl) + '" target="_blank"><i class="fas fa-eye"></i> View</a>';
          }
          vHtml += '</div>';
          // Meta row
          vHtml += '<div style="display:flex;flex-wrap:wrap;gap:12px;font-size:11px;color:#64748b;">';
          if (vBy) vHtml += '<span><i class="fas fa-user" style="margin-right:3px;"></i>' + escapeHtml(vBy) + '</span>';
          if (vDept) vHtml += '<span><i class="fas fa-building" style="margin-right:3px;"></i>' + escapeHtml(vDept) + '</span>';
          if (vWhen) vHtml += '<span><i class="fas fa-clock" style="margin-right:3px;"></i>' + escapeHtml(vWhen) + '</span>';
          vHtml += '</div>';
          vHtml += '</div></div>';
        });
        vHtml += '</div>';
        _docTabData.versions = vHtml;
      } else {
        _docTabData.versions = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-history" style="font-size:32px;margin-bottom:8px;display:block;"></i>No version history yet.<br><small>Versions are created when a returned document is re-captured.</small></div>';
      }

      // ── Tab 3: Attachments (card grid) ──
      if (attachments.length > 0) {
        let aHtml = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">';
        attachments.forEach((a, idx) => {
          const fp = (a.file_path || a.filePath || a.path || '').toString();
          const fn = (a.file_name || a.fileName || a.name || '').toString();
          const by = (a.uploaded_by || a.uploadedBy || a.uploader || '').toString();
          const dept = (a.department || a.dept || '').toString();
          const remarks = (a.remarks || a.comment || '').toString();
          const when = (a.created_at || a.createdAt || '').toString();
          const fu = (a.file_url || a.fileUrl || '').toString();
          const label = fn || (fp ? fp.split('/').pop() : 'Attachment #' + (idx + 1));
          const openUrl = toWebUrl(fu, fp);
          aHtml += buildCard(label, openUrl, by, dept, when, remarks, '#' + (idx + 1), '#8b5cf6');
        });
        aHtml += '</div>';
        _docTabData.attachments = aHtml;
      } else {
        _docTabData.attachments = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-paperclip" style="font-size:32px;margin-bottom:8px;display:block;"></i>No attachments yet.<br><small>Attachments are added from the mobile app.</small></div>';
      }

      // Show the active tab (default: versions)
      _switchDocTab(_activeDocTab || 'versions');
    }

    // Wire up Compile All button — downloads ZIP from merge_documents.php
    document.getElementById('infoCompileAllBtn')?.addEventListener('click', async function() {
      if (!_currentCompileDocId) return;
      const btn = this;
      const origHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>&nbsp;Compiling...';
      try {
        const url = 'merge_documents.php?tracking_id=' + encodeURIComponent(_currentCompileDocId) + '&t=' + Date.now();
        const resp = await fetch(url, { credentials: 'include' });
        if (!resp.ok) {
          const errText = await resp.text();
          throw new Error(errText || ('HTTP ' + resp.status));
        }
        const blob = await resp.blob();
        const cd = resp.headers.get('Content-Disposition') || '';
        let fname = 'Compiled_Documents.zip';
        const m = cd.match(/filename="?([^";\n]+)"?/);
        if (m) fname = m[1];
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fname;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
      } catch (e) {
        alert('Compile failed: ' + (e.message || e));
      }
      btn.disabled = false;
      btn.innerHTML = origHtml;
    });

    // Wire up Download All button — toggles the panel
    (function() {
      const btn = document.getElementById('infoDownloadAllBtn');
      const panel = document.getElementById('downloadAllPanel');
      if (btn && panel) {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        });
        // Close panel when clicking outside
        document.addEventListener('click', function(e) {
          if (!panel.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
            panel.style.display = 'none';
          }
        });
      }
    })();

    // Ensure these are available for non-module scripts
    window.currentUser = window.currentUser ?? <?= json_encode($_SESSION['user'] ?? $_SESSION['username'] ?? '') ?>;
    window.currentDepartment = window.currentDepartment ?? <?= json_encode($_SESSION['department'] ?? $_SESSION['user_department'] ?? '') ?>;

    async function loadDocumentComments(docId) {
      currentInfoDocId = docId;
      const container = document.getElementById('infoCommentsContainer');
      if (!container) return;
      
      container.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fas fa-spinner fa-spin"></i> Loading comments...</div>';
      
      try {
        const r = await fetch(`api/document_actions.php?action=get_comments&tracking_id=${encodeURIComponent(String(docId))}`, { cache: 'no-store', credentials: 'include' });
        const rawText = await r.text();
        let payload = null;
        try {
          payload = JSON.parse(rawText);
        } catch (e) {
          container.innerHTML = '<div style="padding:12px;border:1px solid #fecaca;background:#fef2f2;border-radius:8px;color:#b91c1c;">Failed to parse comments response (not JSON).<br/><small>' + escapeHtml(rawText.slice(0, 500)) + '</small></div>';
          return;
        }
        
        if (!payload || !payload.success) {
          const errMsg = (payload && (payload.error || payload.details)) ? (payload.error || payload.details) : 'Failed to load comments';
          container.innerHTML = '<div style="padding:12px;border:1px solid #fecaca;background:#fef2f2;border-radius:8px;color:#b91c1c;">' + escapeHtml(String(errMsg)) + '</div>';
          return;
        }
        
        const comments = payload.comments || [];
        if (comments.length === 0) {
          container.innerHTML = '<div style="text-align: center; padding: 20px; color: #94a3b8; font-style: italic;">No comments yet. Be the first to add one!</div>';
          return;
        }
        
        let html = '';
        comments.forEach(c => {
          let formattedDate = '—';
          if (c.created_at) {
            const date = new Date(c.created_at);
            if (!isNaN(date.getTime())) {
              formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            }
          }
          const canEdit = (c.username === (window.currentUser || '') || c.department === (window.currentDepartment || ''));
          
          html += `
            <div class="comment-item" data-comment-id="${c.id}" style="padding: 12px; margin-bottom: 10px; background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border-radius: 10px; border: 1px solid #e2e8f0;">
              <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <div>
                  <span style="font-weight: 600; color: #0f172a;">${escapeHtml(c.username || 'Unknown')}</span>
                  <span style="color: #64748b; font-size: 12px; margin-left: 8px;">${escapeHtml(c.department || '')}</span>
                </div>
                <span style="color: #94a3b8; font-size: 11px;">${formattedDate}</span>
              </div>
              <div class="comment-text" style="color: #334155; font-size: 13px; line-height: 1.5; white-space: pre-wrap;">${escapeHtml(c.comment)}</div>
              ${canEdit ? `
              <div style="margin-top: 8px; display: flex; gap: 8px; justify-content: flex-end;">
                <button onclick="editComment(${c.id})" class="comment-action-btn" style="padding: 4px 10px; font-size: 11px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer; color: #475569;">
                  <i class="fas fa-edit"></i> Edit
                </button>
                <button onclick="deleteComment(${c.id})" class="comment-action-btn" style="padding: 4px 10px; font-size: 11px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; cursor: pointer; color: #dc2626;">
                  <i class="fas fa-trash"></i> Delete
                </button>
              </div>
              ` : ''}
            </div>
          `;
        });
        
        container.innerHTML = html;
      } catch (e) {
        container.innerHTML = '<div style="padding:12px;border:1px solid #fecaca;background:#fef2f2;border-radius:8px;color:#b91c1c;">Error loading comments: ' + escapeHtml(String(e && e.message ? e.message : e)) + '</div>';
      }
    }

    function escapeHtml(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    async function editComment(commentId) {
      const item = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
      if (!item) return;
      
      const textEl = item.querySelector('.comment-text');
      if (!textEl) return;
      
      const currentText = textEl.textContent;
      const newText = prompt('Edit comment:', currentText);
      
      if (newText === null || newText.trim() === '' || newText === currentText) return;
      
      try {
        const fd = new FormData();
        fd.append('action', 'edit_comment');
        fd.append('comment_id', String(commentId));
        fd.append('comment', newText.trim());
        fd.append('username', window.currentUser || '');
        fd.append('department', window.currentDepartment || '');
        
        const r = await fetch('api/document_actions.php', { method: 'POST', body: fd, credentials: 'include' });
        const payload = await r.json();
        
        if (payload && payload.success) {
          if (currentInfoDocId) loadDocumentComments(currentInfoDocId);
        } else {
          alert(payload.error || 'Failed to edit comment');
        }
      } catch (e) {
        alert('Failed to edit comment');
      }
    }

    async function deleteComment(commentId) {
      if (!confirm('Are you sure you want to delete this comment?')) return;
      
      try {
        const fd = new FormData();
        fd.append('action', 'delete_comment');
        fd.append('comment_id', String(commentId));
        fd.append('username', window.currentUser || '');
        fd.append('department', window.currentDepartment || '');
        
        const r = await fetch('api/document_actions.php', { method: 'POST', body: fd, credentials: 'include' });
        const payload = await r.json();
        
        if (payload && payload.success) {
          if (currentInfoDocId) loadDocumentComments(currentInfoDocId);
        } else {
          alert(payload.error || 'Failed to delete comment');
        }
      } catch (e) {
        alert('Failed to delete comment');
      }
    }

    async function loadTrackingOcrIntoInfoModal(docId, targetEl) {
      if (!targetEl) return;
      try {
        const r = await fetch(`tracking.php?action=ocr_pages&doc_id=${encodeURIComponent(String(docId))}`, { cache: 'no-store' });
        const payload = await r.json();
        if (!payload || !payload.success) {
          targetEl.value = 'OCR not available.';
          return;
        }
        const pages = Array.isArray(payload.pages) ? payload.pages : [];
        if (pages.length === 0) {
          targetEl.value = 'OCR not available.';
          return;
        }

        const normalizeOcrText = (raw) => {
          if (raw === undefined || raw === null) return '';
          let t = String(raw);
          // Convert literal "\\n" sequences to actual newlines
          t = t.replace(/\\r\\n/g, '\n').replace(/\\n/g, '\n').replace(/\\r/g, '\n');

          // Strip metadata header if present; keep only extracted text
          const marker = '--- Extracted Text ---';
          const idx = t.indexOf(marker);
          if (idx !== -1) {
            t = t.slice(idx + marker.length);
          }

          t = t.replace(/^\s+/, '');
          return t;
        };

        const parts = [];
        const attachmentParts = [];
        for (const p of pages) {
          const num = p.page_number || 1;
          const text = normalizeOcrText((p.ocr_text || '').toString());
          if (text.trim() === '') continue;
          const isAttachment = /^\s*\[ATTACHMENT\s*:/i.test(text);
          const clipped = text.length > 8000 ? (text.slice(0, 8000) + '\n\n[truncated]') : text;

          // Default: show only main-document OCR (exclude attachment OCR pages).
          // If there are no main pages, we fall back to showing whatever exists.
          if (isAttachment) {
            attachmentParts.push(pages.length > 1 ? `--- Page ${num} ---\n${clipped}` : clipped);
          } else {
            parts.push(pages.length > 1 ? `--- Page ${num} ---\n${clipped}` : clipped);
          }
        }

        const chosen = parts.length > 0 ? parts : attachmentParts;
        const finalText = chosen.join('\n\n');
        targetEl.value = finalText.trim() !== '' ? finalText : 'OCR not available.';
        // Parse and display extracted keys from the raw OCR pages
        parseAndShowExtractedKeys(pages);
      } catch (e) {
        targetEl.value = 'OCR not available.';
        parseAndShowExtractedKeys([]);
      }
    }

    /**
     * Parse OCR pages text to extract key information (type, names, dates, amounts, refs, depts)
     * and render them as chips in the info modal
     */
    function parseAndShowExtractedKeys(pages) {
      const section = document.getElementById('infoExtractedKeysSection');
      const grid = document.getElementById('infoExtractedKeysGrid');
      if (!section || !grid) return;
      grid.innerHTML = '';
      section.style.display = 'none';

      // Combine all page text
      let fullText = '';
      for (const p of (pages || [])) {
        const t = String(p.ocr_text || '').replace(/\\n/g, '\n').replace(/\\r/g, '');
        fullText += t + '\n\n';
      }
      if (!fullText.trim()) return;

      const chips = [];

      // 1. Look for tagged format first (TYPE:..., NAME:..., etc.)
      const tagPatterns = [
        { tag: 'TYPE', cls: 'type', icon: 'fas fa-file-alt', label: 'Type' },
        { tag: 'NAME', cls: 'name', icon: 'fas fa-user', label: 'Name' },
        { tag: 'DEPT', cls: 'dept', icon: 'fas fa-building', label: 'Dept' },
        { tag: 'REF', cls: 'ref', icon: 'fas fa-hashtag', label: 'Ref' },
        { tag: 'AMOUNT', cls: 'amount', icon: 'fas fa-money-bill', label: 'Amount' },
      ];

      for (const tp of tagPatterns) {
        const regex = new RegExp('^' + tp.tag + ':(.+)$', 'gm');
        let m;
        let count = 0;
        while ((m = regex.exec(fullText)) !== null && count < 3) {
          const val = m[1].trim();
          if (val && val.length > 1) {
            chips.push({ cls: tp.cls, icon: tp.icon, label: tp.label, value: val });
            count++;
          }
        }
      }

      // 2. If no tagged format found, try regex extraction from raw text
      if (chips.length === 0) {
        // Document type keywords
        const typeMatch = fullText.match(/\b(payroll|memorandum|memo|certificate|clearance|leave|appointment|order|resolution|ordinance|voucher|receipt|invoice|requisition|contract|travel|liquidation|evaluation|appraisal|advisory|announcement)\b/i);
        if (typeMatch) {
          const t = typeMatch[1];
          chips.push({ cls: 'type', icon: 'fas fa-file-alt', label: 'Type', value: t.charAt(0).toUpperCase() + t.slice(1).toLowerCase() });
        }

        // Names (Name:, Employee:, Prepared by:, etc.)
        const nameRegex = /(?:name|employee|staff|prepared by|submitted by|approved by)\s*[:\-]?\s*([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+){1,3})/gi;
        let nm;
        let nc = 0;
        while ((nm = nameRegex.exec(fullText)) !== null && nc < 3) {
          chips.push({ cls: 'name', icon: 'fas fa-user', label: 'Name', value: nm[1].trim() });
          nc++;
        }

        // Dates
        const dateRegex = /\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\w+\s+\d{1,2},?\s+\d{4})\b/g;
        let dm;
        let dc = 0;
        while ((dm = dateRegex.exec(fullText)) !== null && dc < 3) {
          chips.push({ cls: 'date', icon: 'fas fa-calendar', label: 'Date', value: dm[1] });
          dc++;
        }

        // Amounts
        const amtRegex = /(?:₱|PHP|Php)\s*[\d,]+(?:\.\d{2})?/g;
        let am;
        let ac = 0;
        while ((am = amtRegex.exec(fullText)) !== null && ac < 2) {
          chips.push({ cls: 'amount', icon: 'fas fa-money-bill', label: 'Amount', value: am[0] });
          ac++;
        }

        // Reference numbers
        const refRegex = /(?:ref|reference|no|number|control)[.\s#:]*([A-Z0-9\-]{4,})/gi;
        let rm;
        while ((rm = refRegex.exec(fullText)) !== null) {
          chips.push({ cls: 'ref', icon: 'fas fa-hashtag', label: 'Ref', value: rm[1].trim() });
          break;
        }

        // Departments
        const deptRegex = /(?:department|office|division)\s+(?:of\s+)?([A-Za-z\s]{3,30})(?:\n|$|[,.])/gi;
        let dpm;
        while ((dpm = deptRegex.exec(fullText)) !== null) {
          chips.push({ cls: 'dept', icon: 'fas fa-building', label: 'Dept', value: dpm[1].trim() });
          break;
        }
      }

      if (chips.length === 0) return;

      section.style.display = 'flex';
      for (const chip of chips) {
        const el = document.createElement('span');
        el.className = 'ocr-key-chip ' + chip.cls;
        el.innerHTML = '<i class="ocr-key-icon ' + chip.icon + '"></i>' +
          '<span class="ocr-key-label">' + escapeHtml(chip.label) + '</span> ' +
          '<span class="ocr-key-value" title="' + escapeHtml(chip.value) + '">' + escapeHtml(chip.value) + '</span>';
        grid.appendChild(el);
      }
    }

    // Helper function to load document preview in Info modal
    async function loadDocumentPreviewInInfo(previewUrl, doc, wrapper, frame) {
        if (!wrapper || !frame) return;
        wrapper.style.display = 'block';
        frame.src = '';
        frame.srcdoc = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading document...</div>';
        
        try {
            const response = await fetch(previewUrl, { credentials: 'include' });
            if (!response.ok) throw new Error('Failed to load document');
            
            const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            const blob = await response.blob();
            
            if (contentType.startsWith('image/') || contentType.includes('pdf')) {
                const objectUrl = URL.createObjectURL(blob);
                frame.srcdoc = '';
                frame.src = objectUrl;
            } else if (contentType.startsWith('text/')) {
                const text = await blob.text();
                const htmlContent = `<html><head><style>
                    body { margin: 0; padding: 12px; font-family: system-ui, sans-serif; font-size: 14px; line-height: 1.4; background: #f8fafc; white-space: pre-wrap; word-wrap: break-word; }
                </style></head><body>${escapeHtml(text)}</body></html>`;
                const textBlob = new Blob([htmlContent], { type: 'text/html' });
                const textUrl = URL.createObjectURL(textBlob);
                frame.srcdoc = '';
                frame.src = textUrl;
            } else {
                frame.srcdoc = `<div style="text-align:center;padding:20px;">
                    <i class="fas fa-file" style="font-size:48px;color:#64748b;"></i>
                    <p>Preview not available for this file type</p>
                    <a href="${previewUrl}" target="_blank" style="color:#0ea5e9;">Download / Open in New Tab</a>
                </div>`;
            }
        } catch (error) {
            frame.srcdoc = `<div style="text-align:center;color:#b91c1c;padding:20px;font-family:sans-serif;">
                Preview failed. <a href="${previewUrl}" target="_blank" style="color:#0ea5e9;">Try opening directly</a>.
            </div>`;
        }
    }

    async function fetchDocDetail(docId) {
      try {
        const r = await fetch(`tracking.php?action=doc_detail&id=${encodeURIComponent(String(docId))}`, { cache: 'no-store' });
        const payload = await r.json();
        if (payload && payload.success && payload.doc) return payload.doc;
      } catch (_) {}
      return null;
    }

    /**
     * Fetch document bundle (main + attachments + history + OCR) in one call.
     * This is the single-source-of-truth endpoint for document modals.
     * @param {number|string} docId - The tracking ID
     * @returns {Promise<{main: object, attachments: array, history: array, ocr: object|null}|null>}
     */
    async function fetchDocumentBundle(docId) {
      try {
        const url = `api/document_actions.php?action=get_document_bundle&tracking_id=${encodeURIComponent(String(docId))}&_=${Date.now()}`;
        const r = await fetch(url, { cache: 'no-store', credentials: 'include' });
        const payload = await r.json();
        if (payload && payload.success && payload.bundle) {
          return payload.bundle;
        }
        console.warn('[fetchDocumentBundle] Failed:', payload?.error || 'Unknown error');
      } catch (e) {
        console.error('[fetchDocumentBundle] Exception:', e);
      }
      return null;
    }

    // View Document Timeline (displays horizontal timeline in a modal)
    async function viewDocumentTimeline(docId) {
      const base = documents.find(d => String(d.id) === String(docId));
      const detail = await fetchDocDetail(docId);
      const doc = detail ? { ...base, ...detail } : base;
      if (!doc) return;

        // Get modal elements
        const timelineModal = document.getElementById('viewDocumentTimelineModal');
        const timelineDocumentName = document.getElementById('timelineDocumentName');
        const timelineActivityLog = document.getElementById('timelineActivityLog');
        const timelineCurrentStatus = document.getElementById('timelineCurrentStatus');
        const timelineCurrentHolder = document.getElementById('timelineCurrentHolder');

        // Set document name
        timelineDocumentName.textContent = doc.type;
        
        // Show fixed route badge if routing_queue exists
        const routeList = doc.routing_queue_list || [];
        if (routeList.length > 0) {
            const badge = document.createElement('span');
            badge.style.cssText = 'display:inline-block;margin-left:10px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;background:#f0edf6;color:#6868AC;vertical-align:middle;';
            badge.innerHTML = '<i class="fas fa-route" style="margin-right:4px;font-size:10px;"></i>Fixed Route: ' + routeList.join(' → ');
            timelineDocumentName.appendChild(badge);
        }
        
        // Set current status from tracking table (same as status shown in tracking.php list)
        const status = doc.status || 'Pending';
        const statusLower = status.toLowerCase().replace(/\s+/g, '');
        const statusClasses = {
            'pending': 'pending',
            'inreview': 'review', 
            'approved': 'completed',
            'completed': 'completed',
            'archived': 'archived',
            'rejected': 'rejected'
        };
        const statusClass = statusClasses[statusLower] || 'pending';
        timelineCurrentStatus.className = 'status-pill ' + statusClass;
        timelineCurrentStatus.textContent = status;
        
        // Set current holder
        timelineCurrentHolder.textContent = doc.current_holder || doc.department || 'Unknown';

        // Clear previous timeline
        timelineActivityLog.innerHTML = '';

        // Populate horizontal timeline with REAL routing history
        if (doc.history && doc.history.length > 0) {
            doc.history.forEach((item, index) => {
                const timelineItem = document.createElement('div');
                const isPendingRoute = item.isPendingRoute || item.actionType === 'pending_route';
                timelineItem.className = 'timeline-item-horizontal' + (isPendingRoute ? ' pending-route-item' : '');
                
                // Determine status class based on action type and status
                const actionType = item.actionType || 'create';
                let statusClass = 'completed';
                let iconClass = 'check';
                
                if (actionType === 'route') {
                    // Route action - shows sender routing to destination
                    statusClass = 'completed';
                    iconClass = 'paper-plane'; // Send icon for routing
                } else if (actionType === 'pending_route') {
                    // Pending fixed-route department - not yet received
                    statusClass = 'pending-route';
                    iconClass = 'hourglass-half';
                } else if (actionType === 'create' || actionType === 'upload') {
                    statusClass = 'completed';
                    iconClass = 'plus-circle';
                } else if (actionType === 'receive') {
                    // Receive action - shows department confirmed receipt
                    statusClass = 'review';
                    iconClass = 'inbox'; // Inbox icon for receiving
                } else if (actionType === 'return') {
                    // Return action - document returned to previous department
                    statusClass = 'returned';
                    iconClass = 'undo'; // Undo/return icon
                } else if (actionType === 'archive') {
                    statusClass = 'completed';
                    iconClass = 'archive';
                } else if (actionType === 'update' || actionType === 'file_update') {
                    statusClass = 'completed';
                    iconClass = 'edit';
                } else {
                    statusClass = item.status === 'review' ? 'review' : 
                                  item.status === 'pending' ? 'pending' : 'completed';
                    iconClass = item.status === 'review' ? 'eye' : 
                               item.status === 'pending' ? 'clock' : 'check';
                }
                
                const isLast = index === doc.history.length - 1;
                
                // Action description already set from PHP
                let actionDesc = item.action;
                if (actionType === 'edit' || actionType === 'update') {
                    actionDesc = '';
                }
                
                // Add return reason/notes if present
                const returnNotes = item.notes || '';
                let notesHtml = '';
                if (returnNotes && (actionType === 'return' || actionDesc.toLowerCase().includes('return'))) {
                    const rid = `returnNotes_${index}`;
                    notesHtml = `
                      <div class="timeline-notes">
                        <details id="${rid}">
                          <summary>
                            <i class="fas fa-exclamation-circle"></i>
                            <strong>Return reason</strong>
                            <span style="margin-left:auto;font-weight:600;color:#b45309;">Show</span>
                          </summary>
                          <div class="timeline-notes-body">${escapeHtml(returnNotes)}</div>
                        </details>
                      </div>`;
                }
                
                // Build arrived/sent timestamp info (only show when value exists)
                let timestampHtml = '';
                const arrivedAt = item.arrivedAt || '';
                const sentAt = item.sentAt || '';

                if (arrivedAt || sentAt) {
                  timestampHtml = '<div class="timeline-timestamps" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;font-size:11px;">';
                  if (arrivedAt) {
                    timestampHtml += `<span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-sign-in-alt" style="font-size:10px;"></i>Arrived: ${escapeHtml(arrivedAt)}</span>`;
                  }
                  if (sentAt) {
                    timestampHtml += `<span style="background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-sign-out-alt" style="font-size:10px;"></i>Sent: ${escapeHtml(sentAt)}</span>`;
                  }
                  timestampHtml += '</div>';
                }
                
                // 3-column layout: Department (left) | Details (center) | Status (right)
                timelineItem.innerHTML = `
                  <!-- Left Column: Department -->
                  <div class="timeline-dept-col">
                    <div class="timeline-dept-badge">${item.user}</div>
                  </div>
                  <!-- Center Column: Date, Time & Description -->
                  <div class="timeline-details-col">
                    <div class="timeline-date-time">
                      <i class="fas fa-calendar-alt" style="margin-right: 6px; color: var(--primary);"></i>${item.time}
                    </div>
                    <div class="timeline-description">${actionDesc}</div>
                    ${timestampHtml}
                    ${notesHtml}
                  </div>
                  <!-- Right Column: Status with Icon -->
                  <div class="timeline-status-col">
                    <div class="timeline-dot ${statusClass}">
                      <i class="fas fa-${iconClass}"></i>
                    </div>
                    <span class="timeline-status-pill ${statusClass}">
                      ${(actionType === 'edit' || actionType === 'update') ? 'Changed document' : (actionType.charAt(0).toUpperCase() + actionType.slice(1).replace('_', ' '))}
                    </span>
                  </div>
                `;
                timelineActivityLog.appendChild(timelineItem);
            });
        } else {
            const timelineItem = document.createElement('div');
            timelineItem.className = 'timeline-item-horizontal';
            // 3-column layout for empty state: Department (left) | Details (center) | Status (right)
            timelineItem.innerHTML = `
              <!-- Left Column: Department -->
              <div class="timeline-dept-col">
                <div class="timeline-dept-badge">${doc.department || doc.current_holder || 'System'}</div>
              </div>
              <!-- Center Column: Date, Time & Description -->
              <div class="timeline-details-col">
                <div class="timeline-date-time">
                  <i class="fas fa-calendar-alt" style="margin-right: 6px; color: var(--primary);"></i>${doc.date_submitted || 'Unknown'}
                </div>
                <div class="timeline-description">Document created and submitted</div>
              </div>
              <!-- Right Column: Status with Icon -->
              <div class="timeline-status-col">
                <div class="timeline-dot pending">
                  <i class="fas fa-clock"></i>
                </div>
                <span class="timeline-status-pill pending">${doc.status || 'Pending'}</span>
              </div>
            `;
            timelineActivityLog.appendChild(timelineItem);
        }

        openModal('viewDocumentTimelineModal');
    }

    // Legacy function name for compatibility
    function viewDocumentJourney(docId) {
        viewDocumentTimeline(docId);
    }

    // View Document (Now displays details in a modal) - kept for compatibility
    async function viewDocument(docId) {
        // Normalize types because PHP JSON encodes id as number, but we pass a string from DOM
      const base = documents.find(d => String(d.id) === String(docId));
      const detail = await fetchDocDetail(docId);
      const doc = detail ? { ...base, ...detail } : base;
        if (doc) {
            // Reset preview area only when switching to a different document
            if (detailDocumentPreviewWrapper && detailDocumentPreviewFrame && currentPreviewDocId !== String(doc.id)) {
                detailDocumentPreviewWrapper.style.display = 'none';
                detailDocumentPreviewFrame.src = '';
            }
            currentPreviewDocId = String(doc.id);

            // Set document title and icon
            detailDocumentName.textContent = doc.type;
            detailDocTypeTitle.textContent = doc.type;
            
            // Set file type icon
            const fileTypeIcon = getFileTypeIcon(doc.file_type_icon);
            detailFileTypeIcon.className = fileTypeIcon;
            
            // Set basic information
            detailEmployeeName.textContent = doc.employee_name;
            detailDateSubmitted.textContent = doc.date_submitted;
            detailCurrentHolder.textContent = doc.current_holder;
            detailEndLocation.textContent = doc.end_location;
            detailFileType.textContent = doc.file_type_icon.toUpperCase();

            // Configure View Document button; keep it visible for all docs
            detailViewDocumentBtn.style.display = 'inline-flex';
            detailViewDocumentBtn.onclick = async () => {
                // Always use download.php with cache-busting so updated files are never stale
                const freshDetailUrl = 'download.php?id=' + encodeURIComponent(doc.id) + '&inline=1&t=' + Date.now();
                if (doc.file_path) {
                    await loadDocumentPreviewInTracking(freshDetailUrl, doc);
                    return;
                }
                
                let rawPath = (doc.file_path || '').trim();

                // Ignore Android local paths which are not accessible from the web
                if (rawPath.startsWith('/data/user/')) {
                    showToast('This document file is stored only on the mobile device and is not available on the server.', 'info');
                    return;
                }

                if (!rawPath) {
                    showToast('No document file is attached to this record.', 'info');
                    return;
                }

                // If the stored path points to an encrypted archive, route through preview.php
                // (which internally calls download.php) so the file is decrypted and scaled
                // to fit inside the preview area.
                if (rawPath.toLowerCase().endsWith('.enc')) {
                    const href = 'preview.php?id=' + encodeURIComponent(doc.id);
                    if (detailDocumentPreviewWrapper && detailDocumentPreviewFrame) {
                        detailDocumentPreviewWrapper.style.display = 'block';
                        detailDocumentPreviewFrame.onload = resizePreviewFrameToContent;
                        detailDocumentPreviewFrame.src = href;
                    } else {
                        window.open(href, '_blank');
                    }
                    return;
                }

                // If rawPath is already an absolute URL, use it as-is
                if (rawPath.startsWith('http://') || rawPath.startsWith('https://')) {
                    await loadDocumentPreviewInTracking(rawPath, doc);
                    return;
                }

                // Otherwise treat it as a path under the XAMPP web root.
                // Normalize leading ./ and leading slashes.
                rawPath = rawPath.replace(/^\.\//, '');

                // If path already starts with flutter_application_7, just prefix with a single '/'
                let webPath = rawPath;
                if (!webPath.startsWith('flutter_application_7/')) {
                    webPath = 'flutter_application_7/' + webPath.replace(/^\//, '');
                }

                const href = '/' + webPath;
                await loadDocumentPreviewInTracking(href, doc);
            };
            
            // Remove mobile upload details and OCR content from the modal
            detailDocTypeTitle.innerHTML = doc.type;
            const existingMobileInfo = document.querySelector('.mobile-info');
            if (existingMobileInfo) { existingMobileInfo.remove(); }

            // Update status pill
            detailStatusPill.innerHTML = `<span class="${getStatusPillClass(doc.status)}">${doc.status}</span>`;

            // Populate timeline
            detailActivityLog.innerHTML = ''; // Clear previous timeline
            if (doc.history && doc.history.length > 0) {
                doc.history.forEach(item => {
                    const timelineItem = document.createElement('div');
                    timelineItem.className = 'timeline-item';
                    
                    const statusClass = item.status === 'completed' ? 'completed' :
                                       item.status === 'pending' ? 'pending' :
                                       item.status === 'review' ? 'review' : 'completed';
                    
                    timelineItem.innerHTML = `
                        <div class="timeline-header">
                            <span class="timeline-user">${item.user}</span>
                            <span class="timeline-time">${item.time}</span>
                        </div>
                        <div class="timeline-action">${item.action}</div>
                        <div class="timeline-status ${statusClass}">
                            <i class="fas fa-${item.status === 'completed' ? 'check-circle' : 
                                               item.status === 'pending' ? 'clock' : 
                                               item.status === 'review' ? 'eye' : 'check-circle'}"></i>
                            ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}
                        </div>
                        ${item.user === 'CMO' ? `
                        <div class="timeline-document-details" id="timeline-doc-details-${doc.id}-${item.user}">
                            <div class="timeline-info-section">
                                <h6><i class="fas fa-info-circle"></i> Document Information</h6>
                                <div class="timeline-info-item">
                                    <span class="info-label">Employee Name:</span>
                                    <span class="info-value">${doc.employee_name || 'N/A'}</span>
                                </div>
                                <div class="timeline-info-item">
                                    <span class="info-label">Department:</span>
                                    <span class="info-value">${doc.department || 'N/A'}</span>
                                </div>
                                <div class="timeline-info-item">
                                    <span class="info-label">Date Submitted:</span>
                                    <span class="info-value">${doc.date_submitted || 'N/A'}</span>
                                </div>
                                <div class="timeline-info-item">
                                    <span class="info-label">File Type:</span>
                                    <span class="info-value">${doc.file_type_icon?.toUpperCase() || 'N/A'}</span>
                                </div>
                            </div>
                            <div class="timeline-document-preview-section">
                                <h6><i class="fas fa-file-alt"></i> Document Preview</h6>
                                <div class="timeline-document-preview">
                                    ${doc.file_path ? `
                                        ${doc.file_ext && ['jpg', 'jpeg', 'png', 'gif'].includes(doc.file_ext) ? 
                                            `<img src="download.php?id=${doc.id}&inline=1" alt="Document Preview" style="max-width: 100%; height: auto; border-radius: 4px; border: 1px solid var(--border);">` :
                                            doc.file_ext === 'pdf' ? 
                                            `<iframe src="download.php?id=${doc.id}&inline=1" style="width: 100%; height: 300px; border: 1px solid var(--border); border-radius: 4px;"></iframe>` :
                                            `<div class="preview-placeholder">
                                                <i class="fas fa-file"></i>
                                                <p>Document preview available for download</p>
                                                <a href="download.php?id=${doc.id}" class="btn-download" onclick="loadDocumentInPreview(event, 'download.php?id=${doc.id}', ${doc.id}); return false;">
                                                    <i class="fas fa-download"></i> View Document
                                                </a>
                                            </div>`
                                        }
                                    ` : `
                                        <div class="preview-placeholder">
                                            <i class="fas fa-file"></i>
                                            <p>No document file available</p>
                                        </div>
                                    `}
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    `;
                    detailActivityLog.appendChild(timelineItem);
                });
            } else {
                detailActivityLog.innerHTML = `
                    <div class="timeline-item">
                        <div class="timeline-header">
                            <span class="timeline-user">System</span>
                            <span class="timeline-time">No activity recorded</span>
                        </div>
                        <div class="timeline-action">No detailed activity log available for this document.</div>
                        <div class="timeline-status completed">
                            <i class="fas fa-info-circle"></i>
                            No Data
                        </div>
                    </div>
                `;
            }

            openModal('viewDocumentDetailModal');
        } else {
            showToast(`Document with ID ${docId} not found.`, 'error');
        }
    }


    // Confirmation Modal Functions
    function archiveDocumentConfirm(docId, docType) {
        openConfirmModal(
            `Archive Document`,
            `Are you sure you want to archive document "${docType}" (ID: ${docId})? It will be moved to archive storage.`,
            () => {
                // Redirect to PHP script to handle archiving
                window.location.href = `tracking.php?archive_id=${docId}`;
            }
        );
    }

    // Group archive for multi-department announcements: allowed only when all are Completed.
    function archiveAnnouncementGroupConfirm(docId, docType) {
      openConfirmModal(
        `Archive Announcement`,
        `This will archive the announcement "${docType}" for ALL recipient departments (single archive record). Continue?`,
        () => {
          window.location.href = `tracking.php?action=archive_group&id=${docId}`;
        }
      );
    }

    // Mark document as received — updates status to "In Review"
    function markDocumentReceived(docId) {
        openConfirmModal(
            'Confirm Receipt',
            'Are you sure you want to mark this document as received? Status will change to "In Review".',
            async () => {
                try {
                    const r = await fetch(`tracking.php?action=mark_received&id=${encodeURIComponent(docId)}`, { cache: 'no-store', credentials: 'include' });
                    const data = await r.json();
                    if (data && data.success) {
                        showToast('Document marked as received (In Review)', 'success');
                        setTimeout(() => location.reload(), 800);
                    } else {
                        showToast(data.error || 'Failed to mark as received', 'error');
                    }
                } catch (e) {
                    showToast('Error: ' + e.message, 'error');
                }
            }
        );
    }

    // Open camera/file capture for final document update
    function openFinalDocumentCapture(docId, docType) {
        // Create a file input for capturing/uploading the final document
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*,.pdf';
        fileInput.capture = 'environment'; // Use camera on mobile devices
        
        fileInput.onchange = async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            showToast('Uploading final document...', 'info');
            
            const formData = new FormData();
            formData.append('action', 'update_final_document');
            formData.append('doc_id', docId);
            formData.append('file', file);
            
            try {
                const response = await fetch('tracking.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast('Final document captured successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(result.error || 'Failed to upload document', 'error');
                }
            } catch (error) {
                showToast('Error uploading document: ' + error.message, 'error');
            }
        };
        
        fileInput.click();
    }

    // Archive final document with Completed status
    function archiveFinalDocument(docId, docType) {
        openConfirmModal(
            'Complete & Archive Document',
            `This will mark document "${docType}" as COMPLETED and move it to archive. This action is final. Continue?`,
            () => {
                // Use the route handler with Completed status to auto-archive
                window.location.href = `tracking.php?route_id=${docId}&new_holder=Archive&new_status=Completed`;
            }
        );
    }

    // --- Toast Notification Function ---
    function showToast(message, type = 'info', duration = 3000) {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'times-circle' : type === 'info' ? 'info-circle' : 'exclamation-triangle'}"></i> ${message}`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, duration);
    }

    // --- Custom Confirmation Modal Functions ---
    function openConfirmModal(title, message, callback) {
        confirmModalTitle.textContent = title;
        confirmModalMessage.textContent = message;
        currentConfirmAction = callback;
        confirmActionModal.classList.add('show');
    }

    function closeConfirmModal() {
        confirmActionModal.classList.remove('show');
        currentConfirmAction = null;
    }

    // Timeline View Document Function
    function viewDocumentFromTimeline(docId, department) {
        // For CMO documents, toggle the inline details instead of opening modal
        if (department === 'CMO') {
            const detailsElement = document.getElementById(`timeline-doc-details-${docId}-CMO`);
            if (detailsElement) {
                // Toggle visibility
                const isVisible = detailsElement.style.display !== 'none';
                detailsElement.style.display = isVisible ? 'none' : 'block';
                
                // Update button text
                const button = event.target.closest('.btn-view-doc');
                if (button) {
                    button.innerHTML = isVisible ? 
                        '<i class="fas fa-eye"></i> View Document' : 
                        '<i class="fas fa-eye-slash"></i> Hide Document';
                }
            }
        } else {
          // For non-CMO documents, open the info modal (includes attachments)
          closeModal('viewDocumentDetailModal');
          setTimeout(() => {
            viewDocumentInfo(docId);
          }, 300);
        }
    }

    // Event listeners for the custom confirmation modal
    closeConfirmModalBtn.addEventListener('click', closeConfirmModal);
    cancelConfirmBtn.addEventListener('click', closeConfirmModal);
    proceedConfirmBtn.addEventListener('click', () => {
        if (currentConfirmAction) {
            currentConfirmAction();
        }
        closeConfirmModal();
    });
    confirmActionModal.addEventListener('click', (event) => {
        if (event.target === confirmActionModal) {
            closeConfirmModal();
        }
    });

    // --- Sorting ---
    document.querySelectorAll('#documentTable th[data-sortable="true"]').forEach(header => {
      header.addEventListener('click', () => {
        const sortBy = header.dataset.sortBy;
        let direction = 'asc';

        // Reset all sort icons and aria-sort
        document.querySelectorAll('#documentTable th[data-sortable="true"]').forEach(h => {
          const icon = h.querySelector('.sort-icon');
          if (icon) icon.className = 'sort-icon';
          h.setAttribute('aria-sort', 'none');
        });

        if (currentSortColumn === sortBy) {
          direction = currentSortDirection === 'asc' ? 'desc' : 'asc';
        }
        currentSortColumn = sortBy;
        currentSortDirection = direction;

        // Update icon and aria-sort for the clicked header
        const sortIcon = header.querySelector('.sort-icon');
        if (sortIcon) {
          if (direction === 'asc') {
            sortIcon.classList.add('fas', 'fa-sort-up');
            header.setAttribute('aria-sort', 'ascending');
          } else {
            sortIcon.classList.add('fas', 'fa-sort-down');
            header.setAttribute('aria-sort', 'descending');
          }
        }
        sortDocuments(sortBy, direction);
      });
    });

    function sortDocuments(sortBy, direction) {
        const sortedDocs = [...documents].sort((a, b) => {
            let valA = a[sortBy];
            let valB = b[sortBy];

            // Handle date sorting
            if (sortBy === 'date_submitted') {
                valA = new Date(valA);
                valB = new Date(valB);
            } else if (typeof valA === 'string') {
                valA = valA.toLowerCase();
                valB = valB.toLowerCase();
            }

            if (valA < valB) {
                return direction === 'asc' ? -1 : 1;
            }
            if (valA > valB) {
                return direction === 'asc' ? 1 : -1;
            }
            return 0;
        });

        documents.splice(0, documents.length, ...sortedDocs);
        currentPage = 1;
        applyFiltersAndSearch();
    }


    // --- Filtering ---
    const applyFiltersBtn = document.getElementById('applyFiltersBtn');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const searchInput = document.getElementById('searchInput');

    function navigateWithCurrentFilters(resetPage = true) {
        const params = new URLSearchParams(window.location.search);
        params.set('q', searchInput.value || '');
        const type = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Types';
        const status = document.querySelector('#statusDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Statuses';
        const dept = document.querySelector('#departmentDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Departments';
        const drOpt = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Dates';
        params.set('type', type);
        params.set('status', status);
        params.set('dept', dept);
        params.set('dr', drOpt);
        if (drOpt === 'Custom') {
          params.set('drcustom', document.getElementById('dateRange').value || '');
        } else {
          params.delete('drcustom');
        }
        if (resetPage) {
          params.set('page', '1');
        }
        window.location.href = `${location.pathname}?${params.toString()}`;
    }

    function persistFiltersToUrl() {
        const params = new URLSearchParams(window.location.search);
        params.set('q', searchInput.value || '');
        const type = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Types';
        const status = document.querySelector('#statusDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Statuses';
        const dept = document.querySelector('#departmentDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Departments';
        const drOpt = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Dates';
        params.set('type', type);
        params.set('status', status);
        params.set('dept', dept);
        params.set('dr', drOpt);
        if (drOpt === 'Custom') {
          params.set('drcustom', document.getElementById('dateRange').value || '');
        } else {
          params.delete('drcustom');
        }
        history.replaceState(null, '', `${location.pathname}?${params.toString()}`);
    }

    function restoreFiltersFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const q = params.get('q') || '';
        const type = params.get('type');
        const status = params.get('status');
        const dept = params.get('dept');
        const dr = params.get('dr');
        const drcustom = params.get('drcustom');
        if (q) searchInput.value = q;

        function setDropdownSelection(dropdownId, btnId, value, defaultLabel) {
          if (!value) return;
          const dropdown = document.getElementById(dropdownId);
          const btn = document.getElementById(btnId);
          if (!dropdown || !btn) return;
          const current = dropdown.querySelector('.filter-dropdown-item.selected');
          if (current) { current.classList.remove('selected'); current.querySelectorAll('.check-icon').forEach(i=>i.remove()); }
          const target = Array.from(dropdown.querySelectorAll('.filter-dropdown-item')).find(el => el.dataset.value === value);
          if (target) {
            target.classList.add('selected');
            const check = document.createElement('i'); check.className = 'fas fa-check check-icon'; target.appendChild(check);
            btn.querySelector('span').textContent = (value === defaultLabel ? defaultLabel : value);
          }
        }

        setDropdownSelection('documentTypeDropdown', 'documentTypeFilterBtn', type, 'Document Type');
        setDropdownSelection('statusDropdown', 'statusFilterBtn', status, 'Document Status');
        setDropdownSelection('departmentDropdown', 'departmentFilterBtn', dept, 'Department');
        setDropdownSelection('dateRangeDropdown', 'dateRangeFilterBtn', dr, 'Date Range');
        if (dr === 'Custom' && drcustom) document.getElementById('dateRange').value = drcustom;
    }

    function applyFiltersAndSearch() {
        const searchTerm = searchInput.value.toLowerCase();
        const selectedType = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected')?.dataset?.value || 'All Types';
        const selectedStatus = document.querySelector('#statusDropdown .filter-dropdown-item.selected')?.dataset?.value || 'All Statuses';
        const selectedDepartment = document.querySelector('#departmentDropdown .filter-dropdown-item.selected')?.dataset?.value || 'All Departments';
        const selectedDateRangeOption = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected')?.dataset?.value || 'All Dates';
        const dateRangeCustomValue = document.getElementById('dateRange').value;

        let startDate = null;
        let endDate = null;
        const today = new Date();
        today.setHours(0, 0, 0, 0); // Normalize to start of day

        if (selectedDateRangeOption !== 'All Dates') {
            if (selectedDateRangeOption === 'Today') {
                startDate = today;
                endDate = new Date(today);
                endDate.setHours(23, 59, 59, 999);
            } else if (selectedDateRangeOption === 'Last 7 Days') {
                startDate = new Date(today);
                startDate.setDate(today.getDate() - 6);
                endDate = new Date(today);
                endDate.setHours(23, 59, 59, 999);
            } else if (selectedDateRangeOption === 'Last 30 Days') {
                startDate = new Date(today);
                startDate.setDate(today.getDate() - 29);
                endDate = new Date(today);
                endDate.setHours(23, 59, 59, 999);
            } else if (selectedDateRangeOption === 'This Month') {
                startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                endDate.setHours(23, 59, 59, 999);
            } else if (selectedDateRangeOption === 'Custom' && dateRangeCustomValue) {
                const dates = dateRangeCustomValue.split(' to ');
                if (dates.length === 2) {
                    startDate = new Date(dates[0]);
                    endDate = new Date(dates[1]);
                    endDate.setHours(23, 59, 59, 999);
                } else if (dates.length === 1) {
                    startDate = new Date(dates[0]);
                    endDate = new Date(dates[0]);
                    endDate.setHours(23, 59, 59, 999);
                }
            }
        }

        const safeLower = (v) => (v === undefined || v === null) ? '' : String(v).toLowerCase();
        const isOcrMode = window.__trackingOcrSearchMode === true;
        const ocrIds = window.__trackingOcrResultDocIds instanceof Set ? window.__trackingOcrResultDocIds : null;
        const filtered = documents.filter(doc => {
          const matchesOcr = !ocrIds ? true : ocrIds.has(String(doc.id));

          // In OCR mode, do NOT require the typed query to match metadata fields.
          // The OCR endpoint already found the matching docs.
          const matchesSearch = isOcrMode ? true : (
                      searchTerm === '' ||
                      safeLower(doc.type).includes(searchTerm) ||
                      safeLower(doc.employee_name).includes(searchTerm) ||
                      safeLower(doc.current_holder).includes(searchTerm) ||
                      safeLower(doc.end_location).includes(searchTerm) ||
                      safeLower(doc.status).includes(searchTerm) ||
                      safeLower(doc.department).includes(searchTerm) ||
                      safeLower(doc.ocr_content).includes(searchTerm)
          );

            const matchesType = selectedType === 'All Types' || doc.type === selectedType;
            const matchesStatus = selectedStatus === 'All Statuses' ||
              doc.status === selectedStatus ||
              (selectedStatus === 'Returned' && (doc.status === 'Archived' || doc.status === 'Returned'));
            const matchesDepartment = selectedDepartment === 'All Departments' ||
              doc.department === selectedDepartment ||
              safeLower(doc.current_holder).includes(safeLower(selectedDepartment)) ||
              safeLower(doc.end_location).includes(safeLower(selectedDepartment));

            let matchesDate = true;
            if (startDate && endDate) {
                const docDate = doc.date_submitted ? new Date(doc.date_submitted) : (doc.created_at ? new Date(doc.created_at) : null);
                docDate.setHours(0, 0, 0, 0); // Normalize document date to start of day for comparison
                matchesDate = docDate && docDate >= startDate && docDate <= endDate;
            }

            return matchesOcr && matchesSearch && matchesType && matchesStatus && matchesDepartment && matchesDate;
        });

        currentPage = 1; // Reset to first page after filtering
        renderDocuments(filtered);
    }

    // Expose filter function so the Firebase module can trigger re-rendering
    window.applyFiltersAndSearch = applyFiltersAndSearch;

    applyFiltersBtn.addEventListener('click', () => {
      persistFiltersToUrl();
      if (pageInfoSpan && pageInfoSpan.dataset.serverside === '1') {
        navigateWithCurrentFilters(true);
        return;
      }
      applyFiltersAndSearch();
      closeFiltersPanel();
    });
    // Live search (disabled during OCR mode; OCR handler drives filtering)
    let serverSearchDebounce = null;
    searchInput.addEventListener('input', () => {
      if (window.__trackingOcrSearchMode === true) return;
      persistFiltersToUrl();
      if (pageInfoSpan && pageInfoSpan.dataset.serverside === '1') {
        clearTimeout(serverSearchDebounce);
        serverSearchDebounce = setTimeout(() => {
          // Avoid thrashing on very short queries.
          const qv = (searchInput.value || '').trim();
          if (qv.length === 0 || qv.length >= 2) {
            navigateWithCurrentFilters(true);
          }
        }, 450);
        return;
      }
      applyFiltersAndSearch();
    });

    clearFiltersBtn.addEventListener('click', () => {
      searchInput.value = '';

      // Reset Document Type filter
      const curType = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected');
      if (curType) curType.classList.remove('selected');
      const allTypes = document.querySelector('#documentTypeDropdown .filter-dropdown-item[data-value="All Types"]');
      if (allTypes) {
        allTypes.classList.add('selected');
        allTypes.innerHTML = 'All Types <i class="fas fa-check check-icon"></i>';
      }
      document.getElementById('documentTypeFilterBtn').querySelector('span').textContent = 'Document Type';
      document.querySelectorAll('#documentTypeDropdown .check-icon').forEach(icon => {
        if (icon.parentElement !== allTypes) icon.remove();
      });

      // Reset Status filter
      const curStatus = document.querySelector('#statusDropdown .filter-dropdown-item.selected');
      if (curStatus) curStatus.classList.remove('selected');
      const allStatuses = document.querySelector('#statusDropdown .filter-dropdown-item[data-value="All Statuses"]');
      if (allStatuses) {
        allStatuses.classList.add('selected');
        allStatuses.innerHTML = 'All Statuses <i class="fas fa-check check-icon"></i>';
      }
      document.getElementById('statusFilterBtn').querySelector('span').textContent = 'Document Status';
      document.querySelectorAll('#statusDropdown .check-icon').forEach(icon => {
        if (icon.parentElement !== allStatuses) icon.remove();
      });

      // Reset Department filter
      const curDept = document.querySelector('#departmentDropdown .filter-dropdown-item.selected');
      if (curDept) curDept.classList.remove('selected');
      const allDepartments = document.querySelector('#departmentDropdown .filter-dropdown-item[data-value="All Departments"]');
      if (allDepartments) {
        allDepartments.classList.add('selected');
        allDepartments.innerHTML = 'All Departments <i class="fas fa-check check-icon"></i>';
      }
      document.getElementById('departmentFilterBtn').querySelector('span').textContent = 'Department';
      document.querySelectorAll('#departmentDropdown .check-icon').forEach(icon => {
        if (icon.parentElement !== allDepartments) icon.remove();
      });

      // Reset Date Range filter
      const curDate = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected');
      if (curDate) curDate.classList.remove('selected');
      const allDates = document.querySelector('#dateRangeDropdown .filter-dropdown-item[data-value="All Dates"]');
      if (allDates) {
        allDates.classList.add('selected');
        allDates.innerHTML = 'All Dates <i class="fas fa-check check-icon"></i>';
      }
      document.getElementById('dateRangeFilterBtn').querySelector('span').textContent = 'Date Range';
      document.querySelectorAll('#dateRangeDropdown .check-icon').forEach(icon => {
        if (icon.parentElement !== allDates) icon.remove();
      });
      document.getElementById('dateRange').value = ''; // Clear custom date range input

      // Clear URL params
      const cleanUrl = location.pathname;
      history.replaceState(null, '', cleanUrl);
      applyFiltersAndSearch();

      // Reset badge and chips (these functions live inside DOMContentLoaded, accessed via window)
      if (typeof window.updateActiveFiltersCount === 'function') window.updateActiveFiltersCount();
      if (typeof window.renderFilterChips === 'function') window.renderFilterChips();

      // Directly hide badge as a fallback
      const badge = document.getElementById('activeFiltersCount');
      if (badge) { badge.style.display = 'none'; badge.textContent = '0'; }

      closeFiltersPanel();
    });


    // Initial render when page loads
    document.addEventListener('DOMContentLoaded', () => {
      // Ensure "All Types", "All Statuses", "All Departments", "All Dates" are selected and have checkmarks initially
      document.querySelectorAll('.filter-dropdown-item[data-value="All Types"], .filter-dropdown-item[data-value="All Statuses"], .filter-dropdown-item[data-value="All Departments"], .filter-dropdown-item[data-value="All Dates"]').forEach(item => {
        item.classList.add('selected');
        const checkIcon = document.createElement('i');
        checkIcon.className = 'fas fa-check check-icon';
        item.appendChild(checkIcon);
      });

      // Restore from URL if present
      restoreFiltersFromUrl();
      applyFiltersAndSearch();

      // Highlight a specific row if navigated with ?id=
      try {
        const urlParams = new URLSearchParams(window.location.search);
        const targetId = urlParams.get('id');
        if (targetId) {
          const row = document.querySelector(`tr[data-id="${CSS.escape(targetId)}"]`);
          if (row) {
            const originalBg = getComputedStyle(row).backgroundColor;
            row.style.transition = 'background-color 0.6s ease';
            row.style.backgroundColor = '#FFF3CD'; // soft highlight
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => { row.style.backgroundColor = originalBg || ''; }, 2000);
          }
        }
      } catch(_) {}

      // Filters panel open/close logic
      const openBtn = document.getElementById('openFiltersBtn');
      const panel = document.getElementById('filtersPanel');
      const backdrop = document.getElementById('filtersBackdrop');
      function openFiltersPanel(){ panel.classList.add('show'); backdrop.classList.add('show'); }
      function closeFiltersPanel(){ panel.classList.remove('show'); backdrop.classList.remove('show'); }
      window.closeFiltersPanel = closeFiltersPanel;
      if (openBtn){ openBtn.addEventListener('click', openFiltersPanel); }
      if (backdrop){ backdrop.addEventListener('click', closeFiltersPanel); }

      // Active filters counter
      function updateActiveFiltersCount(){
        let count = 0;
        if (searchInput.value.trim() !== '') count++;
        const defaults = {
          type: 'All Types', status: 'All Statuses', dept: 'All Departments', dr: 'All Dates'
        };
        const typeSel = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected')?.dataset.value || defaults.type;
        const statusSel = document.querySelector('#statusDropdown .filter-dropdown-item.selected')?.dataset.value || defaults.status;
        const deptSel = document.querySelector('#departmentDropdown .filter-dropdown-item.selected')?.dataset.value || defaults.dept;
        const drSel = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected')?.dataset.value || defaults.dr;
        if (typeSel !== defaults.type) count++;
        if (statusSel !== defaults.status) count++;
        if (deptSel !== defaults.dept) count++;
        if (drSel !== defaults.dr) count++;
        const badge = document.getElementById('activeFiltersCount');
        if (!badge) return;
        if (count > 0) { badge.textContent = String(count); badge.style.display = 'inline-block'; }
        else { badge.style.display = 'none'; }
      }
      // Expose on window so clear-filters handler (outside DOMContentLoaded) can access them
      window.updateActiveFiltersCount = updateActiveFiltersCount;

      // Update count on interactions
      document.getElementById('documentTypeDropdown').addEventListener('click', updateActiveFiltersCount);
      document.getElementById('statusDropdown').addEventListener('click', updateActiveFiltersCount);
      document.getElementById('departmentDropdown').addEventListener('click', updateActiveFiltersCount);
      document.getElementById('dateRangeDropdown').addEventListener('click', updateActiveFiltersCount);
      searchInput.addEventListener('input', updateActiveFiltersCount);
      updateActiveFiltersCount();

      // Filter chips under header
      function renderFilterChips(){
        const wrap = document.getElementById('filtersChips');
        if (!wrap) return;
        wrap.innerHTML = '';
        const chips = [];
        const q = searchInput.value.trim();
        const type = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Types';
        const status = document.querySelector('#statusDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Statuses';
        const dept = document.querySelector('#departmentDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Departments';
        const dr = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected')?.dataset.value || 'All Dates';
        const drcustom = document.getElementById('dateRange').value || '';

        if (q) chips.push({ key:'q', label:`Search: ${q}` });
        if (type !== 'All Types') chips.push({ key:'type', label:type });
        if (status !== 'All Statuses') chips.push({ key:'status', label:status });
        if (dept !== 'All Departments') chips.push({ key:'dept', label:dept });
        if (dr !== 'All Dates') chips.push({ key:'dr', label: dr === 'Custom' && drcustom ? `Date: ${drcustom}` : dr });

        chips.forEach(c => {
          const el = document.createElement('span');
          el.className = 'chip';
          el.innerHTML = `${c.label} <i class="fas fa-times remove" title="Remove"></i>`;
          el.querySelector('.remove').addEventListener('click', () => {
            if (c.key === 'q') { searchInput.value = ''; }
            if (c.key === 'type') {
              const all = document.querySelector('#documentTypeDropdown .filter-dropdown-item[data-value="All Types"]');
              const cur = document.querySelector('#documentTypeDropdown .filter-dropdown-item.selected');
              if (cur) cur.classList.remove('selected');
              if (all) { all.classList.add('selected'); document.getElementById('documentTypeFilterBtn').querySelector('span').textContent = 'Document Type'; }
            }
            if (c.key === 'status') {
              const all = document.querySelector('#statusDropdown .filter-dropdown-item[data-value="All Statuses"]');
              const cur = document.querySelector('#statusDropdown .filter-dropdown-item.selected');
              if (cur) cur.classList.remove('selected');
              if (all) { all.classList.add('selected'); document.getElementById('statusFilterBtn').querySelector('span').textContent = 'Document Status'; }
            }
            if (c.key === 'dept') {
              const all = document.querySelector('#departmentDropdown .filter-dropdown-item[data-value="All Departments"]');
              const cur = document.querySelector('#departmentDropdown .filter-dropdown-item.selected');
              if (cur) cur.classList.remove('selected');
              if (all) { all.classList.add('selected'); document.getElementById('departmentFilterBtn').querySelector('span').textContent = 'Department'; }
            }
            if (c.key === 'dr') {
              const all = document.querySelector('#dateRangeDropdown .filter-dropdown-item[data-value="All Dates"]');
              const cur = document.querySelector('#dateRangeDropdown .filter-dropdown-item.selected');
              if (cur) cur.classList.remove('selected');
              if (all) { all.classList.add('selected'); document.getElementById('dateRangeFilterBtn').querySelector('span').textContent = 'Date Range'; }
              document.getElementById('dateRange').value = '';
            }
            persistFiltersToUrl();
            updateActiveFiltersCount();
            renderFilterChips();
            applyFiltersAndSearch();
          });
          wrap.appendChild(el);
        });

        // Add "Clear All" chip when 2 or more filters are active
        if (chips.length >= 2) {
          const clearAll = document.createElement('span');
          clearAll.className = 'chip chip-clear-all';
          clearAll.innerHTML = '<i class="fas fa-times-circle"></i> Clear All';
          clearAll.addEventListener('click', () => {
            clearFiltersBtn.click();
            renderFilterChips();
          });
          wrap.appendChild(clearAll);
        }
      }

      // Expose on window so clear-filters handler (outside DOMContentLoaded) can access it
      window.renderFilterChips = renderFilterChips;

      // Re-render chips after selection changes
      document.getElementById('documentTypeDropdown').addEventListener('click', () => { renderFilterChips(); });
      document.getElementById('statusDropdown').addEventListener('click', () => { renderFilterChips(); });
      document.getElementById('departmentDropdown').addEventListener('click', () => { renderFilterChips(); });
      document.getElementById('dateRangeDropdown').addEventListener('click', () => { renderFilterChips(); });
      searchInput.addEventListener('input', () => { renderFilterChips(); });
      renderFilterChips();

      // Centralized "filters changed" handler (used by dropdowns + date range)
      window.__trackingOnFiltersChange = () => {
        persistFiltersToUrl();
        updateActiveFiltersCount();
        renderFilterChips();
        if (pageInfoSpan && pageInfoSpan.dataset.serverside === '1') {
          navigateWithCurrentFilters(true);
          return;
        }
        applyFiltersAndSearch();
      };

      // Keyboard UX
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          persistFiltersToUrl();
          if (pageInfoSpan && pageInfoSpan.dataset.serverside === '1') {
            navigateWithCurrentFilters(true);
            return;
          }
          applyFiltersAndSearch();
        }
      });
      document.addEventListener('keydown', (e) => {
        const panel = document.getElementById('filtersPanel');
        if (e.key === 'Escape' && panel && panel.classList.contains('show')) {
          e.preventDefault(); closeFiltersPanel();
        }
      });

      // --- Search suggestions ---
      const suggWrap = document.getElementById('searchSuggestions');
      let suggIndex = -1; // keyboard cursor
      let suggItems = [];
      let debounceTimer;
      function highlight(text, query){
        try {
          const re = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig');
          return text.replace(re, '<mark>$1</mark>');
        } catch { return text; }
      }
      function fieldIcon(field){
        const map = {
          type: 'fas fa-file',
          employee_name: 'fas fa-user',
          department: 'fas fa-building',
          status: 'fas fa-flag',
          current_holder: 'fas fa-user-tie',
          end_location: 'fas fa-map-marker-alt',
          ocr_content: 'fas fa-search'
        };
        return map[field] || 'fas fa-tag';
      }
      function addSectionHeader(title, actionEl){
        const h = document.createElement('div');
        h.className = 'suggest-section';
        const span = document.createElement('span');
        span.textContent = title;
        h.appendChild(span);
        if (actionEl) h.appendChild(actionEl);
        suggWrap.appendChild(h);
      }
      function addSuggestionItem(s, query){
        const div = document.createElement('div');
        div.className = 'search-suggestion-item';
        div.innerHTML = `<i class="${fieldIcon(s.field)}" style="width:18px;color:#94a3b8;margin-right:8px;"></i>${highlight(s.label, query)} <small style="color:#94a3b8;">(${s.field.replace('_',' ')})</small>`;
        div.addEventListener('mousedown', (e) => {
          e.preventDefault();
          searchInput.value = s.label;
          saveRecentSearch(s.label);
          persistFiltersToUrl();
          if (pageInfoSpan && pageInfoSpan.dataset.serverside === '1') {
            navigateWithCurrentFilters(true);
          } else {
            applyFiltersAndSearch();
          }
          suggWrap.classList.remove('show');
        });
        suggWrap.appendChild(div);
      }
      function groupByField(list){
        const order = ['type','employee_name','department','status','current_holder','end_location','ocr_content'];
        const groups = {};
        list.forEach(s=>{ if(!groups[s.field]) groups[s.field]=[]; groups[s.field].push(s); });
        return order.filter(f=>groups[f]?.length).map(f=>({ field:f, items:groups[f] }));
      }
      function renderSuggestions(list, query){
        suggWrap.innerHTML = '';
        suggItems = list;
        suggIndex = -1;
        if (!list || list.length === 0){ suggWrap.classList.remove('show'); return; }
        // Recent only mode (list with field==='recent')
        const hasRecentOnly = list.length && list.every(i=>i.field==='recent');
        if (hasRecentOnly){
          const btn = document.createElement('button');
          btn.className = 'suggest-clear';
          btn.textContent = 'Clear';
          btn.addEventListener('click', (e)=>{ e.preventDefault(); clearRecentSearches(); suggWrap.classList.remove('show'); });
          addSectionHeader('Recent Searches', btn);
          list.forEach(s=> addSuggestionItem(s, query));
          suggWrap.classList.add('show');
          return;
        }
        // Group by field with headers
        const groups = groupByField(list);
        groups.forEach(g=>{
          const titleMap = { type:'Type', employee_name:'Employee', department:'Department', status:'Status', current_holder:'Current Holder', end_location:'End Location', ocr_content:'OCR Text' };
          addSectionHeader(titleMap[g.field] || g.field);
          g.items.forEach(s=> addSuggestionItem(s, query));
        });
        suggWrap.classList.add('show');
      }
      function fetchSuggestions(q){
        if (!q || q.length < 2){ suggWrap.classList.remove('show'); return; }
        fetch(`tracking.php?action=search_suggest&q=${encodeURIComponent(q)}`, { cache: 'no-store' })
          .then(r => r.json())
          .then(d => renderSuggestions(d.suggestions || [], q))
          .catch(() => { suggWrap.classList.remove('show'); });
      }
      function loadRecent(){
        try {
          const raw = localStorage.getItem('recentSearches');
          const arr = raw ? JSON.parse(raw) : [];
          const items = arr.slice(0,8).map(v=>({ label:v, field:'recent' }));
          if (items.length>0) { renderSuggestions(items, ''); }
        } catch {}
      }
      function clearRecentSearches(){
        try { localStorage.removeItem('recentSearches'); } catch {}
      }
      function saveRecentSearch(q){
        try {
          const key = 'recentSearches';
          const raw = localStorage.getItem(key);
          let arr = raw ? JSON.parse(raw) : [];
          q = (q||'').trim();
          if (!q) return;
          arr = [q, ...arr.filter(x=>x.toLowerCase()!==q.toLowerCase())].slice(0,20);
          localStorage.setItem(key, JSON.stringify(arr));
        } catch {}
      }
      searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          if (q.length>=2) fetchSuggestions(q); else loadRecent();
        }, 220);
      });
      searchInput.addEventListener('focus', () => {
        const q = searchInput.value.trim();
        if (q.length < 2) loadRecent();
      });
      searchInput.addEventListener('keydown', (e) => {
        if (!suggWrap.classList.contains('show')) return;
        const items = Array.from(suggWrap.querySelectorAll('.search-suggestion-item'));
        if (e.key === 'ArrowDown') { e.preventDefault(); suggIndex = Math.min(items.length - 1, suggIndex + 1); items.forEach((el,i)=> el.style.background = i===suggIndex ? '#e6fffb' : ''); }
        if (e.key === 'ArrowUp') { e.preventDefault(); suggIndex = Math.max(0, suggIndex - 1); items.forEach((el,i)=> el.style.background = i===suggIndex ? '#e6fffb' : ''); }
        if (e.key === 'Enter' && suggIndex >= 0) { e.preventDefault(); items[suggIndex].dispatchEvent(new MouseEvent('mousedown')); }
        if (e.key === 'Escape') { suggWrap.classList.remove('show'); }
      });
      document.addEventListener('click', (e) => {
        const within = e.target.closest('.search-wrap');
        if (!within) suggWrap.classList.remove('show');
      });


    // Load notifications from server with localStorage persistence
    async function loadNotificationsFromServer() {
      try {
        // Check if notifications were cleared recently (within last hour)
        const clearedTime = localStorage.getItem('notificationsClearedTime');
        const wasCleared = localStorage.getItem('notificationsCleared') === 'true';
        const oneHour = 60 * 60 * 1000;
        
        if (wasCleared && clearedTime && (Date.now() - parseInt(clearedTime)) < oneHour) {
          // Still within cleared period, show empty state
          notificationBadge.textContent = '0';
          notificationBadge.style.display = 'none';
          return;
        }
        
        // Clear the cleared flag if more than an hour has passed
        if (wasCleared && clearedTime && (Date.now() - parseInt(clearedTime)) >= oneHour) {
          localStorage.removeItem('notificationsCleared');
          localStorage.removeItem('notificationsClearedTime');
        }
        
        const res = await fetch(`tracking.php?action=notifications&_ts=${Date.now()}`, { cache: 'no-store' });
        const data = await res.json();
        const list = data.notifications || [];

        // Only redraw notifications if changed (prevents blinking)
        const sig = JSON.stringify({ c: data.count || list.length, ids: (list || []).map(x => x.id) });
        if (window.__notifSig && window.__notifSig === sig) {
          return;
        }
        window.__notifSig = sig;

        // Update sync indicator (throttled)
        try {
          const now = Date.now();
          if (!window.__lastSyncUi || (now - window.__lastSyncUi) > 5000) {
            window.__lastSyncUi = now;
            const el = document.getElementById('realtimeSyncIndicator');
            const t = document.getElementById('realtimeSyncTime');
            if (el && t) {
              el.style.display = 'block';
              t.textContent = new Date().toLocaleTimeString();
            }
          }
        } catch (_) {}
        
        notificationDropdown.querySelectorAll('.notification-item').forEach(el => el.remove());
        notificationBadge.textContent = String(data.count || list.length);
        notificationBadge.style.display = (data.count || list.length) > 0 ? 'inline-flex' : 'none';
        
        if (list.length === 0) {
          const noNotif = document.createElement('div');
          noNotif.className = 'notification-item';
          noNotif.innerHTML = `<div class="notification-content" style="text-align:center; padding:20px; color:var(--text-light);">No notifications available</div>`;
          notificationDropdown.appendChild(noNotif);
        } else {
          list.forEach(n => {
            const item = document.createElement('div');
            item.className = 'notification-item' + (n.isMobile ? ' unread' : '');
            const iconClass = n.icon || 'fa-file-alt';
            const mobileIndicator = n.isMobile ? '<i class="fas fa-mobile-alt" style="color:#4CAF50;margin-left:4px;"></i>' : '';
            item.innerHTML = `
              <div class="notification-title">
                <span><i class="fas ${iconClass}" style="margin-right:6px;"></i>${n.title}${mobileIndicator}</span>
                <span class="notification-time">${n.time || ''}</span>
              </div>
              <div class="notification-content">${n.content}</div>
            `;
            notificationDropdown.appendChild(item);
          });
        }
      } catch(err) {
        console.error('Failed to load notifications', err);
      }
    }

    // Pagination event listeners
    if (prevPageBtn) prevPageBtn.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage--;
        renderDocuments(filteredDocuments);
      }
    });

    if (nextPageBtn) nextPageBtn.addEventListener('click', () => {
      const totalPages = Math.ceil(filteredDocuments.length / itemsPerPage);
      if (currentPage < totalPages) {
        currentPage++;
        renderDocuments(filteredDocuments);
      }
    });

      // Initially apply filters and search to render documents
      applyFiltersAndSearch();
      
      // Load notifications on page load
      loadNotificationsFromServer();
      
      // Auto-refresh notifications every 15 seconds (paused when tab hidden)
      setInterval(() => { if (!document.hidden) loadNotificationsFromServer(); }, 15000);

      // Auto-refresh tracking data as a fallback when Firestore realtime is blocked
      // Uses ETag conditional requests to skip redundant payloads (304 Not Modified)
      window.__trackingEtag = null;
      async function loadTrackingFromServer() {
        try {
          const url = `tracking.php?action=tracking_latest&limit=200`;
          const headers = {};
          if (window.__trackingEtag) {
            headers['If-None-Match'] = window.__trackingEtag;
          }
          const res = await fetch(url, { cache: 'no-cache', headers });

          // 304 = nothing changed, skip processing entirely
          if (res.status === 304) return;

          // Store the ETag for next conditional request
          const etag = res.headers.get('ETag');
          if (etag) window.__trackingEtag = etag;

          const payload = await res.json();
          const list = (payload && payload.success && Array.isArray(payload.docs)) ? payload.docs : [];
          if (!list.length) return;

          // IMPORTANT: The table filters/renders from the `documents` array.
          // Update it in-place (never reassign), so the UI always reflects latest rows.
          const docs = (typeof documents !== 'undefined' && Array.isArray(documents)) ? documents : window.trackingDocuments;
          if (!Array.isArray(docs)) return;

          // Only update UI if data changed (prevents table blinking)
          const newSig = JSON.stringify((list || []).map(r => [r.id, r.status, r.current_holder, r.end_location, Math.floor(((r.overdue_seconds || 0) / 60))]));
          if (window.__trackingSig && window.__trackingSig === newSig) {
            return;
          }
          window.__trackingSig = newSig;

          docs.splice(0, docs.length, ...list);
          window.trackingDocuments = docs;
          window.documents = docs;

          // Update sync indicator (throttled)
          try {
            const now = Date.now();
            if (!window.__lastSyncUi || (now - window.__lastSyncUi) > 5000) {
              window.__lastSyncUi = now;
              const el = document.getElementById('realtimeSyncIndicator');
              const t = document.getElementById('realtimeSyncTime');
              if (el && t) {
                el.style.display = 'block';
                t.textContent = new Date().toLocaleTimeString();
              }
            }
          } catch (_) {}

          if (typeof window.applyFiltersAndSearch === 'function') {
            requestAnimationFrame(() => window.applyFiltersAndSearch());
          }
        } catch (_) {
        }
      }

      // Update "Time in Dept" every minute (client-side), even if no Firestore/MySQL change happens.
      // This keeps the overdue/time-in-dept badges fresh without extra network requests.
      function refreshTimeInDeptEveryMinute() {
        const docs = (typeof documents !== 'undefined' && Array.isArray(documents)) ? documents : window.trackingDocuments;
        if (!Array.isArray(docs) || typeof window.applyFiltersAndSearch !== 'function') return;

        const formatMeta = (status, sourceDateStr) => {
          const s = String(status || '').trim().toLowerCase();
          if (['archived', 'completed', 'approved'].includes(s)) {
            return { overdue_seconds: 0, overdue_label: 'Cleared', overdue_full_label: 'Cleared', overdue_state: 'cleared' };
          }

          const ts = sourceDateStr ? Date.parse(sourceDateStr) : NaN;
          if (!isFinite(ts)) {
            return { overdue_seconds: 0, overdue_label: '—', overdue_full_label: 'No timestamp available', overdue_state: 'na' };
          }

          const diffSeconds = Math.max(0, Math.floor((Date.now() - ts) / 1000));
          const minutes = Math.floor(diffSeconds / 60);
          const days = Math.floor(minutes / 1440);
          const hours = Math.floor((minutes % 1440) / 60);
          const mins = minutes % 60;

          const shortParts = [];
          if (days > 0) shortParts.push(days + 'd');
          if (hours > 0 && shortParts.length < 2) shortParts.push(hours + 'h');
          if (days === 0 && mins > 0 && shortParts.length < 2) shortParts.push(mins + 'm');
          if (shortParts.length === 0) shortParts.push('<1m');

          const fullParts = [];
          if (days > 0) fullParts.push(days + ' day' + (days === 1 ? '' : 's'));
          if (hours > 0) fullParts.push(hours + ' hour' + (hours === 1 ? '' : 's'));
          if (mins > 0) fullParts.push(mins + ' minute' + (mins === 1 ? '' : 's'));
          if (fullParts.length === 0) fullParts.push('Less than a minute');

          let state = 'ok';
          if (diffSeconds >= 5 * 86400) state = 'late';
          else if (diffSeconds >= 4 * 86400) state = 'warn';

          return {
            overdue_seconds: diffSeconds,
            overdue_label: shortParts.join(' '),
            overdue_full_label: fullParts.join(' '),
            overdue_state: state,
          };
        };

        let changed = false;
        for (const d of docs) {
          if (!d) continue;
          const source = d.created_at || d.date_submitted || '';
          const meta = formatMeta(d.status, source);
          const prevMin = Math.floor(((d.overdue_seconds || 0) / 60));
          const nextMin = Math.floor(((meta.overdue_seconds || 0) / 60));

          if (prevMin !== nextMin || !d.overdue_label || !d.overdue_state) {
            d.overdue_seconds = meta.overdue_seconds;
            d.overdue_label = meta.overdue_label;
            d.overdue_full_label = meta.overdue_full_label;
            d.overdue_state = meta.overdue_state;
            changed = true;
          }
        }

        if (changed) {
          try {
            requestAnimationFrame(() => window.applyFiltersAndSearch());
          } catch (_) {}
        }
      }

      // Run once immediately, then every 10 seconds (paused when tab hidden)
      loadTrackingFromServer();
      setInterval(() => { if (!document.hidden) loadTrackingFromServer(); }, 10000);

      // Recompute time-in-dept once a minute (paused when tab hidden)
      refreshTimeInDeptEveryMinute();
      setInterval(() => { if (!document.hidden) refreshTimeInDeptEveryMinute(); }, 60000);
      
      // Load sidebar badges (centralized, paused when tab hidden)
      if (typeof window.loadSidebarBadges === 'function') {
        window.loadSidebarBadges();
        setInterval(() => { if (!document.hidden) window.loadSidebarBadges(); }, 30000);
      }

      // Resume data refresh immediately when tab becomes visible
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
          loadTrackingFromServer();
          loadNotificationsFromServer();
        }
      });

      // Check for status messages from PHP redirection
      const urlParams = new URLSearchParams(window.location.search);
      const status = urlParams.get('status');
      if (status) {
          if (status === 'added') {
              showToast('Document added successfully!', 'success');
          } else if (status === 'updated') {
              showToast('Document updated successfully!', 'success');
          } else if (status === 'deleted') {
              showToast('Document deleted successfully!', 'success');
          } else if (status === 'routed') {
              showToast('Document routed successfully!', 'success');
          } else if (status === 'archived') {
              showToast('Document archived successfully!', 'success');
          } else if (status === 'archive_wait') {
              showToast('Cannot archive yet — not all departments have completed this document.', 'warning');
          } else if (status === 'error' || status === 'delete_error' || status === 'route_error' || status === 'archive_error') {
              showToast('An error occurred. Please try again.', 'error');
          }
          // Remove status parameter from URL to prevent re-showing toast on refresh
          urlParams.delete('status');
          const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
          window.history.replaceState({}, document.title, newUrl);
      }
      
      // ============================================
      // OCR SEARCH FUNCTIONALITY
      // ============================================
      const ocrSearchBtn = document.getElementById('ocrSearchBtn');
      const ocrSearchResults = document.getElementById('ocrSearchResults');
      let ocrSearchMode = false;
      let ocrSearchTimeout = null;
      
      if (ocrSearchBtn) {
        ocrSearchBtn.addEventListener('click', () => {
          ocrSearchMode = !ocrSearchMode;
          window.__trackingOcrSearchMode = ocrSearchMode;
          ocrSearchBtn.classList.toggle('active', ocrSearchMode);
          searchInput.placeholder = ocrSearchMode ? 'Search document content (OCR)...' : 'Search documents...';
          
          if (ocrSearchMode && searchInput.value.trim().length >= 2) {
            performOcrSearch(searchInput.value.trim());
          } else {
            // Leaving OCR mode (or query too short) restores normal filtering.
            window.__trackingOcrResultDocIds = null;
            if (typeof window.applyFiltersAndSearch === 'function') {
              window.applyFiltersAndSearch();
            }
            ocrSearchResults.style.display = 'none';
          }
        });
      }
      
      // Perform OCR search against server
      async function performOcrSearch(query) {
        if (!ocrSearchMode || query.length < 2) {
          window.__trackingOcrResultDocIds = null;
          if (typeof window.applyFiltersAndSearch === 'function') {
            window.applyFiltersAndSearch();
          }
          ocrSearchResults.style.display = 'none';
          return;
        }
        
        ocrSearchResults.style.display = 'block';
        ocrSearchResults.innerHTML = '<div class="ocr-searching"><i class="fas fa-spinner fa-spin"></i> Searching document content...</div>';
        
        try {
          const response = await fetch(`tracking.php?action=ocr_search&q=${encodeURIComponent(query)}&limit=10`);
          const data = await response.json();
          
          if (!data.success) {
            // Keep the current list as-is on failure.
            ocrSearchResults.innerHTML = '<div class="ocr-no-results">Search failed. Please try again.</div>';
            return;
          }
          
          if (data.results.length === 0) {
            window.__trackingOcrResultDocIds = new Set();
            if (typeof window.applyFiltersAndSearch === 'function') {
              window.applyFiltersAndSearch();
            }
            ocrSearchResults.innerHTML = '<div class="ocr-no-results"><i class="fas fa-search"></i> No documents found matching "' + escapeHtml(query) + '"</div>';
            return;
          }

          window.__trackingOcrResultDocIds = new Set((data.results || []).map(r => String(r.id)));
          if (typeof window.applyFiltersAndSearch === 'function') {
            window.applyFiltersAndSearch();
          }
          
          let html = '';
          data.results.forEach(result => {
            const snippet = result.snippet || '';
            const highlightedSnippet = highlightSearchTerms(snippet, query);
            const pagesInfo = result.matching_pages && result.matching_pages.length > 0 
              ? `<div class="ocr-result-pages"><i class="fas fa-file-alt"></i> Found on page ${result.matching_pages.join(', ')}</div>` 
              : '';
            
            html += `
              <div class="ocr-result-item" onclick="window.scrollToDocumentRow(${result.id}); window.hideTrackingOcrResults();">
                <div class="ocr-result-header">
                  <span class="ocr-result-type">${escapeHtml(result.type || 'Document')}</span>
                  <span class="ocr-result-meta">${escapeHtml(result.department || '')} • ${escapeHtml(result.status || '')}</span>
                </div>
                <div class="ocr-result-meta">${escapeHtml(result.name || '')}</div>
                ${snippet ? `<div class="ocr-result-snippet">${highlightedSnippet}</div>` : ''}
                ${pagesInfo}
              </div>
            `;
          });
          
          ocrSearchResults.innerHTML = html;
          
        } catch (error) {
          console.error('OCR search error:', error);
          ocrSearchResults.innerHTML = '<div class="ocr-no-results">Search error. Please try again.</div>';
        }
      }
      
      // Helper to highlight search terms in snippet
      function highlightSearchTerms(text, query) {
        const escaped = escapeHtml(text);
        const terms = query.toLowerCase().split(/\s+/).filter(t => t.length >= 2);
        let result = escaped;
        terms.forEach(term => {
          const regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
          result = result.replace(regex, '<mark>$1</mark>');
        });
        return result;
      }
      
      // Helper to escape HTML
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
      
      // Scroll to document row and highlight it when clicking OCR result
      function hideTrackingOcrResults() {
        const el = document.getElementById('ocrSearchResults');
        if (el) el.style.display = 'none';
      }

      function waitForAnyElement(selectors, timeoutMs) {
        const started = Date.now();
        return new Promise(resolve => {
          const tick = () => {
            for (const sel of selectors) {
              const el = document.querySelector(sel);
              if (el) return resolve(el);
            }
            if ((Date.now() - started) >= timeoutMs) return resolve(null);
            setTimeout(tick, 50);
          };
          tick();
        });
      }

      async function scrollToDocumentRow(docId) {
        // In OCR mode, narrow the list to just this document.
        window.__trackingOcrSearchMode = true;
        window.__trackingOcrResultDocIds = new Set([String(docId)]);
        if (typeof window.applyFiltersAndSearch === 'function') {
          window.applyFiltersAndSearch();
        }

        // Wait for the re-render (table render has a 300ms delay)
        const selectors = [
          `tr[data-id="${docId}"]`,
          `.doc-list-item[data-id="${docId}"]`,
          `.doc-grid-card[data-id="${docId}"]`,
        ];
        const el = await waitForAnyElement(selectors, 1500);
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          el.classList.add('ocr-highlight-row');
          setTimeout(() => {
            el.classList.remove('ocr-highlight-row');
          }, 3000);
        } else {
          viewDocumentInfo(docId);
        }
      }

      // Inline onclick handlers require globals.
      window.scrollToDocumentRow = scrollToDocumentRow;
      window.hideTrackingOcrResults = hideTrackingOcrResults;
      
      // Trigger OCR search on input when in OCR mode
      searchInput.addEventListener('input', () => {
        if (ocrSearchMode) {
          clearTimeout(ocrSearchTimeout);
          ocrSearchTimeout = setTimeout(() => {
            performOcrSearch(searchInput.value.trim());
          }, 300);
        }
      });
      
      // Close OCR results when clicking outside
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-wrap')) {
          ocrSearchResults.style.display = 'none';
        }
      });
      
      // Show OCR results on focus if in OCR mode
      searchInput.addEventListener('focus', () => {
        if (ocrSearchMode && searchInput.value.trim().length >= 2) {
          performOcrSearch(searchInput.value.trim());
        }
      });
    });
  </script>
</body>
</html>
