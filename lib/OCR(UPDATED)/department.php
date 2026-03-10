<?php
require_once __DIR__ . '/config.php';

// Create connection
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

// Handle Add/Edit Department Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0; // Department ID for editing, 0 for new
    $department_name = mysqli_real_escape_string($connection, $_POST["department_name"]);
    $head = mysqli_real_escape_string($connection, $_POST["head"]);
    $employees = (int)$_POST["employees"];
    $contact = mysqli_real_escape_string($connection, $_POST["contact"]);
    $location = mysqli_real_escape_string($connection, $_POST["location"]);

    if ($id > 0) {
        // Update existing department
        $sql = "UPDATE departments SET department_name=?, head=?, employees=?, contact=?, location=? WHERE id=?";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("ssissi", $department_name, $head, $employees, $contact, $location, $id);
    } else {
        // Add new department
        $sql = "INSERT INTO departments (department_name, head, employees, contact, location) VALUES (?, ?, ?, ?, ?)";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("ssiss", $department_name, $head, $employees, $contact, $location);
    }

    if ($stmt->execute()) {
        $stmt->close();
        header("Location: department.php?status=" . ($id > 0 ? "updated" : "added"));
        exit();
    } else {
        echo "<script>console.error('Error: " . $stmt->error . "');</script>";
        $stmt->close();
        header("Location: department.php?status=error");
        exit();
    }
}

// Handle Delete Department Request
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $sql = "DELETE FROM departments WHERE id = ?";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $delete_id);

    if ($stmt->execute()) {
        header("Location: department.php?status=deleted");
        exit();
    } else {
        echo "<script>console.error('Error deleting record: " . $stmt->error . "');</script>";
        header("Location: department.php?status=delete_error");
        exit();
    }
    
}

// Fetching department data
$departments = [];
$query = "SELECT * FROM `departments` ORDER BY id ASC"; // Assuming 'departments' is your table name
$result = $connection->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    $result->free();
} else {
    echo "<script>console.error('Error fetching departments: " . $connection->error . "');</script>";
}

// Calculate summary statistics
$totalDepartments = count($departments);
$totalEmployees = 0;
foreach ($departments as $dept) {
    $totalEmployees += $dept['employees'];
}

// Close connection at the end of the script
$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Departments - CHRMO Document Tracking</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/animations.css" />
  <script src="assets/smooth-interactions.js" defer></script>
  <style>
    :root {
      --primary: #0891b2;
      --primary-light: #cffafe;
      --primary-dark: #0e7490;
      --secondary: #06b6d4;
      --text-dark: #263238;
      --text-secondary: #78909C;
      --white: #FFFFFF;
      --light-bg: #F5F7FA;
      --border: #E0E0E0;
      --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      --shadow-md: 0 8px 15px rgba(0, 0, 0, 0.1);
      --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.15); /* Stronger shadow for hover */
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Roboto', 'Segoe UI', sans-serif; }
    body { background-color: var(--light-bg); color: var(--text-dark); overflow-x: hidden; /* Prevent horizontal scroll during animations */ }
    .container { display: flex; min-height: 100vh; }
    /* Sidebar - Collapsible */
    .sidebar { width: 70px; background: linear-gradient(to bottom, var(--primary-dark), var(--secondary)); color: var(--white); padding: 20px 0; box-shadow: var(--shadow); position: fixed; height: 100vh; transition: width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1); overflow: hidden; transform: translateZ(0); backface-visibility: hidden; contain: layout style; will-change: width; }
    .sidebar:hover { width: 260px; }
    .sidebar-header { display: flex; align-items: center; padding: 0 20px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
    .sidebar-header h2 { font-size: 20px; margin-left: 10px; color: var(--white); opacity: 0; white-space: nowrap; transition: opacity 0.2s ease 0.1s; }
    .sidebar-header img { height: 40px; width: auto; margin-right: 10px; }
    .sidebar:not(:hover) .sidebar-header { justify-content: center; }
    .sidebar:not(:hover) .sidebar-header h2 { display: none; }
    .sidebar:not(:hover) .sidebar-header img { margin-right: 0; }
    .sidebar:hover .sidebar-header h2 { opacity: 1; display: inline; white-space: normal; }
    .sidebar-menu { margin-top: 30px; }
    .menu-item { display: flex; align-items: center; padding: 15px 20px; color: var(--white); text-decoration: none; transition: all 0.3s; margin-bottom: 5px; }
    .menu-item:hover, .menu-item.active { background-color: rgba(255, 255, 255, 0.1); border-left: 4px solid var(--white); }
    .menu-item i { margin-right: 15px; color: var(--white); width: 24px; min-width: 24px; text-align: center; transition: margin 0.2s ease; }
    .menu-item span { font-size: 15px; color: var(--white); opacity: 0; white-space: nowrap; transition: opacity 0.2s ease; }
    .sidebar:hover .menu-item span { opacity: 1; }
    .sidebar:not(:hover) .menu-item { justify-content: center; }
    .sidebar:not(:hover) .menu-item i { margin-right: 0; }
    .sidebar:not(:hover) .menu-item span { display: none; }
    /* Prevent left border shifting when collapsed */
    .sidebar:not(:hover) .menu-item:hover,
    .sidebar:not(:hover) .menu-item.active { border-left: none; }
    /* Main Content */
    .main-content {
      flex: 1;
      margin-left: 70px;
      padding: 24px 28px 40px;
      min-width: 0;
      color: var(--text-dark);
      background: var(--light-bg);
      opacity: 0; /* Start hidden for fade-in */
      transform: translateY(10px); /* Start slightly below for slide-up */
      animation: fadeInSlideUp 0.6s ease-out forwards; /* Apply animation */
      transition: margin-left 0.3s ease;
    }
    .sidebar:hover ~ .main-content { margin-left: 260px; }
    /* Keyframe for main content fade-in and slide-up */
    @keyframes fadeInSlideUp {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      background: var(--white);
      padding: 18px 24px;
      border-radius: 12px;
      box-shadow: var(--shadow);
      gap: 16px;
      color: var(--text-dark);
      position: relative;
      transition: all 0.3s ease; /* Add transition for hover effect */
    }
    .top-bar:hover {
      box-shadow: var(--shadow-lg); /* Stronger shadow on hover */
      transform: translateY(-2px); /* Slight lift on hover */
    }
    .top-bar h2 { font-size: 26px; font-weight: 700; color: var(--text-dark); }
    .search-bar { position: relative; max-width: 400px; flex-grow: 1; display: flex; align-items: center; background: var(--light-bg); border-radius: 24px; padding: 8px 16px; }
    .search-bar input { border: none; background: transparent; outline: none; padding: 4px 8px; width: 100%; color: var(--text-dark); }
    .search-bar i { color: var(--primary-dark); margin-right: 8px; }
    /* User Profile Dropdown */
    .user-profile { display: flex; align-items: center; cursor: pointer; position: relative; gap: 10px; }
    .user-profile img { width: 40px; height: 40px; border-radius: 50%; margin-right: 10px; }
    .dropdown-menu { position: absolute; top: 100%; right: 0; background-color: var(--white); border-radius: 8px; box-shadow: var(--shadow-md); min-width: 200px; z-index: 100; display: none; margin-top: 8px; overflow: hidden; }
    .dropdown-menu.show { display: block; }
    .dropdown-item { padding: 12px 16px; font-size: 14px; color: var(--text-dark); cursor: pointer; display: flex; align-items: center; gap: 10px; transition: background-color 0.2s ease; }
    .dropdown-item:hover { background-color: var(--primary-light); color: var(--primary-dark); }
    /* Notification Dropdown */
    .notification-icon { margin-right: 20px; position: relative; cursor: pointer; font-size: 1.25rem; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
    .notification-badge { position: absolute; top: -5px; right: -5px; background-color: #FF5252; color: white; font-size: 10px; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .notification-dropdown { position: absolute; top: 100%; right: 0; background-color: var(--white); border-radius: 5px; box-shadow: var(--shadow); width: 350px; z-index: 100; display: none; margin-top: 10px; max-height: 400px; overflow-y: auto; }
    .notification-dropdown.show { display: block; }
    .notification-header { padding: 15px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .notification-header h3 { margin: 0; font-size: 16px; }
    .notification-clear { color: var(--primary); cursor: pointer; font-size: 14px; }
    .notification-item { padding: 15px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background-color 0.2s; }
    .notification-item:hover { background-color: var(--light-bg); }
    .notification-item.unread { background-color: rgba(0, 188, 212, 0.05); }
    .notification-title { font-weight: 500; margin-bottom: 5px; display: flex; justify-content: space-between; }
    .notification-time { color: var(--text-light); font-size: 12px; }
    .notification-content { font-size: 14px; color: var(--text-dark); }
    /* Summary Cards */
    .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 28px; }
    .summary-card {
      background: var(--white);
      border-radius: 12px;
      padding: 20px;
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      gap: 16px;
      color: var(--text-dark);
      transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; /* Added transitions */
    }
    .summary-card:hover { /* Hover animation */
      transform: translateY(-5px);
      box-shadow: var(--shadow-md);
    }
    .summary-card i { font-size: 24px; color: var(--primary-dark); background: var(--primary-light); border-radius: 50%; padding: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; }
    .sc-label { color: var(--text-secondary); font-size: 14px; font-weight: 500; }
    .sc-value { font-size: 24px; font-weight: 800; color: var(--text-dark); }
    .filter-bar { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; background: var(--white); padding: 16px 20px; border-radius: 12px; box-shadow: var(--shadow); color: var(--text-dark); }
    .filter-group { display: flex; align-items: center; gap: 8px; }
    .filter-label { font-size: 14px; color: var(--text-secondary); }
    .filter-select { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border); background: var(--white); color: var(--text-dark); font-size: 14px; }
    .filter-btn {
      padding: 8px 20px;
      border-radius: 8px;
      background: var(--primary);
      color: var(--white);
      border: none;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: background 0.3s ease, transform 0.2s ease; /* Added transform transition */
      box-shadow: var(--shadow); /* Add initial shadow */
    }
    .filter-btn.secondary { background: var(--light-bg); color: var(--primary-dark); border: 1px solid var(--border); }
    .filter-btn i { color: var(--white); font-size: 16px; margin-right: 6px; }
    .filter-btn.secondary i { color: var(--primary-dark); }
    .filter-btn:hover {
      background: var(--primary-dark);
      color: var(--white);
      transform: translateY(-2px); /* Lift effect on hover */
      box-shadow: var(--shadow-md); /* Stronger shadow on hover */
    }
    .filter-btn.secondary:hover {
      background: var(--primary-light);
      color: var(--primary-dark);
      border-color: var(--primary-dark);
      transform: translateY(-2px); /* Lift effect on hover */
      box-shadow: var(--shadow-md); /* Stronger shadow on hover */
    }
    .table-section { background: var(--white); border-radius: 12px; box-shadow: var(--shadow); padding: 24px; overflow-x: auto; color: var(--text-dark); }
    .table-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .table-header h3 { color: var(--text-dark); font-weight: 700; font-size: 20px; }
    .table-header .actions .filter-btn { font-size: 14px; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; color: var(--text-dark); }
    th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border); color: var(--text-dark); }
    th { background: #ECEFF1; color: var(--text-dark); font-size: 14px; }
    /* Table row hover effect */
    tbody tr:hover {
      background-color: rgba(0, 188, 212, 0.05); /* Light primary color background */
      transition: background-color 0.2s ease;
    }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; color: var(--white); background: var(--secondary); }
    .badge.warning { background: #f2994a; color: var(--white);}
    .badge.danger { background: #f44336; color: var(--white);}

    /* Adjusted styles for actions */
    .actions { display: flex; gap: 5px; /* Reduced gap for smaller buttons */ }
    .action-btn {
      border: 1px solid var(--border); /* Add a subtle border */
      background: var(--light-bg); /* Give it a background */
      color: var(--text-dark); /* Default text color */
      cursor: pointer;
      font-size: 13px; /* Slightly smaller font to fit text without icons */
      padding: 8px 12px; /* Increased padding to make buttons bigger */
      border-radius: 8px; /* Rounded corners */
      transition: all 0.2s ease, transform 0.2s ease; /* Smooth transition on hover, include transform */
      font-weight: 500; /* Make text a bit bolder */
      white-space: nowrap; /* Prevent text from wrapping */
      box-shadow: var(--shadow); /* Add initial shadow */
    }
    .action-btn:hover {
      background: var(--primary-light);
      color: var(--primary-dark);
      border-color: var(--primary-dark);
      transform: translateY(-2px); /* Lift effect on hover */
      box-shadow: var(--shadow-md); /* Stronger shadow on hover */
    }
    .action-btn.view-details { color: var(--primary-dark); border-color: var(--primary-light); }
    .action-btn.view-details:hover { background: var(--primary-light); color: var(--primary-dark); border-color: var(--primary-dark); }
    .action-btn.edit { color: #007C91; border-color: #B3E0E9; }
    .action-btn.edit:hover { background: #E0F2F7; color: #007C91; border-color: #007C91; }
    .action-btn.deactivate { color: #f44336; border-color: #FFCDD2; } /* Re-purposing deactivate for status change if needed, or remove */
    .action-btn.deactivate:hover { background: #FFEBEE; color: #f44336; border-color: #f44336; }
    .action-btn.delete { color: #D32F2F; border-color: #EF9A9A; }
    .action-btn.delete:hover { background: #FFEBEE; color: #D32F2F; border-color: #D32F2F; }


    /* New/Modified Styles for interactive features and expanded sections */
    .modal {
      display: none; /* Hidden by default */
      position: fixed; /* Stay in place */
      z-index: 1000; /* Sit on top */
      left: 0;
      top: 0;
      width: 100%; /* Full width */
      height: 100%; /* Full height */
      overflow: auto; /* Enable scroll if needed */
      background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
      justify-content: center;
      align-items: center;
      opacity: 0; /* Start hidden for fade-in */
      transition: opacity 0.3s ease-out; /* Smooth fade-in */
    }
    .modal.show {
      display: flex;
      opacity: 1; /* Fade in */
    }
    .modal-content {
      background-color: var(--white);
      margin: auto;
      padding: 30px;
      border-radius: 12px;
      box-shadow: var(--shadow-md);
      width: 80%;
      max-width: 700px;
      position: relative;
      transform: translateY(-20px); /* Start slightly above for slide-down */
      transition: transform 0.3s ease-out; /* Smooth slide-down */
    }
    .modal.show .modal-content {
        transform: translateY(0); /* Slide down to original position */
    }
    .close-button {
      color: var(--text-secondary);
      font-size: 28px;
      font-weight: bold;
      position: absolute;
      top: 15px;
      right: 20px;
      cursor: pointer;
    }
    .close-button:hover,
    .close-button:focus {
      color: var(--text-dark);
      text-decoration: none;
      cursor: pointer;
    }
    .modal-content h3 {
        margin-bottom: 20px;
        color: var(--primary-dark);
        font-size: 24px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 10px;
    }
    
    /* Department Info Container Styles */
    .department-info-container {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid #e2e8f0;
    }
    .department-info-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 24px;
        padding-bottom: 20px;
        border-bottom: 2px solid #e2e8f0;
    }
    .department-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
    }
    .department-icon i {
        font-size: 24px;
        color: white;
    }
    .department-title {
        flex: 1;
    }
    .department-title h4 {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0 0 8px 0;
    }
    .department-badge {
        display: inline-block;
        padding: 4px 12px;
        background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
        color: white;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .department-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }
    .info-section {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid #f1f5f9;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .info-section:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }
    .info-section h5 {
        font-size: 16px;
        font-weight: 600;
        color: var(--primary-dark);
        margin: 0 0 16px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .info-section h5 i {
        font-size: 14px;
        color: var(--primary);
    }
    .info-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .info-item:last-child {
        border-bottom: none;
    }
    .info-label {
        font-weight: 500;
        color: var(--text-secondary);
        font-size: 14px;
    }
    .info-value {
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
        text-align: right;
        max-width: 60%;
        word-wrap: break-word;
    }
    .activity-timeline {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid #f1f5f9;
    }
    .activity-timeline h5 {
        font-size: 16px;
        font-weight: 600;
        color: var(--primary-dark);
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .activity-timeline h5 i {
        font-size: 14px;
        color: var(--primary);
    }
    .timeline-container {
        position: relative;
        padding-left: 24px;
    }
    .timeline-container::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, var(--primary) 0%, var(--primary-light) 100%);
        border-radius: 1px;
    }
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
        padding: 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .timeline-item:hover {
        transform: translateX(4px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -16px;
        top: 20px;
        width: 12px;
        height: 12px;
        background: var(--primary);
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 0 0 2px var(--primary-light);
    }
    .timeline-item:last-child {
        margin-bottom: 0;
    }
    .timeline-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
    }
    .timeline-user {
        font-weight: 600;
        color: var(--text-dark);
        font-size: 14px;
    }
    .timeline-time {
        color: var(--text-secondary);
        font-size: 12px;
        font-weight: 500;
    }
    .timeline-action {
        color: var(--text-dark);
        font-size: 14px;
        line-height: 1.4;
    }
    .timeline-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }
    .timeline-status.completed {
        background: rgba(76, 175, 80, 0.1);
        color: #2e7d32;
    }
    .timeline-status.pending {
        background: rgba(255, 193, 7, 0.1);
        color: #f57c00;
    }
    .timeline-status.review {
        background: rgba(33, 150, 243, 0.1);
        color: #1565c0;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: var(--text-dark);
    }
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 15px;
        color: var(--text-dark);
        background-color: var(--light-bg);
    }
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
    /* Toast Notifications */
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
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: var(--shadow-lg);
        opacity: 0;
        transform: translateY(-20px);
        animation: toastIn 0.3s forwards, toastOut 0.3s forwards 2.7s;
        min-width: 250px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .toast.success { background-color: #4CAF50; } /* Green */
    .toast.error { background-color: #F44336; } /* Red */
    .toast.info { background-color: #2196F3; } /* Blue */
    .toast.warning { background-color: #FFC107; } /* Amber */

    @keyframes toastIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes toastOut {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(-20px); }
    }

    /* Confirmation Modal Specific Styles */
    .confirm-modal-content {
        background-color: var(--white);
        margin: auto;
        padding: 25px;
        border-radius: 12px;
        box-shadow: var(--shadow-lg);
        width: 90%;
        max-width: 450px; /* Smaller width for confirmation */
        position: relative;
        transform: translateY(-20px);
        transition: transform 0.3s ease-out;
    }
    .confirm-modal-content h3 {
        margin-bottom: 15px;
        color: var(--primary-dark);
        font-size: 20px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 10px;
    }
    .confirm-modal-content p {
        font-size: 15px;
        color: var(--text-dark);
        margin-bottom: 20px;
    }
    .confirm-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
    }


    @media (max-width: 992px) {
      .main-content { margin-left: 70px; }
    }
  </style>
</head>
<body>
<div class="container">
    <div class="sidebar">
      <div class="sidebar-header">
        <img src="hr.png" alt="CHRMO Logo" />
        <h2>CHRMO Document Management</h2>
      </div>
      <div class="sidebar-menu">
        <a href="dashboard.php" class="menu-item">
          <i class="fas fa-tachometer-alt"></i>
          <span>Dashboard</span>
        </a>
        <a href="department.php" class="menu-item active">
          <i class="fas fa-building"></i>
          <span>Departments</span>
        </a>
        <a href="tracking.php" class="menu-item"> <i class="fas fa-file-signature"></i>
          <span>Document Status</span>
        </a>
        <a href="stats.php" class="menu-item">
          <i class="fas fa-chart-bar"></i>
          <span>Status Reports</span>
        </a>
        <a href="archive.php".php" class="menu-item">
          <i class="fas fa-archive"></i>
          <span>Archived Records</span>
        </a>
        <a href="usercontrol.php" class="menu-item">
          <i class="fas fa-user-shield"></i>
          <span>User Control</span>
        </a>
      </div>
    </div>

    <div class="main-content">
      <div class="top-bar">
        <h2>Department Management</h2>
        <div class="search-bar">
          <i class="fas fa-search"></i>
          <input type="search" placeholder="Search departments..." />
        </div>
        <div style="display: flex; align-items: center;">
          <div class="notification-icon" id="notificationIcon">
            <i class="fas fa-bell fa-lg"></i>
            <span class="notification-badge" id="notificationBadge">3</span>
            <div class="notification-dropdown" id="notificationDropdown">
              <div class="notification-header">
                <h3>Notifications</h3>
                <div class="notification-clear" id="clearNotifications">Clear All</div>
              </div>
              <div class="notification-item unread">
                <div class="notification-title">
                  <span>New Document Uploaded</span>
                  <span class="notification-time">2 min ago</span>
                </div>
                <div class="notification-content">Payroll documents for June have been uploaded by CPDO</div>
              </div>
              <div class="notification-item unread">
                <div class="notification-title">
                  <span>Approval Required</span>
                  <span class="notification-time">15 min ago</span>
                </div>
                <div class="notification-content">3 documents are pending your approval in the CBO</div>
              </div>
              <div class="notification-item">
                <div class="notification-title">
                  <span>System Update</span>
                  <span class="notification-time">1 hour ago</span>
                </div>
                <div class="notification-content">Scheduled maintenance this weekend. System will be unavailable from 10PM to 2AM</div>
              </div>
              <div class="notification-item">
                <div class="notification-title">
                  <span>New Feature Added</span>
                  <span class="notification-time">Yesterday</span>
                </div>
                <div class="notification-content">Batch document upload feature is now available. Try it out!</div>
              </div>
            </div>
          </div>
          <div class="user-profile" id="userProfile">
            <img src="https://placehold.co/40x40/00BCD4/FFFFFF?text=JR" alt="User" />
            <div>
              <div><?php echo htmlspecialchars($userInfo ? $userInfo['name'] : 'User'); ?></div>
              <small style="color: var(--text-secondary);"><?php echo htmlspecialchars(function_exists('formatUserRole') ? formatUserRole($userInfo ? $userInfo['role'] : 'user') : ($userInfo ? ucfirst($userInfo['role']) : 'User')); ?></small>
            </div>
            <i class="fas fa-chevron-down" style="margin-left: 10px;"></i>
            <div class="dropdown-menu" id="userDropdown">
              <div class="dropdown-item" style="border-top: 1px solid var(--border);">
                <a href="logout.php" style="text-decoration: none; color: inherit; display: block; width: 100%; padding: 12px 16px;">
                  <i class="fas fa-sign-out-alt" style="width: 20px; color: #ef4444;"></i> <span style="color: #ef4444; font-weight: 500;">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="summary-cards">
        <div class="summary-card">
          <i class="fas fa-sitemap"></i>
          <div>
            <div class="sc-label">Total Departments</div>
            <div class="sc-value"><?php echo $totalDepartments; ?></div>
          </div>
        </div>
        <div class="summary-card">
          <i class="fas fa-users-cog"></i>
          <div>
            <div class="sc-label">Total Employees Across Depts</div>
            <div class="sc-value"><?php echo $totalEmployees; ?></div>
          </div>
        </div>
        <div class="summary-card">
          <i class="fas fa-map-marker-alt"></i>
          <div>
            <div class="sc-label">Unique Locations</div>
            <div class="sc-value">3</div> </div>
        </div>
        </div>

      <div class="filter-bar">
        <div class="filter-group">
          <span class="filter-label">Location:</span>
          <select class="filter-select" id="locationFilter">
            <option value="">All Locations</option>
            <option>Main Campus</option>
            <option>Annex Building</option>
            <option>Remote Office</option>
          </select>
        </div>
        <div class="filter-group">
          <span class="filter-label">Employees:</span>
          <select class="filter-select" id="employeeCountFilter">
            <option value="">All Counts</option>
            <option value="1-10">1-10</option>
            <option value="11-50">11-50</option>
            <option value="51+">51+</option>
          </select>
        </div>
        <button class="filter-btn" id="applyFiltersBtn">
          <i class="fas fa-filter"></i> Apply Filters
        </button>
        <button class="filter-btn secondary" id="clearFiltersBtn">
          <i class="fas fa-eraser"></i> Clear Filters
        </button>
      </div>

      <div class="table-section">
        <div class="table-header">
          <h3>Department List</h3>
          <div class="actions">
            <button class="filter-btn" id="exportBtn">
              <i class="fas fa-file-export"></i> Export
            </button>
            <button class="filter-btn" id="addDepartmentBtn">
              <i class="fas fa-plus"></i> Add Department
            </button>
          </div>
        </div>
        <table id="departmentTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Department Name</th>
              <th>Head</th>
              <th>Employees</th>
              <th>Contact</th>
              <th>Location</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
              if (!empty($departments)) {
                  foreach ($departments as $dept) {
            ?>
            <tr data-department-id="<?php echo $dept['id']; ?>">
              <td><?php echo $dept['id']; ?></td>
              <td><?php echo htmlspecialchars($dept['department_name']); ?></td>
              <td><?php echo htmlspecialchars($dept['head']); ?></td>
              <td><span class="badge"><?php echo $dept['employees']; ?></td>
              <td><?php echo htmlspecialchars($dept['contact']); ?></td>
              <td><?php echo htmlspecialchars($dept['location']); ?></td>
              <td class="actions">
                <button class="action-btn view-details" data-department-id="<?php echo $dept['id']; ?>">View Details</button>
                <button class="action-btn edit" data-department-id="<?php echo $dept['id']; ?>">Edit</button>
                <button class="action-btn delete" data-department-id="<?php echo $dept['id']; ?>">Delete</button>
              </td>
            </tr>
            <?php
                  }
              } else {
            ?>
            <tr>
                <td colspan="7" style="text-align:center; padding:20px; color:var(--text-secondary);">No department records found.</td>
            </tr>
            <?php
              }
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="addEditDepartmentModal" class="modal">
    <div class="modal-content">
      <span class="close-button">&times;</span>
      <h3 id="modalTitle">Add New Department</h3>
      <form id="departmentForm" method="POST" action="department.php">
        <input type="hidden" id="departmentId" name="id" value="">
        <div class="form-group">
          <label for="departmentName">Department Name</label>
          <input type="text" id="departmentName" name="department_name" required>
        </div>
        <div class="form-group">
          <label for="head">Head</label>
          <input type="text" id="head" name="head" required>
        </div>
        <div class="form-group">
          <label for="employees">Number of Employees</label>
          <input type="number" id="employees" name="employees" min="0" required>
        </div>
        <div class="form-group">
          <label for="contact">Contact Info</label>
          <input type="text" id="contact" name="contact" required>
        </div>
        <div class="form-group">
          <label for="location">Location</label>
          <input type="text" id="location" name="location" required>
        </div>
        <div class="form-actions">
          <button type="button" class="filter-btn secondary" id="cancelAddEdit">Cancel</button>
          <button type="submit" class="filter-btn">Save Department</button>
        </div>
      </form>
    </div>
  </div>

  <div id="viewDepartmentModal" class="modal">
    <div class="modal-content">
      <span class="close-button">&times;</span>
      <h3 id="viewDepartmentName">Department Details</h3>

      <div class="department-info-container">
        <div class="department-info-header">
          <div class="department-icon">
            <i class="fas fa-building"></i>
          </div>
          <div class="department-title">
            <h4 id="detailDepartmentNameTitle"></h4>
            <span class="department-badge">Active Department</span>
          </div>
        </div>
        
        <div class="department-info-grid">
          <div class="info-section">
            <h5><i class="fas fa-info-circle"></i> Department Information</h5>
            <div class="info-item">
              <span class="info-label">Department ID:</span>
              <span class="info-value" id="detailId"></span>
            </div>
            <div class="info-item">
              <span class="info-label">Department Name:</span>
              <span class="info-value" id="detailDepartmentName"></span>
            </div>
            <div class="info-item">
              <span class="info-label">Department Head:</span>
              <span class="info-value" id="detailHead"></span>
            </div>
          </div>
          
          <div class="info-section">
            <h5><i class="fas fa-users"></i> Staff & Contact</h5>
            <div class="info-item">
              <span class="info-label">Number of Employees:</span>
              <span class="info-value" id="detailEmployees"></span>
            </div>
            <div class="info-item">
              <span class="info-label">Contact Information:</span>
              <span class="info-value" id="detailContact"></span>
            </div>
          </div>
          
          <div class="info-section">
            <h5><i class="fas fa-map-marker-alt"></i> Location Details</h5>
            <div class="info-item">
              <span class="info-label">Location:</span>
              <span class="info-value" id="detailLocation"></span>
            </div>
            <div class="info-item">
              <span class="info-label">Status:</span>
              <span class="info-value">Active</span>
            </div>
          </div>
        </div>
        
        <div class="activity-timeline">
          <h5><i class="fas fa-history"></i> Recent Activity</h5>
          <div class="timeline-container" id="departmentActivityLog">
            <!-- Timeline items will be populated here -->
          </div>
        </div>
      </div>

    </div>
  </div>

  <div id="confirmActionModal" class="modal">
    <div class="confirm-modal-content">
      <span class="close-button" id="closeConfirmModalBtn">&times;</span>
      <h3 id="confirmModalTitle">Confirm Action</h3>
      <p id="confirmModalMessage">Are you sure you want to proceed with this action?</p>
      <div class="confirm-modal-footer">
        <button type="button" class="filter-btn secondary" id="cancelConfirmBtn">Cancel</button>
        <button type="button" class="filter-btn danger" id="proceedConfirmBtn">Proceed</button>
      </div>
    </div>
  </div>


  <script>
    // User Profile Dropdown
    const userProfile = document.getElementById('userProfile');
    const userDropdown = document.getElementById('userDropdown');
    userProfile.addEventListener('click', function(e) {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
    });
    document.body.addEventListener('click', function() {
      userDropdown.classList.remove('show');
    });

    // Notification dropdown toggling and clearing
    const notificationIcon = document.getElementById('notificationIcon');
    const notificationBadge = document.getElementById('notificationBadge');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const clearNotifications = document.getElementById('clearNotifications');
    const notificationItems = document.querySelectorAll('.notification-item');

    notificationIcon.addEventListener('click', e => {
      e.stopPropagation();
      notificationDropdown.classList.toggle('show');
      if (notificationDropdown.classList.contains('show')) {
        notificationItems.forEach(item => item.classList.remove('unread'));
        notificationBadge.textContent = '0';
      }
    });

    clearNotifications.addEventListener('click', e => {
      e.stopPropagation();
      notificationDropdown.querySelectorAll('.notification-item').forEach(item => item.remove());
      notificationBadge.textContent = '0';
      const noNotifications = document.createElement('div');
      noNotifications.className = 'notification-item';
      noNotifications.innerHTML = `<div class="notification-content" style="text-align:center; padding:20px;">No notifications available</div>`;
      notificationDropdown.appendChild(noNotifications);
    });

    document.addEventListener('click', () => {
      notificationDropdown.classList.remove('show');
    });

    notificationDropdown.addEventListener('click', e => e.stopPropagation());

    // --- Department Management Specific JS ---

    // Get the modals and buttons
    const addEditDepartmentModal = document.getElementById('addEditDepartmentModal');
    const viewDepartmentModal = document.getElementById('viewDepartmentModal');
    const addDepartmentBtn = document.getElementById('addDepartmentBtn');
    const cancelAddEditBtn = document.getElementById('cancelAddEdit');
    const departmentForm = document.getElementById('departmentForm');
    const modalTitle = document.getElementById('modalTitle');
    const exportBtn = document.getElementById('exportBtn');
    const applyFiltersBtn = document.getElementById('applyFiltersBtn');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const locationFilter = document.getElementById('locationFilter');
    const employeeCountFilter = document.getElementById('employeeCountFilter');
    const departmentTableBody = document.querySelector('#departmentTable tbody'); // Get the tbody element

    // Confirmation Modal Elements
    const confirmActionModal = document.getElementById('confirmActionModal');
    const closeConfirmModalBtn = document.getElementById('closeConfirmModalBtn');
    const confirmModalTitle = document.getElementById('confirmModalTitle');
    const confirmModalMessage = document.getElementById('confirmModalMessage');
    const cancelConfirmBtn = document.getElementById('cancelConfirmBtn');
    const proceedConfirmBtn = document.getElementById('proceedConfirmBtn');

    let currentConfirmAction = null; // To store the action to be performed after confirmation


    // --- Toast Notification Function ---
    function showToast(message, type = 'info', duration = 3000) {
        const toastContainer = document.getElementById('toast-container') || (() => {
            const div = document.createElement('div');
            div.id = 'toast-container';
            document.body.appendChild(div);
            return div;
        })();

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let iconClass = '';
        if (type === 'success') iconClass = 'fa-check-circle';
        else if (type === 'error') iconClass = 'fa-times-circle';
        else if (type === 'info') iconClass = 'fa-info-circle';
        else if (type === 'warning') iconClass = 'fa-exclamation-triangle';

        toast.innerHTML = `<i class="fas ${iconClass}"></i> ${message}`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.remove();
            if (toastContainer.children.length === 0) {
                toastContainer.remove(); // Remove container if no toasts left
            }
        }, duration);
    }


    // --- Custom Confirmation Modal Functions ---
    function openConfirmModal(title, message, callback) {
        confirmModalTitle.textContent = title;
        confirmModalMessage.textContent = message;
        currentConfirmAction = callback; // Store the callback function
        confirmActionModal.classList.add('show');
    }

    function closeConfirmModal() {
        confirmActionModal.classList.remove('show');
        currentConfirmAction = null; // Clear the callback
    }

    // Event listeners for the custom confirmation modal
    closeConfirmModalBtn.addEventListener('click', closeConfirmModal);
    cancelConfirmBtn.addEventListener('click', closeConfirmModal);
    proceedConfirmBtn.addEventListener('click', () => {
        if (currentConfirmAction) {
            currentConfirmAction(); // Execute the stored callback
        }
        closeConfirmModal();
    });
    confirmActionModal.addEventListener('click', (event) => {
        if (event.target === confirmActionModal) {
            closeConfirmModal();
        }
    });


    // Close buttons for modals
    document.querySelectorAll('.close-button').forEach(button => {
        button.addEventListener('click', function() {
            addEditDepartmentModal.classList.remove('show'); // Use class for animation
            viewDepartmentModal.classList.remove('show'); // Use class for animation
        });
    });

    // Close modals when clicking outside of them
    window.addEventListener('click', function(event) {
        if (event.target == addEditDepartmentModal) {
            addEditDepartmentModal.classList.remove('show');
        }
        if (event.target == viewDepartmentModal) {
            viewDepartmentModal.classList.remove('show');
        }
    });

    // Open Add Department Modal
    addDepartmentBtn.addEventListener('click', function() {
        modalTitle.textContent = 'Add New Department';
        departmentForm.reset(); // Clear form
        document.getElementById('departmentId').value = ''; // Clear ID for add
        addEditDepartmentModal.classList.add('show'); // Use class for animation
    });

    // Cancel Add/Edit
    cancelAddEditBtn.addEventListener('click', function() {
        addEditDepartmentModal.classList.remove('show'); // Use class for animation
    });

    // Handle Add/Edit Department Form Submission - PHP handles the actual save/update
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');

        if (status === 'added') {
            showToast('Department record added successfully!', 'success');
        } else if (status === 'updated') {
            showToast('Department record updated successfully!', 'success');
        } else if (status === 'deleted') {
            showToast('Department record deleted successfully!', 'success');
        } else if (status === 'error' || status === 'delete_error') {
            showToast('An error occurred during the operation.', 'error');
        }
        // Clear the URL parameters to prevent toast from showing on subsequent refreshes
        if (status) {
            history.replaceState({}, document.title, window.location.pathname);
        }
    });


    // --- Event Delegation for Table Actions ---
    departmentTableBody.addEventListener('click', function(event) {
        const button = event.target.closest('.action-btn'); // Find the closest action button clicked

        if (!button) return; // If no action button was clicked, do nothing

        const departmentId = button.dataset.departmentId;
        const row = button.closest('tr'); // Get the row associated with the button
        const departmentName = row.children[1].textContent;

        if (button.classList.contains('view-details')) {
            handleViewDetailsClick(departmentId, row);
        } else if (button.classList.contains('edit')) {
            handleEditClick(departmentId, row);
        } else if (button.classList.contains('delete')) {
            handleDeleteClick(departmentId, departmentName);
        }
    });

    // Handlers
    function handleViewDetailsClick(departmentId, row) {
        document.getElementById('viewDepartmentName').textContent = `${row.children[1].textContent} Details`;
        document.getElementById('detailDepartmentNameTitle').textContent = row.children[1].textContent;
        document.getElementById('detailId').textContent = row.children[0].textContent;
        document.getElementById('detailDepartmentName').textContent = row.children[1].textContent;
        document.getElementById('detailHead').textContent = row.children[2].textContent;
        document.getElementById('detailEmployees').textContent = row.children[3].textContent;
        document.getElementById('detailContact').textContent = row.children[4].textContent;
        document.getElementById('detailLocation').textContent = row.children[5].textContent;

        // Populate timeline with simulated activity
        const activityLog = document.getElementById('departmentActivityLog');
        activityLog.innerHTML = ''; // Clear previous timeline
        
        // Simulated activity data
        const activities = [
            {
                user: 'System Admin',
                action: 'Department contact information updated',
                time: '2025-05-21 14:30',
                status: 'completed'
            },
            {
                user: 'HR Manager',
                action: 'Department record created and activated',
                time: '2025-05-15 09:15',
                status: 'completed'
            },
            {
                user: 'Department Head',
                action: 'Employee count updated',
                time: '2025-05-10 16:45',
                status: 'completed'
            }
        ];

        activities.forEach(activity => {
            const timelineItem = document.createElement('div');
            timelineItem.className = 'timeline-item';
            
            timelineItem.innerHTML = `
                <div class="timeline-header">
                    <span class="timeline-user">${activity.user}</span>
                    <span class="timeline-time">${activity.time}</span>
                </div>
                <div class="timeline-action">${activity.action}</div>
                <div class="timeline-status ${activity.status}">
                    <i class="fas fa-check-circle"></i>
                    Completed
                </div>
            `;
            activityLog.appendChild(timelineItem);
        });

        viewDepartmentModal.classList.add('show'); // Use class for animation
    }

    function handleEditClick(departmentId, row) {
        modalTitle.textContent = 'Edit Department Details';
        document.getElementById('departmentId').value = departmentId;
        document.getElementById('departmentName').value = row.children[1].textContent;
        document.getElementById('head').value = row.children[2].textContent;
        document.getElementById('employees').value = row.children[3].textContent;
        document.getElementById('contact').value = row.children[4].textContent;
        document.getElementById('location').value = row.children[5].textContent;

        addEditDepartmentModal.classList.add('show'); // Use class for animation
    }

    function handleDeleteClick(departmentId, departmentName) {
        openConfirmModal(
            `Delete Department Record`,
            `Are you sure you want to PERMANENTLY delete the department "${departmentName}"? This action cannot be undone.`,
            () => {
                // Redirect to PHP with delete_id parameter
                window.location.href = `department.php?delete_id=${departmentId}`;
            }
        );
    }

    // Collapsible sections in View Details modal - REMOVED since we're using new unified design
    // document.querySelectorAll('.modal-section h4.collapsible').forEach(header => {
    //     header.addEventListener('click', function() {
    //         const content = this.nextElementSibling;
    //         this.classList.toggle('collapsed');
    //         content.classList.toggle('collapsed');
    //     });
    //     // Collapse all sections by default when modal opens
    //     header.classList.add('collapsed');
    //     header.nextElementSibling.classList.add('collapsed');
    // });

    // Make Export button working (simulated)
    exportBtn.addEventListener('click', function() {
        showToast('Export functionality is simulated. Data would be exported to a CSV/Excel file.', 'info');
        console.log('Exporting department data...');
        const table = document.getElementById('departmentTable');
        let csv = [];
        // Add header row
        let headerRow = [];
        table.querySelectorAll('th').forEach((th, index) => {
            if (index < table.querySelectorAll('th').length -1) { // Exclude actions column
                headerRow.push(th.innerText);
            }
        });
        csv.push(headerRow.join(','));

        // Add data rows
        table.querySelectorAll('tbody tr').forEach(row => {
            let rowData = [];
            row.querySelectorAll('td').forEach((td, index) => {
                if (index < row.querySelectorAll('td').length -1) { // Exclude actions column
                    // For badge content, get the text inside the badge
                    if (td.querySelector('.badge')) {
                        rowData.push(td.querySelector('.badge').innerText);
                    } else {
                        rowData.push(td.innerText);
                    }
                }
            });
            csv.push(rowData.join(','));
        });

        const csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "department_data.csv");
        document.body.appendChild(link); // Required for Firefox
        link.click();
        document.body.removeChild(link);
    });

    // Filter Logic (simulated)
    // Store all rows initially for filtering
    const allDepartmentRows = Array.from(departmentTableBody.children);

    function applyFilters() {
        const selectedLocation = locationFilter.value.toLowerCase();
        const selectedEmployeeRange = employeeCountFilter.value; // e.g., "1-10", "11-50", "51+"
        const searchTerm = document.querySelector('.search-bar input').value.toLowerCase();

        departmentTableBody.innerHTML = ''; // Clear current table content

        const filteredRows = allDepartmentRows.filter(row => {
            const departmentName = row.children[1].textContent.toLowerCase();
            const headName = row.children[2].textContent.toLowerCase();
            const employeesCount = parseInt(row.children[3].textContent);
            const departmentLocation = row.children[5].textContent.toLowerCase();

            const matchesLocation = (selectedLocation === '' || departmentLocation.includes(selectedLocation));

            const matchesEmployeeRange = (selectedEmployeeRange === '' ||
                                         (selectedEmployeeRange === '1-10' && employeesCount >= 1 && employeesCount <= 10) ||
                                         (selectedEmployeeRange === '11-50' && employeesCount >= 11 && employeesCount <= 50) ||
                                         (selectedEmployeeRange === '51+' && employeesCount >= 51));

            const matchesSearch = (searchTerm === '' ||
                                   departmentName.includes(searchTerm) ||
                                   headName.includes(searchTerm) ||
                                   row.children[0].textContent.toLowerCase().includes(searchTerm) || // Search by ID
                                   row.children[4].textContent.toLowerCase().includes(searchTerm) // Search by Contact
                                   );

            return matchesLocation && matchesEmployeeRange && matchesSearch;
        });

        if (filteredRows.length > 0) {
            filteredRows.forEach(row => departmentTableBody.appendChild(row));
        } else {
            const noResultsRow = document.createElement('tr');
            noResultsRow.innerHTML = `<td colspan="7" style="text-align:center; padding:20px; color:var(--text-secondary);">No departments found matching your criteria.</td>`;
            departmentTableBody.appendChild(noResultsRow);
        }
    }

    function clearFilters() {
        locationFilter.value = '';
        employeeCountFilter.value = '';
        document.querySelector('.search-bar input').value = '';
        applyFilters(); // Re-apply to show all rows
    }

    applyFiltersBtn.addEventListener('click', applyFilters);
    clearFiltersBtn.addEventListener('click', clearFilters);
    document.querySelector('.search-bar input').addEventListener('keyup', applyFilters); // Live search

    // Initial load: apply filters to render initial data and set up event delegation
    document.addEventListener('DOMContentLoaded', applyFilters);
  </script>
</body>
</html>
