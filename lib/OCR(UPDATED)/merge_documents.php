<?php
/**
 * Compile Documents — Bundles all document versions + attachments into a ZIP.
 * Each decrypted PDF is named clearly inside the archive.
 * Flutter generates PDF 1.5 with object-stream compression which FPDI free
 * cannot parse, so we use ZipArchive instead.
 */
require_once 'security.php';
require_once 'config.php';

Security::require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$trackingId = isset($_GET['tracking_id']) ? (int)$_GET['tracking_id'] : 0;
if ($trackingId <= 0) {
    http_response_code(400);
    echo 'Invalid tracking_id';
    exit();
}

// DB connect
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
    http_response_code(500);
    echo 'DB connection error';
    exit();
}

/**
 * Decrypt an encrypted file and return the plaintext bytes.
 */
function decryptFile($encPath) {
    if (!is_file($encPath)) return false;
    $blob = @file_get_contents($encPath);
    if ($blob === false) return false;
    if (strlen($blob) < 32) {
        // Too small for ENC1 header; treat as raw
        return $blob;
    }

    $magic = substr($blob, 0, 4);
    if ($magic !== 'ENC1') {
        return $blob;
    }
    $iv = substr($blob, 4, 12);
    $tag = substr($blob, 16, 16);
    $ciphertext = substr($blob, 32);

    $key = defined('FILE_ENC_KEY') ? FILE_ENC_KEY : '';
    $keyBin = ctype_xdigit($key) ? hex2bin($key) : (strlen($key) === 32 ? $key : hash('sha256', (string)$key, true));
    $plain = @openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
    return ($plain !== false) ? $plain : false;
}

/**
 * Resolve a stored file_path to an absolute disk path.
 */
function resolveFilePath($storedPath) {
    if (empty($storedPath)) return '';
    $p = $storedPath;
    if (strpos($p, 'lib/OCR(UPDATED)/') === 0) {
        $p = substr($p, strlen('lib/OCR(UPDATED)/'));
    }
    return __DIR__ . '/' . ltrim($p, '/');
}

/**
 * Sanitize a string for use in a filename.
 */
function safeFilename($str) {
    $str = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $str);
    return trim(preg_replace('/_{2,}/', '_', $str), '_ ');
}

// Fetch document info (type, name) for the download filename
$docType = '';
$docName = '';
$currentPath = '';

// Check which columns exist in tracking
$trackingCols = [];
$colRes = $connection->query("SHOW COLUMNS FROM tracking");
if ($colRes) {
    while ($c = $colRes->fetch_assoc()) {
        $trackingCols[] = strtolower($c['Field']);
    }
    $colRes->free();
}

$selectParts = ['file_path'];
if (in_array('type', $trackingCols)) $selectParts[] = 'type';
if (in_array('document_name', $trackingCols)) $selectParts[] = 'document_name';
if (in_array('doc_name', $trackingCols)) $selectParts[] = 'doc_name';

$stmt = $connection->prepare("SELECT " . implode(',', $selectParts) . " FROM tracking WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $currentPath = trim((string)($row['file_path'] ?? ''));
        $docType = trim((string)($row['type'] ?? ''));
        $docName = trim((string)($row['document_name'] ?? $row['doc_name'] ?? ''));
    }
    if ($res) $res->free();
    $stmt->close();
}

// Collect all sources: ['label' => ..., 'path' => absolute_disk_path]
$pdfSources = [];

// 1. Versions from document_versions
$stmt = $connection->prepare("SELECT id, version_number, file_path, version_type FROM document_versions WHERE tracking_id = ? ORDER BY version_number ASC, id ASC");
if ($stmt) {
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $absPath = resolveFilePath($row['file_path']);
        if ($absPath && is_file($absPath)) {
            $typeLabel = $row['version_type'] === 'original' ? 'Original' : 'Returned';
            $pdfSources[] = [
                'label' => 'v' . $row['version_number'] . '_' . $typeLabel,
                'path' => $absPath,
            ];
        }
    }
    if ($res) $res->free();
    $stmt->close();
}

// 2. Current document (if not already the last version)
$currentAlreadyInVersions = false;
if ($currentPath !== '' && !empty($pdfSources)) {
    $stmtLast = $connection->prepare("SELECT file_path FROM document_versions WHERE tracking_id = ? ORDER BY version_number DESC, id DESC LIMIT 1");
    if ($stmtLast) {
        $stmtLast->bind_param('i', $trackingId);
        $stmtLast->execute();
        $rLast = $stmtLast->get_result();
        if ($rLast && ($rowLast = $rLast->fetch_assoc())) {
            if (trim((string)($rowLast['file_path'] ?? '')) === $currentPath) {
                $currentAlreadyInVersions = true;
            }
        }
        if ($rLast) $rLast->free();
        $stmtLast->close();
    }
}
if (!$currentAlreadyInVersions && $currentPath !== '') {
    $absPath = resolveFilePath($currentPath);
    if ($absPath && is_file($absPath)) {
        $pdfSources[] = [
            'label' => 'Current_Document',
            'path' => $absPath,
        ];
    }
}

// 3. Attachments
$attIdx = 0;
$stmt = $connection->prepare("SELECT id, file_path, file_name FROM document_attachments WHERE tracking_id = ? ORDER BY created_at ASC, id ASC");
if ($stmt) {
    $stmt->bind_param('i', $trackingId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $fp = trim((string)($row['file_path'] ?? ''));
        if ($fp === '') continue;

        $absPath = __DIR__ . '/' . ltrim($fp, '/');
        if (!is_file($absPath)) {
            $absPath = __DIR__ . '/../' . ltrim($fp, '/');
        }
        if (is_file($absPath)) {
            $attIdx++;
            $fn = trim((string)($row['file_name'] ?? ''));
            $label = $fn ? safeFilename(pathinfo($fn, PATHINFO_FILENAME)) : ('Attachment_' . $attIdx);
            $pdfSources[] = [
                'label' => 'Attachment_' . $attIdx . '_' . $label,
                'path' => $absPath,
            ];
        }
    }
    if ($res) $res->free();
    $stmt->close();
}

$connection->close();

if (empty($pdfSources)) {
    http_response_code(404);
    echo 'No documents found to compile for tracking ID ' . $trackingId;
    exit();
}

// Build ZIP
$zipPath = tempnam(sys_get_temp_dir(), 'compile_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP archive';
    exit();
}

$addedCount = 0;
foreach ($pdfSources as $idx => $src) {
    $plain = decryptFile($src['path']);
    if ($plain === false || strlen($plain) === 0) continue;

    // Determine file extension from content
    $ext = '.pdf';
    if (substr($plain, 0, 5) !== '%PDF-') {
        // Might be an image or other file type
        $sig2 = substr($plain, 0, 2);
        $sig3 = substr($plain, 0, 3);
        if ($sig2 === "\xff\xd8") $ext = '.jpg';
        else if ($sig3 === 'PNG' || substr($plain, 1, 3) === 'PNG') $ext = '.png';
        else if ($sig3 === 'GIF') $ext = '.gif';
        else $ext = '.bin';
    }

    $entryName = sprintf('%02d_%s%s', $idx + 1, safeFilename($src['label']), $ext);
    $zip->addFromString($entryName, $plain);
    $addedCount++;
}

$zip->close();

if ($addedCount === 0) {
    @unlink($zipPath);
    http_response_code(404);
    echo 'No valid documents found to compile.';
    exit();
}

// Build download filename: Compiled_[Type]_[DateTime].zip
$typeSlug = $docType !== '' ? safeFilename($docType) : 'Document';
$downloadName = 'Compiled_' . $typeSlug . '_' . date('Y-m-d_h-iA') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);
@unlink($zipPath);
exit();
