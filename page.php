<?php
require_once 'config.php';
require_once 'api/db_connect.php';

// Get page ID from URL
$pageId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$page = null;

if ($pageId) {
    // Try to get page data from database if ID is provided
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT * FROM Pages WHERE id = ?");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        // If page is not found, $page will be false. We'll let the next block handle it.
    } catch (Exception $e) {
        error_log("Error loading page data for ID {$pageId}: " . $e->getMessage());
        // If there's an error, we'll treat it as page not found and try to create it or redirect.
        $page = null; 
    }
}

// If page ID is not provided, or page was not found by ID (or an error occurred fetching it)
if (!$page) {
    $pageName = isset($_GET['name']) ? trim($_GET['name']) : null;

    if ($pageName && !empty($pageName)) { // Ensure pageName is not empty
        // Attempt to create the page via API
        // Construct the API URL dynamically
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
        $host = $_SERVER['HTTP_HOST'];
        // Correctly determine the base path for the API
        // dirname($_SERVER['PHP_SELF']) might give unexpected results if page.php is in root
        // Assuming api is always at <base_url>/api/v1/pages.php
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']); // SCRIPT_NAME is more reliable for path
        if ($scriptPath === '/' || $scriptPath === '\\') {
            $scriptPath = ''; // Avoid double slashes if script is in root
        }
        $apiUrl = $protocol . "://" . $host . $scriptPath . '/api/v1/pages.php';
        
        $data = json_encode(['name' => $pageName]);
        
        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => $data,
                'ignore_errors' => true // Allows us to read response body on errors
            ],
            // Add SSL context options if your server uses self-signed certificates (for development)
            // 'ssl' => [
            //     'verify_peer' => false,
            //     'verify_peer_name' => false,
            // ],
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($apiUrl, false, $context); // Use @ to suppress warnings, check response === false
        
        $statusCode = null;
        if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0) {
            // Filter out any warning messages that might have been added to $http_response_header
            $statusLine = '';
            foreach ($http_response_header as $headerVal) {
                if (strpos(strtolower($headerVal), 'http/') === 0) {
                    $statusLine = $headerVal;
                    break;
                }
            }
            if (!empty($statusLine)) {
                preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
                if ($match) {
                    $statusCode = (int)$match[1];
                }
            }
        }

        if ($response !== false && $statusCode === 201) {
            $responseData = json_decode($response, true);
            if (isset($responseData['id'])) {
                // Page created successfully, redirect to the new page
                header('Location: page.php?id=' . $responseData['id']);
                exit;
            } else {
                // API returned success but no ID, log error and redirect to index
                error_log("Page creation API call to {$apiUrl} succeeded (201) but no ID was returned. Page name: {$pageName}. Response: " . $response);
                header('Location: /?error=creation_no_id');
                exit;
            }
        } else {
            // Page creation failed or other error
            $phpError = error_get_last();
            $phpErrorMessage = $phpError ? $phpError['message'] : 'No PHP error';
            error_log("Page creation API call to {$apiUrl} failed. Page name: {$pageName}. Status: " . ($statusCode ?? 'Unknown') . ". Response: " . $response . ". PHP Error: " . $phpErrorMessage);
            header('Location: /?error=creation_failed');
            exit;
        }
    } else {
        // No page name provided (or empty after trim), or page ID was initially provided but page not found
        // If $pageId was set, it means the page for that ID was not found.
        // If $pageId was not set, and $pageName was also not set or empty.
        if ($pageId && !$pageName) { // This case means an ID was given, but page not found, and no 'name' param to create
             error_log("Page with ID {$pageId} not found, and no 'name' parameter provided to create a new one.");
        }
        header('Location: /');
        exit;
    }
}

// If we reach here, it means a page with $pageId was found successfully and $page is populated.
// The existing functionality for displaying the page continues.
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
    <script src="assets/libs/feather.min.js"></script>
    <script>
        // Pass initial page data to JavaScript
        window.initialPageData = <?php echo json_encode($page); ?>;
    </script>
    <script>
        // Expose PHP configuration to JavaScript
        window.RENDER_INTERNAL_PROPERTIES = <?php echo defined('RENDER_INTERNAL_PROPERTIES') && RENDER_INTERNAL_PROPERTIES ? 'true' : 'false'; ?>;
        window.INTERNAL_PROPERTIES_VISIBLE_IN_EDIT_MODE = <?php echo defined('INTERNAL_PROPERTIES_VISIBLE_IN_EDIT_MODE') && INTERNAL_PROPERTIES_VISIBLE_IN_EDIT_MODE ? 'true' : 'false'; ?>;
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
                    <i data-feather="settings" class="page-title-gear" id="page-properties-gear" data-preserve-id="true"></i>
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
                        <h3>Favorites</h3>
                    </div>
                    <div id="favorites-container"></div>
                    
                    <div class="backlinks-section">
                        <h4>Backlinks</h4>
                        <div id="backlinks-container">
                            <!-- Backlinks will be populated by JavaScript -->
                        </div>
                    </div>

                    <div class="extensions-section">
                        <h4>Extensions</h4>
                        <div id="extension-icons-container">
                            <!-- Extension icons will be populated by JavaScript -->
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
                <div class="modal-header-icons">
                    <div id="page-modal-encryption-button">
                        <button class="modal-icon-button" id="page-encryption-icon" title="Set page encryption">
                            <i data-feather="key"></i>
                        </button>
                    </div>
                    <div id="page-modal-close-button">
                        <button class="modal-icon-button" id="page-properties-modal-close" aria-label="Close modal">
                            <i data-feather="x"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div id="page-properties-list" class="page-properties-list"></div>
            <div class="generic-modal-actions">
                <button class="button add-property-btn" id="add-page-property-btn">+ Add Property</button>
            </div>
        </div>
    </div>

    <!-- Page Search Modal -->
    <div id="page-search-modal" class="generic-modal">
        <div class="generic-modal-content page-search-modal-styling">
            <div class="generic-modal-header">
                <h2 id="page-search-modal-title" class="generic-modal-title">Search or Create Page</h2>
                <button class="modal-close-x" aria-label="Close modal" data-target-modal="page-search-modal">
                    <i data-feather="x"></i>
                </button>
            </div>
            <input type="text" id="page-search-modal-input" class="generic-modal-input-field" placeholder="Type to search or create..." style="margin-top: var(--ls-space-2);">
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
    <script src="assets/libs/sjcl.js"></script>
    <script type="module" src="assets/js/api_client.js"></script>
    <script type="module" src="assets/js/ui.js"></script>
    <script src="assets/js/templates.js"></script>
    <script type="module" src="assets/js/app.js"></script>
</body>
</html> 