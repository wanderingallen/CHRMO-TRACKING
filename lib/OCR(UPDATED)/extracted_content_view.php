<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/api/file_crypto.php';

Security::require_login();
Security::require_role(['admin']);

$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
  die('Database connection failed');
}
$connection->set_charset('utf8mb4');

// Ensure table exists
@$connection->query("CREATE TABLE IF NOT EXISTS extracted_content (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_ref VARCHAR(255) NOT NULL,
  title VARCHAR(255) NULL,
  owner_user_id INT NOT NULL,
  owner_department VARCHAR(255) NULL,
  content_sha256 CHAR(64) NOT NULL,
  enc_blob LONGBLOB NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_owner_doc (owner_user_id, doc_ref),
  KEY idx_doc_ref (doc_ref),
  KEY idx_owner_dept (owner_department),
  KEY idx_sha256 (content_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$docRef = trim((string)($_GET['doc_ref'] ?? ''));
$rows = [];
$error = '';

if ($docRef !== '') {
  $stmt = $connection->prepare('SELECT id, doc_ref, title, owner_user_id, owner_department, content_sha256, enc_blob, created_at, updated_at FROM extracted_content WHERE doc_ref LIKE ? ORDER BY updated_at DESC LIMIT 50');
  if ($stmt) {
    $like = '%' . $docRef . '%';
    $stmt->bind_param('s', $like);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      while ($res && ($r = $res->fetch_assoc())) {
        $plain = file_crypto_decrypt_blob($r['enc_blob']);
        if ($plain === false) {
          $plain = '';
        }
        $rows[] = [
          'id' => (int)$r['id'],
          'doc_ref' => (string)$r['doc_ref'],
          'title' => (string)($r['title'] ?? ''),
          'owner_user_id' => (int)$r['owner_user_id'],
          'owner_department' => (string)($r['owner_department'] ?? ''),
          'content_sha256' => (string)$r['content_sha256'],
          'created_at' => (string)($r['created_at'] ?? ''),
          'updated_at' => (string)($r['updated_at'] ?? ''),
          'extracted_text' => (string)$plain,
        ];
      }
      if ($res) { $res->free(); }
    } else {
      $error = 'Query failed';
    }
    $stmt->close();
  } else {
    $error = 'Prepare failed';
  }
}

$userInfo = function_exists('getUserDisplayInfo') ? getUserDisplayInfo() : null;
$role = strtolower(trim((string)($_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user'))));

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Extracted Content Viewer</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root { --primary:#0ea5e9; --bg:#f6fafc; --text:#0f172a; --muted:#64748b; --card:#ffffff; --border:#e2e8f0; }
    body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:var(--bg); color:var(--text); }
    .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
    .top { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom: 16px; }
    .title { display:flex; align-items:center; gap:10px; }
    .title h1 { font-size:20px; margin:0; }
    .badge { font-size:12px; padding:6px 10px; border:1px solid var(--border); border-radius:999px; background:#fff; color:var(--muted); }
    .actions a { text-decoration:none; color:var(--primary); font-weight:600; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; box-shadow: 0 10px 20px rgba(15,23,42,.04); }
    .form { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
    label { display:block; font-size:12px; color:var(--muted); margin-bottom:6px; }
    input[type=text] { width: 340px; max-width: 100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; outline:none; }
    button { padding:10px 14px; border:0; border-radius:10px; background:var(--primary); color:#fff; font-weight:600; cursor:pointer; }
    .hint { font-size:12px; color:var(--muted); margin-top:10px; }
    .err { margin-top:12px; color:#b91c1c; font-size:13px; }
    .list { margin-top: 16px; display:flex; flex-direction:column; gap:12px; }
    .row { border:1px solid var(--border); border-radius:14px; background:#fff; overflow:hidden; }
    .rowhead { padding:12px 14px; display:flex; justify-content:space-between; gap:12px; align-items:center; border-bottom:1px solid var(--border); background:linear-gradient(135deg, rgba(14,165,233,.08), rgba(14,165,233,.02)); }
    .meta { display:flex; gap:12px; flex-wrap:wrap; color:var(--muted); font-size:12px; }
    .meta b { color:var(--text); font-weight:700; }
    .content { padding: 12px 14px; }
    textarea { width: 100%; min-height: 160px; resize: vertical; border:1px solid var(--border); border-radius:12px; padding: 10px 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div class="title">
        <i class="fa-solid fa-shield-halved" style="color:var(--primary)"></i>
        <h1>Extracted OCR Content</h1>
        <span class="badge">Admin only</span>
      </div>
      <div class="actions">
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
      </div>
    </div>

    <div class="card">
      <form class="form" method="get" action="">
        <div>
          <label for="doc_ref">Search doc_ref</label>
          <input id="doc_ref" name="doc_ref" type="text" value="<?php echo htmlspecialchars($docRef); ?>" placeholder="Example: OCR_" />
        </div>
        <div>
          <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </div>
      </form>
      <div class="hint">Tip: In mobile OCR uploads, doc_ref looks like <b>OCR_&lt;timestamp&gt;</b>. You can paste the full value or just <b>OCR_</b> to list recent entries.</div>
      <?php if ($error): ?>
        <div class="err"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
    </div>

    <?php if ($docRef !== ''): ?>
      <div class="list">
        <?php if (empty($rows)): ?>
          <div class="card">No results found.</div>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <div class="row">
              <div class="rowhead">
                <div>
                  <div style="font-weight:800;"><?php echo htmlspecialchars($r['doc_ref']); ?></div>
                  <div class="meta">
                    <span><b>ID</b> <?php echo (int)$r['id']; ?></span>
                    <span><b>Owner UID</b> <?php echo (int)$r['owner_user_id']; ?></span>
                    <span><b>Dept</b> <?php echo htmlspecialchars($r['owner_department']); ?></span>
                    <span><b>SHA-256</b> <?php echo htmlspecialchars($r['content_sha256']); ?></span>
                    <span><b>Updated</b> <?php echo htmlspecialchars($r['updated_at']); ?></span>
                  </div>
                </div>
              </div>
              <div class="content">
                <div style="margin-bottom:8px; color:var(--muted); font-size:12px;"><b>Title:</b> <?php echo htmlspecialchars($r['title']); ?></div>
                <textarea readonly><?php echo htmlspecialchars($r['extracted_text']); ?></textarea>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
<?php
$connection->close();
