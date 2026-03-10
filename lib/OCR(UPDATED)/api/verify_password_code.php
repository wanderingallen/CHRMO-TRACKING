<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); echo json_encode(['success'=>true]); exit; }

require_once __DIR__ . '/../db_connect.php';

$identifier = trim((string)($_POST['identifier'] ?? ''));
$code = trim((string)($_POST['code'] ?? ''));
$newPassword = (string)($_POST['new_password'] ?? '');
if ($identifier === '' || $code === '' || $newPassword === '') { http_response_code(400); echo json_encode(['success'=>false,'message'=>'identifier, code, new_password required']); exit; }

// Find the latest, unexpired, unused code
$stmt = $conn->prepare("SELECT * FROM password_resets_mobile WHERE (username = ? OR email = ?) AND code = ? AND used = 0 ORDER BY id DESC LIMIT 1");
$stmt->bind_param('sss', $identifier, $identifier, $code);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
if (!$row) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid code']); exit; }
if ((int)$row['expires_at'] < time()) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Code expired']); exit; }

// Detect password column
$pwdCol = null;
$candidates = ['password','pwd','pass','password_hash','pword','passwd'];
foreach ($candidates as $col) {
  $rs = $conn->query("SHOW COLUMNS FROM `control` LIKE '".$conn->real_escape_string($col)."'");
  if ($rs && $rs->num_rows > 0) { $pwdCol = $col; $rs->free(); break; }
  if ($rs) $rs->free();
}
if (!$pwdCol) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Password column not found']); exit; }

// Find the target user
$stmtU = $conn->prepare("SELECT id FROM control WHERE `user` = ? OR `email` = ? LIMIT 1");
$stmtU->bind_param('ss', $identifier, $identifier);
$stmtU->execute();
$ru = $stmtU->get_result();
$u = $ru->fetch_assoc();
if (!$u) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found']); exit; }
$uid = (int)$u['id'];

$hash = password_hash($newPassword, PASSWORD_BCRYPT);
// Update password
$stmtUp = $conn->prepare("UPDATE control SET `{$pwdCol}` = ? WHERE id = ? LIMIT 1");
$stmtUp->bind_param('si', $hash, $uid);
if (!$stmtUp->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Failed to update password']); exit; }

// Mark code as used
$conn->query("UPDATE password_resets_mobile SET used = 1 WHERE id = ".((int)$row['id'])." LIMIT 1");

echo json_encode(['success'=>true]);
