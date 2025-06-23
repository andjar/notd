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
            
            // Simple content rendering function that doesn't depend on external modules
            window.safeParseAndRenderContent = function(content) {
                if (!content) return '';
                
                // Use marked library for markdown parsing if available
                if (typeof marked !== 'undefined') {
                    try {
                        return marked.parse(content);
                    } catch (error) {
                        console.warn('Marked parsing failed, falling back to simple rendering:', error);
                    }
                }
                
                // Fallback: Basic HTML escaping and newline handling
                return content
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\n/g, '<br>');
            };
            
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
                        console.log('Notes data received:', notesData);

                        const appStore = Alpine.store('app');
                        appStore.currentPageId = pageDetails.id;
                        appStore.currentPageName = pageDetails.name;
                        appStore.setNotes(notesData);
                        console.log('Notes set in store:', appStore.notes);

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
                },

                async addRootNote() {
                    try {
                        const appStore = Alpine.store('app');
                        const newNote = {
                            id: 'temp-' + Date.now(),
                            content: '',
                            parent_id: null,
                            page_id: appStore.currentPageId,
                            children: [],
                            collapsed: false
                        };
                        
                        // Add to store
                        appStore.notes.push(newNote);
                        
                        console.log('Added new root note:', newNote.id);
                    } catch (error) {
                        console.error('Error adding root note:', error);
                    }
                }
            }));

            Alpine.data('noteItem', (initialNote) => ({
                note: initialNote,
                isEditing: false,
                isCollapsed: initialNote.collapsed || false,
                contentBuffer: '',

                init() {
                    this.contentBuffer = this.note.content || ''; // Initialize contentBuffer
                    // When a new note is created, it might not have content.
                    // If it's a new, temporary note with no content, enter edit mode immediately.
                    if (String(this.note.id).startsWith('temp-') && !this.note.content) {
                        this.isEditing = true;
                        // contentBuffer is already initialized
                        // Focus the contenteditable element after it becomes visible
                        this.$nextTick(() => {
                            if (this.$refs.content) {
                                this.$refs.content.focus();
                                // Ensure the cursor is at the end of the content
                                const range = document.createRange();
                                const sel = window.getSelection();
                                range.selectNodeContents(this.$refs.content);
                                range.collapse(false);
                                sel.removeAllRanges();
                                sel.addRange(range);
                            }
                        });
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
                    }]).catch(error => console.error('Failed to save collapse state:', error));
                },

                async saveNote() {
                    if (this.contentBuffer !== this.note.content) {
                        const oldContent = this.note.content;
                        this.note.content = this.contentBuffer; // Update local state immediately for reactivity
                        
                        console.log("Saving note:", this.note.id, "New content:", this.note.content);
                        try {
                            await notesAPI.batchUpdateNotes([{ 
                                type: 'update', 
                                payload: { id: this.note.id, content: this.note.content } 
                            }]);
                            console.log("Note saved successfully:", this.note.id);
                            // Optionally, re-render with x-html if parseAndRenderContent is crucial
                            // For now, direct binding to note.content will update the display
                        } catch (error) {
                            console.error("Error saving note:", this.note.id, error);
                            this.note.content = oldContent; // Revert on error
                            this.contentBuffer = oldContent; // Also revert buffer
                            // Consider showing an error to the user
                        }
                    } else {
                        console.log("No changes to save for note:", this.note.id);
                    }
                },

                async handleEnter(event) {
                    event.preventDefault();
                    await this.saveNote();
                    this.isEditing = false; 
                    // Future: Add logic for creating new notes or splitting notes.
                    // For example, dispatch an event to create a new note below this one.
                    // this.$dispatch('new-note-after', { currentNoteId: this.note.id });
                },

                async handleTab(event) {
                    event.preventDefault();
                    await this.saveNote();
                    // Future: Add logic for indenting/outdenting notes.
                    // For example, determine if shift key is pressed for outdent.
                    // this.$dispatch('indent-note', { noteId: this.note.id, direction: event.shiftKey ? 'out' : 'in' });
                    console.log("Tab pressed on note:", this.note.id, "Shift:", event.shiftKey);
                }
            }));

            Alpine.data('calendarWidget', () => ({
                // --- State ---
                currentDisplayDate: new Date(),
                pagesCache: [], // Raw pages from API
                dateToPageMap: new Map(), // O(1) lookup map for performance
                weekdays: ['M', 'T', 'W', 'T', 'F', 'S', 'S'],
                
                // --- Computed Properties (Getters) ---
                get monthYearDisplay() {
                    return this.currentDisplayDate.toLocaleString('default', { month: 'long', year: 'numeric' });
                },

                get calendarGrid() {
                    const year = this.currentDisplayDate.getFullYear();
                    const month = this.currentDisplayDate.getMonth();
                    const todayFormatted = new Date().toISOString().split('T')[0];
                    
                    const firstDayOfMonth = new Date(year, month, 1);
                    const lastDayOfMonth = new Date(year, month + 1, 0);

                    // JS getDay() is 0 (Sun) - 6 (Sat). We want 0 (Mon) - 6 (Sun).
                    const startDayOfWeek = firstDayOfMonth.getDay() === 0 ? 6 : firstDayOfMonth.getDay() - 1;

                    const grid = [];
                    
                    // Add empty cells for the start of the month
                    for (let i = 0; i < startDayOfWeek; i++) {
                        grid.push({ type: 'empty' });
                    }

                    // Add day cells for the month
                    for (let day = 1; day <= lastDayOfMonth.getDate(); day++) {
                        const date = new Date(year, month, day);
                        const formattedDate = date.toISOString().split('T')[0];
                        const pageForDate = this.dateToPageMap.get(formattedDate);
                        const isCurrentPage = Alpine.store('app').currentPageName === (pageForDate?.name || formattedDate);

                        grid.push({
                            type: 'day',
                            day: day,
                            date: formattedDate,
                            isToday: formattedDate === todayFormatted,
                            hasContent: !!pageForDate,
                            pageName: pageForDate?.name || formattedDate,
                            isCurrentPage: isCurrentPage,
                        });
                    }
                    return grid;
                },

                // --- Methods ---
                async init() {
                    console.log("Calendar widget initializing...");
                    // Ensure weekdays is initialized
                    this.weekdays = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
                    await this.fetchAndProcessData();

                    // Watch for page changes and refresh the calendar's "current page" highlight
                    this.$watch('$store.app.currentPageName', () => {
                        // No need to re-render the whole grid, Alpine's reactivity on `isCurrentPage` handles it.
                        // This is just a log to show it's working.
                        console.log('Calendar detected page change to:', Alpine.store('app').currentPageName);
                    });
                },

                async fetchAndProcessData() {
                    try {
                        const { pages } = await pagesAPI.getPages({ per_page: 5000 });
                        this.pagesCache = pages || [];
                        this.processPageDataIntoMap();
                    } catch (error) {
                        console.error('Error fetching pages for calendar:', error);
                    }
                },
                
                processPageDataIntoMap() {
                    this.dateToPageMap.clear();
                    const dateRegex = /^\d{4}-\d{2}-\d{2}$/;

                    for (const page of this.pagesCache) {
                        if (dateRegex.test(page.name)) {
                            this.dateToPageMap.set(page.name, page);
                        }
                        if (page.properties?.date && Array.isArray(page.properties.date)) {
                            for (const dateProp of page.properties.date) {
                                if (dateProp.value && !this.dateToPageMap.has(dateProp.value)) {
                                    this.dateToPageMap.set(dateProp.value, page);
                                }
                            }
                        }
                    }
                },

                goToPrevMonth() {
                    this.currentDisplayDate.setMonth(this.currentDisplayDate.getMonth() - 1);
                    // Force a re-render by creating a new Date object
                    this.currentDisplayDate = new Date(this.currentDisplayDate);
                },

                goToNextMonth() {
                    this.currentDisplayDate.setMonth(this.currentDisplayDate.getMonth() + 1);
                    this.currentDisplayDate = new Date(this.currentDisplayDate);
                },

                goToToday() {
                    this.currentDisplayDate = new Date();
                },

                onDayClick(day) {
                    if (day.type === 'day' && day.pageName) {
                        // Call the loadPage method on the parent appRoot component
                        this.$dispatch('load-page', { pageName: day.pageName });
                    }
                }
            }));
        });
    </script>

    <!-- Alpine.js Core (MUST be last among setup scripts) -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="appRoot" x-init="init()" @load-page.window="loadPage($event.detail.pageName)">
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
                        <!-- Calendar Widget driven by Alpine.js -->
                        <div id="calendar-widget" class="calendar-widget" 
                             x-data="calendarWidget" 
                             @load-page.window="loadPage($event.detail.pageName)">
                            <div class="calendar-header">
                                <!-- The month/year display is now powered by a getter -->
                                <span id="current-month-year" class="month-year-display" x-text="monthYearDisplay"></span>
                                
                                <!-- Navigation controls -->
                                <div class="calendar-nav-controls">
                                    <button @click="goToToday()" class="arrow-btn today-btn" title="Go to Today">Today</button>
                                    <button @click="goToPrevMonth()" id="prev-month-btn" class="arrow-btn"><i data-feather="chevron-left"></i></button>
                                    <button @click="goToNextMonth()" id="next-month-btn" class="arrow-btn"><i data-feather="chevron-right"></i></button>
                                </div>
                            </div>
                            
                            <!-- Weekday headers -->
                            <div class="calendar-grid calendar-weekdays">
                                <template x-for="(weekday, index) in weekdays" :key="index">
                                    <span x-text="weekday"></span>
                                </template>
                            </div>

                            <!-- The main calendar days grid, now rendered with a loop -->
                            <div id="calendar-days-grid" class="calendar-grid calendar-days">
                                <template x-for="(day, index) in calendarGrid" :key="index">
                                    <div :class="{
                                            'calendar-day': day.type === 'day',
                                            'empty': day.type === 'empty',
                                            'today': day.isToday,
                                            'has-content': day.hasContent,
                                            'current-page': day.isCurrentPage
                                         }"
                                         @click="onDayClick(day)"
                                         :title="day.hasContent ? `Page: ${day.pageName}` : ''">
                                        <span x-text="day.day"></span>
                                    </div>
                                </template>
                            </div>
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
                <!-- Notes rendered by Alpine.js -->
                <template x-if="!$store.app.notes || $store.app.notes.length === 0">
                    <div class="no-notes-message">No notes on this page. Click the + button to add one.</div>
                </template>
                <template x-for="note in $store.app.notes" :key="note.id">
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
                                     @click="isEditing = true; contentBuffer = note.content; $nextTick(() => $refs.content.focus())"
                                     x-html="window.safeParseAndRenderContent(note.content || '')">
                                </div>
                                <!-- Editing View -->
                                <div class="note-content edit-mode"
                                     x-ref="content"
                                     x-show="isEditing"
                                     x-text="note.content" 
                                     contenteditable="true"
                                     @input="contentBuffer = $event.target.innerText"
                                     @keydown.enter.prevent="handleEnter($event)"
                                     @keydown.tab.prevent="handleTab($event)"
                                     @blur="isEditing = false; saveNote()">
                                </div>
                            </div>
                        </div>

                        <!-- Children Notes (Simplified for now) -->
                        <div class="note-children" x-show="!isCollapsed" x-transition>
                            <template x-for="childNote in note.children" :key="childNote.id">
                                <div class="note-item" x-data="noteItem(childNote)" x-init="init()" :data-note-id="childNote.id">
                                    <div class="note-header-row">
                                        <div class="note-controls">
                                            <span class="note-bullet"></span>
                                        </div>
                                        <div class="note-content-wrapper">
                                            <div class="note-content rendered-mode"
                                                 x-show="!isEditing"
                                                 @click="isEditing = true; contentBuffer = note.content; $nextTick(() => $refs.content.focus())"
                                                 x-html="window.safeParseAndRenderContent(note.content || '')">
                                            </div>
                                            <div class="note-content edit-mode"
                                                 x-ref="content"
                                                 x-show="isEditing"
                                                 x-text="note.content" 
                                                 contenteditable="true"
                                                 @input="contentBuffer = $event.target.innerText"
                                                 @keydown.enter.prevent="handleEnter($event)"
                                                 @keydown.tab.prevent="handleTab($event)"
                                                 @blur="isEditing = false; saveNote()">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <div id="child-pages-container"></div>
            <button id="add-root-note-btn" class="action-button round-button" title="Add new note to page"
                    @click="addRootNote()">
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
                         @click="isEditing = true; contentBuffer = note.content; $nextTick(() => $refs.content.focus())"
                         x-html="window.safeParseAndRenderContent(note.content || '')">
                    </div>
                    <!-- Editing View -->
                    <div class="note-content edit-mode"
                         x-ref="content"
                         x-show="isEditing"
                         x-text="note.content" 
                         contenteditable="true"
                         @input="contentBuffer = $event.target.innerText"
                         @keydown.enter.prevent="handleEnter($event)"
                         @keydown.tab.prevent="handleTab($event)"
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