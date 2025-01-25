<?php
include 'auth.php';

// Redirect to login page if not authenticated
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MARKit - A beautiful markdown editor">
    <title>MARKit</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <!-- Styles -->
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="manifest" href="manifest.json">
</head>
<body>
    <div id="app">
        <div id="top-bar">
            <div class="app-brand">
                <img src="mmd_512.png" class="app-icon" alt="MARKit">
                <span id="app-name">MARKit</span>
            </div>
            <div class="toolbar">
            <div class="search-container">
                    <div class="search-input-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="search-input" placeholder="Search notes...">
                        <button id="search-type-button" class="icon-button" title="Search Options">
                            <i class="fas fa-filter"></i>
                        </button>
                    </div>
                    <div id="search-dropdown" class="dropdown-menu" style="display: none;">
                        <button class="menu-item active" data-search="all">
                            <i class="fas fa-search"></i>
                            Search All
                        </button>
                        <button class="menu-item" data-search="title">
                            <i class="fas fa-heading"></i>
                            Search Titles
                        </button>
                        <button class="menu-item" data-search="content">
                            <i class="fas fa-align-left"></i>
                            Search Content
                        </button>
                        <button class="menu-item" data-search="tags">
                            <i class="fas fa-tags"></i>
                            Search Tags
                        </button>
                    </div>
                    <div id="search-results-dropdown" class="dropdown-menu" style="display: none;">
                        <!-- Search results will be populated here -->
                    </div>
                </div>
                <button id="toggle-file-panel" title="Toggle File Panel">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button id="toggle-layout" title="Toggle Layout">
                    <i class="fas fa-columns"></i>
                </button>
                <button id="theme-switcher" title="Switch Theme">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="dropdown-container">
                    <button id="recent-files-btn" title="Recent Files">
                        <i class="fas fa-history"></i>
                    </button>
                    <div id="recent-files-dropdown" class="dropdown-menu">
                        <h3>Recently Modified</h3>
                        <div id="recent-files-list">
                            <!-- Recent files will be populated here by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <!-- Hamburger menu -->
                <div class="menu-container">
                    <button id="menu-toggle" title="Menu">
                        <i class="fa-solid fa-circle-chevron-down"></i>
                    </button>
                    <div id="menu-dropdown" class="dropdown-menu">
                        <button id="export-btn" class="menu-item">
                            <i class="fas fa-file-export"></i>
                            <span>Export Notes</span>
                        </button>
                        <button id="import-btn" class="menu-item">
                            <i class="fas fa-file-import"></i>
                            <span>Import Notes</span>
                        </button>
                        <button id="export-pdf-btn" class="menu-item">
                            <i class="fas fa-file-pdf"></i>
                            <span>Export as PDF</span>
                        </button>
                        <button id="logout-btn" class="menu-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div id="main-content">
            <div id="file-panel">
                <div class="panel-section">
                    <div id="file-toolbar">
                        <button id="back-folder" title="Go Back" disabled>
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <button id="create-note" title="Create New Note">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button id="save-note" title="Save Note">
                            <i class="fas fa-save"></i>
                        </button>
                        <button id="create-folder" title="Create New Folder">
                            <i class="fas fa-folder-plus"></i>
                        </button>
                    </div>
                    <div id="file-list">
                        <!-- Files will be loaded here -->
                    </div>
                </div>
            </div>
            <div id="editor-container">
                <div id="editor-panel">
                    <textarea id="markdown-editor" placeholder="Start writing..."></textarea>
                </div>
                <div id="preview-panel">
                    <div id="markdown-preview"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        // Initialize marked immediately after loading
        marked.use({
            gfm: true,
            breaks: true
        });
        
        // Configure CodeMirror defaults
        CodeMirror.defaults.extraKeys = {
            'Tab': function(cm) {
                cm.replaceSelection('    '); // Insert 4 spaces when Tab is pressed
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/showdown/2.1.0/showdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/markdown/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
    <script src="scripts.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('create-note').addEventListener('click', function() {
                newDocument();
            });
            document.getElementById('logout-btn').addEventListener('click', function() {
                window.location.href='logout.php';
            });
        });
    </script>
</body>
</html>
