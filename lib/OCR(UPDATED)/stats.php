<?php
// Avoid emitting UI output for API endpoints
$__api_action = isset($_GET['action']) ? $_GET['action'] : null;
require_once 'config.php';
require_once 'settings_util.php';
if (!isset($__api_action) || $__api_action === null) {
    require_once 'user_profile_widget.php';
}

require_once 'security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$__isAdmin = Security::is_admin();
session_write_close(); // Release session lock early

// Create connection (use shared config)
$connection = null;
try {
  $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
  $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
} catch (mysqli_sql_exception $e) {
  http_response_code(503);
  if (isset($__api_action) && $__api_action !== null) {
    header('Content-Type: application/json');
    echo json_encode([
      'success' => false,
      'error' => 'Database unavailable',
      'message' => 'MySQL connection refused. Start MySQL in XAMPP or verify DB_HOST/DB_PORT in config.php.'
    ]);
    exit;
  }

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
  echo '<div style="color:#475569">Stats page cannot connect to MySQL right now.</div>';
  echo '<ul>';
  echo '<li>Open XAMPP Control Panel and start <b>MySQL</b>.</li>';
  echo '<li>Verify <code>DB_HOST</code> and <code>DB_PORT</code> in <code>lib/OCR(UPDATED)/config.php</code> (current: ' . $safeHost . ':' . $safePort . ').</li>';
  echo '<li>If MySQL uses another port (e.g. 3307), set <code>DB_PORT</code> accordingly.</li>';
  echo '</ul>';
  echo '<a class="btn" href="stats.php">Retry</a>';
  echo '</div></body></html>';
  exit;
}

// Check connection (when mysqli is not in strict exception mode)
if (!$connection || $connection->connect_error) {
  http_response_code(503);
  die('Database unavailable');
}

function stats_table_exists(mysqli $connection, string $tableName): bool {
  $safe = $connection->real_escape_string($tableName);
  $res = $connection->query("SHOW TABLES LIKE '{$safe}'");
  if (!$res) {
    return false;
  }
  $exists = ($res->num_rows > 0);
  $res->free();
  return $exists;
}

function stats_column_exists(mysqli $connection, string $tableName, string $columnName): bool {
  $t = $connection->real_escape_string($tableName);
  $c = $connection->real_escape_string($columnName);
  $res = $connection->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  if (!$res) {
    return false;
  }
  $exists = ($res->num_rows > 0);
  $res->free();
  return $exists;
}

// API: reset Department Activity Summary (non-destructive)
// This stores a timestamp in app_settings and stats will only count history after it.
if ($__api_action === 'reset_department_activity') {
  $confirm = isset($_POST['confirm']) ? (string)$_POST['confirm'] : (string)($_GET['confirm'] ?? '');
  if ($confirm !== '1') {
    header('Content-Type: application/json');
    echo json_encode([
      'success' => false,
      'message' => 'Confirmation required. Call with confirm=1 (POST preferred).'
    ]);
    $connection->close();
    exit;
  }

  $now = date('Y-m-d H:i:s');
  $k = 'dept_activity_reset_at';
  $stmt = $connection->prepare("INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
  if (!$stmt) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to prepare reset query']);
    $connection->close();
    exit;
  }
  $stmt->bind_param('ss', $k, $now);
  $ok = $stmt->execute();
  $stmt->close();

  header('Content-Type: application/json');
  echo json_encode(['success' => (bool)$ok, 'reset_at' => $now]);
  $connection->close();
  exit;
}
// Department isolation: non-admin users see only documents involving their department.
// Department isolation: non-admin users see only documents involving their department.
// route_step gating ensures docs only appear once the route has reached/passed the department.
$__deptFilter = '';
$__deptFilterArchive = '';
if (!$__isAdmin && !empty($_SESSION['user_department'])) {
    $__deptEsc  = $connection->real_escape_string($_SESSION['user_department']);
    $__udUpper  = strtoupper(trim($__deptEsc));
    $__deptFilter = " AND ("
        . "UPPER(TRIM(tracking.department)) = '$__udUpper'"
        . " OR UPPER(TRIM(tracking.current_holder)) = '$__udUpper'"
        . " OR UPPER(TRIM(tracking.end_location)) = '$__udUpper'"
        . " OR (FIND_IN_SET(UPPER(TRIM('$__deptEsc')), UPPER(REPLACE(tracking.routing_queue, ' ', ''))) > 0"
        . " AND CAST(COALESCE(tracking.route_step, 0) AS UNSIGNED) >= (FIND_IN_SET(UPPER(TRIM('$__deptEsc')), UPPER(REPLACE(tracking.routing_queue, ' ', ''))) - 1))"
        . ")";
    // Archive table only has department & last_department (no routing columns)
    $__deptFilterArchive = " AND (UPPER(TRIM(archive.department)) = '$__udUpper' OR UPPER(TRIM(archive.last_department)) = '$__udUpper')";
    $__deptFilterBare = " AND ("
        . "UPPER(TRIM(department)) = '$__udUpper'"
        . " OR UPPER(TRIM(current_holder)) = '$__udUpper'"
        . " OR UPPER(TRIM(end_location)) = '$__udUpper'"
        . " OR (FIND_IN_SET(UPPER(TRIM('$__deptEsc')), UPPER(REPLACE(routing_queue, ' ', ''))) > 0"
        . " AND CAST(COALESCE(route_step, 0) AS UNSIGNED) >= (FIND_IN_SET(UPPER(TRIM('$__deptEsc')), UPPER(REPLACE(routing_queue, ' ', ''))) - 1))"
        . ")";
} else {
    $__deptFilterBare = '';
}

// Primary documents query reused for UI and some APIs
$documents = [];
if (!$__api_action) {
    $query = "SELECT id, type, employee_name, date_submitted, current_holder, end_location, status, department, file_type_icon, 
            date_submitted as mobile_timestamp, 
            '' as ocr_content, 
            0 as file_size, 
            '' as user_email 
            FROM tracking WHERE 1=1 $__deptFilter ORDER BY date_submitted DESC";
    $result = $connection->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['display_date'] = date("Y-m-d", strtotime($row['date_submitted']));
            $documents[] = $row;
        }
        $result->free();
    }
}


// Calculate summary statistics for documents
$totalDocuments = count($documents);

// Get actual status counts from database for better performance
$statusQuery = "SELECT status, COUNT(*) as count FROM tracking WHERE 1=1 $__deptFilter GROUP BY status";
$statusResult = $connection->query($statusQuery);

$statusCounts = [
    'Completed' => 0,
    'Pending' => 0,
    'Archived' => 0,
    'In Review' => 0,
    'In Progress' => 0,
    'Rejected' => 0
];

if ($statusResult) {
    while ($row = $statusResult->fetch_assoc()) {
        $status = $row['status'];
        $count = (int)$row['count'];
        $statusCounts[$status] = $count;
    }
    $statusResult->free();
}

// Calculate totals for the summary cards
$completedDocuments = (int)$statusCounts['Completed'] + (int)$statusCounts['Archived'];
$pendingDocuments = (int)$statusCounts['Pending'] + (int)$statusCounts['In Review'] + (int)$statusCounts['In Progress'];

// Overdue documents share tracking.php logic: still active + 4 days and above
$overdueDocuments = 0;
$overdueSql = "SELECT COUNT(*) AS count
               FROM tracking
               WHERE COALESCE(status,'') NOT IN ('Completed','Approved','Archived')
                 AND DATEDIFF(CURDATE(), DATE(COALESCE(created_at, date_submitted))) >= 4 $__deptFilter";
$overdueResult = $connection->query($overdueSql);
if ($overdueResult && $row = $overdueResult->fetch_assoc()) {
    $overdueDocuments = (int)$row['count'];
    $overdueResult->free();
}

// Ensure variables are integers (safety check)
$completedDocuments = (int)$completedDocuments;
$pendingDocuments = (int)$pendingDocuments;

// ===== Additional KPI Metrics (for panel requirements) =====
// 1) Average processed documents per day
// 2) Average processing time per document (end-to-end)
// Uses document_history when available; otherwise falls back to coarse estimates.

function stats_fmt_number_1dp($n) {
  if ($n === null) return '--';
  return number_format((float)$n, 1);
}

function stats_fmt_duration_short($seconds) {
  if ($seconds === null) return '--';
  $s = (int)round((float)$seconds);
  if ($s < 0) $s = 0;
  $days = intdiv($s, 86400);
  $s = $s % 86400;
  $hours = intdiv($s, 3600);
  $s = $s % 3600;
  $mins = intdiv($s, 60);
  if ($days > 0) {
    return $days . 'd ' . $hours . 'h';
  }
  if ($hours > 0) {
    return $hours . 'h ' . $mins . 'm';
  }
  return $mins . 'm';
}

function stats_delta_pct($today, $yesterday) {
  $t = (float)($today ?? 0);
  $y = (float)($yesterday ?? 0);
  if ($y <= 0) return null;
  return (($t - $y) / $y) * 100.0;
}

$avgProcessedPerDay = null;
$processedToday = 0;
$processedYesterday = 0;
$avgProcessTimeSeconds = null;
$avgProcessTimeTodaySeconds = null;
$avgProcessTimeYesterdaySeconds = null;

$useHistory = stats_table_exists($connection, 'document_history');

// Window for "average per day" computations
$avgWindowDays = 30;

if ($useHistory) {
  // Processed = documents that transitioned to completed/approved/archived (distinct doc_id)
  $sqlDailyProcessed = "SELECT DATE(created_at) AS d, COUNT(DISTINCT doc_id) AS c\n" .
    "FROM document_history\n" .
    "WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
    "  AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$avgWindowDays} DAY)\n" .
    "GROUP BY DATE(created_at)";
  $resDaily = $connection->query($sqlDailyProcessed);
  $sumProcessed = 0;
  if ($resDaily) {
    while ($r = $resDaily->fetch_assoc()) {
      $sumProcessed += (int)($r['c'] ?? 0);
    }
    $resDaily->free();
  }
  $avgProcessedPerDay = $sumProcessed / (float)$avgWindowDays;

  $sqlProcessedToday = "SELECT COUNT(DISTINCT doc_id) AS c\n" .
    "FROM document_history\n" .
    "WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
    "  AND DATE(created_at) = CURDATE()";
  if ($r = $connection->query($sqlProcessedToday)) {
    if ($row = $r->fetch_assoc()) {
      $processedToday = (int)($row['c'] ?? 0);
    }
    $r->free();
  }

  $sqlProcessedYesterday = "SELECT COUNT(DISTINCT doc_id) AS c\n" .
    "FROM document_history\n" .
    "WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
    "  AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
  if ($r = $connection->query($sqlProcessedYesterday)) {
    if ($row = $r->fetch_assoc()) {
      $processedYesterday = (int)($row['c'] ?? 0);
    }
    $r->free();
  }

  // Average processing time = (first event time) -> (completion event time)
  // Overall average for recently completed documents
  $sqlAvgTime = "SELECT AVG(TIMESTAMPDIFF(SECOND, s.start_at, c.completed_at)) AS avg_seconds\n" .
    "FROM (\n" .
    "  SELECT doc_id, MIN(created_at) AS start_at\n" .
    "  FROM document_history\n" .
    "  GROUP BY doc_id\n" .
    ") s\n" .
    "JOIN (\n" .
    "  SELECT doc_id, MAX(created_at) AS completed_at\n" .
    "  FROM document_history\n" .
    "  WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
    "  GROUP BY doc_id\n" .
    ") c ON c.doc_id = s.doc_id\n" .
    "WHERE c.completed_at >= DATE_SUB(NOW(), INTERVAL {$avgWindowDays} DAY)\n" .
    "  AND c.completed_at IS NOT NULL AND s.start_at IS NOT NULL";
  if ($r = $connection->query($sqlAvgTime)) {
    if ($row = $r->fetch_assoc()) {
      $v = $row['avg_seconds'];
      $avgProcessTimeSeconds = ($v === null) ? null : (float)$v;
    }
    $r->free();
  }

  // Today's avg processing time
  $sqlAvgTimeToday = "SELECT AVG(TIMESTAMPDIFF(SECOND, s.start_at, c.completed_at)) AS avg_seconds\n" .
    "FROM (\n" .
    "  SELECT doc_id, MIN(created_at) AS start_at\n" .
    "  FROM document_history\n" .
    "  GROUP BY doc_id\n" .
    ") s\n" .
    "JOIN (\n" .
    "  SELECT doc_id, MAX(created_at) AS completed_at\n" .
    "  FROM document_history\n" .
    "  WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
    "  GROUP BY doc_id\n" .
    ") c ON c.doc_id = s.doc_id\n" .
    "WHERE DATE(c.completed_at) = CURDATE()\n" .
    "  AND c.completed_at IS NOT NULL AND s.start_at IS NOT NULL";
  if ($r = $connection->query($sqlAvgTimeToday)) {
    if ($row = $r->fetch_assoc()) {
      $v = $row['avg_seconds'];
      $avgProcessTimeTodaySeconds = ($v === null) ? null : (float)$v;
    }
    $r->free();
  }

  // Yesterday's avg processing time
  $sqlAvgTimeYesterday = "SELECT AVG(TIMESTAMPDIFF(SECOND, s.start_at, c.completed_at)) AS avg_seconds\n" .
    "FROM (\n" .
    "  SELECT doc_id, MIN(created_at) AS start_at\n" .
    "  FROM document_history\n" .
    "  GROUP BY doc_id\n" .
    ") s\n" .
    "JOIN (\n" .
    "  SELECT doc_id, MAX(created_at) AS completed_at\n" .
    "  FROM document_history\n" .
    "  WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
    "  GROUP BY doc_id\n" .
    ") c ON c.doc_id = s.doc_id\n" .
    "WHERE DATE(c.completed_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)\n" .
    "  AND c.completed_at IS NOT NULL AND s.start_at IS NOT NULL";
  if ($r = $connection->query($sqlAvgTimeYesterday)) {
    if ($row = $r->fetch_assoc()) {
      $v = $row['avg_seconds'];
      $avgProcessTimeYesterdaySeconds = ($v === null) ? null : (float)$v;
    }
    $r->free();
  }
} else {
  // Fallbacks (coarse): use tracking status and date_submitted as the day.
  $sqlAvgProcessedFallback = "SELECT COUNT(*) AS c\n" .
    "FROM tracking\n" .
    "WHERE COALESCE(status,'') IN ('Completed','Approved','Archived')\n" .
    "  AND DATE(COALESCE(created_at, date_submitted)) >= DATE_SUB(CURDATE(), INTERVAL {$avgWindowDays} DAY) $__deptFilter";
  if ($r = $connection->query($sqlAvgProcessedFallback)) {
    if ($row = $r->fetch_assoc()) {
      $avgProcessedPerDay = ((int)($row['c'] ?? 0)) / (float)$avgWindowDays;
    }
    $r->free();
  }

  $sqlProcessedTodayFallback = "SELECT COUNT(*) AS c FROM tracking WHERE COALESCE(status,'') IN ('Completed','Approved','Archived') AND DATE(COALESCE(created_at, date_submitted)) = CURDATE() $__deptFilter";
  if ($r = $connection->query($sqlProcessedTodayFallback)) {
    if ($row = $r->fetch_assoc()) {
      $processedToday = (int)($row['c'] ?? 0);
    }
    $r->free();
  }

  $sqlProcessedYesterdayFallback = "SELECT COUNT(*) AS c FROM tracking WHERE COALESCE(status,'') IN ('Completed','Approved','Archived') AND DATE(COALESCE(created_at, date_submitted)) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) $__deptFilter";
  if ($r = $connection->query($sqlProcessedYesterdayFallback)) {
    if ($row = $r->fetch_assoc()) {
      $processedYesterday = (int)($row['c'] ?? 0);
    }
    $r->free();
  }
  // Processing time cannot be accurately computed without history.
  $avgProcessTimeSeconds = null;
}

$processedDeltaPct = stats_delta_pct($processedToday, $processedYesterday);
$avgTimeDeltaPct = stats_delta_pct($avgProcessTimeTodaySeconds, $avgProcessTimeYesterdaySeconds);

// Departmental activity summary (used for analytics objective 5.2)
// IMPORTANT: This should reflect all departments involved in a document's lifecycle
// (e.g., CMO -> CACCO -> CPDO), not just the originating `tracking.department`.
// We derive this from document_history, counting both from_holder and to_holder.
$deptNames = [];
$deptCol = null;
if (stats_table_exists($connection, 'departments')) {
  if (stats_column_exists($connection, 'departments', 'name')) {
    $deptCol = 'name';
  } elseif (stats_column_exists($connection, 'departments', 'dept_name')) {
    $deptCol = 'dept_name';
  } elseif (stats_column_exists($connection, 'departments', 'department')) {
    $deptCol = 'department';
  }

  if ($deptCol !== null) {
    $deptColSafe = '`' . $connection->real_escape_string($deptCol) . '`';
    if ($deptRes = $connection->query("SELECT {$deptColSafe} AS dept FROM departments ORDER BY {$deptColSafe} ASC")) {
      while ($row = $deptRes->fetch_assoc()) {
        $v = strtoupper(trim((string)($row['dept'] ?? '')));
        if ($v !== '') {
          $deptNames[] = $v;
        }
      }
      $deptRes->free();
    }
  }
}

if (empty($deptNames)) {
  $deptNames = ['CACCO','CADO','CBO','CMO','CPDO','CTO','GSO'];
}
$resetAt = getAppSetting('dept_activity_reset_at', '');
$resetAt = is_string($resetAt) ? trim($resetAt) : '';
$departmentSummary = [];
$deptSummaryLabels = [];
$deptSummaryTotals = [];
$useReset = ($resetAt !== '' && strtotime($resetAt) !== false);

$deptSummarySql = "SELECT d.department, COALESCE(c.total_uploads, 0) AS total_uploads
  FROM (";

$deptSummarySql .= implode(' UNION ALL ', array_map(function($d) use ($connection) {
  $safe = $connection->real_escape_string(strtoupper(trim((string)$d)));
  return "SELECT '{$safe}' AS department";
}, $deptNames));

$deptSummarySql .= ") d
  LEFT JOIN (
    SELECT department, COUNT(*) AS total_uploads
    FROM (
      SELECT
        CASE
          WHEN from_holder IS NULL OR TRIM(from_holder) = '' THEN NULL
          ELSE UPPER(TRIM(from_holder))
        END AS department,
        created_at
      FROM document_history
      UNION ALL
      SELECT
        CASE
          WHEN to_holder IS NULL OR TRIM(to_holder) = '' THEN NULL
          ELSE UPPER(TRIM(to_holder))
        END AS department,
        created_at
      FROM document_history
    ) h
    WHERE department IS NOT NULL";

if ($useReset) {
  $deptSummarySql .= " AND created_at >= ?";
}

$deptSummarySql .= " GROUP BY department
  ) c ON c.department = d.department
  ORDER BY total_uploads DESC, d.department ASC";

if ($useReset) {
    if ($stmt = $connection->prepare($deptSummarySql)) {
        $stmt->bind_param('s', $resetAt);
        if ($stmt->execute()) {
            $deptResult = $stmt->get_result();
            while ($deptResult && ($row = $deptResult->fetch_assoc())) {
                $departmentSummary[] = $row;
                $deptSummaryLabels[] = $row['department'];
              $deptSummaryTotals[] = (int)($row['total_uploads'] ?? 0);
            }
        }
        $stmt->close();
    }
} else {
    if ($deptResult = $connection->query($deptSummarySql)) {
        while ($row = $deptResult->fetch_assoc()) {
            $departmentSummary[] = $row;
            $deptSummaryLabels[] = $row['department'];
            $deptSummaryTotals[] = (int)($row['total_uploads'] ?? 0);
        }
        $deptResult->free();
    }
}

// Debug information (uncomment to see what's in your database)
/*
echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px; border-radius: 5px;'>";
echo "<h4>Debug Information:</h4>";
echo "<p><strong>Total Documents:</strong> $totalDocuments</p>";
echo "<p><strong>Status Counts:</strong></p>";
foreach ($statusCounts as $status => $count) {
    echo "<p>- $status: $count</p>";
}
echo "<p><strong>Completed Documents:</strong> $completedDocuments (Completed: {$statusCounts['Completed']} + Archived: {$statusCounts['Archived']})</p>";
echo "<p><strong>Pending Documents:</strong> $pendingDocuments (Pending: {$statusCounts['Pending']} + In Review: {$statusCounts['In Review']} + In Progress: {$statusCounts['In Progress']})</p>";
echo "</div>";
*/

// Prepare data for charts (only include statuses with documents)
$pieChartLabels = [];
$pieChartData = [];
$pieChartColors = [];

$colorMap = [
    'Completed' => '#2a9d8f',
    'Pending' => '#f2994a', 
    'Archived' => '#6c757d',
    'In Review' => '#00BCD4',
    'In Progress' => '#e76f51',
    'Rejected' => '#e63946'
];

foreach ($statusCounts as $status => $count) {
    // Skip statuses that should not appear in the distribution chart
    if (in_array($status, ['Approved', 'Rejected'], true)) {
        continue;
    }
    if ($count > 0) { // Only include statuses that have documents
        $pieChartLabels[] = $status;
        $pieChartData[] = $count;
        $pieChartColors[] = $colorMap[$status] ?? '#95a5a6'; // Default color if status not in map
    }
}

// Real data for line chart (Document Processing Trend) - Current year by month (Jan..Dec)
$lineChartLabels = [];
$lineChartDataProcessed = [];
$currentYear = (int)date('Y');
for ($i = 1; $i <= 12; $i++) {
    $lineChartLabels[] = date('M', mktime(0,0,0,$i,1,$currentYear));
  $count = 0;
  if (stats_table_exists($connection, 'document_history')) {
    $sql_month = "SELECT COUNT(*) as count\n" .
           "FROM document_history\n" .
           "WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?\n" .
           "  AND (LOWER(IFNULL(action,'')) IN ('complete','archive','approve')\n" .
           "       OR LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived'))";
    if ($stmt = $connection->prepare($sql_month)) {
      $stmt->bind_param('ii', $currentYear, $i);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $count = (int)($row['count'] ?? 0);
        }
        if ($res) { $res->free(); }
      }
      $stmt->close();
    }
  } else {
    // Fallback: count created documents if history isn't available
    $sql_month = "SELECT COUNT(*) as count FROM tracking 
            WHERE YEAR(COALESCE(created_at, date_submitted)) = $currentYear
            AND MONTH(COALESCE(created_at, date_submitted)) = $i $__deptFilter";
    $result_month = $connection->query($sql_month);
    if ($result_month && $row = $result_month->fetch_assoc()) {
      $count = (int)$row['count'];
      $result_month->free();
    }
  }
  $lineChartDataProcessed[] = $count;
}

// Recent activities section removed - no longer needed

// Fetch documents from tracking table for document view integration
$trackingDocuments = [];
$trackingQuery = "SELECT id, type, employee_name, department, status, current_holder, COALESCE(created_at, date_submitted) AS submitted
            FROM tracking WHERE 1=1 $__deptFilter";
$trackingResult = $connection->query($trackingQuery);

if ($trackingResult) {
    while($row = $trackingResult->fetch_assoc()) {
        // Apply same status logic as tracking.php and dashboard.php
        if ($row['status'] === 'Archived') {
            $row['status'] = (rand(0, 1) == 0) ? 'In Review' : 'Pending';
        } elseif ($row['status'] === 'Rejected') {
            $row['status'] = (rand(0, 1) == 0) ? 'In Review' : 'Pending';
        }
        
        // Simulate history data like in tracking.php
        $history = [];
        $subWhen = isset($row['submitted']) && $row['submitted'] ? $row['submitted'] : '';
        $history[] = ['user' => $row['employee_name'], 'action' => 'Document Submitted', 'time' => $subWhen . ' - 09:00 AM', 'status' => 'completed'];

        if ($row['status'] === 'In Review') {
            $history[] = ['user' => $row['current_holder'], 'action' => 'Currently under review', 'time' => $subWhen . ' - 10:00 AM', 'status' => 'review'];
        } elseif ($row['status'] === 'Approved') {
            $history[] = ['user' => $row['current_holder'], 'action' => 'Document Approved', 'time' => $subWhen . ' - 02:00 PM', 'status' => 'completed'];
        } elseif ($row['status'] === 'Pending') {
            $history[] = ['user' => $row['current_holder'], 'action' => 'Awaiting Action', 'time' => $subWhen . ' - 10:00 AM', 'status' => 'pending'];
        }
        
        $row['history'] = $history;
        $trackingDocuments[] = $row;
    }
    $trackingResult->free();
}

// Filter documents by status for display (using different variable names to avoid conflicts)
$pendingDocumentsList = array_filter($trackingDocuments, function($doc) { return $doc['status'] === 'Pending'; });
$inReviewDocumentsList = array_filter($trackingDocuments, function($doc) { return $doc['status'] === 'In Review'; });
$completedDocumentsList = array_filter($trackingDocuments, function($doc) { return $doc['status'] === 'Completed' || $doc['status'] === 'Approved'; });
$archivedDocumentsList = array_filter($trackingDocuments, function($doc) { return $doc['status'] === 'Archived'; });

// Close connection at the end of the script
// API endpoints (json responses) --------------------------------------
if ($__api_action === 'overdue_docs') {
    header('Content-Type: application/json');
    $sql = "SELECT id, employee_name AS name, department, status,
                   DATEDIFF(CURDATE(), DATE(COALESCE(created_at, date_submitted, date_archived))) AS days_overdue
            FROM tracking
            WHERE COALESCE(status,'') NOT IN ('Completed','Approved','Archived')
              AND DATEDIFF(CURDATE(), DATE(COALESCE(created_at, date_submitted))) >= 4
            ORDER BY days_overdue DESC
            LIMIT 5";
    $rows = [];
    if ($result = $connection->query($sql)) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $result->free();
    }
    echo json_encode(['items' => $rows]);
    $connection->close();
    exit;
}

if ($__api_action === 'monthly_counts') {
    header('Content-Type: application/json');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $year = max(2025, $year);
    $labels = [];
    $counts = [];
  // monthly_counts = uploads/generated documents (create events)
  $countsByMonth = array_fill(1, 12, 0);

  if (stats_table_exists($connection, 'document_history')) {
    $sql = "SELECT MONTH(created_at) AS m, COUNT(*) AS c\n" .
         "FROM document_history\n" .
         "WHERE action = 'create' AND YEAR(created_at) = ?\n" .
         "GROUP BY MONTH(created_at)";
    if ($stmt = $connection->prepare($sql)) {
      $stmt->bind_param('i', $year);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
          $m = (int)($row['m'] ?? 0);
          if ($m >= 1 && $m <= 12) {
            $countsByMonth[$m] = (int)($row['c'] ?? 0);
          }
        }
        if ($res) { $res->free(); }
      }
      $stmt->close();
    }
    // If history exists but has no data (common for older DBs), fall back to tracking
    $sum = array_sum($countsByMonth);
    if ($sum === 0) {
      for ($m = 1; $m <= 12; $m++) {
        $sql = sprintf(
          "SELECT COUNT(*) AS c FROM tracking WHERE YEAR(COALESCE(created_at, date_submitted)) = %d AND MONTH(COALESCE(created_at, date_submitted)) = %d $__deptFilter",
          $year,
          $m
        );
        $c = 0;
        if ($res = $connection->query($sql)) {
          if ($row = $res->fetch_assoc()) { $c = (int)$row['c']; }
          $res->free();
        }
        $countsByMonth[$m] = $c;
      }
    }
  } else {
    // Fallback: tracking table
    for ($m = 1; $m <= 12; $m++) {
      $sql = sprintf(
        "SELECT COUNT(*) AS c FROM tracking WHERE YEAR(COALESCE(created_at, date_submitted)) = %d AND MONTH(COALESCE(created_at, date_submitted)) = %d $__deptFilter",
        $year,
        $m
      );
      $c = 0;
      if ($res = $connection->query($sql)) {
        if ($row = $res->fetch_assoc()) { $c = (int)$row['c']; }
        $res->free();
      }
      $countsByMonth[$m] = $c;
    }
  }

  for ($m = 1; $m <= 12; $m++) {
    $labels[] = date('M', mktime(0,0,0,$m,1,$year));
    $counts[] = (int)$countsByMonth[$m];
  }
    echo json_encode(['labels' => $labels, 'counts' => $counts]);
    $connection->close();
    exit;
}

if ($__api_action === 'monthly_processed_counts') {
  header('Content-Type: application/json');
  $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
  $year = max(2025, $year);
  $granularity = isset($_GET['granularity']) ? strtolower(trim((string)$_GET['granularity'])) : 'monthly';
  if (!in_array($granularity, ['daily','weekly','monthly'], true)) {
    $granularity = 'monthly';
  }
  $labels = [];
  $counts = [];

  if ($granularity === 'daily') {
    $countsByDay = [];
    if (stats_table_exists($connection, 'document_history')) {
      $sql = "SELECT DATE(created_at) AS d, COUNT(*) AS c\n" .
           "FROM document_history\n" .
           "WHERE YEAR(created_at) = ?\n" .
           "  AND (LOWER(IFNULL(action,'')) IN ('complete','archive','approve')\n" .
           "       OR LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived'))\n" .
           "GROUP BY DATE(created_at)";
      if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $year);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          while ($res && ($row = $res->fetch_assoc())) {
            $d = (string)($row['d'] ?? '');
            if ($d !== '') $countsByDay[$d] = (int)($row['c'] ?? 0);
          }
          if ($res) { $res->free(); }
        }
        $stmt->close();
      }
    }

    // Fill all days in the selected year for a stable x-axis
    $start = new DateTime(sprintf('%04d-01-01', $year));
    $end = new DateTime(sprintf('%04d-12-31', $year));
    $end->setTime(0, 0, 0);
    for ($dt = clone $start; $dt <= $end; $dt->modify('+1 day')) {
      $key = $dt->format('Y-m-d');
      $labels[] = $key;
      $counts[] = (int)($countsByDay[$key] ?? 0);
    }
  } elseif ($granularity === 'weekly') {
    $countsByWeek = [];
    if (stats_table_exists($connection, 'document_history')) {
      $sql = "SELECT YEARWEEK(created_at, 1) AS yw, COUNT(*) AS c\n" .
           "FROM document_history\n" .
           "WHERE YEAR(created_at) = ?\n" .
           "  AND (LOWER(IFNULL(action,'')) IN ('complete','archive','approve')\n" .
           "       OR LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived'))\n" .
           "GROUP BY YEARWEEK(created_at, 1)";
      if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $year);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          while ($res && ($row = $res->fetch_assoc())) {
            $yw = (string)($row['yw'] ?? '');
            if ($yw !== '') $countsByWeek[$yw] = (int)($row['c'] ?? 0);
          }
          if ($res) { $res->free(); }
        }
        $stmt->close();
      }
    }

    // Fill weeks by scanning the year week-by-week (ISO week start Monday)
    $dt = new DateTime(sprintf('%04d-01-01', $year));
    $dt->setTime(0, 0, 0);
    // move to Monday of the week containing Jan 1
    $dow = (int)$dt->format('N');
    $dt->modify('-' . ($dow - 1) . ' days');
    $seen = [];
    while (true) {
      $weekStart = clone $dt;
      $weekEnd = (clone $dt)->modify('+6 days');
      // stop when weekStart has moved past the year end and we've already produced the last in-year week
      if ((int)$weekStart->format('Y') > $year && (int)$weekEnd->format('Y') > $year) break;
      // compute yearweek key using MySQL-compatible format: YYYYWW (ISO)
      $ywKey = $weekStart->format('o') . $weekStart->format('W');
      if (!isset($seen[$ywKey])) {
        $seen[$ywKey] = true;
        $labels[] = 'W' . $weekStart->format('W');
        $counts[] = (int)($countsByWeek[$ywKey] ?? 0);
      }
      $dt->modify('+7 days');
      if ((int)$dt->format('Y') > $year + 1) break;
    }
  } else {
    // monthly
    $countsByMonth = array_fill(1, 12, 0);
    if (stats_table_exists($connection, 'document_history')) {
      $sql = "SELECT MONTH(created_at) AS m, COUNT(*) AS c\n" .
           "FROM document_history\n" .
           "WHERE YEAR(created_at) = ?\n" .
           "  AND (LOWER(IFNULL(action,'')) IN ('complete','archive','approve')\n" .
           "       OR LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived'))\n" .
           "GROUP BY MONTH(created_at)";
      if ($stmt = $connection->prepare($sql)) {
        $stmt->bind_param('i', $year);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          while ($res && ($row = $res->fetch_assoc())) {
            $m = (int)($row['m'] ?? 0);
            if ($m >= 1 && $m <= 12) {
              $countsByMonth[$m] = (int)($row['c'] ?? 0);
            }
          }
          if ($res) { $res->free(); }
        }
        $stmt->close();
      }
      // If history exists but has no processed events, fall back to current tracking statuses
      $sum = array_sum($countsByMonth);
      if ($sum === 0) {
        for ($m = 1; $m <= 12; $m++) {
          $sql = sprintf(
            "SELECT COUNT(*) AS c FROM tracking WHERE YEAR(COALESCE(created_at, date_submitted)) = %d AND MONTH(COALESCE(created_at, date_submitted)) = %d AND COALESCE(status,'') IN ('Completed','Approved','Archived') $__deptFilter",
            $year,
            $m
          );
          $c = 0;
          if ($res = $connection->query($sql)) {
            if ($row = $res->fetch_assoc()) { $c = (int)$row['c']; }
            $res->free();
          }
          $countsByMonth[$m] = $c;
        }
      }
    } else {
      // Fallback: approximate using current tracking statuses
      for ($m = 1; $m <= 12; $m++) {
        $sql = sprintf(
          "SELECT COUNT(*) AS c FROM tracking WHERE YEAR(COALESCE(created_at, date_submitted)) = %d AND MONTH(COALESCE(created_at, date_submitted)) = %d AND COALESCE(status,'') IN ('Completed','Approved','Archived') $__deptFilter",
          $year,
          $m
        );
        $c = 0;
        if ($res = $connection->query($sql)) {
          if ($row = $res->fetch_assoc()) { $c = (int)$row['c']; }
          $res->free();
        }
        $countsByMonth[$m] = $c;
      }
    }

    for ($m = 1; $m <= 12; $m++) {
      $labels[] = date('M', mktime(0,0,0,$m,1,$year));
      $counts[] = (int)$countsByMonth[$m];
    }
  }

  echo json_encode(['labels' => $labels, 'counts' => $counts, 'granularity' => $granularity]);
  $connection->close();
  exit;
}

if ($__api_action === 'predict_volume') {
    header('Content-Type: application/json');
    $horizon = isset($_GET['h']) ? max(1, min(60, (int)$_GET['h'])) : 5;
    $displayDays = isset($_GET['display_days']) ? max(1, min(60, (int)$_GET['display_days'])) : 5;
    // Backwards compat: frontend historically sends source=both|uploads|processed
    $metric = $_GET['metric'] ?? ($_GET['source'] ?? 'both'); // 'uploads', 'processed', or 'both'
    $granularity = isset($_GET['granularity']) ? strtolower(trim((string)$_GET['granularity'])) : 'daily';
    $granularity = ($granularity === 'weekly') ? 'weekly' : 'daily';

    $startRaw = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
    $endRaw = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';
    $startDt = null;
    $endDt = null;
    if ($startRaw !== '') {
        $tmp = DateTime::createFromFormat('Y-m-d', $startRaw);
        if ($tmp instanceof DateTime) $startDt = $tmp;
    }
    if ($endRaw !== '') {
        $tmp = DateTime::createFromFormat('Y-m-d', $endRaw);
        if ($tmp instanceof DateTime) $endDt = $tmp;
    }
    if ($startDt && $endDt && $startDt > $endDt) {
        $swap = $startDt;
        $startDt = $endDt;
        $endDt = $swap;
    }

    // Helper: Holt-Winters Triple Exponential Smoothing
    function run_forecast_model($history, $horizon, $displayDays, $granularity, DateTime $anchorDt) {
        $todayDt = clone $anchorDt;
        // Fill missing dates (continuous series required)
        // Find min/max date range from history keys
        $dates = array_keys($history);
        if (empty($dates)) {
            // Default empty
            $minDate = (clone $todayDt)->modify('-30 days'); 
        } else {
            $minDate = new DateTime(min($dates));
        }

        // Ensure we have at least $displayDays points in the rendered history window.
        // This avoids charts appearing blank/too-short when there is activity on only 1 day/week.
        if ((int)$displayDays > 0) {
            $desiredStart = clone $todayDt;
            if ($granularity === 'weekly') {
                $desiredStart->modify('monday this week');
                $desiredStart->modify('-' . max(0, (int)$displayDays - 1) . ' weeks');
                if ($minDate->format('N') !== '1') {
                    $minDate->modify('monday this week');
                }
            } else {
                $desiredStart->modify('-' . max(0, (int)$displayDays - 1) . ' days');
            }
            if ($minDate > $desiredStart) {
                $minDate = $desiredStart;
            }
        }
        
        $filled = [];
        $curr = clone $minDate;
        while ($curr <= $todayDt) {
            $k = $curr->format('Y-m-d');
            $filled[$k] = $history[$k] ?? 0;
            $curr->modify($granularity === 'weekly' ? '+1 week' : '+1 day');
        }
        
        $allLabels = array_keys($filled);
        $allValues = array_values($filled);
        $n = count($allValues);

        $histLabels = array_slice($allLabels, -$displayDays);
        $histValues = array_slice($allValues, -$displayDays);

        // Parameters
        // Season length: daily=7 (weekly pattern), weekly=4 (approx monthly cycle)
        $alpha = 0.3; $beta = 0.1; $gamma = 0.3; $m = ($granularity === 'weekly') ? 4 : 7;
        $forecastLabels = []; $forecastValues = [];

        // Logic
        if ($n >= $m * 2) {
             // Full HW
            $L = array_sum(array_slice($allValues, 0, $m)) / $m;
            $T = 0;
            for ($i = 0; $i < $m; $i++) { $T += ($allValues[$i + $m] - $allValues[$i]) / $m; }
            $T /= $m;
            $S = [];
            for ($i = 0; $i < $m; $i++) { $S[$i] = $allValues[$i] - $L; }
            
            $errors = [];
            for ($t = $m; $t < $n; $t++) {
                $y = $allValues[$t];
                $idx = $t % $m;
                $fcast = $L + $T + $S[$idx];
                $errors[] = $y - $fcast;
                
                $L_new = $alpha * ($y - $S[$idx]) + (1 - $alpha) * ($L + $T);
                $T_new = $beta * ($L_new - $L) + (1 - $beta) * $T;
                $S_new = $gamma * ($y - $L_new) + (1 - $gamma) * $S[$idx];
                $L = $L_new; $T = $T_new; $S[$idx] = $S_new;
            }
            
            $mse = count($errors) > 1 ? (array_sum(array_map(fn($e)=>$e*$e, $errors)) / (count($errors)-1)) : 1;
            $stdError = sqrt($mse);
            
            $lastDate = end($allLabels);
            $fStart = new DateTime($lastDate);
            $fStart->modify($granularity === 'weekly' ? '+1 week' : '+1 day');
            
            for ($k = 1; $k <= $horizon; $k++) {
                $d = clone $fStart;
                $d->modify($granularity === 'weekly' ? ('+' . ($k-1) . ' weeks') : ('+' . ($k-1) . ' days'));
                $idx = ($n + $k - 1) % $m;
                $val = max(0, round($L + $k*$T + $S[$idx], 1));
                $se = $stdError * sqrt(1 + ($k-1)*0.15);
                
                $forecastLabels[] = $d->format('Y-m-d');
                $forecastValues[] = $val;
            }
        } elseif ($n >= 1) {
            // Simple Average / Linear Fallback
            $avg = array_sum($allValues) / $n;
            $lastDate = end($allLabels);
            $fStart = new DateTime($lastDate);
            $fStart->modify($granularity === 'weekly' ? '+1 week' : '+1 day');
            for ($k = 1; $k <= $horizon; $k++) {
                $d = clone $fStart;
                $d->modify($granularity === 'weekly' ? ('+' . ($k-1) . ' weeks') : ('+' . ($k-1) . ' days'));
                if ($granularity === 'weekly') {
                    $val = max(0, round($avg, 1));
                } else {
                    $dow = (int)$d->format('N');
                    $fac = ($dow <= 5) ? 1.0 : 0.6;
                    $val = max(0, round($avg * $fac, 1));
                }
                $forecastLabels[] = $d->format('Y-m-d');
                $forecastValues[] = $val;
            }
        } else {
            // No data
             $fStart = clone $todayDt; $fStart->modify('+1 day');
             for ($k = 1; $k <= $horizon; $k++) {
                $d = clone $fStart; $d->modify('+' . ($k-1) . ' days');
                $forecastLabels[] = $d->format('Y-m-d');
                $forecastValues[] = 0;
             }
        }
        
        return [
            'hist_labels' => $histLabels,
            'hist_values' => $histValues,
            'forecast_labels' => $forecastLabels,
            'forecast_values' => $forecastValues
        ];
    }

    // Backtesting: hold back the last N points, forecast them, compare to actual
    function run_backtest($history, $holdback, $granularity, DateTime $anchorDt, $displayDays = 5) {
        // Build a continuous series (fill missing day/week buckets with 0)
        // so comparison still works on sparse/short date windows.
        $todayDt = clone $anchorDt;
        $dates = array_keys($history);
        if (empty($dates)) {
            $minDate = clone $todayDt;
        } else {
            $minDate = new DateTime(min($dates));
        }

        $minNeeded = max(2, (int)$displayDays + 1);
        $desiredStart = clone $todayDt;
        if ($granularity === 'weekly') {
            $desiredStart->modify('monday this week');
            $desiredStart->modify('-' . max(1, $minNeeded - 1) . ' weeks');
            if ($minDate->format('N') !== '1') {
                $minDate->modify('monday this week');
            }
        } else {
            $desiredStart->modify('-' . max(1, $minNeeded - 1) . ' days');
        }
        if ($minDate > $desiredStart) {
            $minDate = $desiredStart;
        }

        $filled = [];
        $curr = clone $minDate;
        while ($curr <= $todayDt) {
            $k = $curr->format('Y-m-d');
            $filled[$k] = $history[$k] ?? 0;
            $curr->modify($granularity === 'weekly' ? '+1 week' : '+1 day');
        }

        $history = $filled;
        $dates = array_keys($history);
        $n = count($dates);
        if ($n < 2) {
            return ['labels' => [], 'actual' => [], 'predicted' => [], 'mape' => null, 'rmse' => null];
        }

        // Adaptive split so Daily/Weekly still render comparison on short ranges.
        $minTrainPreferred = ($granularity === 'weekly') ? 4 : 6;
        $effectiveHoldback = max(1, (int)$holdback);
        if ($n < ($minTrainPreferred + $effectiveHoldback)) {
            $effectiveHoldback = max(1, min(3, $n - 1));
        }
        $trainCount = $n - $effectiveHoldback;
        if ($trainCount < 1) {
            return ['labels' => [], 'actual' => [], 'predicted' => [], 'mape' => null, 'rmse' => null];
        }

        // Split: use all but last $effectiveHoldback as training
        $trainHistory = array_slice($history, 0, $trainCount, true);
        $testLabels = array_slice($dates, $trainCount);
        $testValues = array_map(function($k) use ($history) { return $history[$k] ?? 0; }, $testLabels);

        $trainDates = array_keys($trainHistory);
        $anchorDt = new DateTime(end($trainDates));

        // Use Holt-Winters when enough training points exist, otherwise a naive average fallback.
        $minTrainForHW = ($granularity === 'weekly') ? 8 : 14;
        if (count($trainDates) >= $minTrainForHW) {
            $result = run_forecast_model($trainHistory, $effectiveHoldback, 0, $granularity, $anchorDt);
            $predicted = $result['forecast_values'] ?? [];
        } else {
            $trainVals = array_values($trainHistory);
            $avgTrain = count($trainVals) > 0 ? (array_sum($trainVals) / count($trainVals)) : 0;
            if ($avgTrain > 0) {
                $avg = $avgTrain;
            } else {
                $allVals = array_values($history);
                $avg = count($allVals) > 0 ? (array_sum($allVals) / count($allVals)) : 0;
            }
            $predicted = array_fill(0, count($testLabels), round(max(0, $avg), 1));
        }

        // Calculate RMSE
        $seSum = 0;
        for ($i = 0; $i < min(count($testValues), count($predicted)); $i++) {
            $a = $testValues[$i];
            $p = $predicted[$i];
            $seSum += ($a - $p) * ($a - $p);
        }
        $rmse = count($testValues) > 0 ? round(sqrt($seSum / count($testValues)), 2) : null;

        return [
            'labels' => $testLabels,
            'actual' => $testValues,
            'predicted' => array_slice($predicted, 0, count($testLabels)),
            'rmse' => $rmse
        ];
    }

    // --- Gather Data ---
    $anchorDt = $endDt ? clone $endDt : new DateTime('today');
    if ($granularity === 'weekly') {
        $anchorDt->modify('monday this week');
    }
    $historyStartDt = $startDt ? clone $startDt : (clone $anchorDt)->modify('-120 days');
    if ($granularity === 'weekly') {
        $historyStartDt->modify('monday this week');
    }
    $rangeStart = $historyStartDt->format('Y-m-d');
    $rangeEnd = $anchorDt->format('Y-m-d');
    
    $midDt = clone $historyStartDt;
    $rangeSeconds = max(0, $anchorDt->getTimestamp() - $historyStartDt->getTimestamp());
    $midDt->modify('+' . (int)floor($rangeSeconds / 2) . ' seconds');
    $midDate = $midDt->format('Y-m-d');
    
    $uploadsHistory = [];
    $processedHistory = [];
    $useHistory = stats_table_exists($connection, 'document_history');
        if ($useHistory) {
         // Uploads
         if ($granularity === 'weekly') {
             $sql = "SELECT DATE(DATE_SUB(DATE(created_at), INTERVAL (WEEKDAY(created_at)) DAY)) AS d, COUNT(*) AS c\n" .
                    "FROM document_history\n" .
                    "WHERE LOWER(IFNULL(action,'')) IN ('create','receive')\n" .
                    "  AND DATE(created_at) >= '" . $rangeStart . "'\n" .
                    "  AND DATE(created_at) <= '" . $rangeEnd . "'\n" .
                    "GROUP BY d";
         } else {
             $sql = "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM document_history 
                     WHERE LOWER(IFNULL(action,'')) IN ('create','receive')
                     AND DATE(created_at) >= '" . $rangeStart . "'
                     AND DATE(created_at) <= '" . $rangeEnd . "'
                     GROUP BY DATE(created_at)";
         }
         if ($res = $connection->query($sql)) {
             while ($row = $res->fetch_assoc()) $uploadsHistory[$row['d']] = (int)$row['c'];
             $res->free();
        }
                // Processed (Completed/Approved/Archived)
         if ($granularity === 'weekly') {
             $sql = "SELECT DATE(DATE_SUB(DATE(created_at), INTERVAL (WEEKDAY(created_at)) DAY)) AS d, COUNT(DISTINCT doc_id) AS c\n" .
                    "FROM document_history\n" .
                    "WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')\n" .
                    "  AND DATE(created_at) >= '" . $rangeStart . "'\n" .
                    "  AND DATE(created_at) <= '" . $rangeEnd . "'\n" .
                    "GROUP BY d";
         } else {
             $sql = "SELECT DATE(created_at) AS d, COUNT(DISTINCT doc_id) AS c FROM document_history
                     WHERE LOWER(IFNULL(to_status,'')) IN ('completed','approved','archived')
                     AND DATE(created_at) >= '" . $rangeStart . "'
                     AND DATE(created_at) <= '" . $rangeEnd . "'
                     GROUP BY DATE(created_at)";
         }
         if ($res = $connection->query($sql)) {
             while ($row = $res->fetch_assoc()) $processedHistory[$row['d']] = (int)$row['c'];
             $res->free();
         }

         $trendDocType = ['type' => null, 'count' => null];

         // Use doc_type stored directly on document_history (backfilled + new inserts).
         // COALESCE falls back to tracking/archive lookup for legacy rows still NULL.
         // Query all actions (not just create/receive) to maximize type coverage.
         $archiveJoinIdExpr = 'id';
         if (stats_table_exists($connection, 'archive') && stats_column_exists($connection, 'archive', 'source_tracking_id')) {
           $archiveJoinIdExpr = 'source_tracking_id';
         }
         $hasDocTypeCol = stats_column_exists($connection, 'document_history', 'doc_type');
         $typeExpr = $hasDocTypeCol ? "COALESCE(dh.doc_type, lookup.type)" : "lookup.type";

         $sqlTrendType = "SELECT {$typeExpr} AS type,\n" .
           "  SUM(CASE WHEN DATE(dh.created_at) <= '" . $midDate . "' THEN 1 ELSE 0 END) AS c1,\n" .
           "  SUM(CASE WHEN DATE(dh.created_at) > '" . $midDate . "' THEN 1 ELSE 0 END) AS c2\n" .
           "FROM document_history dh\n" .
           "LEFT JOIN (\n" .
           "    SELECT id, type FROM tracking WHERE type IS NOT NULL AND type != ''\n" .
           "    UNION ALL\n" .
           "    SELECT {$archiveJoinIdExpr} AS id, type FROM archive WHERE type IS NOT NULL AND type != ''\n" .
           ") lookup ON lookup.id = dh.doc_id\n" .
           "WHERE DATE(dh.created_at) >= '" . $rangeStart . "'\n" .
           "  AND DATE(dh.created_at) <= '" . $rangeEnd . "'\n" .
           "  AND {$typeExpr} IS NOT NULL\n" .
           "  AND {$typeExpr} != ''\n" .
           "GROUP BY {$typeExpr}";
         if ($res = $connection->query($sqlTrendType)) {
           $bestType = null;
           $bestDelta = null;
           $bestC2 = null;
           while ($row = $res->fetch_assoc()) {
             $type = (string)($row['type'] ?? '');
             $c1 = (int)($row['c1'] ?? 0);
             $c2 = (int)($row['c2'] ?? 0);
             $delta = $c2 - $c1;
             if ($bestDelta === null || $delta > $bestDelta || ($delta === $bestDelta && $c2 > (int)$bestC2)) {
               $bestDelta = $delta;
               $bestC2 = $c2;
               $bestType = $type;
             }
           }
           $res->free();
           if ($bestType !== null && $bestType !== '') {
             $trendDocType = ['type' => $bestType, 'count' => (int)$bestC2];
           }
         }

         // Fallback: if no trend doc type found in date range, use overall most common type
         if ($trendDocType['type'] === null && $hasDocTypeCol) {
           $sqlFb = "SELECT doc_type AS type, COUNT(*) AS cnt FROM document_history WHERE doc_type IS NOT NULL AND doc_type != '' GROUP BY doc_type ORDER BY cnt DESC LIMIT 1";
           if ($resFb = $connection->query($sqlFb)) {
             if ($rowFb = $resFb->fetch_assoc()) {
               $trendDocType = ['type' => (string)$rowFb['type'], 'count' => (int)$rowFb['cnt']];
             }
             $resFb->free();
           }
         }

         $bucketUnit = ($granularity === 'weekly') ? 'week' : 'day';
         $sumProcessed = 0;
         foreach ($processedHistory as $cnt) {
           $sumProcessed += (int)$cnt;
         }

         $denBuckets = 0;
         $iter = clone $historyStartDt;
         while ($iter <= $anchorDt) {
           $denBuckets++;
           $iter->modify($granularity === 'weekly' ? '+1 week' : '+1 day');
         }

         $kpiPeak = ['label' => null, 'count' => null];
         if ($denBuckets > 0) {
           $maxLabel = null;
           $maxCount = null;
           $iter2 = clone $historyStartDt;
           while ($iter2 <= $anchorDt) {
             $lbl = $iter2->format('Y-m-d');
             $cnt = (int)($processedHistory[$lbl] ?? 0);
             if ($maxCount === null || $cnt > $maxCount) {
               $maxCount = $cnt;
               $maxLabel = $lbl;
             }
             $iter2->modify($granularity === 'weekly' ? '+1 week' : '+1 day');
           }
           $kpiPeak = ['label' => $maxLabel, 'count' => $maxCount];
         }

         $avgProcessed = ['value' => null, 'unit' => $bucketUnit];
         if ($denBuckets > 0) {
           $avgProcessed = ['value' => round($sumProcessed / $denBuckets, 1), 'unit' => $bucketUnit];
         }
     } else {
         // Fallback to tracking
         if ($granularity === 'weekly') {
             $sql = "SELECT DATE(DATE_SUB(DATE(COALESCE(created_at, date_submitted)), INTERVAL (WEEKDAY(COALESCE(created_at, date_submitted))) DAY)) AS d, COUNT(*) AS c\n" .
                    "FROM tracking\n" .
                    "WHERE DATE(COALESCE(created_at, date_submitted)) >= '" . $rangeStart . "'\n" .
                    "  AND DATE(COALESCE(created_at, date_submitted)) <= '" . $rangeEnd . "' $__deptFilter\n" .
                    "GROUP BY d";
         } else {
             $sql = "SELECT DATE(COALESCE(created_at, date_submitted)) AS d, COUNT(*) AS c FROM tracking
                     WHERE DATE(COALESCE(created_at, date_submitted)) >= '" . $rangeStart . "'
                     AND DATE(COALESCE(created_at, date_submitted)) <= '" . $rangeEnd . "' $__deptFilter
                     GROUP BY d";
         }
         if ($res = $connection->query($sql)) {
             while ($row = $res->fetch_assoc()) $uploadsHistory[$row['d']] = (int)$row['c'];
             $res->free();
         }
         // Cannot reliably guess 'Processed' history from tracking table alone without timestamps

         // Compute trend doc type from document_history (with fallback to tracking/archive)
         // Query all actions to maximize type coverage.
         $trendDocType = ['type' => null, 'count' => null];
         $archiveJoinIdExpr = 'id';
         if (stats_table_exists($connection, 'archive') && stats_column_exists($connection, 'archive', 'source_tracking_id')) {
           $archiveJoinIdExpr = 'source_tracking_id';
         }
         $hasDocTypeCol = stats_column_exists($connection, 'document_history', 'doc_type');
         $typeExpr = $hasDocTypeCol ? "COALESCE(dh.doc_type, lookup.type)" : "lookup.type";

         $sqlTrendTypeFb = "SELECT {$typeExpr} AS type,\n" .
           "  SUM(CASE WHEN DATE(dh.created_at) <= '" . $midDate . "' THEN 1 ELSE 0 END) AS c1,\n" .
           "  SUM(CASE WHEN DATE(dh.created_at) > '" . $midDate . "' THEN 1 ELSE 0 END) AS c2\n" .
           "FROM document_history dh\n" .
           "LEFT JOIN (\n" .
           "    SELECT id, type FROM tracking WHERE type IS NOT NULL AND type != ''\n" .
           "    UNION ALL\n" .
           "    SELECT {$archiveJoinIdExpr} AS id, type FROM archive WHERE type IS NOT NULL AND type != ''\n" .
           ") lookup ON lookup.id = dh.doc_id\n" .
           "WHERE DATE(dh.created_at) >= '" . $rangeStart . "'\n" .
           "  AND DATE(dh.created_at) <= '" . $rangeEnd . "'\n" .
           "  AND {$typeExpr} IS NOT NULL\n" .
           "  AND {$typeExpr} != ''\n" .
           "GROUP BY {$typeExpr}";
         if ($resFb = $connection->query($sqlTrendTypeFb)) {
           $bestType = null; $bestDelta = null; $bestC2 = null;
           while ($row = $resFb->fetch_assoc()) {
             $type = (string)($row['type'] ?? '');
             $c1 = (int)($row['c1'] ?? 0);
             $c2 = (int)($row['c2'] ?? 0);
             $delta = $c2 - $c1;
             if ($bestDelta === null || $delta > $bestDelta || ($delta === $bestDelta && $c2 > (int)$bestC2)) {
               $bestDelta = $delta; $bestC2 = $c2; $bestType = $type;
             }
           }
           $resFb->free();
           if ($bestType !== null && $bestType !== '') {
             $trendDocType = ['type' => $bestType, 'count' => (int)$bestC2];
           }
         }

         // Fallback: if no trend doc type found in date range, use overall most common type
         if ($trendDocType['type'] === null && $hasDocTypeCol) {
           $sqlFb2 = "SELECT doc_type AS type, COUNT(*) AS cnt FROM document_history WHERE doc_type IS NOT NULL AND doc_type != '' GROUP BY doc_type ORDER BY cnt DESC LIMIT 1";
           if ($resFb2 = $connection->query($sqlFb2)) {
             if ($rowFb2 = $resFb2->fetch_assoc()) {
               $trendDocType = ['type' => (string)$rowFb2['type'], 'count' => (int)$rowFb2['cnt']];
             }
             $resFb2->free();
           }
         }
     }

     // Run Forecasts
     $uploadsData = run_forecast_model($uploadsHistory, $horizon, $displayDays, $granularity, $anchorDt);
     $processedData = run_forecast_model($processedHistory, $horizon, $displayDays, $granularity, $anchorDt);

     // Run Backtests for predicted vs actual overlay
    $btHoldback = min($displayDays, max(3, (int)($horizon)));
    $uploadsBacktest = run_backtest($uploadsHistory, $btHoldback, $granularity, $anchorDt, $displayDays);
    $processedBacktest = run_backtest($processedHistory, $btHoldback, $granularity, $anchorDt, $displayDays);

    if (!isset($trendDocType) || !is_array($trendDocType)) {
        $trendDocType = ['type' => null, 'count' => null];
    }
    if (empty($trendDocType['type'])) {
        if ($resTypeTr = $connection->query(
            "SELECT type, COUNT(*) AS cnt FROM tracking " .
            "WHERE type IS NOT NULL AND TRIM(type) != '' " .
            "AND DATE(COALESCE(created_at, date_submitted)) >= '" . $rangeStart . "' " .
            "AND DATE(COALESCE(created_at, date_submitted)) <= '" . $rangeEnd . "' $__deptFilter " .
            "GROUP BY type ORDER BY cnt DESC LIMIT 1"
        )) {
            if ($r = $resTypeTr->fetch_assoc()) {
                $trendDocType = ['type' => (string)($r['type'] ?? ''), 'count' => (int)($r['cnt'] ?? 0)];
            }
            $resTypeTr->free();
        }
    }
    if (empty($trendDocType['type']) && stats_table_exists($connection, 'archive') && stats_column_exists($connection, 'archive', 'date_archived')) {
        if ($resTypeAr = $connection->query(
            "SELECT type, COUNT(*) AS cnt FROM archive " .
            "WHERE type IS NOT NULL AND TRIM(type) != '' " .
            "AND DATE(date_archived) >= '" . $rangeStart . "' " .
            "AND DATE(date_archived) <= '" . $rangeEnd . "' $__deptFilterArchive " .
            "GROUP BY type ORDER BY cnt DESC LIMIT 1"
        )) {
            if ($r = $resTypeAr->fetch_assoc()) {
                $trendDocType = ['type' => (string)($r['type'] ?? ''), 'count' => (int)($r['cnt'] ?? 0)];
            }
            $resTypeAr->free();
        }
    }

    echo json_encode([
        'uploads' => $uploadsData,
        'processed' => $processedData,
        'backtest' => [
            'uploads' => $uploadsBacktest,
            'processed' => $processedBacktest
        ],
        'meta' => [
            'horizon' => $horizon,
            'display_days' => $displayDays,
            'granularity' => $granularity,
            'start_date' => $startDt ? $startDt->format('Y-m-d') : null,
            'end_date' => $endDt ? $endDt->format('Y-m-d') : null,
            'kpis' => [
              'trend_doc_type' => $trendDocType ?? ['type' => null, 'count' => null],
              'peak_processed' => $kpiPeak ?? ['label' => null, 'count' => null],
              'avg_processed_per_unit' => $avgProcessed ?? ['value' => null, 'unit' => ($granularity === 'weekly') ? 'week' : 'day']
            ]
        ]
    ]);
    $connection->close();
    exit;
}

$connection->close();
$__db = $connection;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Status Reports - CHRMO Document Tracking</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
      --shadow-md: 0 8px 15px rgba(0, 0, 0, 0.1); /* Added for modals */
      --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.15); /* Added for modals */
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Open Sans', Arial, sans-serif; }
    body { background-color: var(--light-bg); color: var(--text-dark); }
    .container { display: flex; min-height: 100vh; }
    .sidebar {
      width: 80px;
      background: linear-gradient(180deg, #2e2e5e 0%, #3d3d7a 50%, #2e2e5e 100%);
      color: var(--white);
      padding: 0;
      box-shadow: 4px 0 24px rgba(0,0,0,0.18);
      position: fixed;
      height: 100vh;
      transition: width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
      z-index: 100;
      overflow: hidden;
      overflow-y: auto;
      transform: translateZ(0);
      backface-visibility: hidden;
      contain: layout style;
      will-change: width;
      display: flex;
      flex-direction: column;
    }
    .sidebar::-webkit-scrollbar { width: 0; }
    .sidebar:hover { width: 260px; }
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
    .sidebar:hover .sidebar-header img { height: 64px; width: 64px; }
    .sidebar:not(:hover) .sidebar-header img { margin-bottom: 0; }
    .sidebar-header h2 {
      font-size: 15px;
      font-weight: 700;
      margin: 0;
      color: var(--white);
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
    .sidebar:not(:hover) .sidebar-header { justify-content: center; }
    .sidebar:not(:hover) .sidebar-header h2 { display: none; }
    .sidebar:not(:hover) .sidebar-header .sidebar-subtitle { display: none; }
    .sidebar-menu { padding: 0 12px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .sidebar-section-label {
      font-size: 10px; font-weight: 700; letter-spacing: 2.5px; color: rgba(255,255,255,0.35);
      text-transform: uppercase; padding: 16px 14px 6px; opacity: 0;
      transition: opacity 0.2s ease 0.1s; white-space: nowrap;
    }
    .sidebar:hover .sidebar-section-label { opacity: 1; }
    .sidebar:not(:hover) .sidebar-section-label { height: 0; padding: 4px 0; overflow: hidden; }
    .sidebar-section-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 4px 14px 0; }
    .sidebar:not(:hover) .sidebar-section-divider { margin: 2px auto; width: 32px; }
    .sidebar:not(:hover) .menu-item { justify-content: center; }
    .menu-item {
      display: flex; align-items: center; gap: 14px; padding: 11px 14px; color: var(--white);
      text-decoration: none; transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
      border-radius: 12px; position: relative;
    }
    .menu-item:hover { background: rgba(255,255,255,0.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
    .menu-item.active {
      background: rgba(255,255,255,0.13); color: #fff;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15), inset 0 0 0 1px rgba(255,255,255,0.08);
      border-left: 3px solid #64b5f6;
    }
    .menu-item.active i, .menu-item.active span { color: #fff; }
    .menu-item i { font-size: 20px; width: 28px; min-width: 28px; text-align: center; color: rgba(255,255,255,0.85); transition: color .18s ease; }
    .menu-item.active i { color: #90caf9; }
    .menu-item:hover i { color: #fff; }
    .menu-item span { font-size: 14px; opacity: 0; white-space: nowrap; transition: opacity .2s ease; }
    .sidebar:hover .menu-item span { opacity: 1; }
    .sidebar:not(:hover) .menu-item { justify-content: center; width:52px; height:52px; padding:0; margin:3px auto; display:grid; place-items:center; overflow: visible; border-left: none; }
    .sidebar:not(:hover) .menu-item.active { border-left: none; border-bottom: 2px solid #64b5f6; }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display: none; }
    .sidebar:not(:hover) .menu-item i { width:24px; height:24px; display:inline-grid; place-items:center; }
    .menu-badge {
      background-color:#FF5252; color:#fff; font-size:11px; padding: 0 6px; border-radius:999px; margin-left:auto;
      font-weight:700; min-width:20px; height: 20px; line-height: 20px; text-align:center; position:absolute; right:12px; top:50%; transform:translateY(-50%);
      opacity: 1; z-index: 2; pointer-events: none; display: inline-flex; align-items: center; justify-content: center;
    }
    .sidebar:not(:hover) .menu-badge { right:4px; top:4px; transform:none; font-size: 10px; padding: 1px 5px; }
    .menu-badge.success { background-color: #4CAF50; }
    .sidebar-footer {
      padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.08);
      text-align: center; margin-top: auto;
    }
    .sidebar-footer span {
      font-size: 10px; color: rgba(255,255,255,0.3); display: block;
      opacity: 0; transition: opacity 0.2s ease 0.1s; white-space: nowrap;
    }
    .sidebar:hover .sidebar-footer span { opacity: 1; }
    .main-content { flex:1; margin-left: 80px; padding:20px; min-width:0; transition: margin-left .28s ease; }
    .sidebar:hover ~ .main-content { margin-left: 260px; }
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      background-color: var(--white);
      padding: 18px 22px;
      border-radius: 16px;
      box-shadow: 0 8px 28px rgba(2,132,199,.12); border:1px solid #eef3f7;
      position: relative;
      z-index: 4000; /* keep header and its popovers above charts */
    }
    .top-bar h2 {
      font-size: 26px;
      font-weight: 700;
      color: var(--text-dark);
    }
    .search-bar {
      display: flex;
      align-items: center;
      background-color: var(--light-bg);
      border-radius: 20px;
      padding: 5px 15px;
      width: 300px;
    }
    .search-bar input {
      border: none;
      background: transparent;
      outline: none;
      padding: 8px;
      width: 100%;
      font-size: 14px;
      color: var(--text-dark);
    }
    .search-bar i {
      color: var(--primary-dark);
      margin-right: 8px;
    }
    /* User Profile Dropdown */
    .user-profile {
      display: flex;
      align-items: center;
      cursor: pointer;
      position: relative;
    }
    .user-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 10px;
    }
    .dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      min-width: 200px;
      z-index: 100;
      display: none;
      margin-top: 8px;
      overflow: hidden;
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
      transition: background-color 0.2s ease;
    }
    .dropdown-item:hover {
      background-color: var(--primary-light);
      color: var(--primary-dark);
    }
    /* Notification Dropdown */
    .notification-icon {
      margin-right: 20px;
      position: relative;
      cursor: pointer;
      font-size: 1.25rem;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background-color: #FF5252;
      color: white;
      font-size: 10px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .notification-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow);
      width: 350px;
      z-index: 6000; /* above canvases and other UI */
      display: none;
      margin-top: 10px;
      max-height: 400px;
      overflow-y: auto;
    }
    .notification-dropdown.show {
      display: block;
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
    }
    .notification-clear {
      color: var(--primary);
      cursor: pointer;
      font-size: 14px;
    }
    .notification-item {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background-color 0.2s;
    }
    .notification-item:hover {
      background-color: var(--light-bg);
    }
    .notification-item.unread {
      background-color: rgba(0, 188, 212, 0.05);
    }
    .notification-title {
      font-weight: 500;
      margin-bottom: 5px;
      display: flex;
      justify-content: space-between;
    }
    .notification-time {
      color: var(--text-light);
      font-size: 12px;
    }
    .notification-content {
      font-size: 14px;
      color: var(--text-dark);
    }
    /* Stats Cards */
    .stats-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card { background:#fff; border-radius:16px; padding:24px; box-shadow:0 8px 28px rgba(2,132,199,.12); border:1px solid #eef3f7; }
    .stat-card .value { font-size: 34px; font-weight: 800; letter-spacing:-.2px; cursor: default; }
    .stat-card:hover {
      transform: translateY(-5px);
    }
    .stat-card h3 {
      color: var(--text-light);
      font-size: 16px;
      margin-bottom: 10px;
    }
    .stat-card .stat-value {
      font-size: 32px;
      font-weight: bold;
      color: var(--text-dark);
    }
    .stat-card .stat-sub {
      margin-top: 8px;
      font-size: 12px;
      color: var(--text-light);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .stat-card .trend-up {
      color: #16a34a;
      font-weight: 600;
    }
    .stat-card .trend-down {
      color: #dc2626;
      font-weight: 600;
    }
    .stat-card .trend-flat {
      color: #64748b;
      font-weight: 600;
    }
    /* Analytics Section */
    .analytics-section {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 30px;
    }
    @media (max-width: 992px) {
      .analytics-section {
        grid-template-columns: 1fr;
      }
    }
    .analytics-card {
      background-color: var(--white);
      border-radius: 10px;
      padding: 20px;
      box-shadow: var(--shadow);
    }
    .analytics-card h3 {
      font-size: 18px;
      margin-bottom: 20px;
      color: var(--text-dark);
    }
    .chart-container {
      position: relative;
      height: 360px;
      width: 100%;
    }
    @media (max-width: 768px) {
      .chart-container { height: 320px; }
    }
    /* Table Section */
    .pending-docs {
      background-color: var(--white);
      border-radius: 10px;
      padding: 20px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
    }
    .pending-docs h3 {
      font-size: 18px;
      margin-bottom: 20px;
      color: var(--text-dark);
    }
    .docs-table {
      width: 100%;
      border-collapse: collapse;
    }
    .docs-table th, .docs-table td {
      padding: 12px 15px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    .docs-table tr:last-child td {
      border-bottom: none;
    }
    .docs-table th {
      color: var(--text-light);
      font-weight: 500;
      font-size: 14px;
    }
    .badge {
      display: inline-block;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 600;
      color: #fff;
      letter-spacing: 0.5px;
    }
    .badge.completed {
      background: #2a9d8f;
    }
    .badge.pending {
      background: #f2994a;
      color: #4a3c00;
    }
    .badge.archived {
      background: #6c757d;
    }
    .badge.in-review {
      background: #00BCD4;
    }
    .badge.inreview {
      background: #00BCD4;
    }
    .badge.approved {
      background: #4CAF50;
    }
    .badge.rejected {
      background: #F44336;
    }
    /* Document row styles */
    .document-row {
      transition: background-color 0.2s ease;
    }
    .document-row:hover {
      background-color: var(--primary-light);
    }
    .document-row.hidden {
      display: none;
    }
    /* Filter button styles */
    .action-btn.active {
      background-color: var(--primary-dark);
      color: white;
    }
    .action-btn {
      background-color: var(--primary-light);
      color: var(--primary-dark);
      border: none;
      border-radius: 6px;
      padding: 8px 12px;
      margin-left: 5px;
      cursor: pointer;
      font-size: 14px;
      transition: 0.3s;
    }
    .action-btn:hover {
      background-color: var(--primary-dark);
      color: white;
    }
    /* Modals for Preview and Confirmation */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
      justify-content: center;
      align-items: center;
      opacity: 0; /* For fade-in animation */
      transition: opacity 0.3s ease-out;
    }
    .modal.show {
      display: flex;
      opacity: 1;
    }
    .modal-content {
      background-color: var(--white);
      margin: auto;
      padding: 25px;
      border-radius: 12px;
      box-shadow: var(--shadow-lg);
      width: 90%;
      max-width: 500px; /* Adjusted for smaller modals */
      position: relative;
      transform: translateY(-20px); /* For slide-down animation */
      transition: transform 0.3s ease-out;
    }
    .modal.show .modal-content {
        transform: translateY(0);
    }
    .close-button {
      color: var(--text-light);
      font-size: 28px;
      font-weight: bold;
      position: absolute;
      top: 10px;
      right: 15px;
      cursor: pointer;
      transition: color 0.2s;
    }
    .close-button:hover,
    .close-button:focus {
      color: var(--text-dark);
    }
    .modal h3 {
        margin-bottom: 20px;
        color: var(--primary-dark);
        font-size: 24px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 10px;
    }
    .modal-body {
        margin-bottom: 20px;
        max-height: 60vh;
        overflow-y: auto;
    }
    .modal-body p {
        margin-bottom: 10px;
        line-height: 1.5;
    }
    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
    }
    .action-btn.danger {
      background-color: #ffebee;
      color: #f44336;
    }
    .action-btn.danger:hover {
      background-color: #f44336;
      color: white;
    }
    /* Ensure date pickers overlay charts and follow layout */
    .flatpickr-calendar { z-index: 6500 !important; }
    .top-bar h2 {
      font-size: 26px;
      font-weight: 700;
      color: var(--text-dark);
    }
    .badge-small {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      padding: 4px 8px;
      border-radius: 999px;
    }

    #predictiveChartsWrapper {
      transition: flex-direction 0.25s ease, gap 0.25s ease;
    }

    #predictiveChartsWrapper.predictive-expanded {
      flex-direction: column !important;
      gap: 32px !important;
    }

    #predictiveChartsWrapper.predictive-expanded .predictive-chart-card {
      min-width: 100% !important;
    }

    #predictiveChartsWrapper.predictive-expanded canvas {
      min-height: 420px;
    }

    .predictive-chart-card {
      transition: transform 0.2s ease;
    }

    .confirm-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.55);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 16px;
    }

    .confirm-modal-overlay.show {
      display: flex;
    }

    .confirm-modal-dialog {
      background: #fff;
      border-radius: 14px;
      padding: 24px;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 25px 70px rgba(15, 23, 42, 0.25);
      text-align: center;
    }

    .confirm-modal-dialog h4 {
      margin: 0 0 8px;
      color: #0f172a;
    }

    .confirm-modal-dialog p {
      margin: 0 0 20px;
      color: #475569;
      font-size: 14px;
      line-height: 1.4;
    }

    .confirm-modal-actions {
      display: flex;
      gap: 12px;
      justify-content: center;
    }
    .trend-toggle-group {
      display: flex;
      background: #f1f5f9;
      padding: 4px;
      border-radius: 8px;
      gap: 4px;
    }
    .trend-mode-btn {
      padding: 6px 12px;
      border: none;
      background: transparent;
      border-radius: 6px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
      color: #64748b;
      transition: color 0.2s ease, background-color 0.2s ease;
    }
    .trend-mode-btn:hover { color: #0f172a; background: rgba(255,255,255,0.5); }
    .trend-mode-btn.active { background: #ffffff; color: #0891b2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; }
    .collapse-toggle-btn {
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #334155;
      border-radius: 8px;
      padding: 6px 10px;
      font-size: 13px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .collapse-toggle-btn i { transition: transform .2s ease; }
    .collapse-toggle-btn[aria-expanded="true"] i { transform: rotate(180deg); }
    .dept-table-wrap { overflow: hidden; transition: max-height .22s ease, opacity .22s ease; }
    .dept-table-wrap.collapsed { max-height: 0 !important; opacity: 0; }
  </style>
</head>
<body>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="assets/smooth-interactions.js" defer></script>
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
        <a href="tracking.php" class="menu-item">
          <i class="fas fa-file-signature"></i>
          <span>Document Status</span>
          <span class="menu-badge" id="trackingBadge">0</span>
        </a>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">ANALYTICS</div>
        <a href="stats.php" class="menu-item active">
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
        <h2>Status Reports</h2>
        <div style="display: flex; align-items: center;">
          <?php include __DIR__ . '/partials/notifications.php'; ?>
          <div class="user-profile" id="userProfile">
            <?php 
            $userInfo = getUserDisplayInfo();
            $initials = $userInfo ? getUserInitials($userInfo['name']) : 'U';
            $displayName = $userInfo ? $userInfo['name'] : 'User';
            ?>
            <img src="https://placehold.co/40x40/B2EBF2/0097A7?text=<?php echo urlencode($initials); ?>" alt="User" />
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
      <div class="stats-cards">
        <div class="stat-card">
          <h3>Total Documents</h3>
          <div class="stat-value"><?php echo $totalDocuments; ?></div>
        </div>
        <div class="stat-card">
          <h3>Documents Pending</h3>
          <div class="stat-value"><?php echo (int)$pendingDocuments; ?></div>
        </div>
        <div class="stat-card">
          <h3>Avg Processed / Day</h3>
          <div class="stat-value"><?php echo stats_fmt_number_1dp($avgProcessedPerDay); ?></div>
          <div class="stat-sub">
            <?php
              $pd = $processedDeltaPct;
              if ($pd === null) {
                echo '<span class="trend-flat">No comparison</span><span>vs yesterday</span>';
              } else {
                $cls = ($pd >= 0) ? 'trend-up' : 'trend-down';
                $arrow = ($pd >= 0) ? '▲' : '▼';
                echo '<span class="' . $cls . '">' . $arrow . ' ' . number_format(abs($pd), 1) . '%</span><span>vs yesterday</span>';
              }
            ?>
          </div>
        </div>
        <div class="stat-card">
          <h3>Avg Time / Document</h3>
          <div class="stat-value"><?php echo stats_fmt_duration_short($avgProcessTimeSeconds); ?></div>
          <div class="stat-sub">
            <?php
              $td = $avgTimeDeltaPct;
              if ($td === null) {
                echo '<span class="trend-flat">No comparison</span><span>vs yesterday</span>';
              } else {
                // Lower time is better: negative change is improvement
                $isImproved = ($td < 0);
                $cls = $isImproved ? 'trend-up' : 'trend-down';
                $arrow = $isImproved ? '▼' : '▲';
                $label = $isImproved ? 'faster' : 'slower';
                echo '<span class="' . $cls . '">' . $arrow . ' ' . number_format(abs($td), 1) . '%</span><span>' . $label . ' vs yesterday</span>';
              }
            ?>
          </div>
        </div>
      </div>
      <div class="analytics-section">
        <div class="analytics-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <h3 style="display:flex;align-items:center;gap:8px;"><i class="fas fa-chart-line"></i> Document Processing Trend</h3>
            <div style="display:flex;align-items:center;gap:10px;">
              <div class="trend-toggle-group" id="trendGranularityToggle">
                <button type="button" class="trend-mode-btn" data-granularity="daily">Daily</button>
                <button type="button" class="trend-mode-btn active" data-granularity="monthly">Monthly</button>
              </div>
              <div class="chart-filter" id="yearFilter" style="display:flex;align-items:center;background-color: var(--light-bg); border-radius: 6px; padding: 6px 12px; font-size: 14px; cursor: pointer; position: relative;">
                <span id="selectedYear"><?php echo $currentYear; ?></span>
                <i class="fas fa-chevron-down" style="margin-left:6px;transition:transform .3s;"></i>
                <div class="dropdown-menu" id="yearDropdown" style="position:absolute;top:100%;right:0;background:#fff;border-radius:8px;box-shadow:var(--shadow);min-width:140px;z-index:100;display:none;margin-top:6px;overflow:hidden;">
                  <?php for ($y = 2028; $y >= 2025; $y--): ?>
                  <div class="dropdown-item<?php echo $y===$currentYear?' selected':''; ?>" data-value="<?php echo $y; ?>" style="padding:10px 14px;font-size:14px;cursor:pointer;"><?php echo $y; ?></div>
                  <?php endfor; ?>
                </div>
              </div>
            </div>
          </div>
          <div class="chart-container">
            <canvas id="lineChart"></canvas>
          </div>
        </div>
        <div class="analytics-card">
          <h3><i class="fas fa-chart-pie"></i> Document Status Distribution</h3>
          <div class="chart-container">
            <canvas id="pieChart"></canvas>
          </div>
        </div>
      </div>

      <section class="department-summary" style="margin: 30px 0;">
        <div style="background:#ffffff;padding:24px;border-radius:12px;box-shadow:0 4px 10px rgba(15,23,42,0.08);">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
            <div>
              <h3 style="margin:0;font-size:20px;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-building"></i>
                Department Activity Summary
              </h3>
              <p style="margin:4px 0 0;color:#64748b;font-size:14px;">Total uploads by department (includes all previous actions).</p>
            </div>
            <div style="font-size:14px;color:#475569;">
              <strong>Total Departments:</strong> <?php echo count($deptSummaryLabels); ?>
            </div>
          </div>
          <div style="margin-bottom:12px;">
            <button type="button" class="collapse-toggle-btn" id="deptSummaryToggleBtn" aria-expanded="false">
              <i class="fas fa-chevron-down"></i>
              Show department list
            </button>
          </div>
          <div class="chart-container" style="margin-bottom:24px;height:220px;max-height:35vh;">
            <canvas id="deptSummaryChart"></canvas>
          </div>
          <?php if (!empty($departmentSummary)): ?>
          <div id="deptSummaryTableWrap" class="dept-table-wrap collapsed" style="overflow-x:auto;max-height:0;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;color:#0f172a;">
              <thead>
                <tr style="background:#f8fafc;text-align:left;">
                  <th style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">Department</th>
                  <th style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">Total Uploads</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($departmentSummary as $deptRow): ?>
                <tr>
                  <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;font-weight:600;"><?php echo htmlspecialchars($deptRow['department']); ?></td>
                  <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;"><?php echo (int)($deptRow['total_uploads'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="text-align:center;color:#94a3b8;">No departmental activity recorded yet.</p>
          <?php endif; ?>
        </div>
      </section>
      <section class="predictive-analytics" style="margin-bottom: 30px;">
  <div style="background: #ffffff; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <h3 style="font-size: 20px; color: #0097A7; font-weight: 700; margin-bottom: 16px;">
      <i class="fas fa-chart-line" style="margin-right: 10px;"></i> Document Activity & Forecast
    </h3>

    <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; align-items:flex-end;">
      <div style="display:flex; flex-direction:column; gap:6px;">
        <label for="startDate" style="font-size: 14px; color: #333;">Start Date</label>
        <div style="position:relative;">
          <input type="text" id="startDate" class="date-picker" placeholder="Select start date" style="padding:10px 36px 10px 12px;border:1px solid #e2e8f0;border-radius:10px;outline:none;min-width:180px;" />
          <i id="startDateIcon" class="fas fa-calendar" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#64748b;cursor:pointer;"></i>
        </div>
      </div>
      <div style="display:flex; flex-direction:column; gap:6px;">
        <label for="endDate" style="font-size: 14px; color: #333;">End Date</label>
        <div style="position:relative;">
          <input type="text" id="endDate" class="date-picker" placeholder="Select end date" style="padding:10px 36px 10px 12px;border:1px solid #e2e8f0;border-radius:10px;outline:none;min-width:180px;" />
          <i id="endDateIcon" class="fas fa-calendar" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#64748b;cursor:pointer;"></i>
        </div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <div class="view-mode-group" style="display:flex; background:#f1f5f9; padding:4px; border-radius:8px;">
            <button class="view-mode-btn active" onclick="setPredictiveGranularity('daily')" data-granularity="daily">Daily</button>
            <button class="view-mode-btn" onclick="setPredictiveGranularity('weekly')" data-granularity="weekly">Weekly</button>
        </div>
        <button id="predictiveApplyBtn" onclick="applyPredictiveFilters()" style="background-color: #0097A7; color: white; border: 1px solid #0891b2; padding: 10px 16px; border-radius: 10px; cursor: pointer;">
          Apply
        </button>
        <button id="predictiveResetBtn" onclick="resetPredictiveFilters()" style="background-color: transparent; color: #0f172a; border: 1px solid #cbd5f5; padding: 10px 16px; border-radius: 10px; cursor: pointer;">
          Clear Filter
        </button>
      </div>
    </div>

    <div style="margin-bottom:20px;">
      <small id="predictiveHelperText" style="color:#64748b;">Showing the last 5 days of historical data and the next 5-day forecast.</small>
    </div>

    <div id="predictiveKpiGrid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin:0 0 20px 0;">
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Most Active Document Type</div>
        <div id="kpiTrendDocType" style="font-size:18px;color:#0f172a;font-weight:800;margin-top:6px;">--</div>
        <div id="kpiTrendDocTypeSub" style="font-size:12px;color:#94a3b8;margin-top:4px;">--</div>
      </div>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Peak Period</div>
        <div id="kpiPeakPeriod" style="font-size:18px;color:#0f172a;font-weight:800;margin-top:6px;">--</div>
        <div id="kpiPeakPeriodSub" style="font-size:12px;color:#94a3b8;margin-top:4px;">--</div>
      </div>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
        <div style="font-size:12px;color:#64748b;font-weight:600;">Avg. Processed / Day</div>
        <div id="kpiProcessedPerDay" style="font-size:18px;color:#0f172a;font-weight:800;margin-top:6px;">--</div>
        <div id="kpiProcessedPerDaySub" style="font-size:12px;color:#94a3b8;margin-top:4px;">--</div>
      </div>

    </div>

    <style>
      /* Make predictive cards equal height */
      #predictiveChartsWrapper .predictive-chart-card { display:flex; flex-direction:column; height:100%; }
      #predictiveChartsWrapper .predictive-chart-card .chart-body { flex:1; position:relative; min-height:380px; }
      .view-mode-btn {
        padding: 6px 12px;
        border: none;
        background: transparent;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        color: #64748b;
        transition: color 0.2s ease, background-color 0.2s ease;
      }
      .view-mode-btn:hover { color: #0f172a; background: rgba(255,255,255,0.5); }
      .view-mode-btn.active { background: #ffffff; color: #0891b2; box-shadow: 0 1px 2px rgba(0,0,0,0.05); font-weight: 600; }
    </style>
    <div id="predictiveChartsWrapper" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:20px;align-items:stretch;">
      <div class="predictive-chart-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
        <h4 style="margin:0 0 16px 0;font-size:15px;color:#0f172a;font-weight:600;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-history" style="color:#94a3b8;"></i> Documents Processed
        </h4>
        <div class="chart-body" style="height:380px;position:relative;width:100%;">
          <canvas id="historyChartStats"></canvas>
        </div>
      </div>
      <div class="predictive-chart-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
        <h4 style="margin:0 0 16px 0;font-size:15px;color:#0f172a;font-weight:600;display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap;">
          <span style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-magic" style="color:#0ea5e9;"></i> Document Forecasting
          </span>
          <span style="display:inline-flex;align-items:center;gap:10px;">
            <span id="kpiForecastAccuracy" style="font-size:11px;font-weight:700;color:#0f766e;background:#ecfeff;border:1px solid #99f6e4;border-radius:999px;padding:4px 8px;line-height:1;">Model Accuracy: --%</span>
            <div class="trend-toggle-group" id="forecastViewToggle">
              <button type="button" class="trend-mode-btn active" data-view="forecast" onclick="toggleForecastView('forecast')">Forecast</button>
              <button type="button" class="trend-mode-btn" data-view="comparison" onclick="toggleForecastView('comparison')">Comparison</button>
            </div>
          </span>
        </h4>
        <div class="chart-body" style="height:380px;position:relative;width:100%;">
          <canvas id="forecastChartStats"></canvas>
        </div>
      </div>
    </div>
    
  </div>
</section>

      <!-- Document Status View and export actions removed to keep Status Reports focused on analytics. -->
    </div>
  </div>

  <!-- Delete confirmation modal removed with table section -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // User Profile Dropdown
    const userProfile = document.getElementById('userProfile');
    const userDropdown = document.getElementById('userDropdown');
    userProfile.addEventListener('click', function(e) {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
    });
    document.body.addEventListener('click', function() {
      userDropdown.classList.remove('show');
    });

    // Notifications are managed by partials/notifications.php. Ensure it's initialized.
    document.addEventListener('DOMContentLoaded', () => {
      if (typeof window.loadNotifications === 'function') {
        window.loadNotifications();
      }
    });

    // PHP data for charts
    const totalDocuments = <?php echo $totalDocuments; ?>;
    const statusCounts = <?php echo json_encode($statusCounts); ?>;
    const pieChartLabels = <?php echo json_encode($pieChartLabels); ?>;
    const pieChartData = <?php echo json_encode($pieChartData); ?>;
    const pieChartColors = <?php echo json_encode($pieChartColors); ?>;
    const lineChartLabels = <?php echo json_encode($lineChartLabels); ?>;
    const lineChartDataProcessed = <?php echo json_encode($lineChartDataProcessed); ?>;
    const deptSummaryLabels = <?php echo json_encode($deptSummaryLabels); ?>;
    const deptSummaryTotals = <?php echo json_encode($deptSummaryTotals); ?>;
    const __statsDebug = (new URLSearchParams(window.location.search).get('debug') === '1');
    const __statsDbg = (...args) => { if (__statsDebug) { try { console.debug('[stats]', ...args); } catch(_){} } };
    // Table and deletion logic removed; this page focuses on analytics only


    // Chart.js Line Chart (Document Processing Trend) - modernized
    const lineCtx = document.getElementById('lineChart').getContext('2d');
    const lineGrad = lineCtx.createLinearGradient(0, 0, 0, 260);
    lineGrad.addColorStop(0, 'rgba(0, 188, 212, 0.35)');
    lineGrad.addColorStop(1, 'rgba(0, 188, 212, 0.05)');
    let lineChart = new Chart(lineCtx, {
      type: 'line',
      data: {
        labels: lineChartLabels,
        datasets: [
          {
            label: 'Documents Processed',
            data: lineChartDataProcessed,
            borderColor: '#00BCD4',
            backgroundColor: lineGrad,
            tension: 0.35,
            fill: true,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#00BCD4',
            pointBorderColor: '#fff',
            pointBorderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          tooltip: { mode: 'index', intersect: false, callbacks: { label: (ctx) => ` ${ctx.formattedValue} documents` } },
          legend: { labels: { color: '#263238', font: { weight: '500' } } }
        },
        scales: {
          x: { ticks: { color: '#78909C' }, grid: { color: '#ECEFF1' } },
          y: { ticks: { color: '#78909C' }, grid: { color: '#ECEFF1' }, beginAtZero: true, title: { display: true, text: 'Number of Documents' } }
        }
      }
    });

    // Year dropdown for Document Processing Trend (smooth toggle + auto-close)
    (function initYearDropdown(){
      const yearFilter = document.getElementById('yearFilter');
      const yearDropdown = document.getElementById('yearDropdown');
      const selectedYearEl = document.getElementById('selectedYear');
      const granularityToggle = document.getElementById('trendGranularityToggle');
      let currentTrendGranularity = 'monthly';
      const getGranularity = () => {
        return (currentTrendGranularity === 'daily' || currentTrendGranularity === 'monthly')
          ? currentTrendGranularity
          : 'monthly';
      };
      const applyTrendData = (data) => {
        if (data && Array.isArray(data.labels) && Array.isArray(data.counts)) {
          lineChart.data.labels = data.labels;
          lineChart.data.datasets[0].data = data.counts;
          lineChart.update();
        }
      };
      const fetchTrend = async (year) => {
        const g = getGranularity();
        const resp = await fetch(`stats.php?action=monthly_processed_counts&year=${encodeURIComponent(year)}&granularity=${encodeURIComponent(g)}&_=${Date.now()}`, { cache: 'no-store' });
        return await resp.json();
      };
      if (!yearFilter) return;
      if (granularityToggle) {
        granularityToggle.querySelectorAll('.trend-mode-btn').forEach(btn => {
          btn.addEventListener('click', async function(){
            const g = this.getAttribute('data-granularity') || 'monthly';
            currentTrendGranularity = (g === 'daily') ? 'daily' : 'monthly';
            granularityToggle.querySelectorAll('.trend-mode-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            try {
              const y = selectedYearEl ? selectedYearEl.textContent.trim() : String(new Date().getFullYear());
              const data = await fetchTrend(y);
              applyTrendData(data);
            } catch (e) {
              __statsDbg('Failed to load monthly_processed_counts', e);
            }
          });
        });
      }
      yearFilter.addEventListener('click', (e)=>{
        e.stopPropagation();
        const willShow = !yearDropdown.classList.contains('show');
        document.querySelectorAll('.dropdown-menu').forEach(m=>m.classList.remove('show'));
        yearDropdown.style.display = willShow ? 'block' : 'none';
        if (willShow) {
          yearDropdown.classList.add('dropdown-enter');
          setTimeout(()=>yearDropdown.classList.add('show'), 0);
        } else {
          yearDropdown.classList.remove('show');
        }
      });
      yearDropdown.querySelectorAll('.dropdown-item').forEach(item=>{
        item.addEventListener('click', async function(){
          const y = this.getAttribute('data-value');
          selectedYearEl.textContent = y;
          yearDropdown.querySelectorAll('.dropdown-item').forEach(i=>i.classList.remove('selected'));
          this.classList.add('selected');
          yearDropdown.classList.remove('show');
          yearDropdown.style.display = 'none';
          try {
            const data = await fetchTrend(y);
            applyTrendData(data);
          } catch (e) {
            __statsDbg('Failed to load monthly_processed_counts', e);
          }
        });
      });
      document.addEventListener('click', ()=>{
        yearDropdown.classList.remove('show');
        yearDropdown.style.display = 'none';
      });
      yearDropdown.addEventListener('click', e=>e.stopPropagation());
    })();

    // Department summary table collapse/expand
    (function initDeptSummaryCollapse(){
      const btn = document.getElementById('deptSummaryToggleBtn');
      const wrap = document.getElementById('deptSummaryTableWrap');
      if (!btn || !wrap) return;
      const setExpanded = (expanded) => {
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        btn.innerHTML = expanded
          ? '<i class="fas fa-chevron-down"></i> Hide department list'
          : '<i class="fas fa-chevron-down"></i> Show department list';
        if (expanded) {
          wrap.classList.remove('collapsed');
          wrap.style.maxHeight = wrap.scrollHeight + 'px';
          wrap.style.opacity = '1';
        } else {
          wrap.classList.add('collapsed');
          wrap.style.maxHeight = '0px';
          wrap.style.opacity = '0';
        }
      };
      setExpanded(false);
      btn.addEventListener('click', () => {
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        setExpanded(!expanded);
      });
      window.addEventListener('resize', () => {
        if (btn.getAttribute('aria-expanded') === 'true') {
          wrap.style.maxHeight = wrap.scrollHeight + 'px';
        }
      });
    })();

    // Chart.js Doughnut Chart (Document Status Distribution) - modernized
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    const pieChart = new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: pieChartLabels,
        datasets: [{
          data: pieChartData,
          backgroundColor: pieChartColors,
          borderWidth: 2,
          borderColor: '#fff',
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { color: '#263238', font: { weight: '500' } } },
          tooltip: {
            callbacks: {
              label: ctx => {
                const label = ctx.label || '';
                const value = ctx.raw || 0;
                const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                const percent = ((value / total) * 100).toFixed(1);
                return `${label}: ${value} (${percent}%)`;
              }
            }
          }
        }
      }
    });

    // 1) History Chart
    const historyCanvas = document.getElementById('historyChartStats');
    const ctxHistory = historyCanvas.getContext('2d');
    const historyChart = new Chart(ctxHistory, {
      type: 'line',
      data: { labels: [], datasets: [] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: true, position:'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 15, font:{size:11, weight:600} } },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, font: { size: 11 }, color:'#94a3b8' } },
          y: { beginAtZero: true, min: 0, ticks: { stepSize: 1, font: { size: 11 }, color:'#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } }
        }
      }
    });

    const defaultPredictiveHelperMessage = 'Showing the last 5 days of document activity and the next 5-day forecast.';

    // 2) Forecast Chart — also hosts the Comparison (backtest) toggle view
    const forecastCanvas = document.getElementById('forecastChartStats');
    const ctxForecast = forecastCanvas.getContext('2d');

    // State for forecast/comparison toggle
    let forecastViewMode = 'forecast'; // 'forecast' or 'comparison'
    let cachedForecastDatasets = { labels: [], datasets: [] };
    let cachedComparisonDatasets = { labels: [], datasets: [] };

    const forecastChart = new Chart(ctxForecast, {
      type: 'line',
      data: { labels: [], datasets: [] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              padding: 15,
              color: '#334155',
              font: { size: 11, weight: 600 }
            }
          },
          tooltip: {
            mode: 'index', intersect: false,
            callbacks: {
              label: (ctx) => {
                const valRaw = ctx.parsed.y;
                const val = Number.isFinite(valRaw) ? Math.round(valRaw) : valRaw;
                const lbl = (ctx && ctx.dataset && ctx.dataset.label) ? String(ctx.dataset.label) : 'Document Forecast';
                return ` ${lbl}: ${val}`;
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, font: { size: 11 }, color:'#94a3b8' }, title: { display: false } },
          y: { beginAtZero: true, min: 0, ticks: { stepSize: 1, font: { size: 11 }, color:'#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' }, title: { display: false } }
        }
      }
    });

    // Empty-state overlay
    function ensureForecastEmptyOverlay(){
      let el = document.getElementById('forecast-empty-state');
      if (!el) {
        el = document.createElement('div');
        el.id = 'forecast-empty-state';
        el.style.position = 'absolute';
        el.style.inset = '0';
        el.style.display = 'none';
        el.style.alignItems = 'center';
        el.style.justifyContent = 'center';
        el.style.color = '#64748b';
        el.style.fontSize = '14px';
        el.style.fontWeight = '600';
        forecastCanvas.parentElement.style.position = 'relative';
        forecastCanvas.parentElement.appendChild(el);
      }
      return el;
    }

    // State for predictive view
    let currentPredictiveGranularity = 'daily'; // daily, weekly
    let predictiveDataCache = null;
    const predictiveCacheByGranularity = { daily: null, weekly: null };

    function setPredictiveGranularity(granularity) {
        currentPredictiveGranularity = (granularity === 'weekly') ? 'weekly' : 'daily';
        document.querySelectorAll('.view-mode-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.granularity === currentPredictiveGranularity);
        });
        // If cached data exists for the target granularity, render immediately; otherwise fetch
        if (predictiveCacheByGranularity[currentPredictiveGranularity]) {
          predictiveDataCache = predictiveCacheByGranularity[currentPredictiveGranularity];
          renderForecastFromCache();
        } else {
          applyPredictiveFilters();
        }
    }

    function loadForecast(h = 5, source = 'both', startDate = '', endDate = '', granularity = 'daily', displayDays = 5) {
      const params = new URLSearchParams({ action:'predict_volume', h:String(h), source:String(source), granularity:String(granularity), display_days:String(displayDays), _:String(Date.now()) });
      if (startDate) params.set('start_date', startDate);
      if (endDate) params.set('end_date', endDate);
      
      // Show loading state if needed
      
      const url = `stats.php?${params.toString()}`;
      fetch(url, { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
            predictiveDataCache = d;
            predictiveCacheByGranularity[currentPredictiveGranularity] = d;
            renderForecastFromCache();
        })
        .catch(err => {
            console.error(err);
            renderForecastEmpty();
        });
    }

    function renderForecastFromCache() {
        if (!predictiveDataCache) return;
        
        const d = predictiveDataCache;
        
        let histDatasets = [];
        let fcDatasets = [];
        
        let histLabels = [];
        let fcLabels = [];
        
        const prepSeries = (key, label, colorSolid, colorDash) => {
            const data = d[key]; // uploads or processed
            if (!data || !data.hist_labels) return null;
            const normalizeSeriesValues = (arr) => (Array.isArray(arr) ? arr.map((v) => {
                const n = Number(v);
                return Number.isFinite(n) ? n : 0;
            }) : []);
            
            // Capture labels (once)
            if (histLabels.length === 0 && data.hist_labels) histLabels = data.hist_labels;
            if (fcLabels.length === 0 && data.forecast_labels) fcLabels = data.forecast_labels;
            
            // History Dataset
            const dsHist = {
                type: 'line',
                label: label,
                data: normalizeSeriesValues(data.hist_values),
                borderColor: colorSolid,
                backgroundColor: colorSolid,
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.3,
                spanGaps: true,
                fill: false
            };
            
            // Forecast Dataset
            const dsFc = {
                type: 'line',
                label: label,
                data: normalizeSeriesValues(data.forecast_values),
                borderColor: colorDash,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#fff',
                pointBorderColor: colorDash,
                pointBorderWidth: 2,
                segment: {
                  borderColor: (ctx) => {
                    const c = ctx?.p0?.parsed?.y;
                    return Number.isFinite(c) ? colorDash : colorDash;
                  }
                },
                tension: 0.3,
                spanGaps: true,
                fill: false
            };
            
            return { hist: dsHist, fc: dsFc };
        };

        const setUploads = prepSeries('uploads', 'Uploads', '#0ea5e9', '#0ea5e9');
        const setProcessed = prepSeries('processed', 'Processed', '#8b5cf6', '#8b5cf6');

        // ── Merge uploads + processed into single "Documents Processed" line ──
        const mergedHistValues = [];
        const uHistVals = setUploads ? setUploads.hist.data : [];
        const pHistVals = setProcessed ? setProcessed.hist.data : [];
        const mergedLen = Math.max(uHistVals.length, pHistVals.length);
        for (let i = 0; i < mergedLen; i++) {
          mergedHistValues.push((Number(uHistVals[i]) || 0) + (Number(pHistVals[i]) || 0));
        }
        const mergedHistDs = {
          type: 'line',
          label: 'Documents Processed',
          data: mergedHistValues,
          borderColor: '#0ea5e9',
          backgroundColor: 'rgba(14,165,233,0.08)',
          borderWidth: 2,
          pointRadius: 3,
          tension: 0.3,
          spanGaps: true,
          fill: true
        };
        histDatasets.push(mergedHistDs);

        // ── Merge forecast into single "Document Forecast" line (no bar) ──
        const uFcVals = setUploads ? setUploads.fc.data : [];
        const pFcVals = setProcessed ? setProcessed.fc.data : [];
        const mergedFcValues = [];
        const mergedFcLen = Math.max(uFcVals.length, pFcVals.length);
        for (let i = 0; i < mergedFcLen; i++) {
          mergedFcValues.push((Number(uFcVals[i]) || 0) + (Number(pFcVals[i]) || 0));
        }
        const mergedFcDs = {
          type: 'line',
          label: 'Document Forecast',
          data: mergedFcValues,
          borderColor: '#8b5cf6',
          backgroundColor: 'transparent',
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 5,
          pointBackgroundColor: '#fff',
          pointBorderColor: '#8b5cf6',
          pointBorderWidth: 2,
          tension: 0.3,
          spanGaps: true,
          fill: false,
          order: 1
        };
        fcDatasets.push(mergedFcDs);

        renderHistoryChart(histLabels, histDatasets);
        renderForecastChart(fcLabels, fcDatasets);
        cacheBacktestData();
        updatePredictiveKpis();
    }

    // Cache backtest (comparison) data — no longer renders to a separate chart
    function cacheBacktestData() {
      if (!predictiveDataCache || !predictiveDataCache.backtest) {
        cachedComparisonDatasets = { labels: [], datasets: [] };
        setBacktestAccuracyBadge(null);
        return;
      }
      const bt = predictiveDataCache.backtest;
      const pBt = bt.processed || {};

      const primarySrc = (pBt.labels && pBt.labels.length) ? pBt : null;
      if (!primarySrc || !primarySrc.labels || !primarySrc.labels.length) {
        cachedComparisonDatasets = { labels: [], datasets: [] };
        setBacktestAccuracyBadge(null);
        return;
      }
      const labels = Array.isArray(primarySrc.labels) ? primarySrc.labels : [];
      const normalizeSeries = (arr, len) => {
        const out = [];
        const srcArr = Array.isArray(arr) ? arr : [];
        for (let i = 0; i < len; i++) {
          const n = Number(srcArr[i]);
          out.push(Number.isFinite(n) ? n : 0);
        }
        return out;
      };

      const datasets = [];
      const hasProcessed = pBt.labels && pBt.labels.length > 0;

      if (hasProcessed) {
        const pActual = normalizeSeries(pBt.actual, labels.length);
        const pPredicted = normalizeSeries(pBt.predicted, labels.length);
        datasets.push({
          label: 'Actual Documents',
          data: pActual,
          borderColor: '#8b5cf6',
          backgroundColor: 'rgba(139,92,246,0.06)',
          borderWidth: 2.5,
          pointRadius: 4,
          pointBackgroundColor: '#8b5cf6',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          tension: 0.35,
          spanGaps: true,
          fill: true,
          order: 2
        });
        datasets.push({
          label: 'Predicted Documents',
          data: pPredicted,
          borderColor: '#0ea5e9',
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [],
          borderDashOffset: 0,
          pointRadius: 3,
          pointStyle: 'circle',
          pointBackgroundColor: '#0ea5e9',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          tension: 0.35,
          spanGaps: true,
          fill: false,
          order: 1
        });

        // Compute accuracy
        const computeAccuracyPct = (actual, predicted) => {
          const count = Math.min(actual.length, predicted.length);
          if (count <= 0) return null;
          const nonZeroIdx = [];
          for (let i = 0; i < count; i++) {
            if ((Number(actual[i]) || 0) > 0) nonZeroIdx.push(i);
          }
          const idxs = nonZeroIdx.length ? nonZeroIdx : Array.from({ length: count }, (_, i) => i);
          let errSum = 0;
          for (const i of idxs) {
            const a = Number(actual[i]) || 0;
            const p = Number(predicted[i]) || 0;
            const denom = Math.max(1, Math.abs(a));
            errSum += Math.abs(a - p) / denom;
          }
          const mape = errSum / idxs.length;
          return Math.max(0, Math.min(100, (1 - mape) * 100));
        };
        setBacktestAccuracyBadge(computeAccuracyPct(pActual, pPredicted));
      }

      cachedComparisonDatasets = { labels, datasets };

      // If user is currently viewing comparison, refresh
      if (forecastViewMode === 'comparison') {
        forecastChart.data.labels = labels;
        forecastChart.data.datasets = datasets;
        forecastChart.update();
      }
    }

    function setBacktestAccuracyBadge(pct) {
      const el = document.getElementById('kpiForecastAccuracy');
      if (!el) return;
      if (!Number.isFinite(pct)) {
        el.textContent = 'Model Accuracy: --%';
        el.style.color = '#64748b';
        el.style.background = '#f8fafc';
        el.style.borderColor = '#e2e8f0';
        return;
      }
      const val = Math.round(pct * 10) / 10;
      el.textContent = `Model Accuracy: ${val}%`;
      if (val >= 80) {
        el.style.color = '#0f766e';
        el.style.background = '#ecfeff';
        el.style.borderColor = '#99f6e4';
      } else if (val >= 60) {
        el.style.color = '#9a3412';
        el.style.background = '#fff7ed';
        el.style.borderColor = '#fdba74';
      } else {
        el.style.color = '#991b1b';
        el.style.background = '#fef2f2';
        el.style.borderColor = '#fca5a5';
      }
    }

    // Toggle between Forecast and Comparison views on Chart 2
    function toggleForecastView(mode) {
      forecastViewMode = (mode === 'comparison') ? 'comparison' : 'forecast';
      // Update toggle button states
      const toggle = document.getElementById('forecastViewToggle');
      if (toggle) {
        toggle.querySelectorAll('.trend-mode-btn').forEach(btn => {
          btn.classList.toggle('active', btn.getAttribute('data-view') === forecastViewMode);
        });
      }
      if (forecastViewMode === 'comparison') {
        forecastChart.data.labels = cachedComparisonDatasets.labels;
        forecastChart.data.datasets = cachedComparisonDatasets.datasets;
      } else {
        forecastChart.data.labels = cachedForecastDatasets.labels;
        forecastChart.data.datasets = cachedForecastDatasets.datasets;
      }
      forecastChart.update();
    }

    function updatePredictiveKpis() {
      const d = predictiveDataCache;
      const k = d?.meta?.kpis || {};
      const unit = (d?.meta?.granularity === 'weekly') ? 'week' : 'day';
      const rangeLabel = (() => {
        const rs = d?.meta?.range_start;
        const re = d?.meta?.range_end;
        if (rs && re) return `as of ${re}`;
        if (re) return `as of ${re}`;
        return '';
      })();

      const setText = (id, txt) => {
        const el = document.getElementById(id);
        if (el) el.textContent = txt;
      };

      setText('kpiTrendDocType', k.trend_doc_type?.type || '--');
      const trendCountText = (k.trend_doc_type && Number.isFinite(k.trend_doc_type.count)) ? `Count: ${k.trend_doc_type.count}` : '--';
      setText('kpiTrendDocTypeSub', rangeLabel ? `${trendCountText} • ${rangeLabel}` : trendCountText);

      setText('kpiPeakPeriod', (k.peak_processed && k.peak_processed.label) ? String(k.peak_processed.label) : '--');
      setText('kpiPeakPeriodSub', (k.peak_processed && Number.isFinite(k.peak_processed.count)) ? `Processed: ${k.peak_processed.count} per ${unit}` : '--');

      setText('kpiProcessedPerDay', (k.avg_processed_per_unit && Number.isFinite(k.avg_processed_per_unit.value)) ? String(k.avg_processed_per_unit.value) : '--');
      setText('kpiProcessedPerDaySub', (k.avg_processed_per_unit && k.avg_processed_per_unit.unit) ? `Average per ${k.avg_processed_per_unit.unit}` : '--');


    }
    
    function renderHistoryChart(labels, datasets) {
      if (!labels || labels.length === 0) {
          // Empty state
          return;
      }
      historyChart.data.labels = labels;
      historyChart.data.datasets = datasets;
      historyChart.update();
    }

    function renderForecastChart(labels, datasets) {
      const overlay = ensureForecastEmptyOverlay();
      if (!labels || labels.length === 0) {
        cachedForecastDatasets = { labels: [], datasets: [] };
        overlay.textContent = 'No Forecast Data';
        overlay.style.display = 'flex';
        return;
      }
      overlay.style.display = 'none';

      // Cache forecast datasets for toggle support
      cachedForecastDatasets = { labels: [...labels], datasets: [...datasets] };

      // Only render if currently in forecast view
      if (forecastViewMode === 'forecast') {
        forecastChart.data.labels = labels;
        forecastChart.data.datasets = datasets;
        forecastChart.update();
      }
      
      // Auto-update helper text with uniform terminology
      const meta = predictiveDataCache?.meta || {};
      const h = meta.horizon || 5;
      const d = meta.display_days || 5;
      const unit = (meta.granularity === 'weekly') ? 'weeks' : 'days';
      showPredictiveHelper(`Showing the last ${d} ${unit} of document activity and the next ${h}-${unit.slice(0,-1)} forecast.`);
    }
    
    // Legacy / Unused function stubs removed or redirected
    function renderChartWithData(labels, datasets) { /* No-op now */ }

    function renderForecastEmpty(){
      const overlay = ensureForecastEmptyOverlay();
      const hasLabels = Array.isArray(forecastChart.data.labels) && forecastChart.data.labels.length > 0;
      const hasDatasets = Array.isArray(forecastChart.data.datasets) && forecastChart.data.datasets.length > 0;
      if (hasLabels || hasDatasets) {
        overlay.style.display = 'none';
        return;
      }
      overlay.textContent = 'No Historical Data Available to Generate Forecast';
      overlay.style.display = 'flex';
    }

    // Initial load (detect optional source control)
    (function(){
      const srcEl = document.getElementById('predictiveSource');
      const source = srcEl && srcEl.value ? srcEl.value : 'both';
      loadForecast(5, source, '', '', currentPredictiveGranularity, 5);
    })();

    function applyPredictiveFilters() {
      const start = document.getElementById('startDate')?.value || '';
      const end = document.getElementById('endDate')?.value || '';
      const srcEl = document.getElementById('predictiveSource');
      const source = srcEl && srcEl.value ? srcEl.value : 'both';
      let w = 5;

      // Calculate display days based on date range if custom dates are set
      let displayDays = 5;
      if (start && end) {
        const s = new Date(start);
        const e = new Date(end);
        if (!isNaN(s) && !isNaN(e) && e >= s) {
          const diffMs = e - s;
          const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24)) + 1;
          if (currentPredictiveGranularity === 'weekly') {
            displayDays = Math.max(3, Math.min(12, Math.ceil(diffDays / 7)));
          } else {
            displayDays = Math.max(3, Math.min(30, diffDays));
          }
          w = Math.max(3, Math.min(10, displayDays));
        }
      }

      loadForecast(w, source, start, end, currentPredictiveGranularity, displayDays);
    }

    function resetPredictiveFilters() {
      const startEl = document.getElementById('startDate');
      const endEl = document.getElementById('endDate');
      if (startEl) startEl.value = '';
      if (endEl) endEl.value = '';
      const srcEl = document.getElementById('predictiveSource');
      const source = srcEl && srcEl.value ? srcEl.value : 'both';
      loadForecast(5, source, '', '', currentPredictiveGranularity, 5);
    }

    function showPredictiveHelper(message, highlight = false) {
      const helper = document.getElementById('predictiveHelperText');
      if (!helper) return;
      helper.textContent = message || defaultPredictiveHelperMessage;
      helper.style.color = highlight ? '#dc2626' : '#64748b';
    }


    // Toast Notification Function (retained)
    function showToast(message, type = 'info', duration = 3000) {
        const toastContainer = document.getElementById('toast-container') || (() => {
            const div = document.createElement('div');
            div.id = 'toast-container';
            document.body.appendChild(div);
            return div;
        })();

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let iconClass = '';
        if (type === 'success') iconClass = 'fa-check-circle';
        else if (type === 'error') iconClass = 'fa-times-circle';
        else if (type === 'info') iconClass = 'fa-info-circle';
        else if (type === 'warning') iconClass = 'fa-exclamation-triangle';

        toast.innerHTML = `<i class="fas ${iconClass}"></i> ${message}`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 3000);
    }

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof loadNotifications === 'function') loadNotifications();
      if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
    });

    // Ensure auto-refresh if shared function exists
    if (typeof window.loadNotifications === 'function') {
      setInterval(window.loadNotifications, 30000);
    }
    
    // Sidebar badges handled globally by assets/smooth-interactions.js
    
    // Note: Notification handlers are declared above; avoid re-declaration here to prevent JS errors.

    // Initialize Predictive date pickers and make icons open calendars
    (function initPredictivePickers(){
      try {
        if (typeof flatpickr === 'undefined') return;
        const startEl = document.getElementById('startDate');
        const endEl = document.getElementById('endDate');
        if (!startEl || !endEl) return;
        const endPicker = flatpickr(endEl, {
          dateFormat: 'Y-m-d',
          static: true,                // render inside wrapper so it shifts with layout
          appendTo: endEl.parentElement
        });
        const startPicker = flatpickr(startEl, {
          dateFormat: 'Y-m-d',
          static: true,
          appendTo: startEl.parentElement,
          onChange: function(sel){ if (sel && sel[0]) { endPicker.set('minDate', sel[0]); } }
        });
        const startIcon = document.getElementById('startDateIcon');
        const endIcon = document.getElementById('endDateIcon');
        if (startIcon) startIcon.addEventListener('click', function(e){ e.preventDefault(); startPicker.open(); });
        if (endIcon) endIcon.addEventListener('click', function(e){ e.preventDefault(); endPicker.open(); });

        // Re-position calendars when sidebar expands/collapses or window resizes
        const sb = document.querySelector('.sidebar');
        const reposition = () => {
          try { startPicker && startPicker.positionCalendar && startPicker.positionCalendar(); } catch(_){}
          try { endPicker && endPicker.positionCalendar && endPicker.positionCalendar(); } catch(_){}
        };
        if (sb) {
          sb.addEventListener('mouseenter', reposition);
          sb.addEventListener('mouseleave', reposition);
        }
        window.addEventListener('resize', reposition);
      } catch(_) {}
    })();

    const deptCanvas = document.getElementById('deptSummaryChart');
    if (deptCanvas) {
      const barCtx = deptCanvas.getContext('2d');
      const labels = deptSummaryLabels || [];
      const totals = deptSummaryTotals || [];
      __statsDbg('dept_summary', { labels, totals });
      if (window.__deptSummaryChart) { try { window.__deptSummaryChart.destroy(); } catch(_){} }
      window.__deptSummaryChart = new Chart(barCtx, {
        type: 'bar',
        data: {
          labels,
          datasets: [
            {
              label: 'Total Uploads',
              data: totals,
              backgroundColor: 'rgba(14, 165, 233, 0.85)',
              borderRadius: 6
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom' }
          },
          scales: {
            x: {
              ticks: { color: '#475569', autoSkip: false },
              grid: { display: false }
            },
            y: {
              beginAtZero: true,
              ticks: { color: '#475569' },
              grid: { color: 'rgba(148,163,184,0.2)' }
            }
          }
        }
      });
    }
  </script>
</body>
</html>
