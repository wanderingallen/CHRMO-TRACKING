<?php
// Mobile-friendly password reset API (OTP + token)
// POST action=send_code|verify_code|reset_password

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Shared app code lives in lib/OCR(UPDATED)
require_once __DIR__ . '/../lib/OCR(UPDATED)/config.php';
require_once __DIR__ . '/../lib/OCR(UPDATED)/database.php';
require_once __DIR__ . '/../lib/OCR(UPDATED)/security.php';
require_once __DIR__ . '/../lib/OCR(UPDATED)/email.php';

$action = $_POST['action'] ?? '';
$email = Security::sanitize($_POST['email'] ?? '');
$ip = Security::getClientIP();

$fail = function ($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
};

$ok = function ($payload = []) {
    echo json_encode(array_merge(['success' => true], $payload));
    exit();
};

if (!in_array($action, ['send_code', 'verify_code', 'reset_password'], true)) {
    $fail('Invalid action.');
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('Valid email is required.');
}

try {
    $db = Database::getInstance()->getConnection();

    // Ensure reset_token_hash column exists (in case DB tables were created before the update)
    try {
        $db->exec("ALTER TABLE password_resets ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER token_hash");
    } catch (Throwable $e) {
        // ignore
    }

    $stmt = $db->prepare('SELECT id, email, name FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Requested behavior: explicitly tell user email is not registered
        $fail('This email is not registered in the system.', 404);
    }

    if ($action === 'send_code') {
        $identifier = $ip . '|' . strtolower($email);
        $maxAttempts = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? 30 : MAX_RESET_ATTEMPTS;
        if (!Security::checkRateLimit($identifier, 'api_forgot_password', $maxAttempts, RESET_TOKEN_EXPIRY)) {
            $fail('Too many reset attempts. Please try again later.', 429);
        }

        $code = str_pad(strval(random_int(0, 999999)), 6, '0', STR_PAD_LEFT);
        $tokenHash = Security::hashToken($code);

        $stmt = $db->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, user_agent) '
            . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)' 
        );
        $stmt->execute([
            $user['id'],
            $tokenHash,
            RESET_TOKEN_EXPIRY,
            $ip,
            $_SERVER['HTTP_USER_AGENT'] ?? 'mobile'
        ]);

        // Email OTP
        $emailService = new EmailService();
        $subject = 'Your Password Reset Code - CHRMO Document Tracking';
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">'
            . '<div style="background: linear-gradient(135deg, #38bdf8, #0ea5e9); padding: 30px; border-radius: 10px; text-align: center; margin-bottom: 20px;">'
            . '<h1 style="color: white; margin: 0;">Password Reset Code</h1>'
            . '</div>'
            . '<div style="background: #f8fafc; padding: 30px; border-radius: 10px; border: 1px solid #e2e8f0;">'
            . '<h2 style="color: #1e293b; margin-top: 0;">Hello ' . htmlspecialchars($user['name']) . '</h2>'
            . '<p style="color: #475569; font-size: 16px; line-height: 1.6;">Use this 6-digit code to reset your password.</p>'
            . '<div style="background: white; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0; border: 2px solid #0ea5e9;">'
            . '<h3 style="color: #0ea5e9; margin: 0; font-size: 32px; letter-spacing: 3px;">' . $code . '</h3>'
            . '</div>'
            . '<p style="color: #475569; font-size: 14px;">This code expires in 15 minutes.</p>'
            . '</div>'
            . '</div>';
        $text = "Hello {$user['name']},\n\nYour password reset code is: {$code}\n\nThis code expires in 15 minutes.";

        $emailService->send($email, $subject, $html, $text);

        Security::logEvent('password_reset_requested_api', $user['id'], $email);

        $ok([
            'message' => 'A 6-digit verification code has been sent to your email.',
            'cooldown' => RESET_COOLDOWN,
            'expires_in' => RESET_TOKEN_EXPIRY
        ]);
    }

    if ($action === 'verify_code') {
        $code = trim($_POST['code'] ?? '');
        if (!preg_match('/^\d{6}$/', $code)) {
            $fail('A valid 6-digit code is required.');
        }

        $tokenHash = Security::hashToken($code);
        $stmt = $db->prepare(
            'SELECT id, expires_at, used_at FROM password_resets '
            . 'WHERE user_id = ? AND token_hash = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$user['id'], $tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $fail('Invalid verification code.', 401);
        }
        if ($reset['used_at'] !== null) {
            $fail('This code has already been used.', 409);
        }
        if (strtotime($reset['expires_at']) < time()) {
            $fail('This code has expired. Please request a new one.', 410);
        }

        // Issue short-lived reset token for the final step
        $resetToken = Security::generateToken(32);
        $resetTokenHash = Security::hashToken($resetToken);

        $stmt = $db->prepare('UPDATE password_resets SET reset_token_hash = ? WHERE id = ?');
        $stmt->execute([$resetTokenHash, $reset['id']]);

        $ok([
            'message' => 'Code verified. You may now reset your password.',
            'reset_id' => (int)$reset['id'],
            'reset_token' => $resetToken
        ]);
    }

    if ($action === 'reset_password') {
        $resetId = (int)($_POST['reset_id'] ?? 0);
        $resetToken = $_POST['reset_token'] ?? '';
        $newPassword = $_POST['newPassword'] ?? '';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        if ($resetId <= 0 || empty($resetToken)) {
            $fail('reset_id and reset_token are required.');
        }
        if (empty($newPassword) || empty($confirmPassword)) {
            $fail('All fields are required.');
        }
        if ($newPassword !== $confirmPassword) {
            $fail('Passwords do not match.');
        }

        $policyErrors = Security::validatePassword($newPassword);
        if (!empty($policyErrors)) {
            $fail(implode(' ', $policyErrors));
        }

        $resetTokenHash = Security::hashToken($resetToken);
        $stmt = $db->prepare(
            'SELECT id, expires_at, used_at FROM password_resets '
            . 'WHERE id = ? AND user_id = ? AND reset_token_hash = ? LIMIT 1'
        );
        $stmt->execute([$resetId, $user['id'], $resetTokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $fail('Invalid reset token.', 401);
        }
        if ($reset['used_at'] !== null) {
            $fail('This reset request was already used.', 409);
        }
        if (strtotime($reset['expires_at']) < time()) {
            $fail('This reset request has expired. Please start over.', 410);
        }

        // Update password
        $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

        // Mark used + clear token
        $stmt = $db->prepare('UPDATE password_resets SET used_at = NOW(), reset_token_hash = NULL WHERE id = ?');
        $stmt->execute([$resetId]);

        // Invalidate all other unused resets for this user
        $stmt = $db->prepare('UPDATE password_resets SET used_at = NOW(), reset_token_hash = NULL WHERE user_id = ? AND used_at IS NULL AND id != ?');
        $stmt->execute([$user['id'], $resetId]);

        // Confirmation email (best effort)
        try {
            $emailService = new EmailService();
            $emailService->sendPasswordResetConfirmation($email, '');
        } catch (Throwable $e) {
            // non-critical
        }

        Security::logEvent('password_reset_success_api', $user['id'], $email);

        $ok(['message' => 'Password updated successfully! You can now sign in.']);
    }
} catch (Throwable $e) {
    error_log('API password reset error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    exit();
}
