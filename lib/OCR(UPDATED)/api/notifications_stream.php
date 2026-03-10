<?php
// Real-time notifications via Server-Sent Events (SSE)
// Filters by current session's username or department if available

@ini_set('zlib.output_compression', 0);
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', 1);
@set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // for nginx if any

session_start();
$uname = isset($_SESSION['user_username']) ? trim((string)$_SESSION['user_username']) : '';
$udept = isset($_SESSION['user_department']) ? trim((string)$_SESSION['user_department']) : '';
session_write_close(); // release lock so dashboard/tracking calls are not blocked

require_once __DIR__ . '/../config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo "event: error\n";
    echo 'data: ' . json_encode(['message' => 'DB connection failed']) . "\n\n";
    exit();
}

function sse_send($event, $data) {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    @ob_flush(); @flush();
}

// Initial last seen id from client (optional)
$lastId = 0;
if (isset($_GET['last_id'])) { $lastId = max(0, (int)$_GET['last_id']); }

// Resolve latest id at connect to avoid re-sending old ones
$latestId = 0;
if ($res = $conn->query('SELECT IFNULL(MAX(id),0) AS mx FROM notifications')) {
    $row = $res->fetch_assoc();
    $latestId = (int)($row['mx'] ?? 0);
    $res->free();
}
if ($lastId <= 0) { $lastId = $latestId; }

$start = time();
$timeout = 300; // keep stream open for 5 minutes; client will reconnect
$beat = 0;
while (!connection_aborted() && (time() - $start) < $timeout) {
    // Build WHERE filter
    $conds = ['id > ?'];
    $params = [$lastId];
    $types = 'i';
    if ($uname !== '') { $conds[] = 'LOWER(TRIM(recipient_username)) = LOWER(TRIM(?))'; $params[] = $uname; $types .= 's'; }
    if ($udept !== '') { $conds[] = 'LOWER(TRIM(recipient_department)) = LOWER(TRIM(?))'; $params[] = $udept; $types .= 's'; }
    $where = 'WHERE ' . implode(' OR ', $conds); // if either matches
    // Wrap OR groups so id constraint is required
    $where = 'WHERE id > ? AND (' . implode(' OR ', array_slice($conds,1)) . ')';
    if ($uname === '' && $udept === '') { $where = 'WHERE id > ?'; }

    $sql = "SELECT id, title, content, type, recipient_username, recipient_department, UNIX_TIMESTAMP(created_at)*1000 AS ts FROM notifications $where ORDER BY id ASC LIMIT 50";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $batch = [];
            $maxId = $lastId;
            while ($row = $res->fetch_assoc()) {
                $rid = (int)$row['id'];
                if ($rid > $maxId) $maxId = $rid;
                $batch[] = [
                    'id' => $rid,
                    'title' => $row['title'],
                    'content' => $row['content'],
                    'type' => $row['type'],
                    'recipient_username' => $row['recipient_username'],
                    'recipient_department' => $row['recipient_department'],
                    'created_at' => (int)$row['ts']
                ];
            }
            if (!empty($batch)) {
                $lastId = $maxId;
                sse_send('notification', ['items' => $batch, 'last_id' => $lastId]);
            }
        }
        $stmt->close();
    }

    // keep-alive every 10s
    $beat++;
    if (($beat % 10) === 0) { echo ": keepalive\n\n"; @ob_flush(); @flush(); }

    usleep(500000); // 0.5s
}

// tell client to reconnect
sse_send('end', ['reconnect' => true, 'last_id' => $lastId]);
