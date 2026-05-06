<?php
require_once 'security.php';
require_once 'config.php';

// Support signed URLs for mobile (no PHP session cookie).
// If `sig` + `exp` are provided and valid, bypass session login.
$__idForSig = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$__sig = isset($_GET['sig']) ? trim((string)$_GET['sig']) : '';
$__exp = isset($_GET['exp']) ? trim((string)$_GET['exp']) : '';
$__now = time();
$__sigOk = false;
if ($__idForSig !== '' && preg_match('/^\d+$/', $__idForSig) && $__sig !== '' && $__exp !== '' && preg_match('/^\d+$/', $__exp)) {
    $expNum = (int)$__exp;
    if ($expNum >= $__now) {
        $payload = 'att:' . $__idForSig . ':' . $__exp;
        $key = defined('SECRET_KEY') ? (string)SECRET_KEY : '';
        if ($key !== '') {
            $calc = hash_hmac('sha256', $payload, $key);
            $__sigOk = hash_equals($calc, $__sig);
        }
    }
}
if (!$__sigOk) {
    Security::require_login();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '' || !preg_match('/^\d+$/', $id)) {
    http_response_code(400);
    echo 'Bad request';
    exit();
}

$inline = isset($_GET['inline']) ? (int)$_GET['inline'] : 1;

$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
    http_response_code(500);
    echo 'DB connection error';
    exit();
}

$stmt = $connection->prepare("SELECT file_path, file_name FROM document_attachments WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();
$connection->close();

if (!$row || empty($row['file_path'])) {
    http_response_code(404);
    echo 'File not found';
    exit();
}

$storedPath = trim((string)$row['file_path']);
$fileNameFromDb = trim((string)($row['file_name'] ?? ''));

// Normalize stored paths
if (strpos($storedPath, 'lib/OCR(UPDATED)/') === 0) {
    $storedPath = substr($storedPath, strlen('lib/OCR(UPDATED)/'));
}
if (strpos($storedPath, 'lib/uploads/') === 0) {
    $storedPath = substr($storedPath, strlen('lib/'));
}

// Try new location first: lib/OCR(UPDATED)/<storedPath>
$abs = __DIR__ . '/' . ltrim($storedPath, '/');
// Fallback legacy: lib/<storedPath>
if (!is_file($abs)) {
    $abs = __DIR__ . '/../' . ltrim($storedPath, '/');
}

if (!is_file($abs)) {
    http_response_code(404);
    echo 'File not found on disk';
    exit();
}

$blob = @file_get_contents($abs);
if ($blob === false) {
    http_response_code(500);
    echo 'Failed to read file';
    exit();
}

$plain = null;
$offset = 0;
$magic = (strlen($blob) >= 4) ? substr($blob, 0, 4) : '';
if ($magic !== 'ENC1') {
    $plain = $blob;
} else {
    if (strlen($blob) < 4 + 12 + 16) {
        http_response_code(500);
        echo 'Corrupted encrypted file';
        exit();
    }
    $offset = 4;
    $iv = substr($blob, $offset, 12); $offset += 12;
    $tag = substr($blob, $offset, 16); $offset += 16;
    $ciphertext = substr($blob, $offset);

    $key = defined('FILE_ENC_KEY') ? FILE_ENC_KEY : '';
    $keyBin = ctype_xdigit($key) ? hex2bin($key) : (strlen($key) === 32 ? $key : hash('sha256', (string)$key, true));
    $plainDec = @openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plainDec === false) {
        http_response_code(500);
        echo 'Decryption failed';
        exit();
    }
    $plain = $plainDec;
}

$downloadName = $fileNameFromDb !== '' ? $fileNameFromDb : basename($abs);
if (str_ends_with($downloadName, '.enc')) {
    $downloadName = substr($downloadName, 0, -4);
}

$ext = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
else if (in_array($ext, ['jpg','jpeg','png','gif','bmp','webp'], true)) $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
else if (in_array($ext, ['txt','log'], true)) $mime = 'text/plain; charset=utf-8';

header('Content-Type: ' . $mime);
$disp = $inline ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disp . '; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($plain));

echo $plain;
exit();
