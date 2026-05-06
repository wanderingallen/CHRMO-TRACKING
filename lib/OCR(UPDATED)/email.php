<?php
/**
 * Email sending functionality with PHPMailer support
 * Falls back to mail() if PHPMailer is not available
 */

require_once 'config.php';

class EmailService {
    private $usePHPMailer = false;
    private $mailer = null;
    private $lastError = '';

    public function __construct() {
        // Try to load PHPMailer if available
        if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
            if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                $this->usePHPMailer = true;
                error_log("PHPMailer class found, initializing...");
                $this->initPHPMailer();
            } else {
                error_log("PHPMailer class not found after autoload");
            }
        } else {
            error_log("Composer autoload.php not found at: " . __DIR__ . '/../../vendor/autoload.php');
        }
    }

    public function lastError() {
        return $this->lastError;
    }

    private function initPHPMailer() {
        $this->mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = defined('SMTP_AUTH') ? (bool)SMTP_AUTH : true;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            // Encryption from config: 'tls' => STARTTLS (587), 'ssl' => SMTPS (465)
            if (defined('SMTP_ENCRYPTION') && strtolower((string)SMTP_ENCRYPTION) === 'ssl') {
                $this->mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $this->mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $this->mailer->Port = SMTP_PORT;
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->isHTML(true);
            
            // Relax TLS verification in development on localhost only (avoid in production)
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                $this->mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }
            
            // Default sender
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            error_log("PHPMailer initialized successfully with SMTP settings: " . SMTP_HOST . ":" . SMTP_PORT);
        } catch (Exception $e) {
            error_log("PHPMailer initialization failed: " . $e->getMessage());
            $this->lastError = $e->getMessage();
            $this->usePHPMailer = false;
        }
    }

    /**
     * Send password reset email with one-time link
     */
    public function sendPasswordResetLink($email, $fullName, $resetToken) {
        $resetLink = APP_URL . '/reset-password.php?token=' . urlencode($resetToken);
        
        $subject = 'Password Reset Request - ' . APP_NAME;
        
        $htmlBody = $this->getResetEmailTemplate($fullName, $resetLink);
        $textBody = $this->getResetEmailTextTemplate($fullName, $resetLink);

        return $this->send($email, $subject, $htmlBody, $textBody);
    }

    /**
     * Send password reset confirmation email
     */
    public function sendPasswordResetConfirmation($email, $fullName) {
        $subject = 'Password Successfully Reset - ' . APP_NAME;
        
        $htmlBody = $this->getResetConfirmationTemplate($fullName);
        $textBody = "Hello $fullName,\n\nYour password has been successfully reset. If you did not make this change, please contact support immediately.\n\n" . APP_NAME;

        return $this->send($email, $subject, $htmlBody, $textBody);
    }

    /**
     * Generic send method
     */
    public function send($to, $subject, $htmlBody, $textBody = '') {
        $this->lastError = '';
        error_log("EmailService::send called for $to with subject: $subject");
        error_log("Using PHPMailer: " . ($this->usePHPMailer ? 'Yes' : 'No'));
        
        if ($this->usePHPMailer && $this->mailer) {
            error_log("Attempting to send via PHPMailer");
            return $this->sendWithPHPMailer($to, $subject, $htmlBody, $textBody);
        } else {
            error_log("Attempting to send via PHP mail() function");
            return $this->sendWithMailFunction($to, $subject, $htmlBody);
        }
    }

    private function sendWithPHPMailer($to, $subject, $htmlBody, $textBody) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = $textBody ?: strip_tags($htmlBody);
            $this->mailer->isHTML(true);
            
            $result = $this->mailer->send();
            
            error_log("PHPMailer email sent to $to: $subject (Result: " . ($result ? 'success' : 'failed') . ")");
            
            return $result;
        } catch (Exception $e) {
            error_log("PHPMailer send failed to $to: " . $e->getMessage());
            error_log("PHPMailer Error Info: " . $this->mailer->ErrorInfo);
            $this->lastError = $this->mailer->ErrorInfo ?: $e->getMessage();
            return false;
        }
    }

    private function sendWithMailFunction($to, $subject, $htmlBody) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        
        $result = @mail($to, $subject, $htmlBody, $headers);

        error_log("Email attempted to $to: $subject (Result: " . ($result ? 'success' : 'failed') . ")");
        if (!$result) {
            $this->lastError = 'mail() failed';
        }
        return $result;
    }

    private function getResetEmailTemplate($fullName, $resetLink) {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #38bdf8, #0ea5e9); padding: 40px 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: 700;">Password Reset Request</h1>
                            <p style="color: #e0f2fe; margin: 10px 0 0 0; font-size: 14px;">{$_SERVER['HTTP_HOST']}</p>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="color: #1f2937; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">Hello <strong>$fullName</strong>,</p>
                            <p style="color: #4b5563; font-size: 15px; line-height: 1.6; margin: 0 0 20px 0;">
                                We received a request to reset your password for your <strong>CHRMO Document Tracking</strong> account. Click the button below to create a new password:
                            </p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="$resetLink" style="display: inline-block; background: linear-gradient(135deg, #38bdf8, #0ea5e9); color: #ffffff; text-decoration: none; padding: 16px 40px; border-radius: 8px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 6px rgba(14, 165, 233, 0.3);">Reset Password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="color: #6b7280; font-size: 14px; line-height: 1.6; margin: 20px 0;">
                                <strong>This link will expire in 15 minutes</strong> for security reasons. If you didn't request this password reset, you can safely ignore this email.
                            </p>
                            <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px;">
                                <p style="color: #92400e; font-size: 13px; margin: 0; line-height: 1.5;">
                                    <strong>Security tip:</strong> Never share this link with anyone. Our team will never ask for your password.
                                </p>
                            </div>
                            <p style="color: #9ca3af; font-size: 13px; line-height: 1.6; margin: 20px 0 0 0;">
                                If the button doesn't work, copy and paste this link into your browser:<br>
                                <a href="$resetLink" style="color: #0ea5e9; word-break: break-all;">$resetLink</a>
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="color: #6b7280; font-size: 12px; margin: 0 0 10px 0;">
                                Request from IP: {$_SERVER['REMOTE_ADDR']}<br>
                                Time: {date('Y-m-d H:i:s T')}
                            </p>
                            <p style="color: #9ca3af; font-size: 11px; margin: 10px 0 0 0;">
                                © 2025 CHRMO Document Tracking System. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private function getResetEmailTextTemplate($fullName, $resetLink) {
        return <<<TEXT
Hello $fullName,

We received a request to reset your password for your CHRMO Document Tracking account.

To reset your password, visit this link:
$resetLink

This link will expire in 15 minutes for security reasons.

If you didn't request this password reset, you can safely ignore this email.

Request from IP: {$_SERVER['REMOTE_ADDR']}
Time: {date('Y-m-d H:i:s T')}

© 2025 CHRMO Document Tracking System
TEXT;
    }

    private function getResetConfirmationTemplate($fullName) {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Confirmation</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background: linear-gradient(135deg, #10b981, #059669); padding: 40px 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: 700;">✓ Password Reset Successful</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 30px;">
                            <p style="color: #1f2937; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">Hello <strong>$fullName</strong>,</p>
                            <p style="color: #4b5563; font-size: 15px; line-height: 1.6; margin: 0 0 20px 0;">
                                Your password has been successfully reset. You can now log in with your new password.
                            </p>
                            <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 15px; margin: 20px 0; border-radius: 4px;">
                                <p style="color: #991b1b; font-size: 13px; margin: 0; line-height: 1.5;">
                                    <strong>Didn't make this change?</strong> Please contact our support team immediately.
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="color: #9ca3af; font-size: 11px; margin: 0;">
                                © 2025 CHRMO Document Tracking System
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
