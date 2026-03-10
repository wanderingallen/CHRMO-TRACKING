<?php
// settings.php - Admin settings page
session_start();
require_once 'security.php';
Security::require_login();
Security::require_role(['admin','administrator','superadmin','super_admin']);
require_once 'user_profile_widget.php';
require_once 'settings_util.php';
require_once 'config.php';

$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) { die('DB connect error'); }

$connection->query("CREATE TABLE IF NOT EXISTS app_settings (k VARCHAR(64) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function getSetting($conn, $key, $default = '') {
  $stmt = $conn->prepare("SELECT v FROM app_settings WHERE k=?");
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row && isset($row['v']) ? $row['v'] : $default;
}
function setSetting($conn, $key, $val) {
  $stmt = $conn->prepare("REPLACE INTO app_settings (k, v) VALUES (?, ?)");
  $stmt->bind_param('ss', $key, $val);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Handle logo upload if provided
  $uploadedLogoPath = '';
  if (isset($_FILES['logo_file']) && isset($_FILES['logo_file']['tmp_name']) && is_uploaded_file($_FILES['logo_file']['tmp_name'])) {
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['logo_file']['tmp_name']);
    finfo_close($finfo);
    if (isset($allowed[$mime])) {
      $ext = $allowed[$mime];
      $dir = __DIR__ . '/uploads/site';
      if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
      $fname = 'logo_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
      $dest = $dir . '/' . $fname;
      if (@move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
        $uploadedLogoPath = 'uploads/site/' . $fname;
      }
    }
  }
  // Normalize departments: accept commas and new lines, deduplicate and rejoin with commas
  $rawDepartments = $_POST['departments'] ?? 'CACCO,CADO,CBO,CMO,CPDO,CTO,GSO';
  $depTokens = preg_split('/[,\n\r]+/', $rawDepartments);
  $depTokens = array_values(array_filter(array_map(function($s){ return strtoupper(trim($s)); }, $depTokens), function($s){ return $s !== ''; }));
  $depTokens = array_unique($depTokens);
  $normalizedDepartments = implode(',', $depTokens);

  $pairs = [
    'system_title' => $_POST['system_title'] ?? 'CHRMO Document Management',
    // logo_url set below if uploaded
    'default_landing' => $_POST['default_landing'] ?? 'dashboard.php',
    'badges_enabled' => isset($_POST['badges_enabled']) ? '1' : '0',
    'badge_refresh_seconds' => (string)max(5, (int)($_POST['badge_refresh_seconds'] ?? 30)),
    'notifications_enabled' => isset($_POST['notifications_enabled']) ? '1' : '0',
    'notifications_max' => (string)max(1, (int)($_POST['notifications_max'] ?? 10)),
    'date_format' => $_POST['date_format'] ?? 'Y-m-d',
    'departments' => $normalizedDepartments,
    'rows_per_page' => (string)max(5, (int)($_POST['rows_per_page'] ?? 10)),
    'session_timeout' => (string)max(5, (int)($_POST['session_timeout'] ?? 30)),
    // New settings
    'timezone' => $_POST['timezone'] ?? date_default_timezone_get(),
    'week_start' => in_array($_POST['week_start'] ?? 'Mon', ['Sun','Mon']) ? ($_POST['week_start'] ?? 'Mon') : 'Mon',
    'rows_per_page_tracking' => (string)max(5, (int)($_POST['rows_per_page_tracking'] ?? 10)),
    'rows_per_page_archive' => (string)max(5, (int)($_POST['rows_per_page_archive'] ?? 10)),
    'rows_per_page_users' => (string)max(5, (int)($_POST['rows_per_page_users'] ?? 10)),
    'password_min_length' => (string)max(6, (int)($_POST['password_min_length'] ?? 8)),
    'password_require_upper' => isset($_POST['password_require_upper']) ? '1' : '0',
    'password_require_number' => isset($_POST['password_require_number']) ? '1' : '0',
    'password_require_symbol' => isset($_POST['password_require_symbol']) ? '1' : '0',
    'audit_log_enabled' => isset($_POST['audit_log_enabled']) ? '1' : '0'
  ];
  if ($uploadedLogoPath !== '') {
    $pairs['logo_url'] = $uploadedLogoPath;
  }
  foreach ($pairs as $k => $v) setSetting($connection, $k, trim($v));
  $saveMsg = 'Settings saved successfully.';
}

// Load settings
$S = [
  'system_title' => getSetting($connection, 'system_title', 'CHRMO Document Management'),
  'logo_url' => getSetting($connection, 'logo_url', 'hr.png'),
  'default_landing' => getSetting($connection, 'default_landing', 'dashboard.php'),
  'badges_enabled' => getSetting($connection, 'badges_enabled', '1'),
  'badge_refresh_seconds' => getSetting($connection, 'badge_refresh_seconds', '30'),
  'notifications_enabled' => getSetting($connection, 'notifications_enabled', '1'),
  'notifications_max' => getSetting($connection, 'notifications_max', '10'),
  'date_format' => getSetting($connection, 'date_format', 'Y-m-d'),
  'departments' => getSetting($connection, 'departments', 'CACCO,CADO,CBO,CMO,CPDO,CTO,GSO'),
  'rows_per_page' => getSetting($connection, 'rows_per_page', '10'),
  'session_timeout' => getSetting($connection, 'session_timeout', '30'),
  // New settings reads
  'timezone' => getSetting($connection, 'timezone', date_default_timezone_get()),
  'week_start' => getSetting($connection, 'week_start', 'Mon'),
  'rows_per_page_tracking' => getSetting($connection, 'rows_per_page_tracking', '10'),
  'rows_per_page_archive' => getSetting($connection, 'rows_per_page_archive', '10'),
  'rows_per_page_users' => getSetting($connection, 'rows_per_page_users', '10'),
  'password_min_length' => getSetting($connection, 'password_min_length', '8'),
  'password_require_upper' => getSetting($connection, 'password_require_upper', '1'),
  'password_require_number' => getSetting($connection, 'password_require_number', '1'),
  'password_require_symbol' => getSetting($connection, 'password_require_symbol', '0'),
  'audit_log_enabled' => getSetting($connection, 'audit_log_enabled', '0')
];
$connection->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Settings - CHRMO Document Tracking</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <script src="assets/smooth-interactions.js" defer></script>
  <style>
    body { margin:0; font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, 'Open Sans', Arial, sans-serif; background:#F5F7FA; color:#263238; }
    .container { display:flex; min-height:100vh; }
    .sidebar { width:80px; background:linear-gradient(to bottom, #0e7490, #06b6d4); color:#fff; padding:28px 0; position:fixed; height:100vh; overflow:hidden; transition:width .28s cubic-bezier(0.2,0.8,0.2,1); transform:translateZ(0); backface-visibility:hidden; contain:layout style; will-change:width; }
    .sidebar:hover { width:260px; }
    .sidebar-header { display:flex; align-items:center; flex-wrap:nowrap; gap:0; padding:0 22px 26px; border-bottom:1px solid rgba(255,255,255,.12); margin-bottom:18px; overflow: visible; }
    .sidebar-header img { height:40px; margin-right:10px; flex:0 0 auto; }
    .sidebar-header h2 { font-size:20px; color:#fff; opacity:0; transition:opacity .2s ease .1s; white-space:nowrap; font-weight:700; text-shadow: 0 1px 2px rgba(0,0,0,.35); letter-spacing: .2px; line-height:1.2; margin:0; max-width:100%; word-break: break-word; overflow-wrap: anywhere; flex:1 1 auto; min-width:0; }
    .sidebar:hover .sidebar-header h2 { opacity:1; display:inline; white-space: normal; }
    .sidebar:not(:hover) .sidebar-header { justify-content:center; }
    .sidebar:not(:hover) .sidebar-header h2 { display:none; }
    .sidebar:not(:hover) .sidebar-header img { margin-right:0; }
    .sidebar-menu { margin-top:18px; padding:0 12px; display:flex; flex-direction:column; gap:10px; }
    .menu-item { display:flex; align-items:center; gap:14px; padding:12px 14px; color:var(--white); text-decoration:none; border-radius:9999px; margin:0; transition: background-color .18s ease, color .18s ease, box-shadow .18s ease, transform .12s ease; position:relative; }
    .menu-item:hover { background: rgba(255,255,255,0.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
    .menu-item.active { background: var(--primary); color:#fff; box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
    .menu-item.active i, .menu-item.active span { color:#fff; }
    .menu-item i { font-size:20px; width:28px; min-width:28px; text-align:center; color:#fff; }
    .menu-item span { font-size:15px; color: rgba(255,255,255,0.95); opacity:0; white-space:nowrap; transition:opacity .18s ease; }
    .sidebar:hover .menu-item span { opacity:1; }
    .sidebar:not(:hover) .menu-item { justify-content:center; width:56px; height:56px; padding:0; margin:6px auto; display:grid; place-items:center; overflow: visible; }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display:none; }
    .sidebar:not(:hover) .menu-item i { width:24px; height:24px; display:inline-grid; place-items:center; }
    /* Match dashboard.php badge styling exactly */
    .menu-badge {
      background-color: #FF5252; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px;
      margin-left: auto; font-weight: 600; min-width: 20px; text-align: center; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      opacity: 1; z-index: 2; pointer-events: none; display: inline-block;
    }
    .sidebar:not(:hover) .menu-badge { right: 6px; top: 6px; transform: none; }
    .menu-badge.success { background-color: #4CAF50; }
    .main-content { flex:1; margin-left:80px; padding:20px; min-width:0; transition: margin-left .28s ease; }
    .sidebar:hover ~ .main-content { margin-left:260px; }
    .card { background: linear-gradient(180deg, #ffffff, #fbfdff); padding:20px; border-radius:12px; box-shadow:0 6px 14px rgba(2, 132, 199, 0.08); margin-bottom:20px; border:1px solid #eef3f7; transition: transform .15s ease, box-shadow .15s ease; }
    .card:hover { transform: translateY(-2px); box-shadow:0 10px 20px rgba(2, 132, 199, 0.12); }
    .card h3 { margin:0 0 12px; font-size:18px; color:#0e7490; display:flex; align-items:center; gap:8px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:20px; }
    .form-row { display:flex; gap:14px; align-items:center; margin-bottom:14px; }
    label { min-width:140px; color:#546E7A; font-size:14px; }
    input[type=text], input[type=number], input[type=file], select, textarea {
      flex:1; width:100%; max-width:100%; padding:10px 12px; border:1px solid #E0E0E0; border-radius:8px; font-size:15px; line-height:1.4; box-sizing:border-box; background:#fff;
    }
    textarea { resize: vertical; min-height: 110px; }
    .form-row > div { flex:1; }
    .card { overflow:hidden; }
    .logo-preview { display:flex; align-items:center; gap:12px; }
    .logo-preview img { height:40px; width:auto; border-radius:6px; border:1px solid #E0E0E0; background:#fff; }
    .hint { color:#78909C; font-size:12px; }
    .actions { display:flex; gap:10px; justify-content:flex-end; }
    .btn { background:#06b6d4; color:#fff; border:none; border-radius:6px; padding:10px 16px; cursor:pointer; }
    .btn.secondary { background:#ECEFF1; color:#263238; }
    .top-bar { display:flex; align-items:center; justify-content:space-between; background:#fff; padding:12px 16px; border-radius:10px; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin-bottom:20px; }
    .status { color:#2e7d32; font-weight:600; }
    /* Modern switch */
    .switch { position: relative; display: inline-flex; width: 50px !important; height: 20px !important; min-width: 50px !important; vertical-align: middle; flex: 0 0 50px !important; overflow: hidden; }
    .form-row .switch { align-self: center; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .slider-toggle { position: absolute; display: block; cursor: pointer; top: 0; left: 0; width: 50px !important; height: 20px !important; background-color: #cfd8dc; transition: .2s; border-radius: 10px; }
    .switch .slider-toggle:before { position: absolute; content: ""; height: 16px !important; width: 16px !important; left: 2px; top: 2px; background-color: white; transition: .2s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .switch input:checked + .slider-toggle { background-color: #06b6d4; }
    .switch input:checked + .slider-toggle:before { transform: translateX(30px); }
    /* Chips input */
    .chips { display:flex; flex-wrap:wrap; gap:6px; align-items:center; padding:6px; border:1px solid #E0E0E0; border-radius:8px; background:#fff; min-height: 40px; }
    .chip { display:inline-flex; align-items:center; gap:6px; padding:6px 8px; background:#e6f7fb; color:#006b7d; border-radius:16px; font-size:13px; }
    .chip .remove { cursor:pointer; color:#007c91; font-weight:bold; }
    .chip-input { border:none; outline:none; min-width:120px; font-size:14px; padding:6px; }
    .switch-label { margin-left: 10px; color:#546E7A; }
    /* Sticky save bar */
    .save-bar { position: sticky; bottom: 0; background: #ffffffcc; backdrop-filter: blur(6px); border-top:1px solid #e0e0e0; padding:10px; border-radius: 10px; display:flex; align-items:center; justify-content: space-between; gap:10px; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); margin-top: 8px; }
    .dirty-dot { width:10px; height:10px; background:#ff9800; border-radius:50%; display:inline-block; margin-right:8px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="sidebar">
      <div class="sidebar-header">
        <img src="<?php echo htmlspecialchars($S['logo_url']); ?>" alt="Logo" />
        <h2><?php echo htmlspecialchars($S['system_title']); ?></h2>
      </div>
      <div class="sidebar-menu">
        <a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
        <a href="tracking.php" class="menu-item"><i class="fas fa-file-signature"></i><span>Document Status</span><span class="menu-badge" id="trackingBadge">0</span></a>
        <a href="stats.php" class="menu-item"><i class="fas fa-chart-bar"></i><span>Status Reports</span></a>
        <a href="archive.php" class="menu-item"><i class="fas fa-archive"></i><span>Archive Storage</span><span class="menu-badge success" id="archiveBadge">0</span></a>
        <a href="usercontrol.php" class="menu-item"><i class="fas fa-user-shield"></i><span>User Control</span></a>
        <a href="settings.php" class="menu-item active"><i class="fas fa-cog"></i><span>Settings</span></a>
      </div>
    </div>

    <div class="main-content">
      <div class="top-bar">
        <h2>System Settings</h2>
        <div class="status"><?php echo htmlspecialchars($saveMsg); ?></div>
      </div>

      <form method="post" enctype="multipart/form-data" id="settingsForm">
        <div class="grid">
          <div class="card">
            <h3><i class="fas fa-sliders-h"></i> General</h3>
            <div class="form-row"><label>System Title</label><input type="text" name="system_title" value="<?php echo htmlspecialchars($S['system_title']); ?>" /></div>
            <div class="form-row"><label>Logo</label>
              <div style="flex:1; display:flex; flex-direction:column; gap:8px;">
                <div class="logo-preview">
                  <img src="<?php echo htmlspecialchars($S['logo_url']); ?>" alt="Current Logo" />
                  <span class="hint">Current</span>
                </div>
                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif" />
                <span class="hint">PNG/JPG/GIF. Uploading a new image will replace the logo.</span>
              </div>
            </div>
            <div class="form-row"><label>Default Landing Page</label>
              <select name="default_landing">
                <option value="dashboard.php" <?php echo $S['default_landing']==='dashboard.php'?'selected':''; ?>>Dashboard</option>
                <option value="tracking.php" <?php echo $S['default_landing']==='tracking.php'?'selected':''; ?>>Document Status</option>
                <option value="stats.php" <?php echo $S['default_landing']==='stats.php'?'selected':''; ?>>Status Reports</option>
                <option value="archive.php" <?php echo $S['default_landing']==='archive.php'?'selected':''; ?>>Archive Storage</option>
                <option value="usercontrol.php" <?php echo $S['default_landing']==='usercontrol.php'?'selected':''; ?>>User Control</option>
                <option value="settings.php" <?php echo $S['default_landing']==='settings.php'?'selected':''; ?>>Settings</option>
              </select>
            </div>
          </div>

          <div class="card">
            <h3><i class="fas fa-bell"></i> Badges & Dashboard</h3>
            <div class="form-row"><label>Enable Badges</label>
              <label class="switch"><input type="checkbox" name="badges_enabled" <?php echo $S['badges_enabled']==='1'?'checked':''; ?>><span class="slider-toggle"></span></label>
            </div>
            <div class="form-row"><label>Badge Refresh (seconds)</label><input type="number" min="5" name="badge_refresh_seconds" value="<?php echo (int)$S['badge_refresh_seconds']; ?>" /></div>
            <div class="hint">Document Status = total in tracking; Archive Storage = total in archive.</div>
          </div>

          <div class="card">
            <h3><i class="fas fa-envelope"></i> Notifications</h3>
            <div class="form-row"><label>Enable Notifications</label>
              <label class="switch"><input type="checkbox" name="notifications_enabled" <?php echo $S['notifications_enabled']==='1'?'checked':''; ?>><span class="slider-toggle"></span></label>
            </div>
            <div class="form-row"><label>Max Items Shown</label><input type="number" min="1" name="notifications_max" value="<?php echo (int)$S['notifications_max']; ?>" /></div>
          </div>

          <div class="card">
            <h3><i class="fas fa-table"></i> Data & Display</h3>
            <div class="form-row"><label>Date Format</label>
              <select name="date_format">
                <?php $formats = [
                  'Y-m-d' => 'YYYY-MM-DD (e.g., 2025-10-22)',
                  'm-d-Y' => 'MM-DD-YYYY (e.g., 10-22-2025)',
                  'd-m-Y' => 'DD-MM-YYYY (e.g., 22-10-2025)',
                  'M d, Y' => 'Mon DD, YYYY (e.g., Oct 22, 2025)',
                  'd M Y' => 'DD Mon YYYY (e.g., 22 Oct 2025)'
                ]; foreach ($formats as $f => $label): ?>
                  <option value="<?php echo htmlspecialchars($f); ?>" <?php echo $S['date_format']===$f?'selected':''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row"><label>Timezone</label>
              <select name="timezone">
                <?php foreach (timezone_identifiers_list() as $tz): ?>
                  <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo $S['timezone']===$tz?'selected':''; ?>><?php echo htmlspecialchars($tz); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row"><label>Week Starts On</label>
              <select name="week_start">
                <option value="Mon" <?php echo $S['week_start']==='Mon'?'selected':''; ?>>Monday</option>
                <option value="Sun" <?php echo $S['week_start']==='Sun'?'selected':''; ?>>Sunday</option>
              </select>
            </div>
            <div class="form-row"><label>Departments</label>
              <div class="chips" id="deptChips">
                <input type="text" id="deptInput" class="chip-input" placeholder="Type a department and press Enter" />
              </div>
              <input type="hidden" name="departments" id="departmentsHidden" value="<?php echo htmlspecialchars($S['departments']); ?>" />
            </div>
            <div class="form-row"><label>Rows per Page</label><input type="number" min="5" name="rows_per_page" value="<?php echo (int)$S['rows_per_page']; ?>" /></div>
            <div class="form-row"><label>Rows per Page (Tracking)</label><input type="number" min="5" name="rows_per_page_tracking" value="<?php echo (int)$S['rows_per_page_tracking']; ?>" /></div>
            <div class="form-row"><label>Rows per Page (Archive)</label><input type="number" min="5" name="rows_per_page_archive" value="<?php echo (int)$S['rows_per_page_archive']; ?>" /></div>
            <div class="form-row"><label>Rows per Page (Users)</label><input type="number" min="5" name="rows_per_page_users" value="<?php echo (int)$S['rows_per_page_users']; ?>" /></div>
            <div class="hint">Departments are normalized to uppercase and deduplicated when saved.</div>
          </div>

          <div class="card">
            <h3><i class="fas fa-shield-alt"></i> Security</h3>
            <div class="form-row"><label>Session Timeout (minutes)</label><input type="number" min="5" name="session_timeout" value="<?php echo (int)$S['session_timeout']; ?>" /></div>
            <div class="form-row"><label>Password Min Length</label><input type="number" min="6" name="password_min_length" value="<?php echo (int)$S['password_min_length']; ?>" /></div>
            <div class="form-row"><label>Password Requires Uppercase</label><label class="switch"><input type="checkbox" name="password_require_upper" <?php echo $S['password_require_upper']==='1'?'checked':''; ?>><span class="slider-toggle"></span></label></div>
            <div class="form-row"><label>Password Requires Number</label><label class="switch"><input type="checkbox" name="password_require_number" <?php echo $S['password_require_number']==='1'?'checked':''; ?>><span class="slider-toggle"></span></label></div>
            <div class="form-row"><label>Password Requires Symbol</label><label class="switch"><input type="checkbox" name="password_require_symbol" <?php echo $S['password_require_symbol']==='1'?'checked':''; ?>><span class="slider-toggle"></span></label></div>
            <div class="form-row"><label>Audit Log</label><label class="switch"><input type="checkbox" name="audit_log_enabled" <?php echo $S['audit_log_enabled']==='1'?'checked':''; ?>><span class="slider-toggle"></span></label></div>
            <div class="hint">Consider enabling HTTPS and HttpOnly/Secure cookies on deployment.</div>
          </div>
        </div>
        <div class="save-bar">
          <div><span id="dirtyDot" class="dirty-dot" style="display:none;"></span><strong>Settings</strong> <span id="dirtyText" class="hint">No unsaved changes</span></div>
          <div class="actions">
            <button type="reset" class="btn secondary">Reset</button>
            <button type="submit" class="btn">Save Settings</button>
          </div>
        </div>
      </form>
    </div>
  </div>
  <script>
    // Unsaved changes indicator
    (function(){
      const form = document.getElementById('settingsForm');
      if(!form) return;
      const dirtyDot = document.getElementById('dirtyDot');
      const dirtyText = document.getElementById('dirtyText');
      const original = new FormData(form);
      function isDirty(){
        const current = new FormData(form);
        for (const [k,v] of current.entries()) { if ((original.get(k)||'') !== (v||'')) return true; }
        return false;
      }
      form.addEventListener('input', () => {
        const d = isDirty();
        dirtyDot.style.display = d ? 'inline-block' : 'none';
        dirtyText.textContent = d ? 'Unsaved changes' : 'No unsaved changes';
      });
      form.addEventListener('reset', () => { setTimeout(()=>{ dirtyDot.style.display='none'; dirtyText.textContent='No unsaved changes'; }, 0); });
    })();

    // Departments chips
    (function(){
      const chipsEl = document.getElementById('deptChips');
      const inputEl = document.getElementById('deptInput');
      const hiddenEl = document.getElementById('departmentsHidden');
      if(!chipsEl || !inputEl || !hiddenEl) return;
      function currentList(){
        return Array.from(chipsEl.querySelectorAll('.chip[data-val]')).map(c=>c.getAttribute('data-val'));
      }
      function syncHidden(){ hiddenEl.value = currentList().join(','); }
      function addChip(val){
        const v = (val||'').trim().toUpperCase();
        if(!v) return;
        // prevent duplicates
        if (currentList().includes(v)) return;
        const chip = document.createElement('span');
        chip.className = 'chip';
        chip.setAttribute('data-val', v);
        chip.innerHTML = '<span>'+v+'</span><span class="remove" title="Remove">×</span>';
        chip.querySelector('.remove').addEventListener('click', ()=>{ chip.remove(); syncHidden(); });
        chipsEl.insertBefore(chip, inputEl);
        syncHidden();
      }
      function initFromHidden(){
        const vals = (hiddenEl.value||'').split(',').map(s=>s.trim()).filter(Boolean);
        vals.forEach(addChip);
      }
      inputEl.addEventListener('keydown', (e)=>{
        if (e.key === 'Enter' || e.key === ',') {
          e.preventDefault();
          addChip(inputEl.value.replace(',', ''));
          inputEl.value='';
        } else if (e.key === 'Backspace' && inputEl.value==='') {
          const chips = chipsEl.querySelectorAll('.chip');
          if (chips.length) { chips[chips.length-1].remove(); syncHidden(); }
        }
      });
      // paste support
      inputEl.addEventListener('paste', (e)=>{
        const text = (e.clipboardData || window.clipboardData).getData('text');
        if (text) {
          e.preventDefault();
          text.split(/[\n,]+/).forEach(t=> addChip(t));
          inputEl.value='';
        }
      });
      initFromHidden();
    })();
  </script>
</body>
</html>

