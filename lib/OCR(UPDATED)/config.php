<?php
/**
 * Configuration file for CHRMO Document Tracking System
 * 
 * For production: create a .env file in this directory with your real values.
 * See .env.example for the template.
 * DO NOT commit this file or .env with real credentials to version control.
 */

// ──── Load .env overrides (if present) ────
$__envFile = __DIR__ . '/.env';
if (file_exists($__envFile)) {
    $__envLines = file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($__envLines as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#') continue;
        if (strpos($__line, '=') === false) continue;
        list($__key, $__val) = array_map('trim', explode('=', $__line, 2));
        // Strip surrounding quotes
        if (strlen($__val) >= 2 && (($__val[0] === '"' && substr($__val, -1) === '"') || ($__val[0] === "'" && substr($__val, -1) === "'"))) {
            $__val = substr($__val, 1, -1);
        }
        $_ENV[$__key] = $__val;
    }
    unset($__envLines, $__line, $__key, $__val);
}
unset($__envFile);

// Helper: read from $_ENV or fall back to a default
function __env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? $default;
}

// Environment (development or production)
define('ENVIRONMENT', __env('ENVIRONMENT', 'production'));

// Database Configuration
define('DB_HOST', __env('DB_HOST', 'localhost'));
define('DB_NAME', __env('DB_NAME', 'chrmo_db'));
define('DB_USER', __env('DB_USER', 'root'));
define('DB_PASS', __env('DB_PASS', ''));
if (!defined('DB_PORT')) {
    define('DB_PORT', (int)__env('DB_PORT', '3306'));
}
define('DB_CHARSET', 'utf8mb4');

// Security Keys — MUST be overridden via .env in production
define('SECRET_KEY', __env('SECRET_KEY', 'change_this_to_a_random_64_character_string_in_production_abc123'));
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour

// File Encryption (set secure 64-hex key via .env in production)
define('FILE_ENC_KEY', __env('FILE_ENC_KEY', '0000000000000000000000000000000000000000000000000000000000000000'));
define('FILE_ENC_ALGO', 'aes-256-gcm');

// RBAC fallback (false in production — roles must be properly assigned)
define('ALLOW_ADMIN_FALLBACK', __env('ALLOW_ADMIN_FALLBACK', 'false') === 'true');
define('ADMIN_EMAILS', __env('ADMIN_EMAILS', ''));

// Email / SMTP Configuration
define('SMTP_HOST', __env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int)__env('SMTP_PORT', '587'));
define('SMTP_USERNAME', __env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', __env('SMTP_PASSWORD', ''));
define('SMTP_FROM_EMAIL', __env('SMTP_FROM_EMAIL', ''));
define('SMTP_FROM_NAME', __env('SMTP_FROM_NAME', 'CHRMO Document Tracking'));
define('SMTP_ENCRYPTION', __env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_AUTH', true);

// Application URLs
// Derive base URL from the current request host to avoid hard-coded LAN IPs.
// Falls back to localhost when running via CLI.
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', $__scheme . '://' . $__host . '/flutter_application_7/lib/OCR(UPDATED)');
define('APP_NAME', 'CHRMO Document Tracking System');

// Password Reset Settings
define('RESET_TOKEN_EXPIRY', 900); // 15 minutes in seconds
define('RESET_COOLDOWN', 120); // 2 minutes between requests
define('MAX_RESET_ATTEMPTS', 3); // Max attempts per 15 minutes

// Rate Limiting
define('RATE_LIMIT_WINDOW', 900); // 15 minutes
define('RATE_LIMIT_MAX_ATTEMPTS', 5);

// Session Configuration (must be set BEFORE session_start)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
}

// Password Policy
define('MIN_PASSWORD_LENGTH', 8);
define('REQUIRE_UPPERCASE', true);
define('REQUIRE_LOWERCASE', true);
define('REQUIRE_NUMBER', true);
define('REQUIRE_SPECIAL', true);

// Logging
define('LOG_FILE', __DIR__ . '/logs/security.log');
define('ENABLE_LOGGING', true);

// reCAPTCHA (optional - get keys from https://www.google.com/recaptcha/admin)
define('RECAPTCHA_SITE_KEY', ''); // Public key
define('RECAPTCHA_SECRET_KEY', ''); // Secret key
define('ENABLE_RECAPTCHA', false); // Set to true when keys are configured

// Development settings
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
