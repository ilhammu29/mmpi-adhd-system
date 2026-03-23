<?php
// logout.php
require_once 'includes/config.php';

// Get current user info before logging out
$userId = $_SESSION['user_id'] ?? null;

// Destroy session
session_destroy();

// Clear remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
    
    // Clear token from database
    if ($userId) {
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->execute([$userId]);
    }
}

// Redirect to login page
redirect('/login.php');
?>