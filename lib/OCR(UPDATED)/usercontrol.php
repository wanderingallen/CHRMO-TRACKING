<?php
require_once 'security.php';
Security::require_admin();
// Include user profile functions
require_once 'user_profile_widget.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_write_close(); // Release session lock early
require_once 'config.php';

$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check database connection
if ($connection->connect_error) {
  die("Connection failed: " . $connection->connect_error);
}

// Helper: detect which column stores the password in `control` table
function detectPasswordColumn($connection) {
  $candidates = ['password', 'pwd', 'pass', 'password_hash', 'pword'];
  foreach ($candidates as $col) {
    $res = $connection->query("SHOW COLUMNS FROM `control` LIKE '" . $connection->real_escape_string($col) . "'");
    if ($res && $res->num_rows > 0) {
      if ($res) { $res->free(); }
      return $col;
    }
    if ($res) { $res->free(); }
  }
  return null; // No matching password column found
}

$passwordColumn = detectPasswordColumn($connection);

// Helper: detect which column stores the status in `control` table
function detectStatusColumn($connection) {
  $candidates = ['status', 'account_status', 'is_active'];
  foreach ($candidates as $col) {
    $res = $connection->query("SHOW COLUMNS FROM `control` LIKE '" . $connection->real_escape_string($col) . "'");
    if ($res && $res->num_rows > 0) {
      if ($res) { $res->free(); }
      return $col;
    }
    if ($res) { $res->free(); }
  }
  return null;
}

$statusColumn = detectStatusColumn($connection);

// Ensure email column exists in control table
$emailColCheck = @$connection->query("SHOW COLUMNS FROM `control` LIKE 'email'");
if ($emailColCheck && $emailColCheck->num_rows === 0) {
  @$connection->query("ALTER TABLE `control` ADD COLUMN `email` VARCHAR(255) DEFAULT NULL AFTER `user`");
}
if ($emailColCheck) { $emailColCheck->free(); }

// Helper: detect which column stores the department name in `departments` table
function detectDepartmentNameColumn($connection) {
  $candidates = ['name', 'dept_name', 'department', 'department_name'];
  foreach ($candidates as $col) {
    $res = @$connection->query("SHOW COLUMNS FROM departments LIKE '" . $connection->real_escape_string($col) . "'");
    if ($res && $res->num_rows > 0) {
      $res->free();
      return $col;
    }
    if ($res) { $res->free(); }
  }
  return null;
}

// Ensure departments table exists with correct schema
$deptTableOk = false;
$chk = @$connection->query("SHOW COLUMNS FROM departments LIKE 'id'");
if ($chk && $chk->num_rows > 0) {
  $row = $chk->fetch_assoc();
  if (stripos($row['Extra'] ?? '', 'auto_increment') !== false) {
    // Also require a usable department name column
    $deptNameCol = detectDepartmentNameColumn($connection);
    if ($deptNameCol === 'name') {
      $deptTableOk = true;
    }
  }
}
if (!$deptTableOk) {
  // Save existing department names before recreating
  $savedDepts = [];
  $deptNameCol = detectDepartmentNameColumn($connection);
  if ($deptNameCol) {
    $colExpr = '`' . str_replace('`', '``', $deptNameCol) . '`';
    $sr = @$connection->query("SELECT DISTINCT COALESCE($colExpr,'') AS dname FROM departments WHERE $colExpr IS NOT NULL AND $colExpr != ''");
    if ($sr) {
      while ($srow = $sr->fetch_assoc()) {
        if (!empty($srow['dname'])) $savedDepts[] = $srow['dname'];
      }
      $sr->free();
    }
  }
  @$connection->query("DROP TABLE IF EXISTS departments");
  @$connection->query("CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_default TINYINT(1) DEFAULT 0,
    dept_type ENUM('internal','external','custom') NOT NULL DEFAULT 'internal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // Re-insert saved departments as custom
  if (!empty($savedDepts)) {
    $reIns = $connection->prepare("INSERT IGNORE INTO departments (name, dept_type) VALUES (?, 'custom')");
    foreach ($savedDepts as $sd) { $reIns->bind_param('s', $sd); $reIns->execute(); }
    $reIns->close();
  }
}

// Ensure dept_type column exists (for tables created before this update)
$dtCheck = @$connection->query("SHOW COLUMNS FROM departments LIKE 'dept_type'");
if ($dtCheck && $dtCheck->num_rows === 0) {
  @$connection->query("ALTER TABLE departments ADD COLUMN dept_type ENUM('internal','external','custom') NOT NULL DEFAULT 'internal' AFTER is_default");
  @$connection->query("UPDATE departments SET dept_type = 'internal' WHERE is_default = 1");
  @$connection->query("UPDATE departments SET dept_type = 'custom' WHERE is_default = 0 AND dept_type = 'internal'");
}
if ($dtCheck) { $dtCheck->free(); }

// Seed default departments if table is empty
$deptCountRes = @$connection->query("SELECT COUNT(*) AS cnt FROM departments");
$deptCount = $deptCountRes ? ($deptCountRes->fetch_assoc()['cnt'] ?? 0) : 0;
if ((int)$deptCount === 0) {
  $defaults = ["CPDO","GSO","CBO","CTO","CACCO","CADO","CMO","HR"];
  $insStmt = $connection->prepare("INSERT IGNORE INTO departments (name, is_default, dept_type) VALUES (?, 1, 'internal')");
  foreach ($defaults as $dd) { $insStmt->bind_param('s', $dd); $insStmt->execute(); }
  $insStmt->close();
}

// Handle AJAX department actions — flush any stray output first
if (isset($_GET['action'])) {
  // Clean any output that leaked from DDL/warnings above
  if (ob_get_level()) ob_end_clean();
  header('Content-Type: application/json');

  if ($_GET['action'] === 'add_department' && isset($_POST['name'])) {
    $dName = strtoupper(trim($_POST['name']));
    if ($dName === '') { echo json_encode(['success'=>false,'message'=>'Department name is required']); exit; }
    $ins = $connection->prepare("INSERT INTO departments (name) VALUES (?)");
    $ins->bind_param('s', $dName);
    if ($ins->execute()) {
      echo json_encode(['success'=>true,'id'=>$ins->insert_id,'name'=>$dName]);
    } else {
      echo json_encode(['success'=>false,'message'=>'Department already exists']);
    }
    $ins->close(); $connection->close(); exit;
  }

  if ($_GET['action'] === 'edit_department' && isset($_POST['id']) && isset($_POST['name'])) {
    $dId = (int)$_POST['id'];
    $dName = strtoupper(trim($_POST['name']));
    if ($dName === '') { echo json_encode(['success'=>false,'message'=>'Department name is required']); exit; }
    // Check if it's a default department — still allow rename
    $upd = $connection->prepare("UPDATE departments SET name = ? WHERE id = ?");
    $upd->bind_param('si', $dName, $dId);
    if ($upd->execute() && $upd->affected_rows > 0) {
      echo json_encode(['success'=>true,'id'=>$dId,'name'=>$dName]);
    } else {
      $err = $connection->errno === 1062 ? 'Department name already exists' : 'No changes made or department not found';
      echo json_encode(['success'=>false,'message'=>$err]);
    }
    $upd->close(); $connection->close(); exit;
  }

  if ($_GET['action'] === 'delete_department' && isset($_POST['id'])) {
    $dId = (int)$_POST['id'];
    $del = $connection->prepare("DELETE FROM departments WHERE id = ? AND is_default = 0");
    $del->bind_param('i', $dId);
    $del->execute();
    echo json_encode(['success'=> $del->affected_rows > 0, 'message'=> $del->affected_rows > 0 ? 'Deleted' : 'Cannot delete default department']);
    $del->close(); $connection->close(); exit;
  }

  if ($_GET['action'] === 'list_departments') {
    $rows = []; $r = @$connection->query("SELECT id, name, is_default, dept_type FROM departments ORDER BY is_default DESC, name ASC");
    if ($r) { while ($row = $r->fetch_assoc()) $rows[] = $row; $r->free(); }
    echo json_encode(['success'=>true,'departments'=>$rows]); $connection->close(); exit;
  }

  // ═══ Web Accounts AJAX (users table via PDO) ═══
  if ($_GET['action'] === 'list_web_accounts') {
    require_once 'database.php';
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT id, name, email, role, department FROM users ORDER BY id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Ensure id is always a proper integer for JS matching
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
    unset($r);
    echo json_encode(['success'=>true,'accounts'=>$rows]); $connection->close(); exit;
  }

  if ($_GET['action'] === 'add_web_account') {
    require_once 'database.php';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin','department_user']) ? $_POST['role'] : 'department_user';
    $dept = trim($_POST['department'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (!$name || !$email || !$pass) {
      echo json_encode(['success'=>false,'message'=>'Name, email and password are required.']); exit;
    }
    if (strlen($pass) < 8) {
      echo json_encode(['success'=>false,'message'=>'Password must be at least 8 characters.']); exit;
    }
    $db = Database::getInstance()->getConnection();
    // Check duplicate
    $chk = $db->prepare("SELECT id FROM users WHERE name = ? OR email = ? LIMIT 1");
    $chk->execute([$name, $email]);
    if ($chk->fetch()) {
      echo json_encode(['success'=>false,'message'=>'Username or email already exists.']); exit;
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $deptVal = ($role === 'admin') ? null : $dept;
    $ins = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, ?, ?, ?)");
    if ($ins->execute([$name, $email, $hash, $role, $deptVal])) {
      echo json_encode(['success'=>true,'id'=>$db->lastInsertId(),'name'=>$name]);
    } else {
      echo json_encode(['success'=>false,'message'=>'Database error.']);
    }
    exit;
  }

  if ($_GET['action'] === 'edit_web_account') {
    require_once 'database.php';
    $idRaw = $_POST['id'] ?? ($_POST['account_id'] ?? ($_POST['user_id'] ?? ''));
    $id = is_numeric($idRaw) ? (int)$idRaw : 0;
    $originalEmail = trim($_POST['original_email'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin','department_user']) ? $_POST['role'] : 'department_user';
    $dept = trim($_POST['department'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ((!$id && !$originalEmail) || !$name || !$email) {
      echo json_encode(['success'=>false,'message'=>'Name and email are required.']); exit;
    }
    $db = Database::getInstance()->getConnection();
    // Check duplicate (exclude self)
    if ($id > 0) {
      $chk = $db->prepare("SELECT id FROM users WHERE (name = ? OR email = ?) AND id != ? LIMIT 1");
      $chk->execute([$name, $email, $id]);
    } else {
      $chk = $db->prepare("SELECT id FROM users WHERE (name = ? OR email = ?) AND email != ? LIMIT 1");
      $chk->execute([$name, $email, $originalEmail]);
    }
    if ($chk->fetch()) {
      echo json_encode(['success'=>false,'message'=>'Username or email already taken by another account.']); exit;
    }
    $deptVal = ($role === 'admin') ? null : $dept;
    if (!empty($pass)) {
      if (strlen($pass) < 8) {
        echo json_encode(['success'=>false,'message'=>'Password must be at least 8 characters.']); exit;
      }
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      if ($id > 0) {
        $upd = $db->prepare("UPDATE users SET name=?, email=?, role=?, department=?, password=? WHERE id=?");
        $upd->execute([$name, $email, $role, $deptVal, $hash, $id]);
      } else {
        $upd = $db->prepare("UPDATE users SET name=?, email=?, role=?, department=?, password=? WHERE email=?");
        $upd->execute([$name, $email, $role, $deptVal, $hash, $originalEmail]);
      }
    } else {
      if ($id > 0) {
        $upd = $db->prepare("UPDATE users SET name=?, email=?, role=?, department=? WHERE id=?");
        $upd->execute([$name, $email, $role, $deptVal, $id]);
      } else {
        $upd = $db->prepare("UPDATE users SET name=?, email=?, role=?, department=? WHERE email=?");
        $upd->execute([$name, $email, $role, $deptVal, $originalEmail]);
      }
    }
    echo json_encode(['success'=>true,'id'=>$id,'name'=>$name]);
    exit;
  }

  if ($_GET['action'] === 'delete_web_account') {
    require_once 'database.php';
    $idRaw = $_POST['id'] ?? ($_POST['account_id'] ?? ($_POST['user_id'] ?? ''));
    $id = is_numeric($idRaw) ? (int)$idRaw : 0;
    $email = trim($_POST['email'] ?? '');
    if (!$id && !$email) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    // Prevent deleting own account
    if (session_status() === PHP_SESSION_NONE) session_start();
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    $sessionUserEmail = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
    if (($id > 0 && $id == $sessionUserId) || (!empty($email) && strtolower($email) === $sessionUserEmail)) {
      echo json_encode(['success'=>false,'message'=>'You cannot delete your own account.']); exit;
    }
    $db = Database::getInstance()->getConnection();
    if ($id > 0) {
      $del = $db->prepare("DELETE FROM users WHERE id = ?");
      $del->execute([$id]);
    } else {
      $del = $db->prepare("DELETE FROM users WHERE email = ? LIMIT 1");
      $del->execute([$email]);
    }
    echo json_encode(['success'=> $del->rowCount() > 0, 'message'=> $del->rowCount() > 0 ? 'Deleted' : 'Account not found.']);
    exit;
  }
}

// Load departments from DB for dropdown
$departments = [];
$deptResult = $connection->query("SELECT name FROM departments ORDER BY name ASC");
if ($deptResult) {
  while ($row = $deptResult->fetch_assoc()) { $departments[] = $row['name']; }
  $deptResult->free();
}

// Handle Add/Edit User Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0; // User ID for editing, 0 for new
  $user = mysqli_real_escape_string($connection, $_POST["user"]);
  $email = mysqli_real_escape_string($connection, $_POST["email"]);
  $role = "user"; // Fixed role as 'user'
  $department = mysqli_real_escape_string($connection, $_POST["department"]);
  
  // Handle password if provided (for new users or password changes)
  $password_hash = null;
  if (!empty($_POST["password"])) {
    $password_hash = password_hash($_POST["password"], PASSWORD_DEFAULT);
  }

  // 'last_active' is intentionally removed from the form and database operations.

  if ($id > 0) {
    // EDIT MODE: Only allow changing name (user) and department
    // Whitelist approach: ignore all other fields from POST
    $sql = "UPDATE control SET user=?, department=? WHERE id=?";
    $stmt = $connection->prepare($sql);
    // 'ssi' -> s:user (name), s:department, i:id
    $stmt->bind_param("ssi", $user, $department, $id);
  } else {
    // Add new user record to control table - password is required for new users
    if (empty($_POST["password"])) {
      // Redirect with error status if password is not provided for new user
      header("Location: usercontrol.php?status=password_required");
      exit();
    }
    
    if ($passwordColumn && $statusColumn) {
      // Insert with both password and status = 'active'
      $sql = "INSERT INTO control (user, email, role, department, {$passwordColumn}, {$statusColumn}) VALUES (?, ?, ?, ?, ?, 'active')";
      $stmt = $connection->prepare($sql);
      $stmt->bind_param("sssss", $user, $email, $role, $department, $password_hash);
    } elseif ($passwordColumn && !$statusColumn) {
      // Insert with password only
      $sql = "INSERT INTO control (user, email, role, department, {$passwordColumn}) VALUES (?, ?, ?, ?, ?)";
      $stmt = $connection->prepare($sql);
      $stmt->bind_param("sssss", $user, $email, $role, $department, $password_hash);
    } elseif (!$passwordColumn && $statusColumn) {
      // Insert without password but set status = 'active'
      $sql = "INSERT INTO control (user, email, role, department, {$statusColumn}) VALUES (?, ?, ?, ?, 'active')";
      $stmt = $connection->prepare($sql);
      $stmt->bind_param("ssss", $user, $email, $role, $department);
    } else {
      // Fallback: insert without password or status
      $sql = "INSERT INTO control (user, email, role, department) VALUES (?, ?, ?, ?)";
      $stmt = $connection->prepare($sql);
      $stmt->bind_param("ssss", $user, $email, $role, $department);
    }
  }

  if ($stmt->execute()) {
    // Redirect with a status message to be handled by JavaScript for toast notifications
    header("Location: usercontrol.php?status=" . ($id > 0 ? "updated" : "added"));
    exit();
  } else {
    // Log error and redirect with an error status
    error_log("Error: " . $stmt->error); // Log the actual error for debugging
    header("Location: usercontrol.php?status=error");
    exit();
  }

}

// Handle Delete User Request
if (isset($_GET['delete_id'])) {
  $delete_id = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
  $sql = "DELETE FROM control WHERE id = ?";
  $stmt = $connection->prepare($sql);
  $stmt->bind_param("i", $delete_id);

    if ($stmt->execute()) {
        header("Location: usercontrol.php?status=deleted");
        exit();
    } else {
        error_log("Error deleting record: " . $stmt->error); // Log the actual error
        header("Location: usercontrol.php?status=delete_error");
        exit();
    }

}

// Fetching user data from control table
$users = [];
// Select the relevant columns, mapping them to logical user fields for easier JavaScript handling
// 'last_active' column is no longer fetched
$query = "SELECT id, user AS user, email AS email, role AS role, department AS department FROM `control` ORDER BY id ASC";
$result = $connection->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free(); // Free the result set
} else {
    error_log("Error fetching users: " . $connection->error); // Log the error
}

// Calculate summary statistics for users
$totalUsers = count($users);
$activeUsers = $totalUsers; // All users are considered active since we don't have status column
$inactiveUsers = 0;

// Close connection at the end of the script
$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Users - CHRMO Document Management</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --primary: #6868AC;
      --primary-light: #e8e8f4;
      --primary-dark: #52528a;
      --secondary: #8585c0;
      --text-dark: #263238;
      --text-light: #78909C;
      --white: #FFFFFF;
      --light-bg: #F5F7FA;
      --border: #E0E0E0;
      --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      --active-status: #4CAF50;
      --inactive-status: #F44336;
      --pending-status: #FFC107;
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Roboto', sans-serif;
    }
    body {
      background-color: var(--light-bg);
      color: var(--text-dark);
    }
    .container {
      display: flex;
      min-height: 100vh;
    }
    /* Sidebar - Professional Government Style */
    .sidebar { width:80px; background: linear-gradient(180deg, #2e2e5e 0%, #3d3d7a 50%, #2e2e5e 100%); color:var(--white); padding:0; box-shadow: 4px 0 24px rgba(0,0,0,0.18); position:fixed; height:100vh; transition: width .28s cubic-bezier(0.2,0.8,0.2,1); z-index:100; overflow:hidden; overflow-y:auto; transform:translateZ(0); backface-visibility:hidden; contain:layout style; will-change:width; display:flex; flex-direction:column; }
    .sidebar::-webkit-scrollbar { width: 0; }
    .sidebar:hover { width: 260px; }
    .sidebar-header { display:flex; flex-direction:column; align-items:center; padding:24px 16px 20px; border-bottom:1px solid rgba(255,255,255,.10); margin-bottom:8px; background:rgba(0,0,0,0.10); }
    .sidebar-header img {
      height: 48px;
      width: 48px;
      object-fit: contain;
      margin-bottom: 8px;
      transition: height 0.25s ease, width 0.25s ease;
      border-radius: 8px;
      background: rgba(255,255,255,0.08);
      padding: 4px;
    }
    .sidebar:hover .sidebar-header img {
      height: 64px;
      width: 64px;
    }
    .sidebar:not(:hover) .sidebar-header img { margin-bottom: 0; }
    .sidebar-header h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--white);
      opacity: 0;
      white-space: nowrap;
      transition: opacity 0.2s ease 0.1s;
      text-align: center;
      letter-spacing: 0.5px;
      margin: 0;
    }
    .sidebar-header .sidebar-subtitle {
      font-size: 11px;
      opacity: 0;
      color: rgba(255,255,255,0.5);
      margin-top: 2px;
      text-align: center;
      transition: opacity 0.2s ease 0.15s;
      letter-spacing: 0.3px;
    }
    .sidebar:not(:hover) .sidebar-header { justify-content: center; }
    .sidebar:not(:hover) .sidebar-header h2 { display: none; }
    .sidebar:not(:hover) .sidebar-header .sidebar-subtitle { display: none; }
    .sidebar:hover .sidebar-header h2 { opacity: 1; }
    .sidebar:hover .sidebar-header .sidebar-subtitle { opacity: 1; }
    
    /* Sidebar Sections */
    .sidebar-section-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 2.5px;
      color: rgba(255,255,255,0.35);
      font-weight: 700;
      padding: 16px 14px 6px;
      opacity: 0;
      transition: opacity 0.2s ease 0.1s;
      white-space: nowrap;
    }
    .sidebar:hover .sidebar-section-label { opacity: 1; }
    .sidebar:not(:hover) .sidebar-section-label { height: 0; padding: 4px 0; overflow: hidden; }
    .sidebar-section-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 4px 14px 0; }
    .sidebar:not(:hover) .sidebar-section-divider { margin: 2px auto; width: 32px; }
    
    .sidebar-menu { padding:0 12px; display:flex; flex-direction:column; gap:4px; flex:1; }
    .sidebar:not(:hover) .menu-item { justify-content: center; }
    .menu-item { display:flex; align-items:center; gap:14px; padding:11px 14px; color:var(--white); text-decoration:none; transition: background-color .18s ease, color .18s ease, box-shadow .18s ease; border-radius:12px; position:relative; }
    .menu-item:hover { background: rgba(255,255,255,0.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06); }
    .menu-item.active {
      background: rgba(255,255,255,0.13); color:#fff;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15), inset 0 0 0 1px rgba(255,255,255,0.08);
      border-left: 3px solid #64b5f6;
    }
    .menu-item.active i, .menu-item.active span { color:#fff; }
    .menu-item i { font-size:20px; width:28px; min-width:28px; text-align:center; color:rgba(255,255,255,0.85); transition: color .18s ease; }
    .menu-item.active i { color: #90caf9; }
    .menu-item:hover i { color: #fff; }
    .menu-item span { font-size:14px; opacity:0; white-space:nowrap; transition: opacity .2s ease; }
    .sidebar:hover .menu-item span { opacity:1; }
    .sidebar:not(:hover) .menu-item { justify-content:center; width:52px; height:52px; padding:0; margin:3px auto; display:grid; place-items:center; overflow: visible; border-left: none; }
    .sidebar:not(:hover) .menu-item.active { border-left: none; border-bottom: 2px solid #64b5f6; }
    .sidebar:not(:hover) .menu-item span:not(.menu-badge) { display:none; }
    .sidebar:not(:hover) .menu-item i { width:24px; height:24px; display:inline-grid; place-items:center; }
    .menu-badge { background:#FF5252; color:#fff; font-size:11px; padding:0 6px; border-radius:999px; margin-left:auto; font-weight:700; min-width:20px; height:20px; line-height:20px; text-align:center; position:absolute; right:12px; top:50%; transform:translateY(-50%); opacity:1; z-index:2; pointer-events:none; display:inline-flex; align-items:center; justify-content:center; }
    .sidebar:not(:hover) .menu-badge { right:4px; top:4px; transform:none; font-size:10px; padding:1px 5px; }
    .menu-badge.success {
      background-color: #4CAF50;
    }
    
    .main-content { flex:1; margin-left:80px; padding:20px; min-width:0; transition: margin-left .28s ease; }
    .sidebar:hover ~ .main-content { margin-left: 260px; }
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
      background-color: var(--white);
      padding: 15px 20px;
      border-radius: 8px;
      box-shadow: var(--shadow);
    }
    .search-bar {
      display: flex;
      align-items: center;
      background-color: var(--light-bg);
      border-radius: 20px;
      padding: 5px 15px;
      width: 300px;
    }
    .search-bar input {
      border: none;
      background: transparent;
      outline: none;
      padding: 8px;
      width: 100%;
      font-size: 14px;
    }
    .notification-icon {
      margin-right: 20px;
      position: relative;
      cursor: pointer;
      font-size: 1.25rem;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--white);
      z-index: 1002;
    }
    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background-color: #FF5252;
      color: white;
      font-size: 10px;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid var(--white);
    }
    .notification-dropdown {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow);
      width: 350px;
      z-index: 1001;
      display: none;
      margin-top: 10px;
      max-height: 400px;
      overflow-y: auto;
    }
    .notification-dropdown.show {
      display: block;
    }
    .notification-header {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .notification-header h3 {
      margin: 0;
      font-size: 16px;
    }
    .notification-clear {
      color: var(--primary);
      cursor: pointer;
      font-size: 14px;
    }
    .notification-item {
      padding: 15px;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
      transition: background-color 0.2s;
    }
    .notification-item:hover {
      background-color: var(--light-bg);
    }
    .notification-item.unread {
      background-color: rgba(0, 188, 212, 0.05);
    }
    .notification-title {
      font-weight: 500;
      margin-bottom: 5px;
      display: flex;
      justify-content: space-between;
    }
    .notification-time {
      color: var(--text-light);
      font-size: 12px;
    }
    .notification-content {
      font-size: 14px;
      color: var(--text-dark);
    }
    .user-profile {
      display: flex;
      align-items: center;
      cursor: pointer;
      position: relative;
    }
    .user-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 10px;
    }
    .dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow);
      min-width: 150px;
      z-index: 100;
      display: none;
      margin-top: 10px;
    }
    .dropdown-menu.show {
      display: block;
    }
    .dropdown-item {
      padding: 10px 15px;
      font-size: 14px;
      cursor: pointer;
    }
    .dropdown-item:hover {
      background-color: var(--primary-light);
    }
    /* Stats Cards */
    .stats-cards-container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background-color: var(--white);
      border-radius: 8px;
      padding: 20px;
      box-shadow: var(--shadow);
      text-align: center;
    }
    .stat-card .value {
      font-size: 28px;
      font-weight: 700;
      color: var(--text-dark);
      margin-bottom: 5px;
    }
    .stat-card .label {
      font-size: 14px;
      color: var(--text-light);
      text-transform: uppercase;
    }
    /* Filters */
    .filters-container {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-bottom: 20px;
      align-items: center;
      background-color: var(--white);
      padding: 15px;
      border-radius: 8px;
      box-shadow: var(--shadow);
    }
    .filter-group {
      position: relative;
    }
    .filter-btn {
      display: flex;
      align-items: center;
      background-color: var(--white);
      border: 1px solid var(--border);
      border-radius: 5px;
      padding: 8px 12px;
      font-size: 14px;
      cursor: pointer;
      min-width: 150px;
      justify-content: space-between;
    }
    .filter-dropdown-menu {
      position: absolute;
      top: 100%;
      left: 0;
      background-color: var(--white);
      border-radius: 5px;
      box-shadow: var(--shadow);
      min-width: 100%;
      z-index: 100;
      display: none;
      margin-top: 5px;
      max-height: 200px;
      overflow-y: auto;
    }
    .filter-dropdown-menu.show {
      display: block;
    }
    .filter-dropdown-item {
      padding: 8px 12px;
      font-size: 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .filter-dropdown-item:hover {
      background-color: var(--primary-light);
    }
    .filter-dropdown-item.selected {
      background-color: var(--primary-light);
      color: var(--primary-dark);
      font-weight: 500;
    }
    .filter-dropdown-item .check-icon {
        color: var(--primary-dark);
        margin-left: 10px;
        font-size: 0.9em;
    }
    .filter-actions {
      margin-left: auto;
      display: flex;
      gap: 10px;
    }
    .action-btn {
      background-color: var(--primary);
      color: var(--white);
      border: none;
      border-radius: 5px;
      padding: 8px 15px;
      font-size: 14px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .action-btn.secondary {
      background-color: var(--light-bg);
      color: var(--text-dark);
      border: 1px solid var(--border);
    }
    .action-btn.danger {
      background-color: #F44336;
      color: white;
    }
    .action-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    /* User List Section */
    .user-list-section {
      background-color: var(--white);
      border-radius: 8px;
      box-shadow: var(--shadow);
      padding: 20px;
      margin-bottom: 30px;
    }
    /* ═══ Side-by-Side Split Layout ═══ */
    .uc-split-layout {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      margin-bottom: 20px;
    }
    .uc-split-panel {
      min-width: 0; /* prevent overflow from tables */
    }
    .uc-split-panel .user-list-section {
      margin-bottom: 0;
      height: 100%;
    }
    .uc-panel-depts .user-table td,
    .uc-panel-depts .user-table th {
      padding: 10px 15px;
      font-size: 14px;
    }
    @media (max-width: 1100px) {
      .uc-split-layout {
        grid-template-columns: 1fr;
      }
    }
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 10px;
    }
    .user-table {
      width: 100%;
      border-collapse: collapse;
    }
    .user-table th, .user-table td {
      padding: 10px 15px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      font-size: 15px;
    }
    .user-table th {
      color: var(--text-light);
      font-weight: 500;
      font-size: 14px;
      cursor: pointer;
    }
    .user-table th .sort-icon {
        margin-left: 5px;
        font-size: 0.8em;
        color: var(--text-light);
    }
    .user-table tr:last-child td {
      border-bottom: none;
    }
    .user-table th:last-child,
    .user-table td:last-child {
      white-space: nowrap;
    }
    .user-table td .action-btn {
      min-width: 42px;
      padding: 8px 10px;
      justify-content: center;
    }
    .status-pill {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
      text-transform: capitalize;
    }
    .status-active {
      background-color: rgba(76, 175, 80, 0.2);
      color: var(--active-status);
    }
    .status-inactive {
      background-color: rgba(244, 67, 54, 0.2);
      color: var(--inactive-status);
    }
    .status-pending {
      background-color: rgba(255, 193, 7, 0.2);
      color: var(--pending-status);
    }
    .fade-in {
        animation: fadeIn 0.25s ease-out;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .pagination-controls {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      margin-top: 20px;
    }
    .pagination-button {
      background-color: var(--primary);
      color: var(--white);
      border: none;
      border-radius: 5px;
      padding: 8px 12px;
      cursor: pointer;
    }
    .pagination-button:disabled {
      background-color: var(--border);
      color: var(--text-light);
      cursor: not-allowed;
    }
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
      justify-content: center;
      align-items: center;
    }
    .modal.show {
      display: flex;
    }
    .modal-content {
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      margin: auto;
      padding: 0;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 8px 25px rgba(0, 0, 0, 0.1);
      width: 90%;
      max-width: 550px;
      position: relative;
      border: 1px solid rgba(255, 255, 255, 0.2);
      overflow: hidden;
    }
    .close-button {
      color: var(--text-light);
      font-size: 24px;
      font-weight: bold;
      position: absolute;
      top: 20px;
      right: 25px;
      cursor: pointer;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: background-color 0.3s ease, color 0.3s ease, transform 0.3s ease;
      z-index: 10;
    }
    .close-button:hover {
      background-color: rgba(239, 68, 68, 0.1);
      color: #ef4444;
      transform: scale(1.1);
    }
    .modal-header {
      background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
      color: white;
      padding: 20px 30px;
      text-align: center;
      position: relative;
    }
    .modal-header h3 {
      margin: 0;
      font-size: 20px;
      font-weight: 600;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    .modal-body {
      padding: 20px 30px;
    }
    .form-group {
      margin-bottom: 16px;
      position: relative;
    }
    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--text-dark);
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group input[type="password"],
    .form-group select {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      font-size: 14px;
      background-color: #f8fafc;
      transition: border-color 0.3s ease, background-color 0.3s ease, box-shadow 0.3s ease;
      box-sizing: border-box;
    }
    .form-group input[type="text"]:focus,
    .form-group input[type="email"]:focus,
    .form-group input[type="password"]:focus,
    .form-group select:focus {
      outline: none;
      border-color: var(--primary);
      background-color: white;
      box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.1);
      transform: translateY(-2px);
    }
    .password-toggle {
      position: absolute;
      right: 20px;
      top: 45px;
      cursor: pointer;
      color: var(--text-light);
      transition: color 0.3s ease;
    }
    .password-toggle:hover {
      color: var(--primary);
    }
    .password-field {
      position: relative;
    }
    .password-requirements {
      font-size: 12px;
      color: var(--text-light);
      margin-top: 8px;
      padding: 8px 12px;
      background-color: #f1f5f9;
      border-radius: 6px;
      border-left: 3px solid var(--primary);
    }
    .password-match-error {
      color: var(--inactive-status);
      font-size: 12px;
      margin-top: 5px;
      display: none;
    }
    .modal-footer {
      display: flex;
      justify-content: flex-end;
      gap: 15px;
      padding: 16px 30px 20px;
      border-top: 1px solid #e2e8f0;
      background-color: #f8fafc;
    }
    .modal-footer .action-btn {
      padding: 12px 30px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border: none;
      cursor: pointer;
    }
    .modal-footer .action-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }
    .modal-footer .action-btn.secondary {
      background-color: #e2e8f0;
      color: #64748b;
    }
    .modal-footer .action-btn.secondary:hover {
      background-color: #cbd5e1;
    }
    #toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .toast {
        background-color: var(--text-dark);
        color: var(--white);
        padding: 10px 15px;
        border-radius: 5px;
        box-shadow: var(--shadow);
        opacity: 0;
        animation: fadeIn 0.3s forwards, fadeOut 0.3s forwards 2.7s;
    }
    .toast.success { background-color: var(--active-status); }
    .toast.error { background-color: var(--inactive-status); }
    .toast.info { background-color: var(--primary); }
    .toast.warning { background-color: var(--pending-status); }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeOut {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(-10px); }
    }

    /* Responsive */
    @media (max-width: 992px) {
      .main-content { margin-left: 70px; }
      .top-bar .search-bar { width: 200px; }
    }
    @media (max-width: 768px) {
      .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
      .search-bar {
        width: 100%;
      }
      .user-profile {
        align-self: flex-end;
      }
      .stats-cards-container {
        grid-template-columns: 1fr;
      }
      .filters-container {
        flex-direction: column;
        align-items: stretch;
      }
      .filter-group {
        width: 100%;
      }
      .filter-actions {
        margin-left: 0;
        margin-top: 10px;
        width: 100%;
        justify-content: space-between;
      }
      .action-btn {
        width: 48%;
        justify-content: center;
      }
      .user-table {
        overflow-x: auto;
        display: block;
        white-space: nowrap;
      }
      .user-table th, .user-table td {
        min-width: 100px;
      }
      .section-header {
        flex-direction: column;
        align-items: flex-start;
      }
      .modal-content {
        width: 95%;
      }
    }
  </style>
</head>
<?php require_once 'settings_util.php'; ?>
<script src="assets/smooth-interactions.js" defer></script>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
    if (typeof window.loadNotifications === 'function') setInterval(window.loadNotifications, 30000);
    if (typeof window.loadSidebarBadges === 'function') setInterval(window.loadSidebarBadges, 30000);
  });
</script>
<body>
  <div class="container">
    <div class="sidebar">
      <div class="sidebar-header">
        <img src="<?php echo htmlspecialchars(getAppSetting('logo_url','hr.png')); ?>" alt="CHRMO Logo" />
        <h2>CHRMO Document Management</h2>
        <span class="sidebar-subtitle">Document Tracking System</span>
      </div>
      
      <div class="sidebar-menu">
        <div class="sidebar-section-label">WORKSPACE</div>
        <a href="dashboard.php" class="menu-item">
          <i class="fas fa-tachometer-alt"></i>
          <span>Dashboard</span>
        </a>
        <a href="tracking.php" class="menu-item">
          <i class="fas fa-file-signature"></i>
          <span>Document Status</span>
          <span class="menu-badge" id="trackingBadge">0</span>
        </a>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">ANALYTICS</div>
        <a href="stats.php" class="menu-item">
          <i class="fas fa-chart-bar"></i>
          <span>Status Reports</span>
        </a>
        <a href="archive.php" class="menu-item">
          <i class="fas fa-archive"></i>
          <span>Archive Storage</span>
          <span class="menu-badge success" id="archiveBadge">0</span>
        </a>
        <div class="sidebar-section-divider"></div>
        <div class="sidebar-section-label">MANAGEMENT</div>
        <a href="usercontrol.php" class="menu-item active">
          <i class="fas fa-users-cog"></i>
          <span>User Control</span>
        </a>
      </div>
      
    </div>

    <div class="main-content">
      <div class="top-bar">
        <h2>User Control Page</h2>
        <div class="search-bar">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search users..." aria-label="Search users" />
        </div>
        <div style="display: flex; align-items: center;">
          <?php include __DIR__ . '/partials/notifications.php'; ?>
          <div class="user-profile" id="userProfile">
            <?php 
            $userInfo = getUserDisplayInfo();
            $initials = $userInfo ? getUserInitials($userInfo['name']) : 'U';
            $displayName = $userInfo ? $userInfo['name'] : 'User';
            ?>
            <img src="https://placehold.co/40x40/B2EBF2/0097A7?text=<?php echo urlencode($initials); ?>" alt="User" />
            <div>
              <div><?php echo htmlspecialchars($displayName); ?></div>
              <small style="color: var(--text-light);"><?php echo htmlspecialchars(formatUserRole($userInfo ? $userInfo['role'] : 'user')); ?></small>
            </div>
            <i class="fas fa-chevron-down" style="margin-left: 10px;"></i>
            <div class="dropdown-menu" id="userDropdown">
              <div class="dropdown-item" style="border-top: 1px solid var(--border); background: transparent; padding: 12px; display: flex; justify-content: center;">
                <a href="logout.php" class="logout-ghost">
                  <i class="fas fa-sign-out-alt"></i>
                  <span class="label">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="stats-cards-container">
        <div class="stat-card">
          <div class="value" id="totalUsers"><?php echo $totalUsers; ?></div>
          <div class="label">Total Users</div>
        </div>
        <div class="stat-card">
          <div class="value" id="activeUsers"><?php echo $totalUsers; ?></div>
          <div class="label">Active Users</div>
        </div>
        <div class="stat-card">
          <div class="value">User</div>
          <div class="label">Default Role</div>
        </div>
      </div>
      <!-- ═══ Web Accounts (users table) ═══ -->
      <div class="user-list-section" style="margin-bottom:24px;">
        <div class="section-header">
          <h3><i class="fas fa-globe" style="color:#0ea5e9;margin-right:8px;"></i>Web Accounts</h3>
          <div class="action-buttons">
            <button class="action-btn" id="addWebAccountBtn" style="background-color:#0ea5e9;">
              <i class="fas fa-user-plus"></i> Create Web Account
            </button>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="user-table" id="webAccountsTable">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Department</th>
                <th style="width:100px;text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="webAccountsBody">
              <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pagination-controls" id="webAccountsPagination" style="border-top:1px solid var(--border);margin-top:8px;padding-top:12px;">
          <button class="pagination-button" id="waPrevBtn" disabled><i class="fas fa-chevron-left"></i> Prev</button>
          <span id="waPageInfo">Page 1 of 1</span>
          <button class="pagination-button" id="waNextBtn" disabled>Next <i class="fas fa-chevron-right"></i></button>
        </div>
      </div>

      <!-- ═══ Side-by-Side: Mobile User List | Department Management ═══ -->
      <div class="uc-split-layout">
        <!-- LEFT: User List -->
        <div class="uc-split-panel uc-panel-users">
          <div class="user-list-section">
            <div class="section-header">
              <h3><i class="fas fa-mobile-alt" style="color:var(--primary);margin-right:8px;"></i>Mobile User List</h3>
              <div class="action-buttons">
                <button class="action-btn danger" id="deleteSelectedUsersBtn" style="display: none;">
                    <i class="fas fa-trash-alt"></i> Delete Selected
                </button>
                <button class="action-btn" id="addUserBtn">
                  <i class="fas fa-user-plus"></i> Add New User
                </button>
              </div>
            </div>
            <div id="userView" class="fade-in">
              <table class="user-table" id="userTable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAllUsersCheckbox" aria-label="Select all users"></th>
                            <th data-sortable="true" data-sort-by="user" role="columnheader" aria-sort="none">User <span class="sort-icon"></span></th>
                            <th data-sortable="true" data-sort-by="email" role="columnheader" aria-sort="none">Email <span class="sort-icon"></span></th>
                            <th data-sortable="true" data-sort-by="department" role="columnheader" aria-sort="none">Dept <span class="sort-icon"></span></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="userTableBody">
                        <?php foreach ($users as $user): ?>
                        <tr data-user-id="<?php echo $user['id']; ?>">
                            <td><input type="checkbox" class="user-checkbox" aria-label="Select user <?php echo htmlspecialchars($user['user']); ?>"></td>
                            <td><?php echo htmlspecialchars($user['user']); ?></td>
                            <td style="font-size:13px;color:#64748b;"><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['department']); ?></td>
                            <td style="text-align:center;white-space:nowrap;">
                                <button class="action-btn secondary edit-user-btn" data-user-id="<?php echo $user['id']; ?>" title="Edit user">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn danger delete-user-btn" data-user-id="<?php echo $user['id']; ?>" title="Delete user">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-controls">
              <button class="pagination-button" id="prevPageBtn" disabled>
                <i class="fas fa-chevron-left"></i> Prev
              </button>
              <span id="currentPageInfo">Page 1 of 1</span>
              <button class="pagination-button" id="nextPageBtn" disabled>
                Next <i class="fas fa-chevron-right"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- RIGHT: Department Management -->
        <div class="uc-split-panel uc-panel-depts">
          <div class="user-list-section" style="margin-top:0;">
            <div class="section-header">
              <h3><i class="fas fa-building" style="color:var(--primary);margin-right:8px;"></i>Department Management</h3>
              <div class="action-buttons">
                <button class="action-btn" id="addDeptBtn"><i class="fas fa-plus"></i> Add Department</button>
              </div>
            </div>
            <div style="overflow-x:auto;">
              <table class="user-table" id="deptTable">
                <thead>
                  <tr>
                    <th>Department Name</th>
                    <th style="width:80px;text-align:center;">Actions</th>
                  </tr>
                </thead>
                <tbody id="deptTableBody">
                  <tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
              </table>
            </div>
            <div id="deptPagination" class="pagination-controls" style="border-top:1px solid var(--border);margin-top:8px;padding-top:12px;">
              <button class="pagination-button" id="deptPrevBtn" disabled>
                <i class="fas fa-chevron-left"></i> Prev
              </button>
              <span id="deptPageInfo">Page 1 of 1</span>
              <button class="pagination-button" id="deptNextBtn" disabled>
                Next <i class="fas fa-chevron-right"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Add Department Modal -->
  <div class="modal" id="deptModal" role="dialog" aria-labelledby="deptModalTitle" aria-hidden="true">
    <div class="modal-content" style="max-width:440px;">
      <span class="close-button" id="closeDeptModal">&times;</span>
      <div class="modal-header" style="background:linear-gradient(135deg,var(--primary),#00838F);color:#fff;border-radius:12px 12px 0 0;padding:20px 24px;">
        <h3 id="deptModalTitle" style="margin:0;color:#fff;"><i class="fas fa-building" style="margin-right:8px;"></i>Add New Department</h3>
      </div>
      <div class="modal-body" style="padding:24px;">
        <div class="form-group" style="margin-bottom:0;">
          <label for="newDeptName" style="font-weight:600;font-size:13px;color:#475569;margin-bottom:6px;display:block;">Department Name / Abbreviation</label>
          <input type="text" id="newDeptName" placeholder="e.g. CPDO, HR, IT" maxlength="100"
                 style="width:100%;padding:12px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;transition:border-color .2s;box-sizing:border-box;"
                 onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='#e2e8f0'">
          <div id="deptNameError" style="color:#ef4444;font-size:12px;margin-top:6px;display:none;"></div>
          <p style="color:#94a3b8;font-size:12px;margin:8px 0 0;"><i class="fas fa-info-circle"></i> Name will be auto-capitalized. New departments can be assigned to users.</p>
        </div>
      </div>
      <div class="modal-footer" style="padding:16px 24px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="action-btn secondary" id="cancelDeptBtn">Cancel</button>
        <button type="button" class="action-btn" id="saveDeptBtn"><i class="fas fa-check" style="margin-right:6px;"></i>Create Department</button>
      </div>
    </div>
  </div>

  <!-- Add/Edit User Modal -->
  <div class="modal" id="userModal" role="dialog" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-content">
      <span class="close-button" id="closeModal">&times;</span>
      <div class="modal-header">
        <h3 id="modalTitle">Add New User</h3>
      </div>
      <form id="userForm" method="POST" action="usercontrol.php">
        <div class="modal-body">
        <input type="hidden" id="userId" name="id" value="0">
        <div class="form-group">
          <label for="userName">Username</label>
          <input type="text" id="userName" name="user" required>
        </div>
        <div class="form-group" id="emailFieldGroup">
          <label for="userEmail">Email <small style="color:#94a3b8;font-weight:400;text-transform:none;">(for password reset)</small></label>
          <input type="email" id="userEmail" name="email" placeholder="user@example.com" required>
        </div>
        <div class="form-group">
          <label for="userDepartment">Department</label>
          <select id="userDepartment" name="department" required>
            <option value="">Select Department</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group password-field">
          <label for="userPassword">Password</label>
          <input type="password" id="userPassword" name="password" required>
          <span class="password-toggle" id="passwordToggle">
            <i class="fas fa-eye"></i>
          </span>
          <div class="password-requirements">
            Password must be at least 8 characters long
          </div>
        </div>
        <div class="form-group password-field">
          <label for="confirmPassword">Confirm Password</label>
          <input type="password" id="confirmPassword" name="confirm_password" required>
          <span class="password-toggle" id="confirmPasswordToggle">
            <i class="fas fa-eye"></i>
          </span>
          <div class="password-match-error" id="passwordMatchError">
            Passwords do not match
          </div>
        </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="action-btn secondary" id="cancelBtn">Cancel</button>
          <button type="submit" class="action-btn" id="saveUserBtn">Create User</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container"></div>

  <!-- Web Account Modal -->
  <div class="modal" id="waModal" role="dialog" aria-labelledby="waModalTitle" aria-hidden="true">
    <div class="modal-content">
      <span class="close-button" id="closeWaModal">&times;</span>
      <div class="modal-header" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);">
        <h3 id="waModalTitle"><i class="fas fa-globe" style="margin-right:8px;"></i>Create Web Account</h3>
      </div>
      <div class="modal-body">
        <input type="hidden" id="waId" value="0">
        <div class="form-group">
          <label for="waName">Full Name / Username</label>
          <input type="text" id="waName" required>
        </div>
        <div class="form-group">
          <label for="waEmail">Email</label>
          <input type="email" id="waEmail" placeholder="user@example.com" required>
        </div>
        <div class="form-group">
          <label for="waRole">Role</label>
          <select id="waRole">
            <option value="department_user" selected>Department User</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group" id="waDeptGroup">
          <label for="waDept">Department</label>
          <select id="waDept">
            <option value="">Select Department</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group password-field">
          <label for="waPassword">Password <small id="waPassHint" style="color:#94a3b8;font-weight:400;text-transform:none;"></small></label>
          <input type="password" id="waPassword">
          <span class="password-toggle" id="waPassToggle"><i class="fas fa-eye"></i></span>
          <div class="password-requirements">Password must be at least 8 characters</div>
        </div>
        <div class="form-group password-field">
          <label for="waConfirmPassword">Confirm Password</label>
          <input type="password" id="waConfirmPassword">
          <span class="password-toggle" id="waConfirmPassToggle"><i class="fas fa-eye"></i></span>
          <div class="password-match-error" id="waPasswordMatchError">Passwords do not match</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="action-btn secondary" id="cancelWaBtn">Cancel</button>
        <button type="button" class="action-btn" id="saveWaBtn" style="background-color:#0ea5e9;">Create Account</button>
      </div>
    </div>
  </div>

  <script>
    // DOM Elements
    const notificationIcon = document.getElementById('notificationIcon');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const clearNotifications = document.getElementById('clearNotifications');
    const userProfile = document.getElementById('userProfile');
    const userDropdown = document.getElementById('userDropdown');
    const addUserBtn = document.getElementById('addUserBtn');
    const userModal = document.getElementById('userModal');
    const closeModal = document.getElementById('closeModal');
    const cancelBtn = document.getElementById('cancelBtn');
    const userForm = document.getElementById('userForm');
    const modalTitle = document.getElementById('modalTitle');
    const userId = document.getElementById('userId');
    const userName = document.getElementById('userName');
    const userEmail = document.getElementById('userEmail');
    const userDepartment = document.getElementById('userDepartment');
    const userPassword = document.getElementById('userPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const passwordMatchError = document.getElementById('passwordMatchError');
    const passwordToggle = document.getElementById('passwordToggle');
    const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
    const saveUserBtn = document.getElementById('saveUserBtn');
    const selectAllUsersCheckbox = document.getElementById('selectAllUsersCheckbox');
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    const deleteSelectedUsersBtn = document.getElementById('deleteSelectedUsersBtn');
    const editUserButtons = document.querySelectorAll('.edit-user-btn');
    const deleteUserButtons = document.querySelectorAll('.delete-user-btn');
    const searchInput = document.getElementById('searchInput');
    const userTableBody = document.getElementById('userTableBody');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const currentPageInfo = document.getElementById('currentPageInfo');
    const userTable = document.getElementById('userTable');
    const userTableHeaders = userTable ? userTable.querySelectorAll('th[data-sortable="true"]') : [];
    const userView = document.getElementById('userView');
    const toastContainer = document.getElementById('toast-container');

    // Toggle Dropdowns
    function toggleDropdown(dropdown, button) {
      const isExpanded = button.getAttribute('aria-expanded') === 'true';
      button.setAttribute('aria-expanded', !isExpanded);
      dropdown.classList.toggle('show', !isExpanded);
    }

    // Close all dropdowns
    function closeAllDropdowns() {
      if (notificationDropdown) { notificationDropdown.classList.remove('show'); }
      if (notificationIcon) { notificationIcon.setAttribute('aria-expanded', 'false'); }
      if (userDropdown) userDropdown.classList.remove('show');
      if (userProfile) userProfile.setAttribute('aria-expanded', 'false');
    }

    // Event Listeners for Dropdowns
    // Notifications are handled by partials/notifications.php when available
    if (!window.loadNotifications && notificationIcon && notificationDropdown) {
      notificationIcon.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleDropdown(notificationDropdown, notificationIcon);
      });
    }

    if (userProfile) userProfile.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleDropdown(userDropdown, userProfile);
    });


    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
      if (notificationIcon && notificationDropdown && !window.loadNotifications) {
        if (!notificationIcon.contains(e.target) && !notificationDropdown.contains(e.target)) {
          notificationDropdown.classList.remove('show');
          notificationIcon.setAttribute('aria-expanded', 'false');
        }
      }
      if (userProfile && userDropdown && !userProfile.contains(e.target) && !userDropdown.contains(e.target)) {
        userDropdown.classList.remove('show');
        userProfile.setAttribute('aria-expanded', 'false');
      }
    });

    // Clear notifications (fallback only when partial not present)
    if (!window.loadNotifications && clearNotifications) {
      clearNotifications.addEventListener('click', () => {
        const notificationItems = notificationDropdown.querySelectorAll('.notification-item');
        notificationItems.forEach(item => item.remove());
        const notificationBadge = document.getElementById('notificationBadge');
        if (notificationBadge) {
          notificationBadge.textContent = '0';
          notificationBadge.style.display = 'none';
        }
        showToast('All notifications cleared', 'info');
      });
    }

    // Modal Functions
    function openModal(isEdit = false, userData = null) {
      const emailGroup = document.getElementById('emailFieldGroup');
      modalTitle.textContent = isEdit ? 'Edit User' : 'Add New User';
      if (isEdit && userData) {
        userId.value = userData.id;
        userName.value = userData.user;
        userEmail.value = userData.email || '';
        userDepartment.value = userData.department;
        // Make username read-only for editing (cannot change username)
        userName.setAttribute('readonly', 'readonly');
        userName.style.backgroundColor = '#f1f5f9';
        userName.style.cursor = 'not-allowed';
        // Make email read-only in edit mode (show but not editable)
        if (emailGroup) {
          emailGroup.style.display = 'block';
          userEmail.setAttribute('readonly', 'readonly');
          userEmail.style.backgroundColor = '#f1f5f9';
          userEmail.style.cursor = 'not-allowed';
          userEmail.removeAttribute('required');
        }
        // Hide password fields completely for editing
        const passwordFields = document.querySelectorAll('.password-field');
        passwordFields.forEach(field => field.style.display = 'none');
        // Update button text
        saveUserBtn.textContent = 'Save Changes';
      } else {
        userId.value = '0';
        userForm.reset();
        // Make username editable for new users
        userName.removeAttribute('readonly');
        userName.style.backgroundColor = '';
        userName.style.cursor = '';
        // Make email editable and required for new users
        if (emailGroup) {
          emailGroup.style.display = 'block';
          userEmail.removeAttribute('readonly');
          userEmail.style.backgroundColor = '';
          userEmail.style.cursor = '';
          userEmail.setAttribute('required', 'required');
        }
        // Show password fields for new users
        const passwordFields = document.querySelectorAll('.password-field');
        passwordFields.forEach(field => field.style.display = 'block');
        // Make password fields required for new users
        userPassword.setAttribute('required', 'required');
        confirmPassword.setAttribute('required', 'required');
        // Update button text
        saveUserBtn.textContent = 'Create User';
      }
      userModal.classList.add('show');
      userModal.setAttribute('aria-hidden', 'false');
    }

    function closeModalFunc() {
      userModal.classList.remove('show');
      userModal.setAttribute('aria-hidden', 'true');
    }

    if (addUserBtn) addUserBtn.addEventListener('click', () => openModal(false));
    if (closeModal) closeModal.addEventListener('click', closeModalFunc);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModalFunc);

    // Password toggle visibility
    function togglePasswordVisibility(inputElement, toggleElement) {
      toggleElement.addEventListener('click', () => {
        const type = inputElement.getAttribute('type') === 'password' ? 'text' : 'password';
        inputElement.setAttribute('type', type);
        toggleElement.querySelector('i').classList.toggle('fa-eye');
        toggleElement.querySelector('i').classList.toggle('fa-eye-slash');
      });
    }

    if (userPassword && passwordToggle) togglePasswordVisibility(userPassword, passwordToggle);
    if (confirmPassword && confirmPasswordToggle) togglePasswordVisibility(confirmPassword, confirmPasswordToggle);

    // Password validation
    function validatePassword() {
      if (userPassword.value !== confirmPassword.value) {
        passwordMatchError.style.display = 'block';
        return false;
      } else {
        passwordMatchError.style.display = 'none';
        return true;
      }
    }

    if (confirmPassword) confirmPassword.addEventListener('input', validatePassword);
    if (userPassword) userPassword.addEventListener('input', validatePassword);

    // Form submission
    if (userForm) userForm.addEventListener('submit', (e) => {
      // For new users, validate password
      if (userId.value === '0' && !validatePassword()) {
        e.preventDefault();
        showToast('Passwords do not match', 'error');
        return;
      }
      
      // For editing users, no password validation needed (fields are hidden)
      // Only name and department are submitted for edit
      
      // If validation passes, allow form submission
      showToast('User saved successfully', 'success');
    });

    // User selection and deletion
    if (selectAllUsersCheckbox) selectAllUsersCheckbox.addEventListener('change', () => {
      const isChecked = selectAllUsersCheckbox.checked;
      userCheckboxes.forEach(checkbox => {
        checkbox.checked = isChecked;
      });
      updateDeleteButtonVisibility();
    });

    function updateDeleteButtonVisibility() {
      const anyChecked = Array.from(userCheckboxes).some(checkbox => checkbox.checked);
      if (deleteSelectedUsersBtn) deleteSelectedUsersBtn.style.display = anyChecked ? 'inline-flex' : 'none';
    }

    userCheckboxes.forEach(checkbox => {
      checkbox.addEventListener('change', updateDeleteButtonVisibility);
    });

    if (deleteSelectedUsersBtn) deleteSelectedUsersBtn.addEventListener('click', () => {
      const selectedUserIds = Array.from(userCheckboxes)
        .filter(checkbox => checkbox.checked)
        .map(checkbox => checkbox.closest('tr').dataset.userId);
      
      if (selectedUserIds.length > 0) {
        if (confirm(`Are you sure you want to delete ${selectedUserIds.length} user(s)?`)) {
          // In a real application, you would send a request to delete these users
          showToast(`${selectedUserIds.length} user(s) deleted`, 'success');
          // Reset selection
          selectAllUsersCheckbox.checked = false;
          userCheckboxes.forEach(checkbox => checkbox.checked = false);
          updateDeleteButtonVisibility();
        }
      }
    });

    // Edit and Delete user buttons (event delegation to keep actions working after UI updates)
    if (userTableBody) {
      userTableBody.addEventListener('click', (event) => {
        const editBtn = event.target.closest('.edit-user-btn');
        if (editBtn) {
          const row = editBtn.closest('tr');
          if (!row) return;
          const rowUserId = editBtn.dataset.userId || row.dataset.userId || '0';
          const userData = {
            id: rowUserId,
            user: (row.cells[1]?.textContent || '').trim(),
            email: (row.cells[2]?.textContent || '').trim(),
            department: (row.cells[3]?.textContent || '').trim()
          };
          openModal(true, userData);
          return;
        }

        const deleteBtn = event.target.closest('.delete-user-btn');
        if (deleteBtn) {
          const row = deleteBtn.closest('tr');
          if (!row) return;
          const rowUserId = deleteBtn.dataset.userId || row.dataset.userId || '0';
          const rowUserName = (row.cells[1]?.textContent || '').trim();
          if (confirm(`Are you sure you want to delete user "${rowUserName}"?`)) {
            window.location.href = `usercontrol.php?delete_id=${rowUserId}`;
          }
        }
      });
    }

    // Search functionality
    if (searchInput) searchInput.addEventListener('input', () => {
      const searchTerm = searchInput.value.toLowerCase();
      const rows = userTableBody ? userTableBody.querySelectorAll('tr') : [];
      
      rows.forEach(row => {
        const userName = row.cells[1].textContent.toLowerCase();
        const userEmail = row.cells[2].textContent.toLowerCase();
        const userDepartment = row.cells[3].textContent.toLowerCase();
        
        const matches = userName.includes(searchTerm) || 
                        userEmail.includes(searchTerm) || 
                        userDepartment.includes(searchTerm);
        
        row.style.display = matches ? '' : 'none';
      });
    });

    // Pagination
    let currentPage = 1;
    const rowsPerPage = 5;
    const totalRows = userTableBody.querySelectorAll('tr').length;
    const totalPages = Math.ceil(totalRows / rowsPerPage);

    function updatePagination() {
      if (currentPageInfo) currentPageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
      if (prevPageBtn) prevPageBtn.disabled = currentPage === 1;
      if (nextPageBtn) nextPageBtn.disabled = currentPage === totalPages;
      
      const rows = userTableBody.querySelectorAll('tr');
      const startIndex = (currentPage - 1) * rowsPerPage;
      const endIndex = startIndex + rowsPerPage;
      
      // Fade out old rows, then fade in new rows
      rows.forEach((row, index) => {
        if (index >= startIndex && index < endIndex) {
          row.style.display = '';
          row.classList.add('fade-in');
          setTimeout(() => row.classList.remove('fade-in'), 250);
        } else {
          row.style.display = 'none';
        }
      });
    }

    if (prevPageBtn) prevPageBtn.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage--;
        updatePagination();
      }
    });

    if (nextPageBtn) nextPageBtn.addEventListener('click', () => {
      if (currentPage < totalPages) {
        currentPage++;
        updatePagination();
      }
    });

    // Initialize pagination
    updatePagination();

    // Table sorting
    let currentSort = {
      column: null,
      direction: 'asc' // 'asc' or 'desc'
    };

    userTableHeaders.forEach(header => {
      header.addEventListener('click', () => {
        const sortBy = header.dataset.sortBy;
        
        // Update sorting direction
        if (currentSort.column === sortBy) {
          currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort.column = sortBy;
          currentSort.direction = 'asc';
        }
        
        // Update UI
        userTableHeaders.forEach(h => {
          const icon = h.querySelector('.sort-icon');
          if (h.dataset.sortBy === currentSort.column) {
            icon.textContent = currentSort.direction === 'asc' ? '↑' : '↓';
            h.setAttribute('aria-sort', currentSort.direction === 'asc' ? 'ascending' : 'descending');
          } else {
            icon.textContent = '';
            h.setAttribute('aria-sort', 'none');
          }
        });
        
        // Sort the data
        const rows = Array.from(userTableBody.querySelectorAll('tr'));
        rows.sort((a, b) => {
          let aValue, bValue;
          
          switch (currentSort.column) {
            case 'user':
              aValue = a.cells[1].textContent;
              bValue = b.cells[1].textContent;
              break;
            case 'email':
              aValue = a.cells[2].textContent;
              bValue = b.cells[2].textContent;
              break;
            case 'department':
              aValue = a.cells[3].textContent;
              bValue = b.cells[3].textContent;
              break;
            default:
              return 0;
          }
          
          // Compare values
          if (aValue < bValue) return currentSort.direction === 'asc' ? -1 : 1;
          if (aValue > bValue) return currentSort.direction === 'asc' ? 1 : -1;
          return 0;
        });
        
        // Reappend sorted rows
        rows.forEach(row => userTableBody.appendChild(row));
        
        // Update pagination
        updatePagination();
      });
    });

    if (userView) {
      userView.style.display = 'block';
      userView.classList.add('fade-in');
      setTimeout(() => userView.classList.remove('fade-in'), 250);
    }

    // Toast notification function
    function showToast(message, type = 'info') {
      const toast = document.createElement('div');
      toast.className = `toast ${type}`;
      toast.textContent = message;
      toast.setAttribute('role', 'alert');
      toastContainer.appendChild(toast);
      
      // Remove toast after animation completes
      setTimeout(() => {
        toast.remove();
      }, 3000);
    }

    // Handle status messages from PHP
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    
    if (status) {
      switch (status) {
        case 'added':
          showToast('User added successfully', 'success');
          break;
        case 'updated':
          showToast('User updated successfully', 'success');
          break;
        case 'deleted':
          showToast('User deleted successfully', 'success');
          break;
        case 'error':
          showToast('An error occurred', 'error');
          break;
        case 'delete_error':
          showToast('Error deleting user', 'error');
          break;
        case 'password_required':
          showToast('Password is required for new users', 'error');
          break;
      }
      
      // Clean URL
      window.history.replaceState({}, document.title, window.location.pathname);
    }

    // Initialize the page
    document.addEventListener('DOMContentLoaded', () => {
      updatePagination();
      if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
    });
    
    // Auto-refresh sidebar badges (global)
    if (typeof window.loadSidebarBadges === 'function') setInterval(window.loadSidebarBadges, 30000);
  </script>

  <!-- ═══ Department Management (isolated script) ═══ -->
  <script>
  (function(){
    function _toast(msg, type) {
      if (typeof showToast === 'function') return showToast(msg, type);
      const c = document.getElementById('toast-container');
      if (!c) return;
      const t = document.createElement('div');
      t.className = 'toast ' + (type||'info');
      t.textContent = msg;
      c.appendChild(t);
      setTimeout(() => t.remove(), 3000);
    }

    const tbody       = document.getElementById('deptTableBody');
    const addBtn      = document.getElementById('addDeptBtn');
    const deptSelect  = document.getElementById('userDepartment');
    const deptModal   = document.getElementById('deptModal');
    const closeDeptM  = document.getElementById('closeDeptModal');
    const cancelDeptM = document.getElementById('cancelDeptBtn');
    const saveDeptBtn = document.getElementById('saveDeptBtn');
    const deptInput   = document.getElementById('newDeptName');
    const deptError   = document.getElementById('deptNameError');
    const deptModalTitle = document.getElementById('deptModalTitle');

    if (!tbody) { console.warn('deptTableBody not found'); return; }

    let editingDeptId = null; // null = add mode, number = edit mode
    const DEPTS_PER_PAGE = 5;
    let deptCurrentPage = 1;
    let allDepts = [];
    const deptPrevBtn  = document.getElementById('deptPrevBtn');
    const deptNextBtn  = document.getElementById('deptNextBtn');
    const deptPageInfo = document.getElementById('deptPageInfo');

    // ── Modal helpers ──
    function openDeptModal(editId, editName) {
      if (!deptModal) return;
      editingDeptId = editId || null;
      if (deptInput) { deptInput.value = editName || ''; deptInput.style.borderColor = '#e2e8f0'; }
      if (deptError) { deptError.style.display = 'none'; deptError.textContent = ''; }
      if (deptModalTitle) deptModalTitle.textContent = editingDeptId ? 'Edit Department' : 'Add New Department';
      if (saveDeptBtn) saveDeptBtn.innerHTML = editingDeptId ? '<i class="fas fa-save" style="margin-right:6px;"></i>Save Changes' : '<i class="fas fa-check" style="margin-right:6px;"></i>Create Department';
      deptModal.classList.add('show');
      deptModal.setAttribute('aria-hidden', 'false');
      setTimeout(() => { if (deptInput) deptInput.focus(); }, 150);
    }
    function closeDeptModal() {
      if (!deptModal) return;
      deptModal.classList.remove('show');
      deptModal.setAttribute('aria-hidden', 'true');
    }
    if (addBtn)      addBtn.addEventListener('click', () => openDeptModal(null, ''));
    if (closeDeptM)  closeDeptM.addEventListener('click', closeDeptModal);
    if (cancelDeptM) cancelDeptM.addEventListener('click', closeDeptModal);
    if (deptModal)   deptModal.addEventListener('click', (e) => { if (e.target === deptModal) closeDeptModal(); });

    // ── Pagination helpers ──
    function updateDeptPagination() {
      const totalPages = Math.max(1, Math.ceil(allDepts.length / DEPTS_PER_PAGE));
      if (deptCurrentPage > totalPages) deptCurrentPage = totalPages;
      if (deptPageInfo) deptPageInfo.textContent = 'Page ' + deptCurrentPage + ' of ' + totalPages;
      if (deptPrevBtn) deptPrevBtn.disabled = deptCurrentPage <= 1;
      if (deptNextBtn) deptNextBtn.disabled = deptCurrentPage >= totalPages;
    }
    if (deptPrevBtn) deptPrevBtn.addEventListener('click', () => { deptCurrentPage--; renderDeptsPage(); });
    if (deptNextBtn) deptNextBtn.addEventListener('click', () => { deptCurrentPage++; renderDeptsPage(); });

    function renderDeptsPage() {
      updateDeptPagination();
      const start = (deptCurrentPage - 1) * DEPTS_PER_PAGE;
      const pageDepts = allDepts.slice(start, start + DEPTS_PER_PAGE);
      renderDepts(pageDepts);
    }

    // ── Render ──
    function renderDepts(depts) {
      tbody.innerHTML = '';
      if (!allDepts.length) {
        tbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:#94a3b8;padding:20px;">No departments yet. Click <b>Add Department</b> to create one.</td></tr>';
        return;
      }
      if (!depts.length) {
        // Edge case: current page has no items (e.g. after delete)
        deptCurrentPage = Math.max(1, deptCurrentPage - 1);
        renderDeptsPage();
        return;
      }
      depts.forEach(dept => {
        const tr = document.createElement('tr');
        const isDefault = String(dept.is_default) === '1';
        tr.innerHTML =
          '<td style="font-weight:500;color:var(--text-dark);">'+dept.name+'</td>'+
          '<td style="text-align:center;white-space:nowrap;">'+
            '<button class="action-btn secondary dept-edit-btn" data-id="'+dept.id+'" data-name="'+dept.name+'" title="Rename department"><i class="fas fa-pen"></i></button>'+
            (isDefault
              ? ''
              : '<button class="action-btn danger dept-del-btn" data-id="'+dept.id+'" data-name="'+dept.name+'" title="Delete department"><i class="fas fa-trash-alt"></i></button>')+
          '</td>';
        tbody.appendChild(tr);
      });
      // Edit handlers
      tbody.querySelectorAll('.dept-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          openDeptModal(btn.dataset.id, btn.dataset.name);
        });
      });
      // Delete handlers
      tbody.querySelectorAll('.dept-del-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm('Delete department "'+btn.dataset.name+'"?')) return;
          btn.disabled = true;
          btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
          try {
            const fd = new FormData(); fd.append('id', btn.dataset.id);
            const res = await fetch('usercontrol.php?action=delete_department', { method: 'POST', body: fd });
            const j = await res.json();
            if (j.success) { _toast('Department deleted', 'success'); loadDepts(); refreshDeptSelect(); }
            else _toast(j.message || 'Cannot delete this department', 'error');
          } catch(e) { _toast('Network error', 'error'); }
          btn.disabled = false;
        });
      });
    }

    // ── Load ──
    function loadDepts(){
      fetch('usercontrol.php?action=list_departments', { cache: 'no-store' })
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(text => {
          let j = text.trim();
          if (!j.startsWith('{') && !j.startsWith('[')) {
            const a = j.indexOf('{'), b = j.lastIndexOf('}');
            if (a !== -1 && b > a) j = j.slice(a, b + 1);
          }
          return JSON.parse(j);
        })
        .then(d => {
          allDepts = d.departments || [];
          renderDeptsPage();
        })
        .catch(err => {
          console.error('loadDepts error:', err);
          tbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:#ef4444;padding:20px;"><i class="fas fa-exclamation-triangle"></i> Could not load departments. <a href="javascript:void(0)" onclick="window.__loadDepts()" style="color:var(--primary);font-weight:600;">Retry</a></td></tr>';
        });
    }
    window.__loadDepts = loadDepts;

    // ── Refresh the user-creation department dropdown ──
    function refreshDeptSelect(){
      fetch('usercontrol.php?action=list_departments', { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
          if (!deptSelect) return;
          const cur = deptSelect.value;
          deptSelect.innerHTML = '<option value="">Select Department</option>';
          (d.departments || []).forEach(dept => {
            const o = document.createElement('option');
            o.value = dept.name; o.textContent = dept.name;
            if (dept.name === cur) o.selected = true;
            deptSelect.appendChild(o);
          });
        }).catch(() => {});
    }

    // ── Save new department ──
    if (saveDeptBtn) {
      saveDeptBtn.addEventListener('click', async () => {
        const name = (deptInput ? deptInput.value : '').trim();
        if (!name) {
          if (deptError) { deptError.textContent = 'Please enter a department name.'; deptError.style.display = 'block'; }
          if (deptInput) { deptInput.style.borderColor = '#ef4444'; deptInput.focus(); }
          return;
        }
        if (name.length < 2) {
          if (deptError) { deptError.textContent = 'Name must be at least 2 characters.'; deptError.style.display = 'block'; }
          if (deptInput) { deptInput.style.borderColor = '#ef4444'; deptInput.focus(); }
          return;
        }
        saveDeptBtn.disabled = true;
        const isEdit = !!editingDeptId;
        saveDeptBtn.innerHTML = isEdit
          ? '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Saving...'
          : '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Creating...';
        try {
          const fd = new FormData();
          fd.append('name', name);
          const action = isEdit ? 'edit_department' : 'add_department';
          if (isEdit) fd.append('id', editingDeptId);
          const res = await fetch('usercontrol.php?action=' + action, { method: 'POST', body: fd });
          const j = await res.json();
          if (j.success) {
            _toast(isEdit ? 'Department renamed to "' + j.name + '"' : 'Department "' + j.name + '" created!', 'success');
            closeDeptModal();
            loadDepts();
            refreshDeptSelect();
          } else {
            if (deptError) { deptError.textContent = j.message || 'Operation failed.'; deptError.style.display = 'block'; }
            if (deptInput) deptInput.style.borderColor = '#ef4444';
          }
        } catch(e) {
          if (deptError) { deptError.textContent = 'Network error. Please try again.'; deptError.style.display = 'block'; }
        }
        saveDeptBtn.disabled = false;
        saveDeptBtn.innerHTML = isEdit
          ? '<i class="fas fa-save" style="margin-right:6px;"></i>Save Changes'
          : '<i class="fas fa-check" style="margin-right:6px;"></i>Create Department';
      });
    }

    // Allow Enter key in input
    if (deptInput) {
      deptInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); if (saveDeptBtn) saveDeptBtn.click(); }
      });
      deptInput.addEventListener('input', () => {
        if (deptError) deptError.style.display = 'none';
        deptInput.style.borderColor = '#e2e8f0';
      });
    }

    // ── Init ──
    loadDepts();
  })();
  </script>

  <!-- ═══ Web Accounts Management (isolated script) ═══ -->
  <script>
  (function(){
    function _toast(msg, type) {
      if (typeof showToast === 'function') return showToast(msg, type);
      const c = document.getElementById('toast-container');
      if (!c) return;
      const t = document.createElement('div');
      t.className = 'toast ' + (type||'info');
      t.textContent = msg;
      c.appendChild(t);
      setTimeout(() => t.remove(), 3000);
    }

    const tbody = document.getElementById('webAccountsBody');
    const addBtn = document.getElementById('addWebAccountBtn');
    const modal = document.getElementById('waModal');
    const closeBtn = document.getElementById('closeWaModal');
    const cancelBtn = document.getElementById('cancelWaBtn');
    const saveBtn = document.getElementById('saveWaBtn');
    const titleEl = document.getElementById('waModalTitle');
    const idField = document.getElementById('waId');
    const nameField = document.getElementById('waName');
    const emailField = document.getElementById('waEmail');
    const roleField = document.getElementById('waRole');
    const deptGroup = document.getElementById('waDeptGroup');
    const deptField = document.getElementById('waDept');
    const passField = document.getElementById('waPassword');
    const confirmField = document.getElementById('waConfirmPassword');
    const passHint = document.getElementById('waPassHint');
    const passMatchErr = document.getElementById('waPasswordMatchError');
    const passToggle = document.getElementById('waPassToggle');
    const confirmToggle = document.getElementById('waConfirmPassToggle');
    const prevBtn = document.getElementById('waPrevBtn');
    const nextBtn = document.getElementById('waNextBtn');
    const pageInfo = document.getElementById('waPageInfo');

    if (!tbody) return;

    let allAccounts = [];
    let waPage = 1;
    const WA_PER_PAGE = 5;
    let editingId = null;

    // Password toggles
    [passToggle, confirmToggle].forEach((tog, i) => {
      if (!tog) return;
      const inp = i === 0 ? passField : confirmField;
      tog.addEventListener('click', () => {
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        tog.querySelector('i').classList.toggle('fa-eye', !show);
        tog.querySelector('i').classList.toggle('fa-eye-slash', show);
      });
    });

    // Role change — hide dept for admin
    if (roleField) {
      roleField.addEventListener('change', () => {
        if (deptGroup) deptGroup.style.display = roleField.value === 'admin' ? 'none' : 'block';
      });
    }

    // Modal helpers
    function openWaModal(account) {
      editingId = account ? account.id : null;
      titleEl.innerHTML = editingId
        ? '<i class="fas fa-pen" style="margin-right:8px;"></i>Edit Web Account'
        : '<i class="fas fa-globe" style="margin-right:8px;"></i>Create Web Account';
      saveBtn.textContent = editingId ? 'Update Account' : 'Create Account';
      idField.value = editingId || 0;
      nameField.value = account ? account.name : '';
      emailField.value = account ? account.email : '';
      emailField.dataset.originalEmail = account ? (account.email || '') : '';
      roleField.value = account ? account.role : 'department_user';
      deptField.value = account ? (account.department || '') : '';
      passField.value = '';
      confirmField.value = '';
      passMatchErr.style.display = 'none';
      if (passHint) passHint.textContent = editingId ? '(leave blank to keep current)' : '';
      if (editingId) {
        passField.removeAttribute('required');
        confirmField.removeAttribute('required');
      } else {
        passField.setAttribute('required', 'required');
        confirmField.setAttribute('required', 'required');
      }
      if (deptGroup) deptGroup.style.display = roleField.value === 'admin' ? 'none' : 'block';
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      setTimeout(() => nameField.focus(), 150);
    }

    function closeWaModal() {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
    }

    if (addBtn) addBtn.addEventListener('click', () => openWaModal(null));
    if (closeBtn) closeBtn.addEventListener('click', closeWaModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeWaModal);
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeWaModal(); });

    // Pagination
    function updateWaPagination() {
      const total = Math.max(1, Math.ceil(allAccounts.length / WA_PER_PAGE));
      if (waPage > total) waPage = total;
      if (pageInfo) pageInfo.textContent = 'Page ' + waPage + ' of ' + total;
      if (prevBtn) prevBtn.disabled = waPage <= 1;
      if (nextBtn) nextBtn.disabled = waPage >= total;
    }
    if (prevBtn) prevBtn.addEventListener('click', () => { waPage--; renderWaPage(); });
    if (nextBtn) nextBtn.addEventListener('click', () => { waPage++; renderWaPage(); });

    function renderWaPage() {
      updateWaPagination();
      const start = (waPage - 1) * WA_PER_PAGE;
      const page = allAccounts.slice(start, start + WA_PER_PAGE);
      renderAccounts(page);
    }

    function roleBadge(role) {
      if (role === 'admin' || role === 'administrator' || role === 'superadmin') {
        return '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:rgba(239,68,68,0.12);color:#ef4444;">Admin</span>';
      }
      return '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;background:rgba(14,165,233,0.12);color:#0ea5e9;">Dept User</span>';
    }

    function renderAccounts(accounts) {
      tbody.innerHTML = '';
      if (!allAccounts.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:20px;">No web accounts yet. Click <b>Create Web Account</b> to add one.</td></tr>';
        return;
      }
      if (!accounts.length) {
        waPage = Math.max(1, waPage - 1);
        renderWaPage();
        return;
      }
      accounts.forEach(a => {
        const tr = document.createElement('tr');
        const accountId = (a && a.id != null) ? String(a.id) : '';
        const accountEmail = (a && a.email != null) ? String(a.email) : '';
        tr.innerHTML =
          '<td style="font-weight:500;">' + (a.name||'') + '</td>' +
          '<td style="font-size:13px;color:#64748b;">' + (a.email||'') + '</td>' +
          '<td>' + roleBadge(a.role) + '</td>' +
          '<td>' + (a.department||'<span style=\"color:#94a3b8;\">—</span>') + '</td>' +
          '<td style="text-align:center;white-space:nowrap;">' +
            '<button class="action-btn secondary wa-edit-btn" data-id="' + accountId + '" data-email="' + accountEmail + '" title="Edit"><i class="fas fa-edit"></i></button> ' +
            '<button class="action-btn danger wa-del-btn" data-id="' + accountId + '" data-email="' + accountEmail + '" title="Delete"><i class="fas fa-trash-alt"></i></button>' +
          '</td>';
        tbody.appendChild(tr);
      });
      // Bind edit — use email as primary key (always unique), id as secondary
      tbody.querySelectorAll('.wa-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = String(btn.dataset.id || '');
          const email = String(btn.dataset.email || '').toLowerCase();
          const acc = allAccounts.find(a =>
            (email && String(a.email || '').toLowerCase() === email) ||
            (id && id !== '0' && String(a.id) === id)
          );
          if (acc) openWaModal(acc);
        });
      });
      // Bind delete — use email as primary key (always unique), id as secondary
      tbody.querySelectorAll('.wa-del-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = String(btn.dataset.id || '');
          const email = String(btn.dataset.email || '').toLowerCase();
          const acc = allAccounts.find(a =>
            (email && String(a.email || '').toLowerCase() === email) ||
            (id && id !== '0' && String(a.id) === id)
          );
          if (!acc) { _toast('Account not found in table.', 'error'); return; }
          if (!acc.id && !acc.email) { _toast('Account has no ID or email.', 'error'); return; }
          if (!confirm('Delete web account "' + acc.name + '"?')) return;
          btn.disabled = true;
          btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
          try {
            const fd = new FormData();
            if (acc.id !== undefined && acc.id !== null && String(acc.id) !== '') {
              fd.append('id', acc.id);
            }
            if (acc.email) fd.append('email', acc.email);
            const res = await fetch('usercontrol.php?action=delete_web_account', { method: 'POST', body: fd });
            const j = await res.json();
            if (j.success) { _toast('Account deleted', 'success'); loadAccounts(); }
            else {
              _toast(j.message || 'Cannot delete this account', 'error');
              btn.disabled = false;
              btn.innerHTML = '<i class="fas fa-trash-alt"></i>';
            }
          } catch(e) {
            _toast('Network error', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i>';
          }
        });
      });
    }

    function loadAccounts() {
      fetch('usercontrol.php?action=list_web_accounts', { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
          allAccounts = d.accounts || [];
          renderWaPage();
        })
        .catch(err => {
          console.error('loadAccounts error:', err);
          tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#ef4444;padding:20px;"><i class="fas fa-exclamation-triangle"></i> Could not load web accounts.</td></tr>';
        });
    }

    // Save handler
    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const name = (nameField.value || '').trim();
        const email = (emailField.value || '').trim();
        const role = roleField.value;
        const dept = deptField.value;
        const pass = passField.value;
        const confirm = confirmField.value;

        if (!name || !email) {
          _toast('Name and email are required.', 'error');
          return;
        }
        if (!editingId && !pass) {
          _toast('Password is required for new accounts.', 'error');
          return;
        }
        if (pass && pass.length < 8) {
          _toast('Password must be at least 8 characters.', 'error');
          return;
        }
        if (pass && pass !== confirm) {
          passMatchErr.style.display = 'block';
          _toast('Passwords do not match.', 'error');
          return;
        }
        passMatchErr.style.display = 'none';

        saveBtn.disabled = true;
        const prevText = saveBtn.textContent;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Saving...';

        const fd = new FormData();
        fd.append('name', name);
        fd.append('email', email);
        fd.append('role', role);
        fd.append('department', dept);
        if (pass) fd.append('password', pass);

        const action = editingId ? 'edit_web_account' : 'add_web_account';
        if (editingId) {
          fd.append('id', editingId);
          if (emailField.dataset.originalEmail) {
            fd.append('original_email', emailField.dataset.originalEmail);
          }
        }

        try {
          const res = await fetch('usercontrol.php?action=' + action, { method: 'POST', body: fd });
          const j = await res.json();
          if (j.success) {
            _toast(editingId ? 'Account updated!' : 'Account created!', 'success');
            closeWaModal();
            loadAccounts();
          } else {
            _toast(j.message || 'Operation failed.', 'error');
          }
        } catch(e) {
          _toast('Network error.', 'error');
        }
        saveBtn.disabled = false;
        saveBtn.textContent = prevText;
      });
    }

    // Init
    loadAccounts();

    // Refresh dept dropdown when departments change (listen for custom event)
    window.addEventListener('deptChanged', () => {
      fetch('usercontrol.php?action=list_departments', { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
          if (!deptField) return;
          const cur = deptField.value;
          deptField.innerHTML = '<option value="">Select Department</option>';
          (d.departments || []).forEach(dept => {
            const o = document.createElement('option');
            o.value = dept.name; o.textContent = dept.name;
            if (dept.name === cur) o.selected = true;
            deptField.appendChild(o);
          });
        }).catch(() => {});
    });
  })();
  </script>
</body>
</html>