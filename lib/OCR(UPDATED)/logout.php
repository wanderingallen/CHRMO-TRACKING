<?php
// Prevent any output before headers
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Clear remember me cookie unless keep parameter is set
$keep = isset($_GET['keep']) && ($_GET['keep'] === '1' || $_GET['keep'] === 'true');
if (!$keep && isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, '/', '', false, true);
}

// Clear any output
ob_end_clean();

// Redirect to login page
header('Location: log-in.php?logged_out=true', true, 302);
exit();
