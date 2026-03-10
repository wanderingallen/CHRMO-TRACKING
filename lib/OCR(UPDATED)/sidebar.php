<?php
// Get the current page filename to dynamically set the 'active' class
$currentPage = basename($_SERVER['PHP_SELF']);

// Include user profile widget
include_once 'user_profile_widget.php';
$userInfo = getUserDisplayInfo();
?>

<style>
  .sidebar {
    width: 70px;
    background: linear-gradient(to bottom, #0097A7, #26A69A);
    color: #FFFFFF;
    padding: 20px 0;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    position: fixed;
    height: 100vh;
    transition: width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
    overflow: hidden;
    transform: translateZ(0);
    backface-visibility: hidden;
    contain: layout style;
    will-change: width;
  }

  .sidebar:hover { width: 260px; }

  .sidebar-header { display: flex; align-items: center; padding: 0 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
  .sidebar-header h2 { font-size: 20px; margin-left: 10px; color: #FFFFFF; opacity: 0; white-space: nowrap; transition: opacity 0.2s ease 0.1s; }
  .sidebar-header img { height: 40px; width: auto; margin-right: 10px; }

  .sidebar:not(:hover) .sidebar-header { justify-content: center; }
  .sidebar:not(:hover) .sidebar-header h2 { display: none; }
  .sidebar:not(:hover) .sidebar-header img { margin-right: 0; }
  .sidebar:hover .sidebar-header h2 { opacity: 1; display: inline; white-space: normal; }

  .menu-item {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    color: #FFFFFF;
    text-decoration: none;
    transition: background-color 0.3s ease, color 0.3s ease;
    margin-bottom: 5px;
    border-radius: 12px;
    margin: 8px 12px;
  }

  .menu-item:hover,
  .menu-item.active {
    background-color: #dbe9e9;
    border-left: none;
    color: #2c3e50;
  }

  /* Keep icons perfectly centered when collapsed (no left border shift) */
  .sidebar:not(:hover) .menu-item:hover,
  .sidebar:not(:hover) .menu-item.active {
    border-left: none;
  }

  .menu-item i {
    margin-right: 15px;
    transition: margin 0.2s ease;
    width: 24px;
    min-width: 24px;
    text-align: center;
  }

  .menu-item span { opacity: 0; white-space: nowrap; transition: opacity 0.2s ease; }
  .sidebar:hover .menu-item span { opacity: 1; }
  .sidebar:not(:hover) .menu-item span { display: none; }
  .sidebar:not(:hover) .menu-item { justify-content: center; }
  .sidebar:not(:hover) .menu-item i { margin-right: 0; }

  /* Sidebar User Profile Styles */
  .sidebar-user-profile {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    margin: 10px 12px;
    background: rgba(255,255,255,0.1);
    border-radius: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    transition: background-color 0.3s ease;
  }

  .sidebar-user-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1rem;
    margin-right: 15px;
    transition: margin 0.2s ease;
    min-width: 35px;
  }

  .sidebar-user-info {
    opacity: 0;
    white-space: nowrap;
    transition: opacity 0.2s ease;
    overflow: hidden;
  }

  .sidebar-user-info h4 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
    color: #FFFFFF;
  }

  .sidebar-user-info p {
    margin: 0;
    font-size: 0.8rem;
    color: rgba(255,255,255,0.8);
  }

  .sidebar:hover .sidebar-user-info { opacity: 1; }
  .sidebar:not(:hover) .sidebar-user-info { display: none; }
  .sidebar:not(:hover) .sidebar-user-profile { justify-content: center; }
  .sidebar:not(:hover) .sidebar-user-avatar { margin-right: 0; }
</style>

<div class="sidebar">
  <div class="sidebar-header">
    <img src="https://placehold.co/40x40/0097A7/ffffff?text=L" alt="CHRMO Logo" style="height: 40px; width: auto; margin-right: 10px;">
    <h2>CHRMO Document Tracking</h2>
  </div>
  
  <?php if ($userInfo): ?>
  <!-- User Profile Section in Sidebar -->
  <div class="sidebar-user-profile">
    <div class="sidebar-user-avatar">
      <?php echo strtoupper(substr($userInfo['name'], 0, 1)); ?>
    </div>
    <div class="sidebar-user-info">
      <h4><?php echo htmlspecialchars($userInfo['name']); ?></h4>
      <p><?php echo htmlspecialchars($userInfo['department']); ?></p>
    </div>
  </div>
  <?php endif; ?>
  <div class="sidebar-menu">
    <a href="dashboard.php" class="menu-item <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>">
      <i class="fas fa-tachometer-alt"></i>
      <span>Dashboard</span>
    </a>
    <a href="tracking.php" class="menu-item <?php echo ($currentPage == 'tracking.php') ? 'active' : ''; ?>">
      <i class="fas fa-file-signature"></i>
      <span>Document Status</span>
    </a>
    <a href="stats.php" class="menu-item <?php echo ($currentPage == 'stats.php') ? 'active' : ''; ?>">
      <i class="fas fa-chart-bar"></i>
      <span>Status Reports</span>
    </a>
    <a href="archive.php" class="menu-item <?php echo ($currentPage == 'archive.php') ? 'active' : ''; ?>">
      <i class="fas fa-archive"></i>
      <span>Archive Storage</span>
    </a>
    <?php
      $role = strtolower(trim((string)($_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user'))));
      $isAdmin = ($role === 'admin' || $role === 'administrator' || $role === 'superadmin' || $role === 'super_admin');
    ?>
    <?php if ($isAdmin): ?>
    <a href="extracted_content_view.php" class="menu-item <?php echo ($currentPage == 'extracted_content_view.php') ? 'active' : ''; ?>">
      <i class="fas fa-key"></i>
      <span>Extracted OCR</span>
    </a>
    <?php endif; ?>
    <a href="user-management.php" class="menu-item <?php echo ($currentPage == 'user-management.php') ? 'active' : ''; ?>">
      <i class="fas fa-users-cog"></i>
      <span>User Management</span>
    </a>
    <a href="log-out.php" class="menu-item">
      <i class="fas fa-sign-out-alt"></i>
      <span>Log Out</span>
    </a>
  </div>
</div>