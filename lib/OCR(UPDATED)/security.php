<?php
/**
 * Security utilities: CSRF, rate limiting, logging, password validation
 */

require_once 'config.php';
require_once 'database.php';

class Security {
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
            $_SESSION[CSRF_TOKEN_NAME . '_time'] = time();
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    /**
     * Validate CSRF token
     */
    public static function validateCSRFToken($token) {
        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            return false;
        }

        // Check expiry
        if (time() - ($_SESSION[CSRF_TOKEN_NAME . '_time'] ?? 0) > CSRF_TOKEN_EXPIRY) {
            unset($_SESSION[CSRF_TOKEN_NAME]);
            unset($_SESSION[CSRF_TOKEN_NAME . '_time']);
            return false;
        }

        return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }

    /**
     * Generate secure random token
     */
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }

    /**
     * Hash token for storage
     */
    public static function hashToken($token) {
        return hash('sha256', $token);
    }

    /**
     * Check rate limit
     */
    public static function checkRateLimit($identifier, $action, $maxAttempts = RATE_LIMIT_MAX_ATTEMPTS, $window = RATE_LIMIT_WINDOW) {
        $db = Database::getInstance()->getConnection();
        
        // Clean old entries
        $cutoff = time() - $window;
        $db->prepare("DELETE FROM rate_limits WHERE window_start < FROM_UNIXTIME(?)")->execute([$cutoff]);

        // Check current attempts
        $stmt = $db->prepare("
            SELECT SUM(attempts) as total
            FROM rate_limits
            WHERE identifier = ? AND action = ? AND window_start >= ?
        ");
        $stmt->execute([$identifier, $action, date('Y-m-d H:i:s', $cutoff)]);
        $result = $stmt->fetch();
        $total = $result['total'] ?? 0;

        if ($total >= $maxAttempts) {
            self::logEvent('rate_limit_exceeded', null, null, [
                'identifier' => $identifier,
                'action' => $action,
                'attempts' => $total
            ]);
            return false;
        }

        // Record this attempt
        $stmt = $db->prepare("
            INSERT INTO rate_limits (identifier, action, window_start)
            VALUES (?, ?, FROM_UNIXTIME(?))
        ");
        $stmt->execute([$identifier, $action, time()]);

        return true;
    }

    /**
     * Validate password strength
     */
    public static function validatePassword($password) {
        $errors = [];

        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = "Password must be at least " . MIN_PASSWORD_LENGTH . " characters long.";
        }

        if (REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter.";
        }

        if (REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter.";
        }

        if (REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number.";
        }

        if (REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character.";
        }

        return $errors;
    }

    /**
     * Log security event
     */
    public static function logEvent($eventType, $userId = null, $email = null, $details = []) {
        if (!ENABLE_LOGGING) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO security_logs (event_type, user_id, email, ip_address, user_agent, details)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $eventType,
            $userId,
            $email,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            json_encode($details)
        ]);

        // Also log to file
        if (defined('LOG_FILE')) {
            $logDir = dirname(LOG_FILE);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            
            $logMessage = sprintf(
                "[%s] %s | User: %s | Email: %s | IP: %s | Details: %s\n",
                date('Y-m-d H:i:s'),
                $eventType,
                $userId ?? 'N/A',
                $email ?? 'N/A',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                json_encode($details)
            );
            
            @file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
        }
    }

    /**
     * Sanitize input
     */
    public static function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get client IP (handles proxies)
     */
    public static function getClientIP() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /**
     * Verify reCAPTCHA token
     */
    public static function verifyRecaptcha($token) {
        if (!ENABLE_RECAPTCHA || empty(RECAPTCHA_SECRET_KEY)) {
            return true; // Skip if not configured
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => self::getClientIP()
        ];

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            return false;
        }

        $response = json_decode($result, true);
        return isset($response['success']) && $response['success'] === true;
    }

    public static function require_login() {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
            header('Location: log-in.php');
            exit();
        }
    }

    public static function require_role($roles) {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $role = strtolower(trim((string)($_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user'))));
        $allowed = is_array($roles) ? $roles : [$roles];
        $allowed = array_map(function($r){ return strtolower(trim((string)$r)); }, $allowed);

        if (!in_array($role, $allowed, true)) {
            // Fallback path: allow admins by email or allow when roles not yet configured
            if (defined('ALLOW_ADMIN_FALLBACK') && ALLOW_ADMIN_FALLBACK && ($_SESSION['loggedin'] ?? false)) {
                $email = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
                $list = array_filter(array_map('trim', explode(',', (defined('ADMIN_EMAILS') ? ADMIN_EMAILS : ''))));
                $list = array_map('strtolower', $list);
                if (empty($list) || ($email && in_array($email, $list, true))) {
                    return; // allow under fallback
                }
            }
            http_response_code(403);
            exit('Forbidden');
        }
    }

    /**
     * Get the current user's role (normalized to lowercase).
     */
    public static function get_role() {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        return strtolower(trim((string)($_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user'))));
    }

    /**
     * Check if the current user has an admin-level role.
     */
    public static function is_admin() {
        $role = self::get_role();
        return in_array($role, ['admin', 'administrator', 'superadmin', 'super_admin'], true);
    }

    /**
     * Block access for non-admin users. Redirects department_user to dashboard.
     */
    public static function require_admin() {
        self::require_login();
        if (!self::is_admin()) {
            header('Location: dashboard.php');
            exit();
        }
    }
}
