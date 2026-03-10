<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Use shared DB connection
require_once __DIR__ . '/../db_connect.php'; // provides $conn (mysqli)
require_once __DIR__ . '/../firestore_client.php';

function stats_table_exists_mysqli($conn, $tableName) {
  $name = $conn->real_escape_string($tableName);
  $res = $conn->query("SHOW TABLES LIKE '{$name}'");
  if (!$res) return false;
  $ok = ($res->num_rows > 0);
  $res->free();
  return $ok;
}

function stats_column_exists_mysqli($conn, $tableName, $columnName) {
  $t = $conn->real_escape_string($tableName);
  $c = $conn->real_escape_string($columnName);
  $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  if (!$res) return false;
  $ok = ($res->num_rows > 0);
  $res->free();
  return $ok;
}

function stats_insert_generation_row($conn, $type, $department, $status, $fileTypeIcon) {
  // Best-effort only; never fail the main API because of reporting insert.
  try {
    if (!stats_table_exists_mysqli($conn, 'stats')) return;
    $hasDate = stats_column_exists_mysqli($conn, 'stats', 'date');
    $hasDateArchived = stats_column_exists_mysqli($conn, 'stats', 'date_archived');
    $hasDocument = stats_column_exists_mysqli($conn, 'stats', 'document');
    $hasType = stats_column_exists_mysqli($conn, 'stats', 'type');
    $docCol = $hasDocument ? 'document' : ($hasType ? 'type' : null);
    $dateCol = $hasDate ? 'date' : ($hasDateArchived ? 'date_archived' : null);
    if (!$docCol || !$dateCol) return;

    $sql = "INSERT INTO stats (`{$docCol}`, `department`, `status`, `{$dateCol}`, `file_type_icon`) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $dateVal = date('Y-m-d');
    $deptVal = (string)$department;
    $typeVal = (string)$type;
    $statusVal = (string)$status;
    $iconVal = (string)$fileTypeIcon;
    $stmt->bind_param('sssss', $typeVal, $deptVal, $statusVal, $dateVal, $iconVal);
    $stmt->execute();
    $stmt->close();
  } catch (Throwable $t) {
    // ignore
  }
}

function json_error($msg, $code = 400, $extra = null) {
  http_response_code($code);
  $out = ['success' => false, 'message' => $msg];
  if (is_array($extra)) {
    $out = array_merge($out, $extra);
  }
  echo json_encode($out);
  exit();
}

function rd_rebuild_routing_queue(mysqli $conn, int $trackId, string $prevHolder, string $newHolder): array {
  $out = ['routing_queue' => '', 'route_step' => 0];
  if ($trackId <= 0) return $out;

  @$conn->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS routing_queue TEXT DEFAULT NULL");
  @$conn->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS route_step INT DEFAULT 0");

  $curRQ = null;
  $sel = $conn->prepare("SELECT routing_queue FROM tracking WHERE id = ? LIMIT 1");
  if ($sel) {
    $sel->bind_param('i', $trackId);
    $sel->execute();
    $sel->bind_result($curRQ);
    $sel->fetch();
    $sel->close();
  }

  $parts = [];
  if (!empty($curRQ)) {
    $parts = array_values(array_filter(array_map(function ($s) {
      return strtoupper(trim((string)$s));
    }, explode(',', (string)$curRQ)), function ($s) { return $s !== ''; }));
  } else {
    $bf = $conn->prepare("SELECT from_holder, to_holder FROM document_history WHERE doc_id = ? AND action = 'route' ORDER BY id ASC");
    if ($bf) {
      $bf->bind_param('i', $trackId);
      if ($bf->execute()) {
        $res = $bf->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
          $fh = strtoupper(trim((string)($row['from_holder'] ?? '')));
          $th = strtoupper(trim((string)($row['to_holder'] ?? '')));
          if ($fh !== '' && !in_array($fh, $parts, true)) $parts[] = $fh;
          if ($th !== '' && !in_array($th, $parts, true)) $parts[] = $th;
        }
        if ($res) $res->free();
      }
      $bf->close();
    }
    $orig = null;
    $sorig = $conn->prepare("SELECT department FROM tracking WHERE id = ? LIMIT 1");
    if ($sorig) {
      $sorig->bind_param('i', $trackId);
      $sorig->execute();
      $sorig->bind_result($orig);
      $sorig->fetch();
      $sorig->close();
    }
    $origUp = strtoupper(trim((string)$orig));
    if ($origUp !== '' && !in_array($origUp, $parts, true)) {
      array_unshift($parts, $origUp);
    }
  }

  $prevUp = strtoupper(trim($prevHolder));
  $newUp = strtoupper(trim($newHolder));
  if ($prevUp !== '' && !in_array($prevUp, $parts, true)) $parts[] = $prevUp;
  if ($newUp !== '' && !in_array($newUp, $parts, true)) $parts[] = $newUp;

  $newRQ = implode(',', $parts);
  $idx = array_search($newUp, $parts, true);
  $newStep = ($idx !== false) ? (int)$idx : max(0, count($parts) - 1);

  $upd = $conn->prepare("UPDATE tracking SET routing_queue = ?, route_step = GREATEST(COALESCE(route_step,0), ?) WHERE id = ?");
  if ($upd) {
    $upd->bind_param('sii', $newRQ, $newStep, $trackId);
    $upd->execute();
    $upd->close();
  }

  $out['routing_queue'] = $newRQ;
  $out['route_step'] = $newStep;
  return $out;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Only POST allowed');

  if (!isset($conn) || !$conn || $conn->connect_error) {
    json_error('DB connect failed: ' . ($conn?->connect_error ?? 'no connection'));
  }

  // Required sender
  $sender_name = isset($_POST['sender_name']) ? trim($_POST['sender_name']) : '';
  $sender_department = isset($_POST['sender_department']) ? trim($_POST['sender_department']) : '';

  // Receiver: username preferred; department optional
  $receiver_username = isset($_POST['receiver_username']) ? trim($_POST['receiver_username']) : '';
  $receiver_department = isset($_POST['receiver_department']) ? trim($_POST['receiver_department']) : '';

  // Document identity
  $file_name = isset($_POST['file_name']) ? trim($_POST['file_name']) : '';
  $file_path = isset($_POST['file_path']) ? trim($_POST['file_path']) : '';
  $mobile_timestamp = isset($_POST['mobile_timestamp']) ? trim($_POST['mobile_timestamp']) : '';
  $type = isset($_POST['type']) ? trim($_POST['type']) : '';

  // Announcements are broadcast/acknowledge-only and should not be routed.
  if ($type !== '' && strpos(strtolower($type), 'announcement') !== false) {
    json_error('Announcements cannot be routed. Use Received/Acknowledge instead.', 400);
  }

  // ── Fixed Payroll Routing: HR → CBO → ACCOUNTING → CAO → CTO ──
  // If this is a Payroll document and no routing_queue was explicitly provided,
  // auto-assign the fixed chain so sequential routing kicks in.
  $payrollFixedRoute = ['HR', 'CBO', 'ACCOUNTING', 'CAO', 'CTO'];
  $isPayrollType = (stripos($type, 'payroll') !== false);

  // Optional
  $file_type_icon = isset($_POST['file_type_icon']) ? trim($_POST['file_type_icon']) : '';
  $file_size = isset($_POST['file_size']) ? trim($_POST['file_size']) : '';
  $end_location = isset($_POST['end_location']) ? trim($_POST['end_location']) : '';
  $next_department = isset($_POST['next_department']) ? trim($_POST['next_department']) : '';
  // Normalize status: default and "sent" both treated as "Pending" so current holder always appears as pending
  $status = isset($_POST['status']) && $_POST['status'] !== '' ? trim($_POST['status']) : 'Pending';
  if (strtolower($status) === 'sent') {
    $status = 'Pending';
  }
  $ocr_content = isset($_POST['ocr_content']) ? $_POST['ocr_content'] : null;
  $doc_hash = isset($_POST['doc_hash']) ? trim($_POST['doc_hash']) : null;
  $user_email = isset($_POST['receiver_email']) ? trim($_POST['receiver_email']) : null;
  $routing_queue = isset($_POST['routing_queue']) ? trim($_POST['routing_queue']) : '';
  // Optional explicit tracking id from client (dashboard/mobile recent activity)
  $tracking_id = isset($_POST['tracking_id']) ? trim($_POST['tracking_id']) : '';

  // Auto-set payroll routing queue if not already provided
  if ($isPayrollType && $routing_queue === '') {
    // Determine current position in the fixed chain based on sender/receiver
    $senderUpper = strtoupper($sender_department);
    $receiverUpper = strtoupper($receiver_department);
    $routing_queue = implode(',', $payrollFixedRoute);

    // If the document is being routed from a department in the chain,
    // validate the next stop matches the fixed route
    $senderIdx = array_search($senderUpper, $payrollFixedRoute);
    if ($senderIdx !== false && ($senderIdx + 1) < count($payrollFixedRoute)) {
      $expectedNext = $payrollFixedRoute[$senderIdx + 1];
      // Override receiver to enforce fixed routing
      if ($receiverUpper !== $expectedNext) {
        $receiver_department = $expectedNext;
      }
    }
  }
  // Optional notification id (activityId) as a fallback identifier
  $notification_id = isset($_POST['notification_id']) ? trim($_POST['notification_id']) : '';
  $debug = (isset($_POST['debug']) && (string)$_POST['debug'] === '1');

  $debug_in = null;
  if ($debug) {
    $debug_in = [
      'sender_name' => $sender_name,
      'sender_department' => $sender_department,
      'receiver_username' => $receiver_username,
      'receiver_department' => $receiver_department,
      'type' => $type,
      'file_name' => $file_name,
      'file_path' => $file_path,
      'tracking_id' => $tracking_id,
      'notification_id' => $notification_id,
      'mobile_timestamp' => $mobile_timestamp,
      'doc_hash' => $doc_hash,
      'end_location' => $end_location,
      'next_department' => $next_department,
    ];
  }

  // Routing identity requirement:
  // - sender_name, sender_department, file_name are always required
  // - document identifier can be EITHER mobile_timestamp OR tracking_id
  //   (tracking_id is the most reliable for the one-row-per-document model)
  if ($sender_name === '' || $sender_department === '' || $file_name === '') {
    json_error('Missing required fields', 400, $debug ? ['debug_in' => $debug_in] : null);
  }
  $has_tracking_id = ($tracking_id !== '' && ctype_digit($tracking_id) && (int)$tracking_id > 0);
  $has_notification_id = ($notification_id !== '' && ctype_digit($notification_id) && (int)$notification_id > 0);
  // If client only has a notification id (older notifications may be missing tracking_id/mobile_timestamp),
  // resolve identifiers from notifications before enforcing routing requirements.
  if ($has_notification_id && (!$has_tracking_id || $mobile_timestamp === '' || $file_path === '' || $end_location === '')) {
    $nid = (int)$notification_id;
    $nsel = $conn->prepare("SELECT tracking_id, mobile_timestamp, file_url, end_location, current_holder FROM notifications WHERE id = ? LIMIT 1");
    if ($nsel) {
      $nsel->bind_param('i', $nid);
      if ($nsel->execute()) {
        $nres = $nsel->get_result();
        if ($nres && ($nrow = $nres->fetch_assoc())) {
          $ntid = isset($nrow['tracking_id']) ? (int)$nrow['tracking_id'] : 0;
          $nmts = trim((string)($nrow['mobile_timestamp'] ?? ''));
          $nfurl = trim((string)($nrow['file_url'] ?? ''));
          $nend = trim((string)($nrow['end_location'] ?? ''));
          $nholder = trim((string)($nrow['current_holder'] ?? ''));

          if (!$has_tracking_id && $ntid > 0) {
            $tracking_id = (string)$ntid;
            $has_tracking_id = true;
          }
          if ($mobile_timestamp === '' && $nmts !== '') {
            $mobile_timestamp = $nmts;
          }
          if ($file_path === '' && $nfurl !== '') {
            $file_path = $nfurl;
          }
          // Prefer DB end_location/current_holder if client didn't provide them
          if ($end_location === '' && $nend !== '') {
            $end_location = $nend;
          }
          if ($current_holder === '' && $nholder !== '') {
            $current_holder = $nholder;
          }

          if ($debug) {
            $debug_in['resolved_from_notification'] = [
              'notification_id' => $nid,
              'tracking_id' => $tracking_id,
              'mobile_timestamp' => $mobile_timestamp,
              'file_path' => $file_path,
            ];
          }
        }
      }
      $nsel->close();
    }
  }

  if ($mobile_timestamp === '' && !$has_tracking_id && !$has_notification_id) {
    json_error('Missing required fields', 400, $debug ? ['debug_in' => $debug_in] : null);
  }
  if ($receiver_username === '' && $receiver_department === '') {
    json_error('Provide receiver_username or receiver_department', 400, $debug ? ['debug_in' => $debug_in] : null);
  }
  // Fallback department for tracking if not provided
  if ($receiver_department === '') {
    $receiver_department = $sender_department;
  }

  // For tracking, we want the current holder shown as the department, not the username
  $current_holder = $receiver_department;

  // Ensure doc_hash is always present; do not allow routing to wipe it with an empty value.
  if ($doc_hash === null || trim((string)$doc_hash) === '') {
    $canonical = strtolower(trim(
      (string)$type . '|' .
      (string)$sender_name . '|' .
      (string)$sender_department . '|' .
      (string)$receiver_department . '|' .
      (string)$file_name . '|' .
      (string)$file_path . '|' .
      (string)$mobile_timestamp . '|' .
      (string)$end_location
    ));
    $doc_hash = hash('sha256', $canonical);
  }

  // Try to UPDATE an existing tracking record for this document (one-row-per-document model)
  // CRITICAL: Always find the ORIGINAL tracking record created by the first department
  // We should NEVER create duplicate rows - always UPDATE the existing one
  $track_id = null;

  // 1) If client provides an explicit tracking_id, prefer that for updates (most reliable)
  if ($tracking_id !== '' && ctype_digit($tracking_id)) {
    $tid = (int)$tracking_id;
    if ($tid > 0) {
      $sel = $conn->prepare("SELECT id FROM tracking WHERE id = ? LIMIT 1");
      if ($sel) {
        $sel->bind_param('i', $tid);
        if ($sel->execute()) {
          $res = $sel->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $track_id = (int)$row['id'];
          }
        }
        $sel->close();
      }
    }
  }

  // 2) Fallback: use mobile_timestamp + doc_hash (more reliable than just mobile_timestamp)
  // This ensures we find the exact same document even if multiple documents share the same timestamp
  if ($track_id === null && $mobile_timestamp !== '' && $doc_hash !== null && $doc_hash !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? AND doc_hash = ? ORDER BY id ASC LIMIT 1");
    if ($sel) {
      $sel->bind_param('ss', $mobile_timestamp, $doc_hash);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
        }
      }
      $sel->close();
    }
  }

  // 3) Fallback: use mobile_timestamp alone (find the oldest record with this timestamp)
  // ORDER BY id ASC to get the ORIGINAL record (first one created)
  if ($track_id === null && $mobile_timestamp !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id ASC LIMIT 1");
    if ($sel) {
      $sel->bind_param('s', $mobile_timestamp);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
        }
      }
      $sel->close();
    }
  }

  // 4) Fallback: try to find by doc_hash alone (if available)
  // This helps when mobile_timestamp might have changed but doc_hash is stable
  if ($track_id === null && $doc_hash !== null && $doc_hash !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE doc_hash = ? ORDER BY id ASC LIMIT 1");
    if ($sel) {
      $sel->bind_param('s', $doc_hash);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
        }
      }
      $sel->close();
    }
  }

  // 5) Fallback: try to find by type + employee_name + end_location
  // This is very reliable because these three fields together uniquely identify a document route
  // The end_location should be the ORIGINAL one set by the first department
  if ($track_id === null && $type !== '' && $sender_name !== '' && $end_location !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE type = ? AND employee_name = ? AND end_location = ? ORDER BY id ASC LIMIT 1");
    if ($sel) {
      $sel->bind_param('sss', $type, $sender_name, $end_location);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
        }
      }
      $sel->close();
    }
  }

  // 5b) If lookup with end_location failed, try without end_location constraint
  // This helps when the end_location in POST might be wrong (e.g., current department instead of original)
  if ($track_id === null && $type !== '' && $sender_name !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE type = ? AND employee_name = ? ORDER BY id ASC LIMIT 1");
    if ($sel) {
      $sel->bind_param('ss', $type, $sender_name);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
          // If we found a record, update the end_location in POST to match the database
          // This ensures we use the correct original end_location
          $sel_end_check = $conn->prepare("SELECT end_location FROM tracking WHERE id = ? LIMIT 1");
          if ($sel_end_check) {
            $sel_end_check->bind_param('i', $track_id);
            if ($sel_end_check->execute()) {
              $res_end_check = $sel_end_check->get_result();
              if ($row_end_check = $res_end_check->fetch_assoc()) {
                $db_end_location = trim($row_end_check['end_location'] ?? '');
                if ($db_end_location !== '' && $db_end_location !== $end_location) {
                  error_log("[route_document] Corrected end_location: POST had '$end_location', DB has '$db_end_location'. Using DB value.");
                  $end_location = $db_end_location; // Use the correct original value
                }
              }
            }
            $sel_end_check->close();
          }
        }
      }
      $sel->close();
    }
  }

  // 6) Fallback: try to find by type + file_path + mobile_timestamp (for documents without doc_hash)
  if ($track_id === null && $mobile_timestamp !== '' && $file_path !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? AND file_path = ? ORDER BY id ASC LIMIT 1");
    if ($sel) {
      $sel->bind_param('ss', $mobile_timestamp, $file_path);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
        }
      }
      $sel->close();
    }
  }

  // 7) Last resort: try to find by type + employee_name + date_submitted (within last 7 days)
  // This helps when end_location might have been changed incorrectly
  if ($track_id === null && $type !== '' && $sender_name !== '') {
    $sel = $conn->prepare("SELECT id FROM tracking WHERE type = ? AND employee_name = ? AND date_submitted >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 1");
    if ($sel) {
      $sel->bind_param('ss', $type, $sender_name);
      if ($sel->execute()) {
        $res = $sel->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
          $track_id = (int)$row['id'];
        }
      }
      $sel->close();
    }
  }

  // 8) Extra fallback: match by filename (basename) if file_path is a device-local path
  // This helps recover older notifications that used local paths while tracking rows used server paths.
  if ($track_id === null && $file_path !== '') {
    $normalized = str_replace('\\', '/', $file_path);
    $base = basename($normalized);
    if ($base !== '' && $base !== $normalized && strlen($base) >= 8) {
      $sel = $conn->prepare("SELECT id FROM tracking WHERE file_path LIKE CONCAT('%', ?, '%') ORDER BY id DESC LIMIT 1");
      if ($sel) {
        $sel->bind_param('s', $base);
        if ($sel->execute()) {
          $res = $sel->get_result();
          if ($res && ($row = $res->fetch_assoc())) {
            $track_id = (int)$row['id'];
          }
        }
        $sel->close();
      }
    }
  }
  
  // Log if we couldn't find an existing record (for debugging)
  if ($track_id === null) {
    error_log("[route_document] WARNING: Could not find existing tracking record. type=$type, sender=$sender_name, mobile_timestamp=" . ($mobile_timestamp ?? 'null') . ", doc_hash=" . ($doc_hash ?? 'null') . ", tracking_id=" . ($tracking_id ?? 'null') . ", end_location=$end_location. Will create new record.");
  } else {
    error_log("[route_document] SUCCESS: Found existing tracking record id=$track_id for type=$type, sender=$sender_name");
  }

  // OPTION A (simple, no duplicates): routing must NEVER create new tracking rows.
  // If we are routing between departments and we can't find an existing tracking record,
  // fail fast so the client can fix the missing tracking_id instead of duplicating rows.
  $is_routing = ($sender_department !== '' && $receiver_department !== '' &&
                 strcasecmp($sender_department, $receiver_department) !== 0);
  if ($is_routing && $track_id === null) {
    json_error('Cannot route: missing tracking record (tracking_id not found). Ask sender to upload to Tracking first or include tracking_id in the route payload.');
  }

  if ($track_id) {
    // Update existing row: move document to new department/holder and status
    // CRITICAL: NEVER update end_location during routing - it must remain what the first department set
    // Get the original end_location and current state to use in notification and history
    $original_end_location = '';
    $previous_holder = '';
    $previous_status = '';
    $sel_prev = $conn->prepare("SELECT end_location, current_holder, status FROM tracking WHERE id = ? LIMIT 1");
    if ($sel_prev) {
      $sel_prev->bind_param('i', $track_id);
      if ($sel_prev->execute()) {
        $res_prev = $sel_prev->get_result();
        if ($row_prev = $res_prev->fetch_assoc()) {
          $original_end_location = trim($row_prev['end_location'] ?? '');
          $previous_holder = trim($row_prev['current_holder'] ?? '');
          $previous_status = trim($row_prev['status'] ?? '');
        }
      }
      $sel_prev->close();
    }
    
    // Update ONLY: current_holder, department, status, and file-related fields
    // DO NOT update end_location - it stays as originally set by the first department
    $upd = $conn->prepare("UPDATE tracking SET current_holder = ?, department = ?, status = ?, file_type_icon = ?, file_size = ?, file_path = ?, doc_hash = ? WHERE id = ?");
    if (!$upd) json_error('Update prepare failed: ' . $conn->error);
    $upd->bind_param(
      'sssssssi',
      $current_holder,
      $receiver_department,
      $status,
      $file_type_icon,
      $file_size,
      $file_path,
      $doc_hash,
      $track_id
    );
    if (!$upd->execute()) json_error('Update failed: ' . $upd->error);
    $upd->close();

    try {
      firestore_upsert_tracking((string)$track_id, [
        'id' => (int)$track_id,
        'type' => (string)$type,
        'employee_name' => (string)$sender_name,
        'department' => (string)$receiver_department,
        'current_holder' => (string)$current_holder,
        'end_location' => (string)$original_end_location,
        'status' => (string)$status,
        'file_type_icon' => (string)$file_type_icon,
        'file_size' => (string)$file_size,
        'file_path' => (string)$file_path,
        'mobile_timestamp' => (string)$mobile_timestamp,
        'doc_hash' => (string)$doc_hash,
        'updatedAt' => (int)round(microtime(true) * 1000),
      ]);
    } catch (Throwable $t) {
      // ignore
    }
    
    // LOG ROUTING TO document_history TABLE
    // This creates a node in the timeline for each routing action
    $actor_user_id = 0; // Will try to resolve from sender_name
    $sel_actor = $conn->prepare("SELECT id FROM control WHERE user = ? LIMIT 1");
    if ($sel_actor) {
      $sel_actor->bind_param('s', $sender_name);
      if ($sel_actor->execute()) {
        $res_actor = $sel_actor->get_result();
        if ($row_actor = $res_actor->fetch_assoc()) {
          $actor_user_id = (int)$row_actor['id'];
        }
      }
      $sel_actor->close();
    }
    
    // Insert routing history record
    $hist = $conn->prepare("INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, 'route', ?, ?, ?, ?, ?)");
    if ($hist) {
      $hist->bind_param('iissss', $track_id, $actor_user_id, $previous_status, $status, $previous_holder, $current_holder);
      $hist->execute();
      $hist->close();
    }

    $routeMeta = rd_rebuild_routing_queue($conn, (int)$track_id, (string)$previous_holder, (string)$current_holder);

    try {
      firestore_upsert_tracking((string)$track_id, [
        'id' => (int)$track_id,
        'current_holder' => (string)$current_holder,
        'status' => (string)$status,
        'routing_queue' => (string)($routeMeta['routing_queue'] ?? ''),
        'route_step' => (int)($routeMeta['route_step'] ?? 0),
        'updatedAt' => (int)round(microtime(true) * 1000),
      ]);
    } catch (Throwable $t) {
      // ignore
    }
    
    // Use the original end_location for notification (not the client-provided one)
    $end_location = $original_end_location;

    // If the client did not include mobile_timestamp but we found the tracking row,
    // backfill mobile_timestamp from the tracking table so downstream notifications
    // and receivers can route without losing the identifier.
    if ($mobile_timestamp === '') {
      $sel_ts = $conn->prepare("SELECT mobile_timestamp FROM tracking WHERE id = ? LIMIT 1");
      if ($sel_ts) {
        $sel_ts->bind_param('i', $track_id);
        if ($sel_ts->execute()) {
          $res_ts = $sel_ts->get_result();
          if ($res_ts && ($row_ts = $res_ts->fetch_assoc())) {
            $db_ts = trim($row_ts['mobile_timestamp'] ?? '');
            if ($db_ts !== '') {
              $mobile_timestamp = $db_ts;
            }
          }
        }
        $sel_ts->close();
      }
    }
  } else {
    // No existing row found: allow insert ONLY for first creation (same dept / not routing).
    // SAFETY CHECK: Before inserting, do one more comprehensive check to see if a record exists
    // This prevents race conditions where multiple routing requests happen simultaneously
    $should_insert = true;
    
    // Try multiple strategies one more time before giving up
    $final_checks = [
      ['mobile_timestamp', $mobile_timestamp],
      ['doc_hash', $doc_hash],
      ['type+employee+end', ['type' => $type, 'employee' => $sender_name, 'end' => $end_location]],
    ];
    
    foreach ($final_checks as $check) {
      if ($track_id !== null) break; // Already found
      
      if ($check[0] === 'mobile_timestamp' && $check[1] !== '') {
        $final_check = $conn->prepare("SELECT id FROM tracking WHERE mobile_timestamp = ? ORDER BY id ASC LIMIT 1");
        if ($final_check) {
          $final_check->bind_param('s', $check[1]);
          if ($final_check->execute()) {
            $res_final = $final_check->get_result();
            if ($res_final && ($row_final = $res_final->fetch_assoc())) {
              $track_id = (int)$row_final['id'];
              $should_insert = false;
            }
          }
          $final_check->close();
        }
      } elseif ($check[0] === 'doc_hash' && $check[1] !== null && $check[1] !== '') {
        $final_check = $conn->prepare("SELECT id FROM tracking WHERE doc_hash = ? ORDER BY id ASC LIMIT 1");
        if ($final_check) {
          $final_check->bind_param('s', $check[1]);
          if ($final_check->execute()) {
            $res_final = $final_check->get_result();
            if ($res_final && ($row_final = $res_final->fetch_assoc())) {
              $track_id = (int)$row_final['id'];
              $should_insert = false;
            }
          }
          $final_check->close();
        }
      } elseif ($check[0] === 'type+employee+end' && is_array($check[1])) {
        $t = $check[1]['type'] ?? '';
        $e = $check[1]['employee'] ?? '';
        $end = $check[1]['end'] ?? '';
        // First try with end_location
        if ($t !== '' && $e !== '' && $end !== '') {
          $final_check = $conn->prepare("SELECT id FROM tracking WHERE type = ? AND employee_name = ? AND end_location = ? ORDER BY id ASC LIMIT 1");
          if ($final_check) {
            $final_check->bind_param('sss', $t, $e, $end);
            if ($final_check->execute()) {
              $res_final = $final_check->get_result();
              if ($res_final && ($row_final = $res_final->fetch_assoc())) {
                $track_id = (int)$row_final['id'];
                $should_insert = false;
              }
            }
            $final_check->close();
          }
        }
        // If that failed, try without end_location constraint
        if ($track_id === null && $t !== '' && $e !== '') {
          $final_check2 = $conn->prepare("SELECT id FROM tracking WHERE type = ? AND employee_name = ? ORDER BY id ASC LIMIT 1");
          if ($final_check2) {
            $final_check2->bind_param('ss', $t, $e);
            if ($final_check2->execute()) {
              $res_final2 = $final_check2->get_result();
              if ($res_final2 && ($row_final2 = $res_final2->fetch_assoc())) {
                $track_id = (int)$row_final2['id'];
                $should_insert = false;
                // Update end_location to match database
                $sel_end_correct = $conn->prepare("SELECT end_location FROM tracking WHERE id = ? LIMIT 1");
                if ($sel_end_correct) {
                  $sel_end_correct->bind_param('i', $track_id);
                  if ($sel_end_correct->execute()) {
                    $res_end_correct = $sel_end_correct->get_result();
                    if ($row_end_correct = $res_end_correct->fetch_assoc()) {
                      $db_end = trim($row_end_correct['end_location'] ?? '');
                      if ($db_end !== '') {
                        $end_location = $db_end;
                      }
                    }
                  }
                  $sel_end_correct->close();
                }
              }
            }
            $final_check2->close();
          }
        }
      }
    }
    
    // If we found an existing record in the final check, update it instead of inserting
    if ($track_id !== null && !$should_insert) {
      $original_end_location = '';
      $previous_holder = '';
      $previous_status = '';
      $sel_prev = $conn->prepare("SELECT end_location, current_holder, status FROM tracking WHERE id = ? LIMIT 1");
      if ($sel_prev) {
        $sel_prev->bind_param('i', $track_id);
        if ($sel_prev->execute()) {
          $res_prev = $sel_prev->get_result();
          if ($row_prev = $res_prev->fetch_assoc()) {
            $original_end_location = trim($row_prev['end_location'] ?? '');
            $previous_holder = trim($row_prev['current_holder'] ?? '');
            $previous_status = trim($row_prev['status'] ?? '');
          }
        }
        $sel_prev->close();
      }
      
      $upd = $conn->prepare("UPDATE tracking SET current_holder = ?, department = ?, status = ?, file_type_icon = ?, file_size = ?, file_path = ?, doc_hash = ? WHERE id = ?");
      if (!$upd) json_error('Update prepare failed: ' . $conn->error);
      $upd->bind_param(
        'sssssssi',
        $current_holder,
        $receiver_department,
        $status,
        $file_type_icon,
        $file_size,
        $file_path,
        $doc_hash,
        $track_id
      );
      if (!$upd->execute()) json_error('Update failed: ' . $upd->error);
      $upd->close();
      
      // LOG ROUTING TO document_history TABLE
      $actor_user_id = 0;
      $sel_actor = $conn->prepare("SELECT id FROM control WHERE user = ? LIMIT 1");
      if ($sel_actor) {
        $sel_actor->bind_param('s', $sender_name);
        if ($sel_actor->execute()) {
          $res_actor = $sel_actor->get_result();
          if ($row_actor = $res_actor->fetch_assoc()) {
            $actor_user_id = (int)$row_actor['id'];
          }
        }
        $sel_actor->close();
      }
      
      // Insert routing history record
      $hist = $conn->prepare("INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder) VALUES (?, 'route', ?, ?, ?, ?, ?)");
      if ($hist) {
        $hist->bind_param('iissss', $track_id, $actor_user_id, $previous_status, $status, $previous_holder, $current_holder);
        $hist->execute();
        $hist->close();
      }

      $routeMeta = rd_rebuild_routing_queue($conn, (int)$track_id, (string)$previous_holder, (string)$current_holder);

      try {
        firestore_upsert_tracking((string)$track_id, [
          'id' => (int)$track_id,
          'current_holder' => (string)$current_holder,
          'status' => (string)$status,
          'routing_queue' => (string)($routeMeta['routing_queue'] ?? ''),
          'route_step' => (int)($routeMeta['route_step'] ?? 0),
          'updatedAt' => (int)round(microtime(true) * 1000),
        ]);
      } catch (Throwable $t) {
        // ignore
      }
      
      $end_location = $original_end_location;
    } elseif ($should_insert) {
      // Only insert if we truly don't have an existing record (first creation)
      $date_submitted = date('Y-m-d');

      // Guarantee doc_hash so tracking.php list filter includes this row.
      // If client didn't send one, compute stable identity: type + employee_name + end_location.
      if (!isset($doc_hash) || trim((string)$doc_hash) === '') {
        $identity_key = strtolower(trim((string)$type . '|' . (string)$sender_name . '|' . (string)$end_location));
        $doc_hash = hash('sha256', $identity_key);
      }

      // Guarantee created_at (some deployments rely on it for ordering/range filters)
      // Also store routing_queue if provided (for sequential memo routing)
      @$conn->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS routing_queue TEXT DEFAULT NULL");
      @$conn->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS route_step INT DEFAULT 0");

      $sql = "INSERT INTO tracking (type, employee_name, date_submitted, current_holder, end_location, status, department, file_type_icon, ocr_content, mobile_timestamp, file_size, user_email, file_path, doc_hash, routing_queue, route_step, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW())";
      $stmt = $conn->prepare($sql);
      if (!$stmt) json_error('Prepare failed: ' . $conn->error);
      $rqVal = ($routing_queue !== '') ? $routing_queue : null;
      $stmt->bind_param(
        'sssssssssssssss',
        $type,
        $sender_name,
        $date_submitted,
        $current_holder,
        $end_location,
        $status,
        $receiver_department,
        $file_type_icon,
        $ocr_content,
        $mobile_timestamp,
        $file_size,
        $user_email,
        $file_path,
        $doc_hash,
        $rqVal
      );
      if (!$stmt->execute()) json_error('Execute failed: ' . $stmt->error);
      $track_id = $stmt->insert_id;
      $stmt->close();

      try {
        firestore_upsert_tracking((string)$track_id, [
          'id' => (int)$track_id,
          'type' => (string)$type,
          'employee_name' => (string)$sender_name,
          'department' => (string)$receiver_department,
          'current_holder' => (string)$current_holder,
          'end_location' => (string)$end_location,
          'status' => (string)$status,
          'date_submitted' => (string)$date_submitted,
          'file_type_icon' => (string)$file_type_icon,
          'file_size' => (string)$file_size,
          'file_path' => (string)$file_path,
          'mobile_timestamp' => (string)$mobile_timestamp,
          'doc_hash' => (string)$doc_hash,
          'createdAt' => (int)round(microtime(true) * 1000),
          'updatedAt' => (int)round(microtime(true) * 1000),
        ]);
      } catch (Throwable $t) {
        // ignore
      }
      error_log("[route_document] Created NEW tracking record id=$track_id for type=$type, sender=$sender_name, end_location=$end_location");

      // Persist reporting snapshot for Documents Generation Report (best-effort)
      // Use sender_department as the origin department for generation.
      stats_insert_generation_row($conn, $type, $sender_department, $status, $file_type_icon);
      
      // LOG CREATE TO document_history TABLE
      // This creates the first node in the timeline
      $actor_user_id = 0;
      $sel_actor = $conn->prepare("SELECT id FROM control WHERE user = ? LIMIT 1");
      if ($sel_actor) {
        $sel_actor->bind_param('s', $sender_name);
        if ($sel_actor->execute()) {
          $res_actor = $sel_actor->get_result();
          if ($row_actor = $res_actor->fetch_assoc()) {
            $actor_user_id = (int)$row_actor['id'];
          }
        }
        $sel_actor->close();
      }
      
      // Insert create history record
      $hist = $conn->prepare("INSERT INTO document_history (doc_id, action, actor_user_id, to_status, from_holder, to_holder) VALUES (?, 'create', ?, ?, ?, ?)");
      if ($hist) {
        $hist->bind_param('iisss', $track_id, $actor_user_id, $status, $sender_department, $current_holder);
        $hist->execute();
        $hist->close();
      }
    }
  }

  // Create a notification for the receiver.
  // IMPORTANT: do NOT rely on localhost HTTP calls (breaks on real devices / different hosts).
  // Insert directly into DB so web + mobile notifications always appear.
  try {
    $conn->query("CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      content TEXT,
      type VARCHAR(64) DEFAULT 'mobile_message',
      recipient_username VARCHAR(128) NOT NULL,
      sender_username VARCHAR(128) DEFAULT NULL,
      department VARCHAR(128) DEFAULT NULL,
      recipient_department VARCHAR(128) DEFAULT NULL,
      status VARCHAR(32) DEFAULT 'new',
      file_url TEXT DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(recipient_username),
      INDEX(recipient_department),
      INDEX(type),
      INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Best-effort migrations (older installs)
    @$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS tracking_id INT NULL");
    @$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS mobile_timestamp VARCHAR(128) NULL");
    @$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS end_location VARCHAR(128) NULL");
    @$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS current_holder VARCHAR(128) NULL");
    @$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS doc_status VARCHAR(64) NULL");
    @$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS file_url TEXT NULL");

    $notifType = ($type !== '' ? $type : 'document_upload');
    $notifTitle = ($type !== '' ? $type : 'Document');
    // Dashboard parsing expects the doc type as the first token before '•'
    $notifContent = $notifTitle . ' • ' . ($sender_name !== '' ? $sender_name : 'system');
    $recipientUser = $receiver_username;
    // notifications schema requires recipient_username; when routing by dept, use the dept as a stable key.
    if (trim($recipientUser) === '') {
      $recipientUser = $receiver_department;
    }

    if ($insN = $conn->prepare("INSERT INTO notifications (title, content, type, recipient_username, sender_username, department, recipient_department, status, file_url, tracking_id, mobile_timestamp, end_location, current_holder, doc_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ? )")) {
      $dept = $sender_department;
      $fileUrl = $file_path;
      $tid = (int)$track_id;
      $mts = (string)$mobile_timestamp;
      $end = (string)$end_location;
      $holder = (string)$current_holder;
      $docStatus = (string)$status;
      $insN->bind_param('ssssssssisssss', $notifTitle, $notifContent, $notifType, $recipientUser, $sender_name, $dept, $receiver_department, $fileUrl, $tid, $mts, $end, $holder, $docStatus);
      $insN->execute();
      $newNotifId = $insN->insert_id;
      $insN->close();

      // Mirror to Firestore (best-effort) so realtime listeners can pick it up if used
      try {
        if (function_exists('firestore_upsert_document') && $newNotifId > 0) {
          firestore_upsert_document('notifications', (string)$newNotifId, [
            'id' => (int)$newNotifId,
            'title' => (string)$notifTitle,
            'content' => (string)$notifContent,
            'type' => (string)$notifType,
            'recipient_username' => (string)$recipientUser,
            'recipient_department' => (string)$receiver_department,
            'sender_username' => (string)$sender_name,
            'department' => (string)$dept,
            'status' => 'new',
            'file_url' => (string)$fileUrl,
            'tracking_id' => (int)$tid,
            'mobile_timestamp' => (string)$mts,
            'end_location' => (string)$end,
            'current_holder' => (string)$holder,
            'doc_status' => (string)$docStatus,
            'createdAt' => (int)round(microtime(true) * 1000),
          ]);
        }
      } catch (Throwable $t) {
        // ignore
      }
    }
  } catch (Throwable $e) {
    // best-effort only
  }

  // ─── Sequential Memo Routing Queue Auto-Advance ───
  // After updating a tracking row, check if there's a routing_queue.
  // If the current status is Completed/In Review and there's a next department
  // in the queue, auto-advance the holder to that department.
  if ($track_id) {
    try {
      @$conn->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS routing_queue TEXT DEFAULT NULL");
      @$conn->query("ALTER TABLE tracking ADD COLUMN IF NOT EXISTS route_step INT DEFAULT 0");

      $qSel = $conn->prepare("SELECT routing_queue, route_step, current_holder, status FROM tracking WHERE id = ? LIMIT 1");
      if ($qSel) {
        $qSel->bind_param('i', $track_id);
        if ($qSel->execute()) {
          $qRes = $qSel->get_result();
          if ($qRes && ($qRow = $qRes->fetch_assoc())) {
            $rq = trim($qRow['routing_queue'] ?? '');
            $step = (int)($qRow['route_step'] ?? 0);
            $curHolder = trim($qRow['current_holder'] ?? '');
            $curStatus = strtolower(trim($qRow['status'] ?? ''));

            if ($rq !== '') {
              $depts = array_map('trim', explode(',', $rq));
              // If current holder matches the current step dept and status indicates
              // the department is done (Completed or routing happened), advance
              if ($step < count($depts) - 1) {
                $expectedDept = $depts[$step] ?? '';
                // Auto-advance when the current holder matches expected and doc was routed
                if (strcasecmp($curHolder, $expectedDept) === 0 || $is_routing) {
                  $nextStep = $step + 1;
                  $nextDept = $depts[$nextStep] ?? '';
                  if ($nextDept !== '') {
                    $advStatus = 'Pending';
                    $advUpd = $conn->prepare("UPDATE tracking SET current_holder = ?, department = ?, status = ?, route_step = ? WHERE id = ?");
                    if ($advUpd) {
                      $advUpd->bind_param('sssii', $nextDept, $nextDept, $advStatus, $nextStep, $track_id);
                      $advUpd->execute();
                      $advUpd->close();
                      error_log("[route_document] AUTO-ADVANCE: Memo id=$track_id step $step→$nextStep, next dept=$nextDept");

                      // Log auto-advance to document_history
                      $histAdv = $conn->prepare("INSERT INTO document_history (doc_id, action, actor_user_id, from_status, to_status, from_holder, to_holder, notes) VALUES (?, 'route', 0, ?, ?, ?, ?, 'Auto-advanced by sequential routing queue')");
                      if ($histAdv) {
                        $histAdv->bind_param('issss', $track_id, $curStatus, $advStatus, $curHolder, $nextDept);
                        $histAdv->execute();
                        $histAdv->close();
                      }
                    }
                  }
                }
              }
            }
          }
        }
        $qSel->close();
      }
    } catch (Throwable $t) {
      error_log("[route_document] routing_queue check error: " . $t->getMessage());
    }
  }

  echo json_encode(['success' => true, 'tracking_id' => $track_id]);
  $conn->close();
} catch (Exception $e) {
  json_error($e->getMessage());
}
