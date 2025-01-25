<?php
require_once 'db.php';

// Control error reporting based on DEBUG environment variable
$debug = filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOLEAN);
if (!$debug) {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Ensure session hasn't started yet
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings
    ini_set('session.cookie_lifetime', 30 * 24 * 60 * 60); // 30 days
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60); // 30 days

    // Set session cookie parameters
    session_set_cookie_params([
        'lifetime' => 30 * 24 * 60 * 60, // 30 days
        'path' => '/',
        'domain' => '',    // Current domain
        'secure' => false, // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Now start the session
    session_start();
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['userid']) && !empty($_SESSION['userid']);
}

// Function to generate a secure token
function generateToken() {
    return bin2hex(random_bytes(32));
}

// Function to store remember me token
function storeRememberToken($username) {
    $db = Database::getInstance();
    $token = generateToken();
    
    // Store token with expiration (30 days)
    $stmt = $db->prepare("
        UPDATE users 
        SET remember_token = :token 
        WHERE username = :username
    ");
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->execute();
    
    // Set remember me cookie
    setcookie('remember_token', $token, [
        'expires' => time() + (30 * 24 * 60 * 60),
        'path' => '/',
        'domain' => '',
        'secure' => false,  // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Function to verify remember me token
function verifyRememberToken() {
    if (!isset($_COOKIE['remember_token'])) {
        return false;
    }

    $db = Database::getInstance();
    $token = $_COOKIE['remember_token'];
    
    // Find user with this remember token
    $stmt = $db->prepare("
        SELECT * FROM users 
        WHERE remember_token = :token
    ");
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session settings
            ini_set('session.cookie_lifetime', 30 * 24 * 60 * 60);
            ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);

            // Set session cookie parameters
            session_set_cookie_params([
                'lifetime' => 30 * 24 * 60 * 60,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            
            session_start();
        }
        
        // Store user data in session
        $_SESSION['userid'] = $user['userid'];
        $_SESSION['username'] = $user['username'];
        return true;
    }
    
    return false;
}

// Function to hash passwords
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Function to authenticate user
function authenticateUser($username, $password) {
    $db = Database::getInstance();
    $stmt = $db->prepare("
        SELECT * FROM users 
        WHERE username = :username
    ");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($user && verifyPassword($password, $user['password'])) {
        $_SESSION['userid'] = $user['userid'];
        $_SESSION['username'] = $user['username'];
        storeRememberToken($username); // Always remember for web app
        return true;
    }
    return false;
}

// Function to require login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Function to logout user
function logout() {
    // Clear session
    session_destroy();
    
    // Clear remember token if exists
    if (isset($_COOKIE['remember_token'])) {
        $db = Database::getInstance();
        $token = $_COOKIE['remember_token'];
        
        // Clear token from database
        $stmt = $db->prepare("
            UPDATE users 
            SET remember_token = NULL 
            WHERE remember_token = :token
        ");
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->execute();
        
        // Clear cookie
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
    
    // Redirect to login
    header('Location: login.php');
    exit();
}
?>