<?php
require_once __DIR__ . '/config.php';

// Utility to read application settings without opening a fresh connection per call
function getAppSetting($key, $default = '') {
  static $conn = null;

  if ($conn === null) {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
      $conn = null;
      return $default;
    }
    register_shutdown_function(function() use (&$conn) {
      if ($conn instanceof mysqli) {
        $conn->close();
      }
      $conn = null;
    });
  }

  $stmt = $conn->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
  if (!$stmt) {
    return $default;
  }

  $stmt->bind_param('s', $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  return $row && isset($row['v']) ? $row['v'] : $default;
}
