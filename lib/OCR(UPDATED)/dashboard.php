<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: log-in.php');
    exit();
}
session_write_close(); // Release session lock early

// Helper function to calculate time ago
function getTimeAgo($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' min' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hr' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

// Shared configuration + helpers
require_once __DIR__ . '/config.php';
require_once 'user_profile_widget.php';
require_once 'settings_util.php';
require_once 'security.php';
$__isAdmin = Security::is_admin();

if (!function_exists('logDashboardTiming')) {
    function logDashboardTiming(string $label, float $startTime): void {
        error_log(sprintf('[dashboard.php] %s took %.1f ms', $label, (microtime(true) - $startTime) * 1000));
    }
}

// Check if this is a fresh login (welcome parameter)
$showWelcome = isset($_GET['welcome']) && $_GET['welcome'] === 'true';
$userInfo = getUserDisplayInfo();
$userName = $userInfo ? $userInfo['name'] : 'User';

// Database connection details
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check database connection
if ($connection->connect_error) {
    error_log("Database connection failed: " . $connection->connect_error);
    die("Error connecting to the database. Please try again later.");
}

// Set charset to ensure proper encoding
$connection->set_charset("utf8mb4");

// --- Fetch Dashboard Data ---

// Department isolation: non-admin users see only their department
$__deptFilter = '';
$__deptFilterArchive = '';
if (!$__isAdmin && !empty($_SESSION['user_department'])) {
    $__deptEsc = $connection->real_escape_string($_SESSION['user_department']);
    $__deptFilter = " AND tracking.department = '$__deptEsc'";
    $__deptFilterArchive = " AND archive.department = '$__deptEsc'";
}

// Aggregate all tracking statuses once for downstream metrics
$statusCounts = [];
$statusAggTimer = microtime(true);
$sql = "SELECT status, COUNT(*) AS count FROM tracking WHERE 1=1 $__deptFilter GROUP BY status";
$stmt = $connection->prepare($sql);

if ($stmt) {
    $stmt->execute();
    $statusAggResult = $stmt->get_result();
    logDashboardTiming('status aggregate query', $statusAggTimer);
    
    if ($statusAggResult) {
        while ($row = $statusAggResult->fetch_assoc()) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }
        $statusAggResult->free();
    }
    $stmt->close();
} else {
    error_log("Failed to prepare status aggregation query: " . $connection->error);
}

$completedDocs = ($statusCounts['Completed'] ?? 0) + ($statusCounts['Approved'] ?? 0);

// Overdue documents mirror tracking.php logic (>=4 days, still active)
$overdueDocs = 0;
$overdueSql = "SELECT COUNT(*) AS count
               FROM tracking
               WHERE COALESCE(status,'') NOT IN ('Completed','Approved','Archived')
                 AND DATEDIFF(CURDATE(), DATE(COALESCE(created_at, date_submitted))) >= 4 $__deptFilter";
$overdueTimer = microtime(true);

$stmt = $connection->prepare($overdueSql);
if ($stmt) {
    $stmt->execute();
    $overdueResult = $stmt->get_result();
    logDashboardTiming('overdue total query', $overdueTimer);
    
    if ($overdueResult && $row = $overdueResult->fetch_assoc()) {
        $overdueDocs = (int)$row['count'];
    }
    $stmt->close();
} else {
    error_log("Failed to prepare overdue documents query: " . $connection->error);
}

$pendingDocsCount = $statusCounts['Pending'] ?? 0;
$inReviewCount = $statusCounts['In Review'] ?? 0;

// Mimic the legacy random split for Archived/Rejected rows without fetching them all
$archivedCount = $statusCounts['Archived'] ?? 0;
if ($archivedCount > 0) {
    $pendingFromArchived = random_int(0, $archivedCount);
    $pendingDocsCount += $pendingFromArchived;
    $inReviewCount += ($archivedCount - $pendingFromArchived);
}
$rejectedCount = $statusCounts['Rejected'] ?? 0;
if ($rejectedCount > 0) {
    $pendingFromRejected = random_int(0, $rejectedCount);
    $pendingDocsCount += $pendingFromRejected;
    $inReviewCount += ($rejectedCount - $pendingFromRejected);
}
$totalPendingAndReview = $pendingDocsCount + $inReviewCount;

// 3. Total Documents Archived (from archive table)
$archivedDocs = 0;
$sql_archived = "SELECT COUNT(*) AS count FROM archive WHERE 1=1 $__deptFilterArchive";
$archivedTimer = microtime(true);

$stmt = $connection->prepare($sql_archived);
if ($stmt) {
    $stmt->execute();
    $result_archived = $stmt->get_result();
    logDashboardTiming('archive total query', $archivedTimer);
    
    if ($result_archived && $row = $result_archived->fetch_assoc()) {
        $archivedDocs = (int)$row['count'];
    }
    $stmt->close();
} else {
    error_log("Failed to prepare archived documents query: " . $connection->error);
}

// 4. Fetch ALL documents and apply same logic as tracking.php
$pendingDocuments = [];
$pendingOnly = [];
$inReviewOnly = [];

// Get recent documents uploaded today
$today = date('Y-m-d');
$sql_recent_documents = "SELECT id, type, employee_name, date_submitted, department, status, current_holder, end_location, created_at 
                        FROM tracking 
                        WHERE DATE(COALESCE(created_at, date_submitted)) = ? $__deptFilter
                        ORDER BY COALESCE(created_at, date_submitted) DESC 
                        LIMIT 10";
$recentDocuments = [];

$stmt = $connection->prepare($sql_recent_documents);
if ($stmt) {
    $stmt->bind_param('s', $today);
    if ($stmt->execute()) {
        $result_recent = $stmt->get_result();
        if ($result_recent) {
            $recentDocuments = $result_recent->fetch_all(MYSQLI_ASSOC);
            $result_recent->free();
        }
    } else {
        error_log("Failed to fetch recent documents: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("Failed to prepare recent documents query: " . $connection->error);
}
$recentDocsTimer = microtime(true);
logDashboardTiming('recent documents (today)', $recentDocsTimer);

// Get department upload statistics for today
$sql_dept_stats = "SELECT department, COUNT(*) as count FROM tracking WHERE DATE(COALESCE(created_at, date_submitted)) = '$today' $__deptFilter GROUP BY department ORDER BY count DESC";
$departmentStats = [];
$deptStatsTimer = microtime(true);
$result_dept_stats = $connection->query($sql_dept_stats);
logDashboardTiming('department stats (today)', $deptStatsTimer);
if ($result_dept_stats) {
    while ($row = $result_dept_stats->fetch_assoc()) {
        $departmentStats[] = $row;
    }
}

$sql_pending_documents = "SELECT id, type, employee_name, date_submitted, department, status, current_holder, end_location, created_at, COALESCE(created_at, date_submitted) AS sort_date
                           FROM tracking
                           WHERE status IN ('Pending','In Review','Archived','Rejected') $__deptFilter
                           ORDER BY sort_date DESC
                           LIMIT 120";
$pendingDocsTimer = microtime(true);
$result_pending_documents = $connection->query($sql_pending_documents);
logDashboardTiming('pending documents table slice', $pendingDocsTimer);

if ($result_pending_documents) {
    while ($row = $result_pending_documents->fetch_assoc()) {
        $originalStatus = $row['status'];

        if ($originalStatus === 'Archived' || $originalStatus === 'Rejected') {
            $row['status'] = (random_int(0, 1) === 0) ? 'In Review' : 'Pending';
        }

        if ($row['status'] === 'Pending' || $row['status'] === 'In Review') {
            $pendingDocuments[] = $row;

            if ($row['status'] === 'Pending') {
                $pendingOnly[] = $row;
            } elseif ($row['status'] === 'In Review') {
                $inReviewOnly[] = $row;
            }
        }
    }
    $result_pending_documents->free();
}

// Prepare data for Document Status Distribution chart
$statusChartLabels = [];
$statusChartData = [];
$statusChartColors = [];

$statusColorMap = [
    'Completed' => '#2a9d8f',
    'Pending' => '#f2994a', 
    'Archived' => '#6c757d',
    'In Review' => '#00BCD4',
    'In Progress' => '#e76f51',
    'Rejected' => '#e63946',
    'Approved' => '#4CAF50'
];

foreach ($statusCounts as $status => $count) {
    if ($count > 0) {
        $statusChartLabels[] = $status;
        $statusChartData[] = $count;
        $statusChartColors[] = $statusColorMap[$status] ?? '#95a5a6';
    }
}

// Prepare data for Document Processing Trend chart (simulated monthly data)
$totalDocsForTrend = $completedDocs + $totalPendingAndReview;
$trendLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'];
$trendData = [
    floor($totalDocsForTrend * 0.12),
    floor($totalDocsForTrend * 0.15),
    floor($totalDocsForTrend * 0.14),
    floor($totalDocsForTrend * 0.16),
    floor($totalDocsForTrend * 0.18),
    floor($totalDocsForTrend * 0.13),
    floor($totalDocsForTrend * 0.12)
];

// ----- Build dynamic data for 'Recent Document Uploaded' (last 7 days) -----
$today = new DateTime();
$dateMap = [];
for ($i = 6; $i >= 0; $i--) {
  $d = clone $today;
  $d->modify("-$i day");
  $key = $d->format('Y-m-d');
  $dateMap[$key] = 0;
}

$sql_recent7 = "SELECT DATE(COALESCE(created_at, date_submitted)) as d, COUNT(*) as c
                FROM tracking
                WHERE DATE(COALESCE(created_at, date_submitted)) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY d";
$recent7Timer = microtime(true);
$res_recent7 = $connection->query($sql_recent7);
logDashboardTiming('recent 7-day counts', $recent7Timer);
if ($res_recent7) {
  while ($row = $res_recent7->fetch_assoc()) {
    $key = $row['d'];
    if (isset($dateMap[$key])) $dateMap[$key] = (int)$row['c'];
  }
  $res_recent7->free();
}

$recentLabels = [];
$recentCounts = [];
foreach ($dateMap as $dateStr => $count) {
  $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
  $recentLabels[] = $dt ? $dt->format('M j') : $dateStr; // e.g., Jul 5
  $recentCounts[] = $count;
}

// ----- Build dynamic data for 'Documents Generation Report' (by department) -----
$deptLabels = [];
$deptCounts = [];
$deptColors = [];
$currentMonthName = date('F');
$colorPalette = ['#00BCD4', '#26A69A', '#FFB300', '#8E24AA', '#FF7043', '#E91E63', '#9C27B0', '#3F51B5', '#009688', '#FF5722'];
// Exclude NULL/empty departments and normalize case to avoid duplicates like 'hr' vs 'HR'
$sql_dept = "SELECT UPPER(TRIM(COALESCE(NULLIF(TRIM(department), ''), NULLIF(TRIM(current_holder), '')))) AS department, COUNT(*) as c
             FROM tracking
             WHERE COALESCE(NULLIF(TRIM(department), ''), NULLIF(TRIM(current_holder), '')) IS NOT NULL
               AND LENGTH(TRIM(COALESCE(NULLIF(TRIM(department), ''), NULLIF(TRIM(current_holder), '')))) > 1
               AND UPPER(TRIM(COALESCE(NULLIF(TRIM(department), ''), NULLIF(TRIM(current_holder), '')))) NOT IN ('UNKNOWN','UNKNOWN DEPARTMENT','(UNKNOWN)','N/A','(N/A)','NA','N\\A','NONE','NULL','UNASSIGNED','TBD','—','--','-')
             GROUP BY UPPER(TRIM(COALESCE(NULLIF(TRIM(department), ''), NULLIF(TRIM(current_holder), ''))))
             ORDER BY c DESC";
$deptAggTimer = microtime(true);
$res_dept = $connection->query($sql_dept);
logDashboardTiming('department totals (all time)', $deptAggTimer);
if ($res_dept) {
  $colorIndex = 0;
  while ($row = $res_dept->fetch_assoc()) {
    $dept = isset($row['department']) ? trim($row['department']) : '';
    if ($dept === '') { continue; }
    // Present labels in UPPERCASE while grouping remains normalized
    $deptLabels[] = strtoupper($dept);
    $deptCounts[] = (int)$row['c'];
    $deptColors[] = $colorPalette[$colorIndex % count($colorPalette)];
    $colorIndex++;
  }
  $res_dept->free();
}

$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Document Management Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
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

    /* Sidebar - GPU-accelerated for smooth hover transitions */
    .sidebar {
      width: 80px;
      background: linear-gradient(180deg, #2e2e5e 0%, #3d3d7a 50%, #2e2e5e 100%);
      color: var(--white);
      padding: 0;
      box-shadow: 4px 0 24px rgba(0,0,0,0.18);
      position: fixed;
      height: 100vh;
      user-select: none;
      top: 0;
      left: 0;
      overflow: hidden;
      overflow-y: auto;
      transition: width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
      will-change: width;
      transform: translateZ(0);
      backface-visibility: hidden;
      contain: layout style;
      display: flex;
      flex-direction: column;
      z-index: 100;
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

    .sidebar-header h2 {
      font-size: 15px;
      font-weight: 700;
      margin: 0;
      color: var(--white);
      opacity: 0;
      white-space: nowrap;
      transition: opacity 0.2s ease 0.1s;
      transform: translateZ(0);
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
      border-left: 3px solid #a5a5d6;
    }
    .menu-item.active i, .menu-item.active span { color: #fff; }
    .menu-item i { font-size: 20px; width: 28px; min-width: 28px; text-align: center; color: rgba(255,255,255,0.85); transition: color .18s ease; }
    .menu-item.active i { color: #c5c5e8; }
    .menu-item:hover i { color: #fff; }
    .menu-item span {
      font-size: 14px;
      opacity: 0;
      white-space: nowrap;
      transition: opacity 0.2s ease;
    }

    .sidebar:hover .menu-item span { opacity: 1; }
    .sidebar:not(:hover) .menu-item { justify-content: center; width: 52px; height: 52px; padding: 0; margin: 3px auto; display: grid; place-items: center; overflow: visible; border-left: none; }
    .sidebar:not(:hover) .menu-item.active { border-left: none; border-bottom: 2px solid #a5a5d6; }
    .sidebar:not(:hover) .menu-item i { width: 24px; height: 24px; display: inline-grid; place-items: center; }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display: none; }
    
    .menu-badge {
      background-color: #FF5252; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px;
      margin-left: auto; font-weight: 700; min-width: 20px; text-align: center; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      opacity: 1;
      z-index: 2; pointer-events: none; display: inline-flex; align-items: center; justify-content: center; height: 20px; line-height: 20px;
    }
    .sidebar:not(:hover) .menu-badge { right: 4px; top: 4px; transform: none; font-size: 10px; padding: 1px 5px; }
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
      transition: margin-left 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
      will-change: margin-left;
      opacity: 0;
      transition: opacity .25s ease, margin-left 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
    }

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
    }

    .search-bar i {
      color: var(--primary-dark);
      margin-right: 8px;
    }

    .user-profile {
      display: flex;
      align-items: center;
      cursor: pointer;
    }

    .user-profile:hover {
      background-color: var(--light-bg);
    }

    .user-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 10px;
    }

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

    .notification-icon:hover {
      background-color: var(--light-bg);
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

    /* Notification Dropdown */
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
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card { background:#fff; border-radius: 16px; padding: 24px; box-shadow: 0 8px 28px rgba(2,132,199,.12); border:1px solid #eef3f7; transition: transform .2s ease, box-shadow .2s ease; }

    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(2,132,199,.16); }

    .stat-card h3 {
      color: var(--text-light);
      font-size: 16px;
      margin-bottom: 10px;
    }

    .stat-card .stat-value { font-size: 36px; font-weight: 800; color: var(--text-dark); letter-spacing: -.2px; }

    /* Chart Section */
    .charts-container {
      display: grid;
      grid-template-columns: 7fr 3fr; /* make Recent noticeably wider; pie remains beside */
      gap: 20px;
      margin-bottom: 30px;
    }

    .chart-card { background:#fff; border-radius:16px; padding:24px; box-shadow:0 8px 28px rgba(2,132,199,.12); border:1px solid #eef3f7; }
    .chart-card.chart-wide { padding-left:12px; padding-right:12px; }
    .chart-card.chart-wide .chart-content { position: relative; width: 100%; height: 250px; min-height: 250px; }
    /* Prevent canvas collapse during data refresh */
    #recentBarChart { width: 100% !important; height: 100% !important; display: block; }
    /* Overlay should not change layout height */
    .chart-content > .chart-loading-overlay, .chart-content > .empty-state { position: absolute; }
    .chart-loading-overlay {
      position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(255,255,255,0.9));
      z-index: 2; font-weight:600; color:#64748b; font-size:14px; gap:10px; opacity: 0; pointer-events: none; transition: opacity .2s ease;
    }
    .chart-loading-overlay.show { opacity: 1; pointer-events: none; }
    .spinner { width:16px; height:16px; border:2px solid #cbd5e1; border-top-color:#0891b2; border-radius:50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .empty-state { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-weight:600; font-size:14px; z-index:1; }

    .chart-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      position: relative;
      z-index: 1100; /* ensure header stays above chart canvas */
    }

    .chart-header h3 {
      font-size: 18px;
      color: var(--text-dark);
    }

    .chart-filter {
      display: flex;
      align-items: center;
      background-color: var(--light-bg);
      border-radius: 5px;
      padding: 5px 15px;
      font-size: 14px;
      cursor: pointer;
      position: relative;
      z-index: 2100; /* keep triggers above canvas/overlays */
    }

    .chart-filter i {
      margin-left: 5px;
      transition: transform 0.3s;
    }

    .chart-filter.active i {
      transform: rotate(180deg);
    }

    .dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow);
      min-width: 120px;
      z-index: 2000; /* above canvas and overlays */
      display: none;
      margin-top: 5px;
    }

    .dropdown-menu.show {
      display: block;
    }

    .dropdown-item {
      padding: 10px 15px;
      font-size: 14px;
      cursor: pointer;
      transition: background 0.2s;
    }

    .dropdown-item:hover {
      background-color: var(--primary-light);
    }

    .dropdown-item.selected {
      background-color: var(--primary-light);
      color: var(--primary-dark);
      font-weight: 500;
    }

    .chart-content {
      height: 250px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* Pending Documents Table */
    .pending-docs {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
      margin-top: 20px;
      border: 1px solid #e2e8f0;
      position: relative;
      overflow: hidden;
    }

    .pending-docs::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #3b82f6, #06b6d4, #10b981);
    }

    .pending-docs h3 {
      margin-bottom: 24px;
      color: #1e293b;
      font-size: 1.4rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .pending-docs h3::before {
      content: none;
    }

    .docs-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.95rem;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
    }

    .docs-table th {
      background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
      padding: 16px 20px;
      text-align: left;
      font-weight: 600;
      color: #475569;
      border: none;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: relative;
    }

    .docs-table th:first-child {
      border-top-left-radius: 12px;
    }

    .docs-table th:last-child {
      border-top-right-radius: 12px;
    }

    .docs-table td {
      padding: 16px 20px;
      border: none;
      color: #334155;
      background: white;
      transition: all 0.2s ease;
      position: relative;
    }

    .docs-table tbody tr {
      border-bottom: 1px solid #f1f5f9;
    }

    .docs-table tbody tr:last-child {
      border-bottom: none;
    }

    .docs-table tbody tr:last-child td:first-child {
      border-bottom-left-radius: 12px;
    }

    .docs-table tbody tr:last-child td:last-child {
      border-bottom-right-radius: 12px;
    }

    .docs-table tr:hover td {
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .doc-type-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 500;
      text-transform: capitalize;
    }

    .doc-type-leave {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      color: #92400e;
    }

    .doc-type-memo {
      background: linear-gradient(135deg, #dbeafe, #bfdbfe);
      color: #1e40af;
    }

    .doc-type-report {
      background: linear-gradient(135deg, #dcfce7, #bbf7d0);
      color: #166534;
    }

    .doc-type-request {
      background: linear-gradient(135deg, #fce7f3, #fbcfe8);
      color: #be185d;
    }

    .doc-type-default {
      background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
      color: #374151;
    }

    .employee-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .employee-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: linear-gradient(135deg, #3b82f6, #06b6d4);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      font-size: 0.8rem;
    }

    .date-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #64748b;
      font-size: 0.85rem;
    }

    .department-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 12px;
      background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
      color: #0369a1;
      font-size: 0.8rem;
      font-weight: 500;
    }

    .action-btn {
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .action-btn:hover {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .action-btn i {
      font-size: 0.75rem;
    }

    .docs-table thead tr {
      background-color: #ECEFF1; 
      border-radius: 12px;
      box-shadow: inset 0 -1px 0 var(--border);
    }

    .docs-table th, .docs-table td {
      padding: 15px 20px;
      text-align: left;
      vertical-align: middle;
      color: #455A64; /* Softer dark gray for text */
    }

    .docs-table th {
      font-weight: 600;
      font-size: 15px;
      color: var(--primary-dark);
      letter-spacing: 0.05em;
      text-transform: uppercase;
      border-bottom: none;
    }

    .docs-table tbody tr {
      background-color: var(--white);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
      border-radius: 12px;
      transition: background-color 0.3s ease;
      cursor: default;
    }

    .docs-table tbody tr:hover {
      background-color: #CFD8DC; /* Softer hover background */
    }

    .docs-table tbody tr:last-child td {
      border-bottom: none;
    }

    .department-info {
      display: flex;
      align-items: center;
      font-weight: 600;
      color: var(--primary-dark);
    }

    .department-info img {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      margin-right: 15px;
      box-shadow: var(--shadow);
    }

    .due-date {
      color: #d32f2f;
      font-weight: 700;
      font-size: 14px;
    }

    .action-btn {
      background-color: var(--primary);
      color: var(--white);
      border: none;
      border-radius: 25px;
      padding: 8px 18px;
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      box-shadow: var(--shadow);
      transition: all 0.3s ease;
    }

    .action-btn:hover {
      background-color: var(--primary-dark);
      box-shadow: 0 6px 12px rgba(0, 151, 167, 0.4);
    }

    /* Chat Widget */
    .chat-widget {
      position: fixed;
      bottom: 30px;
      right: 30px;
      background-color: var(--primary);
      color: var(--white);
      width: 60px;
      height: 60px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      cursor: pointer;
      transition: all 0.3s;
      z-index: 1000;
    }

    .chat-widget:hover {
      transform: scale(1.1);
    }

    .chat-container {
      position: fixed;
      bottom: 100px;
      right: 30px;
      width: 350px;
      height: 450px;
      background-color: var(--white);
      border-radius: 10px;
      box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
      display: none;
      flex-direction: column;
      overflow: hidden;
      z-index: 1000;
      transition: all 0.3s;
    }

    .chat-container.show {
      display: flex;
    }

    .chat-header {
      background-color: var(--primary-dark);
      color: var(--white);
      padding: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .chat-header h3 {
      margin: 0;
      font-size: 16px;
    }

    .chat-close {
      cursor: pointer;
      font-size: 18px;
    }

    .chat-body {
      flex: 1;
      padding: 15px;
      overflow-y: auto;
      background-color: #f5f5f5;
    }

    .chat-message {
      margin-bottom: 15px;
      max-width: 80%;
    }

    .chat-message-content {
      padding: 10px 15px;
      border-radius: 15px;
      font-size: 14px;
    }

    .message-received {
      align-self: flex-start;
      margin-right: auto;
    }

    .message-received .chat-message-content {
      background-color: #e0e0e0;
      color: var(--text-dark);
    }

    .message-sent {
      align-self: flex-end;
      margin-left: auto;
      text-align: right;
    }

    .message-sent .chat-message-content {
      background-color: var(--primary);
      color: var(--white);
    }

    .chat-footer {
      padding: 10px 15px;
      background-color: var(--white);
      border-top: 1px solid #e0e0e0;
      display: flex;
      align-items: center;
    }

    .chat-input {
      flex: 1;
      border: 1px solid #e0e0e0;
      border-radius: 20px;
      padding: 10px 15px;
      font-size: 14px;
      outline: none;
    }

    .chat-send {
      background-color: var(--primary);
      color: var(--white);
      border: none;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      margin-left: 10px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .chat-send:hover {
      background-color: var(--primary-dark);
    }

    /* User Dropdown */
    .user-profile {
      position: relative;
    }

    #userDropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow);
      min-width: 180px;
      z-index: 100;
      display: none;
      margin-top: 10px;
    }

    #userDropdown.show {
      display: block;
    }

    /* Responsive Design */
    @media (max-width: 992px) {
      .charts-container {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .stats-cards {
        grid-template-columns: 1fr;
      }
      
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
      }
      
      .search-bar {
        width: 100%;
        margin-bottom: 15px;
      }
      
      .user-profile {
        align-self: flex-end;
      }
      
      .notification-dropdown {
        width: 300px;
        right: -50px;
      }

    }
  </style>
  <style>
    /* Animation/transition overrides (scoped) */
    .stat-card:hover,
    .docs-table tr:hover td,
    .action-btn:hover,
    .chat-widget:hover,
    .chart-filter.active i,
    .menu-badge,
    .dropdown-item,
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
        <a href="dashboard.php" class="menu-item active">
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
        <h2>Main Dashboard</h2>
        <div class="top-bar-actions" style="display: flex; align-items: center;">
            <?php
            $notificationsWidget = __DIR__ . '/partials/notifications.php';
            if (!is_file($notificationsWidget)) {
              $notificationsWidget = __DIR__ . '/notifications.php';
            }
            if (is_file($notificationsWidget)) {
              include $notificationsWidget;
            }
            ?>
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
              <div class="dropdown-item logout-item" style="border-top: 1px solid var(--border); background: transparent; padding: 12px; display: flex; justify-content: center;">
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
          <h3>Documents Overdued</h3>
          <div class="stat-value"><?php echo $overdueDocs; ?></div>
        </div>
        <div class="stat-card">
          <h3>Documents Pending/In Review</h3>
          <div class="stat-value"><?php echo $totalPendingAndReview; ?></div>
        </div>
        <div class="stat-card">
          <h3>Documents Archived</h3>
          <div class="stat-value"><?php echo $archivedDocs; ?></div>
        </div>
      </div>

      <div class="charts-container">
        <div class="chart-card chart-wide">
          <div class="chart-header">
            <h3>Recent Document Uploaded</h3>
            <div class="mode-toggle" id="recentModeToggle" style="display:flex;gap:6px;margin-right:8px;">
              <button type="button" class="mode-btn active" data-mode="year" style="padding:6px 10px;border:1px solid #d2f3fb;background:#f1fbff;color:#0891b2;border-radius:8px;font-weight:600;">Yearly</button>
              <button type="button" class="mode-btn" data-mode="day" style="padding:6px 10px;border:1px solid #e2e8f0;background:#fff;color:#64748b;border-radius:8px;font-weight:600;">Daily</button>
            </div>
            <div class="chart-filter" id="yearFilterRecent">
              <span id="selectedYearRecent">Select Year</span>
              <i class="fas fa-chevron-down"></i>
              <div class="dropdown-menu" id="yearDropdownRecent">
                <!-- Years will be injected by JS (current year and previous 3) -->
              </div>
            </div>
            <div class="chart-filter" id="monthFilterDaily" style="display:none; margin-left:8px;">
              <span id="selectedMonthDaily">Select Month</span>
              <i class="fas fa-chevron-down"></i>
              <div class="dropdown-menu" id="monthDropdownDaily">
                <div class="dropdown-item" data-month="01">January</div>
                <div class="dropdown-item" data-month="02">February</div>
                <div class="dropdown-item" data-month="03">March</div>
                <div class="dropdown-item" data-month="04">April</div>
                <div class="dropdown-item" data-month="05">May</div>
                <div class="dropdown-item" data-month="06">June</div>
                <div class="dropdown-item" data-month="07">July</div>
                <div class="dropdown-item" data-month="08">August</div>
                <div class="dropdown-item" data-month="09">September</div>
                <div class="dropdown-item" data-month="10">October</div>
                <div class="dropdown-item" data-month="11">November</div>
                <div class="dropdown-item" data-month="12">December</div>
              </div>
            </div>
          </div>
          <div class="chart-content">
            <div id="recentLoading" class="chart-loading-overlay"><div class="spinner"></div> Loading…</div>
            <div id="recentEmpty" class="empty-state" style="display:none;">No Documents Uploaded Recently</div>
            <canvas id="recentBarChart"></canvas>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-header">
            <h3>Documents Generation Report</h3>
            <div class="chart-filter" id="monthFilter">
              <span id="selectedMonth"><?php echo htmlspecialchars($currentMonthName); ?></span>
              <i class="fas fa-chevron-down"></i>
              <div class="dropdown-menu" id="monthDropdown">
                <div class="dropdown-item<?php echo $currentMonthName==='January'?' selected':''; ?>" data-value="January">January</div>
                <div class="dropdown-item<?php echo $currentMonthName==='February'?' selected':''; ?>" data-value="February">February</div>
                <div class="dropdown-item<?php echo $currentMonthName==='March'?' selected':''; ?>" data-value="March">March</div>
                <div class="dropdown-item<?php echo $currentMonthName==='April'?' selected':''; ?>" data-value="April">April</div>
                <div class="dropdown-item<?php echo $currentMonthName==='May'?' selected':''; ?>" data-value="May">May</div>
                <div class="dropdown-item<?php echo $currentMonthName==='June'?' selected':''; ?>" data-value="June">June</div>
                <div class="dropdown-item<?php echo $currentMonthName==='July'?' selected':''; ?>" data-value="July">July</div>
                <div class="dropdown-item<?php echo $currentMonthName==='August'?' selected':''; ?>" data-value="August">August</div>
                <div class="dropdown-item<?php echo $currentMonthName==='September'?' selected':''; ?>" data-value="September">September</div>
                <div class="dropdown-item<?php echo $currentMonthName==='October'?' selected':''; ?>" data-value="October">October</div>
                <div class="dropdown-item<?php echo $currentMonthName==='November'?' selected':''; ?>" data-value="November">November</div>
                <div class="dropdown-item<?php echo $currentMonthName==='December'?' selected':''; ?>" data-value="December">December</div>
              </div>
            </div>
          </div>
          <div class="chart-content">
            <div id="docGenerationEmpty" class="empty-state" style="display:none;">
              <div style="text-align:center;">
                <i class="fas fa-folder-open" style="font-size:1.6rem;display:block;margin-bottom:8px;color:#cbd5e1;"></i>
                <span>No data recorded for this month.</span>
              </div>
            </div>
            <canvas id="docPieChart"></canvas>
          </div>
        </div>
      </div>
      
      <div class="pending-docs">
        <h3>Pending Documents</h3>
        <div style="overflow-x: auto;">
          <table class="docs-table">
            <thead>
              <tr>
                <th>Document Type</th>
                <th>Current Holder</th>
                <th>End Location</th>
                <th>Date & Time Submitted</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pendingDocuments)): ?>
                <tr>
                  <td colspan="5" style="text-align: center; color: #64748b; padding: 40px;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                      <i class="fas fa-inbox" style="font-size: 2rem; color: #cbd5e1;"></i>
                      <span>No pending documents found.</span>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php $pendingDocumentsLimited = array_slice($pendingDocuments, 0, 5); ?>
                <?php foreach ($pendingDocumentsLimited as $doc): ?>
                  <tr>
                    <td>
                      <?php
                        $type = strtolower($doc['type']);
                        $badgeClass = 'doc-type-default';
                        $icon = 'fas fa-file';
                        
                        if (strpos($type, 'leave') !== false) {
                          $badgeClass = 'doc-type-leave';
                          $icon = 'fas fa-calendar-times';
                        } elseif (strpos($type, 'memo') !== false) {
                          $badgeClass = 'doc-type-memo';
                          $icon = 'fas fa-sticky-note';
                        } elseif (strpos($type, 'report') !== false) {
                          $badgeClass = 'doc-type-report';
                          $icon = 'fas fa-chart-line';
                        } elseif (strpos($type, 'request') !== false) {
                          $badgeClass = 'doc-type-request';
                          $icon = 'fas fa-hand-paper';
                        }
                      ?>
                      <span class="doc-type-badge <?php echo $badgeClass; ?>">
                        <i class="<?php echo $icon; ?>"></i>
                        <?php echo htmlspecialchars($doc['type']); ?>
                      </span>
                    </td>
                    <td>
                      <div class="employee-info">
                        <div class="employee-avatar">
                          <?php echo strtoupper(substr($doc['current_holder'] ?? $doc['department'] ?? 'NA', 0, 2)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($doc['current_holder'] ?? $doc['department'] ?? 'N/A'); ?></span>
                      </div>
                    </td>
                    <td>
                      <span class="department-tag">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($doc['end_location'] ?? $doc['department'] ?? 'N/A'); ?>
                      </span>
                    </td>
                    <td>
                      <span class="date-badge">
                        <i class="fas fa-clock"></i>
                        <?php echo date('M j, Y - h:i A', strtotime($doc['date_submitted'] . ' ' . ($doc['created_at'] ? date('H:i:s', strtotime($doc['created_at'])) : '00:00:00'))); ?>
                      </span>
                    </td>
                    <td>
                      <button class="action-btn" onclick="window.location.href='tracking.php?id=<?php echo htmlspecialchars($doc['id']); ?>'">
                        <i class="fas fa-eye"></i> View
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          <div style="display:flex;justify-content:flex-end;margin-top:12px;">
            <a href="tracking.php?status=Pending" class="action-btn" style="text-decoration:none;">See All in Tracking</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    let recentTriedFallback = false; // used only during initial load
    let recentInitPhase = true;      // initial auto-load phase
    // Smooth fade-in for the page content to mitigate perceived stutter on reloads
    document.addEventListener('DOMContentLoaded', function(){
      try { document.querySelector('.main-content').style.opacity = '1'; } catch(_){}
    });
    // PHP data for charts
    const recentLabels = <?php echo json_encode($recentLabels); ?>;
    const recentCounts = <?php echo json_encode($recentCounts); ?>;
    // Keep originals as a hard fallback for display
    const initialRecentLabels = [...recentLabels];
    const initialRecentCounts = [...recentCounts];
    const deptLabels = <?php echo json_encode($deptLabels); ?>;
    const deptCounts = <?php echo json_encode($deptCounts); ?>;
    const deptColors = <?php echo json_encode($deptColors); ?>;

    // Chart.js Bar Chart - Recent Document Uploaded (Yearly view with trendline)
    const recentBarCtx = document.getElementById('recentBarChart').getContext('2d');
    const recentBarGradient = recentBarCtx.createLinearGradient(0, 0, 0, 240);
    recentBarGradient.addColorStop(0, 'rgba(0, 188, 212, 0.9)');
    recentBarGradient.addColorStop(1, 'rgba(0, 188, 212, 0.15)');
    const recentBarChart = new Chart(recentBarCtx, {
      type: 'bar',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        datasets: [
          {
            label: 'Documents',
            type: 'bar',
            data: new Array(12).fill(0),
            backgroundColor: recentBarGradient,
            borderColor: 'rgba(0, 188, 212, 1)',
            borderWidth: 2,
            borderRadius: 8,
            hoverBorderWidth: 2,
            maxBarThickness: 28
          },
          {
            label: 'Trend',
            type: 'line',
            data: new Array(12).fill(0),
            borderColor: 'rgba(99, 102, 241, 0.9)',
            backgroundColor: 'rgba(99, 102, 241, 0.2)',
            borderWidth: 2,
            pointRadius: 0,
            tension: 0.35,
            borderDash: [4,4]
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
          duration: 600,
          easing: 'easeOutQuart'
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => ` ${ctx.formattedValue} document${ctx.parsed.y === 1 ? '' : 's'}`
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { autoSkip: false }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { stepSize: 1 }
          }
        }
      }
    });

    // Chart.js Doughnut - Documents Generation Report (modernized)
    const pieCtx = document.getElementById('docPieChart').getContext('2d');
    // PHP-driven department distribution
    const docGenerationEmpty = document.getElementById('docGenerationEmpty');
    function updateDocGenerationEmptyState(dataArr) {
      if (!docGenerationEmpty) return;
      const total = Array.isArray(dataArr) ? dataArr.reduce((sum, val) => sum + (+val || 0), 0) : 0;
      docGenerationEmpty.style.display = total > 0 ? 'none' : 'flex';
    }

    const docPieChart = new Chart(pieCtx, {
      type: 'doughnut',
      data: {
          labels: deptLabels,
          datasets: [{
            data: deptCounts,
            backgroundColor: deptColors,
            borderColor: '#fff',
          borderWidth: 2,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              color: '#263238',
              font: { weight: '500' }
            }
          },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                const val = ctx.parsed || 0;
                const pct = total ? ((val / total) * 100).toFixed(1) : 0;
                return ` ${ctx.label}: ${val} (${pct}%)`;
              }
            }
          }
        }
      }
    });

    // Notifications handled by partials/notifications.php

    // User dropdown toggle
    const userProfile = document.getElementById('userProfile');
    const userDropdown = document.getElementById('userDropdown');
    userProfile.addEventListener('click', e => {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
    });

    document.body.addEventListener('click', () => {
      userDropdown.classList.remove('show');
    });

    // Toggle modes
    let recentMode = 'year'; // 'year' | 'day'
    function daysInMonthUTC(year, mmStr) {
      const m = parseInt(mmStr, 10);
      if (!Number.isFinite(m) || m < 1 || m > 12) return 31;
      return new Date(Date.UTC(year, m, 0)).getUTCDate();
    }
    function prepareDailySkeleton(year, mm) {
      try {
        const daysInMonth = daysInMonthUTC(year, mm);
        const labels = Array.from({length: daysInMonth}, (_,i)=> String(i+1));
        recentBarChart.data.labels = labels;
        recentBarChart.data.datasets[0].data = new Array(daysInMonth).fill(0);
        if (recentBarChart.data.datasets[1]) {
          recentBarChart.data.datasets[1].data = new Array(daysInMonth).fill(null);
          recentBarChart.data.datasets[1].hidden = true;
        }
        recentBarChart.options.scales.x.title = { display: true, text: 'Day of Month' };
        // give small headroom so tiny values are visible
        if (!recentBarChart.options.scales.y) recentBarChart.options.scales.y = {};
        recentBarChart.options.scales.y.suggestedMax = 2;
        recentBarChart.update();
      } catch(_){}
    }
    function setRecentMode(mode){
      recentMode = mode === 'day' ? 'day' : 'year';
      const toggle = document.getElementById('recentModeToggle');
      const yearF = document.getElementById('yearFilterRecent');
      const yearD = document.getElementById('yearDropdownRecent');
      const monthF = document.getElementById('monthFilterDaily');
      const monthD = document.getElementById('monthDropdownDaily');
      if (toggle) toggle.querySelectorAll('.mode-btn').forEach(b=>{
        b.classList.toggle('active', b.dataset.mode === recentMode);
        if (b.dataset.mode === recentMode) {
          b.style.background = '#f1fbff'; b.style.borderColor = '#d2f3fb'; b.style.color = '#0891b2';
        } else {
          b.style.background = '#fff'; b.style.borderColor = '#e2e8f0'; b.style.color = '#64748b';
        }
      });
      if (recentMode === 'year') {
        if (yearF) yearF.style.display = '';
        if (yearD) yearD.style.display = '';
        if (monthF) monthF.style.display = 'none';
        if (monthD) monthD.style.display = 'none';
        // restore 12-month labels and unhide trendline immediately
        try {
          recentBarChart.data.labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          if (recentBarChart.data.datasets[1]) recentBarChart.data.datasets[1].hidden = false;
          recentBarChart.options.scales.x.title = { display: false };
          recentBarChart.update();
        } catch(_){}
        const y = parseInt(document.getElementById('selectedYearRecent')?.textContent || new Date().getFullYear(), 10);
        loadRecentYear(y, false);
      } else {
        if (yearF) yearF.style.display = '';
        if (yearD) yearD.style.display = '';
        if (monthF) monthF.style.display = '';
        if (monthD) monthD.style.display = '';
        const y = parseInt(document.getElementById('selectedYearRecent')?.textContent || new Date().getFullYear(), 10);
        const mm = document.getElementById('selectedMonthDaily')?.dataset.mm || String(new Date().getMonth()+1).padStart(2,'0');
        // immediately swap to day labels while fetching
        prepareDailySkeleton(y, mm);
        loadRecentMonth(y, mm, false);
      }
    }

    // Year dropdown for Recent Document Uploaded (reuse elements)
    const yearFilterRecent = document.getElementById('yearFilterRecent');
    const yearDropdownRecent = document.getElementById('yearDropdownRecent');
    const selectedYearRecent = document.getElementById('selectedYearRecent');
    if (yearFilterRecent && yearDropdownRecent && selectedYearRecent) {
      yearFilterRecent.style.cursor = 'pointer';
      yearFilterRecent.addEventListener('click', e => {
        e.stopPropagation();
        yearDropdownRecent.classList.toggle('show');
        yearFilterRecent.classList.toggle('active');
      });
      // Prevent outside click handler from closing immediately when interacting with menu
      yearDropdownRecent.addEventListener('click', e => e.stopPropagation());
      // Populate years dynamically (current and previous 3)
      const minYear = 2025;
      const nowY = Math.max(minYear, new Date().getFullYear());
      const years = [nowY, nowY + 1, nowY + 2, nowY + 3];
      yearDropdownRecent.innerHTML = years.map((y,i)=>`<div class="dropdown-item${i===0?' selected':''}" data-year="${y}">${y}</div>`).join('');
      selectedYearRecent.textContent = String(nowY);
      yearDropdownRecent.querySelectorAll('.dropdown-item').forEach(item => {
        item.addEventListener('click', function() {
          const y = this.getAttribute('data-year');
          selectedYearRecent.textContent = y;
          yearDropdownRecent.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('selected'));
          this.classList.add('selected');
          yearDropdownRecent.classList.remove('show');
          yearFilterRecent.classList.remove('active');
          recentInitPhase = false;
          if (recentMode === 'year') loadRecentYear(parseInt(y,10), false);
          else loadRecentMonth(parseInt(y,10), (document.getElementById('selectedMonthDaily')?.dataset.mm || String(new Date().getMonth()+1).padStart(2,'0')), false);
        });
      });

      // Close when clicking outside
      document.addEventListener('click', function(evt){
        if (!yearDropdownRecent.contains(evt.target) && !yearFilterRecent.contains(evt.target)) {
          yearDropdownRecent.classList.remove('show');
          yearFilterRecent.classList.remove('active');
        }
      });
    }

    // Month dropdown for Daily mode
    const monthFilterDaily = document.getElementById('monthFilterDaily');
    const monthDropdownDaily = document.getElementById('monthDropdownDaily');
    const selectedMonthDaily = document.getElementById('selectedMonthDaily');
    if (monthFilterDaily && monthDropdownDaily && selectedMonthDaily) {
      monthFilterDaily.addEventListener('click', e => { e.stopPropagation(); monthDropdownDaily.classList.toggle('show'); monthFilterDaily.classList.toggle('active'); });
      monthDropdownDaily.addEventListener('click', e => e.stopPropagation());
      // default to current month
      const curMM = String(new Date().getMonth() + 1).padStart(2, '0');
      const curItem = monthDropdownDaily.querySelector(`[data-month="${curMM}"]`);
      if (curItem) { selectedMonthDaily.textContent = curItem.textContent; selectedMonthDaily.dataset.mm = curMM; curItem.classList.add('selected'); }
      monthDropdownDaily.querySelectorAll('.dropdown-item').forEach(item => {
        item.addEventListener('click', function(){
          const mm = this.getAttribute('data-month');
          selectedMonthDaily.textContent = this.textContent;
          selectedMonthDaily.dataset.mm = mm;
          monthDropdownDaily.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('selected'));
          this.classList.add('selected');
          monthDropdownDaily.classList.remove('show');
          monthFilterDaily.classList.remove('active');
          const y = parseInt(selectedYearRecent?.textContent || new Date().getFullYear(), 10);
          // Immediately show correct day labels before fetch
          prepareDailySkeleton(y, mm);
          loadRecentMonth(y, mm, true);
        });
      });
      document.addEventListener('click', function(evt){ if (!monthDropdownDaily.contains(evt.target) && !monthFilterDaily.contains(evt.target)) { monthDropdownDaily.classList.remove('show'); monthFilterDaily.classList.remove('active'); } });
    }

    // Month dropdown for Documents Generation Report
    const monthFilter = document.getElementById('monthFilter');
    const monthDropdown = document.getElementById('monthDropdown');
    const selectedMonth = document.getElementById('selectedMonth');
    if (monthFilter && monthDropdown && selectedMonth) {
      const dbg = () => {};

      monthFilter.addEventListener('click', e => {
        e.stopPropagation();
        monthDropdown.classList.toggle('show');
        monthFilter.classList.toggle('active');
      });

      monthDropdown.querySelectorAll('.dropdown-item').forEach(item => {
        item.addEventListener('click', function() {
          const v = this.getAttribute('data-value') || this.textContent || '';
          selectedMonth.textContent = v;
          monthDropdown.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('selected'));
          this.classList.add('selected');
          monthDropdown.classList.remove('show');
          monthFilter.classList.remove('active');
          dbg('selected', v);
          updateDocPieChart(v);
        });
      });
    }

    // Functions to update charts based on filter selection
    function computeTrend(arr) {
      const out = new Array(arr.length).fill(0);
      let sum = 0;
      for (let i = 0; i < arr.length; i++) { sum += (+arr[i]||0); out[i] = +(sum/(i+1)).toFixed(2); }
      return out;
    }

    async function loadRecentYear(year, allowEmptyOverlay = false) {
      const loader = document.getElementById('recentLoading');
      const empty = document.getElementById('recentEmpty');
      if (loader) loader.classList.add('show');
      if (empty) empty.style.display = 'none';
      try {
        const resp = await fetch(`stats.php?action=monthly_counts&year=${encodeURIComponent(String(year))}&_=${Date.now()}`, { cache: 'no-store' });
        if (!resp.ok) throw new Error('network');
        const data = await resp.json();
        if (recentBarChart && Array.isArray(data.counts)) {
          const counts = new Array(12).fill(0).map((_,i)=> Number(data.counts[i]||0));
          recentBarChart.data.labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          recentBarChart.data.datasets[0].data = counts;
          if (recentBarChart.data.datasets[1]) {
            recentBarChart.data.datasets[1].hidden = false;
            recentBarChart.data.datasets[1].data = computeTrend(counts);
          }
          recentBarChart.options.scales.x.title = { display: false };
          const sum = counts.reduce((a,b)=>a+(+b||0),0);
          if (empty) empty.style.display = allowEmptyOverlay ? (sum===0?'flex':'none') : 'none';
          recentBarChart.update();
          try { recentBarChart.resize(); } catch(_){ }
        }
      } catch(err) {
        console.debug('monthly_counts failed', err);
      } finally {
        if (loader) loader.classList.remove('show');
      }
    }

    async function loadRecentMonth(year, mm, userChange = true) {
      const loader = document.getElementById('recentLoading');
      const empty = document.getElementById('recentEmpty');
      if (loader) loader.classList.add('show');
      if (empty) empty.style.display = 'none';
      const ym = `${year}-${mm}`;
      try {
        const resp = await fetch(`tracking.php?action=recent_uploads_by_month&month=${encodeURIComponent(ym)}&_=${Date.now()}`, { cache:'no-store' });
        if (!resp.ok) throw new Error('network');
        const data = await resp.json();
        const dim = new Date(year, parseInt(mm,10), 0).getDate();
        // API returns counts for each day in order; align to 1..dim
        const labels = Array.from({length: dim}, (_,i)=> String(i+1));
        const counts = Array.isArray(data.counts) ? data.counts.slice(0, dim).map(v=> Number(v)||0) : new Array(dim).fill(0);
        recentBarChart.data.labels = labels;
        recentBarChart.data.datasets[0].data = counts;
        if (recentBarChart.data.datasets[1]) { recentBarChart.data.datasets[1].data = new Array(labels.length).fill(null); recentBarChart.data.datasets[1].hidden = true; }
        recentBarChart.options.scales.x.title = { display: true, text: 'Day of Month' };
        // If all values are 0/1, add headroom to ensure visible bars
        const maxVal = counts.reduce((m,v)=> Math.max(m, v||0), 0);
        if (!recentBarChart.options.scales.y) recentBarChart.options.scales.y = {};
        recentBarChart.options.scales.y.suggestedMax = maxVal <= 1 ? 2 : undefined;
        const sum = counts.reduce((a,b)=>a+(+b||0),0);
        if (empty) empty.style.display = userChange ? 'none' : (sum===0?'flex':'none');
        recentBarChart.update();
        try { recentBarChart.resize(); } catch(_){ }
      } catch(err) {
        console.debug('recent_uploads_by_month failed', err);
      } finally {
        if (loader) loader.classList.remove('show');
      }
    }

    // No-op fallback now; yearly view will always render 12 months
    function renderInitialRecentIfEmpty() { /* intentionally blank */ }
    // Initialize Recent widget with the CURRENT year and wire toggle
    function initRecentWidgetToCurrentMonth(){
      const now = new Date();
      const y = now.getFullYear();
      const sel = document.getElementById('selectedYearRecent');
      if (sel) sel.textContent = String(y);
      const dd = document.getElementById('yearDropdownRecent');
      if (dd) dd.querySelectorAll('.dropdown-item').forEach(i=>{
        i.classList.toggle('selected', i.getAttribute('data-year')===String(y));
      });
      recentTriedFallback = false;
      recentInitPhase = true; // only on initial load
      // Bind toggle
      const toggle = document.getElementById('recentModeToggle');
      if (toggle) {
        toggle.querySelectorAll('.mode-btn').forEach(btn=>{
          btn.addEventListener('click', ()=> setRecentMode(btn.dataset.mode));
        });
      }
      setRecentMode('year');
    }
    document.addEventListener('DOMContentLoaded', function(){
      // Delay slightly to allow layout settle then fetch
      setTimeout(initRecentWidgetToCurrentMonth, 50);
      // As ultimate guard, if still zero after 1s, render initial server data
      setTimeout(renderInitialRecentIfEmpty, 1000);
    });

    async function updateDocPieChart(monthName) {
      const monthMap = {
        'January': '01','February': '02','March': '03','April': '04','May': '05','June': '06',
        'July': '07','August': '08','September': '09','October': '10','November': '11','December': '12'
      };
      const mm = monthMap[monthName];
      if (!mm) return;
      const debugEnabled = false;

      // Important: the current date is 2026. If your data is in 2025 (or earlier),
      // a naive `${currentYear}-${mm}` request returns empty.
      // We auto-try recent years until we find data.
      const nowY = new Date().getFullYear();
      const yearCandidates = [nowY, nowY - 1, nowY - 2, nowY - 3, nowY - 4, nowY - 5];

      let lastDebug = null;
      let chosenYm = null;
      let chosen = null;
      try {
        for (const y of yearCandidates) {
          const ym = `${y}-${mm}`;
          const resp = await fetch(`tracking.php?action=dept_distribution_by_month&month=${encodeURIComponent(ym)}`, { cache: 'no-store' });
          if (!resp.ok) continue;
          const ct = (resp.headers.get('content-type')||'').toLowerCase();
          if (!ct.includes('application/json')) continue;
          const data = await resp.json();
          lastDebug = data && data.debug ? data.debug : null;
          const counts = (data && Array.isArray(data.counts)) ? data.counts : [];
          const total = counts.reduce((s,v)=>s+(+v||0),0);
          if (Array.isArray(data.labels) && counts.length > 0 && total > 0) {
            chosenYm = ym;
            chosen = data;
            break;
          }
        }

        // If nothing found, still try the current year request to show empty state deterministically
        if (!chosenYm) {
          const ym = `${nowY}-${mm}`;
          const resp = await fetch(`tracking.php?action=dept_distribution_by_month&month=${encodeURIComponent(ym)}`, { cache: 'no-store' });
          if (resp.ok && ((resp.headers.get('content-type')||'').toLowerCase().includes('application/json'))) {
            chosenYm = ym;
            chosen = await resp.json();
            lastDebug = chosen && chosen.debug ? chosen.debug : lastDebug;
          }
        }

        if (docPieChart && chosen && Array.isArray(chosen.labels) && Array.isArray(chosen.counts)) {
          docPieChart.data.labels = chosen.labels;
          docPieChart.data.datasets[0].data = chosen.counts;
          if (Array.isArray(chosen.colors)) {
            docPieChart.data.datasets[0].backgroundColor = chosen.colors;
          }
          docPieChart.update();
          updateDocGenerationEmptyState(docPieChart.data.datasets[0].data);

          
        }
      } catch (e) {
        // Swallow errors to avoid interrupting other widgets
        
      }
    }

    // Chat widget toggle and messaging logic
    const chatButton = document.getElementById('chatButton');
    const chatContainer = document.getElementById('chatContainer');
    const chatClose = document.getElementById('chatClose');
    const chatInput = document.getElementById('chatInput');
    const chatSend = document.getElementById('chatSend');
    const chatBody = document.getElementById('chatBody');

    chatButton.addEventListener('click', () => {
      chatContainer.classList.toggle('show');
      if (chatContainer.classList.contains('show')) {
        chatInput.focus();
        chatBody.scrollTop = chatBody.scrollHeight;
      }
    });

    chatClose.addEventListener('click', () => {
      chatContainer.classList.remove('show');
    });

    function sendMessage() {
      const message = chatInput.value.trim();
      if (!message) return;
      const messageElement = document.createElement('div');
      messageElement.className = 'chat-message message-sent';
      messageElement.innerHTML = `<div class="chat-message-content">${message}</div>`;
      chatBody.appendChild(messageElement);
      chatInput.value = '';
      chatBody.scrollTop = chatBody.scrollHeight;
      setTimeout(() => {
        const responseElement = document.createElement('div');
        responseElement.className = 'chat-message message-received';
        responseElement.innerHTML = `<div class="chat-message-content">Thanks for your message. Our support team will get back to you shortly.</div>`;
        chatBody.appendChild(responseElement);
        chatBody.scrollTop = chatBody.scrollHeight;
      }, 1000);
    }

    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keypress', e => {
      if (e.key === 'Enter') sendMessage();
    });

    // Close dropdowns on outside click
    document.addEventListener('click', () => {
      document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show'));
      document.querySelectorAll('.chart-filter').forEach(filter => filter.classList.remove('active'));
      // notifications dropdown is handled inside partials/notifications.php
    });

    document.querySelectorAll('.dropdown-menu').forEach(menu => {
      menu.addEventListener('click', e => e.stopPropagation());
    });

    // Notifications handled by partials/notifications.php

    document.addEventListener('DOMContentLoaded', () => {
      loadNotifications();
      if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
      // Charts are initialized by initRecentWidgetToCurrentMonth() and existing pie handlers
      const selPie = document.getElementById('selectedMonth');
      const ddPie = document.getElementById('monthDropdown');
      if (selPie && ddPie) {
        const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const now = new Date();
        const currentMonthName = monthNames[now.getMonth()];
        selPie.textContent = currentMonthName;
        ddPie.querySelectorAll('.dropdown-item').forEach(i => i.classList.toggle('selected', i.getAttribute('data-value') === currentMonthName));
        updateDocPieChart(currentMonthName);
      }
    });
    // Auto-refresh every 30 seconds (notifications only)
    setInterval(loadNotifications, 30000);
    if (typeof window.loadSidebarBadges === 'function') setInterval(window.loadSidebarBadges, 30000);
    
    // Sidebar badges handled globally by assets/smooth-interactions.js
    
    // Recent Activity Functions
    function showEncryptedDialog() {
      const modal = document.createElement('div');
      modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
      `;
      
      modal.innerHTML = `
        <div style="background: white; border-radius: 16px; padding: 32px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
          <div style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <i class="fas fa-lock" style="font-size: 28px; color: white;"></i>
          </div>
          <h3 style="margin: 0 0 16px 0; color: #1e293b; font-size: 20px; font-weight: 700;">Encrypted Document</h3>
          <p style="margin: 0 0 24px 0; color: #64748b; line-height: 1.6;">This document contains sensitive information and is encrypted for security purposes. You cannot open it directly from the activity list.</p>
          <button onclick="this.closest('div').parentElement.remove()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;">Understood</button>
        </div>
      `;
      
      document.body.appendChild(modal);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
      });
    }
    
    function confirmDelete(id, type) {
      const modal = document.createElement('div');
      modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
      `;
      
      modal.innerHTML = `
        <div style="background: white; border-radius: 16px; padding: 32px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
          <div style="width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <i class="fas fa-trash" style="font-size: 28px; color: white;"></i>
          </div>
          <h3 style="margin: 0 0 16px 0; color: #1e293b; font-size: 20px; font-weight: 700;">Delete Document</h3>
          <p style="margin: 0 0 24px 0; color: #64748b; line-height: 1.6;">Are you sure you want to delete "<strong>${type}</strong>"? This action cannot be undone.</p>
          <div style="display: flex; gap: 12px; justify-content: center;">
            <button onclick="this.closest('div').parentElement.remove()" style="background: #f1f5f9; color: #475569; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;">Cancel</button>
            <button onclick="deleteDocument(${id}, this)" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;">Delete</button>
          </div>
        </div>
      `;
      
      document.body.appendChild(modal);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
      });
    }
    
    function deleteDocument(id, button) {
      button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
      button.disabled = true;
      
      fetch(`tracking.php?action=delete&id=${id}`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
        }
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Remove the activity item from the DOM
          const activityItems = document.querySelectorAll('.activity-item');
          activityItems.forEach(item => {
            if (item.innerHTML.includes(`deleteDocument(${id}`)) {
              item.style.transition = 'all 0.3s ease';
              item.style.opacity = '0';
              item.style.transform = 'translateX(-20px)';
              setTimeout(() => item.remove(), 300);
            }
          });
          
          // Close the modal
          button.closest('div').parentElement.remove();
          
          // Show success message
          showNotification('Document deleted successfully', 'success');
        } else {
          throw new Error(data.message || 'Delete failed');
        }
      })
      .catch(error => {
        console.error('Delete error:', error);
        button.innerHTML = 'Delete';
        button.disabled = false;
        showNotification('Failed to delete document', 'error');
      });
    }
    
    function showNotification(message, type = 'info') {
      const notification = document.createElement('div');
      const bgColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6';
      notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${bgColor};
        color: white;
        padding: 16px 20px;
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 10001;
        font-weight: 500;
        transform: translateX(100%);
        transition: transform 0.3s ease;
      `;
      notification.textContent = message;
      
      document.body.appendChild(notification);
      
      setTimeout(() => {
        notification.style.transform = 'translateX(0)';
      }, 100);
      
      setTimeout(() => {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => notification.remove(), 300);
      }, 3000);
    }
    
    // Clear notifications handler is already defined above; avoid duplication.
  </script>

  <!-- Welcome Modal -->
  <?php if ($showWelcome): ?>
  <div id="welcomeModal" class="welcome-modal">
    <div class="welcome-modal-content">
      <div class="welcome-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <h2>Welcome Back!</h2>
      <p><strong><span id="welcomeGreeting">Hello, <?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'User')[0]); ?></span></strong>,</p>
      <p>You have successfully logged into the CHRMO Document Tracking System.</p>
      <div class="welcome-features">
        <div class="feature-item">
          <i class="fas fa-file-alt"></i>
          <span>Manage Documents</span>
        </div>
        <div class="feature-item">
          <i class="fas fa-chart-bar"></i>
          <span>View Statistics</span>
        </div>
        <div class="feature-item">
          <i class="fas fa-users"></i>
          <span>Track Progress</span>
        </div>
      </div>
      <div class="welcome-footer">
        <p>Ready to get started?</p>
      </div>
    </div>
  </div>

  <style>
    .welcome-modal {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 10000;
      opacity: 1;
      animation: fadeIn 0.45s ease-out both;
    }

    .welcome-modal-content {
      background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
      color: #1e293b;
      padding: 40px;
      border-radius: 20px;
      text-align: center;
      max-width: 450px;
      width: 90%;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
      border: 1px solid #cbd5e1;
      animation: slideInScale 0.6s ease-out;
      position: relative;
      overflow: hidden;
    }

    /* Remove looping shimmer to avoid stutter */
    .welcome-modal-content::before { display: none; }

    .welcome-icon {
      font-size: 4rem;
      color: #4ade80;
      margin-bottom: 20px;
      animation: bounceIn 0.6s ease-out 0.2s both;
    }

    .welcome-modal h2 {
      font-size: 2.2rem;
      margin-bottom: 15px;
      font-weight: 700;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .welcome-modal p {
      font-size: 1.1rem;
      margin-bottom: 15px;
      opacity: 0.95;
      line-height: 1.5;
    }

    .welcome-features {
      display: flex;
      justify-content: space-around;
      margin: 30px 0;
      flex-wrap: wrap;
      gap: 15px;
    }

    .feature-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      opacity: 0;
      animation: fadeInUp 0.6s ease-out forwards;
    }

    .feature-item:nth-child(1) { animation-delay: 0.8s; }
    .feature-item:nth-child(2) { animation-delay: 1s; }
    .feature-item:nth-child(3) { animation-delay: 1.2s; }

    .feature-item i {
      font-size: 1.8rem;
      margin-bottom: 8px;
      color: #fbbf24;
    }

    .feature-item span {
      font-size: 0.9rem;
      font-weight: 500;
    }

    .welcome-footer {
      margin-top: 25px;
      padding-top: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.2);
    }

    .welcome-footer p {
      font-size: 1rem;
      margin: 0;
      font-weight: 500;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideInScale {
      from {
        opacity: 0;
        transform: translateY(-30px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @keyframes bounceIn {
      0% {
        opacity: 0;
        transform: scale(0.3);
      }
      50% {
        opacity: 1;
        transform: scale(1.05);
      }
      70% {
        transform: scale(0.9);
      }
      100% {
        opacity: 1;
        transform: scale(1);
      }
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeOut {
      from { opacity: 1; }
      to { opacity: 0; }
    }

    .welcome-modal.fade-out {
      animation: fadeOut 0.45s ease-out forwards;
    }

    @media (max-width: 480px) {
      .welcome-modal-content {
        padding: 30px 25px;
        margin: 20px;
      }
      
      .welcome-modal h2 {
        font-size: 1.8rem;
      }
      
      .welcome-features {
        flex-direction: column;
        align-items: center;
      }
    }
  </style>

  <script>
    // Set greeting based on client-side local time
    (function() {
      const greetingEl = document.getElementById('welcomeGreeting');
      if (greetingEl) {
        const hour = new Date().getHours();
        let greeting = 'Hello';
        if (hour < 12) {
          greeting = 'Good morning';
        } else if (hour < 18) {
          greeting = 'Good afternoon';
        } else {
          greeting = 'Good evening';
        }
        const firstName = greetingEl.textContent.replace(/^Hello,\s*/, '').trim();
        greetingEl.textContent = greeting + ', ' + firstName;
      }
    })();

    // Auto-close welcome modal after shorter delay with smooth fade
    <?php if ($showWelcome): ?>
    setTimeout(function() {
      const modal = document.getElementById('welcomeModal');
      if (modal) {
        // Apply smooth fade transition
        modal.style.transition = 'opacity 0.45s ease-out, transform 0.45s ease-out';
        modal.style.opacity = '0';
        modal.style.transform = 'translateY(-6px)';

        setTimeout(function() {
          modal.remove();
          // Clean URL by removing welcome parameter
          const url = new URL(window.location);
          url.searchParams.delete('welcome');
          window.history.replaceState({}, document.title, url.pathname + url.search);
        }, 460);
      }
    }, 1300);
    <?php endif; ?>
  </script>
  <?php endif; ?>
</body>
</html>
