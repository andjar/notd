/**
 * @file UI module for handling all direct DOM manipulations.
 * This module exports a single `ui` object that contains all UI-related functions.
 */

import { setSaveStatus } from './app/state.js';
import { domRefs } from './ui/dom-refs.js';
import {
    displayNotes,
    addNoteElement,
    removeNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    handleNoteDrop
} from './ui/note-elements.js';

// **FIX**: Import the new calendar widget
import { calendarWidget } from './ui/calendar-widget.js';

import {
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties,
    initializeDelegatedNoteEventListeners,
    renderTransclusion
} from './ui/note-renderer.js';

/**
 * Updates the entire page title block, including breadcrumbs and settings gear.
 * @param {string} pageName - The full name of the page, which may include namespaces.
 */
function updatePageTitle(pageName) {
    document.title = `${pageName} - notd`;
    if (!domRefs.pageTitleContainer) return;

    domRefs.pageTitleContainer.innerHTML = '';

    const pageNameParts = pageName.split('/');
    let currentPath = '';

    pageNameParts.forEach((part, index) => {
        if (index > 0) {
            domRefs.pageTitleContainer.appendChild(document.createTextNode(' / '));
        }
        currentPath += (index > 0 ? '/' : '') + part;

        if (index < pageNameParts.length - 1) {
            const link = document.createElement('a');
            link.href = '#';
            link.textContent = part;
            link.dataset.pageName = currentPath;
            link.onclick = (e) => {
                e.preventDefault();
                if (window.loadPage) window.loadPage(link.dataset.pageName);
            };
            domRefs.pageTitleContainer.appendChild(link);
        } else {
            domRefs.pageTitleContainer.appendChild(document.createTextNode(part));
        }
    });
    
    const gearIcon = document.createElement('i');
    gearIcon.dataset.feather = 'settings';
    gearIcon.id = 'page-properties-gear';
    gearIcon.className = 'page-title-gear';
    gearIcon.title = 'Page Properties';
    domRefs.pageTitleContainer.appendChild(gearIcon);
    if (typeof feather !== 'undefined') feather.replace();
}

/**
 * Updates the page list in the sidebar
 * @param {Array} pages - Array of page objects
 * @param {string} activePageName - Currently active page
 */
function updatePageList(pages, activePageName) {
    if (!domRefs.pageListContainer) return;
    domRefs.pageListContainer.innerHTML = '';

    if (!pages || !Array.isArray(pages) || pages.length === 0) {
        domRefs.pageListContainer.innerHTML = '<li>No pages found.</li>';
        return;
    }

    pages
        .sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at))
        .slice(0, 10)
        .forEach(page => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = `#`;
            link.dataset.pageName = page.name;
            link.textContent = page.name;
            if (page.name === activePageName) {
                link.classList.add('active');
            }
            li.appendChild(link);
            domRefs.pageListContainer.appendChild(li);
        });
}

/**
 * Updates which link in the sidebar is marked as active
 * @param {string} pageName - The name of the page to mark as active
 */
function updateActivePageLink(pageName) {
    if (!domRefs.pageListContainer) return;
    const links = domRefs.pageListContainer.querySelectorAll('a');
    links.forEach(link => {
        if (link.dataset.pageName === pageName) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

/**
 * Renders page properties as inline "pills" directly on the page.
 * @param {Object} properties - The page's properties object.
 * @param {HTMLElement} targetContainer - The HTML element to render properties into.
 */
function renderPageInlineProperties(properties, targetContainer) {
    if (!targetContainer) return;
    targetContainer.innerHTML = '';
    targetContainer.style.display = 'none';

    if (!properties || Object.keys(properties).length === 0) return;

    const fragment = document.createDocumentFragment();
    let hasVisibleProperties = false;
    const RENDER_INTERNAL = window.APP_CONFIG?.RENDER_INTERNAL_PROPERTIES ?? false;

    Object.entries(properties).forEach(([key, instances]) => {
        if (!Array.isArray(instances)) return;
        instances.forEach(instance => {
            if (instance.internal && !RENDER_INTERNAL) return;

            hasVisibleProperties = true;
            const propItem = document.createElement('span');
            propItem.className = 'property-inline';

            if (key === 'favorite' && String(instance.value).toLowerCase() === 'true') {
                propItem.innerHTML = `<span class="property-favorite">⭐</span>`;
            } else {
                propItem.innerHTML = `<span class="property-key">${key}:</span> <span class="property-value">${instance.value}</span>`;
            }
            fragment.appendChild(propItem);
        });
    });

    if (hasVisibleProperties) {
        targetContainer.appendChild(fragment);
        targetContainer.style.display = 'flex';
    }
}

/**
 * Initializes the page properties modal and its event listeners
 */
function initPagePropertiesModal() {
    const modal = domRefs.pagePropertiesModal;
    if (!modal) return;

    const showModal = async () => {
        if (!window.currentPageId || !window.pagesAPI) return;
        try {
            const pageData = await window.pagesAPI.getPageById(window.currentPageId);
            if (window.displayPageProperties) {
                await window.displayPageProperties(pageData.properties || {});
                modal.classList.add('active');
            }
        } catch (error) {
            console.error("Error fetching page properties for modal:", error);
            alert("Error loading page properties.");
        }
    };

    const hideModal = () => modal.classList.remove('active');
    
    document.addEventListener('click', (e) => {
        if (e.target.closest('#page-properties-gear')) {
            showModal();
        }
    });

    domRefs.pagePropertiesModalClose?.addEventListener('click', hideModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) hideModal(); });
}

/**
 * Updates the visual save status indicator.
 * @param {string} newStatus - The new status ('saved', 'pending', 'error').
 */
function updateSaveStatusIndicator(newStatus) {
    const indicator = document.getElementById('save-status-indicator');
    if (!indicator) return;

    setSaveStatus(newStatus);
    indicator.className = 'save-status-indicator';
    indicator.classList.add(`status-${newStatus}`);

    let iconHtml = '';
    switch (newStatus) {
        case 'saved':
            iconHtml = '<i data-feather="check-circle"></i>';
            indicator.title = 'All changes saved';
            break;
        case 'pending':
            iconHtml = '<div class="dot-spinner"><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div></div>';
            indicator.title = 'Saving...';
            break;
        case 'error':
            iconHtml = '<i data-feather="alert-triangle"></i>';
            indicator.title = 'Error saving changes';
            break;
    }
    indicator.innerHTML = iconHtml;
    if (newStatus !== 'pending' && typeof feather !== 'undefined') {
        feather.replace({ width: '18px', height: '18px' });
    }
}

function renderBreadcrumbs(focusedNoteId, allNotesOnPage, currentPageName) {
    if (!domRefs.noteFocusBreadcrumbsContainer || !currentPageName) return;

    let breadcrumbLinksHtml = `<a href="#" onclick="ui.showAllNotesAndLoadPage('${currentPageName}'); return false;">${currentPageName}</a>`;

    if (focusedNoteId) {
        const ancestors = getNoteAncestors(focusedNoteId, allNotesOnPage);
        ancestors.forEach(ancestor => {
            const noteName = (ancestor.content || `Note ${ancestor.id}`).split('\n')[0].substring(0, 30);
            breadcrumbLinksHtml += ` > <a href="#" onclick="ui.focusOnNote('${ancestor.id}'); return false;">${noteName}</a>`;
        });
    }
    domRefs.noteFocusBreadcrumbsContainer.innerHTML = breadcrumbLinksHtml;
    domRefs.noteFocusBreadcrumbsContainer.style.display = 'block';
}

function focusOnNote(noteId) {
    window.currentFocusedNoteId = noteId;
    const allNoteElements = document.querySelectorAll('.note-item');
    allNoteElements.forEach(el => el.classList.add('note-hidden'));
    
    const elementsToShow = new Set();
    let current = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    while(current) {
        elementsToShow.add(current);
        const children = Array.from(current.querySelectorAll('.note-item'));
        children.forEach(child => elementsToShow.add(child));
        current = current.parentElement.closest('.note-item');
    }

    elementsToShow.forEach(el => el.classList.remove('note-hidden'));
    
    renderBreadcrumbs(noteId, window.notesForCurrentPage, window.currentPageName);
}

function showAllNotes() {
    window.currentFocusedNoteId = null;
    document.querySelectorAll('.note-item').forEach(el => el.classList.remove('note-hidden'));
    if (domRefs.noteFocusBreadcrumbsContainer) domRefs.noteFocusBreadcrumbsContainer.style.display = 'none';
}

function showAllNotesAndLoadPage(pageName) {
    showAllNotes();
    if(window.loadPage) window.loadPage(pageName);
}

function getNestingLevel(noteElement) {
    let level = 0;
    let parent = noteElement.parentElement;
    while (parent) {
        if (parent.classList.contains('note-children')) level++;
        if (parent.id === 'notes-container') break;
        parent = parent.parentElement;
    }
    return level;
}

function getNoteAncestors(noteId, allNotesOnPage) {
    const ancestors = [];
    let currentNote = allNotesOnPage.find(note => String(note.id) === String(noteId));
    while (currentNote && currentNote.parent_note_id) {
        const parentNote = allNotesOnPage.find(note => String(note.id) === String(currentNote.parent_note_id));
        if (parentNote) {
            ancestors.unshift(parentNote);
            currentNote = parentNote;
        } else {
            break;
        }
    }
    return ancestors;
}

function promptForPassword() {
    return new Promise((resolve, reject) => {
        const modal = domRefs.passwordModal;
        const input = domRefs.passwordInput;
        const submit = domRefs.passwordSubmit;
        const cancel = domRefs.passwordCancel;

        if (!modal || !input || !submit || !cancel) {
            return reject(new Error('Password modal elements not found in the DOM.'));
        }

        const cleanup = () => {
            submit.removeEventListener('click', handleSubmit);
            cancel.removeEventListener('click', handleCancel);
            input.removeEventListener('keydown', handleKeydown);
            modal.style.display = 'none';
        };

        const handleSubmit = () => {
            const password = input.value;
            if (password) {
                cleanup();
                resolve(password);
            }
        };

        const handleCancel = () => {
            cleanup();
            reject(new Error('Password entry cancelled.'));
        };
        
        const handleKeydown = (e) => {
            if (e.key === 'Enter') {
                handleSubmit();
            } else if (e.key === 'Escape') {
                handleCancel();
            }
        };

        input.value = '';
        submit.addEventListener('click', handleSubmit);
        cancel.addEventListener('click', handleCancel);
        input.addEventListener('keydown', handleKeydown);

        modal.style.display = 'flex';
        input.focus();
    });
}

// Export the main UI object
export const ui = {
    displayNotes,
    addNoteElement,
    removeNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    handleNoteDrop,
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties,
    initializeDelegatedNoteEventListeners,
    renderTransclusion,
    domRefs,
    updatePageTitle,
    updatePageList,
    updateActivePageLink,
    renderPageInlineProperties,
    initPagePropertiesModal,
    updateSaveStatusIndicator,
    renderBreadcrumbs,
    focusOnNote,
    showAllNotes,
    showAllNotesAndLoadPage,
    getNestingLevel,
    calendarWidget,
    promptForPassword
};

// Make ui available globally
if (typeof window !== 'undefined') {
    window.ui = ui;
}