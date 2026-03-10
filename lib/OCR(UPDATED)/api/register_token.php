<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); echo json_encode(['success'=>true]); exit; }

require_once __DIR__ . '/../db_connect.php'; // $conn mysqli

function respond($arr, $code=200){ http_response_code($code); echo json_encode($arr); exit; }

// Ensure table
$conn->query("CREATE TABLE IF NOT EXISTS fcm_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(128) NOT NULL,
  department VARCHAR(128) DEFAULT NULL,
  token TEXT NOT NULL,
  platform VARCHAR(16) DEFAULT 'android',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(username), INDEX(department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$username = trim((string)($_POST['username'] ?? $_GET['username'] ?? ''));
$department = trim((string)($_POST['department'] ?? $_GET['department'] ?? ''));
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$platform = trim((string)($_POST['platform'] ?? $_GET['platform'] ?? 'android'));
if ($username === '' || $token === '') respond(['success'=>false,'message'=>'username and token required'],400);

// Upsert by username+platform (latest token wins)
$stmt = $conn->prepare("SELECT id FROM fcm_tokens WHERE username=? AND platform=? LIMIT 1");
$stmt->bind_param('ss',$username,$platform);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $id = intval($row['id']);
    $stmt2 = $conn->prepare("UPDATE fcm_tokens SET token=?, department=? WHERE id=?");
    $stmt2->bind_param('ssi',$token,$department,$id);
    $ok = $stmt2->execute();
} else {
    $stmt2 = $conn->prepare("INSERT INTO fcm_tokens(username,department,token,platform) VALUES (?,?,?,?)");
    $stmt2->bind_param('ssss',$username,$department,$token,$platform);
    $ok = $stmt2->execute();
}

respond(['success'=>$ok]);
