<?php
// Lightweight endpoint for sidebar badges (JSON only)
// Department-scoped: non-admin users see only docs relevant to their department.
// Uses a short-lived file cache (15s) keyed per-department to avoid cross-dept pollution.

header('Content-Type: application/json');

// Start session to read user role/department
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine admin status and department
$__ssIsAdmin = false;
$__ssDept = '';
$adminRoles = ['admin', 'administrator', 'superadmin', 'super_admin'];
if (isset($_SESSION['user_role']) && in_array(strtolower(trim($_SESSION['user_role'])), $adminRoles)) {
    $__ssIsAdmin = true;
}
if (!$__ssIsAdmin && !empty($_SESSION['user_department'])) {
    $__ssDept = strtoupper(trim($_SESSION['user_department']));
    if ($__ssDept === 'ACCOUNT' || $__ssDept === 'ACCOUNTING' || $__ssDept === 'CAO') {
        $__ssDept = 'CACCO';
    }
}

// ── File-based cache (15-second TTL) — keyed per department ──
$cacheKey = $__ssIsAdmin ? 'admin' : ($__ssDept !== '' ? $__ssDept : 'global');
$cacheFile = sys_get_temp_dir() . '/chrmo_sidebar_stats_' . md5($cacheKey) . '.json';
$cacheTTL = 15; // seconds

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    // Serve from cache — no DB hit
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
        // ETag support even for cached responses
        $etag = '"sb-' . md5($cached) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: no-cache');
        $clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
        if ($clientEtag === $etag) {
            http_response_code(304);
            exit();
        }
        echo $cached;
        exit();
    }
}

// Database connection
require_once __DIR__ . '/config.php';
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
  http_response_code(500);
  echo json_encode(['error' => 'db_connect_failed']);
  exit();
}

$__ssHasDeptArchives = false;
if ($chkDa = @$connection->query("SHOW TABLES LIKE 'department_archives'")) {
    $__ssHasDeptArchives = ($chkDa->num_rows > 0);
    $chkDa->free();
}

// Total documents (department-scoped for non-admin users)
$total = 0;
if (!$__ssIsAdmin && $__ssDept !== '') {
    $sqlT = "SELECT COUNT(*) AS c FROM tracking WHERE UPPER(TRIM(COALESCE(status,''))) <> 'ARCHIVED' AND (UPPER(TRIM(department)) = ? OR UPPER(TRIM(current_holder)) = ? OR UPPER(TRIM(end_location)) = ? OR (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(routing_queue, ' ', ''))) > 0 AND CAST(COALESCE(route_step, 0) AS UNSIGNED) >= (FIND_IN_SET(UPPER(TRIM(?)), UPPER(REPLACE(routing_queue, ' ', ''))) - 1)))";
    if ($__ssHasDeptArchives) {
        $sqlT .= " AND NOT EXISTS (SELECT 1 FROM department_archives da WHERE da.tracking_id = tracking.id AND UPPER(TRIM(da.department)) = ?)";
    }
    if ($stmtT = $connection->prepare($sqlT)) {
        if ($__ssHasDeptArchives) {
            $stmtT->bind_param('ssssss', $__ssDept, $__ssDept, $__ssDept, $__ssDept, $__ssDept, $__ssDept);
        } else {
            $stmtT->bind_param('sssss', $__ssDept, $__ssDept, $__ssDept, $__ssDept, $__ssDept);
        }
        if ($stmtT->execute()) {
            $resT = $stmtT->get_result();
            if ($rowT = $resT->fetch_assoc()) { $total = (int)$rowT['c']; }
            if ($resT) $resT->free();
        }
        $stmtT->close();
    }
} else {
    if ($res = $connection->query("SELECT COUNT(*) AS c FROM tracking WHERE UPPER(TRIM(COALESCE(status,''))) <> 'ARCHIVED'")) {
        if ($row = $res->fetch_assoc()) { $total = (int)$row['c']; }
        $res->free();
    }
}

// Total archived documents — department-scoped for non-admin users
$archivedToday = 0;
if (!$__ssIsAdmin && $__ssDept !== '') {
    $sqlA = "SELECT COUNT(*) AS c FROM archive WHERE (UPPER(TRIM(department)) = ? OR UPPER(TRIM(last_department)) = ?)";
    if ($stmtA = $connection->prepare($sqlA)) {
        $stmtA->bind_param('ss', $__ssDept, $__ssDept);
        if ($stmtA->execute()) {
            $resA = $stmtA->get_result();
            if ($rowA = $resA->fetch_assoc()) { $archivedToday = (int)$rowA['c']; }
            if ($resA) $resA->free();
        }
        $stmtA->close();
    }
} else {
    $sqlArchived = "SELECT COUNT(*) AS c FROM archive";
    if ($resA = $connection->query($sqlArchived)) {
        if ($rowA = $resA->fetch_assoc()) { $archivedToday = (int)$rowA['c']; }
        $resA->free();
    }
}

$connection->close();

$payload = json_encode(['pending_count' => $total, 'archived_today' => $archivedToday]);

// Write cache file (best-effort)
@file_put_contents($cacheFile, $payload, LOCK_EX);

// ETag support: let browsers skip re-downloading identical JSON
$etag = '"sb-' . md5($payload) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache');
$clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
if ($clientEtag === $etag) {
    http_response_code(304);
    exit();
}

echo $payload;
