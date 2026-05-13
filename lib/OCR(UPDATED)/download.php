<?php
require_once 'security.php';
require_once 'config.php';

Security::require_login();

// Validate ID
$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$requestedPathRaw = isset($_GET['path']) ? trim((string)$_GET['path']) : '';
$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
function renderFileUnavailablePage($storedPath = '', $debugData = null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $safePath = htmlspecialchars((string)$storedPath, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Document unavailable</title>';
    echo '<style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0b1220;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;box-sizing:border-box}.card{max-width:720px;background:#111c33;border:1px solid rgba(148,163,184,.25);border-radius:18px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25)}h1{font-size:22px;margin:0 0 10px;color:#fff}p{line-height:1.55;color:#cbd5e1}.path{margin-top:14px;padding:12px;border-radius:12px;background:#0b1220;border:1px solid rgba(251,146,60,.35);color:#fed7aa;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px}.hint{margin-top:16px;color:#94a3b8;font-size:14px}</style>';
    echo '</head><body><div class="card">';
    echo '<h1>Document file is not available on the server</h1>';
    echo '<p>This record points to a local desktop/mobile path or a missing uploaded file. Other users can only preview files that were uploaded to server storage.</p>';
    if ($safePath !== '') {
        echo '<div class="path">' . $safePath . '</div>';
    }
    echo '<div class="hint">Fix: re-upload or complete the document so it is saved under the server uploads folder, then open the document again.</div>';
    if (is_array($debugData)) {
        echo '<pre class="path">' . htmlspecialchars(json_encode($debugData, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</div></body></html>';
    exit();
}

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

if (!$row) {
    http_response_code(404);
    echo 'File not found';
    exit();
}

// Prefer explicit path when provided (used for attachments/versions).
// This avoids failures when tracking.file_path contains a mobile-local cache path.
$storedPath = !empty($requestedPathRaw) ? $requestedPathRaw : (string)($row['file_path'] ?? '');
// Some callers may double-encode the path (e.g. uploads%252Fattachments...).
// Decode up to 2 times so we can resolve actual filesystem paths.
for ($i = 0; $i < 2; $i++) {
    $decoded = rawurldecode((string)$storedPath);
    if ($decoded === $storedPath) {
        break;
    }
    $storedPath = $decoded;
}
// If caller provided an explicit path, we require it to be non-empty.
// If not provided, the main document may still be resolvable via ID-based scanning.
if ($storedPath === '') {
    if (!empty($requestedPathRaw)) {
        http_response_code(404);
        echo 'File not found';
        exit();
    }
}

// Basic path sanitization (defense-in-depth):
// - Disallow traversal
// - Disallow Windows drive paths
// - Disallow Android local cache paths
// If the invalid path comes from the DB (no explicit ?path=), do NOT hard-fail.
// Instead, clear it and allow Strategy 6 (ID-based scan) to locate the file.
$storedPath = str_replace('\\', '/', $storedPath);
$isInvalidPath = (
    strpos($storedPath, '..') !== false ||
    preg_match('#^[A-Za-z]:/#', $storedPath) ||
    stripos($storedPath, '/data/') === 0 ||
    stripos($storedPath, 'data/user/') === 0
);
if ($isInvalidPath) {
    if (!empty($requestedPathRaw)) {
        renderFileUnavailablePage($requestedPathRaw, $debug ? [
            'normalized_path' => $storedPath,
            'attempts' => [],
            'dir' => __DIR__,
        ] : null);
    }
    // DB path is invalid (e.g. Android cache). Try to fall back to latest attachment.
    $storedPath = '';
    try {
        $connection2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$connection2->connect_error) {
            if ($stmt2 = $connection2->prepare("SELECT file_path FROM document_attachments WHERE tracking_id = ? OR parent_tracking_id = ? ORDER BY created_at DESC, id DESC LIMIT 1")) {
                $idNum = (int)$id;
                $stmt2->bind_param('ii', $idNum, $idNum);
                if ($stmt2->execute()) {
                    $res2 = $stmt2->get_result();
                    if ($res2) {
                        $row2 = $res2->fetch_assoc();
                        if (!empty($row2['file_path'])) {
                            $storedPath = (string)$row2['file_path'];
                        }
                        $res2->free();
                    }
                }
                $stmt2->close();
            }
            $connection2->close();
        }
    } catch (Throwable $e) {
    }
}

$storedPath = str_replace('\\', '/', (string)$storedPath);
$prefixesToStrip = [
    'lib/OCR(UPDATED)/',
    'lib/OCR%28UPDATED%29/',
    'lib/uploads/',
];
foreach ($prefixesToStrip as $pfx) {
    $pos = strpos($storedPath, $pfx);
    if ($pos !== false) {
        $storedPath = substr($storedPath, $pos + strlen($pfx));
        break;
    }
}

if (preg_match('#[A-Za-z]:/#', $storedPath)) {
    $markers = ['lib/OCR(UPDATED)/', 'OCR(UPDATED)/', 'lib/uploads/', 'uploads/'];
    foreach ($markers as $m) {
        $mPos = strpos($storedPath, $m);
        if ($mPos !== false) {
            $storedPath = substr($storedPath, $mPos + strlen($m));
            break;
        }
    }
    if (preg_match('#[A-Za-z]:/#', $storedPath)) {
        $storedPath = basename($storedPath);
    }
}

$storedPath = ltrim($storedPath, '/');

$attempts = [];
$encPath = '';

$roots = [];
$roots[] = __DIR__;
$roots[] = __DIR__ . '/..';
$roots[] = dirname(dirname(__DIR__));
$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($docRoot !== '') {
    $roots[] = rtrim($docRoot, '/');
    // Common deployment: files are stored under a sibling folder like /flutter_application_7/
    // even when this PHP app lives under /CHRMO-TRACKING-main/.
    $roots[] = rtrim($docRoot, '/') . '/flutter_application_7';
    $roots[] = rtrim($docRoot, '/') . '/flutter_application_7/lib/OCR(UPDATED)';
}

foreach ($roots as $root) {
    $candidate = rtrim($root, '/\\') . '/' . $storedPath;
    $attempts[] = $candidate;
    if ($storedPath !== '' && is_file($candidate)) {
        $encPath = $candidate;
        break;
    }
}

if ($encPath === '' && $storedPath !== '') {
    $base = basename($storedPath);
    $scanDirs = [
        __DIR__ . '/uploads/',
        __DIR__ . '/uploads/final/',
        __DIR__ . '/uploads/returned/',
        __DIR__ . '/uploads/archive/',
        __DIR__ . '/uploads/batch/',
        __DIR__ . '/../uploads/',
        __DIR__ . '/../uploads/final/',
        __DIR__ . '/../uploads/returned/',
        __DIR__ . '/../uploads/archive/',
        __DIR__ . '/../uploads/batch/',
        __DIR__ . '/../uploads/attachments/' . $id . '/',
    ];
    foreach ($scanDirs as $dir) {
        $candidate = $dir . $base;
        $attempts[] = $candidate;
        if (is_file($candidate)) {
            $encPath = $candidate;
            break;
        }
        $candidateEnc = $candidate . '.enc';
        $attempts[] = $candidateEnc;
        if (is_file($candidateEnc)) {
            $encPath = $candidateEnc;
            break;
        }
    }
}

// Strategy 5: wildcard scan for attachments under lib/uploads/attachments/*/
// Some deployments store attachments under a parent folder that doesn't match tracking.id
if ($encPath === '' && $storedPath !== '') {
    $base = basename($storedPath);
    $attachmentsRoot = realpath(__DIR__ . '/../uploads/attachments');
    if ($attachmentsRoot && is_dir($attachmentsRoot)) {
        $pattern1 = rtrim($attachmentsRoot, '/\\') . '/*/' . $base;
        foreach (glob($pattern1) ?: [] as $match) {
            $attempts[] = $match;
            if (is_file($match)) {
                $encPath = $match;
                break;
            }
        }
        if ($encPath === '') {
            $pattern2 = rtrim($attachmentsRoot, '/\\') . '/*/' . $base . '.enc';
            foreach (glob($pattern2) ?: [] as $match) {
                $attempts[] = $match;
                if (is_file($match)) {
                    $encPath = $match;
                    break;
                }
            }
        }
    }
}

// Strategy 6: glob search by tracking id inside known upload directories.
// If tracking.file_path was updated but the actual filename differs, we can still locate the latest copy.
if ($encPath === '' && (int)$id > 0) {
    $idNum = (int)$id;
    $candidateDirs = [
        __DIR__ . '/uploads/final/',
        __DIR__ . '/uploads/returned/',
        __DIR__ . '/uploads/archive/',
        __DIR__ . '/uploads/batch/',
        __DIR__ . '/../uploads/final/',
        __DIR__ . '/../uploads/returned/',
        __DIR__ . '/../uploads/archive/',
        __DIR__ . '/../uploads/batch/',
        __DIR__ . '/uploads/attachments/' . $idNum . '/',
        __DIR__ . '/../uploads/attachments/' . $idNum . '/',
    ];
    $patterns = [
        'final_' . $idNum . '_*.enc',
        $idNum . '_*.enc',
        '*' . $idNum . '_*.enc',
        'final_' . $idNum . '_*',
        $idNum . '_*',
        '*' . $idNum . '_*',
    ];
    foreach ($candidateDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach ($patterns as $pat) {
            $globPat = rtrim($dir, '/\\') . '/' . $pat;
            foreach (glob($globPat) ?: [] as $match) {
                $attempts[] = $match;
                if (is_file($match)) {
                    $encPath = $match;
                    break 3;
                }
            }
        }
    }
}

// Strategy 7: If we still couldn't find a file, pick the most recent file from attachments/<id>/.
// This is important when the DB file_path is a mobile cache path and the real server file is only stored as an attachment.
if ($encPath === '' && (int)$id > 0) {
    $idNum = (int)$id;
    $attDirs = [
        __DIR__ . '/uploads/attachments/' . $idNum . '/',
        __DIR__ . '/../uploads/attachments/' . $idNum . '/',
    ];
    foreach ($attDirs as $dir) {
        if (!is_dir($dir)) continue;
        $files = [];
        foreach (glob(rtrim($dir, '/\\') . '/*') ?: [] as $f) {
            $attempts[] = $f;
            if (is_file($f)) {
                $files[] = $f;
            }
        }
        if (!empty($files)) {
            usort($files, function($a, $b) {
                return (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0);
            });
            if (is_file($files[0])) {
                $encPath = $files[0];
                break;
            }
        }
    }
}

if ($encPath === '' || !is_file($encPath)) {
    renderFileUnavailablePage(!empty($requestedPathRaw) ? $requestedPathRaw : (string)($row['file_path'] ?? ''), $debug ? [
        'normalized_path' => $storedPath,
        'attempts' => $attempts,
        'dir' => __DIR__,
    ] : null);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    http_response_code(200);
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
if (in_array($ext, ['jpg','jpeg','png','gif','bmp'], true)) {
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
