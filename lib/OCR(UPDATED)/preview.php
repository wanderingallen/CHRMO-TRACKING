<?php
require_once 'security.php';
Security::require_login();

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '' || !preg_match('/^\d+$/', $id)) {
    http_response_code(400);
    echo 'Bad request';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Document Preview</title>
  <style>
    html, body {
      margin: 0;
      padding: 0;
      height: 100%;
      width: 100%;
      background: #fff;
      overflow: auto;
    }
    iframe {
      width: 100%;
      height: 100%;
      border: 0;
      display: block;
      background: #fff;
    }
  </style>
</head>
<body>
  <iframe
    title="Document preview"
    src="download.php?id=<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>&inline=1"
  ></iframe>
</body>
</html>
