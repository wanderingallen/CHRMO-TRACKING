<?php
// layout_shell.php - Master shell with sidebar/header and content swap container
session_start();

$validPages = [
  'dashboard' => 'dashboard.php',
  'tracking' => 'tracking.php',
  'stats' => 'stats.php',
  'archive' => 'archive.php',
  'usercontrol' => 'usercontrol.php',
];
$contentPages = [
  'dashboard' => 'dashboard.content.php',
  'tracking' => 'tracking.content.php',
  'stats' => 'stats.content.php',
  'archive' => 'archive.content.php',
  'usercontrol' => 'usercontrol.content.php',
];
$page = strtolower($_GET['page'] ?? 'dashboard');
if (!isset($validPages[$page])) { $page = 'dashboard'; }
$target = __DIR__ . '/' . $validPages[$page];

function extract_main_content($html) {
    // Try to extract the first main content container
    // Prefer div.main-content; fallback to <main>
    $patternDiv = '/<div\s+class=\"([^\"]*\bmain-content\b[^\"]*)\"[^>]*>([\s\S]*?)<\/div>/i';
    if (preg_match($patternDiv, $html, $m)) {
        return '<div class="main-content">' . $m[2] . '</div>';
    }
    $patternMain = '/<main[^>]*>([\s\S]*?)<\/main>/i';
    if (preg_match($patternMain, $html, $m)) {
        return '<div class="main-content">' . $m[1] . '</div>';
    }
    // If not found, return whole body content as a fallback
    $patternBody = '/<body[^>]*>([\s\S]*?)<\/body>/i';
    if (preg_match($patternBody, $html, $m)) {
        return '<div class="main-content">' . $m[1] . '</div>';
    }
    return '<div class="main-content"><div style="padding:20px">Content not found</div></div>';
}

// If contentOnly=1, return only main content HTML (used by JS navigation)
if (isset($_GET['contentOnly']) && $_GET['contentOnly'] == '1') {
    // Serve content-only file directly if it exists
    $candidate = __DIR__ . '/' . ($contentPages[$page] ?? '');
    if ($candidate && file_exists($candidate)) {
        header('Content-Type: text/html; charset=UTF-8');
        include $candidate;
        exit;
    }
    ob_start();
    include $target; // include full page and then extract main content
    $html = ob_get_clean();
    // Remove any BOM or stray output before extraction
    $html = trim($html);
    header('Content-Type: text/html; charset=UTF-8');
    echo extract_main_content($html);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CHRMO Document Management</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
  <style>
    :root { --primary:#0891b2; --secondary:#06b6d4; --white:#ffffff; --text-dark:#263238; }
    html, body { height:100%; }
    body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:#f6fafc; }
    .container { display:flex; min-height:100vh; }
    .sidebar { width:80px; background:linear-gradient(to bottom, #0e7490, #06b6d4); color:#fff; padding:28px 0; position:fixed; height:100vh; overflow:hidden; transition:width .28s cubic-bezier(0.2,0.8,0.2,1); will-change: width; transform: translateZ(0); }
    .sidebar:hover { width:260px; }
    body.sidebar-hover-open .sidebar { width:260px; }
    .sidebar-header { display:flex; align-items:center; flex-wrap:nowrap; gap:0; padding:0 22px 26px; border-bottom:1px solid rgba(255,255,255,.12); margin-bottom:18px; overflow: visible; }
    .sidebar-header img { height:40px; margin-right:10px; flex:0 0 auto; }
    .sidebar-header h2 { font-size:20px; color:#fff; opacity:0; transition:opacity .2s ease .1s; white-space:nowrap; font-weight:700; text-shadow: 0 1px 2px rgba(0,0,0,.35); letter-spacing: .2px; line-height:1.2; margin:0; max-width:100%; word-break: break-word; overflow-wrap: anywhere; flex:1 1 auto; min-width:0; }
    .sidebar:hover .sidebar-header h2, body.sidebar-hover-open .sidebar .sidebar-header h2 { opacity:1; display:inline; white-space: normal; }
    .sidebar:not(:hover):not(body.sidebar-hover-open .sidebar) .sidebar-header { justify-content:center; }
    .sidebar:not(:hover):not(body.sidebar-hover-open .sidebar) .sidebar-header h2 { display:none; }
    .sidebar:not(:hover):not(body.sidebar-hover-open .sidebar) .sidebar-header img { margin-right:0; }
    .sidebar-menu { margin-top:18px; padding:0 12px; display:flex; flex-direction:column; gap:10px; }
    .menu-item { display:flex; align-items:center; gap:14px; padding:12px 14px; color:var(--white); text-decoration:none; border-radius:9999px; position:relative; transition: background-color .2s ease-out, color .2s ease-out, box-shadow .2s ease-out, transform .12s ease; will-change: background-color, color, box-shadow; transform: translateZ(0); }
    .menu-item:hover { background: rgba(255,255,255,0.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
    .menu-item.active { background: var(--primary); color:#fff; box-shadow: 0 4px 10px rgba(0,0,0,0.12); }
    .menu-item.active i, .menu-item.active span { color:#fff; }
    .menu-item i { font-size:20px; width:28px; min-width:28px; text-align:center; color:#fff; }
    .menu-item span { font-size:15px; color: rgba(255,255,255,0.95); opacity:0; white-space:nowrap; transition:opacity .18s ease; }
    .sidebar:hover .menu-item span, body.sidebar-hover-open .sidebar .menu-item span { opacity:1; }
    .sidebar:not(:hover) .menu-item { justify-content:center; width:56px; height:56px; padding:0; margin:6px auto; display:grid; place-items:center; overflow: visible; }
    .sidebar:not(:hover) .menu-item i { width:24px; height:24px; display:inline-grid; place-items:center; }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display:none; }
    .menu-badge { background-color:#FF5252; color:#fff; font-size:11px; padding:2px 6px; border-radius:10px; margin-left:auto; font-weight:600; min-width:20px; text-align:center; position:absolute; right:12px; top:50%; transform:translateY(-50%); opacity:1; z-index:2; pointer-events:none; display:inline-block; }
    .menu-badge.success { background:#4CAF50; }
    .sidebar:not(:hover) .menu-badge { right:6px; top:6px; transform:none; }
    .main-root { flex:1; margin-left:80px; padding:30px; background:#f9fafb; min-width:0; transition: margin-left .28s cubic-bezier(0.2,0.8,0.2,1); }
    .sidebar:hover ~ .main-root, body.sidebar-hover-open .sidebar ~ .main-root { margin-left:260px; }
    .top-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; background:#fff; padding:20px 25px; border-radius:12px; box-shadow: 0 8px 20px rgba(0,0,0,0.08); position:relative; overflow:visible; }
    .top-bar::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; background: linear-gradient(to bottom, var(--primary), var(--secondary)); }

    /* Transition container */
    #main-content-swap { opacity:1; transform: translateY(0); transition: opacity .3s ease, transform .3s ease; will-change: opacity, transform; }
    #main-content-swap.swapping-out { opacity:0; transform: translateY(6px); }
    #main-content-swap.swapping-in { opacity:1; transform: translateY(0); }

    /* Global guard: disable all transitions/animations during swaps to prevent flicker */
    body.no-anim *, body.no-anim *::before, body.no-anim *::after { transition: none !important; animation: none !important; }

    /* Card performance hints */
    .stat-card, .card { will-change: transform, opacity; }

    /* Disable hover/stagger effects on flicker-prone pages */
    #main-content-swap[data-page="archive"] .stat-card,
    #main-content-swap[data-page="stats"] .stat-card,
    #main-content-swap[data-page="usercontrol"] .stat-card { transition: none !important; }
    #main-content-swap[data-page="archive"] .stat-card:hover,
    #main-content-swap[data-page="stats"] .stat-card:hover,
    #main-content-swap[data-page="usercontrol"] .stat-card:hover { transform: none !important; box-shadow: inherit !important; }
  </style>
  <script src="assets/smooth-interactions.js" defer></script>
  <script src="assets/app-swup.js" defer></script>
</head>
<body>
  <div class="container">
    <div class="sidebar">
      <div class="sidebar-header">
        <img src="<?php echo htmlspecialchars(getAppSetting('logo_url','hr.png')); ?>" alt="CHRMO Logo" />
        <h2><strong>CHRMO Document Management</strong></h2>
      </div>
      <div class="sidebar-menu">
        <a href="layout_shell.php?page=dashboard" class="menu-item<?php echo $page==='dashboard'?' active':''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
        <a href="layout_shell.php?page=tracking" class="menu-item<?php echo $page==='tracking'?' active':''; ?>"><i class="fas fa-file-signature"></i><span>Document Status</span><span class="menu-badge" id="trackingBadge">0</span></a>
        <a href="layout_shell.php?page=stats" class="menu-item<?php echo $page==='stats'?' active':''; ?>"><i class="fas fa-chart-bar"></i><span>Status Reports</span></a>
        <a href="layout_shell.php?page=archive" class="menu-item<?php echo $page==='archive'?' active':''; ?>"><i class="fas fa-archive"></i><span>Archive Storage</span><span class="menu-badge success" id="archiveBadge">0</span></a>
        <a href="layout_shell.php?page=usercontrol" class="menu-item<?php echo $page==='usercontrol'?' active':''; ?>"><i class="fas fa-user-shield"></i><span>User Control</span></a>
      </div>
    </div>

    <div class="main-root">
      <div class="top-bar">
        <h2><?php echo ucfirst($page); ?></h2>
        <div class="top-bar-actions" style="display:flex;align-items:center;gap:10px;">
            <?php
            $notificationsWidget = __DIR__ . '/partials/notifications.php';
            if (!is_file($notificationsWidget)) {
              $notificationsWidget = __DIR__ . '/notifications.php';
            }
            if (is_file($notificationsWidget)) {
              include $notificationsWidget;
            }
            ?>
        </div>
      </div>

      <div id="main-content-swap" data-page="<?php echo htmlspecialchars($page); ?>">
        <?php
          $candidate = __DIR__ . '/' . ($contentPages[$page] ?? '');
          if ($candidate && file_exists($candidate)) {
              include $candidate;
          } else {
              ob_start();
              include $target;
              $html = ob_get_clean();
              echo extract_main_content($html);
          }
        ?>
      </div>
    </div>
  </div>
</body>
</html>
