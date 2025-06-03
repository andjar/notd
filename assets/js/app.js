/**
 * Main application module for NotTD
 * Handles state management, event handling, and coordination between UI and API
 * @module app
 */

// State
let currentPageId = null;
let currentPageName = null;
let notesForCurrentPage = []; // Flat list of notes with properties

// Make some variables globally accessible for drag and drop
window.currentPageId = null;
window.notesForCurrentPage = [];
window.notesAPI = notesAPI;
window.currentFocusedNoteId = null; // Initialize focused note ID

/**
 * Safely adds an event listener to an element if it exists
 * @param {HTMLElement|null} element - The element to add the listener to
 * @param {string} event - The event type
 * @param {Function} handler - The event handler
 * @param {string} elementName - Name of the element for logging
 */
function safeAddEventListener(element, event, handler, elementName) {
    if (!element) {
        console.warn(`Cannot add ${event} listener: ${elementName} element not found`);
        return;
    }
    element.addEventListener(event, handler);
}

// Get DOM references from UI module
const {
    notesContainer,
    pageListContainer,
    addRootNoteBtn,
    toggleLeftSidebarBtn,
    toggleRightSidebarBtn,
    leftSidebar,
    rightSidebar,
    globalSearchInput,
    backlinksContainer,
    currentPageTitleEl,
    pagePropertiesGear,
    pagePropertiesModal,
    pagePropertiesModalClose,
    pagePropertiesList,
    addPagePropertyBtn,
    openPageSearchModalBtn,
    pageSearchModal,
    pageSearchModalInput,
    pageSearchModalResults,
    pageSearchModalCancel
} = ui.domRefs;

// Debug logging for DOM elements
console.log('DOM Elements Status:', {
    notesContainer: !!notesContainer,
    pageListContainer: !!pageListContainer,
    addRootNoteBtn: !!addRootNoteBtn,
    toggleLeftSidebarBtn: !!toggleLeftSidebarBtn,
    toggleRightSidebarBtn: !!toggleRightSidebarBtn,
    leftSidebar: !!leftSidebar,
    rightSidebar: !!rightSidebar,
    globalSearchInput: !!globalSearchInput,
    backlinksContainer: !!backlinksContainer,
    currentPageTitleEl: !!currentPageTitleEl,
    pagePropertiesGear: !!pagePropertiesGear,
    pagePropertiesModal: !!pagePropertiesModal,
    pagePropertiesModalClose: !!pagePropertiesModalClose,
    pagePropertiesList: !!pagePropertiesList,
    addPagePropertyBtn: !!addPagePropertyBtn,
    openPageSearchModalBtn: !!openPageSearchModalBtn,
    pageSearchModal: !!pageSearchModal,
    pageSearchModalInput: !!pageSearchModalInput,
    pageSearchModalResults: !!pageSearchModalResults,
    pageSearchModalCancel: !!pageSearchModalCancel
});

// Verify critical DOM elements
const criticalElements = {
    notesContainer,
    pageListContainer
};

// Check if critical elements exist
Object.entries(criticalElements).forEach(([name, element]) => {
    if (!element) {
        console.error(`Critical element missing: ${name}`);
    }
});

/**
 * Loads a page and its notes
 * @param {string} pageName - Name of the page to load
 * @param {boolean} [focusFirstNote=false] - Whether to focus the first note
 * @param {boolean} [updateHistory=true] - Whether to update browser history
 */
async function loadPage(pageName, focusFirstNote = false, updateHistory = true) {
    if (typeof ui !== 'undefined' && ui.showAllNotes) {
        ui.showAllNotes(); // Clear any existing focus and breadcrumbs
    } else {
        // This case should ideally not happen if ui.js is loaded before app.js
        console.warn('ui.showAllNotes() is not available. Skipping focus clear on page load.');
        window.currentFocusedNoteId = null; // Manually reset if ui.js is not ready
        if (ui.domRefs && ui.domRefs.breadcrumbsContainer) {
             ui.domRefs.breadcrumbsContainer.innerHTML = ''; // Clear breadcrumbs manually
        }
    }
    console.log(`Loading page: ${pageName}`);
    try {
        const pageData = await pagesAPI.getPage(pageName);
        
        if (pageData && pageData.id) {
            // Page exists, load it
            currentPageId = pageData.id;
            currentPageName = pageData.name;
            window.currentPageName = currentPageName; // Expose globally for breadcrumbs
            
            // Update global variables for drag and drop
            window.currentPageId = currentPageId;
            
            // Update URL if needed
            if (updateHistory) {
                const url = new URL(window.location);
                url.searchParams.set('page', pageName);
                window.history.pushState({ pageName }, '', url);
            }
            
            // Load page properties
            const pageProperties = await propertiesAPI.getProperties('page', currentPageId);
            displayPageProperties(pageProperties);
            
            notesForCurrentPage = await notesAPI.getNotesForPage(currentPageId);
            window.notesForCurrentPage = notesForCurrentPage; // Update global
            ui.updatePageTitle(currentPageName);
            ui.displayNotes(notesForCurrentPage, currentPageId);
            ui.updateActivePageLink(currentPageName);

            // Load backlinks
            const backlinks = await searchAPI.getBacklinks(currentPageName);
            displayBacklinks(backlinks);

            // Handle transclusions
            await handleTransclusions();

            if (focusFirstNote) {
                const firstNote = notesContainer.querySelector('.note-content');
                if (firstNote) firstNote.focus();
            }
        } else {
            // Page doesn't exist, check if it's a journal page
            const journalPattern = /^\d{4}-\d{2}-\d{2}$|^Journal$/i;
            if (journalPattern.test(pageName)) {
                console.log(`Creating journal page: ${pageName}`);
                const newPage = await pagesAPI.createPage({ name: pageName });
                
                if (newPage && newPage.id) {
                    await fetchAndDisplayPages(newPage.name);
                    await loadPage(newPage.name, true);
                } else {
                    currentPageName = `Failed: ${pageName}`;
                    window.currentPageName = currentPageName; // Expose globally
                    updatePageTitle(currentPageName);
                    notesContainer.innerHTML = `<p>Could not create page: ${pageName}</p>`;
                }
            } else {
                currentPageName = `Not Found: ${pageName}`;
                window.currentPageName = currentPageName; // Expose globally
                updatePageTitle(currentPageName);
                notesContainer.innerHTML = `<p>Page "${pageName}" not found.</p>`;
                updateActivePageLink(null);
            }
        }
    } catch (error) {
        console.error('Error loading page:', error);
        currentPageName = `Error: ${pageName}`;
        window.currentPageName = currentPageName; // Expose globally
        updatePageTitle(currentPageName);
        notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
    }
}

/**
 * Fetches and displays the page list
 * @param {string} [activePageName] - Name of the page to mark as active
 */
async function fetchAndDisplayPages(activePageName) {
    try {
        const pages = await pagesAPI.getAllPages();
        updatePageList(pages, activePageName || currentPageName);
    } catch (error) {
        console.error('Error fetching pages:', error);
        pageListContainer.innerHTML = '<li>Error loading pages.</li>';
    }
}

/**
 * Loads or creates today's journal page
 */
async function loadOrCreateDailyNotePage() {
    const todayPageName = getTodaysJournalPageName();
    await loadPage(todayPageName, true);
    await fetchAndDisplayPages(todayPageName);
}

/**
 * Gets note data by ID
 * @param {string} noteId - Note ID
 * @returns {Object|undefined} Note data
 */
function getNoteDataById(noteId) {
    return notesForCurrentPage.find(n => String(n.id) === String(noteId));
}

/**
 * Gets note element by ID
 * @param {string} noteId - Note ID
 * @returns {HTMLElement|null} Note element
 */
function getNoteElementById(noteId) {
    return notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
}

/**
 * Handles transclusions in notes
 * @param {Array} notes - Array of notes to process (optional, defaults to current page notes)
 */
async function handleTransclusions(notes = notesForCurrentPage) {
    const placeholders = document.querySelectorAll('.transclusion-placeholder');
    console.log(`Found ${placeholders.length} transclusion placeholders.`);

    for (const placeholder of placeholders) {
        const blockRef = placeholder.dataset.blockRef;
        if (!blockRef) {
            console.warn('Placeholder found with no data-block-ref', placeholder);
            placeholder.textContent = 'Invalid block reference (missing ref)';
            placeholder.classList.add('error');
            continue;
        }

        console.log(`Processing transclusion for blockRef: ${blockRef}`);

        try {
            // Ensure blockRef is treated as a string for API consistency if needed
            const note = await notesAPI.getNote(String(blockRef)); 
            
            if (note && note.content) {
                console.log(`Content found for blockRef ${blockRef}:`, note.content.substring(0, 100) + "...");
                ui.renderTransclusion(placeholder, note.content, blockRef); // Pass the note ID
            } else if (note) {
                console.warn(`Content is empty or missing for blockRef ${blockRef}. Note data:`, note);
                placeholder.textContent = 'Block content is empty';
                placeholder.classList.add('error');
            } else {
                console.warn(`Block not found for blockRef ${blockRef}.`);
                placeholder.textContent = 'Block not found';
                placeholder.classList.add('error');
            }
        } catch (error) {
            console.error(`Error loading transclusion for blockRef ${blockRef}:`, error);
            placeholder.textContent = 'Error loading block';
            placeholder.classList.add('error');
        }
    }
}

/**
 * Displays backlinks for the current page
 * @param {Array} backlinks - Array of backlink objects
 */
function displayBacklinks(backlinks) {
    if (!backlinksContainer) return;

    if (!backlinks || backlinks.length === 0) {
        backlinksContainer.innerHTML = '<p>No backlinks found.</p>';
        return;
    }

    const html = backlinks.map(link => `
        <div class="backlink-item">
            <a href="#" class="page-link" data-page-name="${link.source_page_name}">
                ${link.source_page_name}
            </a>
            <div class="backlink-snippet">${link.content_snippet}</div>
        </div>
    `).join('');

    backlinksContainer.innerHTML = html;
}

// Sidebar state management
const sidebarState = {
    left: {
        isCollapsed: false,
        element: null, 
        button: null,  
        toggle() {
            if (!this.element || !this.button) return;
            this.isCollapsed = !this.isCollapsed;
            this.element.classList.toggle('collapsed', this.isCollapsed);
            document.body.classList.toggle('left-sidebar-collapsed', this.isCollapsed);
            localStorage.setItem('leftSidebarCollapsed', this.isCollapsed);
            this.updateButtonVisuals();
        },
        updateButtonVisuals() {
            if (!this.button) return;
            this.button.textContent = this.isCollapsed ? '☰' : '✕';
            this.button.title = this.isCollapsed ? 'Show left sidebar' : 'Hide left sidebar';
        }
    },
    right: {
        isCollapsed: false,
        element: null, 
        button: null,  
        toggle() {
            if (!this.element || !this.button) return;
            this.isCollapsed = !this.isCollapsed;
            this.element.classList.toggle('collapsed', this.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.isCollapsed);
            localStorage.setItem('rightSidebarCollapsed', this.isCollapsed);
            this.updateButtonVisuals();
        },
        updateButtonVisuals() {
            if (!this.button) return;
            this.button.textContent = this.isCollapsed ? '☰' : '✕';
            this.button.title = this.isCollapsed ? 'Show right sidebar' : 'Hide right sidebar';
        }
    },
    init() {
        this.left.element = ui.domRefs.leftSidebar;
        this.left.button = ui.domRefs.toggleLeftSidebarBtn;
        this.right.element = ui.domRefs.rightSidebar;
        this.right.button = ui.domRefs.toggleRightSidebarBtn;

        if (this.left.element && this.left.button) {
            this.left.isCollapsed = localStorage.getItem('leftSidebarCollapsed') === 'true';
            this.left.element.classList.toggle('collapsed', this.left.isCollapsed);
            document.body.classList.toggle('left-sidebar-collapsed', this.left.isCollapsed);
            this.left.updateButtonVisuals();
            this.left.button.addEventListener('click', () => this.left.toggle());
        }
        if (this.right.element && this.right.button) {
            this.right.isCollapsed = localStorage.getItem('rightSidebarCollapsed') === 'true';
            this.right.element.classList.toggle('collapsed', this.right.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.right.isCollapsed);
            this.right.updateButtonVisuals();
            this.right.button.addEventListener('click', () => this.right.toggle());
        }
    }
};

// Event Handlers

// Sidebar toggle (Remove old direct listeners if sidebarState.init handles it)
// if (toggleLeftSidebarBtn && leftSidebar) { ... } // This is now handled by sidebarState.init()
// if (toggleRightSidebarBtn && rightSidebar) { ... } // This is now handled by sidebarState.init()

// Page list clicks
if (criticalElements.pageListContainer) {
    safeAddEventListener(criticalElements.pageListContainer, 'click', (e) => {
        if (e.target.matches('a[data-page-name]')) {
            e.preventDefault();
            loadPage(e.target.dataset.pageName);
        }
    }, 'pageListContainer');
}

// Global search
const debouncedSearch = debounce(async (query) => {
    const searchResults = document.getElementById('search-results');
    
    if (!query.trim()) {
        searchResults.classList.remove('has-results');
        searchResults.innerHTML = '';
        return;
    }
    
    try {
        const results = await searchAPI.search(query);
        displaySearchResults(results);
    } catch (error) {
        console.error('Search error:', error);
        searchResults.innerHTML = '<div class="search-result-item">Error performing search</div>';
        searchResults.classList.add('has-results');
    }
}, 300);

if (globalSearchInput) {
    safeAddEventListener(globalSearchInput, 'input', (e) => {
        debouncedSearch(e.target.value);
    }, 'globalSearchInput');
}

// Add root note
safeAddEventListener(addRootNoteBtn, 'click', async () => {
    if (!currentPageId) {
        alert('Please select or create a page first.');
        return;
    }

    const tempId = generateTempId();
    const tempNote = {
        id: tempId,
        content: '',
        page_id: currentPageId,
        parent_note_id: null,
        properties: {},
        children: []
    };

    // Optimistic UI update
    const noteEl = renderNote(tempNote, 0);
    if (notesContainer) {
        notesContainer.appendChild(noteEl);
        const contentDiv = noteEl.querySelector('.note-content');
        if (contentDiv) contentDiv.focus();
    }

    try {
        const savedNote = await notesAPI.createNote({
            page_id: currentPageId,
            content: '',
            parent_note_id: null
        });

        // Update with real data
        noteEl.dataset.noteId = savedNote.id;
        notesForCurrentPage.push(savedNote);
    } catch (error) {
        console.error('Error creating root note:', error);
        noteEl.remove();
        alert('Failed to save new note.');
    }
}, 'addRootNoteBtn');

// Note content editing
const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) return;

    const contentDiv = noteEl.querySelector('.note-content');
    const rawContent = contentDiv.dataset.rawContent || contentDiv.innerText;
    
    const noteData = getNoteDataById(noteId);
    if (noteData && noteData.content === rawContent) return;

    try {
        const updatedNote = await notesAPI.updateNote(noteId, { content: rawContent });
        const noteIndex = notesForCurrentPage.findIndex(n => n.id === updatedNote.id);
        if (noteIndex > -1) {
            notesForCurrentPage[noteIndex] = updatedNote;
        }
        
        // Update the stored raw content
        contentDiv.dataset.rawContent = updatedNote.content;
        
        await fetchAndDisplayPages(currentPageName);
    } catch (error) {
        console.error('Error updating note:', error);
    }
}, 1000);

/**
 * Saves a note immediately without debouncing
 * @param {HTMLElement} noteEl - Note element
 */
async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) return;

    const contentDiv = noteEl.querySelector('.note-content');
    const rawContent = contentDiv.dataset.rawContent || contentDiv.textContent;
    
    const noteData = getNoteDataById(noteId);
    if (noteData && noteData.content === rawContent) return;

    try {
        const updatedNote = await notesAPI.updateNote(noteId, { content: rawContent });
        const noteIndex = notesForCurrentPage.findIndex(n => n.id === updatedNote.id);
        if (noteIndex > -1) {
            notesForCurrentPage[noteIndex] = updatedNote;
        }
        
        // Update the stored raw content
        contentDiv.dataset.rawContent = updatedNote.content;
        
        await fetchAndDisplayPages(currentPageName);
    } catch (error) {
        console.error('Error updating note:', error);
    }
}

safeAddEventListener(notesContainer, 'input', (e) => {
    if (e.target.matches('.note-content.edit-mode')) {
        const noteItem = e.target.closest('.note-item');
        if (noteItem) {
            // Update the raw content data attribute
            const contentDiv = e.target;
            contentDiv.dataset.rawContent = contentDiv.textContent;
            debouncedSaveNote(noteItem);
        }
    }
}, 'notesContainer');

safeAddEventListener(notesContainer, 'blur', (e) => {
    if (e.target.matches('.note-content.edit-mode')) {
        const noteItem = e.target.closest('.note-item');
        if (noteItem) {
            // Save immediately on blur
            const contentDiv = e.target;
            contentDiv.dataset.rawContent = contentDiv.textContent;
            
            // Cancel the debounced save and save immediately
            debouncedSaveNote.cancel();
            
            const noteId = noteItem.dataset.noteId;
            if (!noteId.startsWith('temp-')) {
                saveNoteImmediately(noteItem);
            }
        }
    }
}, 'notesContainer');

// Note keyboard navigation and editing
notesContainer.addEventListener('keydown', async (e) => {
    // Handle both edit and rendered mode
    if (!e.target.matches('.note-content')) return;

    const noteItem = e.target.closest('.note-item');
    const noteId = noteItem.dataset.noteId;
    const noteData = getNoteDataById(noteId);
    const contentDiv = e.target;

    // Handle shortcuts only in edit mode
    if (contentDiv.classList.contains('edit-mode')) {
        const text = contentDiv.textContent;
        const selection = window.getSelection();
        const cursorPos = selection.anchorOffset;

        // Autoclose brackets
        if (e.key === '[') {
            e.preventDefault();
            document.execCommand('insertText', false, '[]');
            selection.collapse(selection.anchorNode, cursorPos + 1);
            return; // Consume event
        } else if (e.key === '{') {
            e.preventDefault();
            document.execCommand('insertText', false, '{}');
            selection.collapse(selection.anchorNode, cursorPos + 1);
            return; // Consume event
        } else if (e.key === '(') {
            e.preventDefault();
            document.execCommand('insertText', false, '()');
            selection.collapse(selection.anchorNode, cursorPos + 1);
            return; // Consume event
        }

        // Check for shortcut triggers (e.g., :t, :d)
        if (text.substring(cursorPos - 2, cursorPos) === ':t' && e.key === ' ') { // Trigger on space after :t
            e.preventDefault();
            document.execCommand('deleteBackward', false, null); // Delete 't'
            document.execCommand('deleteBackward', false, null); // Delete ':'
            document.execCommand('insertText', false, '{tag::}');
            selection.collapse(selection.anchorNode, cursorPos - 2 + 6); // Move cursor inside {}
            return; 
        } else if (text.substring(cursorPos - 2, cursorPos) === ':d' && e.key === ' ') {
            e.preventDefault();
            document.execCommand('deleteBackward', false, null);
            document.execCommand('deleteBackward', false, null);
            const today = new Date().toISOString().slice(0, 10);
            document.execCommand('insertText', false, `{date::${today}}`);
            selection.collapse(selection.anchorNode, cursorPos - 2 + 18); // Move cursor after date
            return;
        } else if (text.substring(cursorPos - 2, cursorPos) === ':r' && e.key === ' ') {
            e.preventDefault();
            document.execCommand('deleteBackward', false, null);
            document.execCommand('deleteBackward', false, null);
            const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
            document.execCommand('insertText', false, `{timestamp::${now}}`);
            selection.collapse(selection.anchorNode, cursorPos - 2 + 23); // Move cursor after timestamp
            return;
        } else if (text.substring(cursorPos - 2, cursorPos) === ':k' && e.key === ' ') {
            e.preventDefault();
            document.execCommand('deleteBackward', false, null);
            document.execCommand('deleteBackward', false, null);
            document.execCommand('insertText', false, '{keyword::}');
            selection.collapse(selection.anchorNode, cursorPos - 2 + 10); // Move cursor inside {}
            return;
        }
    }

    // if (!noteData || noteId.startsWith('temp-')) return; // Keep this for other keydown events

    switch (e.key) {
        case 'Enter':
            if (noteId.startsWith('temp-')) return; // Prevent actions on temp notes for Enter
            e.preventDefault();
            
            // If in rendered mode, switch to edit mode
            if (contentDiv.classList.contains('rendered-mode')) {
                ui.switchToEditMode(contentDiv);
                return;
            }
            
            // If in edit mode, create new note
            const newNote = {
                page_id: currentPageId,
                content: '',
                parent_note_id: noteData.parent_note_id,
                order_index: noteData.order_index + 1
            };

            try {
                const savedNote = await notesAPI.createNote(newNote);
                notesForCurrentPage.push(savedNote);
                
                // Re-render to get correct order
                const notes = await notesAPI.getNotesForPage(currentPageId);
                notesForCurrentPage = notes;
                ui.displayNotes(notesForCurrentPage, currentPageId);

                if (window.currentFocusedNoteId) {
                    const focusedNoteStillExists = window.notesForCurrentPage.some(n => String(n.id) === String(window.currentFocusedNoteId));
                    if (focusedNoteStillExists) {
                        ui.focusOnNote(window.currentFocusedNoteId);
                    } else {
                        ui.showAllNotes();
                    }
                }

                // Focus new note in edit mode
                const newNoteEl = getNoteElementById(savedNote.id);
                if (newNoteEl) {
                    const newContentDiv = newNoteEl.querySelector('.note-content');
                    if (newContentDiv) {
                        ui.switchToEditMode(newContentDiv);
                    }
                }
            } catch (error) {
                console.error('Error creating sibling note:', error);
            }
            break;

        case 'Tab':
            e.preventDefault();
            if (noteId.startsWith('temp-') || !noteData) return;
            
            // Save current content first
            const currentContent = contentDiv.dataset.rawContent || contentDiv.textContent;
            if (currentContent !== noteData.content) {
                await saveNoteImmediately(noteItem);
                // Update local note data
                noteData.content = currentContent;
            }
            
            if (e.shiftKey) {
                // Outdent
                if (!noteData.parent_note_id) return;
                
                try {
                    const parentNote = getNoteDataById(noteData.parent_note_id);
                    const updatedNote = await notesAPI.updateNote(noteId, {
                        content: currentContent, // Preserve content
                        parent_note_id: parentNote.parent_note_id,
                        order_index: parentNote.order_index + 1
                    });
                    
                    // Update local data
                    const noteIndex = notesForCurrentPage.findIndex(n => n.id === noteId);
                    if (noteIndex > -1) {
                        notesForCurrentPage[noteIndex] = updatedNote;
                        window.notesForCurrentPage = notesForCurrentPage;
                    }
                    
                    // Only refresh if the structure changes significantly
                    // For simple indentation, let the current DOM state persist
                    setTimeout(async () => {
                        // Don't refresh if drag operation is in progress
                        if (window.isDragInProgress) {
                            console.log('Skipping refresh due to drag in progress');
                            return;
                        }
                        
                        const notes = await notesAPI.getNotesForPage(currentPageId);
                        notesForCurrentPage = notes;
                        window.notesForCurrentPage = notes;
                        ui.displayNotes(notesForCurrentPage, currentPageId);

                        if (window.currentFocusedNoteId) {
                            const focusedNoteStillExists = window.notesForCurrentPage.some(n => String(n.id) === String(window.currentFocusedNoteId));
                            if (focusedNoteStillExists) {
                                ui.focusOnNote(window.currentFocusedNoteId);
                            } else {
                                ui.showAllNotes();
                            }
                        }
                        
                        const updatedNoteEl = getNoteElementById(updatedNote.id);
                        if (updatedNoteEl) {
                            const updatedContentDiv = updatedNoteEl.querySelector('.note-content');
                            if (updatedContentDiv) {
                                ui.switchToEditMode(updatedContentDiv);
                            }
                        }
                    }, 100); // Small delay to allow any pending drag operations to complete
                } catch (error) {
                    console.error('Error outdenting note:', error);
                }
            } else {
                // Indent
                const siblings = notesForCurrentPage.filter(n => 
                    n.parent_note_id === noteData.parent_note_id && 
                    n.order_index < noteData.order_index
                );
                
                if (siblings.length === 0) return;
                
                const newParent = siblings[siblings.length - 1];
                
                try {
                    const updatedNote = await notesAPI.updateNote(noteId, {
                        content: currentContent, // Preserve content
                        parent_note_id: newParent.id,
                        order_index: 0
                    });
                    
                    // Update local data
                    const noteIndex = notesForCurrentPage.findIndex(n => n.id === noteId);
                    if (noteIndex > -1) {
                        notesForCurrentPage[noteIndex] = updatedNote;
                        window.notesForCurrentPage = notesForCurrentPage;
                    }
                    
                    // Only refresh if the structure changes significantly
                    // For simple indentation, let the current DOM state persist
                    setTimeout(async () => {
                        // Don't refresh if drag operation is in progress
                        if (window.isDragInProgress) {
                            console.log('Skipping refresh due to drag in progress');
                            return;
                        }
                        
                        const notes = await notesAPI.getNotesForPage(currentPageId);
                        notesForCurrentPage = notes;
                        window.notesForCurrentPage = notes;
                        ui.displayNotes(notesForCurrentPage, currentPageId);
                        
                        const updatedNoteEl = getNoteElementById(updatedNote.id);
                        if (updatedNoteEl) {
                            const updatedContentDiv = updatedNoteEl.querySelector('.note-content');
                            if (updatedContentDiv) {
                                ui.switchToEditMode(updatedContentDiv);
                            }
                        }
                    }, 100); // Small delay to allow any pending drag operations to complete
                } catch (error) {
                    console.error('Error indenting note:', error);
                }
            }
            break;

        case 'Backspace':
            if (noteId.startsWith('temp-')) return; // Prevent actions on temp notes for Backspace
            
            // Only delete on backspace if in edit mode and content is empty
            if (contentDiv.classList.contains('edit-mode') && e.target.textContent.trim() === '') {
                const children = notesForCurrentPage.filter(n => n.parent_note_id === noteData.id);
                if (children.length > 0) {
                    console.log('Note has children, not deleting');
                    return;
                }

                const isOnlyRootNote = !noteData.parent_note_id && 
                    notesForCurrentPage.filter(n => !n.parent_note_id).length === 1;
                if (isOnlyRootNote && notesForCurrentPage.length === 1) {
                    console.log('Cannot delete the only note on the page');
                    return;
                }

                e.preventDefault();
                try {
                    await notesAPI.deleteNote(noteId);
                    notesForCurrentPage = notesForCurrentPage.filter(n => n.id !== noteData.id);
                    noteItem.remove();
                    await fetchAndDisplayPages(currentPageName);
                } catch (error) {
                    console.error('Error deleting note:', error);
                }
            }
            break;
        
        case 'ArrowUp':
        case 'ArrowDown':
            e.preventDefault();
            const allNotes = Array.from(notesContainer.querySelectorAll('.note-item .note-content'));
            const currentIndex = allNotes.indexOf(e.target);
            let nextIndex = -1;

            if (e.key === 'ArrowUp') {
                if (currentIndex > 0) {
                    nextIndex = currentIndex - 1;
                }
            } else { // ArrowDown
                if (currentIndex < allNotes.length - 1) {
                    nextIndex = currentIndex + 1;
                }
            }

            if (nextIndex !== -1) {
                const nextNoteContent = allNotes[nextIndex];
                if (nextNoteContent.classList.contains('rendered-mode')) {
                    ui.switchToEditMode(nextNoteContent);
                } else {
                    nextNoteContent.focus();
                }
            }
            break;
    }
});

// Note interactions (bullet clicks, task markers)
notesContainer.addEventListener('click', async (e) => {
    // Bullet click for collapse/expand
    if (e.target.matches('.note-bullet.has-children-bullet')) {
        const noteId = e.target.dataset.noteId;
        const noteData = getNoteDataById(noteId);

        if (noteData) {
            const newCollapsedState = !noteData.collapsed;
            try {
                const updatedNote = await notesAPI.updateNote(noteId, { 
                    collapsed: newCollapsedState 
                });
                noteData.collapsed = updatedNote.collapsed;
                updateNoteDOM(noteData);
            } catch (error) {
                console.error('Error updating collapsed state:', error);
            }
        }
    }
    // Task checkbox click
    else if (e.target.matches('.task-checkbox')) {
        const checkbox = e.target;
        const noteItem = checkbox.closest('.note-item');
        if (!noteItem) return;
        
        const noteId = noteItem.dataset.noteId;
        const contentDiv = noteItem.querySelector('.note-content');
        const noteData = getNoteDataById(noteId);

        if (!noteData || !contentDiv) {
            console.error('Note data or contentDiv not found for task checkbox click', { noteId, noteData, contentDiv });
            return;
        }

        const currentMarkerType = checkbox.dataset.markerType;
        let newContent, newStatus, doneAt = null;
        const currentText = contentDiv.innerText; // Get text directly from contentDiv for accurate parsing

        if (currentMarkerType === 'TODO') {
            newContent = 'DONE ' + currentText.replace(/^TODO\s*/, '');
            newStatus = 'DONE';
            doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
        } else if (currentMarkerType === 'DONE') {
            newContent = 'TODO ' + currentText.replace(/^DONE\s*/, '');
            newStatus = 'TODO';
        } else if (currentMarkerType === 'CANCELLED') {
            // Can't change cancelled tasks
            checkbox.checked = true; // Keep it checked/disabled
            return;
        } else {
            return;
        }

        try {
            // 1. Update note content first
            const updatedNote = await notesAPI.updateNote(noteId, { content: newContent });
            noteData.content = updatedNote.content; // Update local cache
            noteData.updated_at = updatedNote.updated_at; // Update local cache
            contentDiv.innerHTML = ui.parseAndRenderContent(newContent); // Update UI with parsed content

            // 2. Update properties
            await propertiesAPI.setProperty({
                entity_type: 'note',
                entity_id: parseInt(noteId),
                name: 'status',
                value: newStatus
            });

            if (doneAt) {
                await propertiesAPI.setProperty({
                    entity_type: 'note',
                    entity_id: parseInt(noteId),
                    name: 'done_at',
                    value: doneAt
                });
            } else {
                // Ensure 'done_at' property is removed if it exists
                try {
                    await propertiesAPI.deleteProperty('note', parseInt(noteId), 'done_at');
                } catch (delError) {
                    // Ignore if property didn't exist (some backends might error)
                    console.warn('Could not delete done_at property (might not exist):', delError);
                }
            }

            // 3. Fetch updated properties for the note
            const updatedProperties = await propertiesAPI.getProperties('note', parseInt(noteId));
            noteData.properties = updatedProperties; // Update local cache of properties

            // 4. Re-render the properties section for this specific note
            const propertiesEl = noteItem.querySelector('.note-properties');
            if (propertiesEl) {
                ui.renderProperties(propertiesEl, updatedProperties);
            } else {
                console.warn('Could not find properties element for note:', noteId);
            }

            // 5. Update global notes data to keep drag-and-drop changes
            const updatedNoteIndex = notesForCurrentPage.findIndex(n => n.id === noteData.id);
            if (updatedNoteIndex > -1) {
                notesForCurrentPage[updatedNoteIndex] = noteData;
                window.notesForCurrentPage = notesForCurrentPage;
            }

            console.log(`Task status updated: ${newStatus}`, { noteId, newContent, doneAt });

        } catch (error) {
            console.error('Error updating task status:', error);
            alert('Failed to update task status: ' + error.message);
            // Revert UI optimistic update on error
            contentDiv.innerHTML = ui.parseAndRenderContent(noteData.content); // Revert to original content
        }
    }
});

// Page link clicks
document.body.addEventListener('click', async (e) => {
    if (e.target.matches('.page-link[data-page-name]')) {
        e.preventDefault();
        const pageName = e.target.dataset.pageName;
        
        try {
            // Try to load the page first
            const existingPage = await pagesAPI.getPage(pageName);
            
            if (existingPage && existingPage.id) {
                // Page exists, load it normally
                await loadPage(pageName);
            } else {
                // Page doesn't exist, create it
                console.log(`Creating new page: ${pageName}`);
                const newPage = await pagesAPI.createPage({ name: pageName });
                
                if (newPage && newPage.id) {
                    // Check if it's a journal page (date format or "Journal")
                    const journalPattern = /^\d{4}-\d{2}-\d{2}$|^Journal$/i;
                    if (journalPattern.test(pageName)) {
                        // Add journal type property
                        await propertiesAPI.setProperty({
                            entity_type: 'page',
                            entity_id: newPage.id,
                            name: 'type',
                            value: 'journal'
                        });
                    }
                    
                    // Refresh page list and load the new page
                    await fetchAndDisplayPages(newPage.name);
                    await loadPage(newPage.name, true);
                } else {
                    alert(`Failed to create page: ${pageName}`);
                }
            }
        } catch (error) {
            console.error('Error handling page link click:', error);
            // If there's an error, try to create the page anyway
            try {
                const newPage = await pagesAPI.createPage({ name: pageName });
                if (newPage && newPage.id) {
                    const journalPattern = /^\d{4}-\d{2}-\d{2}$|^Journal$/i;
                    if (journalPattern.test(pageName)) {
                        await propertiesAPI.setProperty({
                            entity_type: 'page',
                            entity_id: newPage.id,
                            name: 'type',
                            value: 'journal'
                        });
                    }
                    await fetchAndDisplayPages(newPage.name);
                    await loadPage(newPage.name, true);
                } else {
                    alert(`Failed to create page: ${pageName}`);
                }
            } catch (createError) {
                console.error('Error creating page:', createError);
                alert(`Failed to create page: ${pageName}`);
            }
        }
    }
});

/**
 * Gets the initial page name to load
 * @returns {string} Today's date in YYYY-MM-DD format
 */
function getInitialPage() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Page Properties Modal Handling
function initPagePropertiesModal() {
    const gear = ui.domRefs.pagePropertiesGear;
    const modal = ui.domRefs.pagePropertiesModal;
    const closeButton = ui.domRefs.pagePropertiesModalClose;

    console.log('Initializing page properties modal...');
    console.log('Gear element:', gear);
    console.log('Modal element:', modal);
    console.log('Close button element:', closeButton);

    if (!gear || !modal || !closeButton) {
        console.warn('Page properties modal elements not found. Modal functionality will be disabled.');
        return;
    }

    gear.addEventListener('click', async () => {
        console.log('Page properties gear clicked!');
        console.log('Current page ID:', currentPageId);
        
        // Refresh page properties before showing modal
        if (currentPageId) {
            try {
                console.log('Fetching properties for page:', currentPageId);
                const properties = await propertiesAPI.getProperties('page', currentPageId);
                console.log('Properties fetched:', properties);
                displayPageProperties(properties); // Make sure this function is available in ui module
            } catch (error) {
                console.error('Error fetching page properties for modal:', error);
                displayPageProperties({}); // Display empty or error state
            }
        }
        
        console.log('Adding active class to modal');
        modal.classList.add('active');
        
        if (typeof feather !== 'undefined' && feather.replace) {
            feather.replace();
        }
    });

    closeButton.addEventListener('click', () => {
        console.log('Page properties modal close button clicked');
        modal.classList.remove('active');
    });

    // Close modal when clicking outside its content
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            console.log('Clicked outside modal content, closing modal');
            modal.classList.remove('active');
        }
    });

    // Add property button
    const addBtn = ui.domRefs.addPagePropertyBtn;
    if (addBtn) {
        addBtn.addEventListener('click', async () => {
            const key = await ui.showGenericInputModal('Enter property name:');
            if (key && key.trim()) {
                await addPageProperty(key.trim(), '');
            }
        });
    }
}

// Update displayPageProperties function
function displayPageProperties(properties) {
    const pagePropertiesList = ui.domRefs.pagePropertiesList;
    console.log('displayPageProperties called with:', properties);
    console.log('pagePropertiesList element:', pagePropertiesList);
    
    if (!pagePropertiesList) {
        console.error('pagePropertiesList element not found!');
        return;
    }

    pagePropertiesList.innerHTML = '';
    if (!properties || Object.keys(properties).length === 0) {
        console.log('No properties to display');
        return;
    }

    Object.entries(properties).forEach(([key, value]) => {
        if (key === 'type' && value === 'journal') return; // Skip journal type property
        
        if (Array.isArray(value)) {
            value.forEach(singleValue => {
                const propItem = document.createElement('div');
                propItem.className = 'page-property-item';
                // For multi-value, make value non-editable for now, or decide on edit strategy
                // Deletion should delete just this specific value if backend supports it, or the whole key if not.
                // For simplicity, current delete button will target the key.
                propItem.innerHTML = `
                    <span class="page-property-key">${key}::</span>
                    <span class="page-property-value" data-property="${key}" data-value="${singleValue}">${singleValue}</span>
                    <button class="page-property-delete" data-property="${key}" title="Delete all '${key}' properties">×</button>
                `;
                pagePropertiesList.appendChild(propItem);
            });
        } else {
            const propItem = document.createElement('div');
            propItem.className = 'page-property-item';
            propItem.innerHTML = `
                <span class="page-property-key">${key}::</span>
                <span class="page-property-value" contenteditable="true" data-property="${key}">${value}</span>
                <button class="page-property-delete" data-property="${key}">×</button>
            `;
            pagePropertiesList.appendChild(propItem);
        }
    });

    // Add event listeners for property editing
    pagePropertiesList.addEventListener('blur', async (e) => {
        if (e.target.matches('.page-property-value')) {
            const key = e.target.dataset.property;
            const value = e.target.textContent.trim();
            await updatePageProperty(key, value);
        }
    }, true);

    // Add event listeners for property deletion
    pagePropertiesList.addEventListener('click', async (e) => {
        if (e.target.matches('.page-property-delete')) {
            const key = e.target.dataset.property;
            const confirmed = await ui.showGenericConfirmModal('Delete Property', `Are you sure you want to delete the property "${key}"?`);
            if (confirmed) {
                await deletePageProperty(key);
            }
        }
    });

    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace(); // Ensure Feather icons are re-applied
    }
}

/**
 * Adds a new page property
 * @param {string} key - Property key
 * @param {string} value - Property value
 */
async function addPageProperty(key, value) {
    if (!currentPageId) return;

    try {
        await propertiesAPI.setProperty({
            entity_type: 'page',
            entity_id: currentPageId,
            name: key,
            value: value
        });

        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
    } catch (error) {
        console.error('Error adding page property:', error);
        alert('Failed to add property');
    }
}

/**
 * Updates a page property
 * @param {string} key - Property key
 * @param {string} value - New property value
 */
async function updatePageProperty(key, value) {
    if (!currentPageId) return;

    try {
        await propertiesAPI.setProperty({
            entity_type: 'page',
            entity_id: currentPageId,
            name: key,
            value: value
        });

        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
    } catch (error) {
        console.error('Error updating page property:', error);
        alert('Failed to update property');
    }
}

/**
 * Deletes a page property
 * @param {string} key - Property key to delete
 */
async function deletePageProperty(key) {
    if (!currentPageId) return;

    try {
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
    } catch (error) {
        console.error('Error deleting page property:', error);
        alert('Failed to delete property');
    }
}

/**
 * Displays search results in the sidebar
 * @param {Array} results - Search results from the API
 */
function displaySearchResults(results) {
    const searchResults = document.getElementById('search-results');
    
    if (!results || results.length === 0) {
        searchResults.innerHTML = '<div class="search-result-item">No results found</div>';
        searchResults.classList.add('has-results');
        return;
    }

    const html = results.map(result => `
        <div class="search-result-item" data-page-name="${result.page_name}" data-note-id="${result.note_id}">
            <div class="search-result-title">${result.page_name}</div>
            <div class="search-result-snippet">${highlightSearchTerms(result.content_snippet, globalSearchInput.value)}</div>
        </div>
    `).join('');

    searchResults.innerHTML = html;
    searchResults.classList.add('has-results');

    // Add click handlers for search results
    searchResults.addEventListener('click', (e) => {
        const resultItem = e.target.closest('.search-result-item');
        if (resultItem) {
            const pageName = resultItem.dataset.pageName;
            const noteId = resultItem.dataset.noteId;
            
            // Clear search and hide results
            globalSearchInput.value = '';
            searchResults.classList.remove('has-results');
            searchResults.innerHTML = '';
            
            // Navigate to the page
            loadPage(pageName).then(() => {
                // If there's a specific note ID, try to focus on it
                if (noteId) {
                    const noteElement = document.querySelector(`[data-note-id="${noteId}"]`);
                    if (noteElement) {
                        noteElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        const contentDiv = noteElement.querySelector('.note-content');
                        if (contentDiv) {
                            setTimeout(() => contentDiv.focus(), 100);
                        }
                    }
                }
            });
        }
    });
}

/**
 * Highlights search terms in text
 * @param {string} text - Text to highlight
 * @param {string} searchTerm - Term to highlight
 * @returns {string} HTML with highlighted terms
 */
function highlightSearchTerms(text, searchTerm) {
    if (!searchTerm || !text) return text;
    
    const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<span class="search-result-highlight">$1</span>');
}

// Page Search Modal Logic
let allPagesForSearch = [];
let selectedSearchResultIndex = -1;

async function openSearchOrCreatePageModal() {
    if (!pageSearchModal || !pageSearchModalInput || !pageSearchModalResults || !pageSearchModalCancel) {
        console.error('Page search modal elements not found!');
        return;
    }
    try {
        allPagesForSearch = await pagesAPI.getAllPages();
    } catch (error) {
        console.error('Failed to fetch pages for search modal:', error);
        allPagesForSearch = []; // Continue with empty list if fetch fails
    }
    pageSearchModalInput.value = '';
    renderPageSearchResults('');
    pageSearchModal.classList.add('active');
    pageSearchModalInput.focus();
}

function closeSearchOrCreatePageModal() {
    pageSearchModal.classList.remove('active');
    selectedSearchResultIndex = -1; // Reset selection
}

function renderPageSearchResults(query) {
    if (!pageSearchModalResults) return;
    pageSearchModalResults.innerHTML = '';
    selectedSearchResultIndex = -1; // Reset selection on new render

    const filteredPages = allPagesForSearch.filter(page => 
        page.name.toLowerCase().includes(query.toLowerCase())
    );

    filteredPages.forEach(page => {
        const li = document.createElement('li');
        li.textContent = page.name;
        li.dataset.pageName = page.name;
        li.addEventListener('click', () => selectAndActionPageSearchResult(page.name, false));
        pageSearchModalResults.appendChild(li);
    });

    // Add "Create page" option if query is not empty and doesn't exactly match an existing page
    const exactMatch = allPagesForSearch.some(page => page.name.toLowerCase() === query.toLowerCase());
    if (query.trim() !== '' && !exactMatch) {
        const li = document.createElement('li');
        li.classList.add('create-new-option');
        li.innerHTML = `Create page: <span>"${query}"</span>`;
        li.dataset.pageName = query; // The name to create
        li.dataset.isCreate = 'true';
        li.addEventListener('click', () => selectAndActionPageSearchResult(query, true));
        pageSearchModalResults.appendChild(li);
    }
    
    // Auto-select first item if any results
    if (pageSearchModalResults.children.length > 0) {
        selectedSearchResultIndex = 0;
        pageSearchModalResults.children[0].classList.add('selected');
    }
}

async function selectAndActionPageSearchResult(pageName, isCreate) {
    closeSearchOrCreatePageModal();
    if (isCreate) {
        try {
            const newPage = await pagesAPI.createPage({ name: pageName });
            if (newPage && newPage.id) {
                await fetchAndDisplayPages(newPage.name); // Refresh page list
                await loadPage(newPage.name, true); // Load the new page
            } else {
                alert(`Failed to create page: ${pageName}`);
            }
        } catch (error) {
            console.error('Error creating page from search modal:', error);
            alert(`Error creating page: ${error.message}`);
        }
    } else {
        await loadPage(pageName, true);
    }
}

// Event Listeners for Page Search Modal
if (openPageSearchModalBtn) {
    safeAddEventListener(openPageSearchModalBtn, 'click', openSearchOrCreatePageModal, 'openPageSearchModalBtn');
}

if (pageSearchModalCancel) {
    safeAddEventListener(pageSearchModalCancel, 'click', closeSearchOrCreatePageModal, 'pageSearchModalCancel');
}

if (pageSearchModalInput) {
    pageSearchModalInput.addEventListener('input', (e) => {
        renderPageSearchResults(e.target.value);
    });

    pageSearchModalInput.addEventListener('keydown', (e) => {
        const items = pageSearchModalResults.children;
        if (items.length === 0) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (selectedSearchResultIndex < items.length - 1) {
                    items[selectedSearchResultIndex]?.classList.remove('selected');
                    selectedSearchResultIndex++;
                    items[selectedSearchResultIndex]?.classList.add('selected');
                }
                break;
            case 'ArrowUp':
                e.preventDefault();
                if (selectedSearchResultIndex > 0) {
                    items[selectedSearchResultIndex]?.classList.remove('selected');
                    selectedSearchResultIndex--;
                    items[selectedSearchResultIndex]?.classList.add('selected');
                }
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedSearchResultIndex !== -1 && items[selectedSearchResultIndex]) {
                    const selectedItem = items[selectedSearchResultIndex];
                    selectAndActionPageSearchResult(selectedItem.dataset.pageName, selectedItem.dataset.isCreate === 'true');
                } else if (pageSearchModalInput.value.trim() !== '') {
                    // If no item selected but input has text, treat as create new
                    selectAndActionPageSearchResult(pageSearchModalInput.value.trim(), true);
                }
                break;
            case 'Escape':
                closeSearchOrCreatePageModal();
                break;
        }
    });
}

// Global Ctrl+Space listener
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.code === 'Space') {
        e.preventDefault();
        openSearchOrCreatePageModal();
    }
});

/**
 * Initializes the application
 */
async function initializeApp() {
    try {
        // Initialize sidebar state
        sidebarState.init();
        
        // Initialize page properties modal
        initPagePropertiesModal();
        
        // Handle browser back/forward navigation
        window.addEventListener('popstate', (event) => {
            if (event.state && event.state.pageName) {
                loadPage(event.state.pageName, false, false);
            }
        });
        
        // Get initial page from URL or default to today's date
        const urlParams = new URLSearchParams(window.location.search);
        const initialPageName = urlParams.get('page') || getInitialPage();
        
        // Load initial page (don't focus first note yet, let loadPage handle it if needed)
        await loadPage(initialPageName, false);
        
        // Load page list
        await fetchAndDisplayPages(initialPageName);
        
        // If it was a new journal page that got created, loadPage might have focused.
        // If not, and it's an existing page, and no specific note focused by URL, focus first note.
        // This part needs careful consideration based on desired UX.
        // For now, let loadPage handle focus based on its `focusFirstNote` param.
        // If initialPageName leads to creation, focus is handled inside loadPage flow for new pages.
        // If it is an existing page, loadPage default focusFirstNote is false. We might want true for initial load.
        // Re-calling loadPage or focusing manually: 
        if (notesForCurrentPage.length > 0 && !document.activeElement.closest('.note-content')) {
            // const firstNoteEl = notesContainer.querySelector('.note-item .note-content');
            // if (firstNoteEl) ui.switchToEditMode(firstNoteEl);
            // Decided to let loadPage handle focus consistently. 
            // If you want initial focus on existing pages always, pass true to initial loadPage.
        }

        // Add delegated click listener for content images (pasted or from Markdown)
        if (ui.domRefs.notesContainer) {
            ui.domRefs.notesContainer.addEventListener('click', (e) => {
                const target = e.target;
                if (target.matches('img.content-image') && target.dataset.originalSrc) {
                    e.preventDefault();
                    if (ui.domRefs.imageViewerModal && ui.domRefs.imageViewerModalImg && ui.domRefs.imageViewerModalClose) {
                        ui.domRefs.imageViewerModalImg.src = target.dataset.originalSrc;
                        ui.domRefs.imageViewerModal.classList.add('active');

                        // Ensure close listeners are set up (idempotently or cleaned up)
                        const closeImageModal = () => {
                            ui.domRefs.imageViewerModal.classList.remove('active');
                            ui.domRefs.imageViewerModalImg.src = '';
                            // Remove specific listeners to prevent multiple additions if this code runs multiple times
                            ui.domRefs.imageViewerModalClose.removeEventListener('click', closeImageModal);
                            ui.domRefs.imageViewerModal.removeEventListener('click', outsideClickHandlerForContentImage);
                        };

                        const outsideClickHandlerForContentImage = (event) => {
                            if (event.target === ui.domRefs.imageViewerModal) {
                                closeImageModal();
                            }
                        };
                        
                        // Add listeners
                        ui.domRefs.imageViewerModalClose.addEventListener('click', closeImageModal, { once: true });
                        ui.domRefs.imageViewerModal.addEventListener('click', outsideClickHandlerForContentImage, { once: true });

                    } else {
                        console.error('Image viewer modal elements not found in domRefs.');
                        window.open(target.dataset.originalSrc, '_blank'); // Fallback
                    }
                }
            });
        }
        
        console.log('App initialized successfully');
    } catch (error) {
        console.error('Failed to initialize app:', error);
        // Display a user-friendly error message on the page
        if (document.body) {
            document.body.innerHTML = '<div style="padding: 20px; text-align: center;"><h1>App Initialization Failed</h1><p>' + error.message + '</p>Check console for details.</div>';
        }
    }
}

// Start the application
document.addEventListener('DOMContentLoaded', () => {
    // Ensure UI module is loaded
    if (typeof ui === 'undefined') {
        console.error('UI module not loaded. Please check script loading order.');
        return;
    }
    initializeApp();
});

// Add drag-and-drop file handling
notesContainer.addEventListener('dragover', (e) => {
    e.preventDefault();
    const noteItem = e.target.closest('.note-item');
    if (noteItem) {
        noteItem.classList.add('drag-over');
    }
});

notesContainer.addEventListener('dragleave', (e) => {
    const noteItem = e.target.closest('.note-item');
    if (noteItem) {
        noteItem.classList.remove('drag-over');
    }
});

notesContainer.addEventListener('drop', async (e) => {
    e.preventDefault();
    const noteItem = e.target.closest('.note-item');
    if (!noteItem) return;
    
    noteItem.classList.remove('drag-over');
    const noteId = noteItem.dataset.noteId;
    if (!noteId || noteId.startsWith('temp-')) return;

    const files = Array.from(e.dataTransfer.files);
    if (files.length === 0) return;

    const uploadPromises = files.map(async (file) => {
        const formData = new FormData();
        formData.append('attachmentFile', file);
        formData.append('note_id', noteId);

        try {
            const result = await attachmentsAPI.uploadAttachment(formData);
            console.log('File uploaded via drag and drop:', result);
            return result;
        } catch (error) {
            console.error('Error uploading file:', error);
            return null;
        }
    });

    try {
        const results = await Promise.all(uploadPromises);
        const successfulUploads = results.filter(r => r !== null);
        
        if (successfulUploads.length > 0) {
            // Refresh the note to show new attachments
            const notes = await notesAPI.getNotesForPage(currentPageId);
            notesForCurrentPage = notes;
            window.notesForCurrentPage = notesForCurrentPage;
            ui.displayNotes(notesForCurrentPage, currentPageId);

            if (window.currentFocusedNoteId) {
                const focusedNoteStillExists = window.notesForCurrentPage.some(n => String(n.id) === String(window.currentFocusedNoteId));
                if (focusedNoteStillExists) {
                    ui.focusOnNote(window.currentFocusedNoteId);
                } else {
                    ui.showAllNotes();
                }
            }
        }
    } catch (error) {
        console.error('Error handling file uploads:', error);
    }
});