<?php
require_once 'db.php';

// Start the session
session_start();

// Function to hash passwords
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// Prompt for username and password
echo "Enter username: ";
$username = trim(fgets(STDIN));

echo "Enter password: ";
$password = trim(fgets(STDIN));

// Hash the password
$hashedPassword = hashPassword($password);

try {
    $db = Database::getInstance();
    
    // Check if user already exists
    if ($db->getUserByUsername($username)) {
        die("Error: Username already exists.\n");
    }
    
    // Create the user in database
    $userid = $db->createUser($username, $hashedPassword);
    if (!$userid) {
        die("Error: Failed to create user.\n");
    }
    
    echo "User '$username' added successfully (ID: $userid).\n";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
?>