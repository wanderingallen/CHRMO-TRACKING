<?php
// Include user profile widget for centralized user display functions
include_once 'user_profile_widget.php';

// Get user info for display
$userInfo = getUserDisplayInfo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CHRMO Document Tracking</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="stylesheet" href="assets/animations.css" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="assets/smooth-interactions.js" defer></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Poppins', 'sans-serif'],
          },  
          colors: {
            primary: {
              400: '#38bdf8',
              500: '#0ea5e9',
            }
          }
        }
      }
    }
  </script>
  <style>
    body {
      background: linear-gradient(135deg, #e0f7fa, #81d4fa);
      transition: background 0.3s ease;
    }
    
    /* Enhanced page transitions */
    body > * {
      animation: pageEnter 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .floating-label {
      position: absolute;
      pointer-events: none;
      left: 16px;
      top: 14px;
      transition: top 0.2s cubic-bezier(0.4, 0, 0.2, 1), left 0.2s cubic-bezier(0.4, 0, 0.2, 1), font-size 0.2s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      background: white;
      padding: 0 4px;
      color: #6b7280;
    }
    input:focus ~ .floating-label,
    input:not(:placeholder-shown) ~ .floating-label,
    select:focus ~ .floating-label,
    select:not([value=""]) ~ .floating-label {
      top: -10px;
      left: 12px;
      font-size: 12px;
      color: #0ea5e9; /* Primary color when active */
      background: white;
      z-index: 10; /* Ensure label is above input */
    }
    input:not(:placeholder-shown) ~ .floating-label,
    select:not([value=""]) ~ .floating-label {
      top: -10px;
      left: 12px;
      font-size: 12px;
      color: #6b7280; /* Keep gray when filled but not focused */
      background: white;
      z-index: 10;
    }
    .password-toggle {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: #64748b;
      z-index: 10;
      transition: color 0.2s ease, transform 0.2s ease;
    }
    
    .password-toggle:hover {
      color: #0ea5e9;
      transform: translateY(-50%) scale(1.1);
    }
    /* Responsive branding panel */
    @media (max-width: 768px) {
      .branding-panel h1 {
        font-size: 1.75rem !important;
      }
      .branding-panel img {
        width: 120px !important;
        height: 120px !important;
      }
    }
    /* Custom style for select arrow to match input border */
    select {
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%236b7280'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      background-size: 1.5em;
      padding-right: 2.5rem; /* Space for the custom arrow */
    }
    
    /* Adjust body margin when user profile header is present */
    body.with-user-header {
      margin-top: 80px;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen <?php echo $userInfo ? 'with-user-header' : ''; ?>">

<?php
// Ensure session + helper loaded for profile header
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Render user profile header using the widget (role pulled from $_SESSION['user_role'])
echo renderUserProfileHeader();
?>