<?php
// Use SMTP settings from config.php
require_once __DIR__ . '/config.php';

// Sender defaults to your SMTP_FROM_* constants
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@example.com');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'CHRMO'));
}

// Track last SMTP error for diagnostics
static $SMTP_LAST_ERROR = '';

function smtp_last_error() {
    global $SMTP_LAST_ERROR; return $SMTP_LAST_ERROR;
}

// Basic SMTP client supporting SMTPS:465 and STARTTLS:587
function sendMailSMTPNative($to, $subject, $htmlBody) {
    $host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $user = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
    // Gmail app passwords are shown with spaces — remove any whitespace
    $pass = defined('SMTP_PASSWORD') ? preg_replace('/\s+/', '', SMTP_PASSWORD) : '';
    $enc  = strtolower(defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls');

    $fromEmail = MAIL_FROM_EMAIL;
    $fromName  = MAIL_FROM_NAME;
    // Gmail commonly rejects mismatched MAIL FROM when authenticating as another account.
    if (stripos($host, 'gmail.com') !== false && !empty($user)) {
        $fromEmail = $user;
    }

    $boundary = 'bnd_' . bin2hex(random_bytes(8));
    $headers  = '';
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $message = $htmlBody;

    $timeout = 20;

    // Primary endpoint from configured encryption/port
    $endpoints = [];
    if ($enc === 'ssl' || $port === 465) {
        $endpoints[] = 'ssl://' . $host . ':' . $port;
    } else {
        $endpoints[] = $host . ':' . $port;
    }
    // Fallback STARTTLS endpoint if primary is different
    if ($port !== 587) {
        $endpoints[] = $host . ':587';
    }

    global $SMTP_LAST_ERROR; $lastErr = '';
    foreach ($endpoints as $ep) {
        $fp = @stream_socket_client($ep, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) { $lastErr = "$ep connect error: $errstr"; continue; }
        stream_set_timeout($fp, $timeout);

        $recv = function() use ($fp) { return fgets($fp, 515); };
        $send = function($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };

        $banner = $recv(); if (strpos($banner, '220') !== 0) { fclose($fp); $lastErr = 'Bad banner: ' . trim($banner); continue; }
        $send('EHLO localhost');
        $ehlo = '';
        for ($i=0;$i<10;$i++){ $line = $recv(); if ($line === false) break; $ehlo .= $line; if (preg_match('/^\d{3} /',$line)) break; }
        if (strpos($ehlo, '250') !== 0) { fclose($fp); $lastErr = 'EHLO failed'; continue; }

        // STARTTLS if plain 587
        if (strpos($ep, 'ssl://') === false) {
            $send('STARTTLS');
            $resp = $recv();
            if (strpos($resp, '220') !== 0) { fclose($fp); $lastErr = 'STARTTLS failed: ' . trim($resp); continue; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); $lastErr = 'TLS enable failed'; continue; }
            $send('EHLO localhost');
            // Must drain ALL multi-line EHLO responses (250-xxx ... 250 xxx)
            $ehlo2 = '';
            for ($j=0;$j<20;$j++){ $ln = $recv(); if ($ln === false) break; $ehlo2 .= $ln; if (preg_match('/^\d{3} /',$ln)) break; }
            if (strpos($ehlo2, '250') !== 0) { /* continue anyway */ }
        }

        // AUTH LOGIN
        $send('AUTH LOGIN'); if (strpos($recv(), '334') !== 0) { fclose($fp); $lastErr = 'AUTH LOGIN not accepted'; continue; }
        $send(base64_encode($user)); if (strpos($recv(), '334') !== 0) { fclose($fp); $lastErr = 'Username not accepted'; continue; }
        $send(base64_encode($pass)); $authResp = $recv(); if (strpos($authResp, '235') !== 0) { fclose($fp); $lastErr = 'Password not accepted'; continue; }

        // MAIL TRANSACTION
        $send('MAIL FROM: <' . $fromEmail . '>'); if (strpos($recv(), '250') !== 0) { fclose($fp); $lastErr = 'MAIL FROM failed'; continue; }
        $send('RCPT TO: <' . $to . '>');
        $rcptResp = $recv();
        if (strpos($rcptResp, '250') !== 0 && strpos($rcptResp, '251') !== 0) { fclose($fp); $lastErr = 'RCPT TO failed: ' . trim((string)$rcptResp); continue; }
        $send('DATA'); if (strpos($recv(), '354') !== 0) { fclose($fp); $lastErr = 'DATA not accepted'; continue; }

        $data  = 'To: <' . $to . ">\r\n";
        $data .= 'Subject: ' . $subject . "\r\n";
        $data .= $headers;
        $data .= "\r\n" . $message . "\r\n";
        $data .= ".\r\n";
        fwrite($fp, $data);
        $final = $recv();
        $send('QUIT');
        fclose($fp);
        if (strpos($final, '250') === 0) { $SMTP_LAST_ERROR = ''; return true; }
        $lastErr = 'Final response: ' . trim($final);
    }
    if (!empty($lastErr)) { $SMTP_LAST_ERROR = $lastErr; error_log('[SMTP] ' . $lastErr); }
    return false;
}

// Public API used by the app
function sendMail($to, $subject, $message, $headers = '') {
    // Prefer SMTP; fall back to mail()
    $ok = sendMailSMTPNative($to, $subject, $message);
    if ($ok) return true;

    if (empty($headers)) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . ">\r\n";
    }
    return @mail($to, $subject, $message, $headers);
}
?>
