<?php
// Simple API to update user's display name
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../security.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
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

    $rawId = $payload['user_id'] ?? '';
    $rawEmail = $payload['email'] ?? '';
    $rawName = $payload['name'] ?? '';

    $userId = intval($rawId);
    $email = trim($rawEmail);
    $name = trim(Security::sanitize($rawName));

    if (($userId <= 0 && $email === '') || $name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid parameters: provide user_id or email, and name', 'received' => $payload]);
        exit;
    }

    $db = Database::getInstance()->getConnection();

    // Try update by id first when valid
    $updated = false;
    if ($userId > 0) {
        $stmt = $db->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([$name, $userId]);
        $updated = $stmt->rowCount() > 0;
    }

    // If not updated yet and email provided, try by email
    if (!$updated && $email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid email']);
            exit;
        }
        $stmt = $db->prepare('UPDATE users SET name = ? WHERE email = ?');
        $stmt->execute([$name, $email]);
        $updated = $stmt->rowCount() > 0;
    }

    if (!$updated) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Optional: log event
    if (class_exists('Security')) {
        Security::logEvent('profile_name_updated', $userId, null, ['name' => $name]);
    }

    echo json_encode(['success' => true, 'name' => $name]);
} catch (Exception $e) {
    error_log('update_profile.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
