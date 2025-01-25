<?php
require_once 'db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

function migrateToDatabase() {
    $db = Database::getInstance();
    
    // First, add updated_at column to folders table if it doesn't exist
    try {
        $db->exec("ALTER TABLE folders ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "Added updated_at column to folders table\n";
    } catch (Exception $e) {
        // Column might already exist, continue
        echo "Note: updated_at column might already exist\n";
    }
    
    $usersDir = 'users';
    $usersFile = 'users.json';
    
    if (!file_exists($usersFile)) {
        die("users.json not found\n");
    }
    
    if (!file_exists($usersDir)) {
        die("Users directory not found\n");
    }
    
    // Load existing users from JSON
    $users = json_decode(file_get_contents($usersFile), true);
    if (!$users) {
        die("Failed to parse users.json\n");
    }
    
    echo "Starting migration...\n";
    
    foreach ($users as $username => $userData) {
        echo "Migrating user: $username\n";
        
        try {
            // Create user in database with existing password hash
            $userid = $db->createUser($username, $userData['password']);
            if (!$userid) {
                echo "Failed to create user $username in database\n";
                continue;
            }
            
            echo "Created user with ID: $userid\n";
            
            // Migrate all folders and notes for this user
            $userDir = "$usersDir/$username";
            if (is_dir($userDir)) {
                migrateFolderStructure($userDir, $userid, null, $db);
            }
            
        } catch (Exception $e) {
            echo "Error migrating user $username: " . $e->getMessage() . "\n";
            continue;
        }
    }
    
    echo "\nMigration completed!\n";
    echo "Note: The original users.json and markdown files have not been deleted.\n";
    echo "After verifying the migration, you can manually remove them if desired.\n";
}

function migrateFolderStructure($path, $userid, $parent_folderid, $db) {
    $items = scandir($path);
    $folderid = null;
    
    // Create folder entry if this is not the root user directory
    if ($parent_folderid !== null || basename($path) !== strval($userid)) {
        $folderName = basename($path);
        $folderid = $db->createFolder($userid, $folderName, $parent_folderid);
        
        if ($folderid) {
            // Get folder timestamps
            $mtime = filemtime($path);
            $ctime = filectime($path);
            
            // Update folder timestamps
            $db->exec("UPDATE folders SET 
                created_at = datetime($ctime, 'unixepoch'),
                updated_at = datetime($mtime, 'unixepoch') 
                WHERE folderid = $folderid");
            
            echo "  Created folder: $folderName (ID: $folderid)\n";
        }
    }
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.DS_Store' || 
            $item === '.git' || $item === '.recent_modified.json') {
            continue;
        }
        
        $itemPath = $path . '/' . $item;
        
        if (is_dir($itemPath)) {
            // Recursively handle subdirectories
            migrateFolderStructure($itemPath, $userid, $folderid, $db);
        } elseif (is_file($itemPath) && pathinfo($item, PATHINFO_EXTENSION) === 'md') {
            // Handle markdown files
            $content = mb_convert_encoding(file_get_contents($itemPath), 'UTF-8', 'UTF-8');
            $title = pathinfo($item, PATHINFO_FILENAME);
            
            try {
                // Create note in the current folder
                $noteid = $db->createNote($userid, $title, $content, $folderid);
                if ($noteid) {
                    // Get file timestamps
                    $mtime = filemtime($itemPath);
                    $ctime = filectime($itemPath);
                    
                    // Update note timestamps
                    $db->exec("UPDATE notes SET 
                        created_at = datetime($ctime, 'unixepoch'),
                        updated_at = datetime($mtime, 'unixepoch') 
                        WHERE noteid = $noteid");
                    
                    echo "  Migrated note: $item (ID: $noteid)\n";
                } else {
                    echo "  Failed to migrate note: $item\n";
                }
            } catch (Exception $e) {
                echo "  Error migrating note $item: " . $e->getMessage() . "\n";
            }
        }
    }
}

// Run the migration
echo "Starting migration to database...\n";
migrateToDatabase();