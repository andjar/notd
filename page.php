<?php
require_once 'config.php';
require_once 'api/db_connect.php';

// Get page ID from URL
$pageId = isset($_GET['id']) ? (int)$_GET['id'] : null;

// If no page ID is provided, redirect to index
if (!$pageId) {
    header('Location: /');
    exit;
}

// Get page data from database
try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
    $stmt->execute([$pageId]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$page) {
        header('Location: /');
        exit;
    }
} catch (Exception $e) {
    error_log("Error loading page: " . $e->getMessage());
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page['title']); ?> - notd</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>��</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
    <?php include 'assets/css/theme_loader.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/icons.css">
    <script src="https://unpkg.com/feather-icons"></script>
    <script>
        // Pass initial page data to JavaScript
        window.initialPageData = <?php echo json_encode($page); ?>;
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

                    <!-- Global Search -->
                    <div class="search-section">
                        <input type="text" id="global-search-input" placeholder="Search notes..." class="search-input">
                        <div id="search-results" class="search-results">
                            <!-- Search results will be populated by JavaScript -->
                        </div>
                    </div>

                    <div class="recent-pages">
                        <h3>Recent Pages</h3>
                        <div id="page-list">
                            <!-- Pages will be populated by JavaScript -->
                        </div>
                    </div>

                    <div class="sidebar-footer">
                        <button id="open-page-search-modal-btn" class="action-button full-width-button">
                            <span class="icon icon-search"></span>&nbsp;
                            Search or Create Page
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div id="main-content" class="main-content">
            <div class="page-header">
                <div id="current-page-title-container" class="page-title-container">
                    <h1 id="current-page-title">Loading...</h1>
                    <i data-feather="settings" class="page-title-gear" id="page-properties-gear"></i>
                </div>
            </div>

            <div id="page-title"></div>
            <div id="page-properties"></div>
            <div id="breadcrumbs-container">
                <!-- Breadcrumb navigation will be populated by JavaScript -->
            </div>
            <div id="notes-container" class="outliner">
                <!-- Notes will be rendered here -->
            </div>

            <button id="add-root-note-btn" class="action-button round-button">
                <span class="icon icon-plus"></span>
            </button>
        </div>

        <div id="save-status-indicator" title="All changes saved">
            <!-- Icon will be injected by JS -->
        </div>

        <!-- Right Sidebar -->
        <div id="right-sidebar-outer">
            <button id="toggle-right-sidebar-btn" class="sidebar-toggle-btn right-toggle">☰</button>
            <div id="right-sidebar" class="sidebar right-sidebar">
                <div class="sidebar-content">
                    <div class="sidebar-header">
                        <h3>Page Info</h3>
                    </div>
                    
                    <div class="backlinks-section">
                        <h4>Backlinks</h4>
                        <div id="backlinks-container">
                            <!-- Backlinks will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Page Properties Modal -->
    <div id="page-properties-modal" class="generic-modal">
        <div class="generic-modal-content">
            <div class="generic-modal-header">
                <h2 id="page-properties-modal-title" class="generic-modal-title">Page Properties</h2>
                <i data-feather="x" class="page-properties-modal-close" id="page-properties-modal-close"></i>
            </div>
            <div id="page-properties-list" class="page-properties-list"></div>
            <div class="generic-modal-actions">
                <button class="button add-property-btn" id="add-page-property-btn">+ Add Property</button>
            </div>
        </div>
    </div>

    <!-- Page Search Modal -->
    <div id="page-search-modal" class="generic-modal">
        <div class="generic-modal-content page-search-modal-content">
            <h3 id="page-search-modal-title">Search or Create Page</h3>
            <input type="text" id="page-search-modal-input" class="generic-modal-input-field" placeholder="Type to search or create...">
            <ul id="page-search-modal-results" class="page-search-results-list">
                <!-- Results will be populated here by JavaScript -->
            </ul>
            <div class="generic-modal-actions">
                <button id="page-search-modal-cancel" class="button secondary-button">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Generic Input Modal -->
    <div id="generic-input-modal" class="generic-modal">
        <div class="generic-modal-content">
            <h2 id="generic-input-modal-title" class="generic-modal-title">Input Required</h2>
            <input type="text" id="generic-input-modal-input" class="generic-modal-input-field">
            <div class="generic-modal-actions">
                <button id="generic-input-modal-cancel" class="button secondary-button">Cancel</button>
                <button id="generic-input-modal-ok" class="button primary-button">OK</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script type="module" src="assets/js/api_client.js"></script>
    <script type="module" src="assets/js/ui.js"></script>
    <script src="assets/js/templates.js"></script>
    <script type="module" src="assets/js/app.js"></script>
</body>
</html> 