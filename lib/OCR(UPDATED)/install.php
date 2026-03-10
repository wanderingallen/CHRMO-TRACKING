<?php
/**
 * Installation and Setup Script
 * Run this once to set up the database and migrate from JSON
 */

require_once 'config.php';
require_once 'database.php';

$messages = [];
$errors = [];

// Create logs directory
$logDir = dirname(LOG_FILE);
if (!is_dir($logDir)) {
    if (@mkdir($logDir, 0755, true)) {
        $messages[] = "✓ Created logs directory: $logDir";
    } else {
        $errors[] = "✗ Failed to create logs directory: $logDir";
    }
} else {
    $messages[] = "✓ Logs directory already exists";
}

// Initialize database
try {
    $db = Database::getInstance();
    $messages[] = "✓ Database connection established";
    
    // Migrate from JSON if exists
    $jsonFile = __DIR__ . '/users.json';
    if (file_exists($jsonFile)) {
        if ($db->migrateFromJSON($jsonFile)) {
            $messages[] = "✓ Migrated users from users.json to database";
            
            // Optionally backup the JSON file
            $backupFile = $jsonFile . '.backup_' . date('Y-m-d_His');
            if (@copy($jsonFile, $backupFile)) {
                $messages[] = "✓ Backed up users.json to: " . basename($backupFile);
            }
        } else {
            $errors[] = "✗ Failed to migrate users from JSON";
        }
    } else {
        $messages[] = "ℹ No users.json file found (skipping migration)";
    }
    
    // Test database tables
    $conn = $db->getConnection();
    $tables = ['users', 'password_resets', 'rate_limits', 'security_logs'];
    foreach ($tables as $table) {
        $stmt = $conn->query("SELECT COUNT(*) as count FROM $table");
        $result = $stmt->fetch();
        $messages[] = "✓ Table '$table' exists ({$result['count']} records)";
    }
    
} catch (Exception $e) {
    $errors[] = "✗ Database error: " . $e->getMessage();
}

// Check configuration
$configChecks = [
    'SECRET_KEY' => (SECRET_KEY !== 'change_this_to_a_random_64_character_string_in_production_abc123'),
    'SMTP configured' => (!empty(SMTP_USERNAME) && SMTP_USERNAME !== 'your-email@gmail.com'),
    'App URL set' => (APP_URL !== 'http://localhost/flutter_application_7/lib/OCR(UPDATED)'),
];

foreach ($configChecks as $check => $passed) {
    if ($passed) {
        $messages[] = "✓ $check";
    } else {
        $errors[] = "⚠ $check - Please update config.php";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - CHRMO Document Tracking</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-3xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6">
                <h1 class="text-3xl font-bold flex items-center gap-3">
                    <i class="fas fa-cog"></i>
                    Installation & Setup
                </h1>
                <p class="text-blue-100 mt-2">CHRMO Document Tracking System</p>
            </div>
            
            <div class="p-6">
                <?php if (!empty($messages)): ?>
                    <div class="mb-6">
                        <h2 class="text-xl font-semibold mb-3 text-green-700">
                            <i class="fas fa-check-circle"></i> Success Messages
                        </h2>
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                            <?php foreach ($messages as $msg): ?>
                                <div class="flex items-start gap-2 mb-2 last:mb-0">
                                    <span class="text-green-600"><?php echo htmlspecialchars($msg); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="mb-6">
                        <h2 class="text-xl font-semibold mb-3 text-red-700">
                            <i class="fas fa-exclamation-triangle"></i> Warnings & Errors
                        </h2>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <?php foreach ($errors as $err): ?>
                                <div class="flex items-start gap-2 mb-2 last:mb-0">
                                    <span class="text-red-600"><?php echo htmlspecialchars($err); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <h3 class="font-semibold text-blue-900 mb-2">
                        <i class="fas fa-info-circle"></i> Next Steps
                    </h3>
                    <ol class="list-decimal list-inside space-y-2 text-blue-800 text-sm">
                        <li>Update <code class="bg-blue-100 px-2 py-1 rounded">config.php</code> with your production settings</li>
                        <li>Configure SMTP credentials for email delivery</li>
                        <li>Change SECRET_KEY to a random 64-character string</li>
                        <li>Set ENVIRONMENT to 'production' when deploying</li>
                        <li>Test the forgot password flow at <a href="log-in.php" class="underline">log-in.php</a></li>
                        <li>Delete or restrict access to this install.php file</li>
                    </ol>
                </div>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <h3 class="font-semibold text-yellow-900 mb-2">
                        <i class="fas fa-shield-alt"></i> Security Checklist
                    </h3>
                    <ul class="space-y-1 text-yellow-800 text-sm">
                        <li>✓ CSRF protection enabled</li>
                        <li>✓ Rate limiting configured (<?php echo MAX_RESET_ATTEMPTS; ?> attempts per <?php echo RATE_LIMIT_WINDOW/60; ?> min)</li>
                        <li>✓ Password reset cooldown (<?php echo RESET_COOLDOWN; ?>s between requests)</li>
                        <li>✓ Token expiry (<?php echo RESET_TOKEN_EXPIRY/60; ?> minutes)</li>
                        <li>✓ Security logging enabled</li>
                        <li>✓ Password strength validation</li>
                    </ul>
                </div>
                
                <div class="flex gap-4">
                    <a href="log-in.php" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white text-center py-3 px-6 rounded-lg font-medium transition-colors">
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Go to Login Page
                    </a>
                    <a href="reset-password.php?token=test" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white text-center py-3 px-6 rounded-lg font-medium transition-colors">
                        <i class="fas fa-key mr-2"></i>
                        Test Reset Page
                    </a>
                </div>
                
                <div class="mt-6 text-center text-sm text-gray-500">
                    <p>Environment: <strong class="text-gray-700"><?php echo ENVIRONMENT; ?></strong></p>
                    <p>Database: <strong class="text-gray-700"><?php echo class_exists('PDO') ? 'SQLite/MySQL' : 'Not available'; ?></strong></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
