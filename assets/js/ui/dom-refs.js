/**
 * DOM References Module for the UI
 * Centralized object for all DOM element references to avoid repeated queries.
 * @module ui/dom-refs
 */

export const domRefs = {
    // Main Layout & Sidebars
    appContainer: document.querySelector('.app-container'),
    leftSidebar: document.getElementById('left-sidebar'),
    rightSidebar: document.getElementById('right-sidebar'),
    mainContent: document.getElementById('main-content'),

    // Sidebar Controls & Content
    toggleLeftSidebarBtn: document.getElementById('toggle-left-sidebar-btn'),
    toggleRightSidebarBtn: document.getElementById('toggle-right-sidebar-btn'),
    pageListContainer: document.getElementById('page-list'),
    globalSearchInput: document.getElementById('global-search-input'),
    searchResults: document.getElementById('search-results'),
    favoritesContainer: document.getElementById('favorites-container'),
    extensionIconsContainer: document.getElementById('extension-icons-container'),
    
    // Main Content Area
    pageTitle: document.getElementById('page-title'),
    pagePropertiesContainer: document.getElementById('page-properties'),
    notesContainer: document.getElementById('notes-container'),
    addRootNoteBtn: document.getElementById('add-root-note-btn'),
    backlinksContainer: document.getElementById('backlinks-container'),
    noteFocusBreadcrumbsContainer: document.getElementById('note-focus-breadcrumbs-container'),

    // Modals
    pagePropertiesModal: document.getElementById('page-properties-modal'),
    pagePropertiesList: document.getElementById('page-properties-list'),
    addPagePropertyBtn: document.getElementById('add-page-property-btn'),
    pageSearchModal: document.getElementById('page-search-modal'),
    pageSearchModalInput: document.getElementById('page-search-modal-input'),
    pageSearchModalResults: document.getElementById('page-search-modal-results'),
    pageSearchModalCancel: document.getElementById('page-search-modal-cancel'),
    openPageSearchModalBtn: document.getElementById('open-page-search-modal-btn'),
    imageViewerModal: document.getElementById('image-viewer-modal'),
    imageViewerModalImg: document.getElementById('image-viewer-modal-img'),
    imageViewerModalClose: document.getElementById('image-viewer-modal-close'),

    // UI Indicators
    saveStatusIndicator: document.getElementById('save-status-indicator'),
    toggleSplashBtn: document.getElementById('toggle-splash-btn'),
};
