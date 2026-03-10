<?php
/**
 * User Display Examples
 * This file shows how to use the centralized user display functions in your PHP pages
 */

session_start();

// Include the user profile widget
include_once 'user_profile_widget.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: log-in.php');
    exit();
}

// Include header (which will automatically show the user profile header)
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Display Examples - CHRMO Document Tracking</title>
</head>
<body>
    <div class="container mx-auto p-8">
        <h1 class="text-3xl font-bold mb-8">User Display Examples</h1>
        
        <!-- Example 1: Simple user name display -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">1. Simple User Name Display</h2>
            <p>Welcome, <strong><?php echo renderUserName(); ?></strong>!</p>
            <p>First name: <strong><?php echo renderUserName('first'); ?></strong></p>
            <p>Last name: <strong><?php echo renderUserName('last'); ?></strong></p>
            <p>Initials: <strong><?php echo renderUserName('initials'); ?></strong></p>
        </div>
        
        <!-- Example 2: User greeting -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">2. User Greeting</h2>
            <p><?php echo getUserGreeting(); ?></p>
            <p><?php echo getUserGreeting(false); ?> (without time-based greeting)</p>
        </div>
        
        <!-- Example 3: User profile card -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">3. User Profile Card</h2>
            <?php echo renderUserProfileCard(); ?>
        </div>
        
        <!-- Example 4: Compact user profile card -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">4. Compact User Profile Card</h2>
            <?php echo renderUserProfileCard(true); ?>
        </div>
        
        <!-- Example 5: Manual user info display -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">5. Manual User Info Display</h2>
            <?php 
            $userInfo = getUserDisplayInfo();
            if ($userInfo): 
            ?>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <strong>Name:</strong> <?php echo htmlspecialchars($userInfo['name']); ?>
                    </div>
                    <div>
                        <strong>Email:</strong> <?php echo htmlspecialchars($userInfo['email']); ?>
                    </div>
                    <div>
                        <strong>Department:</strong> <?php echo htmlspecialchars($userInfo['department']); ?>
                    </div>
                    <div>
                        <strong>Role:</strong> <?php echo htmlspecialchars(formatUserRole($userInfo['role'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Example 6: Code examples -->
        <div class="bg-gray-100 p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">6. Code Examples</h2>
            <div class="space-y-4">
                <div>
                    <h3 class="font-semibold">Include the widget in your PHP file:</h3>
                    <pre class="bg-gray-800 text-green-400 p-3 rounded text-sm"><code>&lt;?php
include_once 'user_profile_widget.php';
?&gt;</code></pre>
                </div>
                
                <div>
                    <h3 class="font-semibold">Display user name:</h3>
                    <pre class="bg-gray-800 text-green-400 p-3 rounded text-sm"><code>&lt;?php echo renderUserName(); ?&gt;</code></pre>
                </div>
                
                <div>
                    <h3 class="font-semibold">Display user greeting:</h3>
                    <pre class="bg-gray-800 text-green-400 p-3 rounded text-sm"><code>&lt;?php echo getUserGreeting(); ?&gt;</code></pre>
                </div>
                
                <div>
                    <h3 class="font-semibold">Display user profile card:</h3>
                    <pre class="bg-gray-800 text-green-400 p-3 rounded text-sm"><code>&lt;?php echo renderUserProfileCard(); ?&gt;</code></pre>
                </div>
                
                <div>
                    <h3 class="font-semibold">Get user info array:</h3>
                    <pre class="bg-gray-800 text-green-400 p-3 rounded text-sm"><code>&lt;?php 
$userInfo = getUserDisplayInfo();
if ($userInfo) {
    echo $userInfo['name'];
    echo $userInfo['email'];
    echo $userInfo['department'];
    echo $userInfo['role'];
}
?&gt;</code></pre>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
