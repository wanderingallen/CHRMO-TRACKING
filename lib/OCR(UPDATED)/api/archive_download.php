<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/archive_storage.php';
require_once __DIR__ . '/file_crypto.php';

Security::require_login();

if (!isset($_GET['id']) || !preg_match('/^\d+$/', (string)$_GET['id'])) {
    http_response_code(400);
    echo 'Invalid request';
    exit();
}
$id = (int)$_GET['id'];

$inline = isset($_GET['inline']) && $_GET['inline'] == '1';
$downloadFlag = isset($_GET['dl']);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo 'Database connection error';
    exit();
}

// Also fetch file_path column to check for directly stored path
$stmt = $conn->prepare('SELECT document_name, type, date_archived, file_type_icon, file_path FROM archive WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();
$conn->close();

if (!$row) {
    http_response_code(404);
    echo 'Document not found';
    exit();
}

// First try to find file using the file_path column (supports final document uploads)
$path = null;
if (!empty($row['file_path'])) {
    // file_path may be relative like "uploads/archive/123_1234567890.jpg.enc"
    // Try resolving it relative to OCR(UPDATED) directory
    $storedPath = (string)$row['file_path'];
    $storedPath = str_replace('\\', '/', $storedPath);
    // Allow stored paths like "lib/OCR(UPDATED)/uploads/final/..." (legacy)
    $marker = 'lib/OCR(UPDATED)/';
    $pos = strpos($storedPath, $marker);
    if ($pos !== false) {
        $storedPath = substr($storedPath, $pos + strlen($marker));
    }
    $storedPath = ltrim($storedPath, '/');
    
    // Check if it's an absolute path
    if (file_exists($storedPath)) {
        $path = $storedPath;
    } else {
        // Try relative to OCR(UPDATED)
        $basePath = realpath(__DIR__ . '/..');
        $fullPath = rtrim((string)$basePath, '/\\') . '/' . $storedPath;
        if (file_exists($fullPath)) {
            $path = realpath($fullPath);
        }
    }
}

// Fallback to the archive storage pattern lookup
if (!$path || !is_file($path)) {
    // Strategy: Search for file in known sibling storage locations
    $baseName = '';
    if (!empty($row['file_path'])) {
        $baseName = basename($row['file_path']);
    }
    
    $roots = [];
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot !== '') {
        $roots[] = rtrim($docRoot, '/') . '/CHRMO-TRACKING-main/lib/OCR(UPDATED)';
        $roots[] = rtrim($docRoot, '/') . '/flutter_application_7/lib/OCR(UPDATED)';
    }
    
    $subDirs = ['uploads/final', 'uploads/archive', 'uploads/returned', 'uploads/batch'];
    
    foreach ($roots as $root) {
        foreach ($subDirs as $sd) {
            $cand = rtrim($root, '/\\') . '/' . $sd . '/' . $baseName;
            if (is_file($cand)) {
                $path = $cand;
                break 2;
            }
            $candEnc = $cand . '.enc';
            if (is_file($candEnc)) {
                $path = $candEnc;
                break 2;
            }
        }
    }
}

if (!$path || !is_file($path)) {
    // Final check for specific archive pattern if filename search failed
    if (!$path || !is_file($path)) {
        $path = archive_find_file_path($id);
    }
}

if (!$path || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "File not found on server.\nID: $id\nExpected: " . ($row['file_path'] ?? 'unknown');
    exit();
}

$blob = @file_get_contents($path);
if ($blob === false) {
    http_response_code(500);
    echo 'Unable to read encrypted file';
    exit();
}

// Support both encrypted payloads (ENC1...) and legacy/raw files.
$plain = null;
if (substr($blob, 0, 4) === 'ENC1') {
    $plain = file_crypto_decrypt_blob($blob);
    if ($plain === false) {
        http_response_code(500);
        echo 'Unable to decrypt file';
        exit();
    }
} else {
    $plain = $blob;
}

// Diagnostic logging for empty payloads
if (empty($plain)) {
    error_log("archive_download.php: Decrypted content is empty for ID $id (path: $path)");
}

$ext = archive_guess_extension_from_path($path) ?: infer_ext_from_icon($row['file_type_icon'] ?? '');
$mime = mime_from_extension($ext);

// Prefer a stable, user-friendly download name: <Type>(YYYY-MM-DD_HH-mm).ext
$base = trim((string)($row['type'] ?? ''));
if ($base === '') {
    $base = (string)($row['document_name'] ?: ('document_' . $id));
}
$ts = !empty($row['date_archived']) ? strtotime((string)$row['date_archived']) : false;
$stamp = $ts ? date('Y-m-d_H-i', $ts) : date('Y-m-d_H-i');
$downloadName = $base . '(' . $stamp . ')';
$downloadName = preg_replace('/[^A-Za-z0-9_\-\.\(\)]/', '_', $downloadName);
if ($ext && !str_ends_with(strtolower($downloadName), '.' . strtolower($ext))) {
    $downloadName .= '.' . $ext;
}

if ($inline && !$downloadFlag) {
    // Clear any previous output buffers to avoid corrupting the file
    if (ob_get_level()) ob_end_clean();
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($plain));
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
} else {
    // Clear any previous output buffers
    if (ob_get_level()) ob_end_clean();

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($plain));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
}

echo $plain;
exit();

function infer_ext_from_icon($icon) {
    $icon = strtolower($icon);
    switch ($icon) {
        case 'pdf': return 'pdf';
        case 'doc':
        case 'docx': return 'docx';
        case 'xls':
        case 'xlsx': return 'xlsx';
        case 'txt': return 'txt';
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'img': return 'png';
        default: return 'dat';
    }
}

function mime_from_extension($ext) {
    $ext = strtolower($ext ?? '');
    return match ($ext) {
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
        default => 'application/octet-stream',
    };
}
