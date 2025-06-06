/**
 * UI Module for NotTD application
 * Handles all DOM manipulation and rendering
 * @module ui
 */

import { saveStatus, setSaveStatus } from './app/state.js';
import { domRefs } from './ui/dom-refs.js';

// Import functions related to note elements
import {
    displayNotes,
    updateNoteElement,
    addNoteElement,
    removeNoteElement,
    moveNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    handleNoteDrop
} from './ui/note-elements.js';

// Import functions related to note rendering
import {
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    switchToRenderedMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties
} from './ui/note-renderer.js';

// Functions that will remain in ui.js or be moved to other specific UI modules (e.g., modals, page-specific UI)

/**
 * Updates the page title
 * @param {string} name - Page name
 */
function updatePageTitle(name) {
    domRefs.currentPageTitleEl.textContent = name;
    document.title = `${name} - notd`;
}

/**
 * Updates the page list in the sidebar
 * @param {Array} pages - Array of page objects
 * @param {string} activePageName - Currently active page
 */
function updatePageList(pages, activePageName) {
    domRefs.pageListContainer.innerHTML = '';

    if (!pages || pages.length === 0) {
        const defaultPageName = typeof getTodaysJournalPageName === 'function' 
            ? getTodaysJournalPageName() 
            : 'Journal'; // Fallback if getTodaysJournalPageName is not defined globally
        const link = document.createElement('a');
        link.href = '#';
        link.dataset.pageName = defaultPageName;
        link.textContent = `${defaultPageName} (Create)`;
        domRefs.pageListContainer.appendChild(link);
        return;
    }

    pages.sort((a, b) => {
        if (a.updated_at > b.updated_at) return -1;
        if (a.updated_at < b.updated_at) return 1;
        return a.name.localeCompare(b.name);
    });

    const limitedPages = pages.slice(0, 7);

    limitedPages.forEach(page => {
        const link = document.createElement('a');
        link.href = '#';
        link.dataset.pageName = page.name;
        link.textContent = page.name;
        if (page.name === activePageName) {
            link.classList.add('active');
        }
        domRefs.pageListContainer.appendChild(link);
    });
}

/**
 * Updates the active page link in the sidebar
 * @param {string} pageName - Active page name
 */
function updateActivePageLink(pageName) {
    document.querySelectorAll('#page-list a').forEach(link => {
        link.classList.toggle('active', link.dataset.pageName === pageName);
    });
}

/**
 * Shows or updates a property in a note (potentially for properties not handled by inline rendering)
 * @param {string} noteId - Note ID
 * @param {string} propertyName - Property name
 * @param {string} propertyValue - Property value
 */
function showPropertyInNote(noteId, propertyName, propertyValue) {
    const noteEl = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteEl) return;

    let propertiesEl = noteEl.querySelector('.note-properties');
    if (!propertiesEl) {
        propertiesEl = document.createElement('div');
        propertiesEl.className = 'note-properties';
        // Assuming note-content-wrapper is where properties should be appended if not inline
        const contentWrapper = noteEl.querySelector('.note-content-wrapper');
        if (contentWrapper) {
            contentWrapper.appendChild(propertiesEl);
        } else {
            noteEl.appendChild(propertiesEl); // Fallback
        }
    }
    
    // This function might need to be smarter if `renderProperties` handles the main display
    // For now, it adds/updates a specific property.
    const existingProp = propertiesEl.querySelector(`.property-item[data-property="${propertyName}"]`);
    if (existingProp) {
        existingProp.querySelector('.property-value').innerHTML = parseAndRenderContent(String(propertyValue));
    } else {
        const propItem = document.createElement('span');
        propItem.className = 'property-item';
        propItem.dataset.property = propertyName; // For easier selection
        propItem.innerHTML = `
            <span class="property-key">${propertyName}::</span>
            <span class="property-value">${parseAndRenderContent(String(propertyValue))}</span>
        `;
        propertiesEl.appendChild(propItem);
    }
    propertiesEl.style.display = 'block'; // Or 'flex' if using flexbox for layout
}

/**
 * Removes a property from a note (potentially for properties not handled by inline rendering)
 * @param {string} noteId - Note ID
 * @param {string} propertyName - Property name to remove
 */
function removePropertyFromNote(noteId, propertyName) {
    const noteEl = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteEl) return;

    const propertiesEl = noteEl.querySelector('.note-properties');
    if (!propertiesEl) return;

    const propItem = propertiesEl.querySelector(`.property-item[data-property="${propertyName}"]`);
    if (propItem) {
        propItem.remove();
        if (propertiesEl.children.length === 0) {
            propertiesEl.style.display = 'none';
        }
    }
}

/**
 * Renders transcluded content
 * @param {HTMLElement} placeholderEl - Transclusion placeholder element
 * @param {string} noteContent - Content to render
 * @param {string} noteId - ID of the transcluded note
 */
function renderTransclusion(placeholderEl, noteContent, noteId) {
    if (!placeholderEl || !noteContent) return;

    const contentEl = document.createElement('div');
    contentEl.className = 'transcluded-content';
    contentEl.innerHTML = `
        <div class="transclusion-header">
            <span class="transclusion-icon">🔗</span>
            <a href="#" class="transclusion-link" data-note-id="${noteId}">View original note</a>
        </div>
        <div class="transclusion-body">
            ${parseAndRenderContent(noteContent)}
        </div>
    `;
    
    const transclusionLink = contentEl.querySelector('.transclusion-link');
    if (transclusionLink) {
        transclusionLink.addEventListener('click', (e) => {
            e.preventDefault();
            const originalNote = document.querySelector(`[data-note-id="${noteId}"]`);
            if (originalNote) {
                originalNote.scrollIntoView({ behavior: 'smooth', block: 'center' });
                originalNote.style.background = 'rgba(59, 130, 246, 0.1)';
                setTimeout(() => {
                    originalNote.style.background = '';
                }, 2000);
            }
        });
    }
    
    placeholderEl.replaceWith(contentEl);
}

/**
 * Calendar Widget Module
 */
const calendarWidget = {
    currentDate: new Date(),
    currentPageName: null,
    
    init() {
        this.calendarEl = document.querySelector('.calendar-widget');
        if (!this.calendarEl) return;
        
        this.monthEl = this.calendarEl.querySelector('.current-month');
        this.daysEl = this.calendarEl.querySelector('.calendar-days');
        this.prevBtn = this.calendarEl.querySelector('.calendar-nav.prev');
        this.nextBtn = this.calendarEl.querySelector('.calendar-nav.next');
        
        this.bindEvents();
        this.render();
    },
    
    bindEvents() {
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => {
                this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                this.render();
            });
        }
        
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => {
                this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                this.render();
            });
        }
    },
    
    setCurrentPage(pageName) {
        this.currentPageName = pageName;
        this.render();
    },
    
    render() {
        if (!this.monthEl || !this.daysEl) return;
        
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();
        
        this.monthEl.textContent = new Date(year, month).toLocaleString('default', { 
            month: 'long', 
            year: 'numeric' 
        });
        
        this.daysEl.innerHTML = '';
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const totalDays = lastDay.getDate();
        const startingDay = firstDay.getDay();
        
        for (let i = 0; i < startingDay; i++) {
            const emptyDay = document.createElement('div');
            emptyDay.className = 'calendar-day empty';
            this.daysEl.appendChild(emptyDay);
        }
        
        const today = new Date();
        for (let day = 1; day <= totalDays; day++) {
            const dayEl = document.createElement('div');
            dayEl.className = 'calendar-day';
            dayEl.textContent = day;
            
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            if (day === today.getDate() && 
                month === today.getMonth() && 
                year === today.getFullYear()) {
                dayEl.classList.add('today');
            }
            
            if (this.currentPageName === dateStr) {
                dayEl.classList.add('current-page');
            }
            
            dayEl.addEventListener('click', () => {
                if (typeof window.loadPage === 'function') {
                    window.loadPage(dateStr);
                }
            });
            
            this.daysEl.appendChild(dayEl);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    calendarWidget.init();
});


/**
 * Shows a generic input modal and returns a Promise with the entered value.
 * @param {string} title - The title for the modal.
 * @param {string} [defaultValue=''] - The default value for the input field.
 * @returns {Promise<string|null>} A Promise that resolves with the input string or null if canceled.
 */
function showGenericInputModal(title, defaultValue = '') {
    return new Promise((resolve) => {
        const modal = document.getElementById('generic-input-modal');
        const titleEl = document.getElementById('generic-input-modal-title');
        const inputEl = document.getElementById('generic-input-modal-input');
        const okBtn = document.getElementById('generic-input-modal-ok');
        const cancelBtn = document.getElementById('generic-input-modal-cancel');

        if (!modal || !titleEl || !inputEl || !okBtn || !cancelBtn) {
            console.error('Generic input modal elements not found!');
            resolve(prompt(title, defaultValue)); 
            return;
        }

        titleEl.textContent = title;
        inputEl.value = defaultValue;
        modal.classList.add('active');
        inputEl.focus();
        inputEl.select();

        const closeHandler = (value) => {
            modal.classList.remove('active');
            okBtn.removeEventListener('click', okHandler);
            inputEl.removeEventListener('keypress', enterKeyHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
            document.removeEventListener('keydown', escapeKeyHandler);
            resolve(value);
        };

        const okHandler = () => {
            closeHandler(inputEl.value);
        };

        const cancelHandler = () => {
            closeHandler(null);
        };

        const enterKeyHandler = (e) => {
            if (e.key === 'Enter') {
                okHandler();
            }
        };
        
        const escapeKeyHandler = (e) => {
            if (e.key === 'Escape') {
                cancelHandler();
            }
        };

        okBtn.addEventListener('click', okHandler);
        inputEl.addEventListener('keypress', enterKeyHandler);
        cancelBtn.addEventListener('click', cancelHandler);
        document.addEventListener('keydown', escapeKeyHandler); 
    });
}

/**
 * Shows a generic confirmation modal and returns a Promise with a boolean.
 * @param {string} title - The title for the modal.
 * @param {string} message - The confirmation message.
 * @returns {Promise<boolean>} A Promise that resolves with true if OK is clicked, false otherwise.
 */
function showGenericConfirmModal(title, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('generic-confirm-modal');
        const titleEl = document.getElementById('generic-confirm-modal-title');
        const messageEl = document.getElementById('generic-confirm-modal-message');
        const okBtn = document.getElementById('generic-confirm-modal-ok');
        const cancelBtn = document.getElementById('generic-confirm-modal-cancel');

        if (!modal || !titleEl || !messageEl || !okBtn || !cancelBtn) {
            console.error('Generic confirm modal elements not found!');
            resolve(confirm(message)); 
            return;
        }

        titleEl.textContent = title;
        messageEl.textContent = message;
        modal.classList.add('active');
        okBtn.focus();

        const closeHandler = (value) => {
            modal.classList.remove('active');
            okBtn.removeEventListener('click', okHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
            document.removeEventListener('keydown', escapeKeyHandler);
            resolve(value);
        };

        const okHandler = () => {
            closeHandler(true);
        };

        const cancelHandler = () => {
            closeHandler(false);
        };
        
        const escapeKeyHandler = (e) => {
            if (e.key === 'Escape') {
                cancelHandler();
            }
        };

        okBtn.addEventListener('click', okHandler);
        cancelBtn.addEventListener('click', cancelHandler);
        document.addEventListener('keydown', escapeKeyHandler);
    });
}

/**
 * @deprecated This function is kept for backward compatibility but properties are now handled inline
 */
function extractPropertiesFromContent(content) {
    return { content: content || '', properties: {} };
}

/**
 * @deprecated This function is kept for backward compatibility but properties are now handled inline
 */
function renderInlineProperties(properties) {
    return '';
}

/**
 * Renders page properties as inline "pills" directly on the page.
 * @param {Object} properties - The page's properties object.
 * @param {HTMLElement} targetContainer - The HTML element to render properties into.
 */
function renderPageInlineProperties(properties, targetContainer) {
    if (!targetContainer) {
        console.error("Target container for inline page properties not provided.");
        return;
    }
    targetContainer.innerHTML = ''; 

    if (!properties || Object.keys(properties).length === 0) {
        targetContainer.style.display = 'none';
        return;
    }

    let hasVisibleProperties = false;
    const fragment = document.createDocumentFragment();

    Object.entries(properties).forEach(([key, value]) => {
        hasVisibleProperties = true;
        const processValue = (val) => {
            const propItem = document.createElement('span');
            propItem.className = 'property-inline'; 
            if (key.toLowerCase() === 'favorite' && String(val).toLowerCase() === 'true') {
                propItem.innerHTML = `<span class="property-favorite">⭐</span>`;
                fragment.appendChild(propItem); return;
            }
            if (key.startsWith('tag::')) {
                const tagName = key.substring(5); 
                propItem.innerHTML = `<span class="property-key">#${tagName}</span>`;
                propItem.classList.add('property-tag'); 
            } else {
                const displayValue = String(val).trim();
                propItem.innerHTML = `<span class="property-key">${key}:</span> <span class="property-value">${displayValue}</span>`;
            }
            fragment.appendChild(propItem);
        };
        if (Array.isArray(value)) { value.forEach(v => processValue(v)); } else { processValue(value); }
    });

    if (hasVisibleProperties) {
        targetContainer.appendChild(fragment);
        targetContainer.style.display = 'flex'; 
        targetContainer.style.flexWrap = 'wrap'; 
        targetContainer.style.gap = 'var(--ls-space-2, 8px)'; 
        targetContainer.classList.remove('hidden'); 
    } else {
        targetContainer.style.display = 'none';
        targetContainer.classList.add('hidden');
    }
}

/**
 * Traverses upwards from the noteId to collect all ancestors.
 * @param {string} noteId - The ID of the note to start from.
 * @param {Array<Object>} allNotesOnPage - Flat list of all notes on the current page.
 * @returns {Array<Object>} Array of note objects, ordered from furthest ancestor to direct parent.
 */
function getNoteAncestors(noteId, allNotesOnPage) {
    const ancestors = [];
    if (!allNotesOnPage) { console.warn('getNoteAncestors called without allNotesOnPage'); return ancestors; }
    let currentNote = allNotesOnPage.find(note => String(note.id) === String(noteId));
    while (currentNote && currentNote.parent_note_id) {
        const parentNote = allNotesOnPage.find(note => String(note.id) === String(currentNote.parent_note_id));
        if (parentNote) { ancestors.unshift(parentNote); currentNote = parentNote; } else { break; }
    }
    return ancestors;
}

/**
 * Renders breadcrumbs for the focused note.
 * @param {string|null} focusedNoteId - The ID of the currently focused note, or null.
 * @param {Array<Object>} allNotesOnPage - Flat list of all notes for the current page.
 * @param {string} currentPageName - The name of the current page.
 */
function renderBreadcrumbs(focusedNoteId, allNotesOnPage, currentPageName) {
    if (!domRefs.breadcrumbsContainer) { console.warn('Breadcrumbs container not found in DOM.'); return; }
    if (!focusedNoteId || !allNotesOnPage || allNotesOnPage.length === 0) { domRefs.breadcrumbsContainer.innerHTML = ''; return; }
    const focusedNote = allNotesOnPage.find(n => String(n.id) === String(focusedNoteId));
    if (!focusedNote) { domRefs.breadcrumbsContainer.innerHTML = ''; return; }

    const ancestors = getNoteAncestors(focusedNoteId, allNotesOnPage);
    let html = `<a href="#" onclick="ui.showAllNotesAndLoadPage('${currentPageName}'); return false;">${currentPageName}</a>`;
    ancestors.forEach(ancestor => {
        const noteName = (ancestor.content ? (ancestor.content.split('\n')[0].substring(0, 30) + (ancestor.content.length > 30 ? '...' : '')) : `Note ${ancestor.id}`).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        html += ` &gt; <a href="#" onclick="ui.focusOnNote('${ancestor.id}'); return false;">${noteName}</a>`;
    });
    const focusedNoteName = (focusedNote.content ? (focusedNote.content.split('\n')[0].substring(0, 30) + (focusedNote.content.length > 30 ? '...' : '')) : `Note ${focusedNote.id}`).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    html += ` &gt; <span class="breadcrumb-current">${focusedNoteName}</span>`;
    domRefs.breadcrumbsContainer.innerHTML = html;
}

// Helper function to be called by breadcrumb page link
function showAllNotesAndLoadPage(pageName) {
    if (typeof ui !== 'undefined' && ui.showAllNotes) { ui.showAllNotes(); }
    if (typeof window.loadPage === 'function') { window.loadPage(pageName); } 
    else { console.warn('window.loadPage function not found. Cannot reload page for breadcrumb.'); }
}

// This function seems to duplicate renderProperties from note-renderer.js
// It should be reviewed and likely removed or merged.
function renderNoteProperties(note) {
    if (!note.properties || Object.keys(note.properties).length === 0) { return ''; }
    const propertyItems = Object.entries(note.properties).map(([name, value]) => {
        if (name.toLowerCase() === 'favorite' && String(value).toLowerCase() === 'true') {
            return `<span class="property-item favorite"><span class="property-favorite">⭐</span></span>`;
        }
        if (name.startsWith('tag::')) {
            const tagName = name.substring(5);
            return `<span class="property-item tag"><span class="property-key">#</span><span class="property-value">${tagName}</span></span>`;
        }
        if (Array.isArray(value)) {
            return value.map(v => `<span class="property-item"><span class="property-key">${name}</span><span class="property-value">${v}</span></span>`).join('');
        }
        return `<span class="property-item"><span class="property-key">${name}</span><span class="property-value">${value}</span></span>`;
    }).join('');
    return `<div class="note-properties">${propertyItems}</div>`;
}

function updateSidebarToggleButtons() {
    const leftToggle = domRefs.toggleLeftSidebarBtn;
    const rightToggle = domRefs.toggleRightSidebarBtn;
    const isLeftCollapsed = domRefs.leftSidebar && domRefs.leftSidebar.classList.contains('collapsed');
    const isRightCollapsed = domRefs.rightSidebar && domRefs.rightSidebar.classList.contains('collapsed');
    if (leftToggle) { leftToggle.textContent = isLeftCollapsed ? '☰' : '✕'; leftToggle.title = isLeftCollapsed ? 'Show left sidebar' : 'Hide left sidebar'; }
    if (rightToggle) { rightToggle.textContent = isRightCollapsed ? '☰' : '✕'; rightToggle.title = isRightCollapsed ? 'Show right sidebar' : 'Hide right sidebar'; }
}

document.addEventListener('DOMContentLoaded', () => {
    updateSidebarToggleButtons();
});

/**
 * Focuses on a specific note by hiding all other notes at the same level and above
 * @param {string} noteId - The ID of the note to focus on
 */
function focusOnNote(noteId) {
    window.currentFocusedNoteId = noteId; 
    const focusedNote = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    const notesContainer = document.getElementById('notes-container'); 
    if (!focusedNote) { console.warn(`Focus target note with ID ${noteId} not found.`); return; }

    const elementsToMakeVisible = new Set();
    const elementsToFocus = new Set();
    elementsToMakeVisible.add(focusedNote);
    elementsToFocus.add(focusedNote);

    function collectAndMarkDescendants(currentElement) {
        const childrenContainer = currentElement.querySelector('.note-children');
        if (childrenContainer) {
            const childNotes = Array.from(childrenContainer.children).filter(el => el.matches('.note-item'));
            childNotes.forEach(child => {
                elementsToMakeVisible.add(child);
                elementsToFocus.add(child);
                collectAndMarkDescendants(child); 
            });
        }
    }
    collectAndMarkDescendants(focusedNote);

    let currentAncestor = focusedNote.parentElement.closest('.note-item');
    while (currentAncestor) {
        elementsToMakeVisible.add(currentAncestor);
        currentAncestor = currentAncestor.parentElement.closest('.note-item');
    }

    const allNoteElements = document.querySelectorAll('#notes-container .note-item');
    allNoteElements.forEach(noteElement => {
        if (elementsToMakeVisible.has(noteElement)) { noteElement.classList.remove('note-hidden'); } 
        else { noteElement.classList.add('note-hidden'); }
        if (elementsToFocus.has(noteElement)) { noteElement.classList.add('note-focused'); } 
        else { noteElement.classList.remove('note-focused'); }
    });

    notesContainer.classList.add('has-focused-notes');
    const existingBtn = notesContainer.querySelector('.show-all-notes-btn');
    if (existingBtn) { existingBtn.remove(); }
    const showAllBtn = document.createElement('button');
    showAllBtn.className = 'show-all-notes-btn';
    showAllBtn.textContent = '← Show All Notes';
    showAllBtn.addEventListener('click', showAllNotes);
    notesContainer.insertBefore(showAllBtn, notesContainer.firstChild);

    if (window.notesForCurrentPage && window.currentPageName) {
        renderBreadcrumbs(noteId, window.notesForCurrentPage, window.currentPageName);
    } else {
        console.warn("Cannot render breadcrumbs: notesForCurrentPage or currentPageName is missing.");
        if (domRefs.breadcrumbsContainer) domRefs.breadcrumbsContainer.innerHTML = '';
    }
}

/**
 * Shows all notes and clears focus state
 */
function showAllNotes() {
    window.currentFocusedNoteId = null; 
    const allNotes = document.querySelectorAll('.note-item');
    const notesContainer = document.getElementById('notes-container');
    allNotes.forEach(note => {
        note.classList.remove('note-hidden');
        note.classList.remove('note-focused');
    });
    notesContainer.classList.remove('has-focused-notes');
    const showAllBtn = notesContainer.querySelector('.show-all-notes-btn');
    if (showAllBtn) { showAllBtn.remove(); }
    if (domRefs.breadcrumbsContainer) { domRefs.breadcrumbsContainer.innerHTML = ''; }
}

function getNestingLevel(noteElement) {
    let level = 0;
    let parent = noteElement.parentElement;
    while (parent) {
        if (parent.classList.contains('note-children')) { level++; }
        if (parent.id === 'notes-container') { break; }
        parent = parent.parentElement;
    }
    return level;
}

function updateParentVisuals(parentNoteElement) {
    if (!parentNoteElement) return;
    const noteId = parentNoteElement.dataset.noteId;
    const controlsEl = parentNoteElement.querySelector('.note-controls');
    if (!controlsEl || !window.notesForCurrentPage || !window.propertiesAPI) return; // Added checks for globals

    const children = window.notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteId));
    const hasChildren = children.length > 0;
    const existingArrow = controlsEl.querySelector('.note-collapse-arrow');
    if (existingArrow) { existingArrow.remove(); }

    if (hasChildren) {
        const arrow = document.createElement('span');
        arrow.className = 'note-collapse-arrow';
        arrow.dataset.noteId = noteId;
        arrow.dataset.collapsed = 'false';
        arrow.innerHTML = '<i data-feather="chevron-right"></i>';
        controlsEl.insertBefore(arrow, controlsEl.firstChild);
        
        arrow.addEventListener('click', async (e) => {
            e.stopPropagation();
            const isCollapsed = arrow.dataset.collapsed === 'true';
            const childrenContainer = parentNoteElement.querySelector('.note-children');
            if (!childrenContainer) return;
            try {
                arrow.dataset.collapsed = (!isCollapsed).toString();
                childrenContainer.style.display = isCollapsed ? 'block' : 'none';
                parentNoteElement.classList.toggle('collapsed', !isCollapsed);
                const childNotes = childrenContainer.querySelectorAll('.note-item');
                childNotes.forEach(child => { child.classList.toggle('note-hidden', !isCollapsed); });
                await propertiesAPI.setProperty({
                    entity_type: 'note', entity_id: parseInt(noteId),
                    name: 'collapsed', value: (!isCollapsed).toString()
                });
                if (typeof feather !== 'undefined') feather.replace();
            } catch (error) {
                console.error('Error updating collapse state:', error);
                arrow.dataset.collapsed = isCollapsed.toString();
                childrenContainer.style.display = isCollapsed ? 'none' : 'block';
                parentNoteElement.classList.toggle('collapsed', isCollapsed);
                alert('Failed to save collapse state. Please try again.');
            }
        });
        parentNoteElement.classList.add('has-children');
        const noteData = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
        if (noteData && noteData.properties && noteData.properties.collapsed === 'true') {
            arrow.dataset.collapsed = 'true';
            const childrenContainer = parentNoteElement.querySelector('.note-children');
            if (childrenContainer) {
                childrenContainer.style.display = 'none';
                parentNoteElement.classList.add('collapsed');
                childrenContainer.querySelectorAll('.note-item').forEach(child => { child.classList.add('note-hidden'); });
            }
        }
    } else {
        parentNoteElement.classList.remove('has-children');
    }
    if (typeof feather !== 'undefined') feather.replace();
}

/**
 * Initializes the page properties modal and its event listeners
 */
function initPagePropertiesModal() {
    // Ensure the modal is properly initialized
    const modal = domRefs.pagePropertiesModal;
    if (!modal) {
        console.error('Page properties modal element not found');
        return;
    }

    // Initialize Feather Icons for the modal
    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace({
            width: '18px',
            height: '18px',
            'stroke-width': '2'
        });
    }

    // Function to show the modal
    const showModal = () => {
        modal.classList.add('active');
        const modalContent = modal.querySelector('.generic-modal-content');
        if (modalContent) {
            modalContent.style.transform = 'scale(1)';
        }
    };

    // Function to hide the modal
    const hideModal = () => {
        modal.classList.remove('active');
        const modalContent = modal.querySelector('.generic-modal-content');
        if (modalContent) {
            modalContent.style.transform = 'scale(0.95)';
        }
    };

    // Close button handler
    const closeButton = modal.querySelector('#page-properties-modal-close');
    if (closeButton) {
        closeButton.addEventListener('click', hideModal);
    }

    // Click outside to close
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            hideModal();
        }
    });

    // Function to handle gear icon click
    const handleGearClick = async () => {
        console.log('Page properties gear clicked');
        if (!window.currentPageId || !(window.propertiesAPI && typeof window.propertiesAPI.getProperties === 'function')) {
            console.error('currentPageId or window.propertiesAPI.getProperties is not available.');
            alert('Cannot load page properties at the moment.');
            return;
        }
        try {
            const properties = await window.propertiesAPI.getProperties('page', window.currentPageId);
            
            if (typeof window.displayPageProperties === 'function') {
                window.displayPageProperties(properties);
                showModal();
            } else {
                console.error('window.displayPageProperties is not defined. Cannot populate property modal.');
            }
        } catch (error) {
            console.error("Error fetching or displaying page properties:", error);
            alert("Error loading page properties.");
        }
    };

    // Attach click handler to gear icon
    const attachGearClickListener = () => {
        const gearElement = document.getElementById('page-properties-gear') || 
                           document.querySelector('.page-title-gear') ||
                           document.querySelector('[data-feather="settings"]') ||
                           document.querySelector('svg.feather-settings');
        
        if (gearElement && !gearElement.hasAttribute('data-properties-listener-attached')) {
            gearElement.setAttribute('data-properties-listener-attached', 'true');
            gearElement.addEventListener('click', handleGearClick);
            console.log('Page properties gear click listener attached successfully');
        }
    };

    // Try to attach immediately and after delays
    attachGearClickListener();
    setTimeout(attachGearClickListener, 100);
    setTimeout(attachGearClickListener, 500);

    // Event delegation as backup
    const pageHeaderContainer = document.querySelector('.page-header') || document.querySelector('#current-page-title-container');
    if (pageHeaderContainer && !pageHeaderContainer.hasAttribute('data-properties-delegation-attached')) {
        pageHeaderContainer.setAttribute('data-properties-delegation-attached', 'true');
        pageHeaderContainer.addEventListener('click', (e) => {
            if (e.target.matches('#page-properties-gear, .page-title-gear, [data-feather="settings"], svg.feather-settings') ||
                e.target.closest('#page-properties-gear, .page-title-gear, [data-feather="settings"], svg.feather-settings')) {
                handleGearClick();
            }
        });
    }
}

function updateSaveStatusIndicatorVisuals() {
    const indicator = document.getElementById('save-status-indicator');
    if (!indicator) { console.warn('Save status indicator element not found.'); return; }
    const currentSaveStatus = saveStatus; 
    const splashScreen = document.getElementById('splash-screen');
    const isSplashVisible = splashScreen && !splashScreen.classList.contains('hidden');
    if (isSplashVisible) { indicator.classList.add('status-hidden'); indicator.innerHTML = ''; return; } 
    else { indicator.classList.remove('status-hidden'); }
    indicator.classList.remove('status-saved', 'status-pending', 'status-error');
    indicator.classList.add(`status-${currentSaveStatus}`);
    let iconHtml = '';
    switch (currentSaveStatus) {
        case 'saved': iconHtml = '<i data-feather="check-circle"></i>'; indicator.title = 'All changes saved'; break;
        case 'pending': iconHtml = `<div class="dot-spinner"><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div></div>`; indicator.title = 'Saving changes...'; break;
        case 'error': iconHtml = '<i data-feather="alert-triangle"></i>'; indicator.title = 'Error saving changes. Please try again.'; break;
        default: console.warn(`Unknown save status: ${currentSaveStatus}. Defaulting visual to 'saved'.`); indicator.classList.remove('status-pending', 'status-error'); indicator.classList.add(`status-saved`); iconHtml = '<i data-feather="check-circle"></i>'; indicator.title = 'All changes saved'; break;
    }
    indicator.innerHTML = iconHtml;
    if (currentSaveStatus === 'saved' || currentSaveStatus === 'error') {
        if (typeof feather !== 'undefined' && feather.replace) { feather.replace({ width: '18px', height: '18px', 'stroke-width': '2' }); } 
        else { console.warn('Feather Icons library not found.'); if (currentSaveStatus === 'saved') indicator.textContent = '✓'; if (currentSaveStatus === 'error') indicator.textContent = '!'; }
    }
}

function updateSaveStatusIndicator(newStatus) {
    setSaveStatus(newStatus); 
    updateSaveStatusIndicatorVisuals(); 
}

// Template related functions (to be potentially moved)
async function handleNoteTemplateInsertion(contentDiv, templateName) { 
    console.log('[Templates] handleNoteTemplateInsertion called with:', templateName);
    // Actual insertion logic is complex and deferred.
    // For now, just replace the trigger.
    if (contentDiv && contentDiv.textContent.endsWith('/' + templateName.split(' ')[0])) { // crude match
        contentDiv.textContent = contentDiv.textContent.replace(/\/[^\s]*$/, `[[Template: ${templateName}]]`);
    } else if (contentDiv && contentDiv.textContent.includes('/')) {
         contentDiv.textContent = contentDiv.textContent.replace('/', `[[Template: ${templateName}]]` );
    }
    templateAutocomplete.hide();
}
async function showPageTemplateMenu(pageId) { console.log('[Templates] showPageTemplateMenu called for pageId:', pageId); }
function addTemplateButtonToPageProperties() { console.log('[Templates] addTemplateButtonToPageProperties called'); }

const templateAutocomplete = {
    templates: [],
    dropdownEl: null,
    activeTarget: null,
    selectedIndex: -1,

    async init() {
        console.log('[Templates] templateAutocomplete.init()');
        this.dropdownEl = document.createElement('div');
        this.dropdownEl.className = 'template-autocomplete-dropdown';
        this.dropdownEl.style.display = 'none';
        this.dropdownEl.style.position = 'absolute';
        this.dropdownEl.style.border = '1px solid #ccc';
        this.dropdownEl.style.backgroundColor = 'white';
        this.dropdownEl.style.zIndex = '1000';
        document.body.appendChild(this.dropdownEl);
        await this.loadTemplates();

        // Add global click listener to hide dropdown
        document.addEventListener('click', (e) => {
            if (this.dropdownEl && !this.dropdownEl.contains(e.target) && this.activeTarget !== e.target) {
                this.hide();
            }
        }, true); // Use capture phase

        // Add keyboard navigation
        // Storing the bound function reference for potential future removal (e.g., in a destroy method)
        this._boundKeyDownHandler = this._handleKeyDown.bind(this);
        document.addEventListener('keydown', this._boundKeyDownHandler, true); // true for capture phase
    },

    _handleKeyDown(e) {
        // If dropdown is not active, do nothing and let event propagate.
        if (!this.dropdownEl || this.dropdownEl.style.display !== 'block' || !this.activeTarget) {
            return; 
        }

        // If dropdown IS active, then we will handle specific keys.
        // The activeTarget check ensures context.
        if (!this.activeTarget.matches('.note-content.edit-mode')) {
            // console.log('[Templates] _handleKeyDown: Dropdown is active, but target is not a note content div in edit mode.');
            // This path should ideally not be hit if show() correctly sets activeTarget
            // and hide() clears it / click-away logic is sound.
            // If it does, letting event propagate might be okay, or we could hide().
            return; 
        }
        
        // console.log('[Templates] _handleKeyDown CAPTURE key:', e.key, 'SelectedIndex:', this.selectedIndex);

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault(); // Must be first
                e.stopPropagation(); // Then stop others
                // console.log('[Templates] ArrowDown CAPTURE. Current selectedIndex:', this.selectedIndex, 'Templates length:', this.templates ? this.templates.length : 'undefined');
                if (this.templates && this.templates.length > 0 && this.selectedIndex < this.templates.length - 1) {
                    const items = this.dropdownEl.querySelectorAll('li');
                    if (this.selectedIndex > -1 && items[this.selectedIndex]) {
                        items[this.selectedIndex].classList.remove('selected');
                    }
                    this.selectedIndex++;
                    if (items[this.selectedIndex]) {
                        items[this.selectedIndex].classList.add('selected');
                        // console.log('[Templates] ArrowDown CAPTURE: New selectedIndex:', this.selectedIndex, 'Item:', items[this.selectedIndex].textContent);
                    }
                }
                break;
            case 'ArrowUp':
                e.preventDefault(); // Must be first
                e.stopPropagation(); // Then stop others
                // console.log('[Templates] ArrowUp CAPTURE. Current selectedIndex:', this.selectedIndex);
                if (this.templates && this.templates.length > 0 && this.selectedIndex > 0) {
                    const items = this.dropdownEl.querySelectorAll('li');
                    if (items[this.selectedIndex]) { // current item
                        items[this.selectedIndex].classList.remove('selected');
                    }
                    this.selectedIndex--;
                    if (items[this.selectedIndex]) { // new current item
                        items[this.selectedIndex].classList.add('selected');
                        // console.log('[Templates] ArrowUp CAPTURE: New selectedIndex:', this.selectedIndex, 'Item:', items[this.selectedIndex].textContent);
                    }
                }
                break;
            case 'Enter':
                e.preventDefault(); // Must be first
                e.stopPropagation(); // Then stop others
                // console.log('[Templates] Enter CAPTURE. SelectedIndex:', this.selectedIndex);
                if (this.selectedIndex !== -1 && this.templates && this.templates[this.selectedIndex]) {
                    const templateName = this.templates[this.selectedIndex].name;
                    // console.log('[Templates] Enter CAPTURE: Attempting to insert template:', templateName);
                    this._insertTemplate(this.activeTarget, templateName);
                } else {
                    // console.log('[Templates] Enter CAPTURE: no item selected or templates array issue.');
                    this.hide(); // Hide if no selection or issue with templates
                }
                break;
            case 'Escape':
                e.preventDefault(); // Must be first
                e.stopPropagation(); // Then stop others
                // console.log('[Templates] Escape CAPTURE. Hiding dropdown.');
                this.hide();
                break;
            default:
                // If the dropdown is active but we don't handle the key (e.g., typing a letter),
                // we let the event propagate by doing nothing here. This allows typing into the
                // note content, which might be desired for filtering later or if the user decides
                // not to use a template. The problem of the dropdown disappearing on typing
                // will be handled by other logic (e.g., in handleNoteInput or by filtering).
                break; 
        }
    },

    _insertTemplate(contentDiv, templateName) {
        if (!contentDiv || !templateName) return;

        try {
            // Get the current cursor position
            const selection = window.getSelection();
            if (!selection.rangeCount) return;
            
            const range = selection.getRangeAt(0);
            const cursorPosition = range.startOffset;

            // Get the text content
            const text = contentDiv.textContent || '';
            
            // Find the last '/' before the cursor
            const lastSlashIndex = text.lastIndexOf('/', cursorPosition);
            if (lastSlashIndex === -1) return;

            // Find the template object
            const template = this.templates.find(t => t.name === templateName);
            if (!template) {
                console.error(`[Templates] Template "${templateName}" not found.`);
                this.hide(); // Still hide the dropdown
                return;
            }

            const templateContent = template.content || '';

            // Create new text with template content inserted
            const beforeSlash = text.substring(0, lastSlashIndex);
            const afterCursor = text.substring(cursorPosition);
            const newText = beforeSlash + templateContent + afterCursor;

            // Update the content
            contentDiv.textContent = newText;

            // Set cursor position after the inserted template
            const newCursorPosition = beforeSlash.length + templateContent.length;
            const newRange = document.createRange();

            // Ensure contentDiv has a text node if it's empty, otherwise setStart might fail
            if (contentDiv.childNodes.length === 0) {
                contentDiv.appendChild(document.createTextNode(''));
            }
            // Try to set range on firstChild, fallback to contentDiv itself if no child or firstChild is not a text node
            const nodeToSetCursorIn = (contentDiv.firstChild && contentDiv.firstChild.nodeType === Node.TEXT_NODE) ? contentDiv.firstChild : contentDiv;
            const maxOffset = nodeToSetCursorIn.nodeType === Node.TEXT_NODE ? nodeToSetCursorIn.textContent.length : nodeToSetCursorIn.childNodes.length;
            
            newRange.setStart(nodeToSetCursorIn, Math.min(newCursorPosition, maxOffset));
            newRange.setEnd(nodeToSetCursorIn, Math.min(newCursorPosition, maxOffset));
            selection.removeAllRanges();
            selection.addRange(newRange);

            // Update dataset for save mechanism
            contentDiv.dataset.rawContent = contentDiv.textContent;

            // Trigger input event to ensure note content is saved
            contentDiv.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (error) {
            console.error('[Templates] Error inserting template:', error);
        } finally {
            this.hide();
        }
    },

    show(targetElement) {
        this.activeTarget = targetElement;
        this.selectedIndex = -1; // Reset selection
        console.log('[Templates] templateAutocomplete.show() called for target:', targetElement);
        
        if (!this.dropdownEl) {
            console.error('[Templates] Dropdown element not initialized.');
            return;
        }

        if (!this.templates || this.templates.length === 0) {
            console.log('[Templates] No templates to show or still loading.');
            this.dropdownEl.innerHTML = '<li>Loading templates...</li>';
        } else {
            this.dropdownEl.innerHTML = ''; // Clear previous items
            const ul = document.createElement('ul');
            ul.style.listStyle = 'none';
            ul.style.margin = '0';
            ul.style.padding = '5px';
            
            // Create all items first
            const items = this.templates.map((template, index) => {
                const li = document.createElement('li');
                li.textContent = template.name;
                li.style.padding = '5px';
                li.style.cursor = 'pointer';
                li.dataset.index = index;
                li.dataset.templateName = template.name;
                return li;
            });

            // Then add event listeners
            items.forEach((li, index) => {
                li.addEventListener('mouseover', () => {
                    if (this.selectedIndex !== -1) {
                        items[this.selectedIndex].classList.remove('selected');
                    }
                    this.selectedIndex = index;
                    li.classList.add('selected');
                });
                li.addEventListener('mouseout', () => {
                    if (this.selectedIndex !== index) {
                        li.classList.remove('selected');
                    }
                });
                li.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const templateName = li.dataset.templateName;
                    if (templateName) {
                        this._insertTemplate(this.activeTarget, templateName);
                    }
                });
                ul.appendChild(li);
            });

            this.dropdownEl.appendChild(ul);

            // Auto-select first item
            if (items.length > 0) {
                this.selectedIndex = 0;
                items[0].classList.add('selected');
            }
        }

        const rect = targetElement.getBoundingClientRect();
        this.dropdownEl.style.top = `${rect.bottom + window.scrollY}px`;
        this.dropdownEl.style.left = `${rect.left + window.scrollX}px`;
        this.dropdownEl.style.display = 'block';
        console.log('[Templates] Dropdown displayed with items:', this.templates.length);
    },

    hide() {
        if (this.dropdownEl) {
            this.dropdownEl.style.display = 'none';
        }
        this.activeTarget = null;
        this.selectedIndex = -1;
        console.log('[Templates] Dropdown hidden');
    },

    async loadTemplates() {
        console.log('[Templates] templateAutocomplete.loadTemplates()');
        if (window.templatesAPI && typeof window.templatesAPI.getTemplates === 'function') {
            try {
                // Pass 'note' as the type parameter since this is for note templates
                const response = await window.templatesAPI.getTemplates('note');
                // Handle both array and object response formats
                this.templates = Array.isArray(response) ? response : (response.data || []);
                console.log('[Templates] Templates loaded:', this.templates);
                
                if (this.templates.length === 0) {
                    this.templates = [{name: "No templates available", content: ""}];
                }
            } catch (error) {
                console.error('[Templates] Error loading templates:', error);
                this.templates = [{name: "Error loading templates. Please try again.", content: ""}];
            }
        } else {
            console.error('[Templates] window.templatesAPI.getTemplates is not available.');
            this.templates = [{name: "Template system not available", content: ""}];
        }
    }
};

function getCursorCharacterOffsetWithin(element) { /* ... */ return 0; } // Stubbed

function handleNoteInput(e) {
    if (!e.target || !e.target.matches || !e.target.matches('.note-content.edit-mode')) {
        // console.log('[Templates] handleNoteInput: Event target is not a note content div in edit mode.');
        return;
    }
    const contentDiv = e.target;
    const text = contentDiv.textContent || '';
    
    // Determine cursor position carefully
    let cursorPosition = 0;
    const selection = window.getSelection();
    if (selection && selection.rangeCount > 0) {
        const range = selection.getRangeAt(0);
        // Ensure the cursor is within the contentDiv.
        // If range.startContainer is contentDiv itself, startOffset is char offset or child index.
        // If range.startContainer is a text node inside contentDiv, startOffset is char offset within text node.
        // For simplicity, assuming cursor is directly in contentDiv or its direct text node.
        // A more robust way might involve traversing from range.startContainer up to contentDiv.
        if (contentDiv.contains(range.startContainer)) {
             cursorPosition = range.startOffset;
             // If startContainer is not contentDiv itself but a child (e.g. text node)
             // we might need to sum offsets of previous siblings if contentDiv has mixed content.
             // However, note-content is usually a single text block or gets replaced.
             // For this specific case, this should be mostly fine.
        } else {
            // Cursor is not within the contentDiv that received the input event. Strange.
            // console.log('[Templates] handleNoteInput: Cursor not in the target contentDiv.');
            return;
        }
    } else {
        // console.log('[Templates] handleNoteInput: No selection or range.');
        // If no selection, we might not be able to determine where to show dropdown.
        // However, an input event usually implies there's a cursor/selection.
        return; 
    }

    const isDropdownVisible = templateAutocomplete.dropdownEl && 
                            templateAutocomplete.dropdownEl.style.display === 'block';

    let shouldShowDropdown = false;
    let shouldHideDropdown = false;
    // let potentialFilterText = ""; // For future filtering logic

    // Analyze text around cursor to decide whether to show/hide/keep open
    if (cursorPosition > 0) {
        const textBeforeCursor = text.substring(0, cursorPosition);
        const lastCharIndexInTextBeforeCursor = textBeforeCursor.length - 1;
        
        if (lastCharIndexInTextBeforeCursor >= 0 && textBeforeCursor[lastCharIndexInTextBeforeCursor] === '/') {
            // If the character right before cursor is '/', show.
            shouldShowDropdown = true;
        } else {
            // If not '/', check if we are in "template typing mode"
            // This means: dropdown is already visible, and the text from the last '/' 
            // to the cursor is a valid non-empty sequence without spaces/newlines.
            if (isDropdownVisible) {
                const lastSlashGlobalIndex = textBeforeCursor.lastIndexOf('/');
                if (lastSlashGlobalIndex !== -1) {
                    const textAfterSlash = textBeforeCursor.substring(lastSlashGlobalIndex + 1);
                    // If textAfterSlash is empty, it means cursor is right after / - handled by previous 'if'
                    // If textAfterSlash is not empty, check if it's a valid filter prefix
                    if (textAfterSlash.length > 0 && !textAfterSlash.includes(' ') && !textAfterSlash.includes('\n')) {
                        // Likely typing a template name. Keep dropdown open.
                        shouldShowDropdown = true; // Effectively "keep open"
                        // potentialFilterText = textAfterSlash;
                    } else {
                        // Invalid char after slash (space, newline) or slash was deleted. Hide.
                        shouldHideDropdown = true;
                    }
                } else {
                    // No '/' found before cursor while dropdown is visible. Hide.
                    shouldHideDropdown = true;
                }
            }
            // If dropdown is not visible and char before cursor is not '/', do nothing.
        }
    } else if (isDropdownVisible) { 
        // Cursor is at the beginning of the text (cursorPosition === 0), but dropdown is visible.
        // This implies the trigger '/' was deleted.
        shouldHideDropdown = true;
    }
    // If text is empty, and dropdown is visible, hide it.
    if (text.length === 0 && isDropdownVisible) {
        shouldHideDropdown = true;
    }


    if (shouldShowDropdown) {
        // If it should be shown (or kept open)
        if (!isDropdownVisible) {
            // console.log('[Templates] handleNoteInput: Showing dropdown.');
            templateAutocomplete.show(contentDiv); 
        }
        // If it's already visible, do nothing here, just don't hide it.
        // Later, filtering logic would go here:
        // templateAutocomplete.filter(potentialFilterText);
    } else if (shouldHideDropdown && isDropdownVisible) {
        // console.log('[Templates] handleNoteInput: Hiding dropdown.');
        templateAutocomplete.hide();
    }
    // Otherwise, do nothing (e.g. dropdown not visible, no trigger).
}

// Add global click handler for template dropdown
document.addEventListener('click', (e) => {
    if (templateAutocomplete.dropdownEl && 
        templateAutocomplete.dropdownEl.style.display === 'block' && 
        !templateAutocomplete.dropdownEl.contains(e.target) &&
        !e.target.matches('.note-content.edit-mode')) {
        templateAutocomplete.hide();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    templateAutocomplete.init(); // init will call loadTemplates
    addTemplateButtonToPageProperties(); // Remains stubbed for now
    // The 'input' event listener for notesContainer in app.js handles general input.
    // This document 'input' listener in ui.js is specifically for template autocomplete.
    // It might conflict or be redundant if app.js notesContainer input listener is too broad.
    // For now, let's assume this specific one is for templates.
    // Re-evaluating: The input listener in app.js is for saving notes.
    // This one in ui.js should be specifically for the template trigger.
    // It's better to attach this to notesContainer as well for consistency.
    // However, the original code has `document.addEventListener('input', handleNoteInput);`
    // Let's keep it for now, but be aware it might need refinement.
    console.log('[Templates] Adding document input listener for handleNoteInput');
    document.addEventListener('input', handleNoteInput);
});

// Export the main UI object
export const ui = {
    // Functions imported from note-elements.js
    displayNotes,
    updateNoteElement,
    addNoteElement,
    removeNoteElement,
    moveNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    handleNoteDrop,

    // Functions imported from note-renderer.js
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    switchToRenderedMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties,

    // Functions remaining in ui.js
    domRefs,
    updatePageTitle,
    updatePageList,
    updateActivePageLink,
    showPropertyInNote,
    removePropertyFromNote,
    renderTransclusion,
    calendarWidget,
    showGenericInputModal,
    showGenericConfirmModal,
    extractPropertiesFromContent,
    renderInlineProperties,
    renderPageInlineProperties,
    getNoteAncestors,
    renderBreadcrumbs,
    showAllNotesAndLoadPage,
    renderNoteProperties,
    focusOnNote,
    showAllNotes,
    getNestingLevel,
    updateParentVisuals,
    initPagePropertiesModal,
    updateSaveStatusIndicatorVisuals,
    updateSaveStatusIndicator,
    handleNoteTemplateInsertion,
    showPageTemplateMenu,
    addTemplateButtonToPageProperties,
    templateAutocomplete
};

// Make ui available globally for backward compatibility
if (typeof window !== 'undefined') {
    window.ui = ui;
}
