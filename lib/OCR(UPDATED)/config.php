<?php
/**
 * Configuration file for CHRMO Document Tracking System
 * DO NOT commit this file with real credentials to version control
 */

// Environment (development or production)
define('ENVIRONMENT', 'development'); // Change to 'production' for live deployment

// Database Configuration (use the schema from chrmo_db.sql)
define('DB_HOST', 'localhost');
define('DB_NAME', 'chrmo_db');
define('DB_USER', 'root');
define('DB_PASS', '');
// Optional: set this if MySQL runs on a non-default port (e.g., 3307)
if (!defined('DB_PORT')) {
    define('DB_PORT', 3306);
}
define('DB_CHARSET', 'utf8mb4');

// Security Keys (CHANGE THESE IN PRODUCTION!)
define('SECRET_KEY', 'change_this_to_a_random_64_character_string_in_production_abc123');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour

// File Encryption (set secure key in production; 64-hex preferred)
// If FILE_ENC_KEY is 64-hex, it will be used as-is; otherwise a SHA-256 of the value is used
define('FILE_ENC_KEY', '0000000000000000000000000000000000000000000000000000000000000000');
define('FILE_ENC_ALGO', 'aes-256-gcm');

// RBAC fallback: allow admin pages if role not set (set to false in production once roles are configured)
define('ALLOW_ADMIN_FALLBACK', true);
// Comma-separated list of admin emails allowed during fallback (optional)
define('ADMIN_EMAILS', '');

// Email Configuration (for production)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);               // 587 = STARTTLS, 465 = SMTPS
define('SMTP_USERNAME', 'saquinalemcris@gmail.com');
define('SMTP_PASSWORD', 'qcrs cdvs civb bgrf'); // Gmail App Password
define('SMTP_FROM_EMAIL', 'saquinalemcris16@gmail.com'); // Use Gmail to avoid SPF issues
define('SMTP_FROM_NAME', 'CHRMO Document Tracking');
define('SMTP_ENCRYPTION', 'tls');       // 'tls' for STARTTLS, 'ssl' for port 465
define('SMTP_AUTH', true);              // keep true for Gmail

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
