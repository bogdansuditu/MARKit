<?php
class Database {
    private static $instance = null;
    private $db;
    private $dbPath = '/var/www/html/notes.db';
    private $timezoneOffset;
    private $transactionLevel = 0;

    private function __construct() {
        $isNewDb = !file_exists($this->dbPath);
        
        try {
            $this->db = new SQLite3($this->dbPath);
            $this->db->enableExceptions(true);
            
            // Set pragmas for better performance and security
            $this->db->exec('PRAGMA foreign_keys = ON');
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA encoding = "UTF-8"');
            
            // Configure timezone offset based on environment variable
            $timezone = new DateTimeZone(getenv('TZ') ?: 'UTC');
            $now = new DateTime('now', $timezone);
            $offset = $timezone->getOffset($now);
            $hours = floor($offset / 3600);
            $minutes = floor(($offset % 3600) / 60);
            $this->timezoneOffset = sprintf("%+03d:%02d", $hours, $minutes);
            
            if ($isNewDb) {
                $this->createTables();
            }
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new Exception("Could not connect to database: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    private function createTables() {
        // Users table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                userid INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                remember_token TEXT
            )
        ");

        // Folders table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS folders (
                folderid INTEGER PRIMARY KEY AUTOINCREMENT,
                userid INTEGER NOT NULL,
                parent_folderid INTEGER,
                name TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT (datetime('now', '" . $this->timezoneOffset . "')),
                updated_at TIMESTAMP DEFAULT (datetime('now', '" . $this->timezoneOffset . "')),
                FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE,
                FOREIGN KEY (parent_folderid) REFERENCES folders(folderid) ON DELETE CASCADE
            )
        ");
        
        // Set the autoincrement start value to 2
        $this->db->exec("
            INSERT OR IGNORE INTO sqlite_sequence (name, seq) 
            VALUES ('folders', 1)
        ");
        
        // Create a hidden root folder with ID 1 if it doesn't exist
        $stmt = $this->prepare("SELECT COUNT(*) as count FROM folders WHERE folderid = 1");
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($result['count'] == 0) {
            $stmt = $this->prepare("
                INSERT INTO folders (folderid, userid, name, parent_folderid)
                SELECT 1, userid, '.root', NULL FROM users LIMIT 1
            ");
            $stmt->execute();
        }

        // Notes table with folder support
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS notes (
                noteid INTEGER PRIMARY KEY AUTOINCREMENT,
                userid INTEGER NOT NULL,
                folderid INTEGER,
                title TEXT NOT NULL,
                content TEXT,
                created_at TIMESTAMP DEFAULT (datetime('now', '" . $this->timezoneOffset . "')),
                updated_at TIMESTAMP DEFAULT (datetime('now', '" . $this->timezoneOffset . "')),
                FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE,
                FOREIGN KEY (folderid) REFERENCES folders(folderid) ON DELETE CASCADE
            )
        ");

        // Tags table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tags (
                tagid INTEGER PRIMARY KEY AUTOINCREMENT,
                noteid INTEGER NOT NULL,
                tag TEXT NOT NULL,
                FOREIGN KEY (noteid) REFERENCES notes(noteid) ON DELETE CASCADE
            )
        ");

        // Recent modifications table
        $this->db->exec("DROP TABLE IF EXISTS recent_modifications");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS recent_modifications (
                userid INTEGER NOT NULL,
                noteid INTEGER NOT NULL,
                modified_at TIMESTAMP DEFAULT (datetime('now', '" . $this->timezoneOffset . "')),
                PRIMARY KEY (userid, noteid),
                FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE,
                FOREIGN KEY (noteid) REFERENCES notes(noteid) ON DELETE CASCADE
            )
        ");

        // System logs table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS system_logs (
                logid INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TIMESTAMP DEFAULT (datetime('now', '" . $this->timezoneOffset . "')),
                message TEXT NOT NULL,
                session_id TEXT,
                request_method TEXT,
                request_uri TEXT,
                request_data TEXT,
                userid INTEGER,
                FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE
            )
        ");

        // Create indexes
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_tags_tag ON tags(tag)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_notes_userid ON notes(userid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_notes_folderid ON notes(folderid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_folders_userid ON folders(userid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_folders_parent ON folders(parent_folderid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_recent_mods_userid ON recent_modifications(userid, modified_at)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON system_logs(timestamp)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_logs_userid ON system_logs(userid)");
    }

    public function prepare($query) {
        return $this->db->prepare($query);
    }

    public function exec($query) {
        return $this->db->exec($query);
    }

    public function lastInsertRowID() {
        return $this->db->lastInsertRowID();
    }

    // User Operations
    public function createUser($username, $passwordHash) {
        try {
            $this->beginTransaction();
            
            // Create user
            $stmt = $this->prepare("
                INSERT INTO users (username, password_hash)
                VALUES (:username, :password_hash)
            ");
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':password_hash', $passwordHash, SQLITE3_TEXT);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create user");
            }
            
            $userid = $this->lastInsertRowID();
            
            // Create root folder with ID 1 if it doesn't exist
            $stmt = $this->prepare("
                INSERT OR IGNORE INTO folders (folderid, userid, name, parent_folderid)
                VALUES (1, :userid, '.root', NULL)
            ");
            $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
            $stmt->execute();
            
            $this->commitTransaction();
            return $userid;
            
        } catch (Exception $e) {
            $this->rollbackTransaction();
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }

    public function getUserByUsername($username) {
        $stmt = $this->prepare("
            SELECT * FROM users WHERE username = :username
        ");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function updateRememberToken($userid, $token) {
        $stmt = $this->prepare("
            UPDATE users SET remember_token = :token
            WHERE userid = :userid
        ");
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // Folder Operations
    public function createFolder($userid, $name, $parent_folderid = null, $created_at = null, $updated_at = null) {
        $created_at = $created_at ?: date('Y-m-d H:i:s');
        $updated_at = $updated_at ?: $created_at;
        
        $stmt = $this->prepare("
            INSERT INTO folders (userid, parent_folderid, name, created_at, updated_at)
            VALUES (:userid, :parent_folderid, :name, :created_at, :updated_at)
        ");
        
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':parent_folderid', $parent_folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $created_at, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $updated_at, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            return $this->lastInsertRowID();
        }
        return false;
    }

    public function getFolderPath($folderid) {
        $path = [];
        $currentFolderId = $folderid;
        
        while ($currentFolderId) {
            $stmt = $this->prepare("
                SELECT folderid, parent_folderid, name 
                FROM folders 
                WHERE folderid = :folderid
            ");
            $stmt->bindValue(':folderid', $currentFolderId, SQLITE3_INTEGER);
            
            $folder = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            
            if (!$folder) break;
            
            array_unshift($path, $folder['name']);
            $currentFolderId = $folder['parent_folderid'];
        }
        
        return implode('/', $path);
    }

    public function getStatusFolderPath($folderid) {
        if (!$folderid) return [];
        
        $path = [];
        $currentFolderId = $folderid;
        
        while ($currentFolderId) {
            $stmt = $this->prepare("
                SELECT folderid, name, parent_folderid
                FROM folders
                WHERE folderid = :folderid
            ");
            $stmt->bindValue(':folderid', $currentFolderId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $folder = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$folder) break;
            
            if ($folder['folderid'] != 1) { // Don't include root folder
                array_unshift($path, [
                    'id' => $folder['folderid'],
                    'name' => $folder['name']
                ]);
            }
            
            $currentFolderId = $folder['parent_folderid'];
        }
        
        return $path;
    }

    public function getFolderContents($userid, $folderid = null) {
        // Get subfolders
        $stmt = $this->prepare("
            SELECT folderid, name, created_at, 'folder' as type
            FROM folders 
            WHERE userid = :userid AND parent_folderid IS :folderid
            ORDER BY name
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $contents = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contents[] = $row;
        }

        // Get notes in this folder
        $stmt = $this->prepare("
            SELECT noteid, title, updated_at, 'note' as type
            FROM notes 
            WHERE userid = :userid AND folderid IS :folderid
            ORDER BY title
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contents[] = $row;
        }

        return $contents;
    }

    public function moveNote($noteid, $userid, $folderid = null) {
        $stmt = $this->prepare("
            UPDATE notes 
            SET folderid = :folderid 
            WHERE noteid = :noteid AND userid = :userid
        ");
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function moveFolder($folderid, $userid, $new_parent_folderid = null) {
        // Prevent circular references
        if ($this->isDescendant($folderid, $new_parent_folderid)) {
            return false;
        }

        $stmt = $this->prepare("
            UPDATE folders 
            SET parent_folderid = :new_parent_folderid 
            WHERE folderid = :folderid AND userid = :userid
        ");
        $stmt->bindValue(':new_parent_folderid', $new_parent_folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    private function isDescendant($folderid, $potential_ancestor_id) {
        if (!$potential_ancestor_id) return false;
        if ($folderid == $potential_ancestor_id) return true;

        $stmt = $this->prepare("
            SELECT parent_folderid FROM folders WHERE folderid = :folderid
        ");
        $stmt->bindValue(':folderid', $potential_ancestor_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$result || !$result['parent_folderid']) return false;
        return $this->isDescendant($folderid, $result['parent_folderid']);
    }

    public function getFoldersByParent($userid, $parent_folderid = null) {
        $stmt = $this->prepare("
            SELECT folderid, name, created_at
            FROM folders 
            WHERE userid = :userid AND parent_folderid IS :parent_folderid
            ORDER BY name
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':parent_folderid', $parent_folderid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $folders = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $folders[] = $row;
        }
        return $folders;
    }

    public function getNotesByFolder($userid, $folderid = null) {
        $stmt = $this->prepare("
            SELECT noteid, title, content, created_at, updated_at
            FROM notes 
            WHERE userid = :userid AND folderid IS :folderid
            ORDER BY title
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $notes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $notes[] = $row;
        }
        return $notes;
    }

    // Note Operations
    public function createNote($userid, $title, $content, $folderid = null, $created_at = null, $updated_at = null) {
        $created_at = $created_at ?: date('Y-m-d H:i:s');
        $updated_at = $updated_at ?: $created_at;
        
        $stmt = $this->prepare("
            INSERT INTO notes (userid, folderid, title, content, created_at, updated_at)
            VALUES (:userid, :folderid, :title, :content, :created_at, :updated_at)
        ");
        
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $created_at, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $updated_at, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            $noteid = $this->lastInsertRowID();
            $this->updateNoteTags($noteid, $content);
            $this->updateRecentModification($userid, $noteid);
            return $noteid;
        }
        return false;
    }

    public function updateNote($noteid, $title, $content) {
        $this->beginTransaction();
        try {
            $stmt = $this->prepare("
                UPDATE notes 
                SET title = :title, 
                    content = :content, 
                    updated_at = datetime('now', '" . $this->timezoneOffset . "')
                WHERE noteid = :noteid
            ");
            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->bindValue(':content', $content, SQLITE3_TEXT);
            $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                $this->updateNoteTags($noteid, $content);
                $this->updateRecentModification($this->getNoteUserId($noteid), $noteid);
                $this->commitTransaction();
                return true;
            }
            $this->rollbackTransaction();
            return false;
        } catch (Exception $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    private function getNoteUserId($noteid) {
        $stmt = $this->prepare("SELECT userid FROM notes WHERE noteid = :noteid");
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result ? $result['userid'] : null;
    }

    public function getNote($noteid, $userid) {
        $stmt = $this->prepare("
            SELECT * FROM notes 
            WHERE noteid = :noteid AND userid = :userid
        ");
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function getNotesByUser($userid) {
        $stmt = $this->prepare("
            SELECT * FROM notes 
            WHERE userid = :userid 
            ORDER BY updated_at DESC
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $notes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $notes[] = $row;
        }
        return $notes;
    }

    public function deleteNote($noteid, $userid) {
        $stmt = $this->prepare("
            DELETE FROM notes 
            WHERE noteid = :noteid AND userid = :userid
        ");
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // Search Operations
    public function searchNotes($userid, $query, $type = 'all') {
        $query = trim($query);
        if (empty($query)) return [];
        
        // Prepare the base query
        $sql = "
            SELECT DISTINCT n.noteid, n.title, n.content, n.updated_at,
                   GROUP_CONCAT(t.tag) as tags
            FROM notes n
            LEFT JOIN tags t ON n.noteid = t.noteid
            WHERE n.userid = :userid
        ";
        
        // Add type-specific conditions
        switch ($type) {
            case 'title':
                $sql .= " AND n.title LIKE :search";
                break;
            case 'content':
                $sql .= " AND n.content LIKE :search";
                break;
            case 'tags':
                $sql .= " AND t.tag = :search";
                break;
            default: // 'all'
                $sql .= " AND (
                    n.title LIKE :search OR 
                    n.content LIKE :search OR 
                    t.tag LIKE :search
                )";
        }
        
        $sql .= " GROUP BY n.noteid ORDER BY n.updated_at DESC";
        
        $stmt = $this->prepare($sql);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        
        // For content and title search, wrap in wildcards
        if ($type !== 'tags') {
            $stmt->bindValue(':search', '%' . $query . '%', SQLITE3_TEXT);
        } else {
            $stmt->bindValue(':search', $this->sanitizeTag($query), SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $notes = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Get a preview of the content
            $content = $row['content'];
            if ($type === 'content') {
                // Try to get content around the matched term
                $pos = stripos($content, $query);
                if ($pos !== false) {
                    $start = max(0, $pos - 50);
                    $length = strlen($query) + 100;
                    $preview = substr($content, $start, $length);
                    // Add ellipsis if we're not at the start/end
                    if ($start > 0) $preview = '...' . $preview;
                    if ($start + $length < strlen($content)) $preview .= '...';
                    $row['preview'] = $preview;
                } else {
                    $row['preview'] = substr($content, 0, 100) . '...';
                }
            } else {
                $row['preview'] = substr($content, 0, 100) . '...';
            }
            
            // Parse tags into array
            $row['tags'] = $row['tags'] ? explode(',', $row['tags']) : [];
            
            $notes[] = $row;
        }
        
        return $notes;
    }

    // Tag Operations
    private function sanitizeTag($tag) {
        // Remove any special characters except spaces, letters, numbers, and hyphens
        $tag = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $tag);
        // Convert multiple spaces to single space
        $tag = preg_replace('/\s+/', ' ', $tag);
        // Trim spaces and hyphens from ends
        $tag = trim($tag, " -");
        // Convert to lowercase for consistency
        return mb_strtolower($tag);
    }

    private function updateNoteTags($noteid, $content) {
        // Delete existing tags
        $stmt = $this->prepare("DELETE FROM tags WHERE noteid = :noteid");
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $stmt->execute();

        // Extract tags from YAML frontmatter
        if (preg_match('/^---\s*\n(.*?)\n---/s', $content, $frontMatter)) {
            $yaml = $frontMatter[1];
            if (preg_match('/^tags:\s*(.+)$/m', $yaml, $tagsMatch)) {
                // Split tags by comma
                $tags = array_map('trim', explode(',', $tagsMatch[1]));
                
                // Process tags
                $validTags = [];
                foreach ($tags as $tag) {
                    if (empty($tag)) continue;
                    
                    // Sanitize and validate tag
                    $tag = $this->sanitizeTag($tag);
                    
                    // Skip if tag is empty after sanitization or too long
                    if (empty($tag) || mb_strlen($tag) > 50) continue;
                    
                    // Add to valid tags (using tag as key to remove duplicates)
                    $validTags[$tag] = true;
                }
                
                // Insert unique valid tags
                if (!empty($validTags)) {
                    $stmt = $this->prepare("
                        INSERT INTO tags (noteid, tag)
                        VALUES (:noteid, :tag)
                    ");
                    
                    foreach (array_keys($validTags) as $tag) {
                        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
                        $stmt->bindValue(':tag', $tag, SQLITE3_TEXT);
                        $stmt->execute();
                    }
                }
            }
        }
    }

    public function addTag($noteid, $tag) {
        $tag = $this->sanitizeTag($tag);
        if (empty($tag) || mb_strlen($tag) > 50) {
            return false;
        }
        
        $stmt = $this->prepare("
            INSERT INTO tags (noteid, tag)
            VALUES (:noteid, :tag)
        ");
        
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $stmt->bindValue(':tag', $tag, SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function searchByTag($userid, $tag) {
        $stmt = $this->prepare("
            SELECT DISTINCT n.* 
            FROM notes n
            JOIN tags t ON n.noteid = t.noteid
            WHERE n.userid = :userid AND t.tag = :tag
            ORDER BY n.updated_at DESC
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':tag', strtolower($tag), SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $notes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $notes[] = $row;
        }
        return $notes;
    }

    public function getNoteTags($noteid) {
        $stmt = $this->prepare("
            SELECT DISTINCT tag
            FROM tags
            WHERE noteid = :noteid
            ORDER BY tag ASC
        ");
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $tags = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tags[] = $row['tag'];
        }
        return $tags;
    }

    // Recent modifications
    private function updateRecentModification($userid, $noteid) {
        // First check if the note exists
        $checkStmt = $this->prepare("
            SELECT 1 FROM notes 
            WHERE noteid = :noteid AND userid = :userid
        ");
        $checkStmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $checkStmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $result = $checkStmt->execute()->fetchArray();
        
        if (!$result) {
            return false; // Note doesn't exist or doesn't belong to user
        }
        
        $stmt = $this->prepare("
            INSERT OR REPLACE INTO recent_modifications (userid, noteid)
            VALUES (:userid, :noteid)
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getRecentModifiedFiles($userid, $limit = 10) {
        $stmt = $this->prepare("
            SELECT n.noteid, n.title, 
                   substr(trim(n.content), 1, 200) as preview,
                   rm.modified_at
            FROM recent_modifications rm
            JOIN notes n ON rm.noteid = n.noteid
            WHERE rm.userid = :userid
            ORDER BY rm.modified_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $recentFiles = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Clean up the preview text
            $preview = $row['preview'];
            
            // Remove markdown code blocks
            $preview = preg_replace('/```[\s\S]*?```/m', '', $preview);
            $preview = preg_replace('/`[^`]*`/', '', $preview);
            
            // Remove HTML tags
            $preview = strip_tags($preview);
            
            // Clean up whitespace and special chars
            $preview = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $preview);
            $preview = preg_replace('/\s+/', ' ', $preview);
            $preview = trim($preview);
            
            // Get first line or first 100 chars
            if (strpos($preview, "\n") !== false) {
                $preview = substr($preview, 0, strpos($preview, "\n"));
            }
            
            // Ensure it's not too long and add ellipsis if needed
            if (mb_strlen($preview) > 100) {
                $preview = mb_substr($preview, 0, 97, 'UTF-8') . '...';
            }
            
            $recentFiles[] = [
                'noteid' => (int)$row['noteid'],
                'title' => $row['title'],
                'modified_at' => $row['modified_at'],
                'preview' => $preview
            ];
        }
        return $recentFiles;
    }

    // Logging Operations
    public function logMessage($message, $userid = null) {
        $stmt = $this->prepare("
            INSERT INTO system_logs (message, session_id, request_method, request_uri, request_data, userid)
            VALUES (:message, :session_id, :request_method, :request_uri, :request_data, :userid)
        ");
        
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':session_id', session_id(), SQLITE3_TEXT);
        $stmt->bindValue(':request_method', $_SERVER['REQUEST_METHOD'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':request_uri', $_SERVER['REQUEST_URI'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':request_data', file_get_contents('php://input'), SQLITE3_TEXT);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        
        return $stmt->execute();
    }

    public function getRecentLogs($limit = 1000) {
        $stmt = $this->prepare("
            SELECT * FROM system_logs 
            ORDER BY timestamp DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // Export/Import Operations
    
    public function getFoldersByUser($userid) {
        $stmt = $this->prepare("
            SELECT folderid, parent_folderid, name, created_at, updated_at 
            FROM folders 
            WHERE userid = :userid
            ORDER BY parent_folderid, name
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $folders = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $folders[] = $row;
        }
        return $folders;
    }
    
    public function getTagsByUser($userid) {
        $stmt = $this->prepare("
            SELECT t.tagid, t.noteid, t.tag
            FROM tags t
            JOIN notes n ON t.noteid = n.noteid
            WHERE n.userid = :userid
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $tags = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tags[] = $row;
        }
        return $tags;
    }
    
    public function deleteUserData($userid) {
        // Start with tags (they reference notes)
        $stmt = $this->prepare("
            DELETE FROM tags 
            WHERE noteid IN (SELECT noteid FROM notes WHERE userid = :userid)
        ");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->execute();
        
        // Delete notes
        $stmt = $this->prepare("DELETE FROM notes WHERE userid = :userid");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->execute();
        
        // Delete folders
        $stmt = $this->prepare("DELETE FROM folders WHERE userid = :userid");
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        $stmt->execute();
        
        // Reset sequences for the tables we've cleared
        $this->db->exec("UPDATE sqlite_sequence SET seq = 0 WHERE name IN ('folders', 'notes', 'tags')");
    }
    
    public function beginTransaction() {
        if ($this->transactionLevel == 0) {
            $this->db->exec('BEGIN TRANSACTION');
        }
        $this->transactionLevel++;
    }
    
    public function commitTransaction() {
        if ($this->transactionLevel == 1) {
            $this->db->exec('COMMIT');
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }
    
    public function rollbackTransaction() {
        if ($this->transactionLevel == 1) {
            $this->db->exec('ROLLBACK');
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    public function updateFolderParent($folderid, $parent_folderid) {
        $stmt = $this->prepare("
            UPDATE folders 
            SET parent_folderid = :parent_folderid,
                updated_at = datetime('now', :tz)
            WHERE folderid = :folderid
        ");
        
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':parent_folderid', $parent_folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':tz', $this->timezoneOffset, SQLITE3_TEXT);
        
        return $stmt->execute();
    }

    public function renameFolder($folderid, $userid, $newName) {
        $stmt = $this->prepare("
            UPDATE folders 
            SET name = :newName,
                updated_at = datetime('now', :timezone)
            WHERE folderid = :folderid 
            AND userid = :userid
        ");
        
        $stmt->bindValue(':newName', $newName, SQLITE3_TEXT);
        $stmt->bindValue(':timezone', $this->timezoneOffset, SQLITE3_TEXT);
        $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        
        return $stmt->execute();
    }

    public function renameNote($noteid, $userid, $newTitle) {
        $stmt = $this->prepare("
            UPDATE notes 
            SET title = :newTitle,
                updated_at = datetime('now', :timezone)
            WHERE noteid = :noteid 
            AND userid = :userid
        ");
        
        $stmt->bindValue(':newTitle', $newTitle, SQLITE3_TEXT);
        $stmt->bindValue(':timezone', $this->timezoneOffset, SQLITE3_TEXT);
        $stmt->bindValue(':noteid', $noteid, SQLITE3_INTEGER);
        $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            $this->updateRecentModification($userid, $noteid);
            return true;
        }
        return false;
    }

    public function deleteFolder($folderid, $userid) {
        try {
            $stmt = $this->prepare("
                DELETE FROM folders 
                WHERE folderid = :folderid AND userid = :userid
            ");
            $stmt->bindValue(':folderid', $folderid, SQLITE3_INTEGER);
            $stmt->bindValue(':userid', $userid, SQLITE3_INTEGER);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Error deleting folder: " . $e->getMessage());
            return false;
        }
    }
}