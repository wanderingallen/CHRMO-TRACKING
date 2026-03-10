<?php
require 'config.php';
$c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$r = $c->query('SHOW COLUMNS FROM tracking');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}
echo "\n--- notifications ---\n";
$r2 = $c->query('SHOW COLUMNS FROM notifications');
while ($row = $r2->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . "\n";
}
echo "\n--- Sample routing data ---\n";
$r3 = $c->query("SELECT id, department, current_holder, end_location, routing_queue, route_step, status FROM tracking ORDER BY id DESC LIMIT 5");
while ($row = $r3->fetch_assoc()) {
    echo json_encode($row) . "\n";
}
$c->close();
