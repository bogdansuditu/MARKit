// Global variables and state
let markdownEditor;
let markdownPreview;
let currentPath = '';
let currentFolderId = localStorage.getItem('currentFolderId') || null; // Don't default to 1
const documentState = {
    currentFilePath: null,
    hasChanges: false,
    lastSavedContent: '',
    lastSavedTitle: '', // Add last saved title
    isNewDocument: true,
    currentNoteId: localStorage.getItem('currentNoteId') || null
};

let isEditorScrolling = false;
let isPreviewScrolling = false;
let scrollTimeout;

// Server's time and timezone info
let SERVER_TIME = new Date();
let SERVER_TIMEZONE = 'UTC'; // Will be updated from server response

// Update server time from response
function updateServerTime(response) {
    if (response && response.server_time) {
        SERVER_TIME = new Date(response.server_time);
        // Extract timezone from server time string if available
        const tzMatch = response.server_time.match(/([+-]\d{2}:?\d{2}|Z)$/);
        if (tzMatch) {
            SERVER_TIMEZONE = tzMatch[0];
        }
    }
}

// Function to update preview
function updatePreview() {
    const markdownText = markdownEditor.getValue();
    
    // Store current scroll position of preview
    const previewScrollInfo = {
        top: markdownPreview.scrollTop,
        height: markdownPreview.scrollHeight
    };
    
    // Remove the entire tags section (including the tags line) from the preview
    const contentWithoutTags = markdownText.replace(/^---\ntags:[\s\S]*?\n---([\r\n]*)?/, '');
    
    markdownPreview.innerHTML = marked.parse(contentWithoutTags);
    
    // If preview height changed, adjust scroll position proportionally
    if (previewScrollInfo.height > 0) {
        const ratio = previewScrollInfo.top / previewScrollInfo.height;
        markdownPreview.scrollTop = ratio * markdownPreview.scrollHeight;
    }
}

// // Function to sync scroll positions
// function syncScroll(source, target, sourceIsEditor = true) {
//     // Clear any pending scroll timeout
//     if (scrollTimeout) {
//         clearTimeout(scrollTimeout);
//     }

    // Get source scroll info
    const sourceInfo = sourceIsEditor ? source.getScrollInfo() : {
        top: source.scrollTop,
        height: source.scrollHeight - source.clientHeight,
        clientHeight: source.clientHeight,
        scrollHeight: source.scrollHeight
    };

    // Get target scroll info
    const targetInfo = sourceIsEditor ? {
        height: target.scrollHeight - target.clientHeight,
        clientHeight: target.clientHeight,
        scrollHeight: target.scrollHeight
    } : target.getScrollInfo();

    // Calculate scroll ratios
    const sourceRatio = sourceInfo.top / Math.max(1, sourceInfo.height);
    const threshold = 20; // pixels from top/bottom to consider as boundary

    // Determine if we're at boundaries
    const isAtTop = sourceInfo.top <= threshold;
    const isAtBottom = sourceInfo.top >= (sourceInfo.height - threshold);

    // Prevent scroll feedback loops
    if ((sourceIsEditor && isPreviewScrolling) || (!sourceIsEditor && isEditorScrolling)) {
        return;
    }

    // Set scroll lock
    if (sourceIsEditor) {
        isEditorScrolling = true;
    } else {
        isPreviewScrolling = true;
    }

    // Perform the scroll
    try {
        if (isAtTop) {
            // Scroll to top
            if (sourceIsEditor) {
                target.scrollTop = 0;
            } else {
                target.scrollTo(0, 0);
            }
        } else if (isAtBottom) {
            // Scroll to bottom
            if (sourceIsEditor) {
                target.scrollTop = targetInfo.scrollHeight - targetInfo.clientHeight;
            } else {
                target.scrollTo(0, targetInfo.height);
            }
        } else {
            // Proportional scroll
            if (sourceIsEditor) {
                target.scrollTop = sourceRatio * targetInfo.height;
            } else {
                target.scrollTo(0, sourceRatio * targetInfo.height);
            }
        }
    } finally {
        // Release scroll lock after a short delay
        scrollTimeout = setTimeout(() => {
            if (sourceIsEditor) {
                isEditorScrolling = false;
            } else {
                isPreviewScrolling = false;
            }
        }, 100);
    }
}

// Function to check if content should be saved
function shouldSave(content) {
    return content.trim().length > 0;
}

// Function to extract title from content
function extractTitle(content) {
    if (!content.trim()) return null;
    
    let title;
    
    // Try to find the first header
    const headerMatch = content.match(/^#\s+(.+)$/m);
    if (headerMatch) {
        title = headerMatch[1].trim();
    } else {
        // If no header, use first few words (max 5)
        const words = content.trim().split(/\s+/).slice(0, 5);
        title = words.join(' ');
    }
    
    // Clean up special characters, preserving alphanumeric chars, spaces, and basic punctuation
    title = title.replace(/[^a-zA-Z0-9\s-_.]/g, '')  // Keep only alphanumeric, spaces, hyphens, dots
               .replace(/\s+/g, ' ')                  // Normalize spaces
               .trim();                               // Trim any leading/trailing spaces
    
    return title || 'untitled';  // Fallback to 'untitled' if title is empty after cleanup
}

// Function to debounce
function debounce(func, wait) {
    let timeout;
    return function() {
        const context = this;
        const args = arguments;
        const later = function() {
            timeout = null;
            func.apply(context, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Function to prompt for saving changes
function promptSaveChanges() {
    return new Promise((resolve) => {
        const content = markdownEditor.getValue();
        
        // Don't prompt if it's a new empty document
        if (documentState.isNewDocument && !shouldSave(content)) {
            resolve('discard');
            return;
        }
        
        Swal.fire({
            title: 'Save changes?',
            text: 'Your changes will be lost if you don\'t save them.',
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Save',
            denyButtonText: 'Don\'t save',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                saveDocument()
                    .then(() => resolve('saved'))
                    .catch(() => resolve('cancel'));
            } else if (result.isDenied) {
                resolve('discard');
            } else {
                resolve('cancel');
            }
        });
    });
}

// Function to create new document
function newDocument() {
    console.log('New document requested'); // Debug
    // If there are changes, prompt to save
    if (documentState.hasChanges && shouldSave(markdownEditor.getValue())) {
        promptSaveChanges().then(result => {
            if (result !== 'cancel') {
                resetDocument();
            }
        });
    } else {
        resetDocument();
    }
    markdownEditor.focus();
}

// Function to reset document state
function resetDocument() {
    console.log('Resetting document state'); // Debug
    switchDocument('', null, '');
    console.log('New document state:', documentState); // Debug
}

// Function to handle content changes
function handleContentChange() {
    const content = markdownEditor.getValue();
    
    // Ensure we're still editing the same document
    if (documentState.currentNoteId !== activeDocumentId) {
        console.warn('Document ID mismatch, ignoring change');
        return;
    }
    
    updatePreview();
    
    // Don't mark empty new documents as changed
    if (documentState.isNewDocument && !content.trim()) {
        documentState.hasChanges = false;
        return;
    }
    
    if (content !== documentState.lastSavedContent) {
        documentState.hasChanges = true;
        
        // Auto-save for existing documents only
        if (!documentState.isNewDocument) {
            autoSave();
        }
    } else {
        documentState.hasChanges = false;
    }
}

// Auto-save debounced function
const autoSave = debounce(() => {
    if (!documentState.isNewDocument && documentState.hasChanges) {
        saveDocument().catch(error => {
            console.error('Auto-save failed:', error);
            // Don't show popup for auto-save failures
        });
    }
}, 1000);

// Function to load file content
function loadFileContent(id) {
    // Don't attempt to load if no ID is provided
    if (!id) {
        return Promise.resolve();
    }
    
    // Check if there are unsaved changes before loading new content
    if (documentState.hasChanges && shouldSave(markdownEditor.getValue())) {
        promptSaveChanges().then(result => {
            if (result !== 'cancel') {
                // Proceed with loading the new file
                performLoadFileContent(id);
            }
        });
    } else {
        // No changes, load directly
        performLoadFileContent(id);
    }
}

// Helper function to perform the actual file loading
function performLoadFileContent(id) {
    // Extract note ID from the full ID string (e.g., 'note_123' -> '123')
    const noteid = id.startsWith('note_') ? id.replace('note_', '') : id;
    
    fetch('file_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'load',
            noteid: noteid
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        
        switchDocument(data.content, noteid, data.title);
        updateServerTime(data);
        
        // Refresh file list to show active state
        loadFiles(currentFolderId);
    })
    .catch(error => {
        console.error('Error loading file:', error);
        Swal.fire('Error', 'Failed to load file: ' + error.message, 'error');
    });
}

// Function to load files into the file list
function loadFiles(folderId = null) {
    // If there are unsaved changes, prompt to save before switching folders
    if (documentState.hasChanges && shouldSave(markdownEditor.getValue())) {
        promptSaveChanges().then(result => {
            if (result !== 'cancel') {
                performLoadFiles(folderId);
            }
        });
    } else {
        performLoadFiles(folderId);
    }
}

// Helper function to perform the actual folder loading
function performLoadFiles(folderId = null) {
    // If no folder ID is provided, use the current folder ID or get the root folder
    if (folderId === null) {
        folderId = currentFolderId || 1;  // Only default to 1 when explicitly loading folders
    }
    
    // Reset document state if we're switching folders and have a new document
    if (folderId !== currentFolderId && documentState.isNewDocument) {
        resetDocument();
    }
    
    currentFolderId = folderId;
    localStorage.setItem('currentFolderId', currentFolderId);
    
    fetch('file_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'list',
            folderid: folderId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        
        const fileList = document.getElementById('file-list');
        fileList.innerHTML = '';
        currentFolderId = folderId;
        localStorage.setItem('currentFolderId', folderId);
        
        // Update back button state
        const backButton = document.getElementById('back-folder');
        const isRootFolder = parseInt(folderId) === 1 || folderId === null;
        backButton.disabled = isRootFolder;
        
        // Sort items by last update time first, then by name
        const sortedItems = data.items.sort((a, b) => {
            // First sort by type to keep folders together
            if (a.type === 'directory' && b.type !== 'directory') return -1;
            if (a.type !== 'directory' && b.type === 'directory') return 1;
            
            // Then sort by last modified time (most recent first)
            const timeA = new Date(a.lastModified).getTime();
            const timeB = new Date(b.lastModified).getTime();
            if (timeA !== timeB) {
                return timeB - timeA;
            }
            
            // Finally sort by name
            return a.name.localeCompare(b.name, undefined, {sensitivity: 'base'});
        });
        
        // Display files and folders
        sortedItems.forEach(item => {
            if (!item || typeof item !== 'object') return;

            const listItem = document.createElement('button');
            listItem.className = 'list-group-item';
            
            // Set data attributes for item identification
            const itemType = item.type === 'directory' ? 'folder' : 'note';
            const itemId = item.id.replace(`${itemType}_`, '');
            listItem.dataset[`${itemType}Id`] = itemId;
            
            if (item.id === 'note_' + documentState.currentNoteId) {
                listItem.classList.add('active');
            }
            
            // Create content wrapper to hold both icon and text content
            const contentWrapper = document.createElement('div');
            contentWrapper.className = 'content-wrapper';
            
            // Create left section with icon
            const iconContainer = document.createElement('div');
            iconContainer.className = 'icon-container';
            const icon = document.createElement('i');
            icon.className = item.type === 'directory' ? 'fas fa-folder' : 'fas fa-file-alt';
            iconContainer.appendChild(icon);
            
            // Create text content container
            const itemContent = document.createElement('div');
            itemContent.className = 'item-content';
            
            const nameSpan = document.createElement('span');
            if (item.type === 'directory') {
                nameSpan.className = 'folder-name';
            } else {
                nameSpan.className = 'file-name';
            }
            nameSpan.textContent = item.name;
            
            const timeSpan = document.createElement('small');
            timeSpan.className = 'file-time';
            timeSpan.textContent = formatTimestamp(item.lastModified);
            
            // Assemble the elements
            contentWrapper.appendChild(iconContainer);
            itemContent.appendChild(nameSpan);
            itemContent.appendChild(timeSpan);
            contentWrapper.appendChild(itemContent);
            listItem.appendChild(contentWrapper);
            
            if (item.type === 'directory') {
                listItem.addEventListener('click', () => {
                    const folderId = item.id.replace('folder_', '');
                    loadFiles(folderId);
                });
            } else if (item.type === 'file') {
                listItem.addEventListener('click', () => {
                    if (!item.id || !item.id.startsWith('note_')) {
                        console.error('Invalid note ID:', item.id);
                        return;
                    }
                    loadFileContent(item.id);
                });
            }
            
            // Add context menu functionality
            listItem.addEventListener('contextmenu', (e) => {
                showContextMenu(e, item);
            });
            
            fileList.appendChild(listItem);
        });
        
        updateServerTime(data);
    })
    .catch(error => {
        console.error('Error loading files:', error);
        Swal.fire('Error', 'Failed to load files: ' + error.message, 'error');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize CodeMirror with theme support
    markdownEditor = CodeMirror.fromTextArea(document.getElementById('markdown-editor'), {
        mode: 'markdown',
        lineNumbers: false,
        lineWrapping: true,
        viewportMargin: Infinity,
        themeVars: currentTheme
    });
    
    markdownPreview = document.getElementById('markdown-preview');
    const toggleFilePanelButton = document.getElementById('toggle-file-panel');
    const themeSwitcherButton = document.getElementById('theme-switcher');
    const toggleLayoutButton = document.getElementById('toggle-layout');
    const createFolderButton = document.getElementById('create-folder');
    const saveButton = document.getElementById('save-note');
    const newFileButton = document.getElementById('new-file');
    const recentFilesBtn = document.getElementById('recent-files-btn');
    const recentFilesDropdown = document.getElementById('recent-files-dropdown');
    const filePanel = document.getElementById('file-panel');
    const exportPdfButton = document.getElementById('export-pdf-btn');
    const exportButton = document.getElementById('export-btn');
    const importButton = document.getElementById('import-btn');
    const logoutButton = document.getElementById('logout-btn');
    const backButton = document.getElementById('back-folder');
    
    // Add back button click handler
    if (backButton) {
        backButton.addEventListener('click', () => {
            if (currentFolderId && parseInt(currentFolderId) !== 1) {
                // Get current folder's parent ID from the list response
                fetch('file_operations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'list',
                        folderid: parseInt(currentFolderId)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.currentFolder && data.currentFolder.parent_folderid) {
                        loadFiles(parseInt(data.currentFolder.parent_folderid));
                    }
                })
                .catch(error => {
                    console.error('Error navigating to parent folder:', error);
                });
            };
            updateStatusBar();
        });
    }
    
    // Set up the input event listener
    markdownEditor.on('change', handleContentChange);
    // markdownEditor.on('scroll', () => {
    //     syncScroll(markdownEditor, markdownPreview, true);
    // });
    // markdownPreview.addEventListener('scroll', () => {
    //     syncScroll(markdownPreview, markdownEditor, false);
    // });
    
    // Add event listeners for export/import
    if (exportButton) {
        exportButton.addEventListener('click', exportNotes);
    }
    if (importButton) {
        importButton.addEventListener('click', importNotes);
    }
    
    // Load both file list and recent files initially
    loadFiles();
    loadRecentModifiedFiles();
    
    // Set initial layout and theme
    updateLayout(currentLayout);
    setTheme(currentTheme);
    
    // Add theme-specific styles to CodeMirror
    const style = document.createElement('style');
    style.textContent = `
        .CodeMirror {
            color: var(--text-color);
            background: var(--bg-color);
            border: 1px solid var(--separator-color);
        }
        .cm-theme-light .CodeMirror {
            --text-color: var(--light-text);
            --bg-color: var(--light-bg);
            --separator-color: var(--light-separator);
        }
        .cm-theme-dark .CodeMirror {
            --text-color: var(--dark-text);
            --bg-color: var(--dark-editor-bg);
            --separator-color: var(--dark-separator);
        }
        .cm-theme-sepia .CodeMirror {
            --text-color: var(--sepia-text);
            --bg-color: var(--sepia-editor);
            --separator-color: var(--sepia-separator);
        }
        .CodeMirror .cm-header { color: var(--accent-color); font-weight: bold; }
        .CodeMirror .cm-quote { color: var(--text-color); font-style: italic; }
        .CodeMirror .cm-link { color: var(--accent-color); }
        .CodeMirror .cm-url { color: var(--accent-color); }
        .CodeMirror .cm-string { color: var(--accent-color); }
        .CodeMirror span.cm-variable-2 { color: var(--accent-color) !important; }
        .CodeMirror span.cm-variable-3 { color: var(--accent-color) !important; }
        .CodeMirror span.cm-variable-4 { color: var(--accent-color) !important; }
        .CodeMirror-gutters {
            border-right: 1px solid var(--separator-color);
            background-color: var(--bg-color);
        }   
        .CodeMirror-linenumber {
            color: var(--text-color);
            opacity: 0.5;
        }
    `;
    document.head.appendChild(style);
    
    // Toggle file panel
    if (toggleFilePanelButton) {
        toggleFilePanelButton.addEventListener('click', () => {
            filePanel.classList.toggle('collapsed');
            
            // Update icon and refresh content
            const icon = toggleFilePanelButton.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-chevron-left');
                icon.classList.toggle('fa-chevron-right');
            }
            
            loadFiles(currentFolderId);
            loadRecentModifiedFiles();
        });
        
        // Close file panel when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && 
                !filePanel.contains(e.target) && 
                !toggleFilePanelButton.contains(e.target) &&
                !filePanel.classList.contains('collapsed')) {
                filePanel.classList.add('collapsed');
                const icon = toggleFilePanelButton.querySelector('i');
                if (icon) {
                    icon.classList.add('fa-chevron-left');
                    icon.classList.remove('fa-chevron-right');
                }
            }
        });
    }
    
    // Layout toggle
    if (toggleLayoutButton) {
        toggleLayoutButton.addEventListener('click', () => {
            const layouts = ['split', 'editor', 'preview'];
            const currentIndex = layouts.indexOf(currentLayout);
            const nextLayout = layouts[(currentIndex + 1) % layouts.length];
            updateLayout(nextLayout);
        });
    }
    
    // Theme switcher
    if (themeSwitcherButton) {
        themeSwitcherButton.addEventListener('click', () => {
            const themes = ['light', 'dark', 'sepia'];
            const currentIndex = themes.indexOf(currentTheme);
            const nextTheme = themes[(currentIndex + 1) % themes.length];
            setTheme(nextTheme);
        });
    }
    
    // Add create folder button listener
    if (createFolderButton) {
        createFolderButton.addEventListener('click', createFolder);
    }
    
    // Add new file button listener
    if (newFileButton) {
        newFileButton.addEventListener('click', newDocument);
    }
    
    if (logoutButton) logoutButton.addEventListener('click', () => window.location.href = 'logout.php');
    
    // Add save button listener
    if (saveButton) {
        saveButton.addEventListener('click', function(e) {
            e.preventDefault();
            if (!documentState.isNewDocument) return;
            
            const content = markdownEditor.getValue();
            if (!content || !content.trim()) return;
            
            const title = extractTitle(content);
            if (!title) return;
            
            saveDocument();
        });
    }
    
    // Add PDF export button listener
    if (exportPdfButton) {
        exportPdfButton.addEventListener('click', () => {
            if (!markdownEditor.getValue().trim()) {
                Swal.fire({
                    title: 'Empty Document',
                    text: 'Cannot export an empty document to PDF',
                    icon: 'warning'
                });
                return;
            }
            exportToPDF();
        });
    }
    
    // Recent files dropdown handlers
    if (recentFilesBtn && recentFilesDropdown) {
        // Toggle dropdown on button click
        recentFilesBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            recentFilesDropdown.classList.toggle('show');
            if (recentFilesDropdown.classList.contains('show')) {
                loadRecentModifiedFiles();
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!recentFilesDropdown.contains(e.target) && !recentFilesBtn.contains(e.target)) {
                recentFilesDropdown.classList.remove('show');
            }
        });
    }
    
    // Initialize search
    initializeSearch();
    
    // First load the current folder
    loadFiles();
    
    // Then load the last opened note if any
    const lastNoteId = localStorage.getItem('currentNoteId');
    if (lastNoteId) {
        loadFileContent(lastNoteId);
    }
    
    // Add reset app functionality
    document.getElementById('reset-app-btn').addEventListener('click', function() {
        Swal.fire({
            title: 'Reset Application?',
            text: 'This action will delete all your notes and folders. This action is irreversible!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, reset everything',
            cancelButtonText: 'No, keep my data',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
        }).then((result) => {
            if (result.isConfirmed) {
                // Call the reset endpoint
                fetch('file_operations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'resetApp'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    
                    // Clear editor state
                    markdownEditor.setValue('');
                    documentState.currentNoteId = null;
                    documentState.lastSavedContent = '';
                    
                    Swal.fire({
                        title: 'Reset Complete',
                        text: 'The application has been reset successfully.',
                        icon: 'success',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Load root folder and refresh UI
                        loadFiles(1); // Root folder should be ID 1
                        loadRecentModifiedFiles();
                    });
                })
                .catch(error => {
                    console.error('Error resetting app:', error);
                    Swal.fire('Error', 'Failed to reset application: ' + error.message, 'error');
                });
            }
        });
    });
});

// Update SweetAlert2 default options for consistent styling
const swalConfig = {
    customClass: {
        popup: 'themed-swal'
    },
    buttonsStyling: true,
    showClass: {
        popup: 'animate__animated animate__fadeIn animate__faster'
    },
    hideClass: {
        popup: 'animate__animated animate__fadeOut animate__faster'
    }
};

// Apply these defaults to all SweetAlert2 dialogs
Swal = Swal.mixin(swalConfig);

// Export/Import functions

function exportNotes() {
    fetch('file_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'exportNotes'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        
        // Create blob and download
        const jsonStr = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonStr], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `notes_export_${formatDate(new Date())}.json`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    })
    .catch(error => {
        Swal.fire({
            title: 'Export Failed',
            text: error.message,
            icon: 'error'
        });
    });
}

function importNotes() {
    // Create file input
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json';
    
    input.onchange = function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Confirm import
        Swal.fire({
            title: 'Import Notes',
            text: 'This will replace all your current notes, folders, and tags. Are you sure you want to continue?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, import',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const jsonData = JSON.parse(e.target.result);
                        
                        fetch('file_operations.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'importNotes',
                                jsonData: jsonData
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) throw new Error(data.error);
                            
                            Swal.fire({
                                title: 'Import Successful',
                                text: data.message,
                                icon: 'success'
                            }).then(() => {
                                // Reload the file list
                                loadFiles();
                                // Clear the editor
                                resetDocument();
                            });
                        })
                        .catch(error => {
                            Swal.fire({
                                title: 'Import Failed',
                                text: error.message,
                                icon: 'error'
                            });
                        });
                    } catch (error) {
                        Swal.fire({
                            title: 'Invalid File',
                            text: 'The selected file is not a valid JSON export file.',
                            icon: 'error'
                        });
                    }
                };
                reader.readAsText(file);
            }
        });
    };
    
    input.click();
}

// Helper function for export filename
function formatDate(date) {
    return date.toISOString().split('T')[0];
}

// Function to delete item (file or folder)
function deleteItem(item) {
    const message = item.type === 'directory' 
        ? 'This will delete the folder and all its contents.'
        : 'This will delete the file permanently.';
        
    Swal.fire({
        title: 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            // Extract the ID based on item type
            const id = item.id.split('_')[1];
            const requestData = {
                action: item.type === 'directory' ? 'deleteFolder' : 'delete',
                folderid: item.type === 'directory' ? id : null,
                noteid: item.type === 'file' ? id : null
            };
            
            fetch('file_operations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                
                // If we deleted the current file, create new document
                if (item.type === 'file' && 
                    item.id === 'note_' + documentState.currentNoteId) {
                    resetDocument();
                }
                
                loadFiles(currentFolderId);
                Swal.fire('Deleted!', 'The item has been deleted.', 'success');
            })
            .catch(error => {
                Swal.fire('Error', 'Failed to delete: ' + error.message, 'error');
            });
        }
    });
}

// Context menu functionality
let contextMenu = null;

// Function to create context menu
function createContextMenu() {
    if (contextMenu) return;
    
    contextMenu = document.createElement('div');
    contextMenu.className = 'dropdown-menu context-menu';
    contextMenu.style.display = 'none';
    document.body.appendChild(contextMenu);
    
    // Close context menu when clicking outside
    document.addEventListener('click', (e) => {
        if (!contextMenu.contains(e.target)) {
            hideContextMenu();
        }
    });
    
    // Close context menu on scroll
    document.addEventListener('scroll', hideContextMenu);
}

// Function to show context menu
function showContextMenu(e, item) {
    e.preventDefault();
    if (!contextMenu) createContextMenu();
    
    // Position the menu at click location
    contextMenu.style.left = `${e.pageX}px`;
    contextMenu.style.top = `${e.pageY}px`;
    
    // Clear previous menu items
    contextMenu.innerHTML = '';
    
    // Create menu items
    const menuItems = [
        {
            label: 'Delete',
            icon: 'fa-trash',
            action: () => deleteItem(item)
        },
        {
            label: 'Rename',
            icon: 'fa-edit',
            action: () => handleRename(
                item.id.replace(item.type === 'directory' ? 'folder_' : 'note_', ''),
                item.type === 'directory' ? 'folder' : 'note'
            )
        },
        {
            label: 'Move',
            icon: 'fa-folder-open',
            action: () => moveNote(item.id.replace('note_', ''), currentFolderId)
        }
    ];
    
    menuItems.forEach(menuItem => {
        const itemElement = document.createElement('button');
        itemElement.className = 'menu-item';
        itemElement.innerHTML = `<i class="fas ${menuItem.icon}"></i> ${menuItem.label}`;
        itemElement.addEventListener('click', () => {
            menuItem.action();
            hideContextMenu();
        });
        contextMenu.appendChild(itemElement);
    });
    
    // Show the menu
    contextMenu.style.display = 'block';
}

// Function to hide context menu
function hideContextMenu() {
    if (contextMenu) {
        contextMenu.style.display = 'none';
    }
}

// Function to rename item
async function handleRename(id, type) {
    const item = document.querySelector(`[data-${type}-id="${id}"]`);
    if (!item) return;
    
    const currentName = type === 'folder' ? 
        item.querySelector('.folder-name').textContent : 
        item.querySelector('.file-name').textContent;
    
    try {
        const { value: newName } = await Swal.fire({
            title: `Rename ${type}`,
            input: 'text',
            inputValue: currentName,
            inputLabel: 'New name',
            showCancelButton: true,
            inputValidator: (value) => {
                if (!value.trim()) {
                    return 'Name cannot be empty';
                }
            }
        });
        
        if (newName && newName !== currentName) {
            const response = await fetch('file_operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'rename',
                    id: id,
                    type: type,
                    newName: newName
                })
            });
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Update the UI
            if (type === 'folder') {
                item.querySelector('.folder-name').textContent = newName;
            } else {
                item.querySelector('.file-name').textContent = newName;
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Renamed successfully',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    } catch (error) {
        console.error('Rename error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to rename item',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    }
}

// Function to move note
function moveNote(noteId, currentFolderId) {
    fetch('file_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'getAllFoldersFlat' })
    })
    .then(response => response.json())
    .then(data => {
        let selectedFolderId = null;
        const folderTreeHtml = buildFolderTreeHtml(data.folders, currentFolderId);
        
        Swal.fire({
            title: 'Move Note',
            html: `
                <div class="move-note-dialog">
                    <div class="folder-tree-scroll">
                        ${folderTreeHtml}
                    </div>
                    <div class="selected-folder-info">
                        <span>Selected folder: </span>
                        <span class="selected-folder-name">None</span>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Move',
            cancelButtonText: 'Cancel',
            didOpen: () => {
                // Add click handlers for folder items
                document.querySelectorAll('.list-group-item:not(.disabled)').forEach(item => {
                    item.addEventListener('click', () => {
                        // Remove previous selection
                        document.querySelectorAll('.list-group-item').forEach(i => i.classList.remove('selected'));
                        // Add selection to clicked item
                        item.classList.add('selected');
                        // Update selected folder ID
                        selectedFolderId = item.dataset.folderId;
                        // Update selected folder name display
                        const folderName = item.querySelector('.folder-name').textContent;
                        document.querySelector('.selected-folder-name').textContent = folderName;
                    });
                });
            },
            preConfirm: () => {
                if (!selectedFolderId) {
                    Swal.showValidationMessage('Please select a folder');
                    return false;
                }
                return selectedFolderId;
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                // Move the note to selected folder
                fetch('file_operations.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'moveNote',
                        noteid: noteId,
                        targetFolderId: result.value
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Note moved successfully',
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => {
                            // Refresh the current folder view
                            loadFiles(currentFolderId);
                        });
                    } else {
                        throw new Error(data.error || 'Failed to move note');
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message
                    });
                });
            }
        });
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to load folders'
        });
    });
}

// Function to build folder tree HTML
function buildFolderTreeHtml(folders, currentFolderId) {
    let html = '<div class="file-list-container"><ul id="file-list" class="list-group">';
    
    // Create a map of parent folders to their children
    const folderMap = new Map();
    folders.forEach(folder => {
        folderMap.set(folder.folderid, {
            ...folder,
            children: []
        });
    });
    
    // Build parent-child relationships
    folders.forEach(folder => {
        if (folder.parent_folderid && folderMap.has(folder.parent_folderid)) {
            folderMap.get(folder.parent_folderid).children.push(folderMap.get(folder.folderid));
        }
    });
    
    // Get root folders (parent_folderid === null)
    const rootFolders = Array.from(folderMap.values()).filter(folder => 
        folder.parent_folderid === null
    );
    
    function buildFolderItem(folder, level = 0) {
        const isDisabled = folder.folderid === currentFolderId;
        const isChild = folder.parent_folderid !== null;
        
        let itemHtml = `
            <li class="list-group-item ${isDisabled ? 'disabled' : ''}" data-folder-id="${folder.folderid}">
                <div class="content-wrapper">
                    <div class="icon-container" style="padding-left: ${level * 20}px">
                        <i class="fas ${isChild ? 'fa-folder' : 'fa-folder-open'}"></i>
                    </div>
                    <div class="item-content">
                        <span class="folder-name">${folder.name}</span>
                    </div>
                </div>
            </li>
        `;
        
        if (folder.children && folder.children.length > 0) {
            folder.children.forEach(child => {
                itemHtml += buildFolderItem(child, level + 1);
            });
        }
        
        return itemHtml;
    }
    
    rootFolders.forEach(folder => {
        html += buildFolderItem(folder);
    });
    
    html += '</ul></div>';
    return html;
}

// Function to save document
function saveDocument(forcePath = null) {
    return new Promise((resolve, reject) => {
        const content = markdownEditor.getValue();
        
        // Don't save empty documents
        if (!shouldSave(content)) {
            reject(new Error('Cannot save empty document'));
            return;
        }
        
        // Extract title from content or use existing one
        const title = documentState.isNewDocument ? 
            extractTitle(content) : 
            (documentState.currentNoteId ? documentState.lastSavedTitle : extractTitle(content));
        
        if (!title) {
            reject(new Error('Cannot save document without title'));
            return;
        }
        
        fetch('file_operations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save',
                title: title,
                content: content,
                noteid: documentState.currentNoteId,
                folderid: currentFolderId // Add current folder ID
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) throw new Error(data.error);
            
            // Update document state
            documentState.isNewDocument = false;
            documentState.hasChanges = false;
            documentState.lastSavedContent = content;
            documentState.lastSavedTitle = title; // Update last saved title
            documentState.currentNoteId = data.noteid;
            
            // Refresh the file list and recent files
            loadFiles(currentFolderId);
            loadRecentModifiedFiles();
            
            resolve(data);
            updateServerTime(data);
        })
        .catch(error => {
            console.error('Save failed:', error);
            reject(error);
        });
    });
}

// Function to format timestamp
function formatTimestamp(timestamp) {
    if (!timestamp) return '';
    
    try {
        // Ensure timestamp has timezone information
        const timestampWithTZ = timestamp.endsWith('Z') || /[+-]\d{2}:?\d{2}$/.test(timestamp) 
            ? timestamp 
            : timestamp + SERVER_TIMEZONE;
        
        // Parse the timestamp in server's timezone
        const date = new Date(timestampWithTZ);
        if (isNaN(date.getTime())) return '';
        
        const now = new Date(SERVER_TIME);
        
        // Calculate time difference in milliseconds
        const diff = now.getTime() - date.getTime();
        
        // If less than a minute ago
        if (diff < 60 * 1000) {
            return 'just now';
        }
        
        // If less than an hour ago
        if (diff < 60 * 60 * 1000) {
            const minutes = Math.floor(diff / (60 * 1000));
            return `${minutes}m ago`;
        }
        
        // If less than 24 hours ago
        if (diff < 24 * 60 * 60 * 1000) {
            const hours = Math.floor(diff / (60 * 60 * 1000));
            return `${hours}h ago`;
        }
        
        // If this year
        if (date.getFullYear() === now.getFullYear()) {
            return date.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // Otherwise show full date
        return date.toLocaleString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (error) {
        console.error('Error formatting timestamp:', error);
        return timestamp; // Return original timestamp if parsing fails
    }
}

// Function to load recent modified files
async function loadRecentModifiedFiles() {
    try {
        const response = await fetch('file_operations.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                action: 'get_recent_modified'
            }),
            credentials: 'same-origin'
        });

        if (!response.ok) {
            console.error('Response not OK:', response.status, response.statusText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        console.log('Response headers:', [...response.headers.entries()]);
        const contentType = response.headers.get('content-type');
        console.log('Content-Type:', contentType);

        const text = await response.text();
        console.log('Raw response text:', text);
        console.log('Response text length:', text.length);
        
        // Check for BOM and other special characters
        console.log('First few bytes:', text.split('').slice(0, 10).map(c => c.charCodeAt(0)));
        
        let data;
        try {
            data = JSON.parse(text);
            console.log('Parsed data:', data);
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Failed to parse:', text);
            throw new Error(`Failed to parse JSON: ${e.message}`);
        }

        if (!data || typeof data !== 'object') {
            console.error('Invalid data format:', data);
            throw new Error('Invalid response format');
        }

        if (!Array.isArray(data.files)) {
            throw new Error('No files data received');
        }

        const recentFilesList = document.getElementById('recent-files-list');
        if (!recentFilesList) {
            throw new Error('Recent files list element not found');
        }

        recentFilesList.innerHTML = '';
        
        if (data.files.length === 0) {
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'empty-recent-files';
            emptyMessage.textContent = 'No recently modified files';
            recentFilesList.appendChild(emptyMessage);
            return;
        }
        
        data.files.forEach(file => {
            if (!file || typeof file !== 'object') return;

            const fileItem = document.createElement('div');
            fileItem.className = 'recent-file-item';
            
            const mainContent = document.createElement('div');
            mainContent.className = 'recent-file-main';
            
            const fileLink = document.createElement('div');
            fileLink.className = 'recent-file-name';
            fileLink.textContent = file.title || 'Untitled';
            
            const timeSpan = document.createElement('div');
            timeSpan.className = 'recent-file-time';
            const date = new Date(file.modified_at);
            timeSpan.textContent = date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            
            mainContent.appendChild(fileLink);
            mainContent.appendChild(timeSpan);
            fileItem.appendChild(mainContent);
            
            if (file.preview) {
                const preview = document.createElement('div');
                preview.className = 'recent-file-preview';
                preview.textContent = file.preview;
                fileItem.appendChild(preview);
            }
            
            fileItem.addEventListener('click', () => {
                loadFileContent(`note_${file.noteid}`);
                document.getElementById('search-results-dropdown').style.display = 'none';
                document.getElementById('search-input').value = '';
            });
            
            recentFilesList.appendChild(fileItem);
        });
        
        updateServerTime(data);
    } catch (error) {
        console.error('Error loading recent files:', error);
        Swal.fire('Error', error.message, 'error');
    }
}

// Function to export preview as PDF
async function exportToPDF() {
    const previewContent = document.getElementById('markdown-preview');
    const title = extractTitle(markdownEditor.getValue()) || 'Untitled';
    
    // Create a clone of the preview content to avoid modifying the original
    const contentClone = previewContent.cloneNode(true);
    
    // Create a temporary container with PDF theme
    const container = document.createElement('div');
    container.classList.add('pdf-theme');
    container.style.padding = '20px';
    container.style.fontFamily = "'Space Grotesk', -apple-system, BlinkMacSystemFont, sans-serif";
    
    // Remove any theme-specific classes and attributes from the clone
    contentClone.classList.remove('light', 'dark', 'sepia');
    contentClone.removeAttribute('style');
    
    // Clean up all child elements
    const elements = contentClone.getElementsByTagName('*');
    for (let el of elements) {
        // Remove theme-specific classes
        el.classList.remove('light', 'dark', 'sepia');
        // Remove any inline background styles
        el.style.removeProperty('background');
        el.style.removeProperty('background-color');
        el.style.removeProperty('box-shadow');
        el.style.removeProperty('border-radius');
        }

    // Add the cleaned content to the container
    container.appendChild(contentClone);
    
    // Configure PDF options
    const opt = {
        margin: [10, 10],
        filename: `${title}.pdf`,
        html2canvas: { 
            scale: 2,
            useCORS: true,
            logging: false,
            backgroundColor: '#ffffff'
        },
        jsPDF: { 
            unit: 'mm', 
            format: 'a4', 
            orientation: 'portrait'
        }
    };
    
    try {
        // Generate PDF
        await html2pdf().set(opt).from(container).save();
        
        Swal.fire({
            title: 'Success!',
            text: 'PDF has been generated and downloaded',
            icon: 'success'
        });
    } catch (error) {
        console.error('PDF generation failed:', error);
        Swal.fire({
            title: 'Error',
            text: 'Failed to generate PDF: ' + error.message,
            icon: 'error'
        });
    }
}

// Function to create folder
function createFolder() {
    Swal.fire({
        title: 'Create Folder',
        input: 'text',
        inputLabel: 'Folder Name',
        showCancelButton: true,
        inputValidator: (value) => {
            if (!value) return 'Please enter a folder name';
            if (value.includes('/')) return 'Folder name cannot contain "/"';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const folderName = result.value;
            
            fetch('file_operations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'createFolder',
                    path: folderName,
                    parent_id: currentFolderId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                loadFiles(currentFolderId);
                Swal.fire('Success', 'Folder created successfully!', 'success');
            })
            .catch(error => {
                Swal.fire('Error', 'Failed to create folder: ' + error.message, 'error');
            });
        }
    });
}

// Search functionality
let currentSearchType = 'all';
let searchTimeout = null;

function initializeSearch() {
    const searchInput = document.getElementById('search-input');
    const searchTypeBtn = document.getElementById('search-type-button');
    const searchDropdown = document.getElementById('search-dropdown');
    const searchResultsDropdown = document.getElementById('search-results-dropdown');
    
    // Handle search input
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        // Clear previous timeout
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // Set new timeout to prevent too many requests
        searchTimeout = setTimeout(() => {
            if (query.length > 0) {
                performSearch(query);
                searchResultsDropdown.style.display = 'block';
            } else {
                searchResultsDropdown.style.display = 'none';
            }
        }, 300);
    });
    
    // Handle search type button
    searchTypeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        searchDropdown.style.display = searchDropdown.style.display === 'none' ? 'block' : 'none';
    });
    
    // Handle search type selection
    searchDropdown.addEventListener('click', (e) => {
        const button = e.target.closest('.menu-item');
        if (!button) return;
        
        // Update active state
        searchDropdown.querySelectorAll('.menu-item').forEach(item => {
            item.classList.remove('active');
        });
        button.classList.add('active');
        
        // Update search type
        currentSearchType = button.dataset.search;
        
        // Hide dropdown
        searchDropdown.style.display = 'none';
        
        // Trigger search if there's a query
        const query = searchInput.value.trim();
        if (query.length > 0) {
            performSearch(query);
        }
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-container')) {
            searchDropdown.style.display = 'none';
            searchResultsDropdown.style.display = 'none';
        }
    });
}

function performSearch(query) {
    fetch('file_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'search',
            query: query,
            type: currentSearchType
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) throw new Error(data.error);
        if (!data.success) throw new Error('Search failed');
        displaySearchResults(data.results);
    })
    .catch(error => {
        console.error('Search error:', error);
        Swal.fire('Error', 'Failed to search: ' + error.message, 'error');
    });
}

function displaySearchResults(results) {
    const resultsDropdown = document.getElementById('search-results-dropdown');
    resultsDropdown.innerHTML = '';
    
    if (results.length === 0) {
        const noResults = document.createElement('div');
        noResults.className = 'no-results';
        noResults.innerHTML = '<i class="fas fa-search"></i><span>No results found</span>';
        resultsDropdown.appendChild(noResults);
        return;
    }
    
    results.forEach(result => {
        const resultItem = document.createElement('div');
        resultItem.className = 'search-result';
        resultItem.innerHTML = `
            <div class="result-title">
                <i class="fas fa-file-alt"></i>
                ${result.title}
            </div>
            <div class="result-preview">${result.preview}</div>
            ${result.tags && result.tags.length > 0 ? `
                <div class="result-tags">
                    ${result.tags.split ? result.tags.split(',').map(tag => 
                        `<span class="result-tag">${tag.trim()}</span>`
                    ).join('') : ''}
                </div>
            ` : ''}
        `;
        
        resultItem.addEventListener('click', () => {
            loadFileContent(`note_${result.noteid}`);
            document.getElementById('search-results-dropdown').style.display = 'none';
            document.getElementById('search-input').value = '';
        });
        
        resultsDropdown.appendChild(resultItem);
    });
    
    resultsDropdown.style.display = 'block';
}

function clearSearchResults() {
    const resultsDropdown = document.getElementById('search-results-dropdown');
    resultsDropdown.innerHTML = '';
    resultsDropdown.style.display = 'none';
}

// Layout and theme functions
let currentLayout = localStorage.getItem('layout') || 'split';
let currentTheme = localStorage.getItem('theme') || 'light';

// Define custom CodeMirror themes
CodeMirror.defineOption("themeVars", null, function(cm, val, old) {
    if (old && old != CodeMirror.Init) {
        cm.display.wrapper.classList.remove(`cm-theme-${old}`);
    }
    if (val) {
        cm.display.wrapper.classList.add(`cm-theme-${val}`);
    }
});

function updateLayout(newLayout) {
    const editorContainer = document.getElementById('editor-container');
    const editor = document.getElementById('markdown-editor');
    const preview = document.getElementById('markdown-preview');
    
    // Remove all layout classes
    editorContainer.classList.remove('split', 'editor-only', 'preview-only');
    editor.classList.remove('hidden');
    preview.classList.remove('hidden');
    
    // Add appropriate classes based on layout
    switch(newLayout) {
        case 'editor':
            editorContainer.classList.add('editor-only');
            preview.classList.add('hidden');
            break;
        case 'preview':
            editorContainer.classList.add('preview-only');
            editor.classList.add('hidden');
            break;
        default: // split
            editorContainer.classList.add('split');
    }
    
    localStorage.setItem('layout', newLayout);
    currentLayout = newLayout;
    
    // Force preview update when switching layouts
    updatePreview();
}

function updateSwalTheme(theme) {
    const swalStyles = {
        light: {
            background: 'rgba(255, 255, 255, 0.8)',
            color: 'var(--light-text)',
            confirmButtonColor: 'var(--light-accent)',
            cancelButtonColor: 'var(--light-surface)',
            denyButtonColor: 'var(--light-surface)',
            shadow: 'var(--light-shadow)'
        },
        dark: {
            background: 'rgba(37, 37, 37, 0.8)',
            color: 'var(--dark-text)',
            confirmButtonColor: 'var(--dark-accent)',
            cancelButtonColor: 'var(--dark-surface)',
            denyButtonColor: 'var(--dark-surface)',
            shadow: 'var(--dark-shadow)'
        },
        sepia: {
            background: 'rgba(223, 211, 195, 0.8)',
            color: 'var(--sepia-text)',
            confirmButtonColor: 'var(--sepia-accent)',
            cancelButtonColor: 'var(--sepia-surface)',
            denyButtonColor: 'var(--sepia-surface)',
            shadow: 'var(--sepia-shadow)'
        }
    };

    const style = document.createElement('style');
    const currentStyle = swalStyles[theme];
    
    style.textContent = `
        .swal2-container {
            backdrop-filter: blur(10px) !important;
            -webkit-backdrop-filter: blur(10px) !important;
        }
        .swal2-popup {
            background: ${currentStyle.background} !important;
            color: ${currentStyle.color} !important;
            box-shadow: ${currentStyle.shadow} !important;
            border-radius: 12px !important;
        }
        .swal2-title, .swal2-html-container {
            color: ${currentStyle.color} !important;
        }
        .swal2-actions {
            gap: 8px !important;
        }
        .swal2-styled {
            border-radius: 8px !important;
            font-weight: 500 !important;
            padding: 8px 16px !important;
            transition: all 0.2s ease !important;
        }
        .swal2-styled.swal2-confirm {
            background: ${currentStyle.confirmButtonColor} !important;
            color: white !important;
        }
        .swal2-styled.swal2-cancel {
            background: ${currentStyle.cancelButtonColor} !important;
            color: ${currentStyle.color} !important;
        }
        .swal2-styled.swal2-deny {
            background: ${currentStyle.denyButtonColor} !important;
            color: ${currentStyle.color} !important;
        }
        .swal2-styled:focus {
            box-shadow: none !important;
        }
        .swal2-styled:hover {
            transform: translateY(-1px) !important;
            filter: brightness(110%) !important;
        }
        .swal2-timer-progress-bar {
            background: ${currentStyle.confirmButtonColor} !important;
        }
    `;
    
    // Remove any existing SweetAlert2 theme
    const existingStyle = document.getElementById('swal2-theme');
    if (existingStyle) {
        existingStyle.remove();
    }
    
    style.id = 'swal2-theme';
    document.head.appendChild(style);
}

function setTheme(theme) {
    document.body.classList.remove('light', 'dark', 'sepia');
    document.body.classList.add(theme);
    localStorage.setItem('theme', theme);
    currentTheme = theme;
    
    // Update theme switcher icon to show next theme
    const themeSwitcherIcon = document.querySelector('#theme-switcher i');
    if (themeSwitcherIcon) {
        // Show icon for the next theme in the cycle
        const nextTheme = theme === 'light' ? 'dark' : theme === 'dark' ? 'sepia' : 'light';
        themeSwitcherIcon.className = 'fas ' + (nextTheme === 'light' ? 'fa-sun' : nextTheme === 'dark' ? 'fa-moon' : 'fa-book');
    }
    
    // Update CodeMirror theme to match
    if (markdownEditor) {
        markdownEditor.setOption('themeVars', theme);
    }
    
    // Update SweetAlert2 theme
    updateSwalTheme(theme);
}

// Menu functionality
document.getElementById('menu-toggle').addEventListener('click', (e) => {
    e.stopPropagation();
    const dropdown = document.getElementById('menu-dropdown');
    dropdown.classList.toggle('show');
});

// Close menu when clicking outside
document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('menu-dropdown');
    if (!e.target.closest('.menu-container') && dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
    }
});

// Prevent menu from closing when clicking inside
document.getElementById('menu-dropdown').addEventListener('click', (e) => {
    e.stopPropagation();
});

// Add activeDocumentId to track the current document
let activeDocumentId = null;

// Function to safely switch documents
function switchDocument(content, noteId, title) {
    // Step 1: Disable the editor temporarily to prevent change events
    markdownEditor.setOption('readOnly', true);
    
    // Step 2: Clear the editor's state completely
    markdownEditor.setValue('');
    markdownEditor.clearHistory();
    
    // Step 3: Update document state
    activeDocumentId = noteId;
    documentState.currentNoteId = noteId;
    documentState.lastSavedContent = content;
    documentState.lastSavedTitle = title;
    documentState.hasChanges = false;
    documentState.isNewDocument = !noteId;
    
    // Step 4: Set the new content
    markdownEditor.setValue(content || '');
    
    // Step 5: Re-enable the editor
    markdownEditor.setOption('readOnly', false);
    
    // Step 6: Update UI
    updatePreview();
    localStorage.setItem('currentNoteId', noteId);
    updateStatusBar();
}

async function updateStatusBar() {
    const noteId = documentState.currentNoteId;

    try {
        const response = await fetch('file_operations.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                action: 'getNoteStatus',
                noteid: noteId
            })
        });
        
        if (!response.ok) {
            alert('Network response was not ok');
            throw new Error('Network response was not ok');
        }
        const data = await response.json();
        
        if (data.error) {
            console.error('Error getting note status:', data.error);
            return;
        }
        // Update path
        const pathEl = document.getElementById('file-path');
        if (data.path && data.path.length > 0) {
            pathEl.innerHTML = data.path.map((folder, index) => {
                return `<span class="folder-name">${folder.name}</span>${
                    index < data.path.length - 1 ? '<span class="separator">/</span>' : ''
                }`;
            }).join('');
        } else {
            pathEl.innerHTML = '<span class="folder-name">Root</span>';
        }
        
        if (documentState.lastSavedTitle) {
            pathEl.innerHTML += '<span class="separator">/</span>' + documentState.lastSavedTitle;
        }

        // Add word count
        const wordCount = markdownEditor.getValue().trim().split(/\s+/).length;
        const wordCountEl = document.createElement('span');
        wordCountEl.className = 'word-count';
        wordCountEl.innerHTML = `<span class="separator">|</span> (${wordCount} words)`;
        pathEl.appendChild(wordCountEl);

        // Update tags
        const tagsEl = document.getElementById('file-tags');
        if (data.tags && data.tags.length > 0) {
            tagsEl.innerHTML = data.tags.map(tag => 
                `<span class="tag">${tag}</span>`
            ).join('');
        } else {
            tagsEl.innerHTML = '';
        }
    } catch (error) {
        console.error('Error updating status bar:', error);
    }
}