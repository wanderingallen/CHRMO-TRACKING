<?php
/**
 * Password Reset Page - Handles one-time reset links
 */

session_start();

require_once 'config.php';
require_once 'database.php';
require_once 'security.php';
require_once 'email.php';

$token = $_GET['token'] ?? '';
$errors = [];
$success = false;
$tokenValid = false;
$userId = null;
$userEmail = null;
$userName = null;

// Validate token on page load
if (!empty($token)) {
    $tokenHash = Security::hashToken($token);
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("
        SELECT pr.*, u.email, u.full_name
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ?
        LIMIT 1
    ");
    
    $stmt->execute([$tokenHash, time()]);
    $reset = $stmt->fetch();
    
    if ($reset) {
        $tokenValid = true;
        $userId = $reset['user_id'];
        $userEmail = $reset['email'];
        $userName = $reset['full_name'];
    } else {
        $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
        Security::logEvent('password_reset_invalid_token', null, null, ['token_hash' => substr($tokenHash, 0, 10)]);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate CSRF
    if (!Security::validateCSRFToken($csrfToken)) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    }
    
    // Validate passwords
    if (empty($newPassword)) {
        $errors[] = 'Please enter a new password.';
    }
    
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    
    // Check password strength
    $passwordErrors = Security::validatePassword($newPassword);
    if (!empty($passwordErrors)) {
        $errors = array_merge($errors, $passwordErrors);
    }
    
    // If no errors, update password
    if (empty($errors)) {
        $db = Database::getInstance()->getConnection();
        
        try {
            $db->beginTransaction();
            
            // Update user password
            $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([
                password_hash($newPassword, PASSWORD_DEFAULT),
                time(),
                $userId
            ]);
            
            // Mark token as used
            $tokenHash = Security::hashToken($token);
            $stmt = $db->prepare("UPDATE password_resets SET used_at = ? WHERE token_hash = ?");
            $stmt->execute([time(), $tokenHash]);
            
            $db->commit();
            
            // Send confirmation email
            $emailService = new EmailService();
            $emailService->sendPasswordResetConfirmation($userEmail, $userName);
            
            // Log success
            Security::logEvent('password_reset_success', $userId, $userEmail);
            
            $success = true;
            $tokenValid = false; // Prevent reuse
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'An error occurred while resetting your password. Please try again.';
            Security::logEvent('password_reset_error', $userId, $userEmail, ['error' => $e->getMessage()]);
        }
    }
}

// Generate CSRF token for form
$csrfToken = Security::generateCSRFToken();

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CHRMO Document Tracking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: { primary: { 400: '#38bdf8', 500: '#0ea5e9' } }
                }
            }
        }
    </script>
    <style>
        body { background: linear-gradient(135deg, #e0f7fa, #81d4fa); }
        .fade-in { animation: fadeIn 0.8s ease-in-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .floating-label-group { position: relative; margin-bottom: 1.5rem; }
        .floating-label {
            position: absolute; top: 14px; left: 15px; font-size: 1rem;
            color: #9ca3af; pointer-events: none; transition: all 0.2s ease-out;
            background-color: transparent; padding: 0 5px;
        }
        input:focus ~ .floating-label,
        input:not(:placeholder-shown) ~ .floating-label {
            top: -10px; left: 12px; font-size: 0.75rem;
            color: #0ea5e9; background-color: white; z-index: 10;
        }
        input:focus {
            outline: none; border-color: #0ea5e9;
            box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.2);
        }
        .password-toggle {
            position: absolute; right: 16px; top: 14px;
            cursor: pointer; color: #64748b;
        }
        .strength-meter {
            height: 4px; background: #e5e7eb; border-radius: 2px;
            margin-top: 8px; overflow: hidden;
        }
        .strength-bar {
            height: 100%; transition: width 0.3s, background-color 0.3s;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-6">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden fade-in">
        <?php if ($success): ?>
            <!-- Success State -->
            <div class="bg-gradient-to-r from-green-400 to-green-500 text-white p-8 text-center">
                <div class="text-6xl mb-4"><i class="fas fa-check-circle"></i></div>
                <h1 class="text-2xl font-bold mb-2">Password Reset Successful!</h1>
                <p class="text-green-100">Your password has been updated.</p>
            </div>
            <div class="p-8 text-center">
                <p class="text-gray-600 mb-6">You can now log in with your new password.</p>
                <a href="log-in.php" class="inline-block bg-primary-400 hover:bg-primary-500 text-white py-3 px-8 rounded-xl font-medium transition-all">
                    Go to Login
                </a>
            </div>
        <?php elseif (!$tokenValid): ?>
            <!-- Invalid/Expired Token -->
            <div class="bg-gradient-to-r from-red-400 to-red-500 text-white p-8 text-center">
                <div class="text-6xl mb-4"><i class="fas fa-exclamation-triangle"></i></div>
                <h1 class="text-2xl font-bold mb-2">Invalid or Expired Link</h1>
            </div>
            <div class="p-8">
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-6">
                        <?php foreach ($errors as $error): ?>
                            <p class="text-sm"><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="text-gray-600 mb-6 text-center">This password reset link is no longer valid. Please request a new one.</p>
                <a href="log-in.php" class="block w-full text-center bg-primary-400 hover:bg-primary-500 text-white py-3 rounded-xl font-medium transition-all">
                    Back to Login
                </a>
            </div>
        <?php else: ?>
            <!-- Reset Form -->
            <div class="bg-gradient-to-r from-primary-400 to-primary-500 text-white p-8 text-center">
                <div class="text-5xl mb-4"><i class="fas fa-key"></i></div>
                <h1 class="text-2xl font-bold mb-2">Reset Your Password</h1>
                <p class="text-primary-100">Enter your new password below</p>
            </div>
            <div class="p-8">
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-6">
                        <ul class="list-disc list-inside text-sm">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    
                    <div class="floating-label-group">
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            placeholder=" "
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl text-gray-900 pr-10"
                            required
                            autocomplete="new-password"
                        />
                        <label for="new_password" class="floating-label">New Password</label>
                        <span class="password-toggle" onclick="togglePassword('new_password')">
                            <i class="far fa-eye-slash"></i>
                        </span>
                        <div class="strength-meter">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2" id="strengthText">Password strength: <span id="strengthLabel">-</span></p>
                    </div>

                    <div class="floating-label-group">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder=" "
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl text-gray-900 pr-10"
                            required
                            autocomplete="new-password"
                        />
                        <label for="confirm_password" class="floating-label">Confirm Password</label>
                        <span class="password-toggle" onclick="togglePassword('confirm_password')">
                            <i class="far fa-eye-slash"></i>
                        </span>
                    </div>

                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
                        <p class="text-sm text-blue-800 font-medium mb-2">Password Requirements:</p>
                        <ul class="text-xs text-blue-700 space-y-1">
                            <li>• At least <?php echo MIN_PASSWORD_LENGTH; ?> characters long</li>
                            <?php if (REQUIRE_UPPERCASE): ?><li>• Contains uppercase letter (A-Z)</li><?php endif; ?>
                            <?php if (REQUIRE_LOWERCASE): ?><li>• Contains lowercase letter (a-z)</li><?php endif; ?>
                            <?php if (REQUIRE_NUMBER): ?><li>• Contains number (0-9)</li><?php endif; ?>
                            <?php if (REQUIRE_SPECIAL): ?><li>• Contains special character (!@#$%^&*)</li><?php endif; ?>
                        </ul>
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-primary-400 hover:bg-primary-500 text-white py-3 rounded-xl font-medium transition-all transform hover:translate-y-[-1px] hover:shadow-md"
                    >
                        Reset Password
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.parentElement.querySelector('.password-toggle i');
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        }

        // Password strength meter
        document.getElementById('new_password')?.addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthLabel = document.getElementById('strengthLabel');
            
            let strength = 0;
            if (password.length >= <?php echo MIN_PASSWORD_LENGTH; ?>) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            const colors = ['#ef4444', '#f59e0b', '#eab308', '#84cc16', '#22c55e'];
            const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            const widths = ['20%', '40%', '60%', '80%', '100%'];
            
            strengthBar.style.width = widths[strength - 1] || '0%';
            strengthBar.style.backgroundColor = colors[strength - 1] || '#e5e7eb';
            strengthLabel.textContent = labels[strength - 1] || '-';
            strengthLabel.style.color = colors[strength - 1] || '#6b7280';
        });

        // Confirm password validation
        document.getElementById('confirm_password')?.addEventListener('input', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = e.target.value;
            
            if (confirmPass && newPass !== confirmPass) {
                e.target.setCustomValidity('Passwords do not match');
            } else {
                e.target.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
