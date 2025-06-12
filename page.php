<?php
// Include the main configuration file to make constants available.
require_once 'config.php';

// --- Failsafe Redirect ---
// Get the page name from the URL.
$pageName = isset($_GET['page']) ? trim($_GET['page']) : null;

// If no page name is provided, this script was loaded directly without the
// proper context. Redirect to the default journal page to ensure the
// JavaScript application has a valid starting point.
if (empty($pageName)) {
    $default_page_name = date('Y-m-d');
    $redirect_url = 'page.php?page=' . urlencode($default_page_name);
    header('Location: ' . $redirect_url, true, 302);
    exit;
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
    
    <script>
        // Pass server-side configuration to JavaScript
        window.APP_CONFIG = {
            RENDER_INTERNAL_PROPERTIES: <?php echo json_encode($renderInternal); ?>,
            SHOW_INTERNAL_PROPERTIES_IN_EDIT_MODE: <?php echo json_encode($showInternalInEdit); ?>
        };
    </script>
</head>
<body>
    <div class="app-container">
        <!-- Left Sidebar -->
        <div id="left-sidebar-outer">
            <button id="toggle-left-sidebar-btn" class="sidebar-toggle-btn left-toggle">☰</button>
            <div id="left-sidebar" class="sidebar left-sidebar">
                <div class="sidebar-content">
                    <div class="app-header">
                        <a href="/" id="app-title" class="app-title">notd</a>
                    </div>
                    <div class="sidebar-section calendar-section">
                        <h4>Calendar</h4>
                        <div id="calendar-widget" class="calendar-widget">
                            <div class="calendar-header">
                                <button id="prev-month-btn" class="arrow-btn"><i data-feather="chevron-left"></i></button>
                                <span id="current-month-year" class="month-year-display"></span>
                                <button id="next-month-btn" class="arrow-btn"><i data-feather="chevron-right"></i></button>
                            </div>
                            <div class="calendar-grid calendar-weekdays">
                                <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                            </div>
                            <div id="calendar-days-grid" class="calendar-grid calendar-days"></div>
                        </div>
                    </div>
                    <div class="search-section">
                        <input type="text" id="global-search-input" placeholder="Search..." class="search-input">
                        <div id="search-results" class="search-results"></div>
                    </div>
                    <div class="recent-pages">
                        <h3>Recent</h3>
                        <div id="page-list"></div>
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
            <div id="note-focus-breadcrumbs-container"></div>
            <div id="notes-container" class="outliner">
                <!-- Notes will be rendered here by JavaScript -->
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
            <button id="toggle-right-sidebar-btn" class="sidebar-toggle-btn right-toggle">☰</button>
            <div id="right-sidebar" class="sidebar right-sidebar">
                <div class="sidebar-content">
                    <div class="sidebar-section">
                        <h4>Favorites</h4>
                        <div id="favorites-container"></div>
                    </div>
                    <div class="sidebar-section backlinks-section">
                        <h4>Backlinks</h4>
                        <div id="backlinks-container"></div>
                    </div>
                    <div class="sidebar-section extensions-section">
                        <h4>Extensions</h4>
                        <div id="extension-icons-container"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Save Status Indicator -->
        <div id="save-status-indicator" title="All changes saved"></div>
    </div>

    <!-- Password Modal for Encrypted Pages -->
    <div id="password-modal" class="modal-container" style="display: none;">
        <div class="modal-content">
            <h3>Encrypted Page</h3>
            <p>This page is encrypted. Please enter the password to view it.</p>
            <input type="password" id="password-input" placeholder="Password">
            <div class="modal-actions">
                <button id="password-submit">Decrypt</button>
                <button id="password-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Page Properties Modal -->
    <div id="page-properties-modal" class="modal-container" style="display: none;">
        <div class="modal-content">
            <div class="generic-modal-header">
                <h2 id="page-properties-modal-title" class="generic-modal-title">Page Properties</h2>
                <button id="page-properties-modal-close" class="modal-close-x" aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <div id="page-properties-list" class="page-properties-list"></div>
            <div class="generic-modal-actions">
                <button id="add-page-property-btn" class="button">+ Add Property</button>
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

    <!-- Scripts -->
    <script src="assets/libs/sjcl.js"></script>
    <script type="module" src="assets/js/app.js"></script>
</body>
</html>