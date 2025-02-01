<?php
// Set timezone from environment variable
date_default_timezone_set(getenv('TZ') ?: 'UTC');

// Start output buffering immediately
ob_start();

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set up debug log path
$debug_log = __DIR__ . '/debug.log';
$debug_enabled = filter_var(getenv('DEBUG'), FILTER_VALIDATE_BOOLEAN); // Get debug setting from environment
ini_set('error_log', $debug_log);

// Function to log messages with detail
function logMessage($message) {
    global $debug_log, $debug_enabled;
    
    // Only write logs if debugging is enabled
    if (!$debug_enabled) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] $message" . PHP_EOL;
    error_log($formatted, 3, $debug_log);
}

// Clear log at start of request
if ($debug_enabled && file_exists($debug_log) && filesize($debug_log) > 1024 * 1024) {
    file_put_contents($debug_log, ''); // Clear if over 1MB
}

// Log all request details
logMessage("=== NEW REQUEST ===");
logMessage("Method: " . $_SERVER['REQUEST_METHOD']);
logMessage("Query String: " . $_SERVER['QUERY_STRING']);
logMessage("Request URI: " . $_SERVER['REQUEST_URI']);
logMessage("Raw POST data: " . file_get_contents('php://input'));
logMessage("Session ID: " . session_id());
logMessage("Session data: " . json_encode($_SESSION));

// Set headers for UTF-8
header('Content-Type: application/json; charset=utf-8');

// Log initial request info
logMessage("Request started");
logMessage("Request Method: " . $_SERVER['REQUEST_METHOD']);
logMessage("Session ID: " . session_id());
logMessage("Session data: " . json_encode($_SESSION));

include 'db.php';
include 'auth.php';

// Function to clean and truncate text for preview
function preparePreviewText($text) {
    // First convert all line endings to \n for consistent processing
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    
    // Remove markdown code blocks (both with and without language specifiers)
    $text = preg_replace('/```[\s\S]*?```/m', '', $text);
    $text = preg_replace('/`{1,3}[^`]*`{1,3}/m', '', $text); // Handle 1-3 backticks
    
    // Remove any remaining backticks
    $text = str_replace('`', '', $text);
    
    // Remove HTML tags
    $text = strip_tags($text);
    
    // Aggressive cleanup of problematic characters
    $text = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $text); // Remove all control chars and nbsp
    $text = preg_replace('/\s+/u', ' ', $text); // Normalize all whitespace to single space
    $text = preg_replace('/[^\P{C}\n]+/u', '', $text); // Remove other control characters
    
    // Get first line or truncate
    if (strpos($text, "\n") !== false) {
        $text = substr($text, 0, strpos($text, "\n"));
    }
    
    // Clean up the text
    $text = trim($text);
    
    // Ensure valid UTF-8
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    
    // Final length limit with ellipsis
    if (mb_strlen($text, 'UTF-8') > 100) {
        $text = mb_substr($text, 0, 97, 'UTF-8') . '...';
    }
    
    return $text;
}

// Function to send JSON response with logging
function sendJsonResponse($data) {
    try {
        // Clean ALL output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Start fresh buffer
        ob_start();
        
        // Convert boolean success to actual boolean
        if (isset($data['success'])) {
            $data['success'] = (bool)$data['success'];
        }
        
        // If this is a recent files response, clean up the previews
        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as &$file) {
                if (isset($file['preview'])) {
                    $file['preview'] = preparePreviewText($file['preview']);
                }
                // Ensure all fields are properly encoded
                foreach ($file as $key => &$value) {
                    if (is_string($value)) {
                        // Remove any potential invalid UTF-8 sequences
                        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                        // Remove any control characters except tabs and newlines
                        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
                    }
                }
            }
        }
        
        // Add server time to every response
        $data['server_time'] = date('c'); // ISO 8601 format
        
        // Set response headers - MUST be before any output
        if (headers_sent($file, $line)) {
            throw new Exception("Headers already sent in $file on line $line");
        }
        
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        
        // Encode with minimal options first
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        if ($json === false) {
            // If that fails, try with more aggressive options
            $json = json_encode($data, 
                JSON_UNESCAPED_UNICODE | 
                JSON_UNESCAPED_SLASHES | 
                JSON_INVALID_UTF8_SUBSTITUTE |
                JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            
            if ($json === false) {
                throw new Exception("JSON encode error: " . json_last_error_msg());
            }
        }
        
        // Log a sample of the response for debugging
        logMessage("Response sample (first 500 chars): " . substr($json, 0, 500));
        
        // Clean the output buffer again
        ob_end_clean();
        
        // Send the response
        echo $json;
        exit();
        
    } catch (Exception $e) {
        // Clean any output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        logMessage("Error in sendJsonResponse: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
        exit();
    }
}

// Check authentication
if (!isLoggedIn()) {
    logMessage("Not authenticated - Session status: " . session_status());
    logMessage("Session ID: " . session_id());
    http_response_code(401);
    sendJsonResponse(['error' => 'Not authenticated']);
}

// Get username from session
$userid = $_SESSION['userid'] ?? null;
if (!$userid) {
    logMessage("No userid in session. Session data: " . print_r($_SESSION, true));
    http_response_code(401);
    sendJsonResponse(['error' => 'No userid in session']);
}

$db = Database::getInstance();
logMessage("Database instance created");

try {
    // Get raw input
    $input = file_get_contents('php://input');
    logMessage("Raw input: $input");
    
    // Parse JSON input
    $data = json_decode($input, true);
    logMessage("Decoded data: " . json_encode($data));
    
    if (!isset($data['action'])) {
        throw new Exception('No action specified');
    }
    
    // Handle the load action
    if ($data['action'] === 'load') {
        logMessage("Processing load action");
        if (!isset($data['noteid'])) {
            throw new Exception('No note ID provided');
        }
        
        $note = $db->getNote($data['noteid'], $userid);
        logMessage("Found note: " . json_encode($note));
        if (!$note) {
            throw new Exception('Note not found');
        }
        
        logMessage("Sending response with note content");
        sendJsonResponse([
            'content' => $note['content'],
            'title' => $note['title']
        ]);
    }
    
    // Handle the save action
    if ($data['action'] === 'save') {
        logMessage("Processing save action");
        if (!isset($data['content']) || !isset($data['title'])) {
            throw new Exception('Missing content or title');
        }
        
        $noteid = $data['noteid'] ?? null;
        $folderid = $data['folderid'] ?? 1; // Default to root folder if not specified
        logMessage("Note ID: " . ($noteid ?? 'null'));
        logMessage("Folder ID: " . ($folderid ?? 'null'));
        
        if ($noteid) {
            // Update existing note
            logMessage("Updating existing note");
            if (!$db->updateNote($noteid, $data['title'], $data['content'])) {
                throw new Exception('Failed to update note');
            }
        } else {
            // Create new note
            logMessage("Creating new note");
            $noteid = $db->createNote($userid, $data['title'], $data['content'], $folderid);
            logMessage("Created note with ID: $noteid");
            if (!$noteid) {
                throw new Exception('Failed to create note');
            }
        }
        
        logMessage("Sending response with success and note ID");
        sendJsonResponse(['success' => true, 'noteid' => $noteid]);
    }
    
    // Handle the list action
    if ($data['action'] === 'list') {
        logMessage("Processing list action");
        $folderid = $data['folderid'] ?? null;
        logMessage("Folder ID: " . ($folderid ?? 'null'));
        
        // If no folder ID is provided, use user's root folder
        if ($folderid === null) {
            $folderid = 1; // User's root folder ID
        }
        
        $items = [];
        
        // Get current folder info if we're in a subfolder
        $currentFolder = null;
        if ($folderid) {
            $stmt = $db->prepare("
                SELECT folderid, name, parent_folderid, created_at
                FROM folders 
                WHERE folderid = :folderid AND userid = :userid
            ");
            $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
            $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
            $currentFolder = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        }
        
        // Get folders
        $folders = $db->getFoldersByParent($userid, $folderid);
        logMessage("Found " . count($folders) . " folders");
        foreach ($folders as $folder) {
            $items[] = [
                'id' => 'folder_' . $folder['folderid'],
                'name' => $folder['name'],
                'type' => 'directory',
                'lastModified' => $folder['created_at']
            ];
        }
        
        // Get notes in current folder
        $notes = $db->getNotesByFolder($userid, $folderid);
        logMessage("Found " . count($notes) . " notes");
        foreach ($notes as $note) {
            $items[] = [
                'id' => 'note_' . $note['noteid'],
                'name' => $note['title'],
                'type' => 'file',
                'lastModified' => $note['updated_at'] ?? $note['created_at']
            ];
        }
        
        logMessage("Sending response with " . count($items) . " total items");
        sendJsonResponse([
            'items' => $items,
            'currentFolder' => $currentFolder
        ]);
        return;
    }
    
    // Handle the delete action
    if ($data['action'] === 'delete') {
        logMessage("Processing delete action");
        if (!isset($data['noteid'])) {
            throw new Exception('No note ID provided for deletion');
        }
        
        logMessage("Deleting note with ID: " . $data['noteid']);
        if (!$db->deleteNote($data['noteid'], $userid)) {
            throw new Exception('Failed to delete note');
        }
        
        logMessage("Sending response with success");
        sendJsonResponse(['success' => true]);
    }
    
    // Handle folder deletion
    if ($data['action'] === 'deleteFolder') {
        logMessage("Processing deleteFolder action");
        if (!isset($data['folderid'])) {
            throw new Exception('No folder ID provided for deletion');
        }
        
        logMessage("Deleting folder with ID: " . $data['folderid']);
        if (!$db->deleteFolder($data['folderid'], $userid)) {
            throw new Exception('Failed to delete folder');
        }
        
        logMessage("Sending response with success");
        sendJsonResponse(['success' => true]);
    }
    
    // Handle the createFolder action
    if ($data['action'] === 'createFolder') {
        logMessage("Processing createFolder action");
        if (!isset($data['path'])) {
            throw new Exception('Missing path for folder creation');
        }
        
        // Get folder name and parent ID
        $folderName = trim($data['path']);
        $parentFolderId = isset($data['parent_id']) ? $data['parent_id'] : 1;
        
        // Create folder in database
        logMessage("Creating folder in database: $folderName (parent: $parentFolderId)");
        if (!$db->createFolder($userid, $folderName, $parentFolderId)) {
            throw new Exception('Failed to create folder in database');
        }
        
        logMessage("Sending response with success");
        sendJsonResponse([
            'message' => 'Folder created successfully'
        ]);
    }
    
    // Handle the get_recent_modified action
    if ($data['action'] === 'get_recent_modified') {
        logMessage("Processing get_recent_modified action");

        $recentFiles = $db->getRecentModifiedFiles($userid);
        logMessage("Found " . count($recentFiles) . " recent files");
        sendJsonResponse(['success' => true, 'files' => $recentFiles]);
        return;
    }
    
    // Handle the search action
    if ($data['action'] === 'search') {
        logMessage("Processing search action");
        $query = $data['query'] ?? '';
        $type = $data['type'] ?? 'all';
        
        if (empty($query)) {
            sendJsonResponse(['error' => 'Search query is required']);
            return;
        }
        
        $results = $db->searchNotes($_SESSION['userid'], $query, $type);
        sendJsonResponse(['success' => true, 'results' => $results]);
        return;
    }
    
    // Handle the exportNotes action
    if ($data['action'] === 'exportNotes') {
        logMessage("Processing exportNotes action");
        
        $db = Database::getInstance();
        $userid = $_SESSION['userid'];
        
        // Get all user data
        $userData = [
            'userid' => $userid,
            'folders' => $db->getFoldersByUser($userid),
            'notes' => $db->getNotesByUser($userid),
            'tags' => $db->getTagsByUser($userid),
            'exportDate' => date('Y-m-d H:i:s'),
            'version' => '1.0'
        ];
        
        sendJsonResponse($userData);
    }
    
    // Handle the importNotes action
    if ($data['action'] === 'importNotes') {
        logMessage("Processing importNotes action");
        
        if (!isset($data['jsonData'])) {
            throw new Exception('No JSON data provided for import');
        }
        
        $jsonData = $data['jsonData'];
        
        // Validate JSON structure
        if (!isset($jsonData['folders']) || !isset($jsonData['notes']) || !isset($jsonData['tags']) || !isset($jsonData['version'])) {
            throw new Exception('Invalid JSON structure: missing required fields');
        }
        
        if ($jsonData['version'] !== '1.0') {
            throw new Exception('Unsupported JSON version');
        }
        
        $db = Database::getInstance();
        $userid = $_SESSION['userid'];
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Delete existing user data
            $db->deleteUserData($userid);
            
            // Keep track of old to new ID mappings
            $folderMap = [];
            
            // First, create a map of original folder hierarchy
            $folderHierarchy = [];
            foreach ($jsonData['folders'] as $folder) {
                $folderHierarchy[$folder['folderid']] = [
                    'name' => $folder['name'],
                    'parent_folderid' => $folder['parent_folderid'],
                    'created_at' => $folder['created_at'],
                    'updated_at' => $folder['updated_at']
                ];
            }
            
            // Create folders in hierarchical order (root folders first)
            $processedFolders = [];
            while (count($processedFolders) < count($folderHierarchy)) {
                foreach ($folderHierarchy as $oldId => $folder) {
                    // Skip if already processed
                    if (isset($processedFolders[$oldId])) continue;
                    
                    // If this is a root folder or its parent is already processed
                    if ($folder['parent_folderid'] === null || isset($processedFolders[$folder['parent_folderid']])) {
                        // Get the new parent ID if it exists
                        $newParentId = null;
                        if ($folder['parent_folderid'] !== null) {
                            $newParentId = $folderMap[$folder['parent_folderid']];
                        }
                        
                        // Create the folder with correct parent and timestamps
                        $newFolderId = $db->createFolder(
                            $userid, 
                            $folder['name'], 
                            $newParentId,
                            $folder['created_at'],
                            $folder['updated_at']
                        );
                        $folderMap[$oldId] = $newFolderId;
                        $processedFolders[$oldId] = true;
                    }
                }
            }
            
            // Import notes with correct folder mappings and timestamps
            $noteMap = [];
            foreach ($jsonData['notes'] as $note) {
                $newFolderId = isset($note['folderid']) ? ($folderMap[$note['folderid']] ?? null) : null;
                $noteId = $db->createNote(
                    $userid, 
                    $note['title'], 
                    $note['content'], 
                    $newFolderId,
                    $note['created_at'],
                    $note['updated_at']
                );
                $noteMap[$note['noteid']] = $noteId;
            }
            
            // Import tags with correct note mappings
            foreach ($jsonData['tags'] as $tag) {
                if (isset($noteMap[$tag['noteid']])) {
                    $db->addTag($noteMap[$tag['noteid']], $tag['tag']);
                }
            }
            
            $db->commitTransaction();
            sendJsonResponse(['success' => true, 'message' => 'Import completed successfully']);
            
        } catch (Exception $e) {
            $db->rollbackTransaction();
            throw new Exception('Import failed: ' . $e->getMessage());
        }
    }
    
    // Handle the rename action
    if ($data['action'] === 'rename') {
        logMessage("Processing rename action");
        
        if (!isset($data['id']) || !isset($data['newName']) || !isset($data['type'])) {
            throw new Exception('Missing required fields for rename operation');
        }
        
        $id = intval($data['id']);
        $newName = trim($data['newName']);
        $type = $data['type'];
        
        // Validate new name
        if (empty($newName)) {
            throw new Exception('New name cannot be empty');
        }
        
        // Sanitize the new name
        $newName = htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');
        
        $db = Database::getInstance();
        $success = false;
        
        if ($type === 'folder') {
            $success = $db->renameFolder($id, $_SESSION['userid'], $newName);
        } else if ($type === 'note') {
            $success = $db->renameNote($id, $_SESSION['userid'], $newName);
        } else {
            throw new Exception('Invalid type for rename operation');
        }
        
        if (!$success) {
            throw new Exception('Failed to rename ' . $type);
        }
        
        sendJsonResponse(['success' => true, 'message' => ucfirst($type) . ' renamed successfully']);
    }
    
    // Handle the resetApp action
    if ($data['action'] === 'resetApp') {
        logMessage("Processing resetApp action");
        
        $db = Database::getInstance();
        $userid = $_SESSION['userid'];
        
        // Delete all user data
        logMessage("Deleting user data for user: " . $userid);
        $db->deleteUserData($userid);
        
        // Create root folder
        logMessage("Creating root folder for user: " . $userid);
        $rootFolderId = $db->createFolder($userid, 'Root', null);
        if (!$rootFolderId) {
            throw new Exception('Failed to create root folder');
        }
        
        logMessage("Reset completed successfully");
        sendJsonResponse(['success' => true]);
    }
    
    // If we get here, no valid action was found
    throw new Exception('Invalid action: ' . $data['action']);
    
} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
    http_response_code(400);
    sendJsonResponse(['error' => $e->getMessage()]);
} catch (Error $e) {
    logMessage("Fatal Error: " . $e->getMessage());
    http_response_code(500);
    sendJsonResponse(['error' => 'Internal server error']);
}