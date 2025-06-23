<?php
// Include the main configuration file to make constants available.
require_once 'config.php';

// --- Failsafe Redirect ---
$pageName = isset($_GET['page']) ? trim($_GET['page']) : null;
if (empty($pageName)) {
    $default_page_name = date('Y-m-d');
    $redirect_url = 'page.php?page=' . urlencode($default_page_name);
    header('Location: ' . $redirect_url, true, 302);
    exit;
}

// --- Frontend Configuration ---
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
    <script src="assets/libs/sjcl.js"></script>
    
    <script>
        // Pass server-side configuration to JavaScript
        window.APP_CONFIG = {
            RENDER_INTERNAL_PROPERTIES: <?php echo json_encode($renderInternal); ?>,
            SHOW_INTERNAL_PROPERTIES_IN_EDIT_MODE: <?php echo json_encode($showInternalInEdit); ?>
        };
    </script>

    <!-- Alpine.js Plugins (MUST be loaded before the inline script) -->
    <script src="https://cdn.jsdelivr.net/npm/@alpinejs/persist@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/@alpinejs/intersect@3.x.x/dist/cdn.min.js" defer></script>
    
    <!-- Main Application Logic (Inline Module) -->
    <script type="module">
        import { pagesAPI, notesAPI } from './assets/js/api_client.js';
        import { defineStore } from './assets/js/store.js';

        document.addEventListener('alpine:init', () => {
            console.log('Alpine initializing...');
            
            // 1. Define the store first
            defineStore();

            // 2. Define the main component
            Alpine.data('appRoot', () => ({
                
                async init() {
                    console.log('Alpine root component initialized.');

                    const urlParams = new URLSearchParams(window.location.search);
                    const initialPageName = urlParams.get('page') || new Date().toISOString().split('T')[0];
                    
                    this.initSidebars();
                    await this.loadPage(initialPageName, false);
                    await this.fetchRecentPages();

                    this.$nextTick(() => {
                        if (typeof feather !== 'undefined' && feather.replace) {
                            feather.replace();
                        }
                    });

                    const splashScreen = document.getElementById('splash-screen');
                    if (splashScreen) {
                        window.splashAnimations?.stop();
                        splashScreen.classList.add('hidden');
                    }
                },

                async loadPage(pageName, updateHistory = true) {
                    try {
                        const pageDetails = await pagesAPI.getPageByName(pageName);
                        if (!pageDetails || !pageDetails.id) {
                            const newPage = await pagesAPI.createPage(pageName, '');
                            await this.loadPage(newPage.name, updateHistory);
                            return;
                        }
                        const notesData = await notesAPI.getPageData(pageDetails.id);

                        const appStore = Alpine.store('app');
                        appStore.currentPageId = pageDetails.id;
                        appStore.currentPageName = pageDetails.name;
                        appStore.setNotes(notesData);

                        if (updateHistory) {
                            const newUrl = new URL(window.location);
                            newUrl.searchParams.set('page', pageName);
                            history.pushState({ pageName }, '', newUrl.toString());
                        }
                        document.title = `${pageName} - notd`;

                    } catch (error) {
                        console.error(`Error loading page ${pageName}:`, error);
                    }
                },

                initSidebars() {
                    console.log('Sidebars are managed by Alpine store and HTML bindings.');
                },

                async fetchRecentPages() {
                    try {
                        const { pages } = await pagesAPI.getPages({ 
                            sort_by: 'updated_at', 
                            sort_order: 'desc', 
                            per_page: 10, 
                            exclude_journal: '1' 
                        });
                        Alpine.store('app').recentPages = pages || [];
                    } catch (error) {
                        console.error('Error fetching recent pages:', error);
                        Alpine.store('app').recentPages = [];
                    }
                }
            }));
        });
    </script>

    <!-- Alpine.js Core (MUST be last among setup scripts) -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="appRoot" x-init="init()">
    <div id="splash-screen">
        <div class="time-date-container">
            <div id="clock" class="clock">12:00</div>
            <div id="date" class="date">Monday, 1 January</div>
        </div>
        <div id="splash-background-bubbles-canvas"></div>
        <div id="splash-orb-container">
            <div id="splash-orb-inner-core">
                <div id="splash-orb-text-container">
                    <p id="splash-orb-text">notd</p>
                </div>
                <div id="splash-orb-perimeter-dots"></div>
            </div>
        </div>
    </div>
    
    <div class="app-container">
        <!-- Left Sidebar -->
        <div id="left-sidebar-outer" :class="{ 'collapsed': $store.app.isLeftSidebarCollapsed }">
            <button @click="$store.app.isLeftSidebarCollapsed = !$store.app.isLeftSidebarCollapsed" id="toggle-left-sidebar-btn" class="sidebar-toggle-btn left-toggle">
                <i data-feather="x"></i>
            </button>
            <div id="left-sidebar" class="sidebar left-sidebar">
                <div class="sidebar-content">
                    <div class="app-header">
                        <a href="/" id="app-title" class="app-title">notd</a>
                    </div>
                    <div class="sidebar-section">
                        <!-- Calendar Widget will be converted to Alpine component later -->
                        <div id="calendar-widget" class="calendar-widget">
                            <div class="calendar-header">
                                <button id="prev-month-btn" class="arrow-btn"><i data-feather="chevron-left"></i></button>
                                <span id="current-month-year" class="month-year-display"></span>
                                <button id="next-month-btn" class="arrow-btn"><i data-feather="chevron-right"></i></button>
                            </div>
                            <div class="calendar-grid calendar-weekdays">
                                <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                            </div>
                            <div id="calendar-days-grid" class="calendar-grid calendar-days"></div>
                        </div>
                    </div>
                    <div class="search-section">
                        <!-- Global Search will be an Alpine component later -->
                        <input type="text" id="global-search-input" placeholder="Search..." class="search-input">
                        <div id="search-results" class="search-results"></div>
                    </div>
                    <div class="sidebar-section">
                        <div class="recent-pages">
                            <h3>Recent Pages</h3>
                            <div id="page-list">
                                <template x-if="!$store.app.recentPages || $store.app.recentPages.length === 0">
                                    <div class="no-pages-message">No recent pages</div>
                                </template>
                                <ul class="recent-pages-list">
                                    <template x-for="page in $store.app.recentPages" :key="page.id">
                                        <li>
                                            <a href="#" 
                                               @click.prevent="loadPage(page.name)"
                                               class="recent-page-link"
                                               :class="{ 'active': page.name === $store.app.currentPageName }">
                                                <i data-feather="file-text" class="recent-page-icon"></i>
                                                <span class="recent-page-name" x-text="page.name"></span>
                                            </a>
                                        </li>
                                    </template>
                                </ul>
                            </div>
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
                <h1 id="page-title" class="page-title" x-text="$store.app.currentPageName">
                    <!-- Alpine will now populate this -->
                </h1>
                <div id="page-properties-container" class="page-properties-inline"></div>
            </div>
            <div id="page-content" class="page-content"></div>
            <div id="note-focus-breadcrumbs-container"></div>
            <div id="notes-container" class="outliner">
                <!-- Notes will be an Alpine component next -->
            </div>
            <div id="child-pages-container"></div>
            <button id="add-root-note-btn" class="action-button round-button" title="Add new note to page">
                <i data-feather="plus"></i>
            </button>
        </div>

        <!-- Right Sidebar -->
        <div id="right-sidebar-outer" :class="{ 'collapsed': $store.app.isRightSidebarCollapsed }">
            <button @click="$store.app.isRightSidebarCollapsed = !$store.app.isRightSidebarCollapsed" id="toggle-right-sidebar-btn" class="sidebar-toggle-btn right-toggle">
                <i data-feather="x"></i>
            </button>
            <div id="right-sidebar" class="sidebar right-sidebar">
                <!-- Sidebar content will be migrated next -->
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
                            <div id="backlinks-list" class="backlinks-list"></div>
                        </div>
                    </div>
                    <div class="sidebar-section">
                        <div id="child-pages-sidebar" class="child-pages-sidebar"></div>
                    </div>
                    <div class="sidebar-section extensions-section">
                        <h4>Extensions</h4>
                        <div id="extension-icons-container"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="save-status-indicator" title="All changes saved"></div>
        <button id="toggle-splash-btn" class="action-button round-button absolute-bottom-right" title="Toggle Splash Screen">
            <i data-feather="pause-circle"></i>
        </button>
    </div>

    <!-- ALL MODALS remain unchanged for now -->
    <!-- ... -->
    
    <script src="assets/js/splash.js"></script>
</body>
</html>