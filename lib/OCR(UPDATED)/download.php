<?php
require_once 'security.php';
require_once 'config.php';

Security::require_login();

// Prevent caching so updated documents (e.g., returned re-capture) always show the latest file
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Validate ID
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '' || !preg_match('/^\d+$/', $id)) {
    http_response_code(400);
    echo 'Bad request';
    exit();
}

// DB connect (mysqli, consistent with other pages)
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
    http_response_code(500);
    echo 'DB connection error';
    exit();
}

// Lookup file path
$stmt = $connection->prepare("SELECT file_path FROM tracking WHERE id = ?");
$stmt->bind_param('s', $id);
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

$storedPath = $row['file_path'];
// Allow paths stored either as 'uploads/archive/..' or 'lib/OCR(UPDATED)/uploads/archive/..'
if (strpos($storedPath, 'lib/OCR(UPDATED)/') === 0) {
    $storedPath = substr($storedPath, strlen('lib/OCR(UPDATED)/'));
}
$encPath = __DIR__ . '/' . ltrim($storedPath, '/');
if (!is_file($encPath)) {
    http_response_code(404);
    echo 'File not found on disk';
    exit();
}

// Read encrypted file
$blob = @file_get_contents($encPath);
if ($blob === false || strlen($blob) < 4 + 12 + 16) {
    http_response_code(500);
    echo 'Corrupted encrypted file';
    exit();
}

$offset = 0;
$magic = substr($blob, $offset, 4); $offset += 4;
if ($magic !== 'ENC1') {
    // Not encrypted with our scheme: serve raw as fallback (legacy files)
    $fallbackName = basename($encPath);
    $extRaw = strtolower(pathinfo($fallbackName, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if (in_array($extRaw, ['jpg','jpeg','png','gif','bmp','webp'], true)) {
        $mime = $extRaw === 'jpg' ? 'image/jpeg' : ($extRaw === 'jpeg' ? 'image/jpeg' : 'image/' . $extRaw);
    } elseif ($extRaw === 'pdf') {
        $mime = 'application/pdf';
    } elseif (in_array($extRaw, ['txt','log'], true)) {
        $mime = 'text/plain; charset=utf-8';
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $fallbackName . '"');
    header('Content-Length: ' . strlen($blob));
    echo $blob;
    exit();
}
$iv = substr($blob, $offset, 12); $offset += 12;
$tag = substr($blob, $offset, 16); $offset += 16;
$ciphertext = substr($blob, $offset);

$key = defined('FILE_ENC_KEY') ? FILE_ENC_KEY : '';
$keyBin = ctype_xdigit($key) ? hex2bin($key) : (strlen($key) === 32 ? $key : hash('sha256', (string)$key, true));
$plain = @openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
if ($plain === false) {
    error_log('[download.php] Decryption failed for tracking_id=' . (int)$id . ' path=' . $encPath);
    http_response_code(500);
    echo 'Decryption failed';
    exit();
}

// Try to derive original filename from stored pattern: <uniqid>_<original>.enc
$base = basename($encPath);
$downloadName = 'document_' . $id;
if (str_ends_with($base, '.enc')) { $base = substr($base, 0, -4); }
$pos = strpos($base, '_');
if ($pos !== false && $pos + 1 < strlen($base)) {
    $candidate = substr($base, $pos + 1);
    if ($candidate !== '') { $downloadName = $candidate; }
}

// Try to infer a sensible Content-Type from the download name
$ext = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if (in_array($ext, ['jpg','jpeg','png','gif','bmp','webp'], true)) {
    $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
} elseif ($ext === 'pdf') {
    $mime = 'application/pdf';
} elseif (in_array($ext, ['txt','log'], true)) {
    $mime = 'text/plain; charset=utf-8';
}

header('Content-Type: ' . $mime);
// Inline so browser/tab viewer shows the file instead of forcing a download
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($plain));
echo $plain;
exit();
