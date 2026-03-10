<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config.php';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) throw new Exception('DB connect failed: ' . $conn->connect_error);

    $dept = isset($_GET['department']) ? trim($_GET['department']) : '';
    if ($dept === '') throw new Exception('department is required');

    $sql = "SELECT id, user AS username, email, department FROM control WHERE department = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('s', $dept);
    if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();

    echo json_encode([ 'success' => true, 'users' => $rows ]);
    $conn->close();
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([ 'success' => false, 'message' => $e->getMessage() ]);
}
