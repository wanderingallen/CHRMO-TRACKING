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

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$trackingId = isset($_GET['tracking_id']) ? (int)$_GET['tracking_id'] : 0;
if ($trackingId <= 0) {
    http_response_code(400);
    echo 'Invalid tracking_id';
    exit();
}

$viewMode = isset($_GET['view']) && (string)$_GET['view'] === '1';

// DB connect
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
    http_response_code(500);
    echo 'DB connection error';
    exit();
}

// Normalize incoming tracking_id to the root/main document row.
// This allows callers to pass any page row id while still viewing the full multi-page set.
try {
    $hasParentTrackingId = false;
    $hasBatchId = false;
    $hasPageNumber = false;

    if ($colRes = $connection->query("SHOW COLUMNS FROM tracking")) {
        while ($c = $colRes->fetch_assoc()) {
            $f = strtolower((string)($c['Field'] ?? ''));
            if ($f === 'parent_tracking_id') $hasParentTrackingId = true;
            if ($f === 'batch_id') $hasBatchId = true;
            if ($f === 'page_number') $hasPageNumber = true;
        }
        $colRes->free();
    }

    // 1) Prefer explicit parent pointer when available.
    if ($hasParentTrackingId) {
        if ($stmtRoot = $connection->prepare("SELECT parent_tracking_id FROM tracking WHERE id = ? LIMIT 1")) {
            $stmtRoot->bind_param('i', $trackingId);
            if ($stmtRoot->execute()) {
                $resRoot = $stmtRoot->get_result();
                if ($resRoot && ($rowRoot = $resRoot->fetch_assoc())) {
                    $parentId = (int)($rowRoot['parent_tracking_id'] ?? 0);
                    if ($parentId > 0) {
                        $trackingId = $parentId;
                    }
                }
                if ($resRoot) $resRoot->free();
            }
            $stmtRoot->close();
        }
    }

    // 2) Fallback for schemas without parent links: use batch/page metadata.
    if ($hasBatchId) {
        $batchId = '';
        $pageNo = 1;
        $sel = "SELECT batch_id";
        if ($hasPageNumber) $sel .= ", page_number";
        $sel .= " FROM tracking WHERE id = ? LIMIT 1";

        if ($stmtMeta = $connection->prepare($sel)) {
            $stmtMeta->bind_param('i', $trackingId);
            if ($stmtMeta->execute()) {
                $resMeta = $stmtMeta->get_result();
                if ($resMeta && ($rowMeta = $resMeta->fetch_assoc())) {
                    $batchId = trim((string)($rowMeta['batch_id'] ?? ''));
                    if ($hasPageNumber) {
                        $pageNo = (int)($rowMeta['page_number'] ?? 1);
                    }
                }
                if ($resMeta) $resMeta->free();
            }
            $stmtMeta->close();
        }

        if ($batchId !== '' && $pageNo > 1) {
            $order = $hasPageNumber ? " ORDER BY page_number ASC, id ASC" : " ORDER BY id ASC";
            $where = "batch_id = ?";
            if ($hasParentTrackingId) {
                $where .= " AND (parent_tracking_id IS NULL OR parent_tracking_id = 0)";
            }
            $stmtMain = $connection->prepare("SELECT id FROM tracking WHERE " . $where . $order . " LIMIT 1");
            if ($stmtMain) {
                $stmtMain->bind_param('s', $batchId);
                if ($stmtMain->execute()) {
                    $resMain = $stmtMain->get_result();
                    if ($resMain && ($rowMain = $resMain->fetch_assoc())) {
                        $mainId = (int)($rowMain['id'] ?? 0);
                        if ($mainId > 0) {
                            $trackingId = $mainId;
                        }
                    }
                    if ($resMain) $resMain->free();
                }
                $stmtMain->close();
            }
        }
    }
} catch (Throwable $_) {
    // Keep original id on best-effort resolution failure.
}

// If ZipArchive isn't available, fall back to single-file viewing instead of HTTP 500.
if (!$viewMode && !class_exists('ZipArchive')) {
    header('Location: download.php?id=' . urlencode((string)$trackingId) . '&inline=1&t=' . time());
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
function resolveFilePath($storedPath, $trackingId = 0) {
    $p = trim((string)$storedPath);

    // Decode URL encoding (sometimes stored/transported encoded)
    for ($i = 0; $i < 2; $i++) {
        $d = rawurldecode($p);
        if ($d === $p) break;
        $p = $d;
    }

    $p = str_replace('\\', '/', $p);
    $p = ltrim($p, '/');

    // Ignore mobile-local cache paths
    if (stripos($p, 'data/user/') === 0 || stripos($p, '/data/user/') === 0 || stripos($p, '/data/') === 0) {
        return '';
    }

    // If no stored path was provided, try to locate a file by tracking id (fallback scan)
    if ($p === '') {
        $idNum = (int)$trackingId;
        if ($idNum <= 0) {
            return '';
        }
        $candidateDirs = [
            __DIR__ . '/uploads/final/',
            __DIR__ . '/uploads/returned/',
            __DIR__ . '/uploads/archive/',
            __DIR__ . '/uploads/batch/',
            __DIR__ . '/../uploads/final/',
            __DIR__ . '/../uploads/returned/',
            __DIR__ . '/../uploads/archive/',
            __DIR__ . '/../uploads/batch/',
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
            if (!is_dir($dir)) continue;
            foreach ($patterns as $pat) {
                $globPat = rtrim($dir, '/\\') . '/' . $pat;
                foreach (glob($globPat) ?: [] as $match) {
                    if (is_file($match)) {
                        return $match;
                    }
                }
            }
        }
        return '';
    }

    // Strip common prefixes
    $prefixesToStrip = [
        'lib/OCR(UPDATED)/',
        'lib/OCR%28UPDATED%29/',
        'lib/uploads/',
    ];
    foreach ($prefixesToStrip as $pfx) {
        $pos = strpos($p, $pfx);
        if ($pos !== false) {
            $p = substr($p, $pos + strlen($pfx));
            break;
        }
    }

    $roots = [];
    $roots[] = __DIR__;
    $roots[] = __DIR__ . '/..';
    $roots[] = dirname(dirname(__DIR__));
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($docRoot !== '') {
        $roots[] = rtrim($docRoot, '/');
        $roots[] = rtrim($docRoot, '/') . '/flutter_application_7';
        $roots[] = rtrim($docRoot, '/') . '/flutter_application_7/lib/OCR(UPDATED)';
    }

    // Try direct resolution against roots
    foreach ($roots as $root) {
        $candidate = rtrim($root, '/\\') . '/' . $p;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    // Try scanning common upload directories by basename
    $base = basename($p);
    if ($base !== '') {
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
        ];
        if ((int)$trackingId > 0) {
            $scanDirs[] = __DIR__ . '/../uploads/attachments/' . (int)$trackingId . '/';
        }
        foreach ($scanDirs as $dir) {
            $c1 = rtrim($dir, '/\\') . '/' . $base;
            if (is_file($c1)) return $c1;
            $c2 = $c1 . '.enc';
            if (is_file($c2)) return $c2;
        }
    }

    // Fallback: glob search by tracking id in known directories
    $idNum = (int)$trackingId;
    if ($idNum > 0) {
        $candidateDirs = [
            __DIR__ . '/uploads/final/',
            __DIR__ . '/uploads/returned/',
            __DIR__ . '/uploads/archive/',
            __DIR__ . '/uploads/batch/',
            __DIR__ . '/../uploads/final/',
            __DIR__ . '/../uploads/returned/',
            __DIR__ . '/../uploads/archive/',
            __DIR__ . '/../uploads/batch/',
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
            if (!is_dir($dir)) continue;
            foreach ($patterns as $pat) {
                $globPat = rtrim($dir, '/\\') . '/' . $pat;
                foreach (glob($globPat) ?: [] as $match) {
                    if (is_file($match)) {
                        return $match;
                    }
                }
            }
        }
    }

    return '';
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

if ($viewMode) {
    $sources = [];
    $sourceSeen = [];
    $appendSource = function($label, $url) use (&$sources, &$sourceSeen) {
        $u = trim((string)$url);
        if ($u === '') return;
        if (isset($sourceSeen[$u])) return;
        $sourceSeen[$u] = true;
        $sources[] = [
            'label' => (string)$label,
            'url' => $u,
        ];
    };

    // Multi-page support: some uploads store additional pages as child tracking rows.
    // Detect schema and fetch child pages ordered by page_number.
    $hasParentTrackingId = false;
    $hasBatchId = false;
    $hasPageNumber = false;
    try {
        if ($colRes2 = $connection->query("SHOW COLUMNS FROM tracking")) {
            while ($c2 = $colRes2->fetch_assoc()) {
                $f = strtolower((string)($c2['Field'] ?? ''));
                if ($f === 'parent_tracking_id') $hasParentTrackingId = true;
                if ($f === 'batch_id') $hasBatchId = true;
                if ($f === 'page_number') $hasPageNumber = true;
            }
            $colRes2->free();
        }
    } catch (Throwable $_) {}

    $extraPages = [];
    $rootBatchId = '';
    $rootPageNumber = 1;

    // Read root row metadata once.
    $metaSel = "SELECT file_path";
    if ($hasBatchId) $metaSel .= ", batch_id";
    if ($hasPageNumber) $metaSel .= ", page_number";
    $metaSel .= " FROM tracking WHERE id = ? LIMIT 1";
    if ($stmtMeta = $connection->prepare($metaSel)) {
        $stmtMeta->bind_param('i', $trackingId);
        if ($stmtMeta->execute()) {
            $resMeta = $stmtMeta->get_result();
            if ($resMeta && ($metaRow = $resMeta->fetch_assoc())) {
                if ($hasBatchId) {
                    $rootBatchId = trim((string)($metaRow['batch_id'] ?? ''));
                }
                if ($hasPageNumber) {
                    $rootPageNumber = max(1, (int)($metaRow['page_number'] ?? 1));
                }
            }
            if ($resMeta) { $resMeta->free(); }
        }
        $stmtMeta->close();
    }

    // Mode A: pages stored as child rows.
    if ($hasParentTrackingId) {
        $sel = "id, file_path";
        if ($hasPageNumber) $sel .= ", page_number";
        $order = $hasPageNumber ? "ORDER BY page_number ASC, id ASC" : "ORDER BY id ASC";
        $stmtChild = $connection->prepare("SELECT {$sel} FROM tracking WHERE parent_tracking_id = ? {$order}");
        if ($stmtChild) {
            $stmtChild->bind_param('i', $trackingId);
            if ($stmtChild->execute()) {
                $resChild = $stmtChild->get_result();
                while ($resChild && ($r = $resChild->fetch_assoc())) {
                    if (!empty($r['file_path'])) {
                        $extraPages[] = $r;
                    }
                }
                if ($resChild) { $resChild->free(); }
            }
            $stmtChild->close();
        }
    }

    // Mode B: pages stored as sibling tracking rows in the same batch.
    if ($hasBatchId && $rootBatchId !== '') {
        $sel = "id, file_path";
        if ($hasPageNumber) $sel .= ", page_number";
        $order = $hasPageNumber ? "ORDER BY page_number ASC, id ASC" : "ORDER BY id ASC";
        $stmtBatch = $connection->prepare("SELECT {$sel} FROM tracking WHERE batch_id = ? AND id <> ? {$order}");
        if ($stmtBatch) {
            $stmtBatch->bind_param('si', $rootBatchId, $trackingId);
            if ($stmtBatch->execute()) {
                $resBatch = $stmtBatch->get_result();
                while ($resBatch && ($r = $resBatch->fetch_assoc())) {
                    if (!empty($r['file_path'])) {
                        $extraPages[] = $r;
                    }
                }
                if ($resBatch) { $resBatch->free(); }
            }
            $stmtBatch->close();
        }
    }

    // De-duplicate extra pages by file_path and sort by page number when available.
    if (!empty($extraPages)) {
        $byPath = [];
        foreach ($extraPages as $r) {
            $p = trim((string)($r['file_path'] ?? ''));
            if ($p === '' || preg_match('#^/data/user/#', $p)) {
                continue;
            }
            $k = strtolower($p);
            if (!isset($byPath[$k])) {
                $byPath[$k] = $r;
            }
        }
        $extraPages = array_values($byPath);
        usort($extraPages, function($a, $b) use ($hasPageNumber) {
            $ap = $hasPageNumber ? (int)($a['page_number'] ?? 0) : 0;
            $bp = $hasPageNumber ? (int)($b['page_number'] ?? 0) : 0;
            if ($ap !== $bp) return $ap <=> $bp;
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
    }

    // Determine original main document path (tracking.file_path or first version)
    $originalUrl = 'download.php?id=' . urlencode((string)$trackingId) . '&inline=1&t=' . time();
    $stmtOrig = $connection->prepare("SELECT file_path FROM tracking WHERE id = ? LIMIT 1");
    if ($stmtOrig) {
        $stmtOrig->bind_param('i', $trackingId);
        $stmtOrig->execute();
        $resOrig = $stmtOrig->get_result();
        if ($resOrig && ($rowOrig = $resOrig->fetch_assoc())) {
            $fp = trim((string)($rowOrig['file_path'] ?? ''));
            if ($fp !== '' && !preg_match('#^/data/user/#', $fp)) {
                // Use explicit path if it's a valid server path
                $originalUrl = 'download.php?id=' . urlencode((string)$trackingId) . '&inline=1&path=' . urlencode($fp) . '&t=' . time();
            }
        }
        if ($resOrig) $resOrig->free();
        $stmtOrig->close();
    }

    // Always include the main/root document first.
    $appendSource(!empty($extraPages) ? ('Page ' . $rootPageNumber) : 'Current Document', $originalUrl);

    // Add additional pages from child rows and/or batch siblings.
    foreach ($extraPages as $idx => $cp) {
        $fp = trim((string)($cp['file_path'] ?? ''));
        if ($fp === '') continue;
        $pnum = $hasPageNumber ? (int)($cp['page_number'] ?? 0) : 0;
        if ($pnum <= 0) {
            $pnum = $rootPageNumber + $idx + 1;
        }
        $appendSource('Page ' . $pnum, 'download.php?id=' . urlencode((string)$trackingId) . '&inline=1&path=' . urlencode($fp) . '&t=' . time());
    }

    $stmt = $connection->prepare("SELECT id, version_number, file_path, version_type FROM document_versions WHERE tracking_id = ? ORDER BY version_number ASC, id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $trackingId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $fp = trim((string)($row['file_path'] ?? ''));
            if ($fp === '') continue;
            $typeLabel = $row['version_type'] === 'original' ? 'Original' : 'Returned';
            $label = 'v' . (string)$row['version_number'] . ' ' . $typeLabel;
            $appendSource($label, 'download.php?id=' . urlencode((string)$trackingId) . '&inline=1&path=' . urlencode($fp) . '&t=' . time());
        }
        if ($res) $res->free();
        $stmt->close();
    }

    $attCols = [];
    $attHasParent = false;
    try {
        if ($attRes = $connection->query("SHOW COLUMNS FROM document_attachments")) {
            while ($c = $attRes->fetch_assoc()) {
                $attCols[] = strtolower((string)($c['Field'] ?? ''));
            }
            $attRes->free();
        }
    } catch (Throwable $_) {}
    $attHasParent = in_array('parent_tracking_id', $attCols, true);
    $attWhere = $attHasParent ? '(tracking_id = ? OR parent_tracking_id = ?)' : 'tracking_id = ?';

    $stmt = $connection->prepare("SELECT id, file_path, file_name FROM document_attachments WHERE {$attWhere} ORDER BY created_at ASC, id ASC");
    if ($stmt) {
        if ($attHasParent) {
            $stmt->bind_param('ii', $trackingId, $trackingId);
        } else {
            $stmt->bind_param('i', $trackingId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $attIdx = 0;
        while ($res && ($row = $res->fetch_assoc())) {
            $fp = trim((string)($row['file_path'] ?? ''));
            if ($fp === '') continue;
            $attIdx++;
            $fn = trim((string)($row['file_name'] ?? ''));
            $label = $fn !== '' ? $fn : ('Attachment ' . $attIdx);
            $appendSource($label, 'download.php?id=' . urlencode((string)$trackingId) . '&inline=1&path=' . urlencode($fp) . '&t=' . time());
        }
        if ($res) $res->free();
        $stmt->close();
    }

    $connection->close();

    $title = $docType !== '' ? $docType : ('Document #' . $trackingId);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $zipUrl = 'merge_documents.php?tracking_id=' . urlencode((string)$trackingId) . '&t=' . time();

    $firstUrl = '';
    foreach ($sources as $s) { $firstUrl = $s['url']; break; }
    $firstUrlEsc = htmlspecialchars($firstUrl, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $safeTitle . ' — Combined View</title>';
    echo '<style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0b1220;color:#e2e8f0}a{color:inherit} .wrap{display:flex;min-height:100vh} .left{width:320px;max-width:45vw;background:#0f172a;border-right:1px solid rgba(148,163,184,.2);padding:14px;box-sizing:border-box} .right{flex:1;display:flex;flex-direction:column} .hdr{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid rgba(148,163,184,.2);background:#0b1220} .btn{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;border:1px solid rgba(148,163,184,.25);background:#111c33;color:#e2e8f0;text-decoration:none;font-weight:600;font-size:13px} .list{display:flex;flex-direction:column;gap:8px;margin-top:12px} .item{display:block;padding:10px 10px;border-radius:12px;border:1px solid rgba(148,163,184,.2);background:#0b1220;text-decoration:none} .item:hover{border-color:rgba(148,163,184,.45)} .item small{display:block;color:#94a3b8;margin-top:4px} iframe{flex:1;border:0;background:#0b1220}</style>';
    echo '</head><body>';
    echo '<div class="wrap">';
    echo '<div class="left">';
    echo '<div style="font-weight:800;font-size:16px;line-height:1.2">' . $safeTitle . '</div>';
    echo '<div style="color:#94a3b8;font-size:12px;margin-top:6px">Tracking ID: ' . (int)$trackingId . '</div>';
    echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="' . htmlspecialchars($zipUrl, ENT_QUOTES, 'UTF-8') . '">Download ZIP</a>';
    echo '<a class="btn" href="' . $firstUrlEsc . '" target="_blank">Open Current</a>';
    echo '</div>';
    echo '<div class="list">';
    foreach ($sources as $idx => $s) {
        $lbl = htmlspecialchars((string)$s['label'], ENT_QUOTES, 'UTF-8');
        $u = htmlspecialchars((string)$s['url'], ENT_QUOTES, 'UTF-8');
        echo '<a class="item" href="#" data-url="' . $u . '" onclick="return pick(this)">';
        echo $lbl;
        echo '<small>' . htmlspecialchars(parse_url((string)$s['url'], PHP_URL_PATH) ?? '', ENT_QUOTES, 'UTF-8') . '</small>';
        echo '</a>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="right">';
    echo '<div class="hdr"><div style="font-weight:800">Preview</div><div style="color:#94a3b8;font-size:12px">Select a file from the left</div></div>';
    echo '<iframe id="pv" src="' . $firstUrlEsc . '"></iframe>';
    echo '</div>';
    echo '</div>';
    echo '<script>function pick(el){var u=el.getAttribute("data-url");if(u){document.getElementById("pv").src=u;}return false;}</script>';
    echo '</body></html>';
    exit();
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
        $absPath = resolveFilePath($row['file_path'], $trackingId);
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
    $absPath = resolveFilePath($currentPath, $trackingId);
    if ($absPath && is_file($absPath)) {
        $pdfSources[] = [
            'label' => 'Current_Document',
            'path' => $absPath,
        ];
    }
}

// If tracking.file_path was invalid/missing, try to locate current doc by ID scan anyway.
if ($currentPath === '' || empty($pdfSources)) {
    $absPath = resolveFilePath('', $trackingId);
    if ($absPath && is_file($absPath)) {
        $pdfSources[] = [
            'label' => 'Current_Document',
            'path' => $absPath,
        ];
    }
}

// 3. Attachments
$attIdx = 0;

// Attachments table may use tracking_id OR parent_tracking_id depending on deployment.
$attCols = [];
$attHasParent = false;
try {
    if ($attRes = $connection->query("SHOW COLUMNS FROM document_attachments")) {
        while ($c = $attRes->fetch_assoc()) {
            $attCols[] = strtolower((string)($c['Field'] ?? ''));
        }
        $attRes->free();
    }
} catch (Throwable $_) {}
$attHasParent = in_array('parent_tracking_id', $attCols, true);
$attWhere = $attHasParent ? '(tracking_id = ? OR parent_tracking_id = ?)' : 'tracking_id = ?';

$stmt = $connection->prepare("SELECT id, file_path, file_name FROM document_attachments WHERE {$attWhere} ORDER BY created_at ASC, id ASC");
if ($stmt) {
    if ($attHasParent) {
        $stmt->bind_param('ii', $trackingId, $trackingId);
    } else {
        $stmt->bind_param('i', $trackingId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $fp = trim((string)($row['file_path'] ?? ''));
        if ($fp === '') continue;

        $absPath = resolveFilePath($fp, $trackingId);
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
