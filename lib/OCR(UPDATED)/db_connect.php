<?php
require_once __DIR__ . '/config.php';

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // Do not echo/print here; API endpoints will handle errors and return JSON.
}
