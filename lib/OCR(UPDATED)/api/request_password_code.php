<?php
@ini_set('display_errors', 0);
@ini_set('html_errors', 0);
if (function_exists('ob_get_level')) { @ob_start(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } } echo json_encode(['success'=>true]); exit; }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../email_config.php';

$src = $_POST;
if (empty($src)) { $src = $_GET; }
// Accept common aliases: identifier, email, user, username, to; fallback to session email
$identifier = trim((string)($src['identifier'] ?? $src['email'] ?? $src['user'] ?? $src['username'] ?? $src['to'] ?? ''));
if ($identifier === '' && isset($_SESSION) && !empty($_SESSION['user_email'])) {
    $identifier = trim((string)$_SESSION['user_email']);
}
if ($identifier === '') {
    http_response_code(400);
    if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } }
    echo json_encode([
        'success'=>false,
        'message'=>'identifier required (use ?identifier= or ?email= or ?user=)' 
    ]);
    exit;
}

// Find user by username or email in control table
$stmt = $conn->prepare("SELECT id, `user`, `email` FROM control WHERE `user` = ? OR `email` = ? LIMIT 1");
$stmt->bind_param('ss', $identifier, $identifier);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
if (!$user) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found']); exit; }

$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires = time() + 10 * 60; // 10 minutes

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS password_resets_mobile (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(128) NULL,
  email VARCHAR(255) NOT NULL,
  code VARCHAR(12) NOT NULL,
  created_at INT NOT NULL,
  expires_at INT NOT NULL,
  used TINYINT(1) DEFAULT 0,
  INDEX(email), INDEX(code), INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt2 = $conn->prepare("INSERT INTO password_resets_mobile (user_id, username, email, code, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
$uid = (int)($user['id'] ?? 0);
$uname = (string)$user['user'];
$uemail = (string)$user['email'];
$now = time();
$stmt2->bind_param('isssii', $uid, $uname, $uemail, $code, $now, $expires);
$stmt2->execute();

// Send email using PHP mail() as fallback; can be replaced with SMTP library
$subject = 'Your CHRMO password reset code';
$bodyTxt = "Hello {$uname},\n\nYour verification code is: {$code}\nThis code will expire in 10 minutes.\n\nIf you did not request this, you can ignore this email.";
// Send via email_config (PHPMailer SMTP if available), fallback handled inside
$sent = sendMail($uemail, $subject, nl2br($bodyTxt));
if (!$sent) {
    if (function_exists('smtp_last_error')) {
        $smtpErr = smtp_last_error();
        if (!empty($smtpErr)) { error_log('[ForgotPasswordSMTP] ' . $smtpErr); }
    }
}

if (function_exists('ob_get_length')) { while (ob_get_level()>0) { @ob_end_clean(); } }
echo json_encode([
    'success'=>true,
    'sent'=>$sent,
    'email'=>$uemail,
    'mask'=> substr($uemail,0,2).'***@'.preg_replace('/^.*@/','',$uemail),
    'smtp_error'=> (function_exists('smtp_last_error') ? smtp_last_error() : null)
]);
