<?php
/**
 * User Profile Widget Component
 * Reusable component for displaying user information consistently across all pages
 */

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Function to get user display information
function getUserDisplayInfo() {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        return null;
    }
    
    return [
        'name' => $_SESSION['user_name'] ?? 'User',
        'username' => $_SESSION['user_username'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'department' => $_SESSION['user_department'] ?? '',
        'role' => $_SESSION['user_role'] ?? 'user',
        'id' => $_SESSION['user_id'] ?? ''
    ];
}

// Function to get user initials for avatar
function getUserInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return substr($initials, 0, 2); // Limit to 2 characters
}

// Function to format user role for display
function formatUserRole($role) {
    $roleMap = [
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'department_user' => 'Department User',
        'user' => 'User',
        'hr' => 'HR Staff',
        'supervisor' => 'Supervisor'
    ];
    
    return $roleMap[$role] ?? ucfirst($role);
}

// Function to render user profile header
function renderUserProfileHeader($showActions = true) {
    $userInfo = getUserDisplayInfo();
    
    if (!$userInfo) {
        return '';
    }
    
    $initials = getUserInitials($userInfo['name']);
    $formattedRole = formatUserRole($userInfo['role']);
    
    ob_start();
    ?>
    <div class="user-profile-header">
        <div class="user-profile-info">
            <div class="user-avatar" title="<?php echo htmlspecialchars($userInfo['name']); ?>">
                <?php echo htmlspecialchars($initials); ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($userInfo['name']); ?></h3>
                <p><?php echo htmlspecialchars($userInfo['department']); ?> • <?php echo htmlspecialchars($formattedRole); ?></p>
            </div>
        </div>
        <?php if ($showActions): ?>
        <div class="user-actions">
            <a href="dashboard.php" title="Dashboard">
                <i class="fas fa-tachometer-alt"></i>
            </a>
            <a href="logout.php" title="Logout" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                <span class="label">Logout</span>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Function to render user profile card (for sidebar or other locations)
function renderUserProfileCard($compact = false) {
    $userInfo = getUserDisplayInfo();
    
    if (!$userInfo) {
        return '';
    }
    
    $initials = getUserInitials($userInfo['name']);
    $formattedRole = formatUserRole($userInfo['role']);
    
    ob_start();
    ?>
    <div class="user-profile-card <?php echo $compact ? 'compact' : ''; ?>">
        <div class="user-avatar-large">
            <?php echo htmlspecialchars($initials); ?>
        </div>
        <div class="user-info">
            <h4><?php echo htmlspecialchars($userInfo['name']); ?></h4>
            <p class="user-email"><?php echo htmlspecialchars($userInfo['email']); ?></p>
            <p class="user-department"><?php echo htmlspecialchars($userInfo['department']); ?></p>
            <span class="user-role-badge"><?php echo htmlspecialchars($formattedRole); ?></span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Function to render user name only (for simple displays)
function renderUserName($format = 'full') {
    $userInfo = getUserDisplayInfo();
    
    if (!$userInfo) {
        return 'Guest';
    }
    
    switch ($format) {
        case 'first':
            $nameParts = explode(' ', $userInfo['name']);
            return htmlspecialchars($nameParts[0]);
        case 'last':
            $nameParts = explode(' ', $userInfo['name']);
            return htmlspecialchars(end($nameParts));
        case 'initials':
            return htmlspecialchars(getUserInitials($userInfo['name']));
        case 'full':
        default:
            return htmlspecialchars($userInfo['name']);
    }
}

// Function to get user greeting message
function getUserGreeting($timeBased = true) {
    $userInfo = getUserDisplayInfo();
    
    if (!$userInfo) {
        return 'Hello!';
    }
    
    $name = $userInfo['name'];
    $firstName = explode(' ', $name)[0];
    
    if ($timeBased) {
        $hour = date('H');
        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 17) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }
        return $greeting . ', ' . htmlspecialchars($firstName);
    }
    
    return 'Hello, ' . htmlspecialchars($firstName);
}
?>

<style>
/* User Profile Widget Styles */
.user-profile-header {
    background: linear-gradient(135deg, #0ea5e9, #38bdf8);
    color: white;
    padding: 1rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
}

.user-profile-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1.2rem;
    cursor: pointer;
    transition: transform 0.2s;
}

.user-avatar:hover {
    transform: scale(1.05);
}

.user-details h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.user-details p {
    margin: 0;
    font-size: 0.9rem;
    opacity: 0.9;
}

.user-actions {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.user-actions a {
    color: white;
    text-decoration: none;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}

.user-actions a:hover { background: rgba(255,255,255,0.1); }

/* Modern logout button: subtle pill */
.btn-logout {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.25);
    padding: 0.45rem 0.9rem;
    border-radius: 9999px;
    font-weight: 600;
    line-height: 1;
}
.btn-logout .label { font-size: 0.9rem; }
.btn-logout:hover {
    background: rgba(255,255,255,0.2);
    border-color: rgba(255,255,255,0.4);
}
.btn-logout i { font-size: 0.95rem; }

/* User Profile Card Styles */
.user-profile-card {
    background: white;
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    text-align: center;
    margin: 1rem 0;
}

.user-profile-card.compact {
    padding: 1rem;
    margin: 0.5rem 0;
}

.user-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0ea5e9, #38bdf8);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 2rem;
    margin: 0 auto 1rem;
}

.user-profile-card.compact .user-avatar-large {
    width: 60px;
    height: 60px;
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.user-info h4 {
    margin: 0 0 0.5rem 0;
    color: #1f2937;
    font-size: 1.2rem;
}

.user-profile-card.compact .user-info h4 {
    font-size: 1rem;
    margin-bottom: 0.25rem;
}

.user-email {
    color: #6b7280;
    font-size: 0.9rem;
    margin: 0 0 0.25rem 0;
}

.user-department {
    color: #374151;
    font-size: 0.9rem;
    margin: 0 0 0.5rem 0;
}

.user-role-badge {
    background: linear-gradient(135deg, #0ea5e9, #38bdf8);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.8rem;
    font-weight: 500;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .user-profile-header {
        padding: 0.75rem 1rem;
    }
    
    .user-details h3 {
        font-size: 1rem;
    }
    
    .user-details p {
        font-size: 0.8rem;
    }
    
    .user-actions {
        gap: 0.5rem;
    }
    
    .user-actions a {
        padding: 0.4rem 0.8rem;
        font-size: 0.9rem;
    }
    
    .user-profile-card {
        padding: 1rem;
    }
    
    .user-avatar-large {
        width: 60px;
        height: 60px;
        font-size: 1.5rem;
    }
}
</style>
