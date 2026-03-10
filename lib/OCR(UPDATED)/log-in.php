<?php
// Start output buffering to prevent any header issues
ob_start();

// Load configuration FIRST (sets session ini settings)
require_once 'config.php';

// THEN start the session (after ini settings are configured)
@session_start();

// Load other dependencies
require_once 'database.php';
require_once 'security.php';
require_once 'email.php';

// Function to check and validate remember me cookie (DB-based)
function checkRememberMeCookie() {
    if (isset($_COOKIE['remember_me'])) {
        $cookieValue = $_COOKIE['remember_me'];
        $parts = explode(':', $cookieValue);
        
        if (count($parts) === 2) {
            $userId = $parts[0];
            $hash = $parts[1];
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id, name, email, role, department FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                if ($row = $stmt->fetch()) {
                    $expectedHash = hash_hmac('sha256', $row['id'] . $row['email'], SECRET_KEY);
                    if (hash_equals($expectedHash, $hash)) {
                        $_SESSION['loggedin'] = true;
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['user_email'] = $row['email'];
                        $_SESSION['user_name'] = $row['name'];
                        $_SESSION['user_username'] = $row['name'];
                        $_SESSION['user_department'] = $row['department'] ?? '';
                        $_SESSION['user_role'] = $row['role'] ?? 'user';
                        $_SESSION['saved_identifier'] = $row['email'];
                        return true;
                    }
                }
            } catch (Exception $e) {
                error_log('Remember me DB check failed: ' . $e->getMessage());
            }
        }
        // Invalid cookie, remove it
        setcookie('remember_me', '', time() - 3600, "/");
    }
    return false;
}

// Check remember me cookie before checking if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    checkRememberMeCookie();
}

// If the user is already logged in, redirect them to the dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit();
}

// Define variables for form fields and messages
$identifier = '';
$password = '';
$rememberMe = false;

// Prefill when saved cookies exist (these are only set when the box was checked)
if (isset($_COOKIE['saved_identifier']) || isset($_COOKIE['saved_password'])) {
    $identifier = isset($_COOKIE['saved_identifier']) ? $_COOKIE['saved_identifier'] : '';
    $password = isset($_COOKIE['saved_password']) ? base64_decode($_COOKIE['saved_password']) : '';
    $rememberMe = true;
}

$errors = [];
$successMessage = '';
$notificationMessage = '';
$notificationType = 'info';

// New database-based forgot password handler (6-digit code)
function handleForgotPasswordDB($email) {
    try {
        $db = Database::getInstance()->getConnection();
        
        // Check if user exists (prevent user enumeration by always showing same message)
        $stmt = $db->prepare("SELECT id, email, name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate 6-digit verification code
            $code = str_pad(strval(random_int(0, 999999)), 6, '0', STR_PAD_LEFT);
            $tokenHash = Security::hashToken($code);
            $expiresAt = date('Y-m-d H:i:s', time() + RESET_TOKEN_EXPIRY); // 15 minutes
            
            // Store reset token using DB time to avoid PHP/MySQL timezone drift
            $stmt = $db->prepare("
                INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, user_agent)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)
            ");
            $stmt->execute([
                $user['id'],
                $tokenHash,
                RESET_TOKEN_EXPIRY,
                Security::getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // Send email with code using EmailService
            $emailService = new EmailService();
            $subject = 'Your Password Reset Code - CHRMO Document Tracking';
            $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                        <div style="background: linear-gradient(135deg, #38bdf8, #0ea5e9); padding: 30px; border-radius: 10px; text-align: center; margin-bottom: 20px;">
                            <h1 style="color: white; margin: 0;">Password Reset Code</h1>
                        </div>
                        <div style="background: #f8fafc; padding: 30px; border-radius: 10px; border: 1px solid #e2e8f0;">
                            <h2 style="color: #1e293b; margin-top: 0;">Hello ' . htmlspecialchars($user['name']) . '</h2>
                            <p style="color: #475569; font-size: 16px; line-height: 1.6;">You have requested to reset your password for CHRMO Document Tracking system.</p>
                            <div style="background: white; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0; border: 2px solid #0ea5e9;">
                                <h3 style="color: #0ea5e9; margin: 0; font-size: 32px; letter-spacing: 3px;">' . $code . '</h3>
                            </div>
                            <p style="color: #475569; font-size: 14px;">This code will expire in 15 minutes. If you did not request this password reset, please ignore this email.</p>
                            <p style="color: #64748b; font-size: 12px; margin-top: 30px;">© 2026 CHRMO Document Tracking System</p>
                        </div>
                    </div>';
            $text = "Hello " . $user['name'] . ",\n\nYour password reset code is: " . $code . "\n\nThis code will expire in 15 minutes.\n\nIf you did not request this password reset, please ignore this email.\n\n© 2026 CHRMO Document Tracking System";
            
            $emailSent = $emailService->send($email, $subject, $html, $text);
            
            // Log the event
            Security::logEvent('password_reset_requested', $user['id'], $email);
            
            $resp = [
                'success' => true,
                'message' => 'A 6-digit verification code has been sent to your email. Please enter it below to reset your password.',
                'cooldown' => RESET_COOLDOWN
            ];
            return $resp;
        }
        
        // Explicitly tell user the email is not registered (requested behavior)
        return [
            'success' => false,
            'message' => 'This email is not registered in the system.'
        ];
        
    } catch (Exception $e) {
        error_log("Forgot password error: " . $e->getMessage());
        Security::logEvent('password_reset_error', null, $email, ['error' => $e->getMessage()]);
        return [
            'success' => false,
            'message' => 'An error occurred. Please try again later.'
        ];
    }
}

// Legacy JSON reset function removed (DB flow replaces it)

// ─── AJAX endpoint: Send OTP code to user's email ───
if (isset($_POST['forgot_password']) && $_POST['forgot_password'] === 'true') {
    header('Content-Type: application/json');
    $email = Security::sanitize($_POST['email'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!Security::validateCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit();
    }
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your email address.']);
        exit();
    }
    // Rate limit: include email in identifier so one user doesn't block everyone on shared IPs
    $ip = Security::getClientIP();
    $identifier = $ip . '|' . strtolower($email);
    $maxAttempts = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? 20 : MAX_RESET_ATTEMPTS;
    if (!Security::checkRateLimit($identifier, 'forgot_password', $maxAttempts, RESET_TOKEN_EXPIRY)) {
        echo json_encode(['success' => false, 'message' => 'Too many reset attempts. Please try again later.']);
        exit();
    }

    $result = handleForgotPasswordDB($email);
    echo json_encode($result);
    exit();
}

// ─── AJAX endpoint: Verify OTP code ───
if (isset($_POST['verify_otp']) && $_POST['verify_otp'] === 'true') {
    header('Content-Type: application/json');
    $email = Security::sanitize($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!Security::validateCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit();
    }
    if (empty($email) || empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Email and code are required.']);
        exit();
    }

    try {
        $db = Database::getInstance()->getConnection();
        // Look up user
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Invalid code or email.']);
            exit();
        }

        $tokenHash = Security::hashToken($code);
        $stmt = $db->prepare("SELECT id, expires_at, used_at FROM password_resets WHERE user_id = ? AND token_hash = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user['id'], $tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
            exit();
        }
        if ($reset['used_at'] !== null) {
            echo json_encode(['success' => false, 'message' => 'This code has already been used.']);
            exit();
        }
        if (strtotime($reset['expires_at']) < time()) {
            echo json_encode(['success' => false, 'message' => 'This code has expired. Please request a new one.']);
            exit();
        }

        // Code is valid — issue short-lived reset token for the final step.
        // This avoids relying solely on PHP session (which can be lost across AJAX calls on some setups).
        $resetToken = Security::generateToken(32);
        $resetTokenHash = Security::hashToken($resetToken);

        // Ensure reset_token_hash column exists (older DBs)
        try {
            $db->exec("ALTER TABLE password_resets ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER token_hash");
        } catch (Throwable $e) {
            // ignore
        }

        $stmt = $db->prepare("UPDATE password_resets SET reset_token_hash = ? WHERE id = ?");
        $stmt->execute([$resetTokenHash, $reset['id']]);

        // Keep session as a fallback
        $_SESSION['otp_verified_reset_id'] = $reset['id'];
        $_SESSION['otp_verified_user_id'] = $user['id'];
        $_SESSION['otp_verified_email'] = $email;
        $_SESSION['otp_verified_at'] = time();

        echo json_encode([
            'success' => true,
            'message' => 'Code verified! Please set your new password.',
            'reset_id' => (int)$reset['id'],
            'reset_token' => $resetToken
        ]);
    } catch (Exception $e) {
        error_log("OTP verify error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
    exit();
}

// ─── AJAX endpoint: Set new password after OTP verification ───
if (isset($_POST['reset_password']) && $_POST['reset_password'] === 'true') {
    header('Content-Type: application/json');
    $newPassword = $_POST['newPassword'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    $email = Security::sanitize($_POST['email'] ?? '');
    $codeFromClient = trim((string)($_POST['code'] ?? ''));
    $resetIdFromClient = (int)($_POST['reset_id'] ?? 0);
    $resetTokenFromClient = (string)($_POST['reset_token'] ?? '');

    if (!Security::validateCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit();
    }

    // Prefer token-based reset if provided; fall back to session-based flow.
    $useTokenFlow = ($resetIdFromClient > 0 && $resetTokenFromClient !== '' && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL));
    if (!$useTokenFlow) {
        // If session is missing, fall back to the most recent verified reset request for this email.
        // (Verified requests have reset_token_hash set during OTP verification.)
        $hasSession = (!empty($_SESSION['otp_verified_reset_id']) && !empty($_SESSION['otp_verified_user_id']));
        if (!$hasSession) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Please verify the OTP code first.']);
                exit();
            }

            try {
                $db = Database::getInstance()->getConnection();
                $stmtU = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmtU->execute([$email]);
                $u = $stmtU->fetch();
                if (!$u) {
                    echo json_encode(['success' => false, 'message' => 'User not found.']);
                    exit();
                }
                $userIdFallback = (int)$u['id'];

                $ip = Security::getClientIP();
                // 1) Strongest fallback: if client sent the OTP code again, match by token_hash
                if ($codeFromClient !== '' && preg_match('/^\d{6}$/', $codeFromClient)) {
                    $tokenHash = Security::hashToken($codeFromClient);
                    $stmtR = $db->prepare(
                        "SELECT id FROM password_resets WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1"
                    );
                    $stmtR->execute([$userIdFallback, $tokenHash]);
                    $rowR = $stmtR->fetch();
                    if ($rowR) {
                        $resetIdFromClient = (int)$rowR['id'];
                        $resetTokenFromClient = '';
                        $useTokenFlow = true;
                        $_POST['reset_id'] = $resetIdFromClient;
                    }
                }

                // 2) Next fallback: most recent verified reset (reset_token_hash not null) for same IP
                if (!$useTokenFlow) {
                    $stmtR = $db->prepare(
                        "SELECT id FROM password_resets\n"
                        . "WHERE user_id = ? AND used_at IS NULL AND reset_token_hash IS NOT NULL\n"
                        . "AND expires_at > NOW()\n"
                        . "AND (ip_address IS NULL OR ip_address = ?)\n"
                        . "ORDER BY id DESC LIMIT 1"
                    );
                    $stmtR->execute([$userIdFallback, $ip]);
                    $rowR = $stmtR->fetch();
                    if (!$rowR) {
                        echo json_encode(['success' => false, 'message' => 'Please verify the OTP code first.']);
                        exit();
                    }

                    // Promote to token flow using server-side verified record
                    $resetIdFromClient = (int)$rowR['id'];
                    $resetTokenFromClient = ''; // not available, but we will trust the verified record w/ same IP
                    $useTokenFlow = true;
                    $_POST['reset_id'] = $resetIdFromClient;
                }
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => 'Please verify the OTP code first.']);
                exit();
            }
        } else {
            // OTP verification must be recent (10 min max)
            if ((time() - ($_SESSION['otp_verified_at'] ?? 0)) > 600) {
                unset($_SESSION['otp_verified_reset_id'], $_SESSION['otp_verified_user_id'], $_SESSION['otp_verified_email'], $_SESSION['otp_verified_at']);
                echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
                exit();
            }
        }
    }
    if (empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    }
    $policyErrors = Security::validatePassword($newPassword);
    if (!empty($policyErrors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $policyErrors)]);
        exit();
    }

    try {
        $db = Database::getInstance()->getConnection();
        if ($useTokenFlow && $resetTokenFromClient !== '') {
            // Resolve user id by email
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if (!$u) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit();
            }
            $userId = (int)$u['id'];
            $resetId = $resetIdFromClient;

            $resetTokenHash = Security::hashToken($resetTokenFromClient);
            $stmt = $db->prepare(
                "SELECT id, expires_at, used_at FROM password_resets WHERE id = ? AND user_id = ? AND reset_token_hash = ? LIMIT 1"
            );
            $stmt->execute([$resetId, $userId, $resetTokenHash]);
            $reset = $stmt->fetch();
            if (!$reset) {
                echo json_encode(['success' => false, 'message' => 'Invalid reset token. Please verify the code again.']);
                exit();
            }
            if ($reset['used_at'] !== null) {
                echo json_encode(['success' => false, 'message' => 'This reset request was already used.']);
                exit();
            }
            if (strtotime($reset['expires_at']) < time()) {
                echo json_encode(['success' => false, 'message' => 'This reset request has expired. Please start over.']);
                exit();
            }
        } else if ($useTokenFlow && $resetTokenFromClient === '') {
            // Server-side fallback path (session missing and client token missing):
            // trust the latest verified record (reset_token_hash NOT NULL) for this email and same IP.
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if (!$u) {
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit();
            }
            $userId = (int)$u['id'];
            $resetId = $resetIdFromClient;
            $ip = Security::getClientIP();

            $stmt = $db->prepare(
                "SELECT id, expires_at, used_at FROM password_resets WHERE id = ? AND user_id = ? AND reset_token_hash IS NOT NULL AND used_at IS NULL AND expires_at > NOW() AND (ip_address IS NULL OR ip_address = ?) LIMIT 1"
            );
            $stmt->execute([$resetId, $userId, $ip]);
            $reset = $stmt->fetch();
            if (!$reset) {
                echo json_encode(['success' => false, 'message' => 'Please verify the OTP code first.']);
                exit();
            }
        } else {
            $userId = (int)$_SESSION['otp_verified_user_id'];
            $resetId = (int)$_SESSION['otp_verified_reset_id'];
            $email = (string)($_SESSION['otp_verified_email'] ?? '');
        }

        // Update password
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

        // Mark token as used (also clear any API reset token if present)
        $stmt = $db->prepare("UPDATE password_resets SET used_at = NOW(), reset_token_hash = NULL WHERE id = ?");
        $stmt->execute([$resetId]);

        // Invalidate all other unused tokens for this user
        $stmt = $db->prepare("UPDATE password_resets SET used_at = NOW(), reset_token_hash = NULL WHERE user_id = ? AND used_at IS NULL AND id != ?");
        $stmt->execute([$userId, $resetId]);

        // Clear session OTP state
        unset($_SESSION['otp_verified_reset_id'], $_SESSION['otp_verified_user_id'], $_SESSION['otp_verified_email'], $_SESSION['otp_verified_at']);

        // Send confirmation email
        try {
            $emailService = new EmailService();
            $emailService->sendPasswordResetConfirmation($email, '');
        } catch (Exception $e) {
            // Non-critical
        }

        Security::logEvent('password_reset_success', $userId, $email);
        echo json_encode(['success' => true, 'message' => 'Password updated successfully! You can now sign in.']);
    } catch (Exception $e) {
        error_log('Reset password error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
    exit();
}

// Check for logout success message from dashboard.php
$showLogoutModal = false;
if (isset($_GET['logged_out']) && $_GET['logged_out'] === 'true') {
    $showLogoutModal = true;
}

// Check for registration success message from register.php
if ((isset($_GET['registration_success']) && $_GET['registration_success'] === 'true') || (!empty($_SESSION['registration_success']))) {
    $notificationMessage = 'Registration successful! You can now log in.';
    $notificationType = 'success';
    unset($_SESSION['registration_success']);
}

// Legacy code verification endpoint - now redirects to token-based reset
// Kept for backward compatibility but not used in new flow

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['forgot_password']) && !isset($_POST['verify_reset'])) {
    // Sanitize and get form data
    $identifier = htmlspecialchars(trim($_POST['identifier'] ?? ''));
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['rememberMe']);

    // --- Validation ---
    if (empty($identifier)) {
        $errors['identifier'] = 'Username or Email is required.';
    }
    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    }

    // If no validation errors, proceed with authentication
    if (empty($errors)) {
        // Try database authentication
        try {
            $db = Database::getInstance()->getConnection();
            // chrmo_db.sql schema: users(id INT AUTO_INCREMENT, name, email, password, role)
            $stmt = $db->prepare("SELECT id, name, email, password, role, department FROM users WHERE email = ? OR name = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $row = $stmt->fetch();
            if ($row && password_verify($password, $row['password'])) {
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['user_email'] = $row['email'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_username'] = $row['name']; // fallback mapping
                $_SESSION['user_department'] = $row['department'] ?? '';
                $_SESSION['user_role'] = $row['role'] ?? 'user';

                // Handle Remember Me
                if ($rememberMe) {
                    $cookieName = 'remember_me';
                    $cookieValue = $row['id'] . ':' . hash_hmac('sha256', $row['id'] . $row['email'], SECRET_KEY);
                    $expiration = time() + (86400 * 30);
                    $cookieOptions = [
                        'expires' => $expiration,
                        'path' => '/',
                        'secure' => false,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ];
                    setcookie($cookieName, $cookieValue, $cookieOptions);
                    $identifierCookieOptions = [
                        'expires' => time() + (86400 * 365),
                        'path' => '/',
                        'secure' => false,
                        'httponly' => false,
                        'samesite' => 'Lax'
                    ];
                    setcookie('saved_identifier', $identifier, $identifierCookieOptions);
                    $passwordCookieOptions = [
                        'expires' => time() + (86400 * 365),
                        'path' => '/',
                        'secure' => false,
                        'httponly' => false,
                        'samesite' => 'Lax'
                    ];
                    setcookie('saved_password', base64_encode($password), $passwordCookieOptions);
                } else {
                    if (isset($_COOKIE['remember_me'])) setcookie('remember_me', '', time() - 3600, "/");
                    if (isset($_COOKIE['saved_identifier'])) setcookie('saved_identifier', '', time() - 3600, "/");
                    if (isset($_COOKIE['saved_password'])) setcookie('saved_password', '', time() - 3600, "/");
                }

                header('Location: dashboard.php?welcome=true');
                exit();
            }
        } catch (Exception $e) {
            error_log('DB login failed: ' . $e->getMessage());
        }

        // Authentication failed via DB
        if (empty($errors)) {
            $errors['login'] = 'Invalid username/email or password.';
        }
    }
}

// Generate CSRF token for forms
$csrfToken = Security::generateCSRFToken();

// Include common header AFTER all processing is done (but before HTML output)
include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CHRMO Document Tracking - Login</title>
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
                        }
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
        /* This fade-in animation applies to the main login form container */
        .fade-in {
            animation: fadeIn 0.8s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .shake {
            animation: shake 0.3s ease-in-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            50% { transform: translateX(8px); }
            75% { transform: translateX(-8px); }
        }
        .fade-out {
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
        }
        .floating-label-group {
            position: relative;
            margin-bottom: 2rem; /* Adjusted margin-bottom for slightly less spacing */
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
        input:focus ~ .floating-label,
        input:not(:placeholder-shown) ~ .floating-label {
            top: -10px;
            left: 12px;
            font-size: 0.75rem;
            color: #6868AC;
            background-color: white;
            z-index: 10;
        }
        input:focus {
            outline: none;
            border-color: #6868AC;
            box-shadow: 0 0 0 2px rgba(104, 104, 172, 0.2);
        }
        /* Password field container - ensure proper positioning */
        .floating-label-group {
            position: relative;
        }
        .floating-label-group .relative {
            position: relative !important;
            display: block;
        }
        .password-toggle {
            position: absolute !important;
            right: 12px !important;
            left: auto !important;
            top: 50% !important;
            bottom: auto !important;
            transform: translateY(-50%) !important;
            cursor: pointer;
            color: #94a3b8;
            border: none;
            background: transparent;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: none;
            z-index: 10;
        }
        .password-toggle:hover,
        .password-toggle:focus-visible {
            color: #64748b;
            outline: none;
        }
        /* Modal styles */
        .modal {
            display: none; /* toggled by adding the 'open' class */
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease-out;
            align-items: center;
            justify-content: center;
            padding: 16px; /* prevent edges from touching on small screens */
        }
        .modal.open { display: flex; }
        .modal-content {
            background-color: #ffffff;
            margin: 0 auto; /* centered via flex container */
            padding: 0;
            border-radius: 1rem;
            width: 100%;
            max-width: 520px;
            max-height: 90vh; /* keep within viewport */
            overflow-y: auto; /* scroll content if needed */
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideIn 0.3s ease-out;
            border: 1px solid #e5e7eb;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            padding: 0 10px;
        }
        .close:hover,
        .close:focus {
            color: #000;
        }
        
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
<body class="flex items-center justify-center min-h-screen p-4 md:p-6">
<div class="w-full max-w-2xl bg-white rounded-2xl shadow-xl overflow-hidden flex flex-col md:flex-row page-enter">
    <div class="relative md:w-1/2 bg-gradient-to-br from-primary-400 to-primary-500 text-white p-6 flex flex-col items-center justify-center text-center">
        <img src="hr.png" alt="CHRMO Logo" class="w-40 h-auto mb-3 rounded-full shadow-lg" />
        <h1 class="text-3xl font-bold mb-2">Welcome Back!</h1>
        <p class="text-primary-100">Sign in to continue managing your documents.</p>
        <div class="absolute bottom-4 left-0 right-0 text-xs text-primary-200">
            &copy; 2026 CHRMO Document Tracking
        </div>
    </div>

    <div class="w-full md:w-1/2 p-6 md:p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Login to Account</h2>
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-8" role="alert">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form action="log-in.php" method="POST" class="space-y-5" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="floating-label-group">
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    placeholder=" "
                    class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-primary-500 focus:border-primary-500 text-gray-900"
                    autocomplete="off"
                    value="<?php echo htmlspecialchars($identifier); ?>"
                />
                <label for="identifier" class="floating-label">Username or Email</label>
            </div>
            <div class="floating-label-group">
                <div class="relative" style="position: relative;">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder=" "
                        class="block w-full px-4 py-3 pr-12 border border-gray-300 rounded-xl focus:ring-primary-500 focus:border-primary-500 text-gray-900"
                        autocomplete="current-password"
                        value="<?php echo htmlspecialchars($password); ?>"
                        required
                    />
                    <label for="password" class="floating-label">Password</label>
                    <button type="button" class="password-toggle" data-target="password" aria-label="Toggle password visibility" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%);">
                        <i class="far fa-eye-slash"></i>
                    </button>
                </div>
            </div>
            <div class="flex items-start justify-between text-sm space-x-4">
                <label class="flex items-start text-gray-600 cursor-pointer text-sm">
                    <input type="checkbox" id="rememberMe" name="rememberMe" <?php echo $rememberMe ? 'checked' : ''; ?> class="mr-2 mt-1" />
                    <span>Keep me signed in</span>
                </label>
                <a href="#" onclick="openForgotPasswordModal(); return false;" class="text-primary-500 text-sm link-underline">Forgot Password?</a>
            </div>
            <p class="text-xs text-center text-gray-500 -mt-4 mb-2">
                <a href="#" class="link-underline" onclick="openTermsModal(event)">Terms & Privacy Policy</a>
            </p>
            <button
                type="submit"
                class="w-full bg-primary-400 hover:bg-primary-500 text-white py-3 rounded-xl font-medium btn-press btn-ripple hover-lift"
            >
                Sign In
            </button>

            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-200"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-4 bg-white text-gray-500">Need an account?</span>
                </div>
            </div>

            <div class="block w-full text-center py-3 px-4 border border-gray-200 text-gray-500 rounded-xl text-sm bg-gray-50">
                <i class="fas fa-info-circle mr-1"></i> Contact your administrator to create an account.
            </div>
        </form>
    </div>
</div>

<!-- Forgot Password Modal (3-step OTP flow) -->
<div id="forgotPasswordModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-primary-400 to-primary-500 text-white p-6 rounded-t-2xl relative">
            <button class="close" onclick="closeForgotPasswordModal()" aria-label="Close modal">&times;</button>
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <i class="fas fa-key text-2xl"></i>
                </div>
                <div>
                    <h3 id="modalTitle" class="text-2xl font-bold">Reset Password</h3>
                    <p id="modalSubtitle" class="text-primary-100 text-sm mt-1">Enter your email to receive a verification code.</p>
                </div>
            </div>
            <!-- Step indicator -->
            <div class="flex gap-2 mt-4">
                <div id="stepDot1" class="h-1.5 flex-1 rounded-full bg-white"></div>
                <div id="stepDot2" class="h-1.5 flex-1 rounded-full bg-white bg-opacity-30"></div>
                <div id="stepDot3" class="h-1.5 flex-1 rounded-full bg-white bg-opacity-30"></div>
            </div>
        </div>

        <div class="p-6">
            <input type="hidden" id="forgotCsrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div id="forgotPasswordMessage" class="mb-4 hidden" role="alert" aria-live="polite"></div>

            <!-- STEP 1: Enter Email -->
            <div id="resetStep1" class="space-y-4">
                <p class="text-sm text-gray-600 mb-2">We'll send a <strong>6-digit verification code</strong> to the email associated with your account.</p>
                <div class="floating-label-group">
                    <input type="email" id="resetEmail" placeholder=" "
                        class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-primary-500 focus:border-primary-500 text-gray-900"
                        autocomplete="email" required />
                    <label for="resetEmail" class="floating-label">Email Address</label>
                </div>
                <div class="flex gap-3 mt-2">
                    <button type="button" onclick="closeForgotPasswordModal()"
                        class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 font-medium">Cancel</button>
                    <button type="button" id="sendCodeBtn" onclick="sendOtpCode()"
                        class="flex-1 bg-primary-400 hover:bg-primary-500 text-white py-2.5 px-4 rounded-xl font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="sendCodeText">Send Code</span>
                    </button>
                </div>
            </div>

            <!-- STEP 2: Enter 6-digit Code -->
            <div id="resetStep2" class="space-y-4" style="display:none;">
                <p class="text-sm text-gray-600 mb-2">A 6-digit code was sent to <strong id="sentToEmail"></strong>. Enter it below.</p>
                <div class="flex justify-center gap-2" id="otpInputGroup">
                    <input type="text" maxlength="1" class="otp-digit" data-idx="0" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" />
                    <input type="text" maxlength="1" class="otp-digit" data-idx="1" inputmode="numeric" pattern="[0-9]" />
                    <input type="text" maxlength="1" class="otp-digit" data-idx="2" inputmode="numeric" pattern="[0-9]" />
                    <input type="text" maxlength="1" class="otp-digit" data-idx="3" inputmode="numeric" pattern="[0-9]" />
                    <input type="text" maxlength="1" class="otp-digit" data-idx="4" inputmode="numeric" pattern="[0-9]" />
                    <input type="text" maxlength="1" class="otp-digit" data-idx="5" inputmode="numeric" pattern="[0-9]" />
                </div>
                <p class="text-xs text-center text-gray-400" id="otpTimer">Code expires in <span id="otpCountdown">15:00</span></p>
                <div class="text-center">
                    <button type="button" id="resendCodeBtn" onclick="sendOtpCode()" class="text-sm text-primary-500 hover:underline disabled:text-gray-400 disabled:no-underline" disabled>Resend Code</button>
                </div>
                <div class="flex gap-3 mt-2">
                    <button type="button" onclick="goToStep(1)"
                        class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 font-medium">Back</button>
                    <button type="button" id="verifyCodeBtn" onclick="verifyOtpCode()"
                        class="flex-1 bg-primary-400 hover:bg-primary-500 text-white py-2.5 px-4 rounded-xl font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="verifyCodeText">Verify Code</span>
                    </button>
                </div>
            </div>

            <!-- STEP 3: Set New Password -->
            <div id="resetStep3" class="space-y-4" style="display:none;">
                <p class="text-sm text-gray-600 mb-2">Code verified! Create a new password for your account.</p>
                <div class="floating-label-group" style="position: relative;">
                    <input type="password" id="resetNewPassword" placeholder=" "
                        class="block w-full px-4 py-3 border border-gray-300 rounded-xl text-gray-900 pr-10"
                        autocomplete="new-password" required />
                    <label for="resetNewPassword" class="floating-label">New Password</label>
                    <button type="button" class="password-toggle" data-target="resetNewPassword" aria-label="Toggle password visibility"
                        style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%);">
                        <i class="far fa-eye-slash"></i>
                    </button>
                </div>
                <div class="floating-label-group" style="position: relative;">
                    <input type="password" id="resetConfirmPassword" placeholder=" "
                        class="block w-full px-4 py-3 border border-gray-300 rounded-xl text-gray-900 pr-10"
                        autocomplete="new-password" required />
                    <label for="resetConfirmPassword" class="floating-label">Confirm Password</label>
                    <button type="button" class="password-toggle" data-target="resetConfirmPassword" aria-label="Toggle password visibility"
                        style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%);">
                        <i class="far fa-eye-slash"></i>
                    </button>
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-xs text-gray-500 space-y-1">
                    <p class="font-semibold text-gray-600">Password requirements:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li>At least 8 characters</li>
                        <li>One uppercase letter</li>
                        <li>One lowercase letter</li>
                        <li>One number</li>
                        <li>One special character</li>
                    </ul>
                </div>
                <div class="flex gap-3 mt-2">
                    <button type="button" onclick="closeForgotPasswordModal()"
                        class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 font-medium">Cancel</button>
                    <button type="button" id="resetPasswordBtn" onclick="submitNewPassword()"
                        class="flex-1 bg-primary-400 hover:bg-primary-500 text-white py-2.5 px-4 rounded-xl font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="resetPasswordText">Update Password</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .otp-digit {
        width: 48px; height: 56px;
        text-align: center; font-size: 24px; font-weight: 700;
        border: 2px solid #e2e8f0; border-radius: 12px;
        outline: none; transition: border-color 0.2s, box-shadow 0.2s;
        color: #1e293b;
    }
    .otp-digit:focus {
        border-color: #6868AC;
        box-shadow: 0 0 0 3px rgba(104,104,172,0.2);
    }
    .otp-digit.filled {
        border-color: #6868AC;
        background: #f5f3ff;
    }
</style>

<!-- Terms & Privacy Policy Modal -->
<div id="termsModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="termsTitle" style="display:none;">
    <div class="modal-content max-w-3xl">
        <div class="bg-gradient-to-r from-primary-400 to-primary-500 text-white p-5 rounded-t-2xl flex items-center justify-between">
            <div>
                <h3 id="termsTitle" class="text-2xl font-bold">Terms & Privacy Policy</h3>
                <p class="text-primary-100 text-sm mt-1">Understand how your data is handled.</p>
            </div>
            <button class="close" onclick="closeTermsModal()" aria-label="Close terms modal">&times;</button>
        </div>
        <div class="p-6 space-y-6 text-sm text-gray-700">
            <section>
                <h4 class="text-lg font-semibold mb-2">Data Usage</h4>
                <p>Your submitted documents, usernames, and associated activity logs are stored securely within the CHRMO tracking database for audit purposes.</p>
            </section>
            <section>
                <h4 class="text-lg font-semibold mb-2">Privacy</h4>
                <p>Only authorized CHRMO personnel can access the system. We do not share credentials or OCR content with third parties.</p>
            </section>
            <section>
                <h4 class="text-lg font-semibold mb-2">Security Responsibilities</h4>
                <p>Please keep your username and password confidential. Report suspicious access to the administrator immediately.</p>
            </section>
            <div class="text-right">
                <button class="bg-primary-400 hover:bg-primary-500 text-white py-2 px-6 rounded-xl font-medium" onclick="closeTermsModal()">Close</button>
            </div>
        </div>
    </div>

<script>
    // Client-side: Forgot Password multi-step modal
    (function(){
    const state = { step: 1, otpTimerInterval: null, otpExpiresAt: 0, lastFocus: null, resetId: null, resetToken: null, verifiedCode: null };
    const focusableSelector = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

    const getOpenModal = () => document.querySelector('.modal.open');

    const showResetMessage = (message, intent = 'info') => {
        const container = document.getElementById('forgotPasswordMessage');
        if (!container) return;
        const palette = {
            info: { color: 'blue', icon: 'fa-info-circle' },
            success: { color: 'green', icon: 'fa-check-circle' },
            error: { color: 'red', icon: 'fa-triangle-exclamation' }
        }[intent] || { color: 'blue', icon: 'fa-info-circle' };
        container.className = `mb-4 rounded-xl border border-${palette.color}-200 bg-${palette.color}-50 text-${palette.color}-800 px-4 py-3`;
        container.classList.remove('hidden');
        container.innerHTML = `<div class="flex items-start gap-3"><i class="fas ${palette.icon} mt-1.5"></i><div>${message}</div></div>`;
    };

    const hideResetMessage = () => {
        const container = document.getElementById('forgotPasswordMessage');
        if (container) { container.className = 'mb-4 hidden'; container.innerHTML = ''; }
    };

    // ─── Step management ───
    const goToStep = (n) => {
        state.step = n;
        document.getElementById('resetStep1').style.display = n === 1 ? 'block' : 'none';
        document.getElementById('resetStep2').style.display = n === 2 ? 'block' : 'none';
        document.getElementById('resetStep3').style.display = n === 3 ? 'block' : 'none';
        // Update step dots
        for (let i = 1; i <= 3; i++) {
            const dot = document.getElementById('stepDot' + i);
            if (dot) dot.className = 'h-1.5 flex-1 rounded-full ' + (i <= n ? 'bg-white' : 'bg-white bg-opacity-30');
        }
        // Update subtitle
        const sub = document.getElementById('modalSubtitle');
        if (sub) {
            if (n === 1) sub.textContent = 'Enter your email to receive a verification code.';
            else if (n === 2) sub.textContent = 'Enter the 6-digit code sent to your email.';
            else sub.textContent = 'Create a new secure password.';
        }
        hideResetMessage();
        // Focus first input of new step
        setTimeout(() => {
            if (n === 1) {
                // Email step
                document.getElementById('resetEmail')?.focus();
                state.resetId = null;
                state.resetToken = null;
                state.verifiedCode = null;
            } else if (n === 2) {
                document.querySelector('.otp-digit[data-idx="0"]')?.focus();
            } else if (n === 3) {
                document.getElementById('resetNewPassword')?.focus();
            }
        }, 120);
    };

    // ─── Step 1: Send OTP ───
    const sendOtpCode = () => {
        const emailField = document.getElementById('resetEmail');
        const sendBtn = document.getElementById('sendCodeBtn');
        const sendText = document.getElementById('sendCodeText');
        const email = (emailField?.value || '').trim();

        if (!email) { showResetMessage('Please enter your email address.', 'error'); return; }
        if (!/\S+@\S+\.\S+/.test(email)) { showResetMessage('Please enter a valid email address.', 'error'); return; }

        sendBtn.disabled = true;
        sendText.textContent = 'Sending…';
        showResetMessage('Sending verification code…', 'info');

        const fd = new FormData();
        fd.append('forgot_password', 'true');
        fd.append('email', email);
        fd.append('csrf_token', document.getElementById('forgotCsrfToken').value);

        fetch('log-in.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showResetMessage(data.message || 'Code sent! Check your email.', 'success');
                    document.getElementById('sentToEmail').textContent = email;
                    setTimeout(() => goToStep(2), 800);
                    startOtpTimer(15 * 60); // 15 min expiry countdown
                    startResendCooldown(data.cooldown || 120);
                } else {
                    showResetMessage(data.message || 'Failed to send code.', 'error');
                }
                sendBtn.disabled = false;
                sendText.textContent = 'Send Code';
            })
            .catch(() => {
                showResetMessage('Network error. Please try again.', 'error');
                sendBtn.disabled = false;
                sendText.textContent = 'Send Code';
            });
    };

    // ─── OTP Timer ───
    const startOtpTimer = (seconds) => {
        clearInterval(state.otpTimerInterval);
        let remaining = seconds;
        const el = document.getElementById('otpCountdown');
        const tick = () => {
            const m = Math.floor(remaining / 60);
            const s = remaining % 60;
            if (el) el.textContent = `${m}:${String(s).padStart(2, '0')}`;
            if (remaining <= 0) { clearInterval(state.otpTimerInterval); if (el) el.textContent = 'Expired'; }
            remaining--;
        };
        tick();
        state.otpTimerInterval = setInterval(tick, 1000);
    };

    const startResendCooldown = (seconds) => {
        const btn = document.getElementById('resendCodeBtn');
        if (!btn) return;
        state.resendCooldown = seconds;
        btn.disabled = true;
        btn.textContent = `Resend Code (${seconds}s)`;
        const int = setInterval(() => {
            state.resendCooldown--;
            if (state.resendCooldown <= 0) {
                clearInterval(int);
                btn.disabled = false;
                btn.textContent = 'Resend Code';
            } else {
                btn.textContent = `Resend Code (${state.resendCooldown}s)`;
            }
        }, 1000);
    };

    // ─── OTP digit inputs auto-advance ───
    const initOtpInputs = () => {
        document.querySelectorAll('.otp-digit').forEach(input => {
            input.addEventListener('input', (e) => {
                const val = e.target.value.replace(/[^0-9]/g, '');
                e.target.value = val.slice(-1);
                e.target.classList.toggle('filled', val.length > 0);
                if (val && e.target.dataset.idx < 5) {
                    const next = document.querySelector(`.otp-digit[data-idx="${+e.target.dataset.idx + 1}"]`);
                    if (next) next.focus();
                }
                // Auto-verify if all 6 filled
                if (getOtpCode().length === 6) setTimeout(verifyOtpCode, 200);
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && e.target.dataset.idx > 0) {
                    const prev = document.querySelector(`.otp-digit[data-idx="${+e.target.dataset.idx - 1}"]`);
                    if (prev) { prev.focus(); prev.value = ''; prev.classList.remove('filled'); }
                }
            });
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasted = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
                pasted.split('').forEach((ch, i) => {
                    const inp = document.querySelector(`.otp-digit[data-idx="${i}"]`);
                    if (inp) { inp.value = ch; inp.classList.add('filled'); }
                });
                if (pasted.length === 6) setTimeout(verifyOtpCode, 200);
            });
        });
    };

    const getOtpCode = () => {
        let code = '';
        document.querySelectorAll('.otp-digit').forEach(i => code += i.value);
        return code;
    };

    // ─── Step 2: Verify OTP ───
    const verifyOtpCode = () => {
        const code = getOtpCode();
        const btn = document.getElementById('verifyCodeBtn');
        const txt = document.getElementById('verifyCodeText');
        if (code.length !== 6) { showResetMessage('Please enter the full 6-digit code.', 'error'); return; }

        btn.disabled = true;
        txt.textContent = 'Verifying…';

        const fd = new FormData();
        fd.append('verify_otp', 'true');
        fd.append('email', document.getElementById('resetEmail').value.trim());
        fd.append('code', code);
        fd.append('csrf_token', document.getElementById('forgotCsrfToken').value);

        fetch('log-in.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    state.resetId = (data.reset_id !== undefined && data.reset_id !== null) ? data.reset_id : null;
                    state.resetToken = (data.reset_token !== undefined && data.reset_token !== null) ? data.reset_token : null;
                    state.verifiedCode = code;
                    showResetMessage(data.message || 'Code verified!', 'success');
                    clearInterval(state.otpTimerInterval);
                    setTimeout(() => goToStep(3), 600);
                } else {
                    showResetMessage(data.message || 'Invalid code.', 'error');
                    // Clear inputs
                    document.querySelectorAll('.otp-digit').forEach(i => { i.value = ''; i.classList.remove('filled'); });
                    document.querySelector('.otp-digit[data-idx="0"]')?.focus();
                }
                btn.disabled = false;
                txt.textContent = 'Verify Code';
            })
            .catch(() => {
                showResetMessage('Network error.', 'error');
                btn.disabled = false;
                txt.textContent = 'Verify Code';
            });
    };

    // ─── Step 3: Set new password ───
    const submitNewPassword = () => {
        const newPw = document.getElementById('resetNewPassword').value;
        const confirmPw = document.getElementById('resetConfirmPassword').value;
        const btn = document.getElementById('resetPasswordBtn');
        const txt = document.getElementById('resetPasswordText');

        if (!newPw || !confirmPw) { showResetMessage('Please fill in both fields.', 'error'); return; }
        if (newPw !== confirmPw) { showResetMessage('Passwords do not match.', 'error'); return; }

        btn.disabled = true;
        txt.textContent = 'Updating…';

        const fd = new FormData();
        fd.append('reset_password', 'true');
        fd.append('email', document.getElementById('resetEmail').value.trim());
        if (state.resetId !== null && state.resetId !== undefined) fd.append('reset_id', state.resetId);
        if (state.resetToken !== null && state.resetToken !== undefined) fd.append('reset_token', state.resetToken);
        if (state.verifiedCode) fd.append('code', state.verifiedCode);
        fd.append('newPassword', newPw);
        fd.append('confirmPassword', confirmPw);
        fd.append('csrf_token', document.getElementById('forgotCsrfToken').value);

        fetch('log-in.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showResetMessage(data.message || 'Password updated! You can now sign in.', 'success');
                    setTimeout(() => closeForgotPasswordModal(), 2000);
                } else {
                    showResetMessage(data.message || 'Failed to update password.', 'error');
                    btn.disabled = false;
                    txt.textContent = 'Update Password';
                }
            })
            .catch(() => {
                showResetMessage('Network error.', 'error');
                btn.disabled = false;
                txt.textContent = 'Update Password';
            });
    };

    const initPasswordToggles = () => {
        document.querySelectorAll('.password-toggle').forEach((btn) => {
            const toggleHandler = (event) => {
                if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
                if (event.type === 'keydown') event.preventDefault();
                const targetId = btn.getAttribute('data-target');
                if (!targetId) return;
                const target = document.getElementById(targetId);
                if (!target) return;
                const icon = btn.querySelector('i');
                const reveal = target.type === 'password';
                target.type = reveal ? 'text' : 'password';
                if (icon) {
                    icon.classList.toggle('fa-eye', reveal);
                    icon.classList.toggle('fa-eye-slash', !reveal);
                }
            };
            btn.addEventListener('click', toggleHandler);
            btn.addEventListener('keydown', toggleHandler);
        });
    };

    const openForgotPasswordModal = () => {
        state.lastFocus = document.activeElement;
        const modal = document.getElementById('forgotPasswordModal');
        if (!modal) return;
        modal.classList.add('open');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        goToStep(1);
    };

    const closeForgotPasswordModal = () => {
        const modal = document.getElementById('forgotPasswordModal');
        if (!modal) return;
        modal.classList.remove('open');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        clearInterval(state.otpTimerInterval);
        // Reset all fields
        const emailField = document.getElementById('resetEmail');
        if (emailField) emailField.value = '';
        document.querySelectorAll('.otp-digit').forEach(i => { i.value = ''; i.classList.remove('filled'); });
        const pw1 = document.getElementById('resetNewPassword'); if (pw1) pw1.value = '';
        const pw2 = document.getElementById('resetConfirmPassword'); if (pw2) pw2.value = '';
        hideResetMessage();
        // Re-enable buttons
        ['sendCodeBtn','verifyCodeBtn','resetPasswordBtn'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.disabled = false;
        });
        if (state.lastFocus) { state.lastFocus.focus(); state.lastFocus = null; }
    };

    const openTermsModal = (evt) => {
        if (evt) evt.preventDefault();
        const modal = document.getElementById('termsModal');
        if (!modal) return;
        modal.classList.add('open');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    const closeTermsModal = () => {
        const modal = document.getElementById('termsModal');
        if (!modal) return;
        modal.classList.remove('open');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    };

    const initLoginValidation = () => {
        const form = document.querySelector('form[action="log-in.php"]');
        if (!form) return;
        form.addEventListener('submit', (event) => {
            const idInput = document.getElementById('identifier');
            const pwInput = document.getElementById('password');
            const submitBtn = form.querySelector('[type="submit"]');
            let valid = true;
            if (!idInput || !idInput.value.trim()) {
                valid = false;
                if (idInput) {
                    idInput.classList.add('shake', 'border-red-400');
                    setTimeout(() => idInput.classList.remove('shake'), 400);
                }
            }
            if (!pwInput || !pwInput.value.trim()) {
                valid = false;
                if (pwInput) {
                    pwInput.classList.add('shake', 'border-red-400');
                    setTimeout(() => pwInput.classList.remove('shake'), 400);
                }
            }
            if (!valid) {
                event.preventDefault();
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.pointerEvents = '';
                    submitBtn.style.opacity = '';
                    submitBtn.classList.remove('loading');
                    const spinner = submitBtn.querySelector('.spinner');
                    if (spinner) spinner.remove();
                }
                const errorBox = document.createElement('div');
                errorBox.className = 'bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl mb-4 fade-in';
                errorBox.innerHTML = '<div class="font-semibold mb-1">Please fix the following:</div><ul class="list-disc list-inside text-sm"><li>Username or Email is required.</li><li>Password is required.</li></ul>';
                const parent = form.parentElement;
                parent.insertBefore(errorBox, form);
                setTimeout(() => {
                    errorBox.classList.add('fade-out');
                    errorBox.addEventListener('transitionend', () => errorBox.remove());
                }, 2200);
            }
        });
    };

    document.addEventListener('keydown', (event) => {
        const modal = getOpenModal();
        if (!modal) return;
        if (event.key === 'Escape') {
            if (modal.id === 'forgotPasswordModal') closeForgotPasswordModal();
            else if (modal.id === 'termsModal') closeTermsModal();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(modal.querySelectorAll(focusableSelector));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    window.addEventListener('click', (event) => {
        const modal = getOpenModal();
        if (modal && event.target === modal) {
            if (modal.id === 'forgotPasswordModal') closeForgotPasswordModal();
            else if (modal.id === 'termsModal') closeTermsModal();
        }
    });

    initOtpInputs();
    initPasswordToggles();
    initLoginValidation();

    window.openForgotPasswordModal = openForgotPasswordModal;
    window.closeForgotPasswordModal = closeForgotPasswordModal;
    window.openTermsModal = openTermsModal;
    window.closeTermsModal = closeTermsModal;
    window.sendOtpCode = sendOtpCode;
    window.verifyOtpCode = verifyOtpCode;
    window.submitNewPassword = submitNewPassword;
    window.goToStep = goToStep;
})();
</script>

<!-- Logout Success Modal -->
<?php if ($showLogoutModal): ?>
<div id="logoutModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-2xl p-8 max-w-md w-11/12 mx-4 text-center relative overflow-hidden animate-modal-in">
        <!-- Top gradient border -->
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-500 via-purple-500 to-green-500"></div>
        
        <!-- Floating particles background -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="particle absolute w-1 h-1 bg-blue-200 rounded-full animate-float-1"></div>
            <div class="particle absolute w-1 h-1 bg-purple-200 rounded-full animate-float-2"></div>
            <div class="particle absolute w-1 h-1 bg-green-200 rounded-full animate-float-3"></div>
            <div class="particle absolute w-1 h-1 bg-yellow-200 rounded-full animate-float-4"></div>
        </div>
        
        <!-- Success icon -->
        <div class="text-green-500 text-5xl mb-4 animate-bounce-in">
            <i class="fas fa-check-circle"></i>
        </div>
        
        <!-- Title -->
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Successfully Logged Out</h2>
        
        <!-- Message -->
        <p class="text-gray-600 mb-6 leading-relaxed">
            Thank you for using CHRMO Document Tracking System. Your session has been securely ended.
        </p>
        
    </div>
</div>
<script>
  // Auto-dismiss logout modal after 2.5 seconds with fade
  (function(){
    const lm = document.getElementById('logoutModal');
    if(lm){
      setTimeout(()=>{
        lm.style.transition = 'opacity 0.5s ease';
        lm.style.opacity = '0';
        setTimeout(()=> lm.remove(), 500);
      }, 2500);
    }
  })();
</script>

<style>
    @keyframes modal-in {
        0% {
            opacity: 0;
            transform: scale(0.8) translateY(20px);
        }
        100% {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    
    @keyframes bounce-in {
        0% {
            opacity: 0;
            transform: scale(0);
        }
        50% {
            opacity: 1;
            transform: scale(1.2);
        }
        100% {
            opacity: 1;
            transform: scale(1);
        }
    }
    
    @keyframes fade-in-delayed {
        0%, 40% {
            opacity: 0;
        }
        100% {
            opacity: 1;
        }
    }
    
    @keyframes float-1 {
        0%, 100% {
            transform: translate(10px, 20px) rotate(0deg);
            opacity: 0.7;
        }
        50% {
            transform: translate(30px, 10px) rotate(180deg);
            opacity: 0.3;
        }
    }
    
    @keyframes float-2 {
        0%, 100% {
            transform: translate(80%, 30px) rotate(0deg);
            opacity: 0.5;
        }
        50% {
            transform: translate(60%, 10px) rotate(180deg);
            opacity: 0.8;
        }
    }
    
    @keyframes float-3 {
        0%, 100% {
            transform: translate(20px, 80%) rotate(0deg);
            opacity: 0.6;
        }
        50% {
            transform: translate(40px, 60%) rotate(180deg);
            opacity: 0.4;
        }
    }
    
    @keyframes float-4 {
        0%, 100% {
            transform: translate(90%, 80%) rotate(0deg);
            opacity: 0.4;
        }
        50% {
            transform: translate(70%, 70%) rotate(180deg);
            opacity: 0.7;
        }
    }
    
    .animate-modal-in {
        animation: modal-in 0.5s ease-out;
    }
    
    .animate-bounce-in {
        animation: bounce-in 0.6s ease-out 0.3s both;
    }
    
    .animate-progress {
        animation: progress 2s ease-out;
    }
    
    .animate-fade-in-delayed {
        animation: fade-in-delayed 2s ease-out;
    }
    
    .animate-float-1 {
        animation: float-1 3s infinite ease-in-out;
    }
    
    .animate-float-2 {
        animation: float-2 3s infinite ease-in-out 0.5s;
    }
    
    .animate-float-3 {
        animation: float-3 3s infinite ease-in-out 1s;
    }
    
    .animate-float-4 {
        animation: float-4 3s infinite ease-in-out 1.5s;
    }
</style>
<?php endif; ?>

<!-- Registration Success Modal -->
<?php
$showRegistrationModal = false;
if (!empty($notificationMessage) && $notificationType === 'success') {
    $showRegistrationModal = true;
}
?>
<?php if ($showRegistrationModal): ?>
<div id="registrationModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-2xl p-8 max-w-md w-11/12 mx-4 text-center relative overflow-hidden animate-modal-in">
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-green-400 via-emerald-500 to-teal-500"></div>
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="particle absolute w-1.5 h-1.5 bg-green-200 rounded-full animate-float-1"></div>
            <div class="particle absolute w-1 h-1 bg-emerald-200 rounded-full animate-float-2"></div>
            <div class="particle absolute w-1 h-1 bg-teal-200 rounded-full animate-float-3"></div>
        </div>
        <div class="text-green-500 text-5xl mb-4 animate-bounce-in">
            <i class="fas fa-user-check"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Account Created!</h2>
        <p class="text-gray-600 mb-4 leading-relaxed">
            Your account has been successfully created.<br>You may now log in with your credentials.
        </p>
        <div class="flex items-center justify-center gap-2 text-sm text-gray-400 mb-2">
            <i class="fas fa-spinner fa-spin"></i>
            <span id="regRedirectMsg">Redirecting to login…</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-1 overflow-hidden">
            <div id="regProgressBar" class="h-full bg-gradient-to-r from-green-400 to-emerald-500 rounded-full" style="width:0%; transition: width 2.5s ease;"></div>
        </div>
    </div>
</div>
<script>
  (function(){
    const rm = document.getElementById('registrationModal');
    const bar = document.getElementById('regProgressBar');
    if(rm && bar){
      requestAnimationFrame(()=>{ bar.style.width = '100%'; });
      setTimeout(()=>{
        rm.style.transition = 'opacity 0.5s ease';
        rm.style.opacity = '0';
        setTimeout(()=> rm.remove(), 500);
      }, 3000);
    }
  })();
</script>
<?php endif; ?>

<script>
(function(){
  const navEntries = performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
  const isReload = navEntries && navEntries[0] ? (navEntries[0].type === 'reload') : (performance.navigation && performance.navigation.type === 1);
  if (isReload) {
    const id = document.getElementById('identifier');
    const pw = document.getElementById('password');
    if (id) id.value = '';
    if (pw) pw.value = '';
  }
})();
</script>

</body>
</html>
<?php
// Flush output buffer at the end
ob_end_flush();
?>