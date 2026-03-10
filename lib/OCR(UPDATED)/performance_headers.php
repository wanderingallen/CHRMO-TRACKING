<?php
/**
 * CHRMO Document Tracking - Performance Headers
 * Include this file at the start of PHP pages for optimal performance
 */

// Prevent output buffering issues
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}

// Set cache headers for static assets (when serving via PHP)
function setCacheHeaders($maxAge = 3600) {
    if (!headers_sent()) {
        header('Cache-Control: public, max-age=' . $maxAge);
        header('Vary: Accept-Encoding');
    }
}

// Set no-cache headers for dynamic content
function setNoCacheHeaders() {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
}

// Optimize database connection with persistent connection
function getOptimizedConnection() {
    static $connection = null;
    
    if ($connection === null) {
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "document_tracking_db";
        
        // Use persistent connection for better performance
        $connection = new mysqli('p:' . $servername, $username, $password, $dbname);
        
        if ($connection->connect_error) {
            // Fallback to non-persistent
            $connection = new mysqli($servername, $username, $password, $dbname);
        }
        
        if (!$connection->connect_error) {
            // Set charset for security and performance
            $connection->set_charset('utf8mb4');
            
            // Enable query buffering
            $connection->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        }
    }
    
    return $connection;
}

// Preconnect hints for external resources
function outputPreconnectHints() {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="preconnect" href="https://cdnjs.cloudflare.com">';
    echo '<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">';
}

// Performance-optimized asset loading
function outputAssetPreloads() {
    echo '<link rel="preload" href="assets/animations.css" as="style">';
    echo '<link rel="preload" href="assets/smooth-interactions.js" as="script">';
}

// Minify inline CSS (simple minification)
function minifyCSS($css) {
    // Remove comments
    $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
    // Remove whitespace
    $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
    // Remove extra spaces
    $css = preg_replace('/\s+/', ' ', $css);
    $css = preg_replace('/\s*([{};:,>+~])\s*/', '$1', $css);
    return trim($css);
}

// Debounced session writes for better performance
function commitSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
?>
