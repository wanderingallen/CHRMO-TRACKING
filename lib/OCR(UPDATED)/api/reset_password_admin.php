<?php
// API: Reset password by username for ADMIN roles only (web client)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';

function json_exit($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_exit(405, ['success' => false, 'message' => 'Method not allowed']);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $payload = [];
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: [];
    } else {
        $payload = $_POST;
    }

    $username = trim($payload['identifier'] ?? $payload['username'] ?? $payload['user'] ?? $payload['uname'] ?? '');
    $newPassword = (string)($payload['new_password'] ?? $payload['password'] ?? $payload['pass'] ?? '');
    $confirm = (string)($payload['confirm_password'] ?? $payload['confirm'] ?? $newPassword);

    if ($username === '' || $newPassword === '' || $confirm === '') {
        json_exit(400, ['success' => false, 'message' => 'Missing required fields']);
    }
    if ($newPassword !== $confirm) {
        json_exit(400, ['success' => false, 'message' => 'Passwords do not match']);
    }
    if (strlen($newPassword) < 6) {
        json_exit(400, ['success' => false, 'message' => 'Password must be at least 6 characters']);
    }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        json_exit(500, ['success' => false, 'message' => 'DB connection failed']);
    }

    // Web accounts live in `users` table with fixed `password` column
    $allowedRoles = ["admin","administrator","superadmin","super_admin"];
    $rolePlaceholders = implode(',', array_fill(0, count($allowedRoles), '?'));

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update only if role is in allowed set, by username stored in `name` (case-insensitive)
    $sql = "UPDATE users SET `password` = ? WHERE LOWER(`name`) = LOWER(?) AND LOWER(`role`) IN ($rolePlaceholders)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_exit(500, ['success' => false, 'message' => 'Prepare failed']);
    }
    $types = 'ss' . str_repeat('s', count($allowedRoles));
    $params = array_merge([$hash, $username], array_map('strtolower', $allowedRoles));
    $bindParams = array_merge([$types], $params);
    // bind_param requires references
    $refs = [];
    foreach ($bindParams as $k => $v) { $refs[$k] = &$bindParams[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $stmt->close();
        $conn->close();
        json_exit(200, ['success' => true, 'message' => 'Password updated (admin)']);
    }

    $stmt->close();

    // Check whether user exists at all in `users`
    $check = $conn->prepare('SELECT role FROM users WHERE LOWER(`name`) = LOWER(?) LIMIT 1');
    $check->bind_param('s', $username);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->bind_result($role);
        $check->fetch();
        $role = strtolower($role ?? '');
        if (!in_array($role, $allowedRoles, true)) {
            $check->close();
            $conn->close();
            json_exit(403, ['success' => false, 'message' => 'Only admin accounts can be reset on the web.']);
        }
        // Admin but unchanged
        $check->close();
        $conn->close();
        json_exit(500, ['success' => false, 'message' => 'Password unchanged or same as before.']);
    } else {
        $check->close();
        $conn->close();
        json_exit(404, ['success' => false, 'message' => 'Username not found']);
    }

} catch (Throwable $e) {
    error_log('reset_password_admin.php error: ' . $e->getMessage());
    json_exit(500, ['success' => false, 'message' => 'Server error']);
}
