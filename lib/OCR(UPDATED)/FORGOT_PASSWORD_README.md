# Production-Ready Forgot Password System

## 🎯 Overview

This is a comprehensive, capstone-ready password reset system with enterprise-grade security features.

## ✅ Features Implemented

### 1. **One-Time Reset Links** ✓

- Secure token generation (64 characters, cryptographically random)
- Hashed tokens stored in database (SHA-256)
- 15-minute expiration
- Single-use tokens (marked as used after reset)
- Email delivery with branded HTML template

### 2. **Database Migration** ✓

- Automatic migration from `users.json` to SQLite/MySQL
- Tables: `users`, `password_resets`, `rate_limits`, `security_logs`
- Backward compatibility maintained
- Automatic table creation on first run

### 3. **Security Features** ✓

- **CSRF Protection**: All forms protected with secure tokens
- **Rate Limiting**:
  - Max 3 reset attempts per 15 minutes (per IP)
  - 120-second cooldown between requests (per email)
- **User Enumeration Prevention**: Same response for existing/non-existing emails
- **Password Hashing**: Bcrypt with automatic salt
- **Token Hashing**: SHA-256 before database storage
- **Session Security**: HttpOnly, SameSite=Lax cookies

### 4. **Password Policy** ✓

- Minimum 8 characters
- Requires: uppercase, lowercase, number, special character
- Real-time strength meter on reset page
- Client & server-side validation

### 5. **Email Delivery** ✓

- PHPMailer support (SMTP)
- Fallback to PHP `mail()` function
- Responsive HTML email templates
- Plain text alternative for compatibility
- Development mode simulation

### 6. **UX & Accessibility** ✓

- **Resend Timer**: Visual countdown (120s) before next request
- **Focus Management**: Auto-focus, focus trap, return focus on close
- **Keyboard Navigation**: ESC to close, Tab trapping
- **ARIA Attributes**: Proper roles, labels, live regions
- **Loading States**: Spinner animations, disabled buttons
- **Success Instructions**: Clear email check guidance
- **Error Handling**: Descriptive, user-friendly messages

### 7. **Logging & Audit** ✓

- All password reset events logged
- Tracks: user ID, email, IP, user agent, timestamp
- Database + file logging
- Events: requested, success, failed, rate limited, invalid token

### 8. **Analytics Ready** ✓

- Structured event logging for funnel analysis
- Track: request → email sent → link clicked → password reset
- IP and user agent tracking for security analysis

## 📁 Files Created

```
lib/OCR(UPDATED)/
├── config.php              # Configuration (DB, SMTP, security settings)
├── database.php            # Database layer with migration
├── security.php            # CSRF, rate limiting, password validation
├── email.php               # Email service with templates
├── reset-password.php      # Token-based reset page
├── install.php             # Setup & migration script
├── log-in.php              # Updated with new forgot password flow
└── logs/                   # Security logs directory
    └── security.log
```

## 🚀 Installation

### Step 1: Run Installation Script

Visit: `http://your-domain/lib/OCR(UPDATED)/install.php`

This will:

- Create database tables
- Migrate users from `users.json`
- Verify configuration
- Show setup checklist

### Step 2: Configure Settings

Edit `config.php`:

```php
// Production environment
define('ENVIRONMENT', 'production');

// Database (MySQL recommended for production)
define('DB_HOST', 'localhost');
define('DB_NAME', 'chrmo_tracking');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// SMTP Email
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');

// Security Key (CHANGE THIS!)
define('SECRET_KEY', 'generate_a_random_64_character_string_here');

// Application URL
define('APP_URL', 'https://your-domain.com/lib/OCR(UPDATED)');
```

### Step 3: Set Up Email (Optional but Recommended)

#### Option A: Gmail with App Password

1. Enable 2FA on your Google account
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Use the 16-character password in `config.php`

#### Option B: SendGrid/Mailgun

1. Sign up for SendGrid or Mailgun
2. Get SMTP credentials
3. Update `config.php` with their SMTP settings

### Step 4: Test the Flow

1. Go to `log-in.php`
2. Click "Forgot Password?"
3. Enter email → Check inbox
4. Click reset link → Set new password
5. Verify login with new password

## 🔒 Security Best Practices

### Production Checklist

- [ ] Change `SECRET_KEY` to a random 64-character string
- [ ] Set `ENVIRONMENT` to `'production'`
- [ ] Configure real SMTP credentials
- [ ] Use HTTPS (set `secure` cookie flag to `true`)
- [ ] Restrict database user permissions
- [ ] Set proper file permissions (755 for directories, 644 for files)
- [ ] Delete or restrict `install.php` after setup
- [ ] Enable error logging, disable display errors
- [ ] Set up database backups
- [ ] Monitor `logs/security.log` for suspicious activity

### Rate Limiting

Current settings (adjust in `config.php`):

- **3 attempts** per 15 minutes (per IP)
- **120 seconds** cooldown between requests (per email)
- **15 minutes** token expiration

### Password Requirements

- Minimum 8 characters
- At least 1 uppercase letter
- At least 1 lowercase letter
- At least 1 number
- At least 1 special character

## 📧 Email Templates

### Reset Link Email

- Branded header with gradient
- Clear call-to-action button
- 15-minute expiration notice
- Security tips
- IP address and timestamp
- Plain text alternative

### Confirmation Email

- Success notification
- Security alert if not initiated by user
- Support contact information

## 🎨 UI/UX Features

### Modal Enhancements

- Centered with flexbox (no bottom drift)
- Max height 90vh with internal scroll
- Focus trap (Tab key cycles within modal)
- ESC key to close
- Click outside to close
- Return focus to trigger element

### Timer Display

- Visual countdown in seconds
- Disables button during cooldown
- Auto-enables when timer expires
- Persists across page refreshes (via server-side tracking)

### Loading States

- Spinner animation during AJAX
- Button text changes ("Sending...")
- Disabled state with visual feedback
- ARIA live regions for screen readers

### Error Handling

- Descriptive error messages
- Icon-based visual feedback
- Color-coded alerts (red=error, yellow=warning, green=success, blue=info)
- Accessibility-friendly (ARIA roles)

## 📊 Analytics & Monitoring

### Events Logged

```
password_reset_requested    - User requested reset
password_reset_success      - Password successfully changed
password_reset_invalid_token - Invalid/expired token used
password_reset_rate_limited  - Too many attempts
password_reset_error        - System error occurred
```

### Log Format

```
[2025-01-08 14:30:45] password_reset_requested | User: user_123 | Email: user@example.com | IP: <CLIENT_IP> | Details: {"token_hash":"abc123..."}
```

### Funnel Tracking

Monitor these metrics:

1. Reset requests initiated
2. Emails successfully delivered
3. Links clicked (token validated)
4. Passwords successfully reset
5. Drop-off points

## 🧪 Testing

### Manual Testing Checklist

- [ ] Request reset link
- [ ] Receive email within 1 minute
- [ ] Click link opens reset page
- [ ] Password strength meter works
- [ ] Passwords must match
- [ ] Token expires after 15 minutes
- [ ] Token is single-use
- [ ] Rate limiting triggers after 3 attempts
- [ ] Cooldown timer displays correctly
- [ ] Success email received after reset
- [ ] Can login with new password
- [ ] Modal keyboard navigation works
- [ ] Screen reader announces state changes

### Edge Cases

- [ ] Non-existent email (same response as existing)
- [ ] Expired token (clear error message)
- [ ] Used token (cannot reuse)
- [ ] Weak password (validation errors)
- [ ] Network error (retry mechanism)
- [ ] Concurrent requests (rate limiting)

## 🐛 Troubleshooting

### Emails Not Sending

1. Check SMTP credentials in `config.php`
2. Verify firewall allows outbound port 587
3. Check `logs/security.log` for errors
4. Test with development mode (simulates success)
5. Try alternative SMTP provider

### Database Errors

1. Verify database exists and user has permissions
2. Check SQLite file permissions (if using SQLite)
3. Run `install.php` to create tables
4. Check `logs/security.log` for SQL errors

### Rate Limiting Too Strict

Adjust in `config.php`:

```php
define('MAX_RESET_ATTEMPTS', 5); // Increase from 3
define('RESET_COOLDOWN', 60);    // Reduce from 120
```

### Token Expiration Too Short

```php
define('RESET_TOKEN_EXPIRY', 1800); // 30 minutes instead of 15
```

## 📚 API Reference

### Forgot Password Endpoint

**POST** `log-in.php`

```javascript
FormData:
  forgot_password: 'true'
  email: 'user@example.com'
  csrf_token: '<token>'

Response:
{
  "success": true,
  "message": "A password reset link has been sent...",
  "cooldown": 120  // seconds until next request allowed
}
```

### Error Responses

```javascript
// Rate limited
{
  "success": false,
  "message": "Too many password reset attempts...",
  "rate_limited": true,
  "retry_after": 1704723045  // Unix timestamp
}

// Cooldown active
{
  "success": false,
  "message": "Please wait before requesting another reset code.",
  "cooldown": 90,
  "retry_after": 1704723045
}
```

## 🔄 Migration from Old System

The system automatically migrates from the old 6-digit code system:

1. Old `users.json` data is imported to database
2. Old functions remain for backward compatibility
3. New token-based flow takes precedence
4. Old verification code fields are ignored

## 📞 Support

For issues or questions:

- Check `logs/security.log` for errors
- Review this README
- Contact: support@chrmo.com

## 📄 License

© 2025 CHRMO Document Tracking System. All rights reserved.
