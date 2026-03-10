<?php
require_once __DIR__ . '/../lib/OCR(UPDATED)/config.php';

echo "DB_HOST=", DB_HOST, "\n";
echo "DB_NAME=", DB_NAME, "\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Connection failed: {$mysqli->connect_error}\n");
    exit(1);
}

$cntRes = $mysqli->query("SELECT COUNT(*) AS c FROM tracking");
if ($cntRes && ($cnt = $cntRes->fetch_assoc())) {
    echo "tracking.count=", ($cnt['c'] ?? 'n/a'), "\n";
}

$sql = "SELECT id,type,employee_name,current_holder,end_location,status,date_submitted,created_at,file_path,mobile_timestamp,doc_hash,user_email,file_type_icon FROM tracking ORDER BY COALESCE(created_at, date_submitted) DESC, id DESC LIMIT 50";
$res = $mysqli->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed: {$mysqli->error}\n");
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
}
