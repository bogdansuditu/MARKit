<?php
include 'auth.php';  // This will handle session_start()

// Clear all session variables
$_SESSION = array();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Delete remember me token cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Delete theme cookie
setcookie('theme', '', time() - 3600, '/');

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();