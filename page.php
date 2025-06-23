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

        // Safe Feather replacement function
        window.safeFeatherReplace = function(selector = null) {
            if (typeof feather === 'undefined' || !feather.replace) {
                return;
            }
            
            try {
                // If a selector is provided, only replace icons within that scope
                if (selector) {
                    const container = document.querySelector(selector);
                    if (container) {
                        feather.replace({ root: container });
                    }
                } else {
                    // Replace all icons
                    feather.replace();
                }
            } catch (error) {
                console.warn('Feather replace failed:', error);
            }
        };

        // Enhanced Feather replacement that avoids Alpine.js conflicts
        window.enhancedFeatherReplace = function() {
            if (typeof feather === 'undefined' || !feather.replace) {
                return;
            }
            
            try {
                // Only replace icons on static elements (not Alpine.js managed ones)
                const staticIcons = document.querySelectorAll('i[data-feather]:not([x-data]):not([x-bind]):not([x-text]):not([x-html])');
                
                staticIcons.forEach(icon => {
                    try {
                        const iconName = icon.getAttribute('data-feather');
                        if (iconName && icon.parentNode) {
                            const svg = feather.icons[iconName];
                            if (svg) {
                                icon.innerHTML = svg.toSvg();
                            }
                        }
                    } catch (iconError) {
                        console.warn('Failed to replace individual icon:', iconError);
                    }
                });
            } catch (error) {
                console.warn('Enhanced Feather replace failed:', error);
            }
        };

        // Helper functions for sidebar icon replacement
        window.updateLeftSidebarIcon = function() {
            if (typeof feather === 'undefined' || !feather.icons) return;
            
            const leftBtn = document.getElementById('toggle-left-sidebar-btn');
            if (leftBtn) {
                const leftIcon = leftBtn.querySelector('i');
                if (leftIcon) {
                    const appStore = Alpine.store('app');
                    const iconName = appStore.isLeftSidebarCollapsed ? 'chevron-right' : 'chevron-left';
                    if (feather.icons[iconName]) {
                        leftIcon.innerHTML = feather.icons[iconName].toSvg();
                    }
                }
            }
        };

        window.updateRightSidebarIcon = function() {
            if (typeof feather === 'undefined' || !feather.icons) return;
            
            const rightBtn = document.getElementById('toggle-right-sidebar-btn');
            if (rightBtn) {
                const rightIcon = rightBtn.querySelector('i');
                if (rightIcon) {
                    const appStore = Alpine.store('app');
                    const iconName = appStore.isRightSidebarCollapsed ? 'chevron-left' : 'chevron-right';
                    if (feather.icons[iconName]) {
                        rightIcon.innerHTML = feather.icons[iconName].toSvg();
                    }
                }
            }
        };

        window.updateNoteCollapseIcon = function(element) {
            if (typeof feather === 'undefined' || !feather.icons) return;
            
            const icon = element.querySelector('i');
            if (icon) {
                const noteComponent = Alpine.$data(element);
                const iconName = noteComponent.isCollapsed ? 'chevron-right' : 'chevron-down';
                if (feather.icons[iconName]) {
                    icon.innerHTML = feather.icons[iconName].toSvg();
                }
            }
        };

        // MutationObserver to watch for new static icons
        window.setupFeatherObserver = function() {
            if (typeof MutationObserver === 'undefined') return;
            
            const observer = new MutationObserver((mutations) => {
                let hasNewIcons = false;
                
                mutations.forEach((mutation) => {
                    if (mutation.type === 'childList') {
                        mutation.addedNodes.forEach((node) => {
                            if (node.nodeType === Node.ELEMENT_NODE) {
                                // Check if the added node or its children have static feather icons
                                const icons = node.querySelectorAll ? 
                                    node.querySelectorAll('i[data-feather]:not([x-data]):not([x-bind]):not([x-text]):not([x-html])') :
                                    [];
                                
                                if (node.matches && node.matches('i[data-feather]:not([x-data]):not([x-bind]):not([x-text]):not([x-html])')) {
                                    icons.push(node);
                                }
                                
                                if (icons.length > 0) {
                                    hasNewIcons = true;
                                }
                            }
                        });
                    }
                });
                
                if (hasNewIcons) {
                    // Use setTimeout to ensure DOM is settled
                    setTimeout(() => window.enhancedFeatherReplace(), 10);
                }
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
            
            return observer;
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

            // 2. Custom directive for Feather icons
            Alpine.directive('feather', (el, { expression }, { effect }) => {
                console.log('Feather directive initialized with expression:', expression);
                
                effect(() => {
                    try {
                        const iconName = expression;
                        console.log('Feather directive effect running with iconName:', iconName);
                        
                        if (iconName && typeof feather !== 'undefined' && feather.icons[iconName]) {
                            console.log('Replacing icon with:', iconName);
                            el.innerHTML = feather.icons[iconName].toSvg();
                        } else {
                            console.warn('Feather directive: icon not found or feather not available:', iconName);
                        }
                    } catch (error) {
                        console.error('Feather directive error:', error);
                    }
                });
            });

            // 3. Define the main component
            Alpine.data('appRoot', () => ({
                
                async init() {
                    console.log('Alpine root component initialized.');

                    const urlParams = new URLSearchParams(window.location.search);
                    const initialPageName = urlParams.get('page') || new Date().toISOString().split('T')[0];
                    
                    this.initSidebars();
                    await this.loadPage(initialPageName, false);
                    await this.fetchRecentPages();

                    const splashScreen = document.getElementById('splash-screen');
                    if (splashScreen) {
                        window.splashAnimations?.stop();
                        splashScreen.classList.add('hidden');
                    }
                    
                    // Set up Feather observer and replace static icons
                    this.$nextTick(() => {
                        window.setupFeatherObserver();
                        window.enhancedFeatherReplace();
                        
                        // Also replace sidebar icons specifically
                        setTimeout(() => {
                            window.updateLeftSidebarIcon();
                            window.updateRightSidebarIcon();
                        }, 100);
                    });
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
                        
                        // Replace icons in the recent pages list after data is loaded
                        this.$nextTick(() => {
                            window.enhancedFeatherReplace();
                        });
                    } catch (error) {
                        console.error('Error fetching recent pages:', error);
                        Alpine.store('app').recentPages = [];
                    }
                }
            }));

            Alpine.data('noteItem', (initialNote) => ({
                note: initialNote,
                isEditing: false,
                isCollapsed: initialNote.collapsed || false,

                init() {
                    // When a new note is created, it might not have content.
                    // If it's a new, temporary note with no content, enter edit mode immediately.
                    if (String(this.note.id).startsWith('temp-') && !this.note.content) {
                        this.isEditing = true;
                        // Focus the contenteditable element after it becomes visible
                        this.$nextTick(() => this.$refs.content.focus());
                    }
                },

                get hasChildren() {
                    return this.note.children && this.note.children.length > 0;
                },

                toggleCollapse() {
                    if (!this.hasChildren) return;
                    this.isCollapsed = !this.isCollapsed;
                    // Persist the change
                    notesAPI.batchUpdateNotes([{ 
                        type: 'update', 
                        payload: { id: this.note.id, collapsed: this.isCollapsed ? 1 : 0 } 
                    }]);
                },

                // A placeholder for now, will be fleshed out
                saveNote() {
                    console.log("Saving note:", this.note.id);
                    // Here you would call your debounced save function from note-actions.js
                },

                // Placeholder for handling Enter key
                handleEnter(event) {
                    console.log("Enter pressed on note:", this.note.id);
                    // Logic from note-actions.js handleEnterKey goes here
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
        <div id="left-sidebar-outer">
            <!-- Left Sidebar Toggle -->
            <button @click="$store.app.isLeftSidebarCollapsed = !$store.app.isLeftSidebarCollapsed; console.log('Left sidebar collapsed:', $store.app.isLeftSidebarCollapsed)" 
                    id="toggle-left-sidebar-btn" class="sidebar-toggle-btn left-toggle"
                    x-init="$watch('$store.app.isLeftSidebarCollapsed', () => $nextTick(() => window.updateLeftSidebarIcon()))">
                <i :data-feather="$store.app.isLeftSidebarCollapsed ? 'chevron-right' : 'chevron-left'"></i>
            </button>
            <div id="left-sidebar" class="sidebar left-sidebar" :class="{ 'collapsed': $store.app.isLeftSidebarCollapsed }">
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
        <div id="right-sidebar-outer">
            <!-- Right Sidebar Toggle -->
            <button @click="$store.app.isRightSidebarCollapsed = !$store.app.isRightSidebarCollapsed; console.log('Right sidebar collapsed:', $store.app.isRightSidebarCollapsed)" 
                    id="toggle-right-sidebar-btn" class="sidebar-toggle-btn right-toggle"
                    x-init="$watch('$store.app.isRightSidebarCollapsed', () => $nextTick(() => window.updateRightSidebarIcon()))">
                <i :data-feather="$store.app.isRightSidebarCollapsed ? 'chevron-left' : 'chevron-right'"></i>
            </button>
            <div id="right-sidebar" class="sidebar right-sidebar" :class="{ 'collapsed': $store.app.isRightSidebarCollapsed }">
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

    <!-- REUSABLE NOTE TEMPLATE -->
    <template id="note-item-template">
        <div class="note-item" x-data="noteItem(note)" x-init="init()" :data-note-id="note.id">
            <!-- Header: Bullet, Controls, Content -->
            <div class="note-header-row">
                <div class="note-controls">
                    <!-- Collapse Arrow -->
                    <template x-if="hasChildren">
                        <span class="note-collapse-arrow" @click="toggleCollapse" 
                              x-init="$watch('isCollapsed', () => $nextTick(() => window.updateNoteCollapseIcon($el)))">
                            <i :data-feather="isCollapsed ? 'chevron-right' : 'chevron-down'"></i>
                        </span>
                    </template>
                    <!-- Bullet -->
                    <span class="note-bullet"></span>
                </div>

                <div class="note-content-wrapper">
                    <!-- Rendered View -->
                    <div class="note-content rendered-mode"
                         x-show="!isEditing"
                         @click="isEditing = true; $nextTick(() => $refs.content.focus())"
                         x-html="window.ui.parseAndRenderContent(note.content || '')">
                    </div>
                    <!-- Editing View -->
                    <div class="note-content edit-mode"
                         x-ref="content"
                         x-show="isEditing"
                         x-text="note.content"
                         contenteditable="true"
                         @keydown.enter.prevent="handleEnter($event)"
                         @blur="isEditing = false; saveNote()">
                    </div>
                </div>
            </div>

            <!-- Children Notes (The Recursive Part) -->
            <div class="note-children" x-show="!isCollapsed" x-transition>
                <template x-for="childNote in note.children" :key="childNote.id">
                    <!-- This is the recursion: it uses the same template for child notes -->
                    <div x-html="document.getElementById('note-item-template').innerHTML" :x-data="{ note: childNote }"></div>
                </template>
            </div>
        </div>
    </template>

    <!-- ALL MODALS remain unchanged for now -->
    <!-- ... -->
    
    <script src="assets/js/splash.js"></script>
</body>
</html>