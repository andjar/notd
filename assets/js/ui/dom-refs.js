/**
 * DOM References Module for NotTD UI
 * Centralized object for all DOM element references.
 * @module domRefs
 */

export const domRefs = {
    notesContainer: document.getElementById('notes-container'),
    pagePropertiesContainer: document.getElementById('page-properties'),
    pageListContainer: document.getElementById('page-list'),
    addRootNoteBtn: document.getElementById('add-root-note-btn'),
    toggleLeftSidebarBtn: document.getElementById('toggle-left-sidebar-btn'),
    toggleRightSidebarBtn: document.getElementById('toggle-right-sidebar-btn'),
    leftSidebar: document.getElementById('left-sidebar'),
    rightSidebar: document.getElementById('right-sidebar'),
    globalSearchInput: document.getElementById('global-search-input'),
    searchResults: document.getElementById('search-results'),
    backlinksContainer: document.getElementById('backlinks-container'),
    breadcrumbsContainer: document.getElementById('breadcrumbs-container'),
    noteFocusBreadcrumbsContainer: document.getElementById('note-focus-breadcrumbs-container'),
    pagePropertiesGear: document.getElementById('page-properties-gear'),
    pagePropertiesModal: document.getElementById('page-properties-modal'),
    pagePropertiesModalClose: document.getElementById('page-properties-modal-close'),
    pagePropertiesList: document.getElementById('page-properties-list'),
    addPagePropertyBtn: document.getElementById('add-page-property-btn'),

    // New refs for Page Search Modal
    openPageSearchModalBtn: document.getElementById('open-page-search-modal-btn'),
    pageSearchModal: document.getElementById('page-search-modal'),
    pageSearchModalInput: document.getElementById('page-search-modal-input'),
    pageSearchModalResults: document.getElementById('page-search-modal-results'),
    pageSearchModalCancel: document.getElementById('page-search-modal-cancel'),

    // Image Viewer Modal Refs
    imageViewerModal: document.getElementById('image-viewer-modal'),
    imageViewerModalImg: document.getElementById('image-viewer-modal-img'),
    imageViewerModalClose: document.getElementById('image-viewer-modal-close'),
    favoritesContainer: document.getElementById('favorites-container'),
    extensionIconsContainer: document.getElementById('extension-icons-container'),
};
