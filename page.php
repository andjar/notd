<?php
// Include the main configuration file to make constants available.
require_once 'config.php';

// --- Failsafe Redirect ---
// Get the page name from the URL.
$pageName = isset($_GET['page']) ? trim($_GET['page']) : null;

// If no page name is provided, redirect to default page
if (empty($pageName)) {
    $default_page_name = date('Y-m-d');
    $redirect_url = 'page.php?page=' . urlencode($default_page_name);
    header('Location: ' . $redirect_url, true, 302);
    exit;
}

// --- Journal Page Creation ---
// Only create journal pages for date-based pages that don't exist
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pageName)) {
    try {
        // Connect to SQLite database
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // Check if page exists
        $stmt = $pdo->prepare("SELECT id FROM Pages WHERE name = ?");
        $stmt->execute([$pageName]);
        $pageId = $stmt->fetchColumn();
        
        if (!$pageId) {
            // Create the date-based page
            $pdo->beginTransaction();
            $insertPage = $pdo->prepare("INSERT INTO Pages (name, created_at, updated_at) VALUES (?, datetime('now'), datetime('now'))");
            $insertPage->execute([$pageName]);
            $pageId = $pdo->lastInsertId();
            
            // Add journal type property
            $insertProp = $pdo->prepare("INSERT INTO Properties (page_id, name, value, weight) VALUES (?, 'type', 'journal', 2)");
            $insertProp->execute([$pageId]);
            $pdo->commit();
        }
    } catch (PDOException $e) {
        error_log("Error creating journal page: " . $e->getMessage());
    }
}

// --- Frontend Configuration ---
// Pass relevant backend configuration settings to the frontend JavaScript.
// This uses the PROPERTY_WEIGHTS constant from config.php.
$renderInternal = PROPERTY_WEIGHTS[3]['visible_in_view_mode'] ?? false;
$showInternalInEdit = PROPERTY_WEIGHTS[3]['visible_in_edit_mode'] ?? true;

?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageName); ?> - notd</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
    
    <!-- Fonts and Core Styles -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Theme and App Styles -->
    <?php include 'assets/css/theme_loader.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/icons.css">
    <link rel="stylesheet" href="assets/css/calendar.css">
    
    <!-- Libraries -->
    <script src="assets/libs/feather.min.js"></script>
    <script src="assets/libs/marked.min.js"></script>
    <script src="assets/libs/Sortable.min.js"></script>
    <script type="module" src="assets/js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    
    <script>
         window.APP_CONFIG = window.APP_CONFIG || {};
        // Pass server-side configuration to JavaScript
        window.APP_CONFIG = {
            RENDER_INTERNAL_PROPERTIES: <?php echo json_encode($renderInternal); ?>,
            SHOW_INTERNAL_PROPERTIES_IN_EDIT_MODE: <?php echo json_encode($showInternalInEdit); ?>
        };
    </script>
</head>
<body>
    <div id="splash-screen" 
         x-data="splashScreen()" 
         x-show="show" 
         x-init="init()"
         x-transition:leave="transition ease-in duration-300" 
         x-transition:leave-start="opacity-100" 
         x-transition:leave-end="opacity-0">
        <div class="time-date-container">
            <div id="clock" class="clock" x-text="time"></div>
            <div id="date" class="date" x-text="date"></div>
        </div>
        <div id="splash-background-bubbles-canvas">
            <template x-for="bubble in bubbles" :key="bubble.id">
                <div class="splash-bubble" 
                     :style="`width: ${bubble.size}px; height: ${bubble.size}px; background-color: ${bubble.color}; left: ${bubble.x}px; top: ${bubble.y}px; opacity: ${bubble.opacity}; transform: scale(${bubble.scale});`">
                </div>
            </template>
        </div>
        <div id="splash-orb-container">
            <div id="splash-orb-inner-core">
                <div id="splash-orb-text-container">
                    <p id="splash-orb-text">notd</p>
                </div>
                <div id="splash-orb-perimeter-dots">
                    <template x-for="dot in dots" :key="dot.id">
                        <div class="splash-orb-dot" 
                             :style="`opacity: ${dot.opacity}; transform: translate(${dot.x}px, ${dot.y}px) scale(${dot.scale});`">
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
    
    <div class="app-container" x-data="sidebarComponent()" x-init="init()">
        <!-- Left Sidebar -->
        <div id="left-sidebar-outer">
            <button id="toggle-left-sidebar-btn" class="sidebar-toggle-btn left-toggle" @click="toggleLeft()">
                <i x-feather="leftIcon()"></i>
            </button>
            <div id="left-sidebar" class="sidebar left-sidebar" :class="{ 'collapsed': leftCollapsed }">
                <div class="sidebar-content">
                    <div class="app-header">
                        <a href="/" id="app-title" class="app-title">notd</a>
                    </div>
                    <div class="sidebar-section">
                        <div id="calendar-widget" class="calendar-widget" x-data="calendarComponent()" x-init="init()">
                            <div class="calendar-header">
                                <button id="prev-month-btn" class="arrow-btn" @click="prevMonth()"><i data-feather="chevron-left"></i></button>
                                <span id="current-month-year" class="month-year-display" x-text="monthYear()"></span>
                                <button id="next-month-btn" class="arrow-btn" @click="nextMonth()"><i data-feather="chevron-right"></i></button>
                                <button id="today-btn" class="arrow-btn today-btn" @click="goToday()">Today</button>
                            </div>
                            <div class="calendar-grid calendar-weekdays">
                                <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                            </div>
                            <div id="calendar-days-grid" class="calendar-grid calendar-days">
                                <template x-for="(day,index) in days" :key="index">
                                    <div class="calendar-day" :class="{ 'empty': day.empty, 'today': day.today, 'has-content': day.hasPage }" x-text="day.empty ? '' : day.day" @click="dayClick(day)"></div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="search-section">
                        <input type="text" id="global-search-input" placeholder="Search..." class="search-input">
                        <div id="search-results" class="search-results"></div>
                    </div>
                    <div class="sidebar-section">
                        <div class="recent-pages">
                            <h3>Recent Pages</h3>
                            <div id="page-list"></div>
                        </div>
                    </div>
                    <div class="sidebar-footer">
                        <button id="open-page-search-modal-btn" class="action-button full-width-button">
                            <i data-feather="search"></i> Search or Create Page
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div id="main-content" class="main-content">
            <div class="page-title-container">
                <h1 id="page-title" class="page-title">
                    <!-- Page title and breadcrumbs populated by JS -->
                </h1>
                <div id="page-properties-container" class="page-properties-inline"></div>
            </div>
            <div id="page-content" class="page-content">
                <!-- Page content will be rendered here -->
            </div>
            <div id="note-focus-breadcrumbs-container"></div>
            <div id="notes-container" class="outliner" x-data="{ notes: [] }" x-init="initializeDragAndDrop()">
                <template x-for="note in notes" :key="note.id">
                    <div class="note-item"
                        x-data="noteComponent(note, 0)"
                        :data-note-id="note.id"
                        :style="`--nesting-level: ${nestingLevel}`"
                        :class="{ 'has-children': note.children && note.children.length > 0, 'collapsed': note.collapsed, 'encrypted-note': note.is_encrypted, 'decrypted-note': note.is_encrypted && note.content && !note.content.startsWith('{') }">

                        <div class="note-header-row">
                            <div class="note-controls">
                                <span class="note-collapse-arrow" x-show="note.children && note.children.length > 0" @click="toggleCollapse()" :data-collapsed="note.collapsed ? 'true' : 'false'">
                                    <i data-feather="chevron-right"></i>
                                </span>
                                <span class="note-drag-handle" style="display: none;"><i data-feather="menu"></i></span>
                                <span class="note-bullet" :data-note-id="note.id"></span>
                            </div>
                            <div class="note-content-wrapper">
                                <div class="note-content rendered-mode"
                                    x-ref="contentDiv"
                                    :data-note-id="note.id"
                                    :data-raw-content="note.content"
                                    x-html="parseContent(note.content)"
                                    @click="editNote()"
                                    @blur="isEditing = false"
                                    @input="handleInput($event)"
                                    @paste="handlePaste($event)">
                                </div>
                                <div class="note-attachments"></div>
                            </div>
                        </div>

                        <div class="note-children" :class="{ 'collapsed': note.collapsed }">
                            <template x-for="childNote in note.children" :key="childNote.id">
                                <div class="note-item"
                                    x-data="noteComponent(childNote, $parent.nestingLevel + 1)"
                                    :data-note-id="childNote.id"
                                    :style="`--nesting-level: ${$parent.nestingLevel + 1}`"
                                    :class="{ 'has-children': childNote.children && childNote.children.length > 0, 'collapsed': childNote.collapsed, 'encrypted-note': childNote.is_encrypted, 'decrypted-note': childNote.is_encrypted && childNote.content && !childNote.content.startsWith('{') }">

                                    <div class="note-header-row">
                                        <div class="note-controls">
                                            <span class="note-collapse-arrow" x-show="childNote.children && childNote.children.length > 0" @click="toggleCollapse()" :data-collapsed="childNote.collapsed ? 'true' : 'false'">
                                                <i data-feather="chevron-right"></i>
                                            </span>
                                            <span class="note-drag-handle" style="display: none;"><i data-feather="menu"></i></span>
                                            <span class="note-bullet" :data-note-id="childNote.id"></span>
                                        </div>
                                        <div class="note-content-wrapper">
                                            <div class="note-content rendered-mode"
                                                x-ref="contentDiv"
                                                :data-note-id="childNote.id"
                                                :data-raw-content="childNote.content"
                                                x-html="parseContent(childNote.content)"
                                                @click="editNote()"
                                                @blur="isEditing = false"
                                                @input="handleInput($event)"
                                                @paste="handlePaste($event)">
                                            </div>
                                            <div class="note-attachments"></div>
                                        </div>
                                    </div>

                                    <div class="note-children" :class="{ 'collapsed': childNote.collapsed }">
                                        <!-- Recursive rendering of grand-children -->
                                        <template x-for="grandChildNote in childNote.children" :key="grandChildNote.id">
                                            <div class="note-item"
                                                x-data="noteComponent(grandChildNote, $parent.$parent.nestingLevel + 2)"
                                                :data-note-id="grandChildNote.id"
                                                :style="`--nesting-level: ${$parent.$parent.nestingLevel + 2}`"
                                                :class="{ 'has-children': grandChildNote.children && grandChildNote.children.length > 0, 'collapsed': grandChildNote.collapsed, 'encrypted-note': grandChildNote.is_encrypted, 'decrypted-note': grandChildNote.is_encrypted && grandChildNote.content && !grandChildNote.content.startsWith('{') }">

                                                <div class="note-header-row">
                                                    <div class="note-controls">
                                                        <span class="note-collapse-arrow" x-show="grandChildNote.children && grandChildNote.children.length > 0" @click="toggleCollapse()" :data-collapsed="grandChildNote.collapsed ? 'true' : 'false'">
                                                            <i data-feather="chevron-right"></i>
                                                        </span>
                                                        <span class="note-drag-handle" style="display: none;"><i data-feather="menu"></i></span>
                                                        <span class="note-bullet" :data-note-id="grandChildNote.id"></span>
                                                    </div>
                                                    <div class="note-content-wrapper">
                                                        <div class="note-content rendered-mode"
                                                            x-ref="contentDiv"
                                                            :data-note-id="grandChildNote.id"
                                                            :data-raw-content="grandChildNote.content"
                                                            x-html="parseContent(grandChildNote.content)"
                                                            @click="editNote()"
                                                            @blur="isEditing = false"
                                                            @input="handleInput($event)"
                                                            @paste="handlePaste($event)">
                                                        </div>
                                                        <div class="note-attachments"></div>
                                                    </div>
                                                </div>

                                                <div class="note-children" :class="{ 'collapsed': grandChildNote.collapsed }">
                                                    <!-- Further nested children would go here, following the same pattern -->
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <div id="child-pages-container">
                <!-- Child pages will be rendered here by JavaScript -->
            </div>
            <button id="add-root-note-btn" class="action-button round-button" title="Add new note to page">
                <i data-feather="plus"></i>
            </button>
        </div>

        <!-- Right Sidebar -->
        <div id="right-sidebar-outer">
            <button id="toggle-right-sidebar-btn" class="sidebar-toggle-btn right-toggle" @click="toggleRight()">
                <i x-feather="rightIcon()"></i>
            </button>
            <div id="right-sidebar" class="sidebar right-sidebar" :class="{ 'collapsed': rightCollapsed }">
                <div class="sidebar-content">
                    <div class="sidebar-section">
                        <div class="favorites">
                            <h3>Favorites</h3>
                            <div id="favorites-container"></div>
                        </div>
                    </div>
                    <div class="sidebar-section">
                        <div id="backlinks-container" class="backlinks-sidebar">
                            <h4>Backlinks</h4>
                            <div id="backlinks-list" class="backlinks-list">
                                <!-- Backlinks will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    <div class="sidebar-section">
                        <div id="child-pages-sidebar" class="child-pages-sidebar">
                            <!-- Child pages will be populated by JavaScript -->
                        </div>
                    </div>
                    <div class="sidebar-section extensions-section">
                        <div id="extension-icons-container"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Save Status Indicator -->
        <div id="save-status-indicator" title="All changes saved"></div>
        <button id="toggle-splash-btn" class="action-button round-button absolute-bottom-right" title="Toggle Splash Screen">
            <i data-feather="pause-circle"></i>
        </button>
    </div>

    <!-- Password Modal for Encrypted Pages -->
    <div id="password-modal" class="generic-modal">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 class="generic-modal-title">Encrypted Page</h2>
                <button id="password-modal-close" class="modal-close-x" aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <p>This page is encrypted. Please enter the password to view it.</p>
            <input type="password" id="password-input" placeholder="Password" class="full-width-input">
            <div class="generic-modal-actions">
                <button id="password-submit" class="button">Decrypt</button>
                <button id="password-cancel" class="button button-secondary">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Encryption Password Modal -->
    <div id="encryption-password-modal" class="generic-modal">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 class="generic-modal-title">Set Encryption Password</h2>
                <button id="encryption-modal-close" class="modal-close-x" aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Enter a password to encrypt this page:</p>
                <input type="password" id="new-encryption-password" class="generic-modal-input-field" placeholder="New Password">
                <p>Confirm your password:</p>
                <input type="password" id="confirm-encryption-password" class="generic-modal-input-field" placeholder="Confirm Password">
                <p id="encryption-password-error" class="error-message" style="display: none;"></p>
            </div>
            <div class="generic-modal-actions">
                <button id="confirm-encryption-btn" class="button primary-button">Encrypt</button>
                <button id="cancel-encryption-btn" class="button secondary-button">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Page Properties Modal -->
    <div id="page-properties-modal" class="generic-modal">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 id="page-properties-modal-title" class="generic-modal-title">Page Properties</h2>
                <div class="modal-header-icons">
                    <button id="page-properties-modal-close" class="modal-close-x" aria-label="Close">
                        <i data-feather="x"></i>
                    </button>
                </div>
            </div>
            <div id="page-properties-list" class="page-properties-list"></div>
            <div class="generic-modal-actions">
                <button id="page-encryption-button" class="button" title="Encrypt Page">
                    Encrypt
                </button>
                <button id="save-page-content-btn" class="button">Save</button>
            </div>
        </div>
    </div>

    <div id="page-search-modal" class="generic-modal">
        <div class="generic-modal-content page-search-modal-styling">
            <input type="text" id="page-search-modal-input" class="generic-modal-input-field" placeholder="Type to search or create...">
            <ul id="page-search-modal-results" class="page-search-results-list"></ul>
            <div class="generic-modal-actions">
                <button id="page-search-modal-cancel" class="button secondary-button">Cancel</button>
            </div>
        </div>
    </div>
    
    <div id="image-viewer-modal" class="generic-modal image-viewer">
        <div class="generic-modal-content">
             <button id="image-viewer-modal-close" class="modal-close-x" aria-label="Close">
                <i data-feather="x"></i>
            </button>
            <img id="image-viewer-modal-img" src="" alt="Full size view">
        </div>
    </div>
</body>
</html>