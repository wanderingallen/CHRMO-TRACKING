<?php
// Start the session at the very beginning of the script.
// This must be the first thing in your PHP file, before any output (even whitespace).
session_start();
require_once 'config.php';
require_once 'database.php';
require_once 'security.php';

// Only admins can create new accounts
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !Security::is_admin()) {
    header('Location: log-in.php?msg=' . urlencode('Only administrators can create accounts. Please contact your admin.'));
    exit();
}

// Define variables for form fields and messages
$email = '';
$username = '';
$password = '';
$confirmPassword = '';
$agreeTerms = false;

$errors = [];
$successMessage = '';

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and get form data
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $username = htmlspecialchars(trim($_POST['username'] ?? ''));
    $selectedRole = in_array($_POST['role'] ?? '', ['admin', 'department_user']) ? $_POST['role'] : 'department_user';
    $selectedDept = htmlspecialchars(trim($_POST['department'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $agreeTerms = isset($_POST['agreeTerms']);

    // --- Validation ---

    // Username validation
    if (empty($username)) {
        $errors['username'] = 'Username is required.';
    } elseif (strlen($username) < 3) {
        $errors['username'] = 'Username must be at least 3 characters long.';
    }

    // Email validation
    if (empty($email)) {
      $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = 'Please enter a valid email address.';
    }

    // Department required for department_user role
    if ($selectedRole === 'department_user' && empty($selectedDept)) {
        $errors['department'] = 'Department is required for department users.';
    }

    // Password validation
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters long.';
    }

    // Confirm Password validation
    if (empty($confirmPassword)) {
        $errors['confirmPassword'] = 'Confirm Password is required.';
    } elseif ($password !== $confirmPassword) {
        $errors['confirmPassword'] = 'Passwords do not match.';
    }

    // Terms & Privacy Policy agreement validation
    if (!$agreeTerms) {
        $errors['agreeTerms'] = 'You must agree to the Terms & Privacy Policy.';
    }

    // If no validation errors, proceed with user registration
    if (empty($errors)) {
        try {
            $db = Database::getInstance()->getConnection();

            // chrmo_db.sql schema: users(id INT AUTO_INCREMENT, name, email, password, role)
        // Check duplicates for username/email
        $stmt = $db->prepare("SELECT id, name, email FROM users WHERE name = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        $hit = $stmt->fetch();
        if ($hit) {
          if (!empty($hit['name']) && strcasecmp($hit['name'], $username) === 0) {
            $errors['username'] = 'This username is already taken.';
          }
          if (!empty($hit['email']) && strcasecmp($hit['email'], $email) === 0) {
            $errors['email'] = 'This email is already registered.';
          }
        } else {
                // Hash the password for security
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                // Insert into DB (id auto-increment)
          $stmt3 = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES (?, ?, ?, ?, ?)");
          $deptValue = ($selectedRole === 'department_user') ? $selectedDept : null;
          $stmt3->execute([$username, $email, $hashedPassword, $selectedRole, $deptValue]);

                // Success → redirect to login
                $_SESSION['registration_success'] = true; // Use session to carry success message
                header('Location: log-in.php?registration_success=true');
                exit();
            }
        } catch (Exception $e) {
            error_log('Registration DB error: ' . $e->getMessage());
            $errors['register'] = 'Unable to create account at this time. Please try again later.';
        }
    }
}

// Fetch departments for dropdown
$departments = [];
try {
    $db = Database::getInstance()->getConnection();
    $deptStmt = $db->query("SELECT id, name FROM departments ORDER BY name ASC");
    if ($deptStmt) {
        $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Failed to fetch departments: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CHRMO Document Tracking - Register</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/smooth-interactions.js" defer></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Poppins', 'sans-serif'],
          },
          colors: {
            primary: {
              400: '#8585c0',
              500: '#6868AC',
            },
            navy: '#1E3A8A'
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: url('assets/bg%20imag.jpg') no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
    }
    /* Overlay for better contrast */
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg, rgba(200, 200, 230, 0.85), rgba(104, 104, 172, 0.70));
      z-index: -1;
    }
    .fade-in {
      animation: fadeIn 0.8s ease-in-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .floating-label-group {
      position: relative;
      margin-bottom: 0;
    }
    .floating-label {
      position: absolute;
      top: 14px;
      left: 15px;
      font-size: 1rem;
      color: #9ca3af;
      pointer-events: none;
      transition: all 0.2s ease-out;
      background-color: transparent;
      padding: 0 5px;
    }
    /* Adjusted for select elements to prevent overlap */
    input:focus ~ .floating-label,
    input:not(:placeholder-shown) ~ .floating-label,
    select:focus ~ .floating-label,
    select:not([value=""]) ~ .floating-label,
    .floating-label.selected {
      top: -10px;
      left: 12px;
      font-size: 0.75rem;
      color: #6868AC;
      background-color: white;
      z-index: 10;
    }
    /* Specific adjustment for select to ensure label moves when an option is selected */
    select:not([value=""]) ~ .floating-label {
      top: -10px;
      left: 12px;
      font-size: 0.75rem;
      color: #6868AC;
        background-color: white;
        z-index: 10;
    }
    .password-field {
      position: relative;
    }
    .password-field > .password-toggle {
      position: absolute !important;
      top: 50% !important;
      right: 12px !important;
      left: auto !important;
      bottom: auto !important;
      transform: translateY(-50%) !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      width: 40px;
      height: 40px;
      margin: 0 !important;
      padding: 0 !important;
      background: transparent !important;
      border: none !important;
      line-height: 1;
      z-index: 20;
    }
    input:focus, select:focus {
      outline: none;
      border-color: #6b7280; /* neutral gray */
      box-shadow: 0 0 0 2px rgba(107, 114, 128, 0.35);
    }
    .floating-label-group input,
    .floating-label-group select {
      font-size: 0.9rem;
    }
    .password-input {
      display: block;
      width: 100%;
      padding: 0.75rem 3.5rem 0.75rem 1rem;
      border: 1px solid #d1d5db;
      border-radius: 0.75rem;
      box-shadow: inset 0 1px 2px rgba(15,23,42,0.04);
      color: #111827;
      background-color: #fff;
      font-size: 0.9rem;
    }
    .password-input:focus {
      outline: none;
      border-color: #6b7280;
      box-shadow: 0 0 0 2px rgba(107,114,128,0.35);
    }
    .password-field input {
      padding-right: 3.5rem;
    }
    .password-field .floating-label {
      font-size: 0.85rem;
      max-width: calc(100% - 3.75rem);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .password-toggle {
      position: absolute;
      top: 50%;
      right: 12px;
      transform: translateY(-50%);
      cursor: pointer;
      color: #64748b;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      background: transparent;
      border: none;
      padding: 0;
      transition: color .15s ease;
      line-height: 1;
    }
    .password-toggle:hover {
      color: #64748b;
    }
    .password-toggle:focus {
      outline: none;
    }
    .password-toggle i { pointer-events: none; }
    .caps-indicator {
      font-size: 12px;
      color: #b45309; /* amber-700 */
      display: none;
      margin-top: 4px;
    }
    .caps-indicator.show { display: block; }
    .hint {
      font-size: 12px;
      color: #6b7280; /* gray-500 */
      margin-top: 6px;
    }
    /* Compact alert with fade-out support */
    .alert {
      transition: opacity 0.5s ease-out, max-height 0.5s ease-out, margin 0.5s ease-out, padding 0.5s ease-out;
      overflow: hidden;
    }
    .fade-out { opacity: 0; max-height: 0; margin: 0 !important; padding-top: 0 !important; padding-bottom: 0 !important; }
    /* Right pane should not be scrollable in-page; let the page scroll naturally */
    .auth-pane { max-height: none; overflow: visible; }
    /* Centered fade modal toasts */
    #toast-overlay { position: fixed; inset: 0; background: rgba(17, 24, 39, 0.25); display: none; align-items: center; justify-content: center; z-index: 10000; opacity: 0; transition: opacity .2s ease; }
    #toast-overlay.show { display: flex; opacity: 1; }
    #toast-stack { display: flex; flex-direction: column; gap: 12px; max-width: 90vw; align-items: center; }
    .toast { min-width: 260px; max-width: 420px; background: #ffffff; color: #111827; padding: 12px 16px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; opacity: 0; transform: translateY(-6px); transition: opacity .2s ease, transform .2s ease; font-size: 14px; }
    .toast.show { opacity: 1; transform: translateY(0); }
    .toast.success { border-color: #86efac; background: #f0fdf4; color: #166534; }
    .toast.error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    .toast.info { border-color: #bae6fd; background: #eff6ff; color: #1e3a8a; }
    .modal {
      display: none;
      position: fixed;
      z-index: 50;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.5);
    }
    .modal-content {
      background-color: #fefefe;
      margin: 5% auto;
      padding: 20px;
      border: 1px solid #888;
      width: 90%;
      max-width: 700px;
      border-radius: 12px;
      max-height: 80vh;
      overflow-y: auto;
    }
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover,
    .close:focus {
      color: black;
      text-decoration: none;
    }
    .terms-checkbox {
      margin-right: 10px;
    }
    .terms-section {
      margin-bottom: 20px;
    }
    .terms-section h3 {
      margin-bottom: 10px;
      color: #6868AC;
    }
    .terms-content {
      background-color: #f9f9f9;
      padding: 15px;
      border-radius: 8px;
      margin-bottom: 15px;
      max-height: 200px;
      overflow-y: auto;
    }
    .accept-group {
      margin-top: 10px;
      background-color: #f8fafc;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 12px 14px;
    }
    .accept-all {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
      font-weight: 600;
      color: #111827;
    }
    .accept-items {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 16px;
      padding-left: 2px;
    }
    .accept-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #374151;
    }
    /* Shake animation for invalid inputs */
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      20% { transform: translateX(-6px); }
      40% { transform: translateX(6px); }
      60% { transform: translateX(-4px); }
      80% { transform: translateX(4px); }
    }
    .animate-shake { animation: shake .28s ease-out 1; }
    /* Validation error state with smooth transitions */
    .reg-input, .password-input {
      transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .input-error { border-color: #dc2626 !important; box-shadow: 0 0 0 2px rgba(220,38,38,0.2) !important; }
    .input-error ~ .floating-label { color: #dc2626 !important; transition: color 0.3s ease; }
    .input-error-fade { border-color: #dc2626 !important; box-shadow: 0 0 0 2px rgba(220,38,38,0.08) !important;
      animation: errorFadeOut 0.5s ease forwards; }
    @keyframes errorFadeOut {
      0%   { border-color: #dc2626; box-shadow: 0 0 0 2px rgba(220,38,38,0.2); }
      100% { border-color: #d1d5db; box-shadow: none; }
    }
    .field-error { transition: opacity 0.3s ease, max-height 0.3s ease; }
    .field-error.visible { display: block; opacity: 1; max-height: 40px; }
    .field-error.fade-out-err { opacity: 0; max-height: 0; }
    #termsBox { transition: border-color 0.3s ease, background-color 0.3s ease; }
    #termsBox.terms-error { border-color: #dc2626; background-color: #fef2f2; }
    /* Server-side duplicate error highlight */
    .server-error-field { border-color: #dc2626 !important; box-shadow: 0 0 0 2px rgba(220,38,38,0.18) !important; }
    
    /* Disable underline animation for specific links */
    .no-underline-animation {
      text-decoration: none !important;
    }
    .no-underline-animation:hover {
      text-decoration: none !important;
    }
    .no-underline-animation::after {
      display: none !important;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen p-2 md:p-4">
<div class="w-full max-w-2xl bg-white rounded-2xl shadow-xl overflow-hidden flex flex-col md:flex-row page-enter">
    <div class="relative md:w-2/5 bg-gradient-to-br from-primary-400 to-primary-500 text-white p-5 flex flex-col items-center justify-center text-center">
        <img src="hr.png" alt="CHRMO Logo" class="w-32 h-auto mb-3 rounded-full shadow-lg" />
        <h1 class="text-3xl font-bold mb-2">Welcome!</h1>
        <p class="text-primary-100">Register to manage your documents efficiently.</p>
        <div class="mt-4 text-sm">
            <span class="opacity-90">Already have an account?</span>
            <a href="log-in.php" class="ml-1 text-white font-semibold opacity-100 link-underline">Login here</a>
        </div>
        <div class="absolute bottom-4 left-0 right-0 text-xs text-primary-200">
            © 2025 CHRMO Document Tracking
        </div>
    </div>

    <div class="w-full md:w-3/5 p-5 md:p-6 auth-pane">
      <h2 class="text-xl font-extrabold text-primary-500 mb-3 text-center">Create your account</h2>
      <?php if (!empty($successMessage) || !empty($errors)): ?>
          <div id="toast-overlay"><div id="toast-stack"></div></div>
      <?php endif; ?>
      <div class="bg-white shadow-lg rounded-xl p-4">
      <form id="registerForm" action="register.php" method="POST" class="w-full" novalidate>
        <div class="w-full grid grid-cols-1 md:grid-cols-2 gap-x-3 gap-y-4">
          <!-- Row 1: Username + Email -->
          <div>
            <div class="floating-label-group relative w-full">
              <input type="text" id="username" name="username" placeholder=" " class="reg-input block w-full px-4 py-2.5 border border-gray-300 rounded-xl shadow-inner text-gray-900" data-label="Username" value="<?php echo htmlspecialchars($username); ?>" />
              <label for="username" class="floating-label">Username</label>
            </div>
            <p class="field-error text-xs text-red-600 mt-1 hidden"></p>
            <?php if (!empty($errors['username'])): ?><p class="text-sm text-red-600 mt-1"><?php echo $errors['username']; ?></p><?php endif; ?>
          </div>
          <div>
            <div class="floating-label-group relative w-full">
              <input type="email" id="email" name="email" placeholder=" " class="reg-input block w-full px-4 py-2.5 border border-gray-300 rounded-xl shadow-inner text-gray-900" data-label="Email" value="<?php echo htmlspecialchars($email); ?>" />
              <label for="email" class="floating-label">Email</label>
            </div>
            <p class="field-error text-xs text-red-600 mt-1 hidden"></p>
            <?php if (!empty($errors['email'])): ?><p class="text-sm text-red-600 mt-1"><?php echo $errors['email']; ?></p><?php endif; ?>
          </div>

          <!-- Row 2: Password + Confirm Password -->
          <div>
            <div class="floating-label-group w-full">
              <div class="password-field">
                <input type="password" id="password" name="password" placeholder=" " class="reg-input password-input" data-label="Password" style="padding-top:0.625rem;padding-bottom:0.625rem;" />
                <label for="password" class="floating-label">Password</label>
                <button type="button" class="password-toggle" onclick="togglePassword('password', this)" aria-label="Toggle password visibility">
                  <i class="far fa-eye-slash"></i>
                </button>
              </div>
              <div class="hint">Use at least 6 characters. Mix of letters and numbers recommended.</div>
              <div id="capsPassword" class="caps-indicator"><i class="fas fa-exclamation-triangle mr-1"></i>Caps Lock is ON</div>
            </div>
            <p class="field-error text-xs text-red-600 mt-1 hidden"></p>
            <?php if (!empty($errors['password'])): ?><p class="text-sm text-red-600 mt-1"><?php echo $errors['password']; ?></p><?php endif; ?>
          </div>
          <div>
            <div class="floating-label-group w-full">
              <div class="password-field">
                <input type="password" id="confirmPassword" name="confirmPassword" placeholder=" " class="reg-input password-input" data-label="Confirm Password" style="padding-top:0.625rem;padding-bottom:0.625rem;" />
                <label for="confirmPassword" class="floating-label">Confirm Password</label>
                <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)" aria-label="Toggle password visibility">
                  <i class="far fa-eye-slash"></i>
                </button>
              </div>
              <div id="capsConfirm" class="caps-indicator"><i class="fas fa-exclamation-triangle mr-1"></i>Caps Lock is ON</div>
            </div>
            <p class="field-error text-xs text-red-600 mt-1 hidden"></p>
            <?php if (!empty($errors['confirmPassword'])): ?><p class="text-sm text-red-600 mt-1"><?php echo $errors['confirmPassword']; ?></p><?php endif; ?>
          </div>

          <!-- Row 3: Role + Department -->
          <div>
            <div class="floating-label-group relative w-full">
              <select id="role" name="role" class="reg-input block w-full px-4 py-2.5 border border-gray-300 rounded-xl shadow-inner text-gray-900 bg-white" onchange="toggleDeptField()">
                <option value="admin" <?php echo (isset($selectedRole) && $selectedRole === 'admin') ? 'selected' : ''; ?>>Admin</option>
                <option value="department_user" <?php echo (isset($selectedRole) && $selectedRole === 'department_user') ? 'selected' : ''; ?>>Department Manager</option>
              </select>
              <label for="role" class="floating-label selected">Role</label>
            </div>
          </div>
          <div id="deptFieldWrap" style="<?php echo (isset($selectedRole) && $selectedRole === 'department_user') ? '' : 'display:none;'; ?>">
            <div class="floating-label-group relative w-full">
              <select id="department" name="department" class="reg-input block w-full px-4 py-2.5 border border-gray-300 rounded-xl shadow-inner text-gray-900 bg-white">
                <option value="">— Select Department —</option>
                <?php foreach ($departments as $dept): ?>
                  <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo (isset($selectedDept) && $selectedDept === $dept['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                <?php endforeach; ?>
              </select>
              <label for="department" class="floating-label selected">Department</label>
            </div>
            <p class="field-error text-xs text-red-600 mt-1 hidden"></p>
            <?php if (!empty($errors['department'])): ?><p class="text-sm text-red-600 mt-1"><?php echo $errors['department']; ?></p><?php endif; ?>
          </div>

          <!-- Row 4: Terms checkbox -->
          <div class="md:col-span-2">
            <div id="termsBox" class="flex items-start text-sm p-3 bg-blue-50 border border-blue-200 rounded-xl transition-colors">
              <label class="flex items-start text-gray-700" id="agreeTermsLabel">
                <input type="checkbox" id="agreeTerms" name="agreeTerms" <?php echo $agreeTerms ? 'checked' : ''; ?> class="mr-3 mt-0.5 w-4 h-4" />
                <span class="font-medium">I agree to the <a href="#" class="text-primary-600 ml-1 font-semibold link-underline" onclick="showTermsModal(event)">Terms & Privacy Policy</a></span>
              </label>
            </div>
            <p id="termsError" class="field-error text-xs text-red-600 mt-1 hidden"></p>
            <?php if (!empty($errors['agreeTerms'])): ?><p class="text-sm text-red-600 mt-1"><?php echo $errors['agreeTerms']; ?></p><?php endif; ?>
          </div>

          <!-- Row 5: Submit -->
          <div class="md:col-span-2">
            <button type="submit" class="w-full bg-primary-500 hover:bg-primary-600 text-white py-2.5 rounded-xl font-semibold btn-press btn-ripple">Register</button>
          </div>
        </div>
      </form>
      </div>
    </div>
</div>

<!-- Terms & Privacy Policy Modal -->
<div id="termsModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeTermsModal()">&times;</span>
    <h2 class="text-2xl font-bold text-center mb-6">Terms & Privacy Policy</h2>
    
    <div class="terms-section">
      <h3 class="text-lg font-semibold">1. Acceptance of Terms</h3>
      <div class="terms-content">
        <p>By accessing and using the CHRMO Document Tracking System, you accept and agree to be bound by the terms and provisions of this agreement.</p>
        <p>All materials, documents, and information provided on this system are the property of the City Human Resource Management Office and are protected by applicable copyright and trademark law.</p>
      </div>
    </div>
    
    <div class="terms-section">
      <h3 class="text-lg font-semibold">2. Privacy Policy</h3>
      <div class="terms-content">
        <p>We collect personal information that you provide to us, such as name, email address, and department information. This information is used solely for the purpose of managing your account and providing document tracking services.</p>
        <p>We implement appropriate security measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction.</p>
        <p>We will not share your personal information with third parties except as required by law or with your explicit consent.</p>
      </div>
    </div>
    
    <div class="terms-section">
      <h3 class="text-lg font-semibold">3. Data Usage</h3>
      <div class="terms-content">
        <p>You agree that all documents uploaded to the system are appropriate for workplace communication and comply with all applicable laws and regulations.</p>
        <p>The CHRMO reserves the right to monitor document activity for security and compliance purposes.</p>
        <p>Users are responsible for maintaining the confidentiality of their account credentials and for all activities that occur under their account.</p>
      </div>
    </div>

    <div class="accept-group">
      <label class="accept-all">
        <input type="checkbox" id="acceptAll" onchange="toggleAcceptAll()">
        <span>Accept all</span>
      </label>
      <div class="accept-items">
        <label class="accept-item">
          <input type="checkbox" id="acceptTerms" class="terms-checkbox" onchange="checkAllAccepted()">
          <span>Terms of Service</span>
        </label>
        <label class="accept-item">
          <input type="checkbox" id="acceptPrivacy" class="terms-checkbox" onchange="checkAllAccepted()">
          <span>Privacy Policy</span>
        </label>
        <label class="accept-item">
          <input type="checkbox" id="acceptDataUsage" class="terms-checkbox" onchange="checkAllAccepted()">
          <span>Data Usage Policy</span>
        </label>
      </div>
    </div>
    
    <div class="flex justify-center mt-6">
      <button 
        id="confirmTermsBtn" 
        class="bg-primary-400 hover:bg-primary-500 text-white py-2 px-6 rounded-xl font-medium transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
        onclick="acceptAllTerms()"
        disabled
      >
        I have read all the terms and conditions
      </button>
    </div>
  </div>
</div>

<script>
    function toggleDeptField() {
      const role = document.getElementById('role').value;
      const wrap = document.getElementById('deptFieldWrap');
      if (role === 'department_user') {
        wrap.style.display = '';
      } else {
        wrap.style.display = 'none';
        document.getElementById('department').value = '';
      }
    }

    window.togglePassword = function togglePassword(fieldId, toggleElement) {
      const field = document.getElementById(fieldId);
      const icon = toggleElement.querySelector('i');
      if (field.type === 'password') {
        // If password is currently hidden, change to text and show open eye
        field.type = 'text';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      } else {
        // If password is currently visible, change to password and show crossed eye
        field.type = 'password';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      }
    };
    
    function showTermsModal(e) {
      e.preventDefault();
      document.getElementById('termsModal').style.display = 'block';
      // No forced reading; modal is informative only
    }
    
    function closeTermsModal() {
      document.getElementById('termsModal').style.display = 'none';
    }
    
    // Toggle all acceptance checkboxes when "Accept all" is clicked
    function toggleAcceptAll() {
      const acceptAllCheckbox = document.getElementById('acceptAll');
      const checkboxes = document.querySelectorAll('.terms-checkbox');
      checkboxes.forEach(checkbox => {
        checkbox.checked = acceptAllCheckbox.checked;
      });
      checkAllAccepted();
    }
    
    // Check if all individual checkboxes are checked, and update "Accept all" and confirm button
    function checkAllAccepted() {
      const checkboxes = document.querySelectorAll('.terms-checkbox');
      const acceptAllCheckbox = document.getElementById('acceptAll');
      const confirmBtn = document.getElementById('confirmTermsBtn');
      
      let allChecked = true;
      checkboxes.forEach(checkbox => {
        if (!checkbox.checked) {
          allChecked = false;
        }
      });
      
      acceptAllCheckbox.checked = allChecked;
      if (confirmBtn) {
        confirmBtn.disabled = !allChecked;
      }
    }
    
    // Handle the confirm button click - accepts all terms
    function acceptAllTerms() {
      // All terms accepted, close modal
      closeTermsModal();
    }
    
    // Simplified: Terms modal is informative; agreement handled by visible required checkbox
    
    // Close the modal when clicking outside of it
    window.onclick = function(event) {
      const modal = document.getElementById('termsModal');
      if (event.target == modal) {
        closeTermsModal();
      }
    }
    
    // ---------- Client-side validation ----------
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.getElementById('registerForm');
      if (form) {
        // Clear error state on input
        form.querySelectorAll('.reg-input').forEach(inp => {
          inp.addEventListener('input', () => {
            inp.classList.remove('input-error', 'animate-shake');
            const errP = inp.closest('.floating-label-group')?.parentElement?.querySelector('.field-error');
            if (errP) { errP.textContent = ''; errP.classList.add('hidden'); errP.classList.remove('visible'); }
          });
        });
        const agreeBox = document.getElementById('agreeTerms');
        if (agreeBox) {
          agreeBox.addEventListener('change', () => {
            document.getElementById('termsBox')?.classList.remove('terms-error');
            const te = document.getElementById('termsError');
            if (te) { te.textContent = ''; te.classList.add('hidden'); te.classList.remove('visible'); }
          });
        }

        form.addEventListener('submit', function(e) {
          let valid = true;
          // Validate each required input
          const fields = [
            { id: 'username',        msg: 'Username is required.' },
            { id: 'email',           msg: 'Email is required.' },
            { id: 'password',        msg: 'Password is required.' },
            { id: 'confirmPassword', msg: 'Confirm Password is required.' },
          ];
          fields.forEach(f => {
            const inp = document.getElementById(f.id);
            if (!inp) return;
            const val = inp.value.trim();
            const errP = inp.closest('.floating-label-group')?.parentElement?.querySelector('.field-error');
            if (val === '') {
              valid = false;
              inp.classList.add('input-error', 'animate-shake');
              if (errP) { errP.textContent = f.msg; errP.classList.remove('hidden'); errP.classList.add('visible'); }
            } else {
              inp.classList.remove('input-error', 'animate-shake');
              if (errP) { errP.textContent = ''; errP.classList.add('hidden'); errP.classList.remove('visible'); }
            }
          });
          // Extra: password length
          const pw = document.getElementById('password');
          if (pw && pw.value.trim().length > 0 && pw.value.trim().length < 6) {
            valid = false;
            pw.classList.add('input-error', 'animate-shake');
            const errP = pw.closest('.floating-label-group')?.parentElement?.querySelector('.field-error');
            if (errP) { errP.textContent = 'Password must be at least 6 characters.'; errP.classList.remove('hidden'); errP.classList.add('visible'); }
          }
          // Extra: passwords match
          const cp = document.getElementById('confirmPassword');
          if (pw && cp && pw.value.trim().length >= 6 && cp.value.trim() !== '' && cp.value !== pw.value) {
            valid = false;
            cp.classList.add('input-error', 'animate-shake');
            const errP = cp.closest('.floating-label-group')?.parentElement?.querySelector('.field-error');
            if (errP) { errP.textContent = 'Passwords do not match.'; errP.classList.remove('hidden'); errP.classList.add('visible'); }
          }
          // Terms
          if (!document.getElementById('agreeTerms')?.checked) {
            valid = false;
            document.getElementById('termsBox')?.classList.add('terms-error');
            const te = document.getElementById('termsError');
            if (te) { te.textContent = 'You must agree to the Terms & Privacy Policy.'; te.classList.remove('hidden'); te.classList.add('visible'); }
          }
          if (!valid) {
            e.preventDefault();
            // Scroll to first error
            const firstErr = form.querySelector('.input-error, .terms-error');
            if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Auto-fade red borders after 3 seconds
            setTimeout(() => {
              form.querySelectorAll('.input-error').forEach(inp => {
                inp.classList.add('input-error-fade');
                inp.classList.remove('input-error');
                const errP = inp.closest('.floating-label-group')?.parentElement?.querySelector('.field-error');
                if (errP) errP.classList.add('fade-out-err');
              });
              const tb = document.getElementById('termsBox');
              if (tb) tb.classList.remove('terms-error');
              const te = document.getElementById('termsError');
              if (te) te.classList.add('fade-out-err');
              // Fully clean up after fade animation
              setTimeout(() => {
                form.querySelectorAll('.input-error-fade').forEach(inp => {
                  inp.classList.remove('input-error-fade', 'animate-shake');
                });
                form.querySelectorAll('.field-error').forEach(p => {
                  p.classList.remove('visible', 'fade-out-err');
                  p.classList.add('hidden');
                  p.textContent = '';
                });
              }, 500);
            }, 3000);
          }
        });
      }
    });

    // Highlight server-side duplicate errors (username / email) on page load
    document.addEventListener('DOMContentLoaded', function() {
      function addServerErrorFade(el) {
        if (!el) return;
        el.classList.add('server-error-field');
        el.addEventListener('input', () => el.classList.remove('server-error-field'), {once:true});
        // Also auto-fade after 3 seconds (consistent with client-side validation)
        setTimeout(() => {
          el.classList.add('input-error-fade');
          setTimeout(() => { el.classList.remove('server-error-field', 'input-error-fade'); }, 500);
        }, 3000);
      }
      <?php if (!empty($errors['username'])): ?>
        addServerErrorFade(document.getElementById('username'));
      <?php endif; ?>
      <?php if (!empty($errors['email'])): ?>
        addServerErrorFade(document.getElementById('email'));
      <?php endif; ?>
    });

    // Initialize page state
    document.addEventListener('DOMContentLoaded', function() {
      // Caps Lock indicators
      function wireCaps(id, indicatorId) {
        const el = document.getElementById(id);
        const ind = document.getElementById(indicatorId);
        if (!el || !ind) return;
        const handler = (e) => {
          if (e.getModifierState && e.getModifierState('CapsLock')) {
            ind.classList.add('show');
          } else {
            ind.classList.remove('show');
          }
        };
        el.addEventListener('keyup', handler);
        el.addEventListener('keydown', handler);
        el.addEventListener('focus', handler);
        el.addEventListener('blur', () => ind.classList.remove('show'));
      }
      wireCaps('password', 'capsPassword');
      wireCaps('confirmPassword', 'capsConfirm');

      // Auto-dismiss alerts to avoid long layout expansion
      const overlay = document.getElementById('toast-overlay');
      const stack = document.getElementById('toast-stack');
      if (overlay && stack) {
        function ensureOverlayVisible() {
          overlay.classList.add('show');
        }
        function maybeHideOverlay() {
          if (stack.children.length === 0) {
            overlay.classList.remove('show');
            // small delay before display:none handled by CSS; optional clean-up not needed
          }
        }
        function showToast(message, type = 'info', duration = 2800) {
          ensureOverlayVisible();
          const t = document.createElement('div');
          t.className = `toast ${type}`;
          t.textContent = message;
          stack.appendChild(t);
          requestAnimationFrame(() => t.classList.add('show'));
          setTimeout(() => {
            t.classList.remove('show');
            setTimeout(() => { t.remove(); maybeHideOverlay(); }, 200);
          }, duration);
          // Hide overlay if user clicks outside stack
          overlay.onclick = (e) => { if (e.target === overlay) { Array.from(stack.children).forEach(child => child.remove()); maybeHideOverlay(); } };
        }
        // PHP-injected messages
        <?php if (!empty($successMessage)): ?>
          showToast(<?php echo json_encode($successMessage); ?>, 'success', 2200);
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
          <?php foreach ($errors as $key => $error): ?>
            <?php // Skip field-specific errors — they're shown inline below each field ?>
            <?php if ($key === 'username' || $key === 'email') continue; ?>
            showToast(<?php echo json_encode($error); ?>, 'error', 2600);
          <?php endforeach; ?>
        <?php endif; ?>
      }
    });
</script>
</body>
</html>