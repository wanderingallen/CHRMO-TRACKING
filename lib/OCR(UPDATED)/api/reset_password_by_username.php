<?php
// API: Reset password by username for 'control' table (mobile client)
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

    // Accept JSON or form-encoded
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $payload = [];
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: [];
    } else {
        $payload = $_POST;
    }

    // Accept multiple field names
    $username = trim($payload['identifier'] ?? $payload['username'] ?? $payload['user'] ?? $payload['uname'] ?? '');
    $newPassword = (string)($payload['new_password'] ?? $payload['password'] ?? $payload['pass'] ?? '');
    $confirm = (string)($payload['confirm_password'] ?? $payload['confirm'] ?? $newPassword);

    if ($username === '' || $newPassword === '' || $confirm === '') {
        json_exit(400, ['success' => false, 'message' => 'Missing required fields', 'received' => $payload]);
    }
    if ($newPassword !== $confirm) {
        json_exit(400, ['success' => false, 'message' => 'Passwords do not match']);
    }
    if (strlen($newPassword) < 6) {
        json_exit(400, ['success' => false, 'message' => 'Password must be at least 6 characters']);
    }

    // Connect using mysqli like usercontrol.php to target `control` table
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        json_exit(500, ['success' => false, 'message' => 'DB connection failed']);
    }

    // Detect password column in `control`
    $candidates = ['password','pwd','pass','password_hash','pword'];
    $passwordCol = null;
    foreach ($candidates as $col) {
        $esc = $conn->real_escape_string($col);
        $res = $conn->query("SHOW COLUMNS FROM `control` LIKE '$esc'");
        if ($res && $res->num_rows > 0) { $passwordCol = $col; $res->free(); break; }
        if ($res) { $res->free(); }
    }
    if ($passwordCol === null) {
        json_exit(500, ['success' => false, 'message' => 'Password column not found in control table']);
    }

    // Hash password
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update by username (column is `user` in usercontrol.php)
    $sql = "UPDATE control SET `$passwordCol` = ? WHERE `user` = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        json_exit(500, ['success' => false, 'message' => 'Prepare failed']);
    }
    $stmt->bind_param('ss', $hash, $username);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $stmt->close();
        $conn->close();
        json_exit(200, ['success' => true, 'message' => 'Password updated']);
    }

    // If not updated, maybe username not found
    $stmt->close();

    // Check existence
    $check = $conn->prepare('SELECT id FROM control WHERE `user` = ? LIMIT 1');
    $check->bind_param('s', $username);
    $check->execute();
    $check->store_result();
    if ($check->num_rows === 0) {
        $check->close();
        $conn->close();
        json_exit(404, ['success' => false, 'message' => 'Username not found']);
    }
    $check->close();
    $conn->close();
    json_exit(500, ['success' => false, 'message' => 'Password unchanged']);

} catch (Throwable $e) {
    error_log('reset_password_by_username.php error: ' . $e->getMessage());
    json_exit(500, ['success' => false, 'message' => 'Server error']);
}
