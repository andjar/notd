// assets/js/ui/dom-refs.js

export const domRefs = {
    notesContainer: document.getElementById('notes-container'),
    pageContent: document.getElementById('page-content'),
    pageTitle: document.getElementById('page-title'),
    pageTitleContainer: document.querySelector('.page-title-container'),

    pageListContainer: document.getElementById('page-list'),
    addRootNoteBtn: document.getElementById('add-root-note-btn'),
    toggleLeftSidebarBtn: document.getElementById('toggle-left-sidebar-btn'),
    toggleRightSidebarBtn: document.getElementById('toggle-right-sidebar-btn'),
    leftSidebar: document.getElementById('left-sidebar'),
    rightSidebar: document.getElementById('right-sidebar'),
    globalSearchInput: document.getElementById('global-search-input'),
    searchResults: document.getElementById('search-results'),
    noteFocusBreadcrumbsContainer: document.getElementById('note-focus-breadcrumbs-container'),
    // **FIX**: Corrected the ID to match the HTML
    extensionIconsContainer: document.getElementById('extension-icons-container'),
    backlinksContainer: document.getElementById('backlinks-container'),
    openBacklinksModalBtn: document.getElementById('open-backlinks-modal-btn'),
    
    // Modals
    backlinksModal: document.getElementById('backlinks-modal'),
    backlinksModalClose: document.getElementById('backlinks-modal-close'),
    backlinksModalContent: document.getElementById('backlinks-modal-content'),
    pagePropertiesModal: document.getElementById('page-properties-modal'),
    pagePropertiesModalClose: document.getElementById('page-properties-modal-close'),
    pagePropertiesList: document.getElementById('page-properties-list'),
    pageEncryptionButton: document.getElementById('page-encryption-button'),
    pageSearchModal: document.getElementById('page-search-modal'),
    pageSearchModalInput: document.getElementById('page-search-modal-input'),
    pageSearchModalResults: document.getElementById('page-search-modal-results'),
    pageSearchModalCancel: document.getElementById('page-search-modal-cancel'),
    openPageSearchModalBtn: document.getElementById('open-page-search-modal-btn'),
    imageViewerModal: document.getElementById('image-viewer-modal'),
    imageViewerModalImg: document.getElementById('image-viewer-modal-img'),
    imageViewerModalClose: document.getElementById('image-viewer-modal-close'),
    passwordModal: document.getElementById('password-modal'),
    passwordModalClose: document.getElementById('password-modal-close'),
    passwordInput: document.getElementById('password-input'),
    passwordSubmit: document.getElementById('password-submit'),
    passwordCancel: document.getElementById('password-cancel'),

    // Note Search Modal
    noteSearchModal: document.getElementById('note-search-modal'),
    noteSearchModalInput: document.getElementById('note-search-modal-input'),
    noteSearchModalResults: document.getElementById('note-search-modal-results'),
    noteSearchModalCancel: document.getElementById('note-search-modal-cancel'),

    // Encryption Modal Elements
    encryptionPasswordModal: document.getElementById('encryption-password-modal'),
    encryptionModalClose: document.getElementById('encryption-modal-close'),
    newEncryptionPasswordInput: document.getElementById('new-encryption-password'),
    confirmEncryptionPasswordInput: document.getElementById('confirm-encryption-password'),
    encryptionPasswordError: document.getElementById('encryption-password-error'),
    confirmEncryptionBtn: document.getElementById('confirm-encryption-btn'),
    cancelEncryptionBtn: document.getElementById('cancel-encryption-btn'),


    mobileToolbar: document.getElementById('mobile-bottom-toolbar'),
    mobileToggleLeftSidebarBtn: document.getElementById('mobile-toggle-left-sidebar-btn'),
    mobileAddRootNoteBtn: document.getElementById('mobile-add-root-note-btn'),
    mobileToggleRightSidebarBtn: document.getElementById('mobile-toggle-right-sidebar-btn'),
};