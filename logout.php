<?php
session_start();
require_once __DIR__ . '/includes/config.php';

// Prevent caching of this page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (isLoggedIn()) {
    logActivity($conn, $_SESSION['user_id'], 'LOGOUT', 'User logged out');
}

session_unset();
session_destroy();

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

redirect('index.php');
?>
