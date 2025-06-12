// assets/js/ui/dom-refs.js

export const domRefs = {
    notesContainer: document.getElementById('notes-container'),
    pagePropertiesContainer: document.getElementById('page-properties-container'),
    pageListContainer: document.getElementById('page-list'),
    addRootNoteBtn: document.getElementById('add-root-note-btn'),
    toggleLeftSidebarBtn: document.getElementById('toggle-left-sidebar-btn'),
    toggleRightSidebarBtn: document.getElementById('toggle-right-sidebar-btn'),
    leftSidebar: document.getElementById('left-sidebar'),
    rightSidebar: document.getElementById('right-sidebar'),
    globalSearchInput: document.getElementById('global-search-input'),
    searchResults: document.getElementById('search-results'),
    pageTitleContainer: document.getElementById('page-title'),
    noteFocusBreadcrumbsContainer: document.getElementById('note-focus-breadcrumbs-container'),
    // **FIX**: Corrected the ID to match the HTML
    extensionIconsContainer: document.getElementById('extension-icons-container'),
    backlinksContainer: document.getElementById('backlinks-container'),
    
    // Modals
    pagePropertiesModal: document.getElementById('page-properties-modal'),
    pagePropertiesModalClose: document.getElementById('page-properties-modal-close'),
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
    passwordModal: document.getElementById('password-modal'),
    passwordInput: document.getElementById('password-input'),
    passwordSubmit: document.getElementById('password-submit'),
    passwordCancel: document.getElementById('password-cancel'),
};