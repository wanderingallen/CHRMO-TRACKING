<?php
session_start();
require_once 'security.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: log-in.php');
    exit();
}
$__isAdmin = Security::is_admin();
session_write_close(); // Release session lock early

// Buffer output so AJAX JSON responses never get polluted by incidental output.
// This fixes "invalid JSON" on bulk delete when included files emit HTML/warnings.
ob_start();
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

// Include settings/storage helpers early (safe for AJAX).
require_once 'settings_util.php';
require_once 'api/archive_storage.php';
require_once 'api/file_crypto.php';
require_once 'api/ocr_search_helper.php';
require_once 'config.php';

// Create connection
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

// ---------------- Payroll Document Detection Function ----------------
function isPayrollDocument($fileName, $ocrContent = '') {
    // Payroll keywords to check for
    $payrollKeywords = [
        'payroll', 'salary', 'payslip', 'pay slip', 'wage', 'compensation',
        'employee pay', 'staff salary', 'monthly pay', 'net pay', 'gross pay',
        'deduction', 'withholding', 'tax', 'sss', 'philhealth', 'pagibig',
        'employee id', 'pay period', 'pay date', 'income', 'earnings'
    ];
    
    // Check filename (case insensitive)
    $fileNameLower = strtolower($fileName);
    foreach ($payrollKeywords as $keyword) {
        if (strpos($fileNameLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Check OCR content if provided (case insensitive)
    if (!empty($ocrContent)) {
        $ocrContentLower = strtolower($ocrContent);
        foreach ($payrollKeywords as $keyword) {
            if (strpos($ocrContentLower, $keyword) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

function archive_send_json(array $payload, int $statusCode = 200): void {
  if (function_exists('http_response_code')) {
    http_response_code($statusCode);
  }
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload);
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'save_ocr_correction') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    archive_send_json(['success' => false, 'error' => 'Invalid request method'], 405);
  }

  $docId = (int)($_POST['doc_id'] ?? 0);
  $ocrText = (string)($_POST['ocr_text'] ?? '');
  $ocrText = trim($ocrText);

  if ($docId <= 0) {
    archive_send_json(['success' => false, 'error' => 'Invalid doc_id'], 400);
  }

  if ($ocrText === '') {
    archive_send_json(['success' => false, 'error' => 'OCR text is empty'], 400);
  }

  try {
    if (function_exists('ocr_ensure_parent_ocr_columns')) {
      ocr_ensure_parent_ocr_columns($connection);
    }
  } catch (Throwable $t) {
  }

  $summary = '';
  try {
    if (function_exists('ocr_extract_keywords')) {
      $keywords = ocr_extract_keywords($ocrText);
      if (is_array($keywords) && !empty($keywords)) {
        $summary = implode(' ', array_slice($keywords, 0, 50));
      }
    }
  } catch (Throwable $t) {
    $summary = '';
  }

  if ($stmt = $connection->prepare("UPDATE archive SET ocr_content = ?, ocr_summary = ? WHERE id = ?")) {
    $stmt->bind_param('ssi', $ocrText, $summary, $docId);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) {
      archive_send_json(['success' => false, 'error' => $err ?: 'Failed to save OCR correction'], 500);
    }
    archive_send_json(['success' => true, 'doc_id' => $docId]);
  }

  archive_send_json(['success' => false, 'error' => $connection->error ?: 'Failed to prepare statement'], 500);
}

if (false && isset($_GET['action']) && $_GET['action'] === 'debug_ocr_search') {
  $q = trim((string)($_GET['q'] ?? ''));
  $limit = (int)($_GET['limit'] ?? 25);
  if ($limit <= 0) $limit = 25;
  if ($limit > 200) $limit = 200;

  if ($q === '') {
    archive_send_json(['success' => false, 'error' => 'Missing q'], 400);
  }

  try {
    if (function_exists('ocr_ensure_pages_table')) {
      ocr_ensure_pages_table($connection);
    }
    if (function_exists('ocr_ensure_parent_ocr_columns')) {
      ocr_ensure_parent_ocr_columns($connection);
    }
  } catch (Throwable $t) {
    // best-effort only
  }

  $terms = preg_split('/\s+/', $q);
  $terms = array_values(array_filter(array_map('trim', $terms), function($t){ return $t !== '' && strlen($t) >= 2; }));
  if (empty($terms)) {
    $terms = [$q];
  }
  if (count($terms) > 6) {
    $terms = array_slice($terms, 0, 6);
  }

  $patterns = [];
  $patterns[] = $q;
  if (count($terms) >= 2) {
    $patterns[] = implode('%', $terms);
  }
  foreach ($terms as $t) {
    $patterns[] = $t;
  }
  $patterns = array_values(array_unique(array_filter(array_map('trim', $patterns), function($p){ return $p !== ''; })));
  if (count($patterns) > 8) {
    $patterns = array_slice($patterns, 0, 8);
  }

  $fieldClause = "(archive.type LIKE ? OR archive.document_name LIKE ? OR archive.department LIKE ? OR archive.status LIKE ? OR archive.ocr_content LIKE ? OR archive.ocr_summary LIKE ? OR EXISTS (SELECT 1 FROM ocr_pages op WHERE op.scope='archive' AND op.doc_id = archive.id AND (op.ocr_text LIKE ? OR op.ocr_keywords LIKE ?)))";
  $orClauses = [];
  $bindTypes = '';
  $bindParams = [];
  foreach ($patterns as $p) {
    $orClauses[] = $fieldClause;
    $like = '%' . $p . '%';
    $bindTypes .= str_repeat('s', 8);
    for ($i = 0; $i < 8; $i++) {
      $bindParams[] = $like;
    }
  }

  $where = 'WHERE (' . implode(' OR ', $orClauses) . ')';
  $sql = "SELECT archive.id FROM archive $where ORDER BY archive.id DESC LIMIT ?";
  $ids = [];
  $error = null;

  if ($stmt = $connection->prepare($sql)) {
    $types = $bindTypes . 'i';
    $params = $bindParams;
    $params[] = $limit;
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => &$v) {
      $bind[] = &$v;
    }
    @call_user_func_array([$stmt, 'bind_param'], $bind);

    if ($stmt->execute()) {
      $res = $stmt->get_result();
      if ($res) {
        while ($row = $res->fetch_assoc()) {
          $ids[] = (int)$row['id'];
        }
        $res->free();
      }
    } else {
      $error = $stmt->error;
    }
    $stmt->close();
  } else {
    $error = $connection->error;
  }

  archive_send_json([
    'success' => $error === null,
    'q' => $q,
    'terms' => $terms,
    'patterns' => $patterns,
    'limit' => $limit,
    'matched_ids' => $ids,
    'matched_count' => count($ids),
    'sql' => $sql,
    'where' => $where,
    'error' => $error,
  ], $error === null ? 200 : 500);
}

$fatalUploadError = null;

function archive_parse_size_to_bytes($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $bytes = (float)$value;
    switch ($unit) {
        case 'g':
            $bytes *= 1024;
            // no break
        case 'm':
            $bytes *= 1024;
            // no break
        case 'k':
            $bytes *= 1024;
            break;
    }
    return (int)$bytes;
}

function archive_human_bytes($bytes) {
  $bytes = (int)$bytes;
  if ($bytes <= 0) return '0 B';
  if ($bytes < 1024) return $bytes . ' B';
  if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 2) . ' KB';
  if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 2) . ' MB';
  return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

function archive_size_to_bytes_flexible($value, $filePath = null) {
  // Accept:
  // - numeric bytes "1391077"
  // - human strings "13.56MB", "636.0KB", "123 B"
  // - empty -> fall back to filesystem if path exists
  $s = trim((string)$value);
  if ($s !== '' && preg_match('/^\d+$/', $s)) {
    return (int)$s;
  }
  // Normalize "13.5MB" -> "13.5 MB"
  $sNorm = preg_replace('/\s+/', '', $s);
  if ($sNorm !== '' && preg_match('/^(\d+(?:\.\d+)?)([KMG]?B)$/i', $sNorm, $m)) {
    $val = (float)$m[1];
    $unit = strtoupper($m[2]);
    switch ($unit) {
      case 'GB': return (int)round($val * 1024 * 1024 * 1024);
      case 'MB': return (int)round($val * 1024 * 1024);
      case 'KB': return (int)round($val * 1024);
      case 'B':
      default: return (int)round($val);
    }
  }
  if ($filePath && is_string($filePath) && $filePath !== '' && file_exists($filePath)) {
    $fs = @filesize($filePath);
    if ($fs !== false) return (int)$fs;
  }
  return 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    $postMax = archive_parse_size_to_bytes(ini_get('post_max_size'));
    if ($postMax > 0 && $contentLength > $postMax) {
        http_response_code(413);
        $fatalUploadError = sprintf(
            'Upload rejected: payload was %.1f MB but the server limit is %.1f MB. Please upload a smaller file or ask an administrator to raise the cap.',
            $contentLength / 1048576,
            $postMax / 1048576
        );
    }
}

// Function to load data from JSON file (if still needed, though direct DB access is preferred)
function loadJsonFile($file) {
    if (!file_exists($file)) {
        error_log("Error: JSON file not found at " . $file);
        return [];
    }

    $json = file_get_contents($file);
    if ($json === false) {
        error_log("Error: Could not read file " . $file);
        return [];
    }
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Error decoding JSON from " . $file . ": " . json_last_error_msg());
        return [];
    }
    return $data ?? [];
}

// Handle POST requests for actions (add, edit, delete, restore, bulk delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$fatalUploadError) {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action == 'add' || $action == 'edit') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0; // Document ID for editing, 0 for new
            $name = isset($_POST['name']) ? mysqli_real_escape_string($connection, $_POST['name']) : '';
            $department = mysqli_real_escape_string($connection, $_POST["department"]);
            $type = mysqli_real_escape_string($connection, $_POST["type"]);
            $status = "Archived"; // Documents in archive are always 'Archived'
            $date_archived = date("Y-m-d H:i:s"); // Current date AND time for archiving
            // Determine file info from uploaded file if available
            $size = null;
            $file_type_icon = null;

            $uploadDir = archive_uploads_dir();
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            $hasUpload = isset($_FILES['uploadFile']) && isset($_FILES['uploadFile']['tmp_name']) && is_uploaded_file($_FILES['uploadFile']['tmp_name']);
            $file_ext = '';
            if ($hasUpload) {
                $origName = $_FILES['uploadFile']['name'];
                $file_ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                // If no explicit name provided, derive from original filename (without extension)
                if ($name === '' || $name === null) {
                    $base = pathinfo($origName, PATHINFO_FILENAME);
                    $name = mysqli_real_escape_string($connection, $base);
                }
                $bytes = (int)$_FILES['uploadFile']['size'];
                if ($bytes < 1024) {
                    $size = $bytes . 'B';
                } elseif ($bytes < 1024 * 1024) {
                    $size = number_format($bytes / 1024, 1) . 'KB';
                } else {
                    $size = number_format($bytes / (1024 * 1024), 1) . 'MB';
                }
                // Map extension to icon
                if (in_array($file_ext, ['pdf'])) $file_type_icon = 'pdf';
                elseif (in_array($file_ext, ['doc','docx'])) $file_type_icon = 'doc';
                elseif (in_array($file_ext, ['xls','xlsx'])) $file_type_icon = 'xls';
                elseif (in_array($file_ext, ['png','jpg','jpeg'])) $file_type_icon = $file_ext;
                elseif (in_array($file_ext, ['txt'])) $file_type_icon = 'txt';
                else $file_type_icon = 'file';
            } else {
                // Fallback to posted values if no file uploaded
                $size = mysqli_real_escape_string($connection, $_POST["size"] ?? '');
                $file_type_icon = mysqli_real_escape_string($connection, $_POST["file_type_icon"] ?? 'file');
            }

            if ($id > 0) {
                // Update existing document
                $sql = "UPDATE archive SET document_name=?, department=?, type=?, status=?, date_archived=?, size=?, file_type_icon=? WHERE id=?";
                $stmt = $connection->prepare($sql);
                $stmt->bind_param("sssssssi", $name, $department, $type, $status, $date_archived, $size, $file_type_icon, $id);
            } else {
                // Add new document
                $sql = "INSERT INTO archive (document_name, department, type, status, date_archived, size, file_type_icon) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $connection->prepare($sql);
                $stmt->bind_param("sssssss", $name, $department, $type, $status, $date_archived, $size, $file_type_icon);
            }

            if ($stmt->execute()) {
                // If file uploaded, move to uploads/archive/<id>.<ext>
                $newId = $id > 0 ? $id : $connection->insert_id;
                if ($hasUpload && $file_ext) {
                    $baseName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($_FILES['uploadFile']['name'], PATHINFO_BASENAME));
                    if ($baseName === '' || $baseName === null) {
                        $baseName = 'document.' . $file_ext;
                    }
                    
                    // Check if this is a payroll document - skip encryption for payroll documents
                    $isPayrollDocument = isPayrollDocument($_FILES['uploadFile']['name'], '');
                    
                    if ($isPayrollDocument) {
                        // For payroll documents, store without encryption
                        $targetName = $newId . '_' . $baseName;
                        $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/' . $targetName;
                        archive_delete_existing_files($newId, $target);
                        if (!move_uploaded_file($_FILES['uploadFile']['tmp_name'], $target)) {
                            if ($id === 0) {
                                $connection->query("DELETE FROM archive WHERE id=" . (int)$newId);
                            }
                            error_log('Failed to upload payroll file for ID ' . $newId);
                            header("Location: archive.php?status=error");
                            exit();
                        }
                    } else {
                        // Encrypt non-payroll documents
                        $encName = $newId . '_' . $baseName . '.enc';
                        $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . '/' . $encName;
                        archive_delete_existing_files($newId, $target);
                        if (!file_crypto_encrypt_stream_to_path($_FILES['uploadFile']['tmp_name'], $target)) {
                            if ($id === 0) {
                                $connection->query("DELETE FROM archive WHERE id=" . (int)$newId);
                            }
                            error_log('Failed to encrypt uploaded archive file for ID ' . $newId);
                            header("Location: archive.php?status=error");
                            exit();
                        }
                    }
                }
                header("Location: archive.php?status=" . ($id > 0 ? "updated" : "added"));
                exit();
            } else {
                error_log("Error: " . $stmt->error);
                header("Location: archive.php?status=error");
                exit();
            }
        } elseif ($action == 'restore') {
            $doc_id = (int)$_POST['doc_id'];
            
            // 1. Fetch document from archive
            $sql_fetch = "SELECT document_name, department, type, date_archived, file_type_icon FROM archive WHERE id = ?";
            $stmt_fetch = $connection->prepare($sql_fetch);
            $stmt_fetch->bind_param("i", $doc_id);
            $stmt_fetch->execute();
            $result_fetch = $stmt_fetch->get_result();
            $document_to_restore = $result_fetch->fetch_assoc();
            $stmt_fetch->close();

            if ($document_to_restore) {
                // 2. Insert into tracking table (assuming 'tracking' is the active table)
                // Map archive fields to tracking fields.
                // 'status' is set to 'Pending' for restored documents.
                // 'current_holder' and 'end_location' are placeholders.
                $doc_name = $document_to_restore['document_name'];
                $dept = $document_to_restore['department'];
                $type = $document_to_restore['type'];
                $date_submitted = $document_to_restore['date_archived']; // Use archived date as submitted date
                $file_type_icon = $document_to_restore['file_type_icon'];
                $status_tracking = 'Pending'; // Set to pending or active upon restore
                $current_holder = 'Default Holder'; // Placeholder
                $end_location = 'Default Location'; // Placeholder

                $canonical = strtolower(trim($type.'|'.$doc_name.'|'.$date_submitted.'|'.$dept.'|'.$current_holder.'|'.$end_location));
                $doc_hash = hash('sha256', $canonical);
                $sql_insert_tracking = "INSERT INTO tracking (type, employee_name, date_submitted, current_holder, end_location, status, department, file_type_icon, doc_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert_tracking = $connection->prepare($sql_insert_tracking);
                $stmt_insert_tracking->bind_param("sssssssss", $type, $doc_name, $date_submitted, $current_holder, $end_location, $status_tracking, $dept, $file_type_icon, $doc_hash);

                if ($stmt_insert_tracking->execute()) {
                    // 3. Delete from archive table
                    $sql_delete_archive = "DELETE FROM archive WHERE id = ?";
                    $stmt_delete_archive = $connection->prepare($sql_delete_archive);
                    $stmt_delete_archive->bind_param("i", $doc_id);
                    
                    if ($stmt_delete_archive->execute()) {
                        archive_delete_existing_files($doc_id);
                    archive_send_json(['success' => true, 'message' => 'Document restored successfully!']);
                    } else {
                        error_log("Error deleting from archive after restore: " . $stmt_delete_archive->error);
                    archive_send_json(['success' => false, 'message' => 'Failed to remove from archive after restore.'], 500);
                    }
                    $stmt_delete_archive->close();
                } else {
                    error_log("Error inserting into tracking: " . $stmt_insert_tracking->error);
                  archive_send_json(['success' => false, 'message' => 'Failed to restore document to tracking.'], 500);
                }
                $stmt_insert_tracking->close();
            } else {
                archive_send_json(['success' => false, 'message' => 'Document not found in archive.'], 404);
            }
            exit(); // Stop script execution after AJAX response
        } elseif ($action == 'bulkDelete') {
            $selected_ids = json_decode($_POST['selected_ids'] ?? '[]', true);
            if (is_array($selected_ids)) {
                // Cast all ids to integers to be safe
                $ids = array_values(array_map('intval', $selected_ids));
            } else {
                $ids = [];
            }

            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = "DELETE FROM archive WHERE id IN ($placeholders)";
                $stmt = $connection->prepare($sql);
                if (!$stmt) {
                    error_log('Prepare failed for bulk delete: ' . $connection->error);
                  archive_send_json(['success' => false, 'message' => 'Server error preparing delete.'], 500);
                }
                $types = str_repeat('i', count($ids)); // 'i' for integer
                $stmt->bind_param($types, ...$ids);

                if ($stmt->execute()) {
                    foreach ($ids as $docId) {
                        archive_delete_existing_files($docId);
                    }
                  archive_send_json(['success' => true, 'message' => count($ids) . ' documents deleted successfully!']);
                } else {
                    error_log("Error bulk deleting records: " . $stmt->error);
                  archive_send_json(['success' => false, 'message' => 'Failed to delete selected documents.'], 500);
                }
                $stmt->close();
            } else {
                archive_send_json(['success' => false, 'message' => 'No documents selected for deletion.'], 400);
            }
              exit(); // Stop script execution after AJAX response
        }
    }
}

// Handle GET requests for single document deletion (kept for direct URL access)
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $sql = "DELETE FROM archive WHERE id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $delete_id);

    if ($stmt->execute()) {
        archive_delete_existing_files($delete_id);
        header("Location: archive.php?status=deleted");
        exit();
    } else {
        error_log("Error deleting record: " . $stmt->error);
        header("Location: archive.php?status=delete_error");
        exit();
    }
}

// Fetching document data
$documents = [];
// Some login paths historically stored department under different session keys; normalize here.
$__sessionDept = (string)($_SESSION['user_department'] ?? ($_SESSION['department'] ?? ''));
if (!$__isAdmin && trim($__sessionDept) !== '') {
    $__archDeptEsc = $connection->real_escape_string(trim($__sessionDept));
    $__archDeptUpper = strtoupper($__archDeptEsc);
    // Check if the archived_by_department column exists (added by migration)
    $__hasAbdCol = false;
    $__abdChk = @$connection->query("SHOW COLUMNS FROM archive LIKE 'archived_by_department'");
    if ($__abdChk && $__abdChk->num_rows > 0) { $__hasAbdCol = true; }
    if ($__abdChk) { $__abdChk->free(); }

    if ($__hasAbdCol) {
        // Show documents belonging to the user's department OR archived by the user's department
        $query = "SELECT * FROM `archive` WHERE UPPER(TRIM(department)) = '$__archDeptUpper' OR UPPER(TRIM(archived_by_department)) = '$__archDeptUpper' ORDER BY id DESC";
    } else {
        $query = "SELECT * FROM `archive` WHERE UPPER(TRIM(department)) = '$__archDeptUpper' ORDER BY id DESC";
    }
} else {
    $query = "SELECT * FROM `archive` ORDER BY id DESC"; // Newest first
}
$result = $connection->query($query);

if ($result) {
    $dateFmt = getAppSetting('date_format', 'Y-m-d');
    while ($row = $result->fetch_assoc()) {
        // Format date for display (date only)
        $ts = strtotime($row['date_archived']);
        $row['date'] = $ts ? date($dateFmt, $ts) : $row['date_archived'];
        
        // Format date and time for display
        $row['date_time'] = $ts ? date($dateFmt . ' h:i A', $ts) : $row['date_archived'];

        // Time-only for the info modal
        $row['time_only'] = $ts ? date('h:i A', $ts) : '';

        // Check for file - first try file_path column, then pattern lookup
        $filePath = null;
        $hasStoredPath = !empty($row['file_path']);
        if ($hasStoredPath) {
          $storedPath = (string)$row['file_path'];
          // Normalize common stored formats:
          // - uploads/final/...
          // - lib/OCR(UPDATED)/uploads/final/...
          // - \ separated paths
          $storedPath = str_replace('\\', '/', $storedPath);
          $marker = 'lib/OCR(UPDATED)/';
          $pos = strpos($storedPath, $marker);
          if ($pos !== false) {
            $storedPath = substr($storedPath, $pos + strlen($marker));
          }
          $storedPath = ltrim($storedPath, '/');

          // Try resolving relative to lib/OCR(UPDATED)/
          $fullPath = __DIR__ . '/' . $storedPath;
            if (file_exists($fullPath)) {
                $filePath = $fullPath;
            } elseif (file_exists($storedPath)) {
                $filePath = $storedPath;
            }
        }
        // Fallback to archive pattern lookup
        if (!$filePath) {
            $filePath = archive_find_file_path($row['id']);
        }

        // Normalize size to bytes + display string (fixes "0.00 MB" and raw byte numbers)
        $sizeBytes = archive_size_to_bytes_flexible($row['size'] ?? '', $filePath);
        $row['size_bytes'] = $sizeBytes;
        $row['size'] = archive_human_bytes($sizeBytes);
        
        $fileExt = archive_guess_extension_from_path($filePath);
        $row['file_url'] = $filePath ? ('api/archive_download.php?id=' . $row['id'] . '&inline=1') : null;
        $row['file_ext'] = $fileExt; // may be null

        $documents[] = $row;
    }
    $result->free();
} else {
    error_log("Error fetching documents: " . $connection->error);
}

// Calculate summary statistics
$totalArchived = count($documents);

$totalStorageUsedBytes = 0;
foreach ($documents as $doc) {
  $totalStorageUsedBytes += (int)($doc['size_bytes'] ?? 0);
}
$totalStorageUsed = archive_human_bytes($totalStorageUsedBytes);

// Lightweight debug endpoint
if (false && isset($_GET['action']) && $_GET['action'] === 'debug_storage') {
  archive_send_json([
      'success' => true,
      'total_archived' => $totalArchived,
      'total_storage_bytes' => $totalStorageUsedBytes,
      'total_storage_display' => $totalStorageUsed,
      'sample' => array_slice(array_map(function($d){
        return [
          'id' => $d['id'] ?? null,
          'type' => $d['type'] ?? null,
          'size' => $d['size'] ?? null,
          'size_bytes' => $d['size_bytes'] ?? null,
          'file_path' => $d['file_path'] ?? null,
        ];
      }, $documents), 0, 5),
  ]);
}

// OCR Smart Search endpoint for archive
// Usage: archive.php?action=ocr_search&q=payroll+dave&limit=20
if (isset($_GET['action']) && $_GET['action'] === 'ocr_search') {
    $q = trim($_GET['q'] ?? '');
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    
    if ($q === '') {
        archive_send_json([
            'success' => true,
            'query' => '',
            'results' => [],
            'total' => 0,
        ]);
    }
    
    $results = ocr_smart_search($connection, 'archive', $q, $limit);
    
    // Enrich with snippets
    foreach ($results as &$result) {
        if (!empty($result['matching_pages'])) {
            $pageNum = (int)$result['matching_pages'][0];
            $stmt = $connection->prepare("SELECT ocr_text FROM ocr_pages WHERE scope = 'archive' AND doc_id = ? AND page_number = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $result['id'], $pageNum);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $result['snippet'] = ocr_get_match_snippet($row['ocr_text'], $q);
                }
                $stmt->close();
            }
        }
    }
    
    archive_send_json([
        'success' => true,
        'query' => $q,
        'results' => $results,
        'total' => count($results),
    ]);
}

// Get OCR pages for an archived document
// Usage: archive.php?action=ocr_pages&doc_id=123
if (isset($_GET['action']) && $_GET['action'] === 'ocr_pages') {
    $docId = (int)($_GET['doc_id'] ?? 0);
    if ($docId <= 0) {
        archive_send_json(['success' => false, 'error' => 'Invalid doc_id']);
    }

    $pages = [];
    try {
        if (function_exists('ocr_ensure_pages_table')) {
            ocr_ensure_pages_table($connection);
        }
        if (function_exists('ocr_get_pages')) {
            $pages = ocr_get_pages($connection, 'archive', $docId);
        }
    } catch (Throwable $t) {
        $pages = [];
    }

    // Fallback: legacy single OCR content column (if present)
    if (empty($pages)) {
        try {
            if ($stmt = $connection->prepare("SELECT ocr_content FROM archive WHERE id = ? LIMIT 1")) {
                $stmt->bind_param('i', $docId);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $text = trim((string)($row['ocr_content'] ?? ''));
                        if ($text !== '') {
                            $pages = [[
                                'page_number' => 1,
                                'ocr_text' => $text,
                                'ocr_keywords' => null,
                                'confidence_score' => null,
                            ]];
                        }
                    }
                    if ($res) { $res->free(); }
                }
                $stmt->close();
            }
        } catch (Throwable $t) {
            // best-effort only
        }
    }
    
    archive_send_json([
        'success' => true,
        'doc_id' => $docId,
        'total_pages' => count($pages),
        'pages' => $pages,
    ]);
}

 if (isset($_GET['action']) && $_GET['action'] === 'doc_history') {
    $docId = (int)($_GET['doc_id'] ?? 0);
    if ($docId <= 0) {
        archive_send_json(['success' => false, 'error' => 'Invalid doc_id'], 400);
    }

    $events = [];
    $resolvedDocId = $docId;

    // Best-effort: resolve archive.id to the original tracking id.
    // Primary: archive.source_tracking_id (created by archive_transfer.php)
    // Fallbacks: doc_hash/mobile_timestamp (legacy/optional)
    $tryResolve = function($id) use ($connection) {
        $out = [
            'source_tracking_id' => 0,
            'doc_hash' => '',
            'mobile_timestamp' => '',
            'integrity_hash' => '',
            'file_path' => '',
        ];

        // Some deployments don't have doc_hash/mobile_timestamp/source_tracking_id columns
        // in archive yet. Try progressively simpler selects.
        $sqlCandidates = [
            "SELECT COALESCE(source_tracking_id,0) AS source_tracking_id, COALESCE(doc_hash,'') AS doc_hash, COALESCE(mobile_timestamp,'') AS mobile_timestamp, COALESCE(integrity_hash,'') AS integrity_hash, COALESCE(file_path,'') AS file_path FROM archive WHERE id=? LIMIT 1",
            "SELECT COALESCE(source_tracking_id,0) AS source_tracking_id, '' AS doc_hash, '' AS mobile_timestamp, COALESCE(integrity_hash,'') AS integrity_hash, COALESCE(file_path,'') AS file_path FROM archive WHERE id=? LIMIT 1",
            "SELECT 0 AS source_tracking_id, COALESCE(doc_hash,'') AS doc_hash, COALESCE(mobile_timestamp,'') AS mobile_timestamp, COALESCE(integrity_hash,'') AS integrity_hash, COALESCE(file_path,'') AS file_path FROM archive WHERE id=? LIMIT 1",
            "SELECT 0 AS source_tracking_id, '' AS doc_hash, '' AS mobile_timestamp, COALESCE(integrity_hash,'') AS integrity_hash, COALESCE(file_path,'') AS file_path FROM archive WHERE id=? LIMIT 1",
            "SELECT 0 AS source_tracking_id, '' AS doc_hash, '' AS mobile_timestamp, '' AS integrity_hash, COALESCE(file_path,'') AS file_path FROM archive WHERE id=? LIMIT 1",
            "SELECT 0 AS source_tracking_id, '' AS doc_hash, '' AS mobile_timestamp, '' AS integrity_hash, '' AS file_path FROM archive WHERE id=? LIMIT 1",
        ];

        foreach ($sqlCandidates as $sql) {
            $stmt = null;
            try {
                $stmt = $connection->prepare($sql);
            } catch (Throwable $t) {
                $stmt = null;
            }
            if (!$stmt) {
                continue;
            }

            try {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $out['source_tracking_id'] = (int)($row['source_tracking_id'] ?? 0);
                        $out['doc_hash'] = trim((string)($row['doc_hash'] ?? ''));
                        $out['mobile_timestamp'] = trim((string)($row['mobile_timestamp'] ?? ''));
                        $out['integrity_hash'] = trim((string)($row['integrity_hash'] ?? ''));
                        $out['file_path'] = trim((string)($row['file_path'] ?? ''));
                    }
                    if ($res) { $res->free(); }
                }
            } catch (Throwable $t) {
                // try next candidate
            }
            $stmt->close();
            break;
        }
        return $out;
    };

    $loadEvents = function($did) use ($connection) {
        $rows = [];
        if ($stmt = $connection->prepare("SELECT id, action, from_holder, to_holder, from_status, to_status, notes, created_at FROM document_history WHERE doc_id = ? ORDER BY created_at ASC, id ASC")) {
            $stmt->bind_param('i', $did);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }
        return $rows;
    };

    $loadArchiveEvents = function($aid) use ($connection) {
        $rows = [];
        $aid = (int)$aid;
        if ($aid <= 0) {
            return $rows;
        }
        // archive_history is created by api/archive_transfer.php on-demand.
        // If the table doesn't exist, prepare() will fail and we return an empty list.
        if ($stmt = @$connection->prepare("SELECT id, action, from_holder, to_holder, from_status, to_status, notes, created_at FROM archive_history WHERE archive_id = ? ORDER BY created_at ASC, id ASC")) {
            $stmt->bind_param('i', $aid);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }
        return $rows;
    };

    $events = $loadEvents($resolvedDocId);

    if (empty($events)) {
        $ident = $tryResolve($docId);
        $sourceTrackingId = (int)($ident['source_tracking_id'] ?? 0);
        $dh = $ident['doc_hash'] ?? '';
        $mt = $ident['mobile_timestamp'] ?? '';
        $ih = $ident['integrity_hash'] ?? '';
        $fp = $ident['file_path'] ?? '';

        $trackingId = 0;

        if ($sourceTrackingId > 0) {
            $trackingId = $sourceTrackingId;
        }

        // If source_tracking_id is missing/NULL on older data, try integrity hash linkage.
        if ($trackingId <= 0 && $ih !== '' && $stmt = $connection->prepare("SELECT id FROM tracking WHERE archive_hash = ? ORDER BY id DESC LIMIT 1")) {
            $stmt->bind_param('s', $ih);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trackingId = (int)($row['id'] ?? 0);
                }
                if ($res) { $res->free(); }
            }
            $stmt->close();
        }

        // Fallback: match by file_path if present (best-effort only).
        if ($trackingId <= 0 && $fp !== '' && $stmt = $connection->prepare("SELECT id FROM tracking WHERE file_path = ? ORDER BY id DESC LIMIT 1")) {
            $stmt->bind_param('s', $fp);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trackingId = (int)($row['id'] ?? 0);
                }
                if ($res) { $res->free(); }
            }
            $stmt->close();
        }

        if ($dh !== '' && $stmt = $connection->prepare("SELECT id FROM tracking WHERE doc_hash = ? ORDER BY id DESC LIMIT 1")) {
            $stmt->bind_param('s', $dh);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trackingId = (int)$row['id'];
                }
                if ($res) { $res->free(); }
            }
            $stmt->close();
        }
        if ($trackingId <= 0 && $mt !== '' && $stmt = $connection->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id DESC LIMIT 1")) {
            $stmt->bind_param('s', $mt);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $trackingId = (int)$row['id'];
                }
                if ($res) { $res->free(); }
            }
            $stmt->close();
        }

        if ($trackingId > 0) {
            $resolvedDocId = $trackingId;
            $events = $loadEvents($resolvedDocId);
        }
    }

    // If we can't resolve back to tracking (or tracking history is empty), fall back to
    // archive_history (keyed by archive_id) so the archive audit trail still works.
    if (empty($events)) {
        $events = $loadArchiveEvents($docId);
    }

    // Last resort: if we still have no history, emit a minimal synthetic event
    // so the UI doesn't show an empty audit trail for archived documents.
    if (empty($events)) {
        try {
            if ($stmt = $connection->prepare("SELECT department, type, status, date_archived FROM archive WHERE id = ? LIMIT 1")) {
                $stmt->bind_param('i', $docId);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && ($row = $res->fetch_assoc())) {
                        $when = (string)($row['date_archived'] ?? '');
                        $dept = (string)($row['department'] ?? '');
                        $type = (string)($row['type'] ?? '');
                        $status = (string)($row['status'] ?? 'Archived');
                        $events[] = [
                            'id' => 0,
                            'action' => 'archive',
                            'from_holder' => $dept,
                            'to_holder' => 'Archive',
                            'from_status' => $status,
                            'to_status' => 'Archived',
                            'notes' => $type !== '' ? ('Archived document: ' . $type) : 'Archived document',
                            'created_at' => $when,
                        ];
                    }
                    if ($res) { $res->free(); }
                }
                $stmt->close();
            }
        } catch (Throwable $t) {
        }
    }

    archive_send_json([
        'success' => true,
        'doc_id' => $docId,
        'resolved_doc_id' => $resolvedDocId,
        'events' => $events,
    ]);
}

// Get unique departments for filter dropdown and new archive form
$departments = [
    "CACCO", "CADO", "CBO", "CMO", "CPDO", "CTO", "GSO"
];
sort($departments); // Sort departments alphabetically

// Close connection at the end of the script
$connection->close();

// Include UI-only components AFTER all AJAX endpoints, to keep JSON clean.
require_once 'user_profile_widget.php';

// We intentionally keep output buffering enabled for the HTML page render.
// JSON endpoints call archive_send_json() which clears buffers and exits.
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Archive Storage - CHRMO Document Tracking</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://unpkg.com/mammoth/mammoth.browser.min.js"></script>
  <script src="assets/smooth-interactions.js" defer></script>
  <style>
    :root {
      --primary: #6868AC;
      --primary-light: #e8e8f4;
      --primary-dark: #52528a;
      --secondary: #8585c0;
      --text-dark: #263238;
      --text-light: #78909C;
      --white: #FFFFFF;
      --light-bg: #F5F7FA;
      --border: #E0E0E0;
      --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      --shadow-md: 0 8px 15px rgba(0, 0, 0, 0.1);
      --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.15); /* Added for modals */
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', sans-serif;
    }
    body {
      background-color: var(--light-bg);
      color: var(--text-dark);
    }
    .container {
      display: flex;
      min-height: 100vh;
    }
    .sidebar {
      width: 80px;
      background: linear-gradient(180deg, #2e2e5e 0%, #3d3d7a 50%, #2e2e5e 100%);
      color: var(--white);
      padding: 0;
      box-shadow: 4px 0 24px rgba(0,0,0,0.18);
      position: fixed;
      height: 100vh;
      transition: width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
      z-index: 100;
      overflow: hidden;
      overflow-y: auto;
      transform: translateZ(0);
      backface-visibility: hidden;
      contain: layout style;
      will-change: width;
      display: flex;
      flex-direction: column;
    }
    .sidebar::-webkit-scrollbar { width: 0; }
    .sidebar:hover { width: 260px; }
    .sidebar-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 16px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.10);
      margin-bottom: 8px;
      background: rgba(0,0,0,0.10);
    }
    .sidebar-header img {
      height: 48px;
      width: 48px;
      object-fit: contain;
      margin-bottom: 8px;
      border-radius: 8px;
      background: rgba(255,255,255,0.08);
      padding: 4px;
      transition: height 0.25s ease, width 0.25s ease;
    }
    .sidebar:hover .sidebar-header img { height: 64px; width: 64px; }
    .sidebar:not(:hover) .sidebar-header img { margin-bottom: 0; }
    .sidebar-header h2 {
      font-size: 15px;
      font-weight: 700;
      margin: 0;
      color: var(--white);
      opacity: 0;
      white-space: nowrap;
      transition: opacity 0.2s ease 0.1s;
      letter-spacing: 0.5px;
    }
    .sidebar:hover .sidebar-header h2 { opacity: 1; }
    .sidebar-header .sidebar-subtitle {
      font-size: 11px;
      color: rgba(255,255,255,0.5);
      opacity: 0;
      transition: opacity 0.2s ease 0.15s;
      margin-top: 2px;
      letter-spacing: 0.3px;
    }
    .sidebar:hover .sidebar-header .sidebar-subtitle { opacity: 1; }
    .sidebar:not(:hover) .sidebar-header { justify-content: center; }
    .sidebar:not(:hover) .sidebar-header h2 { display: none; }
    .sidebar:not(:hover) .sidebar-header .sidebar-subtitle { display: none; }
    .sidebar-menu { padding: 0 12px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .sidebar-section-label {
      font-size: 10px; font-weight: 700; letter-spacing: 2.5px; color: rgba(255,255,255,0.35);
      text-transform: uppercase; padding: 16px 14px 6px; opacity: 0;
      transition: opacity 0.2s ease 0.1s; white-space: nowrap;
    }
    .sidebar:hover .sidebar-section-label { opacity: 1; }
    .sidebar:not(:hover) .sidebar-section-label { height: 0; padding: 4px 0; overflow: hidden; }
    .sidebar-section-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 4px 14px 0; }
    .sidebar:not(:hover) .sidebar-section-divider { margin: 2px auto; width: 32px; }
    .sidebar:not(:hover) .menu-item { justify-content: center; }
    .menu-item { display:flex; align-items:center; gap:14px; padding:11px 14px; color:var(--white); text-decoration:none; transition: background-color .18s ease, color .18s ease, box-shadow .18s ease; border-radius:12px; position:relative; }
    .menu-item:hover { background: rgba(255,255,255,0.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
    .menu-item.active {
      background: rgba(255,255,255,0.13); color:#fff;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15), inset 0 0 0 1px rgba(255,255,255,0.08);
      border-left: 3px solid #64b5f6;
    }
    .menu-item.active i, .menu-item.active span { color:#fff; }
    .menu-item i { font-size:20px; width:28px; min-width:28px; text-align:center; color:rgba(255,255,255,0.85); transition: color .18s ease; }
    .menu-item.active i { color: #90caf9; }
    .menu-item:hover i { color: #fff; }
    .menu-item span { font-size:14px; opacity:0; white-space:nowrap; transition: opacity .2s ease; }
    .sidebar:hover .menu-item span { opacity:1; }
    .sidebar:not(:hover) .menu-item { justify-content:center; width:52px; height:52px; padding:0; margin:3px auto; display:grid; place-items:center; overflow: visible; border-left: none; }
    .sidebar:not(:hover) .menu-item.active { border-left: none; border-bottom: 2px solid #64b5f6; }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display:none; }
    .sidebar:not(:hover) .menu-item i { width:24px; height:24px; display:inline-grid; place-items:center; }
    .menu-badge { background:#FF5252; color:#fff; font-size:11px; padding:0 6px; border-radius:999px; margin-left:auto; font-weight:700; min-width:20px; height:20px; line-height:20px; text-align:center; position:absolute; right:12px; top:50%; transform:translateY(-50%); opacity:1; z-index:2; pointer-events:none; display:inline-flex; align-items:center; justify-content:center; }
    .sidebar:not(:hover) .menu-badge { right:4px; top:4px; transform:none; font-size:10px; padding:1px 5px; }
    .menu-badge.success { background-color: #4CAF50; }
    .sidebar-footer {
      padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.08);
      text-align: center; margin-top: auto;
    }
    .sidebar-footer span {
      font-size: 10px; color: rgba(255,255,255,0.3); display: block;
      opacity: 0; transition: opacity 0.2s ease 0.1s; white-space: nowrap;
    }
    .sidebar:hover .sidebar-footer span { opacity: 1; }
    .main-content { flex:1; margin-left:80px; padding:20px; min-width:0; transition: margin-left .28s ease; }
    .sidebar:hover ~ .main-content { margin-left: 260px; }
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      background-color: var(--white);
      padding: 15px 20px;
      border-radius: 10px;
      box-shadow: var(--shadow);
    }
    .search-bar {
      display: flex;
      align-items: center;
      background-color: var(--light-bg);
      border-radius: 20px;
      padding: 5px 15px;
      width: 300px;
      transition: all 0.3s;
    }
    .search-bar:focus-within {
      box-shadow: 0 0 0 2px var(--primary-light);
    }
    .search-bar input {
      border: none;
      background: transparent;
      outline: none;
      padding: 8px;
      width: 100%;
      font-size: 14px;
    }
    
    /* OCR Search Toggle Button */
    .ocr-search-toggle {
      background: transparent;
      border: none;
      color: var(--text-light);
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      transition: all 0.2s ease;
      font-size: 14px;
    }
    .ocr-search-toggle:hover { background: var(--bg-light); color: var(--primary); }
    .ocr-search-toggle.active { background: var(--primary); color: white; }
    
    /* OCR Search Results Dropdown */
    .ocr-search-results {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: white;
      border: 1px solid var(--border);
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
      max-height: 400px;
      overflow-y: auto;
      z-index: 1000;
      margin-top: 4px;
      min-width: 350px;
    }
    .ocr-search-results .ocr-result-item {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background 0.2s;
    }
    .ocr-search-results .ocr-result-item:hover { background: var(--bg-light); }
    .ocr-search-results .ocr-result-item:last-child { border-bottom: none; }
    .ocr-search-results .ocr-result-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
    }
    .ocr-search-results .ocr-result-type {
      font-weight: 600;
      color: var(--text-dark);
    }
    .ocr-search-results .ocr-result-meta {
      font-size: 12px;
      color: var(--text-light);
    }
    .ocr-search-results .ocr-result-snippet {
      font-size: 13px;
      color: var(--text-dark);
      background: #FFFDE7;
      padding: 8px;
      border-radius: 4px;
      margin-top: 6px;
      line-height: 1.4;
    }
    .ocr-search-results .ocr-result-snippet mark {
      background: #FFD54F;
      padding: 0 2px;
      border-radius: 2px;
    }
    .ocr-search-results .ocr-result-pages {
      font-size: 11px;
      color: var(--primary);
      margin-top: 4px;
    }
    .ocr-search-results .ocr-no-results {
      padding: 20px;
      text-align: center;
      color: var(--text-light);
    }
    .ocr-search-results .ocr-searching {
      padding: 20px;
      text-align: center;
      color: var(--text-light);
    }
    
    /* OCR search result row highlight animation */
    @keyframes ocr-row-highlight {
      0%, 100% { background-color: inherit; }
      25%, 75% { background-color: rgba(37, 99, 235, 0.2); }
    }
    .ocr-highlight-row {
      animation: ocr-row-highlight 3s ease;
      box-shadow: 0 0 10px rgba(37, 99, 235, 0.5);
    }
    .ocr-highlight-row td {
      position: relative;
    }
    
    /* Extracted OCR Keys Chips */
    .ocr-key-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 500;
      line-height: 1.3;
      max-width: 260px;
    }
    .ocr-key-chip .ocr-key-icon { font-size: 11px; opacity: 0.8; flex-shrink: 0; }
    .ocr-key-chip .ocr-key-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.6; flex-shrink: 0; }
    .ocr-key-chip .ocr-key-value { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ocr-key-chip.type { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
    .ocr-key-chip.name { background: #f0fdfa; color: #0f766e; border: 1px solid #99f6e4; }
    .ocr-key-chip.date { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .ocr-key-chip.amount { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .ocr-key-chip.dept { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }
    .ocr-key-chip.ref { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

    /* Enhanced Notification Icon and Badge Styling */
    .notification-icon {
      margin-right: 20px;
      position: relative;
      cursor: pointer;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      z-index: 1002;
    }
    .notification-icon i.fa-bell {
        font-size: 1.25rem;
        transition: color 0.3s ease;
    }
    .notification-icon:hover {
      background-color: var(--light-bg);
      transform: scale(1.1);
    }
    .notification-icon:hover i {
      animation: bellRing 0.5s ease-in-out;
    }
    @keyframes bellRing {
      0%, 100% { transform: rotate(0deg); }
      10%, 30%, 50%, 70%, 90% { transform: rotate(-10deg); }
      20%, 40%, 60%, 80% { transform: rotate(10deg); }
    }
    .notification-badge {
      position: absolute;
      top: 0px;
      right: 0px;
      background-color: #FF5252;
      color: white;
      font-size: 10px;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--white);
      pointer-events: none;
      animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.1); opacity: 0.8; }
    }
    .notification-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 8px;
      box-shadow: var(--shadow-lg);
      width: 350px;
      z-index: 1001;
      display: none;
      margin-top: 8px;
      max-height: 400px;
      overflow-y: auto;
      border: 1px solid var(--border);
      opacity: 0;
      transform: translateY(-10px);
      transition: opacity 0.2s ease-out, transform 0.2s ease-out;
    }
    .notification-dropdown.show {
      display: block;
      opacity: 1;
      transform: translateY(0);
    }
    .notification-header {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .notification-header h3 {
      margin: 0;
      font-size: 16px;
    }
    .notification-clear {
      color: var(--primary);
      cursor: pointer;
      font-size: 14px;
    }
    .notification-item {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background-color 0.2s;
    }
    .notification-item:hover {
      background-color: var(--light-bg);
    }
    .notification-item.unread {
      background-color: rgba(0, 188, 212, 0.05);
    }
    .notification-title {
      font-weight: 500;
      margin-bottom: 5px;
      display: flex;
      justify-content: space-between;
    }
    .notification-time {
      color: var(--text-light);
      font-size: 12px;
    }
    .notification-content {
      font-size: 14px;
      color: var(--text-dark);
    }
    .user-profile {
      display: flex;
      align-items: center;
      cursor: pointer;
      position: relative;
      padding: 8px 12px;
      border-radius: 30px;
      transition: all 0.2s;
    }
    .user-profile:hover {
      background-color: var(--light-bg);
    }
    .user-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 10px;
      object-fit: cover;
      border: 2px solid var(--primary-light);
    }
    .dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow-md);
      min-width: 180px;
      z-index: 100;
      display: none;
      margin-top: 10px;
      overflow: hidden;
    }
    .dropdown-menu.show {
      display: block;
    }
    .dropdown-item {
      padding: 10px 15px;
      font-size: 14px;
      cursor: pointer;
      transition: background 0.2s;
      color: var(--text-dark);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .dropdown-item:hover {
      background-color: var(--primary-light);
      color: var(--primary-dark);
    }
    .dropdown-item.selected {
      background-color: var(--primary-light);
      color: var(--primary-dark);
      font-weight: 500;
    }
    /* Stats Cards */
    .stats-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background-color: var(--white);
      border-radius: 10px;
      padding: 20px;
      box-shadow: var(--shadow);
      transition: all 0.3s;
      position: relative;
      overflow: hidden;
    }
    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-md);
    }
    .stat-card h3 {
      color: var(--text-light);
      font-size: 16px;
      margin-bottom: 10px;
      font-weight: 400;
    }
    .stat-card .stat-value {
      font-size: 32px;
      font-weight: bold;
      color: var(--text-dark);
    }
    /* Filter Bar */
    .filter-bar {
      display: flex;
      align-items: center;
      background-color: var(--white);
      border-radius: 10px;
      padding: 15px 20px;
      box-shadow: var(--shadow);
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 15px;
    }
    .filter-group {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .filter-label {
      font-size: 14px;
      color: var(--text-light);
    }
    .filter-select {
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background-color: var(--white);
      font-size: 14px;
      outline: none;
      transition: all 0.3s;
    }
    .filter-select:focus {
      border-color: var(--primary);
    }
    .date-range-input {
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background-color: var(--white);
      font-size: 14px;
      width: 180px;
    }
    /* Table */
    .archive-container {
      background-color: var(--white);
      border-radius: 10px;
      padding: 20px;
      box-shadow: var(--shadow);
      margin-bottom: 30px;
    }
    .archive-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 15px;
    }
    .archive-header h3 {
      font-size: 18px;
      color: var(--text-dark);
      font-weight: 400;
    }
    .archive-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .docs-table {
      width: 100%;
      border-collapse: collapse;
    }
    .docs-table th, .docs-table td {
      padding: 12px 15px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    .docs-table th {
      color: var(--text-light);
      font-weight: 500;
      font-size: 14px;
      position: sticky;
      top: 0;
      background-color: var(--white);
      z-index: 2;
    }
    .docs-table tr:last-child td {
      border-bottom: none;
    }
    .docs-table tr:hover td {
      background-color: var(--light-bg);
    }
    .docs-table tbody tr {
        animation: fadeInRow 0.5s ease-out forwards;
    }
    @keyframes fadeInRow {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .badge {
      display: inline-block;
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 600;
      color: #fff;
      letter-spacing: 0.5px;
    }
    .badge.archived {
      background: #6c757d;
      box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
    }
    .action-btn {
      background-color: var(--primary-light);
      color: var(--primary-dark);
      border: none;
      border-radius: 5px;
      padding: 6px 12px;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .action-btn i { /* Increased icon size */
        font-size: 1.1em;
    }
    .action-btn:hover:not(:disabled) {
      background-color: var(--primary);
      color: var(--white);
      box-shadow: var(--shadow);
      transform: translateY(-2px); /* Lift effect on hover */
    }
    .action-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    .action-btn.secondary {
      background-color: var(--light-bg);
      color: var(--text-dark);
    }
    .action-btn.secondary:hover:not(:disabled) {
      background-color: var(--border);
    }
    .action-btn.danger {
      background-color: #ffebee;
      color: #f44336;
    }
    .action-btn.danger:hover:not(:disabled) {
      background-color: #f44336;
      color: white;
    }
    .action-btn.info {
      background-color: #e0f2f7;
      color: #007c91;
    }
    .action-btn.info:hover:not(:disabled) {
      background-color: #007c91;
      color: white;
    }
    /* File Type Indicators */
    .file-type {
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .file-type-icon {
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      color: var(--white);
      font-size: 1.1em; /* Ensure icon is visible */
    }
    .file-type-icon.pdf {
      background-color: #FF5252;
    }
    .file-type-icon.doc, .file-type-icon.docx {
      background-color: #2196F3;
    }
    .file-type-icon.xls, .file-type-icon.xlsx {
      background-color: #4CAF50;
    }
    .file-type-icon.img, .file-type-icon.jpg, .file-type-icon.png, .file-type-icon.jpeg {
      background-color: #FF9800;
    }
    .file-type-icon.txt {
        background-color: #9E9E9E;
    }
    /* Action Buttons Group Spacing */
    .action-buttons-group {
        display: flex;
        gap: 10px; /* Increased spacing */
    }
    /* Pagination */
    .pagination {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      margin-top: 20px;
      gap: 8px;
    }
    .pagination-btn {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 6px;
      border: 1px solid var(--border);
      background-color: var(--white);
      cursor: pointer;
      transition: all 0.3s;
    }
    .pagination-btn:hover:not(.active):not(:disabled) {
      background-color: var(--primary-light);
      color: var(--primary-dark);
      border-color: var(--primary-light);
      transform: translateY(-2px);
    }
    .pagination-btn.active {
      background-color: var(--primary);
      color: var(--white);
      border-color: var(--primary);
      cursor: default;
    }
    .pagination-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    /* Modals for Preview and Upload */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
      justify-content: center;
      align-items: center;
      opacity: 0; /* For fade-in animation */
      transition: opacity 0.3s ease-out;
    }
    .modal.show {
      display: flex;
      opacity: 1;
    }
    .modal-content {
      background-color: var(--white);
      margin: auto;
      padding: 24px;
      border-radius: 12px;
      box-shadow: var(--shadow);
      width: min(90vw, 760px);
      max-height: 90vh;
      position: relative;
      animation: fadeIn 0.3s ease-out;
      transform: translateY(-20px); /* For slide-down animation */
      transition: transform 0.3s ease-out;
      display: flex;
      flex-direction: column;
    }
    .modal.show .modal-content {
        transform: translateY(0);
    }
    .modal-content.small {
        max-width: 500px;
    }
    .close-button {
      color: var(--text-light);
      font-size: 28px;
      font-weight: bold;
      position: absolute;
      top: 10px;
      right: 15px;
      cursor: pointer;
      transition: color 0.2s;
    }
    .close-button:hover,
    .close-button:focus {
      color: var(--text-dark);
    }
    .modal h3 {
        margin-bottom: 20px;
        color: var(--primary-dark);
        font-size: 24px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 10px;
    }
    .modal-body {
      margin-top: 16px;
      margin-bottom: 16px;
      line-height: 1.5;
      max-height: calc(80vh - 140px);
      overflow-y: auto;
    }
    .modal-body p {
        margin-bottom: 10px;
        line-height: 1.5;
    }
    .modal-body .document-preview-frame {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px 20px;
        color: #475569;
        font-size: 0.9rem;
        margin-bottom: 14px;
    }
    .modal-body .document-preview-frame .file-icon-box {
        width: 52px;
        height: 52px;
        border-radius: 12px;
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .modal-body .document-preview-frame .file-icon-box i {
        font-size: 22px;
        color: #0284c7;
    }
    .modal-body .document-preview-frame .file-info-text {
        flex: 1;
        min-width: 0;
    }
    .modal-body .document-preview-frame .file-info-text .file-title {
        font-weight: 600;
        font-size: 14px;
        color: #0f172a;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .modal-body .document-preview-frame .file-info-text .file-meta {
        font-size: 12px;
        color: #64748b;
        margin-top: 2px;
    }
    .file-preview-container {
        width: 100%;
        min-height: 120px;
        max-height: 420px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #f8fafc;
        overflow: hidden;
        margin-bottom: 14px;
        position: relative;
    }
    .file-preview-container iframe,
    .file-preview-container img {
        width: 100%;
        height: 400px;
        border: none;
        border-radius: 10px;
        object-fit: contain;
    }
    .file-preview-container pre {
        padding: 14px;
        margin: 0;
        max-height: 400px;
        overflow: auto;
        font-size: 12px;
        line-height: 1.5;
    }

    #archiveTimelineActivityLog {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 18px;
        max-height: 55vh;
        overflow-y: auto;
        padding-right: 6px;
        margin-top: 12px;
    }
    .timeline-item-horizontal {
        display: grid;
        grid-template-columns: 140px 1fr 120px;
        gap: 16px;
        position: relative;
        min-height: 80px;
    }
    .timeline-dept-col {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        grid-column: 1;
    }
    .timeline-dept-badge {
        background: rgba(8, 145, 178, 0.10);
        color: #0e7490;
        border: 1px solid rgba(8, 145, 178, 0.25);
        padding: 8px 12px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 12px;
        max-width: 140px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .timeline-details-col {
        padding: 12px 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 12px;
        border: 1px solid var(--border);
        grid-column: 2;
    }
    .timeline-status-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        position: relative;
        grid-column: 3;
    }
    .timeline-dot {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: #10b981;
        border: 4px solid #fff;
        box-shadow: 0 6px 14px rgba(0,0,0,0.12);
    }
    .timeline-dot.review { background: #3b82f6; }
    .timeline-dot.pending { background: #f59e0b; }
    .timeline-dot.returned { background: #f97316; }
    .timeline-status-pill {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.6px;
        padding: 6px 10px;
        border-radius: 999px;
        text-transform: uppercase;
        color: #0f172a;
        background: #e2e8f0;
    }
    .timeline-status-pill.review { background: #dbeafe; color:#1d4ed8; }
    .timeline-status-pill.pending { background: #fef3c7; color:#92400e; }
    .timeline-status-pill.returned { background: #ffedd5; color:#9a3412; }
    .timeline-date-time { font-size: 13px; color: var(--text-dark); font-weight: 600; margin-bottom: 6px; }
    .timeline-description { font-size: 13px; color: var(--text-light); line-height: 1.5; }
    .timeline-notes { margin-top: 10px; margin-bottom: 12px; }
    .timeline-notes details { background: #fef3c7; border-left: 3px solid #f59e0b; border-radius: 6px; padding: 0; overflow: hidden; }
    .timeline-notes summary { list-style: none; cursor: pointer; padding: 10px 12px; font-size: 12px; color: #92400e; display: flex; align-items: center; gap: 8px; user-select: none; }
    .timeline-notes summary::-webkit-details-marker { display: none; }
    .timeline-notes .timeline-notes-body { padding: 10px 12px 12px; font-size: 12px; color: #92400e; }
    .modal-body .audit-log {
        border-top: 1px dashed var(--border);
        padding-top: 15px;
        margin-top: 20px;
    }
    .modal-body .audit-log h4 {
        color: var(--primary);
        margin-bottom: 10px;
    }
    .modal-body .audit-log ul {
        list-style: none;
        padding: 0;
    }
    .modal-body .audit-log li {
        background-color: var(--light-bg);
        padding: 8px 12px;
        border-radius: 6px;
        margin-bottom: 5px;
        font-size: 14px;
        color: var(--text-dark);
    }
    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: var(--text-dark);
    }
    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group input[type="number"],
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 15px;
        color: var(--text-dark);
        background-color: var(--light-bg);
    }
    .form-group input[type="file"] {
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 8px;
        background-color: var(--light-bg);
        width: 100%;
    }

    /* File Select Container */
    .file-select-container {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background-color: var(--white);
    }
    
    .file-select-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background-color: var(--primary);
        color: var(--white);
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    
    .file-select-btn:hover {
        background-color: var(--primary-dark);
    }
    
    .file-select-btn i {
        font-size: 16px;
    }
    
    .selected-file-name {
        font-size: 14px;
        color: var(--text-light);
        flex: 1;
    }
    
    .selected-file-name.has-file {
        color: var(--text-dark);
        font-weight: 500;
    }
    .view-options .action-btn {
        padding: 8px 15px;
    }
    .view-options .action-btn.active-view {
        background-color: var(--primary);
        color: var(--white);
    }

    /* List View */
    .docs-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
        width: 100%;
    }
    .docs-list .list-item {
        background-color: var(--white);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: var(--shadow);
        transition: all 0.3s;
    }
    .docs-list .list-item:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-md);
    }
    .docs-list .list-item .item-details {
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }
    .docs-list .list-item .item-name {
        font-weight: 600;
        font-size: 16px;
        color: var(--text-dark);
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 5px;
    }
    .docs-list .list-item .item-meta {
        font-size: 13px;
        color: var(--text-light);
    }
    .docs-list .list-item .item-actions {
        display: flex;
        gap: 8px;
    }

    /* Grid View */
    .docs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
        width: 100%;
        padding: 10px;
    }
    .docs-grid .grid-item {
        background-color: var(--white);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 15px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        box-shadow: var(--shadow);
        transition: all 0.3s;
        min-height: 220px; /* Ensure consistent height */
        justify-content: space-between;
    }
    .docs-grid .grid-item:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-md);
    }
    .docs-grid .grid-item .file-type-icon {
        width: 50px;
        height: 50px;
        font-size: 24px;
        margin-bottom: 10px;
    }
    .docs-grid .grid-item .item-name {
        font-weight: 600;
        font-size: 15px;
        color: var(--text-dark);
        margin-bottom: 5px;
        flex-grow: 1; /* Allows text to take available space */
    }
    .docs-grid .grid-item .item-meta {
        font-size: 12px;
        color: var(--text-light);
        margin-bottom: 10px;
    }
    .docs-grid .grid-item .item-actions {
        display: flex;
        gap: 5px;
        flex-wrap: wrap; /* Allow buttons to wrap if too many */
        justify-content: center;
    }
    .docs-grid .grid-item .action-btn {
        padding: 5px 10px;
        font-size: 12px;
    }


    /* Responsive */
    @media (max-width: 992px) {
      .main-content { margin-left: 70px; }
    }
    @media (max-width: 768px) {
      .stats-cards {
        grid-template-columns: 1fr;
      }
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
      .search-bar {
        width: 100%;
      }
      .user-profile {
        align-self: flex-end;
      }
      .filter-bar {
        flex-direction: column;
        align-items: flex-start;
      }
      .filter-group {
        width: 100%;
      }
      .filter-select,
      .date-range-input {
        width: 100%;
      }
      .archive-header {
        flex-direction: column;
        align-items: flex-start;
      }
      .archive-actions {
          width: 100%;
          justify-content: flex-start;
      }
      .view-options {
          margin-top: 15px;
          margin-left: 0;
          width: 100%;
          justify-content: center;
      }
    }
  </style>
</head>
<body>
  <script src="assets/smooth-interactions.js" defer></script>
  <div class="container">
    <div id="sidebar" class="sidebar">
      <div class="sidebar-header">
        <img src="<?php echo htmlspecialchars(getAppSetting('logo_url','hr.png')); ?>" alt="CHRMO Logo" />
        <h2>CHRMO Document Management</h2>
        <span class="sidebar-subtitle">Document Tracking System</span>
      </div>
      <div class="sidebar-menu">
        <div class="sidebar-section-label">WORKSPACE</div>
        <a href="dashboard.php" class="menu-item">
          <i class="fas fa-tachometer-alt"></i>
          <span>Dashboard</span>
        </a>
        <a href="tracking.php" class="menu-item">
          <i class="fas fa-file-signature"></i>
          <span>Document Status</span>
          <span class="menu-badge" id="trackingBadge">0</span>
        </a>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">ANALYTICS</div>
        <a href="stats.php" class="menu-item">
          <i class="fas fa-chart-bar"></i>
          <span>Status Reports</span>
        </a>
        <a href="archive.php" class="menu-item active">
          <i class="fas fa-archive"></i>
          <span>Archive Storage</span>
          <span class="menu-badge success" id="archiveBadge">0</span>
        </a>
        <?php if ($__isAdmin): ?>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">MANAGEMENT</div>
        <a href="usercontrol.php" class="menu-item">
          <i class="fas fa-users-cog"></i>
          <span>User Control</span>
        </a>
        <?php endif; ?>
      </div>
      <div class="sidebar-footer">
        <span>v2.1.0 &bull; CHRMO &copy; 2026</span>
      </div>
    </div>
    <div class="main-content">
      <div class="top-bar">
        <h2>Archive Storage</h2>
        <div class="search-wrap" style="position: relative;">
          <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="archiveSearchInput" placeholder="Search archived documents..." />
            <button type="button" id="ocrSearchBtn" class="ocr-search-toggle" title="Search in document content (OCR)">
              <i class="fas fa-file-alt"></i>
            </button>
          </div>
          <div id="ocrSearchResults" class="ocr-search-results" style="display:none;"></div>
        </div>
        <div style="display: flex; align-items: center;">
          <?php include __DIR__ . '/partials/notifications.php'; ?>
          <div class="user-profile" id="userProfile">
            <?php 
            $userInfo = getUserDisplayInfo();
            $initials = $userInfo ? getUserInitials($userInfo['name']) : 'U';
            $displayName = $userInfo ? $userInfo['name'] : 'User';
            ?>
            <img src="https://placehold.co/40x40/B2EBF2/0097A7?text=<?php echo urlencode($initials); ?>" alt="User" />
            <div>
              <div><?php echo htmlspecialchars($displayName); ?></div>
              <small style="color: var(--text-light);"><?php echo htmlspecialchars(formatUserRole($userInfo ? $userInfo['role'] : 'user')); ?></small>
            </div>
            <i class="fas fa-chevron-down" style="margin-left: 10px;"></i>
            <div class="dropdown-menu" id="userDropdown">
              <div class="dropdown-item" style="border-top: 1px solid var(--border); background: transparent; padding: 12px; display: flex; justify-content: center;">
                <a href="logout.php" class="logout-ghost">
                  <i class="fas fa-sign-out-alt"></i>
                  <span class="label">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="stats-cards">
        <div class="stat-card">
          <h3>Total Archived</h3>
          <div class="stat-value" id="archivedCount"><?php echo $totalArchived; ?></div>
        </div>
        <div class="stat-card">
          <h3>Storage Used</h3>
          <div class="stat-value" id="storageCount"><?php echo $totalStorageUsed; ?></div>
        </div>
        <div class="stat-card">
          <h3>Departments</h3>
          <div class="stat-value" id="deptCount"><?php echo count($departments); ?></div>
        </div>
      </div>
      <?php if ($fatalUploadError): ?>
        <div style="margin-bottom:15px;padding:15px;border-radius:8px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;display:flex;align-items:flex-start;gap:10px;">
          <i class="fas fa-exclamation-triangle" style="margin-top:3px;"></i>
          <div>
            <strong>Upload too large.</strong>
            <div><?php echo htmlspecialchars($fatalUploadError); ?></div>
          </div>
        </div>
      <?php endif; ?>
      <div class="filter-bar">
        <div class="filter-group">
          <span class="filter-label">Department:</span>
          <select class="filter-select" id="departmentFilter">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept_name): ?>
                <option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <span class="filter-label">Document Type:</span>
          <select class="filter-select" id="documentTypeFilter">
            <option value="">All Types</option>
            <option>Payroll</option>
            <option>Memo</option>
            <option>Travel Order</option>
            <option>Activity Design</option>
            <option>Purchase Request</option>
            <option>Purchase Order</option>
            <option>Advisories</option>
            <option>Announcement</option>
          </select>
        </div>
        <div class="filter-group">
          <span class="filter-label">Date Range:</span>
          <input type="text" class="date-range-input" id="dateRange" placeholder="Select date range" />
        </div>
        <button class="action-btn" id="applyFiltersBtn">
          <i class="fas fa-filter"></i> Apply Filters
        </button>
        <button class="action-btn secondary" id="clearFiltersBtn">
          <i class="fas fa-eraser"></i> Clear Filters
        </button>
        <div class="view-options">
          <button class="action-btn view-toggle-btn active-view" data-view="table" title="Table View">
            <i class="fas fa-table"></i>
          </button>
          <button class="action-btn view-toggle-btn" data-view="list" title="List View">
            <i class="fas fa-list"></i>
          </button>
          <button class="action-btn view-toggle-btn" data-view="grid" title="Grid View">
            <i class="fas fa-th-large"></i>
          </button>
        </div>
      </div>
      <div class="archive-container">
        <div class="archive-header">
          <h3>Archived Documents</h3>
          <div class="archive-actions">
            <button class="action-btn" id="downloadSelectedBtn" style="display: none;">
                <i class="fas fa-download"></i> Download Selected
            </button>
            <button class="action-btn danger" id="deleteSelectedBtn" style="display: none;">
                <i class="fas fa-trash-alt"></i> Delete Selected
            </button>
            <button class="action-btn" id="newArchiveBtn">
              <i class="fas fa-plus"></i> New Archive
            </button>
          </div>
        </div>
        <div id="documentView">
          <table class="docs-table" id="archiveTable">
            <thead>
              <tr>
                <th><input type="checkbox" id="selectAllCheckboxes"></th>
                <th>Document Type</th>
                <th>Department</th>
                <th>Status</th>
                <th>Date Archived</th>
                <th>Size</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="archiveTableBody">
            <?php
              if (!empty($documents)) {
                  foreach ($documents as $doc) {
                    // Format size to KB/MB
                    $sizeDisplay = $doc['size'] ?? 'N/A';
            ?>
            <tr data-doc-id="<?php echo $doc['id']; ?>">
              <td><input type="checkbox" class="rowCheckbox" data-doc-id="<?php echo $doc['id']; ?>"></td>
              <td>
                <div class="file-type">
                  <span class="file-type-icon <?php echo htmlspecialchars(strtolower($doc['file_type_icon'])); ?>">
                    <i class="<?php
                        switch (strtolower($doc['file_type_icon'])) {
                            case 'pdf': echo 'fas fa-file-pdf'; break;
                            case 'doc':
                            case 'docx': echo 'fas fa-file-word'; break;
                            case 'xls':
                            case 'xlsx': echo 'fas fa-file-excel'; break;
                            case 'png':
                            case 'jpg':
                            case 'jpeg': echo 'fas fa-file-image'; break;
                            case 'txt': echo 'fas fa-file-alt'; break;
                            default: echo 'fas fa-file'; break;
                        }
                    ?>"></i>
                  </span>
                  <?php echo htmlspecialchars($doc['type'] ?? 'Document'); ?>
                </div>
              </td>
              <td><?php echo htmlspecialchars($doc['department']); ?></td>
              <td><span class="badge archived"><?php echo htmlspecialchars($doc['status']); ?></span></td>
              <td><?php echo htmlspecialchars($doc['date']); ?></td>
              <td><?php echo htmlspecialchars($sizeDisplay); ?></td>
              <td>
                <div class="action-buttons-group">
                    <button class="action-btn info preview-btn" data-doc-id="<?php echo $doc['id']; ?>">
                      <i class="fas fa-info-circle"></i> Info
                    </button>
                    <button class="action-btn danger delete-btn" data-doc-id="<?php echo $doc['id']; ?>">
                      <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
              </td>
            </tr>
            <?php
                  }
              } else {
            ?>
            <tr>
                <td colspan="7" style="text-align:center; padding:20px; color:var(--text-secondary);">No archived documents found.</td>
            </tr>
            <?php
              }
            ?>
            </tbody>
          </table>
          <div class="docs-list" id="archiveList" style="display: none;">
            </div>
          <div class="docs-grid" id="archiveGrid" style="display: none;">
            </div>
        </div>
        <div class="pagination" style="display:flex;align-items:center;justify-content:center;gap:12px;">
          <button class="pagination-btn" id="prevPageBtn" disabled>Previous</button>
          <span id="paginationNumbers" style="display:none;"></span>
          <span id="pageIndicator" style="font-size:14px;color:#475569;font-weight:500;min-width:100px;text-align:center;">Page 1 of 1</span>
          <button class="pagination-btn" id="nextPageBtn">Next</button>
        </div>
      </div>
    </div>
  </div>

  <div id="documentPreviewModal" class="modal">
    <div class="modal-content">
      <span class="close-button" id="closePreviewModalTopBtn">&times;</span> <h3 id="previewDocName">Document Info</h3>
      <div class="modal-body">
        <div class="document-preview-frame" id="documentPreviewFrame">
          <div class="file-icon-box"><i class="fas fa-file-pdf" id="previewFileIcon"></i></div>
          <div class="file-info-text">
            <div class="file-title" id="previewFileName">Document</div>
            <div class="file-meta"><span id="previewFileType">PDF</span> &bull; <span id="previewSize">—</span></div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin-bottom:14px;">
          <p><strong>Document ID:</strong> <span id="previewDocId"></span></p>
          <p><strong>Department:</strong> <span id="previewDepartment"></span></p>
          <p><strong>Type:</strong> <span id="previewType"></span></p>
          <p><strong>Date Archived:</strong> <span id="previewDateArchived"></span></p>
          <p><strong>Time Archived:</strong> <span id="previewTimeArchived"></span></p>
        </div>

        <div class="audit-log" style="border-top:1px dashed var(--border); margin-top:18px; padding-top:14px;">
          <h4>Tracking Timeline</h4>
          <div id="archiveTimelineActivityLog">
            <div style="color:#64748b;">Loading timeline...</div>
          </div>
        </div>

        <div style="margin-top: 12px;">
          <!-- Extracted Information Keys -->
          <div id="archiveExtractedKeysSection" style="display:none; margin-bottom: 12px;">
            <p style="margin-bottom: 8px;"><strong><i class="fas fa-magic" style="color:#6366f1;"></i> Extracted Information:</strong></p>
            <div id="archiveExtractedKeysGrid" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
          </div>
          <p style="margin-bottom: 8px;"><strong>OCR Content (Editable):</strong></p>
          <textarea id="previewOcrContent" style="width:100%; background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:10px; height:220px; max-height:260px; overflow:auto; resize:vertical; white-space:pre-wrap; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; font-size:12px; line-height:1.5; color:#0f172a; text-align:justify; text-justify:inter-word; text-align-last:left; hyphens:auto; word-break:break-word;" spellcheck="false">Loading OCR...</textarea>
          <div style="margin-top:8px; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="action-btn" id="previewSaveOcrBtn"><i class="fas fa-save"></i> Save OCR Correction</button>
          </div>
        </div>
        <div class="audit-log">
          <h4>Audit Trail / History</h4>
          <ul id="previewAuditLog">
          </ul>
        </div>
      </div>
      <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
        <a id="archiveDownloadBtn" class="action-btn" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;cursor:pointer;" download>
          <i class="fas fa-download"></i> Download
        </a>
        <button class="action-btn secondary" id="closePreviewBtn">Close</button>
      </div>
    </div>
  </div>

  <div id="newArchiveModal" class="modal">
    <div class="modal-content small">
      <span class="close-button">&times;</span>
      <h3>Archive New Document</h3>
      <form id="archiveUploadForm" method="POST" action="archive.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add"> <input type="hidden" id="uploadDocId" name="id" value="">
        <input type="hidden" id="uploadedFileSize" name="size" value="">
        <input type="hidden" id="uploadedFileTypeIcon" name="file_type_icon" value="">

        <!-- Removed username field; name will default from filename if blank -->
        <div class="form-group">
          <label for="uploadDepartment">Department</label>
          <select id="uploadDepartment" name="department" required>
            <option value="">Select Department</option>
            <?php foreach ($departments as $dept_name): ?>
                <option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="uploadDocType">Document Type</label>
          <select id="uploadDocType" name="type" required>
            <option value="">Select Type</option>
            <option>Payroll</option>
            <option>Memo</option>
            <option>Travel Order</option>
            <option>Activity Design</option>
            <option>Purchase Request</option>
            <option>Purchase Order</option>
            <option>Advisories</option>
            <option>Announcement</option>
          </select>
        </div>
        <div class="form-group">
          <label for="uploadFile">Select File</label>
          <div class="file-select-container">
            <button type="button" class="file-select-btn" id="fileSelectBtn">
              <i class="fas fa-file-upload"></i> Choose File
            </button>
            <input type="file" id="uploadFile" name="uploadFile" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt" required style="display: none;">
            <span id="selectedFileName" class="selected-file-name">No file selected</span>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="action-btn secondary" id="cancelUpload">Cancel</button>
          <button type="submit" class="action-btn"><i class="fas fa-upload"></i> Archive Document</button>
        </div>
      </form>
    </div>
  </div>

  <div id="confirmActionModal" class="modal">
    <div class="modal-content small">
      <span class="close-button" id="closeConfirmModalBtn">&times;</span>
      <h3 id="confirmModalTitle">Confirm Action</h3>
      <p id="confirmModalMessage">Are you sure you want to proceed with this action?</p>
      <div class="modal-footer">
        <button type="button" class="action-btn secondary" id="cancelConfirmBtn">Cancel</button>
        <button type="button" class="action-btn danger" id="proceedConfirmBtn">Proceed</button>
      </div>
    </div>
  </div>

  <div id="toast-container" aria-live="polite" aria-atomic="true"></div>


  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    // User profile dropdown toggle
    const userProfile = document.getElementById('userProfile');
    const userDropdown = document.getElementById('userDropdown');
    userProfile.addEventListener('click', e => {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
    });

    // Notification system is now handled by notification_widget.php
    
    document.addEventListener('click', () => {
      userDropdown.classList.remove('show');
    });

    userDropdown.addEventListener('click', e => e.stopPropagation());

    // Initialize flatpickr date range picker
    flatpickr("#dateRange", {
      mode: "range",
      dateFormat: "M j, Y",
      // Default date can be set dynamically or left empty
    });

    // --- Archive Page Specific Logic ---
    // Initial data for documents (PHP will populate this)
    let documentsData = <?php echo json_encode($documents); ?>;

    const documentsPerPage = 5; // Number of documents per page
    let currentPage = 1;
    let filteredDocuments = [...documentsData]; // Initialize with all documents

    const archiveTableBody = document.getElementById('archiveTableBody');
    const archiveList = document.getElementById('archiveList');
    const archiveGrid = document.getElementById('archiveGrid');
    const paginationNumbers = document.getElementById('paginationNumbers');
    const pageIndicator = document.getElementById('pageIndicator');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const archiveSearchInput = document.getElementById('archiveSearchInput');
    const departmentFilter = document.getElementById('departmentFilter');
    const documentTypeFilter = document.getElementById('documentTypeFilter');
    const dateRange = document.getElementById('dateRange');
    const applyFiltersBtn = document.getElementById('applyFiltersBtn');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const viewToggleButtons = document.querySelectorAll('.view-toggle-btn');
    const documentViewDiv = document.getElementById('documentView');

    let currentView = 'table'; // Default view

    // Modals
    const documentPreviewModal = document.getElementById('documentPreviewModal');
    const newArchiveModal = document.getElementById('newArchiveModal');
    const closeButtons = document.querySelectorAll('.close-button'); // Select all elements with this class
    const newArchiveBtn = document.getElementById('newArchiveBtn');
    const cancelUploadBtn = document.getElementById('cancelUpload');
    const archiveUploadForm = document.getElementById('archiveUploadForm');
    const uploadFile = document.getElementById('uploadFile');
    const fileSelectBtn = document.getElementById('fileSelectBtn');
    const selectedFileName = document.getElementById('selectedFileName');

    // Multi-selection elements
    const selectAllCheckbox = document.getElementById('selectAllCheckboxes');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');

    // Preview Modal specific buttons
    const closePreviewBtn = document.getElementById('closePreviewBtn'); // Close button in modal footer
    const closePreviewModalTopBtn = document.getElementById('closePreviewModalTopBtn'); // X button at top right
    const downloadFromPreviewBtn = document.getElementById('downloadFromPreview');
    const previewOcrContent = document.getElementById('previewOcrContent');
    const previewSaveOcrBtn = document.getElementById('previewSaveOcrBtn');

    let previewObjectUrl = null;

    function cleanupPreviewObjectUrl() {
      if (previewObjectUrl) {
        URL.revokeObjectURL(previewObjectUrl);
        previewObjectUrl = null;
      }
      const pc = document.getElementById('filePreviewContainer');
      if (pc) { pc.style.display = 'none'; pc.innerHTML = ''; }
    }

    function escapeHtml(str) {
      if (str === undefined || str === null) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    async function loadDocumentPreview(doc) {
      const container = document.getElementById('filePreviewContainer');
      if (!container) return;
      cleanupPreviewObjectUrl();
      if (!doc.file_url) {
        container.style.display = 'none';
        return;
      }

      const docExtRaw = (doc.file_ext || doc.file_type_icon || '').toString().toLowerCase();
      const docExt = docExtRaw.startsWith('.') ? docExtRaw.slice(1) : docExtRaw;
      container.style.display = 'block';
      container.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:120px;color:#64748b;"><i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Loading preview…</div>';
      try {
        const previewUrl = doc.file_url + (doc.file_url.includes('?') ? '&' : '?') + '_=' + Date.now();
        const resp = await fetch(previewUrl, { credentials: 'include' });
        if (!resp.ok) {
          const errText = await resp.text().catch(() => '');
          throw new Error(errText || `Server responded with ${resp.status}`);
        }
        const contentType = (resp.headers.get('Content-Type') || '').toLowerCase();
        const blob = await resp.blob();

        const isDocx = docExt === 'docx' || contentType.includes('officedocument.wordprocessingml.document');
        const isPdf = docExt === 'pdf' || contentType.includes('pdf');
        const isImage = contentType.startsWith('image/') || ['png','jpg','jpeg','gif','bmp','webp'].includes(docExt);
        const isPlainText = contentType.startsWith('text/') || docExt === 'txt';

        if (isDocx && window.mammoth && blob) {
          try {
            const arrayBuffer = await blob.arrayBuffer();
            const result = await window.mammoth.convertToHtml({ arrayBuffer });
            container.innerHTML = `<div style="width:100%;max-height:400px;overflow:auto;padding:14px;background:#f8fafc;border-radius:10px;">${result.value}</div>`;
            return;
          } catch (mammothError) {
            console.error('Mammoth preview failed', mammothError);
            container.innerHTML = `<div style="text-align:center;padding:20px;color:#b91c1c;">Preview failed. Use Download to get the file.</div>`;
            return;
          }
        }

        const objectUrl = URL.createObjectURL(blob);
        let html = '';
        if (isImage) {
          previewObjectUrl = objectUrl;
          html = `<img src="${objectUrl}" alt="Preview" style="max-width:100%;max-height:400px;object-fit:contain;display:block;margin:0 auto;"/>`;
        } else if (isPdf) {
          previewObjectUrl = objectUrl;
          html = `<iframe src="${objectUrl}#toolbar=0&navpanes=0&scrollbar=1&view=FitH" style="width:100%;height:400px;border:0;" title="PDF Preview" loading="lazy"></iframe>`;
        } else if (isPlainText) {
          const text = await blob.text();
          URL.revokeObjectURL(objectUrl);
          html = `<pre style="white-space:pre-wrap;text-align:left;padding:14px;background:#f8fafc;max-height:400px;overflow:auto;">${escapeHtml(text)}</pre>`;
        } else {
          URL.revokeObjectURL(objectUrl);
          const safeUrl = escapeHtml(doc.file_url);
          html = `<div style="text-align:center;padding:20px;color:#475569;">Preview not available for this file type.<br/><a href="${safeUrl}" target="_blank" style="color:#0ea5e9;">Open in new tab</a></div>`;
        }
        container.innerHTML = html;
      } catch (err) {
        cleanupPreviewObjectUrl();
        container.innerHTML = `<div style="text-align:center;padding:20px;color:#b91c1c;">Unable to load preview.<br/><small>${escapeHtml(err.message || 'Unknown error')}</small></div>`;
      }
    }

    function hidePreviewModal() {
      documentPreviewModal.classList.remove('show');
      cleanupPreviewObjectUrl();
    }

    // Confirmation Modal Elements
    const confirmActionModal = document.getElementById('confirmActionModal');
    const closeConfirmModalBtn = document.getElementById('closeConfirmModalBtn');
    const confirmModalTitle = document.getElementById('confirmModalTitle');
    const confirmModalMessage = document.getElementById('confirmModalMessage');
    const cancelConfirmBtn = document.getElementById('cancelConfirmBtn');
    const proceedConfirmBtn = document.getElementById('proceedConfirmBtn');
    let currentConfirmAction = null; // To store the action to be performed after confirmation

    // --- Multi-select + Bulk Delete ---
    const selectedIds = new Set();

    function refreshSelectionUI() {
      const count = selectedIds.size;
      deleteSelectedBtn.style.display = count > 0 ? 'inline-flex' : 'none';
      downloadSelectedBtn.style.display = count > 0 ? 'inline-flex' : 'none';
      // Sync header checkbox state
      const totalVisible = document.querySelectorAll('#documentView .rowCheckbox').length;
      const totalChecked = document.querySelectorAll('#documentView .rowCheckbox:checked').length;
      selectAllCheckbox.checked = totalVisible > 0 && totalChecked === totalVisible;
      selectAllCheckbox.indeterminate = totalChecked > 0 && totalChecked < totalVisible;
    }

    // Backwards-compatible alias (some render paths call this name).
    function updateSelectedButtonsVisibility() {
      refreshSelectionUI();
    }

    // Event delegation for row checkbox changes (works for table/list/grid renders)
    document.addEventListener('change', function(e){
      if (e.target && e.target.classList && e.target.classList.contains('rowCheckbox')) {
        const id = parseInt(e.target.getAttribute('data-doc-id'), 10);
        if (e.target.checked) selectedIds.add(id); else selectedIds.delete(id);
        refreshSelectionUI();
      }
    });

    // Select/Deselect all currently visible rows
    selectAllCheckbox.addEventListener('change', function(){
      const rows = document.querySelectorAll('#documentView .rowCheckbox');
      rows.forEach(cb => {
        cb.checked = selectAllCheckbox.checked;
        const id = parseInt(cb.getAttribute('data-doc-id'), 10);
        if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
      });
      refreshSelectionUI();
    });

    // Download selected files (opens one download per selected ID)
    downloadSelectedBtn.addEventListener('click', function(){
      const ids = Array.from(selectedIds.values());
      if (!ids.length) return;
      // Trigger downloads sequentially to avoid browser blocking
      let i = 0;
      const tick = () => {
        if (i >= ids.length) {
          showToast('Download started for selected documents.', 'success');
          return;
        }
        const id = ids[i++];
        const a = document.createElement('a');
        a.href = `api/archive_download.php?id=${encodeURIComponent(id)}&dl=1`;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(tick, 350);
      };
      tick();
    });

    // Trigger bulk delete with confirmation
    deleteSelectedBtn.addEventListener('click', function(){
      const n = selectedIds.size;
      if (n === 0) return;
      openConfirmModal('Delete Selected', `Permanently delete ${n} archived document${n===1?'':'s'}? This cannot be undone.`, performBulkDelete);
    });

    async function performBulkDelete(){
      try {
        const ids = Array.from(selectedIds.values());
        if (ids.length === 0) {
          showToast('No documents selected.', 'warning');
          return;
        }
        
        // Show loading state
        const deleteBtn = document.getElementById('deleteSelectedBtn');
        const originalText = deleteBtn ? deleteBtn.innerHTML : '';
        if (deleteBtn) {
          deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
          deleteBtn.disabled = true;
        }
        
        const body = new URLSearchParams();
        body.set('action','bulkDelete');
        body.set('selected_ids', JSON.stringify(ids));
        
        const resp = await fetch('archive.php', { 
          method:'POST', 
          headers: { 'Content-Type':'application/x-www-form-urlencoded' }, 
          body: body.toString(), 
          cache:'no-store' 
        });
        
        // Check response content type to avoid JSON parse errors
        const contentType = resp.headers.get('content-type');
        let data = { success: false };
        
        if (contentType && contentType.includes('application/json')) {
          data = await resp.json().catch(() => ({ success: false, message: 'Invalid JSON response' }));
        } else {
          // Response is not JSON - likely an HTML error page
          const text = await resp.text();
          console.error('Unexpected response:', text.substring(0, 200));
          data = { success: false, message: 'Server returned unexpected response. Documents may have been deleted - please refresh.' };
        }
        
        // Restore button state
        if (deleteBtn) {
          deleteBtn.innerHTML = originalText;
          deleteBtn.disabled = false;
        }
        
        if (data && data.success) {
          // Remove from local arrays using Set for O(1) lookup
          const idsToRemove = new Set(ids.map(id => parseInt(id, 10)));
          documentsData = documentsData.filter(d => !idsToRemove.has(parseInt(d.id, 10)));
          filteredDocuments = filteredDocuments.filter(d => !idsToRemove.has(parseInt(d.id, 10)));
          selectedIds.clear();
          
          // Re-render the document list
          renderDocuments();
          refreshSelectionUI();
          
          showToast(data.message || `${ids.length} document(s) deleted successfully.`, 'success');
          
          // Update counters
          try {
            const countEl = document.getElementById('archivedCount');
            if (countEl) countEl.textContent = String(documentsData.length);
          } catch(_){}

          // Update storage used
          try {
            const storageEl = document.getElementById('storageCount');
            if (storageEl) {
              storageEl.textContent = formatBytes(sumBytes(documentsData));
            }
          } catch(_){ }
        } else {
          showToast((data && data.message) || 'Failed to delete selected documents.', 'error');
        }
      } catch (err) {
        console.error('Bulk delete error:', err);
        showToast('Network error while deleting. Please refresh the page.', 'error');
        
        // Restore button state on error
        const deleteBtn = document.getElementById('deleteSelectedBtn');
        if (deleteBtn) {
          deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
          deleteBtn.disabled = false;
        }
      }
    }

    function sumBytes(list){
      let total = 0;
      for (const d of (list || [])) {
        const b = parseSizeBytes(d);
        if (Number.isFinite(b) && b > 0) total += b;
      }
      return total;
    }

    function parseSizeBytes(doc){
      if (!doc) return 0;
      const sb = doc.size_bytes;
      if (typeof sb === 'number' && Number.isFinite(sb)) return sb;
      if (typeof sb === 'string' && /^\d+$/.test(sb)) return parseInt(sb, 10);
      const s = (doc.size || '').toString().trim();
      if (/^\d+$/.test(s)) return parseInt(s, 10);
      const m = s.replace(/\s+/g,'').match(/^(\d+(?:\.\d+)?)([KMG]?B)$/i);
      if (!m) return 0;
      const val = parseFloat(m[1]);
      const unit = m[2].toUpperCase();
      if (!Number.isFinite(val)) return 0;
      if (unit === 'GB') return Math.round(val * 1024 * 1024 * 1024);
      if (unit === 'MB') return Math.round(val * 1024 * 1024);
      if (unit === 'KB') return Math.round(val * 1024);
      return Math.round(val);
    }

    function formatBytes(bytes){
      const b = (typeof bytes === 'number' && Number.isFinite(bytes)) ? bytes : 0;
      if (b <= 0) return '0 B';
      if (b < 1024) return `${b} B`;
      if (b < 1024 * 1024) return `${(b/1024).toFixed(2)} KB`;
      if (b < 1024 * 1024 * 1024) return `${(b/(1024*1024)).toFixed(2)} MB`;
      return `${(b/(1024*1024*1024)).toFixed(2)} GB`;
    }

    // --- Toast Notification Function ---
    function showToast(message, type = 'info', duration = 3000) {
        const toastContainer = document.getElementById('toast-container') || (() => {
            const div = document.createElement('div');
            div.id = 'toast-container';
            document.body.appendChild(div);
            return div;
        })();

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let iconClass = '';
        if (type === 'success') iconClass = 'fa-check-circle';
        else if (type === 'error') iconClass = 'fa-times-circle';
        else if (type === 'info') iconClass = 'fa-info-circle';
        else if (type === 'warning') iconClass = 'fa-exclamation-triangle';

        toast.innerHTML = `<i class="fas ${iconClass}"></i> ${message}`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.remove();
            if (toastContainer.children.length === 0) {
                toastContainer.remove(); // Remove container if no toasts left
            }
        }, duration);
    }

    // --- Custom Confirmation Modal Functions ---
    function openConfirmModal(title, message, callback) {
        confirmModalTitle.textContent = title;
        confirmModalMessage.textContent = message;
        currentConfirmAction = callback; // Store the callback function
        confirmActionModal.classList.add('show');
    }

    function closeConfirmModal() {
        confirmActionModal.classList.remove('show');
        currentConfirmAction = null; // Clear the callback
    }

    // Event listeners for the custom confirmation modal
    closeConfirmModalBtn.addEventListener('click', closeConfirmModal);
    cancelConfirmBtn.addEventListener('click', closeConfirmModal);
    proceedConfirmBtn.addEventListener('click', () => {
        if (currentConfirmAction) {
            currentConfirmAction(); // Execute the stored callback
        }
        closeConfirmModal();
    });
    confirmActionModal.addEventListener('click', (event) => {
        if (event.target === confirmActionModal) {
            closeConfirmModal();
        }
    });

    // --- File Select Functionality ---
    fileSelectBtn.addEventListener('click', () => {
        uploadFile.click();
    });

    uploadFile.addEventListener('change', (event) => {
        const file = event.target.files[0];
        if (file) {
            selectedFileName.textContent = file.name;
            selectedFileName.classList.add('has-file');
        } else {
            selectedFileName.textContent = 'No file selected';
            selectedFileName.classList.remove('has-file');
        }
    });

    // Function to get file icon based on type
    function getFileIconClass(fileType) {
      switch (fileType.toLowerCase()) {
        case 'pdf': return 'fas fa-file-pdf';
        case 'doc':
        case 'docx': return 'fas fa-file-word';
        case 'xls':
        case 'xlsx': return 'fas fa-file-excel';
        case 'png':
        case 'jpg':
        case 'jpeg': return 'fas fa-file-image';
        case 'txt': return 'fas fa-file-alt';
        default: return 'fas fa-file';
      }
    }

    // Function to get file type class for styling
    function getFileTypeClass(fileType) {
      switch (fileType.toLowerCase()) {
        case 'pdf': return 'pdf';
        case 'doc':
        case 'docx': return 'doc';
        case 'xls':
        case 'xlsx': return 'xls';
        case 'png':
        case 'jpg':
        case 'jpeg': return 'img';
        case 'txt': return 'txt';
        default: return '';
      }
    }

    // Render documents based on current page and filters
    function renderDocuments() {
      const startIndex = (currentPage - 1) * documentsPerPage;
      const endIndex = startIndex + documentsPerPage;
      const paginatedDocuments = filteredDocuments.slice(startIndex, endIndex);

      archiveTableBody.innerHTML = '';
      archiveList.innerHTML = '';
      archiveGrid.innerHTML = '';

      if (paginatedDocuments.length === 0) {
        archiveTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px;">No documents found matching your criteria.</td></tr>`;
        archiveList.innerHTML = `<div style="text-align: center; padding: 20px; color: var(--text-light);">No documents found matching your criteria.</div>`;
        archiveGrid.innerHTML = `<div style="text-align: center; padding: 20px; color: var(--text-light);">No documents found matching your criteria.</div>`;
        return;
      }

      paginatedDocuments.forEach(doc => {
        const fileIconClass = getFileIconClass(doc.file_type_icon); // Use file_type_icon from PHP
        const fileTypeClass = getFileTypeClass(doc.file_type_icon); // Use file_type_icon from PHP
        const sizeDisplay = doc.size || 'N/A';
        const dateDisplay = doc.date || 'N/A';

        // Table View
        const row = document.createElement('tr');
        row.setAttribute('data-doc-id', doc.id);
        row.innerHTML = `
          <td><input type="checkbox" class="rowCheckbox" data-doc-id="${doc.id}"></td>
          <td>
            <div class="file-type">
              <span class="file-type-icon ${fileTypeClass}">
                <i class="${fileIconClass}"></i>
              </span>
              ${doc.type || 'Document'}
            </div>
          </td>
          <td>${doc.department}</td>
          <td><span class="badge archived">${doc.status}</span></td>
          <td>${dateDisplay}</td>
          <td>${sizeDisplay}</td>
          <td>
            <div class="action-buttons-group">
                <button class="action-btn info preview-btn" data-doc-id="${doc.id}">
                  <i class="fas fa-info-circle"></i> Info
                </button>
                <button class="action-btn danger delete-btn" data-doc-id="${doc.id}">
                  <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
          </td>
        `;
        archiveTableBody.appendChild(row);

        // List View
        const listItem = document.createElement('div');
        listItem.className = 'list-item';
        listItem.setAttribute('data-doc-id', doc.id);
        listItem.innerHTML = `
          <div class="item-details">
            <div class="item-name">
              <input type="checkbox" class="rowCheckbox" data-doc-id="${doc.id}" style="margin-right: 10px;"> <span class="file-type-icon ${fileTypeClass}">
                <i class="${fileIconClass}"></i>
              </span>
              ${doc.type || 'Document'}
            </div>
            <div class="item-meta">
              <span>Department: ${doc.department}</span> |
              <span>Status: ${doc.status}</span> |
              <span>Archived: ${dateDisplay}</span> |
              <span>Size: ${sizeDisplay}</span>
            </div>
          </div>
          <div class="action-buttons-group">
            <button class="action-btn info preview-btn" data-doc-id="${doc.id}">
              <i class="fas fa-info-circle"></i>
            </button>
            <button class="action-btn danger delete-btn" data-doc-id="${doc.id}">
              <i class="fas fa-trash-alt"></i>
            </button>
          </div>
        `;
        archiveList.appendChild(listItem);

        // Grid View
        const gridItem = document.createElement('div');
        gridItem.className = 'grid-item';
        gridItem.setAttribute('data-doc-id', doc.id);
        gridItem.innerHTML = `
          <input type="checkbox" class="rowCheckbox" data-doc-id="${doc.id}" style="align-self: flex-start;"> <span class="file-type-icon ${fileTypeClass}">
            <i class="${fileIconClass}"></i>
          </span>
          <div class="item-name">${doc.type || 'Document'}</div>
          <div class="item-meta">
            ${doc.department} <br>
            ${dateDisplay} <br>
            ${sizeDisplay}
          </div>
          <div class="item-actions">
            <button class="action-btn info preview-btn" data-doc-id="${doc.id}">
              <i class="fas fa-info-circle"></i>
            </button>
            <button class="action-btn danger delete-btn" data-doc-id="${doc.id}">
              <i class="fas fa-trash-alt"></i>
            </button>
          </div>
        `;
        archiveGrid.appendChild(gridItem);
      });

      addEventListenersToButtons(); // Attach event listeners after rendering
      updatePagination();
      updateView(); // Ensure correct view is displayed
      updateSelectedButtonsVisibility(); // Update button state after rendering
    }

    // Filter documents function
    function getFilteredDocuments() {
      const isOcrMode = window.__archiveOcrSearchMode === true;
      // In OCR mode, the typed query is for OCR content; don't require it to match metadata fields.
      const searchTerm = isOcrMode ? '' : archiveSearchInput.value.toLowerCase();
      const ocrIds = window.__archiveOcrResultDocIds instanceof Set ? window.__archiveOcrResultDocIds : null;
      const selectedDepartment = departmentFilter.value;
      const selectedDocumentType = documentTypeFilter.value;
      const selectedDateRange = dateRange.value; // e.g., "May 1, 2025 to May 15, 2025"
      let startDate = null;
      let endDate = null;

      if (selectedDateRange) {
        const dates = selectedDateRange.split(' to ');
        if (dates.length === 2) {
          startDate = new Date(dates[0]);
          endDate = new Date(dates[1]);
          // Adjust endDate to include the entire day
          endDate.setHours(23, 59, 59, 999);
        } else if (dates.length === 1) {
          // If only one date is selected, treat it as a single-day range
          startDate = new Date(dates[0]);
          endDate = new Date(dates[0]);
          endDate.setHours(23, 59, 59, 999);
        }
      }

      const safeLower = (v) => (v === undefined || v === null) ? '' : String(v).toLowerCase();

      return documentsData.filter(doc => { // Use documentsData
        const matchesOcr = !ocrIds ? true : ocrIds.has(String(doc.id));
        const matchesSearch = safeLower(doc.document_name).includes(searchTerm) ||
                              safeLower(doc.department).includes(searchTerm) ||
                              safeLower(doc.type).includes(searchTerm) ||
                              safeLower(doc.ocr_content).includes(searchTerm) ||
                              safeLower(doc.original_ocr_content).includes(searchTerm);
        const matchesDepartment = selectedDepartment === "" || doc.department === selectedDepartment;
        const matchesDocumentType = selectedDocumentType === "" || doc.type === selectedDocumentType;

        let matchesDateRange = true;
        if (startDate && endDate) {
          // Prefer raw DB timestamp when available to avoid locale parsing issues.
          const docDate = new Date(doc.date_archived || doc.date_time || doc.date || '');
          matchesDateRange = docDate >= startDate && docDate <= endDate;
        }
        
        return matchesOcr && matchesSearch && matchesDepartment && matchesDocumentType && matchesDateRange;
      });
    }

    // Apply filters and re-render
    applyFiltersBtn.addEventListener('click', () => {
      filteredDocuments = getFilteredDocuments();
      currentPage = 1; // Reset to first page
      renderDocuments();
    });

    // Clear filters and re-render
    clearFiltersBtn.addEventListener('click', () => {
      archiveSearchInput.value = '';
      departmentFilter.value = '';
      documentTypeFilter.value = '';
      dateRange.value = ''; // Clear flatpickr
      filteredDocuments = [...documentsData]; // Reset to original data
      currentPage = 1;
      renderDocuments();
    });

    // Search input live filter
    archiveSearchInput.addEventListener('keyup', () => {
      // When OCR mode is enabled, filtering is driven by OCR results.
      if (window.__archiveOcrSearchMode === true) return;
      filteredDocuments = getFilteredDocuments();
      currentPage = 1; // Reset to first page
      renderDocuments();
    });


    // Pagination functions
    function updatePagination() {
      const totalPages = Math.max(1, Math.ceil(filteredDocuments.length / documentsPerPage));
      paginationNumbers.innerHTML = '';

      prevPageBtn.disabled = currentPage <= 1;
      nextPageBtn.disabled = currentPage >= totalPages;

      if (pageIndicator) {
        pageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;
      }
    }

    // Pagination button event listeners
    prevPageBtn.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage--;
        renderDocuments();
      }
    });

    nextPageBtn.addEventListener('click', () => {
      const totalPages = Math.ceil(filteredDocuments.length / documentsPerPage);
      if (currentPage < totalPages) {
        currentPage++;
        renderDocuments();
      }
    });

    // Modals
    // Event listener for all close buttons (top-right X)
    closeButtons.forEach(button => {
      button.addEventListener('click', () => {
        hidePreviewModal();
        newArchiveModal.classList.remove('show');
      });
    });

    // Event listener for the "Close" button in the preview modal footer
    if (closePreviewBtn) closePreviewBtn.addEventListener('click', hidePreviewModal);
    if (closePreviewModalTopBtn) closePreviewModalTopBtn.addEventListener('click', hidePreviewModal);

    window.addEventListener('click', (event) => {
      if (event.target == documentPreviewModal) {
        hidePreviewModal();
      }
      if (event.target == newArchiveModal) {
        newArchiveModal.classList.remove('show');
      }
    });

    // New Archive Button
    newArchiveBtn.addEventListener('click', () => {
      newArchiveModal.classList.add('show');
      archiveUploadForm.reset();
      // reset file select UI
      selectedFileName.textContent = 'No file selected';
      selectedFileName.classList.remove('has-file');
      document.getElementById('uploadDocId').value = ''; // Clear ID for add
      document.getElementById('uploadedFileSize').value = '';
      document.getElementById('uploadedFileTypeIcon').value = '';
      if (selectAllCheckbox) {
          selectAllCheckbox.checked = false;
      }
      updateSelectedButtonsVisibility();
    });

    cancelUploadBtn.addEventListener('click', () => {
      newArchiveModal.classList.remove('show');
    });

    // Drag & drop removed; use button-triggered file input instead

    // Add event listeners for preview, delete, download, restore buttons
    function addEventListenersToButtons() {
      // Use event delegation on the parent container (documentViewDiv)
      documentViewDiv.addEventListener('click', handleDocumentActions);
    }

    function handleDocumentActions(e) {
        // Only intercept clicks for actual action buttons. Let checkboxes and normal clicks work.
        const target = e.target;
        const button = target.closest('.action-btn');
        if (!button || !button.classList.contains('action-btn')) return; // do not prevent checkbox toggles or other native events

        // Only intercept clicks for row-level action buttons that declare a doc id.
        // This prevents breaking native clicks for modal footer anchors like the Download button.
        const docId = button.dataset.docId;
        if (!docId) {
          return;
        }

        e.preventDefault();
        e.stopPropagation();

        const doc = documentsData.find(d => String(d.id) === String(docId)); // Find by string ID
        if (!doc) return;

        if (button.classList.contains('preview-btn')) {
            document.getElementById('previewDocName').textContent = doc.document_name;
            document.getElementById('previewDocId').textContent = doc.id;
            document.getElementById('previewDepartment').textContent = doc.department;
            document.getElementById('previewType').textContent = doc.type;
            document.getElementById('previewDateArchived').textContent = doc.date || 'N/A';
            document.getElementById('previewTimeArchived').textContent = doc.time_only || 'N/A';
            document.getElementById('previewSize').textContent = doc.size;

            // Populate compact file card
            const fileNameEl = document.getElementById('previewFileName');
            const fileTypeEl = document.getElementById('previewFileType');
            const fileIconEl = document.getElementById('previewFileIcon');
            const inlineDlBtn = document.getElementById('previewInlineDownload');
            if (fileNameEl) fileNameEl.textContent = doc.document_name || 'Document';
            const ext = (doc.file_path || '').split('.').pop().toLowerCase().replace('enc','').replace('.','') || 'pdf';
            const iconMap = { pdf:'fa-file-pdf', jpg:'fa-file-image', jpeg:'fa-file-image', png:'fa-file-image', gif:'fa-file-image', doc:'fa-file-word', docx:'fa-file-word', xls:'fa-file-excel', xlsx:'fa-file-excel', txt:'fa-file-alt' };
            if (fileIconEl) fileIconEl.className = 'fas ' + (iconMap[ext] || 'fa-file-pdf');
            if (fileTypeEl) fileTypeEl.textContent = ext.toUpperCase() + ' File';

            // Wire download button (always enabled; backend will validate availability)
            const archiveDlBtn = document.getElementById('archiveDownloadBtn');
            if (archiveDlBtn) {
              const baseUrl = `api/archive_download.php?id=${encodeURIComponent(String(doc.id))}`;
              archiveDlBtn.href = `${baseUrl}&dl=1&t=${Date.now()}`;
              archiveDlBtn.style.pointerEvents = 'auto';
              archiveDlBtn.style.opacity = '1';
            }

            const archiveTimelineActivityLog = document.getElementById('archiveTimelineActivityLog');
            if (archiveTimelineActivityLog) {
              archiveTimelineActivityLog.innerHTML = '<div style="color:#64748b;">Loading timeline...</div>';
            }

            if (previewOcrContent) {
              previewOcrContent.value = 'Loading OCR...';
              loadArchiveOcrIntoPreviewModal(doc.id, previewOcrContent);
            }

            if (previewSaveOcrBtn && previewOcrContent) {
              previewSaveOcrBtn.disabled = false;
              previewSaveOcrBtn.onclick = async () => {
                const originalLabel = previewSaveOcrBtn.innerHTML;
                previewSaveOcrBtn.disabled = true;
                previewSaveOcrBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                try {
                  const fd = new FormData();
                  fd.append('doc_id', String(doc.id));
                  fd.append('ocr_text', previewOcrContent.value || '');
                  const r = await fetch('archive.php?action=save_ocr_correction', { method: 'POST', body: fd, credentials: 'include' });
                  const payload = await r.json();
                  if (payload && payload.success) {
                    previewSaveOcrBtn.innerHTML = '<i class="fas fa-check"></i> Saved';
                    setTimeout(() => {
                      previewSaveOcrBtn.innerHTML = originalLabel;
                      previewSaveOcrBtn.disabled = false;
                    }, 900);
                    return;
                  }
                  previewSaveOcrBtn.innerHTML = originalLabel;
                  previewSaveOcrBtn.disabled = false;
                  alert((payload && payload.error) ? payload.error : 'Failed to save OCR correction');
                } catch (err) {
                  previewSaveOcrBtn.innerHTML = originalLabel;
                  previewSaveOcrBtn.disabled = false;
                  alert('Failed to save OCR correction');
                }
              };
            }

            const previewAuditLog = document.getElementById('previewAuditLog');
            if (previewAuditLog) {
              previewAuditLog.innerHTML = '<li style="color:#64748b;">Loading history...</li>';
              try {
                fetch(`archive.php?action=doc_history&doc_id=${encodeURIComponent(String(doc.id))}`, { cache: 'no-store', credentials: 'include' })
                  .then(r => r.json())
                  .then(payload => {
                    const events = payload && payload.success && Array.isArray(payload.events) ? payload.events : [];
                    const resolvedTrackingId = payload && payload.success ? (payload.resolved_doc_id || null) : null;

                    // Render tracking timeline: try tracking.php doc_detail first, fall back to events from archive_history
                    if (archiveTimelineActivityLog) {
                      const buildNotesHtml = (item, actionType, actionDesc, index) => {
                        const returnNotes = (item && item.notes) ? String(item.notes) : '';
                        if (!returnNotes) return '';
                        const isReturn = actionType === 'return' || (actionDesc || '').toLowerCase().includes('return');
                        if (!isReturn) return '';
                        const rid = `archiveReturnNotes_${index}`;
                        return `
                          <div class="timeline-notes">
                            <details id="${rid}">
                              <summary>
                                <i class="fas fa-exclamation-circle"></i>
                                <strong>Return reason</strong>
                                <span style="margin-left:auto;font-weight:600;color:#b45309;">Show</span>
                              </summary>
                              <div class="timeline-notes-body">${escapeHtml(returnNotes)}</div>
                            </details>
                          </div>`;
                      };

                      const iconFor = (type) => {
                        if (type === 'route') return 'paper-plane';
                        if (type === 'create' || type === 'upload') return 'plus-circle';
                        if (type === 'receive') return 'inbox';
                        if (type === 'return') return 'undo';
                        if (type === 'archive') return 'archive';
                        if (type === 'update' || type === 'file_update') return 'edit';
                        return 'check';
                      };

                      const statusClassFor = (type) => {
                        if (type === 'receive') return 'review';
                        if (type === 'return') return 'returned';
                        if (type === 'archive') return 'completed';
                        if (type === 'route' || type === 'create' || type === 'upload') return 'completed';
                        return 'completed';
                      };

                      // Render timeline items from a generic list (works for both tracking doc_detail and archive_history events)
                      const renderTimelineFromDocDetail = (hist) => {
                        archiveTimelineActivityLog.innerHTML = '';
                        hist.forEach((item, index) => {
                          const actionType = (item && item.actionType) ? String(item.actionType) : 'create';
                          const statusClass = statusClassFor(actionType);
                          const iconClass = iconFor(actionType);
                          const actionDesc = (item && item.action) ? String(item.action) : '';

                          const arrivedAt = item && item.arrivedAt ? String(item.arrivedAt) : '';
                          const sentAt = item && item.sentAt ? String(item.sentAt) : '';
                          const arrivedLabel = arrivedAt ? escapeHtml(arrivedAt) : '—';
                          const sentLabel = sentAt ? escapeHtml(sentAt) : '—';
                          let timestampHtml = '<div class="timeline-timestamps" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;font-size:11px;">';
                          timestampHtml += `<span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-sign-in-alt" style="font-size:10px;"></i>Arrived: ${arrivedLabel}</span>`;
                          timestampHtml += `<span style="background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:12px;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-sign-out-alt" style="font-size:10px;"></i>Sent: ${sentLabel}</span>`;
                          timestampHtml += '</div>';

                          const notesHtml = buildNotesHtml(item, actionType, actionDesc, index);

                          const timelineItem = document.createElement('div');
                          timelineItem.className = 'timeline-item-horizontal';
                          timelineItem.innerHTML = `
                            <div class="timeline-dept-col">
                              <div class="timeline-dept-badge">${escapeHtml((item && item.user) ? String(item.user) : 'System')}</div>
                            </div>
                            <div class="timeline-details-col">
                              <div class="timeline-date-time">
                                <i class="fas fa-calendar-alt" style="margin-right: 6px; color: var(--primary);"></i>${escapeHtml((item && item.time) ? String(item.time) : '')}
                              </div>
                              <div class="timeline-description">${escapeHtml(actionDesc)}</div>
                              ${timestampHtml}
                              ${notesHtml}
                            </div>
                            <div class="timeline-status-col">
                              <div class="timeline-dot ${statusClass}">
                                <i class="fas fa-${iconClass}"></i>
                              </div>
                              <span class="timeline-status-pill ${statusClass}">${escapeHtml(actionType.charAt(0).toUpperCase() + actionType.slice(1).replace('_',' '))}</span>
                            </div>
                          `;
                          archiveTimelineActivityLog.appendChild(timelineItem);
                        });
                      };

                      // Convert archive_history/document_history events into the same shape as tracking doc_detail history items
                      const renderTimelineFromEvents = (evts) => {
                        if (!evts || !evts.length) {
                          archiveTimelineActivityLog.innerHTML = '<div style="color:#64748b;">No tracking timeline available.</div>';
                          return;
                        }
                        const mapped = evts.map(ev => ({
                          actionType: ev.action || 'archive',
                          action: (() => {
                            const a = (ev.action || '').toLowerCase();
                            const from = ev.from_holder || '';
                            const to = ev.to_holder || '';
                            if (a === 'create') return 'Document Created' + (from ? (' by ' + from) : '');
                            if (a === 'route') return 'Routed' + (from && to ? (' from ' + from + ' to ' + to) : (to ? (' to ' + to) : ''));
                            if (a === 'receive') return 'Received by ' + (to || from || 'Unknown');
                            if (a === 'return') return 'Returned' + (to ? (' to ' + to) : '') + (from ? (' by ' + from) : '');
                            if (a === 'archive') return 'Document Archived';
                            if (a === 'complete') return 'Completed';
                            return (ev.action || 'Event') + (to ? (' → ' + to) : '');
                          })(),
                          user: ev.to_holder || ev.from_holder || 'System',
                          time: ev.created_at || '',
                          arrivedAt: ev.created_at || '',
                          sentAt: '',
                          notes: ev.notes || '',
                        }));
                        renderTimelineFromDocDetail(mapped);
                      };

                      (async () => {
                        try {
                          const resolvedIdNum = resolvedTrackingId ? parseInt(String(resolvedTrackingId), 10) : NaN;
                          let rendered = false;

                          // Try tracking.php doc_detail first (works if tracking row still exists)
                          if (resolvedIdNum && !Number.isNaN(resolvedIdNum) && resolvedIdNum > 0) {
                            try {
                              const detailUrl = `tracking.php?action=doc_detail&id=${encodeURIComponent(String(resolvedIdNum))}`;
                              const rr = await fetch(detailUrl, { cache: 'no-store', credentials: 'include' });
                              const dd = await rr.json();
                              if (dd && dd.success && dd.doc) {
                                const hist = Array.isArray(dd.doc.history) ? dd.doc.history : [];
                                if (hist.length) {
                                  renderTimelineFromDocDetail(hist);
                                  rendered = true;
                                }
                              }
                            } catch (_) { /* fall through to events-based rendering */ }
                          }

                          // Fallback: render timeline from archive_history / document_history events
                          if (!rendered) {
                            renderTimelineFromEvents(events);
                          }
                        } catch (_) {
                          if (archiveTimelineActivityLog) archiveTimelineActivityLog.innerHTML = '<div style="color:#64748b;">No tracking timeline available.</div>';
                        }
                      })();
                    }

                    if (!events.length) {
                      previewAuditLog.innerHTML = '<li style="color:#64748b;">No history available.</li>';
                      return;
                    }
                    const esc = (s) => escapeHtml((s ?? '').toString());
                    previewAuditLog.innerHTML = events.map(ev => {
                      const at = esc(ev.created_at || '');
                      const act = esc(ev.action || '');
                      const fromH = esc(ev.from_holder || '');
                      const toH = esc(ev.to_holder || '');
                      const note = esc(ev.notes || '');
                      const parts = [];
                      if (act) parts.push(act);
                      if (fromH || toH) parts.push(`${fromH}${fromH && toH ? ' → ' : ''}${toH}`);
                      if (note) parts.push(note);
                      const line = parts.filter(Boolean).join(' • ');
                      return `<li>${at ? `<strong>${at}</strong> ` : ''}${line}</li>`;
                    }).join('');
                  })
                  .catch(() => {
                    previewAuditLog.innerHTML = '<li style="color:#64748b;">No history available.</li>';
                  });
              } catch (_) {
                previewAuditLog.innerHTML = '<li style="color:#64748b;">No history available.</li>';
              }
            }

            documentPreviewModal.classList.add('show');
        } else if (button.classList.contains('delete-btn')) {
            openConfirmModal(
                `Delete Document`,
                `Are you sure you want to PERMANENTLY delete "${doc.document_name}"? This action cannot be undone.`,
                () => {
                    // Redirect to PHP script for deletion
                    window.location.href = `archive.php?delete_id=${doc.id}`;
                }
            );
        }
    }

    async function loadArchiveOcrIntoPreviewModal(docId, targetEl) {
      if (!targetEl) return;
      try {
        const r = await fetch(`archive.php?action=ocr_pages&doc_id=${encodeURIComponent(String(docId))}`, { cache: 'no-store', credentials: 'include' });
        const payload = await r.json();
        if (!payload || !payload.success) {
          targetEl.value = 'OCR not available.';
          return;
        }
        const pages = Array.isArray(payload.pages) ? payload.pages : [];
        if (pages.length === 0) {
          targetEl.value = 'OCR not available.';
          return;
        }

        const normalizeOcrText = (raw) => {
          if (raw === undefined || raw === null) return '';
          let t = String(raw);
          t = t.replace(/\r\n/g, '\n').replace(/\n/g, '\n').replace(/\r/g, '\n');
          const marker = '--- Extracted Text ---';
          const idx = t.indexOf(marker);
          if (idx !== -1) {
            t = t.slice(idx + marker.length);
          }
          t = t.replace(/^\s+/, '');
          return t;
        };

        const parts = [];
        for (const p of pages) {
          const num = p.page_number || 1;
          const text = normalizeOcrText((p.ocr_text || '').toString());
          if (text.trim() === '') continue;
          const clipped = text.length > 8000 ? (text.slice(0, 8000) + '\n\n[truncated]') : text;
          parts.push(pages.length > 1 ? `--- Page ${num} ---\n${clipped}` : clipped);
        }

        const finalText = parts.join('\n\n');
        targetEl.value = finalText.trim() !== '' ? finalText : 'OCR not available.';
        // Parse and display extracted keys
        archiveParseAndShowExtractedKeys(pages);
      } catch (e) {
        targetEl.value = 'OCR not available.';
        archiveParseAndShowExtractedKeys([]);
      }
    }

    /**
     * Parse OCR pages text and render extracted key chips in the archive modal
     */
    function archiveParseAndShowExtractedKeys(pages) {
      const section = document.getElementById('archiveExtractedKeysSection');
      const grid = document.getElementById('archiveExtractedKeysGrid');
      if (!section || !grid) return;
      grid.innerHTML = '';
      section.style.display = 'none';

      let fullText = '';
      for (const p of (pages || [])) {
        const t = String(p.ocr_text || '').replace(/\\n/g, '\n').replace(/\\r/g, '');
        fullText += t + '\n\n';
      }
      if (!fullText.trim()) return;

      const chips = [];
      // Tagged format (TYPE:..., NAME:..., etc.)
      const tagPatterns = [
        { tag: 'TYPE', cls: 'type', icon: 'fas fa-file-alt', label: 'Type' },
        { tag: 'NAME', cls: 'name', icon: 'fas fa-user', label: 'Name' },
        { tag: 'DEPT', cls: 'dept', icon: 'fas fa-building', label: 'Dept' },
        { tag: 'REF', cls: 'ref', icon: 'fas fa-hashtag', label: 'Ref' },
        { tag: 'AMOUNT', cls: 'amount', icon: 'fas fa-money-bill', label: 'Amount' },
      ];
      for (const tp of tagPatterns) {
        const regex = new RegExp('^' + tp.tag + ':(.+)$', 'gm');
        let m, count = 0;
        while ((m = regex.exec(fullText)) !== null && count < 3) {
          const val = m[1].trim();
          if (val && val.length > 1) { chips.push({ cls: tp.cls, icon: tp.icon, label: tp.label, value: val }); count++; }
        }
      }
      // Fallback regex extraction
      if (chips.length === 0) {
        const typeMatch = fullText.match(/\b(payroll|memorandum|memo|certificate|clearance|leave|appointment|order|resolution|ordinance|voucher|receipt|invoice|requisition|contract|travel|liquidation|evaluation|appraisal|advisory|announcement)\b/i);
        if (typeMatch) { const t = typeMatch[1]; chips.push({ cls: 'type', icon: 'fas fa-file-alt', label: 'Type', value: t.charAt(0).toUpperCase() + t.slice(1).toLowerCase() }); }
        const nameRegex = /(?:name|employee|prepared by|submitted by|approved by)\s*[:\-]?\s*([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+){1,3})/gi;
        let nm, nc = 0;
        while ((nm = nameRegex.exec(fullText)) !== null && nc < 3) { chips.push({ cls: 'name', icon: 'fas fa-user', label: 'Name', value: nm[1].trim() }); nc++; }
        const dateRegex = /\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\w+\s+\d{1,2},?\s+\d{4})\b/g;
        let dm, dc = 0;
        while ((dm = dateRegex.exec(fullText)) !== null && dc < 3) { chips.push({ cls: 'date', icon: 'fas fa-calendar', label: 'Date', value: dm[1] }); dc++; }
        const amtRegex = /(?:₱|PHP|Php)\s*[\d,]+(?:\.\d{2})?/g;
        let am, ac = 0;
        while ((am = amtRegex.exec(fullText)) !== null && ac < 2) { chips.push({ cls: 'amount', icon: 'fas fa-money-bill', label: 'Amount', value: am[0] }); ac++; }
        const refRegex = /(?:ref|reference|no|number|control)[.\s#:]*([A-Z0-9\-]{4,})/gi;
        let rm;
        while ((rm = refRegex.exec(fullText)) !== null) { chips.push({ cls: 'ref', icon: 'fas fa-hashtag', label: 'Ref', value: rm[1].trim() }); break; }
        const deptRegex = /(?:department|office|division)\s+(?:of\s+)?([A-Za-z\s]{3,30})(?:\n|$|[,.])/gi;
        let dpm;
        while ((dpm = deptRegex.exec(fullText)) !== null) { chips.push({ cls: 'dept', icon: 'fas fa-building', label: 'Dept', value: dpm[1].trim() }); break; }
      }
      if (chips.length === 0) return;
      section.style.display = 'block';
      for (const chip of chips) {
        const el = document.createElement('span');
        el.className = 'ocr-key-chip ' + chip.cls;
        el.innerHTML = '<i class="ocr-key-icon ' + chip.icon + '"></i><span class="ocr-key-label">' + escapeHtml(chip.label) + '</span> <span class="ocr-key-value" title="' + escapeHtml(chip.value) + '">' + escapeHtml(chip.value) + '</span>';
        grid.appendChild(el);
      }
    }

    // Generate PDF download for document
    function generatePDFDownload(doc) {
        // Create a new jsPDF instance
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF();
        
        // Set document properties
        pdf.setProperties({
            title: `${doc.document_name} - Archive Document`,
            subject: 'Archived Document Information',
            author: 'CHRMO Document Management System',
            creator: 'Archive System'
        });
        
        // Add header
        pdf.setFontSize(20);
        pdf.setTextColor(8, 145, 178); // Primary color
        pdf.text('CHRMO Document Archive', 20, 30);
        
        // Add document information
        pdf.setFontSize(16);
        pdf.setTextColor(0, 0, 0);
        pdf.text('Document Information', 20, 50);
        
        // Add document details
        pdf.setFontSize(12);
        let yPosition = 70;
        const lineHeight = 10;
        
        const documentInfo = [
            ['Document Type:', doc.type || 'Document'],
            ['Department:', doc.department],
            ['Status:', doc.status],
            ['Date Archived:', doc.date || 'N/A'],
            ['Time Archived:', doc.time_only || 'N/A'],
            ['File Size:', doc.size || 'N/A'],
            ['Document ID:', doc.id]
        ];
        
        documentInfo.forEach(([label, value]) => {
            pdf.setFont(undefined, 'bold');
            pdf.text(label, 20, yPosition);
            pdf.setFont(undefined, 'normal');
            pdf.text(String(value), 80, yPosition);
            yPosition += lineHeight;
        });
        
        // Add footer
        yPosition += 20;
        pdf.setFontSize(10);
        pdf.setTextColor(120, 144, 156);
        pdf.text('Generated by CHRMO Document Management System', 20, yPosition);
        pdf.text(`Generated on: ${new Date().toLocaleString()}`, 20, yPosition + 10);
        
        // Save the PDF
        const fileName = `${doc.document_name.replace(/[^a-z0-9]/gi, '_')}_Archive.pdf`;
        pdf.save(fileName);
        
        showToast(`"${doc.document_name}" downloaded successfully as PDF!`, "success");
    }

    // Handle Download from Preview Modal
    function handleDownloadFromPreview(e) {
      const docId = e.target.dataset.docId;
      const doc = documentsData.find(d => String(d.id) === docId);
      if (!doc) {
        showToast("Document not found for download.", "error");
        return;
      }

      function buildSafeDownloadBase(d) {
        const type = (d.type || d.document_name || 'Document');
        const raw = d.date_archived || d.date_time || d.date || '';
        let stamp = '';
        const parsed = raw ? new Date(raw) : null;
        if (parsed && !isNaN(parsed.getTime())) {
          const pad = (n) => String(n).padStart(2, '0');
          stamp = `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}_${pad(parsed.getHours())}-${pad(parsed.getMinutes())}`;
        } else {
          stamp = String(Date.now());
        }
        return `${type}(${stamp})`.replace(/[^a-z0-9_\-().]/gi, '_');
      }

      const a = document.createElement('a');
      const base = buildSafeDownloadBase(doc);
      const ext = doc.file_ext ? ('.' + doc.file_ext) : inferExtFromIcon(doc.file_type_icon);
      a.href = `api/archive_download.php?id=${doc.id}&dl=1`;
      a.download = base + ext;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      showToast('Downloading secure archived file...', 'success');
    }

    function inferExtFromIcon(icon) {
      const t = (icon || '').toLowerCase();
      if (t === 'pdf') return '.pdf';
      if (t === 'doc' || t === 'docx') return '.docx';
      if (t === 'xls' || t === 'xlsx') return '.xlsx';
      if (t === 'txt') return '.txt';
      if (t === 'png' || t === 'jpg' || t === 'jpeg' || t === 'img') return '.png';
      return '.dat';
    }

    // Restore functionality removed per requirements


    // View toggling logic
    viewToggleButtons.forEach(button => {
      button.addEventListener('click', () => {
        viewToggleButtons.forEach(btn => btn.classList.remove('active-view'));
        button.classList.add('active-view');
        currentView = button.dataset.view;
        updateView();
      });
    });

    function updateView() {
      // Hide all view containers first
      document.getElementById('archiveTable').style.display = 'none';
      document.getElementById('archiveList').style.display = 'none';
      document.getElementById('archiveGrid').style.display = 'none';

      // Show the active view
      if (currentView === 'table') {
        document.getElementById('archiveTable').style.display = 'table';
      } else if (currentView === 'list') {
        document.getElementById('archiveList').style.display = 'flex'; // Use flex for list view
      } else if (currentView === 'grid') {
        document.getElementById('archiveGrid').style.display = 'grid'; // Use grid for grid view
      }
    }

    // Initial render when page loads
    document.addEventListener('DOMContentLoaded', () => {
      function __ocrDebug(msg, extra) { return; }

      // Ensure earlier page init errors don't prevent OCR toggle wiring.
      try {
        filteredDocuments = getFilteredDocuments(); // Apply any default filters
        renderDocuments();
        updateView(); // Ensure the correct initial view is displayed
        refreshSelectionUI(); // Initialize button state
        // Wire up preview/delete/download/restore handlers
        addEventListenersToButtons();
      } catch (e) {
        __ocrDebug('Main init threw (continuing)', { message: String(e && e.message ? e.message : e) });
        console.error(e);
      }

      // Check for status messages from PHP redirection
      const urlParams = new URLSearchParams(window.location.search);
      const status = urlParams.get('status');
      if (status) {
          if (status === 'added' || status === 'archived') {
              showToast('Document archived successfully!', 'success');
          } else if (status === 'updated') {
              showToast('Document updated successfully!', 'success');
          } else if (status === 'deleted') {
              showToast('Document deleted successfully!', 'success');
          } else if (status === 'error' || status === 'delete_error') {
              showToast('An error occurred. Please try again.', 'error');
          }
          // Remove status parameter from URL to prevent re-showing toast on refresh
          urlParams.delete('status');
          const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
          window.history.replaceState({}, document.title, newUrl);
      }
      
      // ============================================
      // OCR SEARCH FUNCTIONALITY
      // ============================================
      const ocrSearchBtn = document.getElementById('ocrSearchBtn');
      const ocrSearchResults = document.getElementById('ocrSearchResults');
      let ocrSearchMode = false;
      let ocrSearchTimeout = null;

      // OCR debug removed
      
      if (ocrSearchBtn) {
        ocrSearchBtn.addEventListener('click', (e) => {
          if (e) {
            e.preventDefault();
            e.stopPropagation();
          }
          ocrSearchMode = !ocrSearchMode;
          window.__archiveOcrSearchMode = ocrSearchMode;
          ocrSearchBtn.classList.toggle('active', ocrSearchMode);

          if (archiveSearchInput) {
            archiveSearchInput.placeholder = ocrSearchMode ? 'Search document content (OCR)...' : 'Search archived documents...';
          }

          __ocrDebug('OCR toggled', { ocrSearchMode });
          
          if (ocrSearchMode && archiveSearchInput && archiveSearchInput.value.trim().length >= 2) {
            performOcrSearch(archiveSearchInput.value.trim());
          } else {
            // Leaving OCR mode (or query too short) restores normal filtering.
            window.__archiveOcrResultDocIds = null;
            filteredDocuments = getFilteredDocuments();
            currentPage = 1;
            renderDocuments();
            if (ocrSearchResults) ocrSearchResults.style.display = 'none';
          }
        });
      } else {
        __ocrDebug('OCR button not found (check id=ocrSearchBtn)');
      }
      
      // Perform OCR search against server
      async function performOcrSearch(query) {
        if (!ocrSearchMode || query.length < 2) {
          window.__archiveOcrResultDocIds = null;
          filteredDocuments = getFilteredDocuments();
          currentPage = 1;
          renderDocuments();
          if (ocrSearchResults) ocrSearchResults.style.display = 'none';
          return;
        }

        if (!ocrSearchResults) return;
        ocrSearchResults.style.display = 'block';
        ocrSearchResults.innerHTML = '<div class="ocr-searching"><i class="fas fa-spinner fa-spin"></i> Searching document content...</div>';
        
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('action', 'ocr_search');
          url.searchParams.set('q', query);
          url.searchParams.set('limit', '10');

          __ocrDebug('OCR search request', { url: url.toString() });

          const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
          });

          const raw = await response.text();
          let data;
          try {
            data = JSON.parse(raw);
          } catch (e) {
            __ocrDebug('OCR search non-JSON response', {
              status: response.status,
              ok: response.ok,
              redirected: response.redirected,
              finalUrl: response.url,
              preview: String(raw || '').slice(0, 240),
            });
            throw e;
          }

          if (!response.ok) {
            __ocrDebug('OCR search HTTP error', { status: response.status, body: data });
            ocrSearchResults.innerHTML = '<div class="ocr-no-results">Search failed. Please try again.</div>';
            return;
          }
          
          if (!data.success) {
            ocrSearchResults.innerHTML = '<div class="ocr-no-results">Search failed. Please try again.</div>';
            return;
          }
          
          if (data.results.length === 0) {
            window.__archiveOcrResultDocIds = new Set();
            filteredDocuments = getFilteredDocuments();
            currentPage = 1;
            renderDocuments();
            ocrSearchResults.innerHTML = '<div class="ocr-no-results"><i class="fas fa-search"></i> No documents found matching "' + escapeHtml(query) + '"</div>';
            return;
          }

          window.__archiveOcrResultDocIds = new Set((data.results || []).map(r => String(r.id)));
          filteredDocuments = getFilteredDocuments();
          currentPage = 1;
          renderDocuments();
          
          let html = '';
          data.results.forEach(result => {
            const snippet = result.snippet || '';
            const highlightedSnippet = highlightSearchTerms(snippet, query);
            const pagesInfo = result.matching_pages && result.matching_pages.length > 0 
              ? `<div class="ocr-result-pages"><i class="fas fa-file-alt"></i> Found on page ${result.matching_pages.join(', ')}</div>` 
              : '';
            
            html += `
              <div class="ocr-result-item" onclick="window.scrollToArchiveRow(${result.id}); window.hideArchiveOcrResults();">
                <div class="ocr-result-header">
                  <span class="ocr-result-type">${escapeHtml(result.type || 'Document')}</span>
                  <span class="ocr-result-meta">${escapeHtml(result.department || '')}</span>
                </div>
                <div class="ocr-result-meta">${escapeHtml(result.name || '')}</div>
                ${snippet ? `<div class="ocr-result-snippet">${highlightedSnippet}</div>` : ''}
                ${pagesInfo}
              </div>
            `;
          });
          
          ocrSearchResults.innerHTML = html;
          
        } catch (error) {
          console.error('OCR search error:', error);
          ocrSearchResults.innerHTML = '<div class="ocr-no-results">Search error. Please try again.</div>';
        }
      }
      
      // Helper to highlight search terms in snippet
      function highlightSearchTerms(text, query) {
        const escaped = escapeHtml(text);
        const terms = query.toLowerCase().split(/\s+/).filter(t => t.length >= 2);
        let result = escaped;
        terms.forEach(term => {
          const regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
          result = result.replace(regex, '<mark>$1</mark>');
        });
        return result;
      }
      
      // Helper to escape HTML
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
      
      // Scroll to document row and highlight it when clicking OCR result
      function hideArchiveOcrResults() {
        const el = document.getElementById('ocrSearchResults');
        if (el) el.style.display = 'none';
      }

      function waitForElement(selector, timeoutMs) {
        const started = Date.now();
        return new Promise(resolve => {
          const tick = () => {
            const el = document.querySelector(selector);
            if (el) return resolve(el);
            if ((Date.now() - started) >= timeoutMs) return resolve(null);
            setTimeout(tick, 50);
          };
          tick();
        });
      }

      async function scrollToArchiveRow(docId) {
        window.__archiveOcrSearchMode = true;
        window.__archiveOcrResultDocIds = new Set([String(docId)]);
        filteredDocuments = getFilteredDocuments();
        currentPage = 1;
        renderDocuments();

        const row = await waitForElement(`tr[data-doc-id="${docId}"]`, 1500);
        if (row) {
          row.scrollIntoView({ behavior: 'smooth', block: 'center' });
          row.classList.add('ocr-highlight-row');
          setTimeout(() => {
            row.classList.remove('ocr-highlight-row');
          }, 3000);
        } else if (typeof viewArchiveDocument === 'function') {
          viewArchiveDocument(docId);
        }
      }

      // Inline onclick handlers require globals.
      window.scrollToArchiveRow = scrollToArchiveRow;
      window.hideArchiveOcrResults = hideArchiveOcrResults;
      
      // Trigger OCR search on input when in OCR mode
      archiveSearchInput.addEventListener('input', () => {
        if (ocrSearchMode) {
          clearTimeout(ocrSearchTimeout);
          ocrSearchTimeout = setTimeout(() => {
            performOcrSearch(archiveSearchInput.value.trim());
          }, 300);
        }
      });
      
      // Close OCR results when clicking outside
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-wrap')) {
          ocrSearchResults.style.display = 'none';
        }
      });
      
      // Show OCR results on focus if in OCR mode
      archiveSearchInput.addEventListener('focus', () => {
        if (ocrSearchMode && archiveSearchInput.value.trim().length >= 2) {
          performOcrSearch(archiveSearchInput.value.trim());
        }
      });
    });

    // Notifications behavior is provided by partials/notifications.php
    // (Do not override window.loadNotifications here.)
    document.addEventListener('DOMContentLoaded', () => {
      if (typeof window.loadSidebarBadges === 'function') {
        window.loadSidebarBadges();
        setInterval(window.loadSidebarBadges, 30000);
      }
    });
  </script>
</body>
</html>
