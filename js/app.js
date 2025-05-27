marked.setOptions({
    highlight: function(code, lang) {
        const currentCode = (typeof code === 'string') ? code : ''; // Ensure code is a string
        if (lang && hljs.getLanguage(lang)) {
            return hljs.highlight(currentCode, { language: lang }).value;
        }
        return hljs.highlightAuto(currentCode).value;
    }
});

// State variables are now in js/state.js

// loadTemplates is now in js/api.js and will be called during initialization

// Suggestion Popup Logic (closeSuggestionsPopup, positionSuggestionsPopup, insertSuggestion, updateHighlightedSuggestion)
// is now in js/ui.js.
// renderSuggestions (which uses some of these) is in js/render.js.

// DOM Element Selections are now in js/ui.js
// (searchInput, recentPagesList, newPageButton, pageTitle, pageProperties, outlineContainer)

async function navigateToPage(pageId) {
    console.log('navigateToPage called with pageId:', pageId); // Log pageId
    const targetHash = String(pageId).startsWith('#') ? String(pageId).substring(1) : String(pageId);
    const currentHash = window.location.hash.substring(1);

    if (currentHash === targetHash) {
        await loadPage(targetHash);
    } else {
        window.location.hash = targetHash;
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    await loadTemplates();
    loadRecentPages();
    initCalendar();
    initializeSidebarToggle(); // For the left sidebar
    initializeRightSidebarToggle(); // For the new right sidebar
    initializeRightSidebarNotes(); // For the new right sidebar's notes query functionality
    document.addEventListener('keydown', handleGlobalKeyDown);
    
    // Add event listeners after DOM is loaded
    searchInput.addEventListener('input', debounce(handleSearch, 300));
    newPageButton.addEventListener('click', createNewPage);
    outlineContainer.addEventListener('click', handleOutlineClick);

    document.getElementById('advanced-search-link').addEventListener('click', (e) => {
        e.preventDefault();
        showAdvancedSearch();
    });

    document.getElementById('home-button').addEventListener('click', async (e) => {
        e.preventDefault();
        const today = new Date().toISOString().split('T')[0];
        if (document.body.classList.contains('logseq-focus-active')) {
            await zoomOut();
        }
        navigateToPage(today);
    });

    const initialHash = window.location.hash.substring(1);
    if (!initialHash) {
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today);
    } else {
        if (initialHash === 'search-results') {
            showSearchResults();
        } else {
            navigateToPage(initialHash);
        }
    }
});

window.addEventListener('hashchange', () => {
    const pageId = window.location.hash.substring(1);
    if (pageId === 'search-results') {
        showSearchResults();
    } else if (pageId) {
        clearActiveBlock();
        loadPage(pageId);
    } else {
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today);
    }
});

function handleAutoCloseBrackets(event) {
    const textarea = event.target;
    const typedChar = event.data;

    if (typedChar === null || typedChar === undefined || typedChar.length !== 1) {
        return;
    }

    let closeBracketChar = null;
    if (typedChar === '[') {
        closeBracketChar = ']';
    } else if (typedChar === '{') {
        closeBracketChar = '}';
    }

    if (closeBracketChar) {
        const cursorPos = textarea.selectionStart;

        const value = textarea.value;
        const textBeforeCursor = value.substring(0, cursorPos);
        const textAfterCursor = value.substring(cursorPos);

        textarea.value = textBeforeCursor + closeBracketChar + textAfterCursor;

        textarea.selectionStart = textarea.selectionEnd = cursorPos;
    }
}

// debounce is now in js/utils.js

// setActiveBlock, clearActiveBlock are now in js/ui.js

/**
 * Loads a page by its ID and renders it.
 * Fetches page data from the API, handles potential errors,
 * updates the UI to display the page content, title, properties,
 * and recent pages list. Also handles alias redirection and
 * re-activation of a block if specified in sessionStorage.
 * @async
 * @param {string} pageId - The ID of the page to load.
 * @returns {Promise<void>} A promise that resolves when the page is loaded and rendered, or rejects on error.
 */
async function loadPage(pageId) {
    document.body.classList.remove('logseq-focus-active');
    outlineContainer.classList.remove('focused');
    clearActiveBlock();

    try {
        document.getElementById('new-note').style.display = 'block';
        document.getElementById('backlinks-container').style.display = 'block';

        // Use fetchPageData from api.js
        const data = await fetchPageData(pageId);

        // Error handling for data.error is already within fetchPageData,
        // but we might want specific UI updates here if an error is re-thrown.
        // For now, assuming fetchPageData throws an error that's caught below.

        if (data.properties && data.properties.alias) {
            navigateToPage(data.properties.alias); // This navigation will trigger another loadPage
            return;
        }

        currentPage = data;
        await renderPage(data);
        updateRecentPages(pageId);
        if (window.renderCalendarForCurrentPage) {
            window.renderCalendarForCurrentPage();
        }

        const reActivateId = sessionStorage.getItem('lastActiveBlockIdBeforeReload');
        if (reActivateId) {
            const reActivatedBlock = document.querySelector(`.outline-item[data-note-id="${reActivateId}"]`);
            if (reActivatedBlock) setActiveBlock(reActivatedBlock, true);
            sessionStorage.removeItem('lastActiveBlockIdBeforeReload');
        }

    } catch (error) {
        console.error('Error loading page:', error);
        outlineContainer.innerHTML = `
            <div class="error-message">
                <h3>Error loading page</h3>
                <p>${error.message}</p>
                <button onclick="navigateToPage('${pageId.replace(/'/g, "\\'")}')">Retry</button>
            </div>
        `;
    }
}

async function loadRecentPages() {
    try {
        // Use fetchRecentPages from api.js
        const data = await fetchRecentPages();
        recentPages = data; // recentPages is a global state variable
        renderRecentPages(); // `renderRecentPages` is in js/render.js
    } catch (error) {
        console.error('Error loading recent pages:', error);
    }
}

async function handleSearch(event) {
    const query = event.target.value;
    if (query.length < 2) {
        const searchResultsContainer = document.getElementById('search-results-container');
        if (searchResultsContainer) searchResultsContainer.remove();
        return;
    }
    try {
        // Use searchPages from api.js
        const results = await searchPages(query);
        renderSearchResults(results); // `renderSearchResults` is in js/render.js
    } catch (error) {
        console.error('Error searching:', error);
    }
}

/**
 * Creates a modal dialog for the user to enter a new page ID and create a new page.
 * On successful creation, navigates to the new page.
 * @async
 * @returns {Promise<void>} A promise that resolves when the new page is created and navigated to, or when the dialog is cancelled.
 */
async function createNewPage() {
    const pageEditor = document.createElement('div');
    pageEditor.className = 'page-editor';
    pageEditor.innerHTML = `
        <div class="page-editor-content">
            <input type="text" class="page-id-input" placeholder="Enter page ID (e.g., 2024-03-20 or project-name)">
            <div class="page-editor-actions">
                <button class="btn-primary save-page">Create</button>
                <button class="btn-secondary cancel-page">Cancel</button>
            </div>
        </div>
    `;
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.appendChild(pageEditor);
    document.body.appendChild(modal);
    const pageIdInput = pageEditor.querySelector('.page-id-input');
    const saveButton = pageEditor.querySelector('.save-page');
    const cancelButton = pageEditor.querySelector('.cancel-page');
    pageIdInput.focus();
    saveButton.onclick = async () => {
        const pageId = pageIdInput.value.trim();
        if (!pageId) { alert('Please enter a page ID'); return; }
        let type = 'note'; let properties = {};
        if (/^\d{4}-\d{2}-\d{2}$/.test(pageId)) { type = 'journal'; properties = { 'type': 'journal' }; }
        try {
            // Use createNewPageAPI from api.js
            await createNewPageAPI(pageId, type, properties);
            document.body.removeChild(modal); // UI manipulation, will be part of ui.js
            navigateToPage(encodeURIComponent(pageId)); // Coordination, likely stays in app.js or ui.js
        } catch (error) {
            console.error('Error creating page:', error);
            alert('Error creating page: ' + error.message); // UI interaction
        }
    };
    cancelButton.onclick = () => document.body.removeChild(modal); // UI manipulation
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); });
}

// Note: Functions related to rendering and specific UI interactions (like DOM element selections)
// have been moved to their respective files (render.js, ui.js, etc.).
// This file, app.js, primarily handles application flow, event listeners, and coordination between modules.

async function editPageProperties() {
    if (!currentPage) return;
    const properties = currentPage.properties || {};
    const propertyList = Object.entries(properties).map(([key, value]) => `${key}::${value}`).join('\n');
    pageProperties.style.display = 'block';
    const editorHtml = `<div class="properties-editor">
            <textarea class="properties-textarea" placeholder="Enter properties (one per line, format: key::value)">${propertyList}</textarea>
            <div class="properties-editor-actions">
                <button class="btn-primary save-properties">Save</button>
                <button class="btn-secondary cancel-properties">Cancel</button>
            </div></div>`;
    let propertiesContent = pageProperties.querySelector('.page-properties-content');
    const originalContent = propertiesContent ? propertiesContent.innerHTML : '';
    if (!propertiesContent) {
        propertiesContent = document.createElement('div');
        propertiesContent.className = 'page-properties-content';
        pageProperties.appendChild(propertiesContent);
    }
    propertiesContent.innerHTML = editorHtml;
    const textarea = propertiesContent.querySelector('.properties-textarea');
    const saveButton = propertiesContent.querySelector('.save-properties');
    const cancelButton = propertiesContent.querySelector('.cancel-properties');
    textarea.focus();
    textarea.addEventListener('keydown', (e) => { if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); saveButton.click(); } });
    saveButton.onclick = async () => {
        const newPropertiesText = textarea.value.trim();
        const updatedProperties = {};
        if (newPropertiesText) {
            newPropertiesText.split('\n').forEach(line => {
                const parts = line.split('::');
                if (parts.length === 2) {
                    const key = parts[0].trim(); const value = parts[1].trim();
                    if (key && value) updatedProperties[key] = value;
                }
            });
        }
        try {
            // Use updatePagePropertiesAPI from api.js
            await updatePagePropertiesAPI(currentPage.id, currentPage.title, currentPage.type, updatedProperties);
            navigateToPage(currentPage.id); // Coordination
        } catch (error) {
            console.error('Error updating properties:', error); // Error handling
            propertiesContent.innerHTML = originalContent;
            if ((Object.keys(properties).length === 0 && !originalContent.includes('page-property')) || !propertiesContent.innerHTML.trim()) {
                 pageProperties.style.display = 'none';
            }
        }
    };
    cancelButton.onclick = () => {
        propertiesContent.innerHTML = originalContent;
        if ((Object.keys(properties).length === 0 && !originalContent.includes('page-property')) || !propertiesContent.innerHTML.trim()) {
             pageProperties.style.display = 'none';
        }
    };
}

// renderBacklinks is now in js/render.js.
// renderRecentPages is now in js/render.js.

async function showAllPages() {
    try {
        const response = await fetch('api/all_pages.php');
        const pages = await response.json();
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `<div class="page-list-modal"><h3>All Pages</h3><div class="page-list">
            ${pages.map(page => `<div class="page-list-item" onclick="navigateToPage('${page.id.replace(/'/g, "\\'")}'); document.body.removeChild(this.closest('.modal'));">
                                ${page.title || decodeURIComponent(page.id)}</div>`).join('')}
            </div><button class="btn-secondary" style="margin-top:15px;" onclick="document.body.removeChild(this.closest('.modal'));">Close</button></div>`;
        modal.onclick = (e) => { if (e.target === modal) document.body.removeChild(modal); };
        document.body.appendChild(modal);
    } catch (error) { console.error('Error loading all pages:', error); }
}

// renderSearchResults (for inline search results) is now in js/render.js.

function updateRecentPages(pageId) {
    // Use updateRecentPagesAPI from api.js
    updateRecentPagesAPI(pageId)
        .then(() => loadRecentPages()) // loadRecentPages will be refactored
        .catch(error => console.error('Error updating recent pages:', error)); // Add error handling
}

async function uploadFile(noteId, file) {
    if (!file) return;
    try {
        // Use uploadFileAPI from api.js
        await uploadFileAPI(noteId, file);
        navigateToPage(currentPage.id); // Coordination
    } catch (error) {
        console.error('Error uploading file:', error);
        alert('Error uploading file: ' + error.message); // UI interaction
    }
}

async function deleteAttachment(id, event) {
    event.stopPropagation();
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `<div class="delete-confirmation-modal"><h3>Delete Attachment</h3>
        <p>Are you sure you want to delete this attachment?</p>
        <div class="button-group"><button class="btn-secondary cancel-delete">Cancel</button><button class="btn-primary confirm-delete">Delete</button></div></div>`;
    document.body.appendChild(modal);
    modal.querySelector('.cancel-delete').onclick = () => document.body.removeChild(modal); // UI
    modal.querySelector('.confirm-delete').onclick = async () => {
        try {
            // Use deleteAttachmentAPI from api.js
            await deleteAttachmentAPI(id);
            document.body.removeChild(modal); // UI
            navigateToPage(currentPage.id); // Coordination
        } catch (error) {
            console.error('Error deleting attachment:', error);
            alert('Error deleting attachment: ' + error.message); // UI
            document.body.removeChild(modal); // UI
        }
    };
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); }); // UI
}

function initCalendar() {
    const calendar = document.getElementById('calendar');
    if (!calendar) return;
    const todayDate = new Date();

    let currentDisplayMonth = todayDate.getMonth();
    let currentDisplayYear = todayDate.getFullYear();

    function renderCalendar() {
        const firstDay = new Date(currentDisplayYear, currentDisplayMonth, 1);
        const lastDay = new Date(currentDisplayYear, currentDisplayMonth + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDay = (firstDay.getDay() === 0) ? 6 : firstDay.getDay() - 1;

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        let html = `<div class="calendar-header"><button class="calendar-nav" onclick="prevMonth()">←</button>
                    <span><b>${monthNames[currentDisplayMonth]} ${currentDisplayYear}</b></span>
                    <button class="calendar-nav" onclick="nextMonth()">→</button></div>
                    <div class="calendar-grid">${dayNames.map(d => `<div class="calendar-weekday">${d}</div>`).join('')}`;

        for (let i = 0; i < startingDay; i++) html += '<div class="calendar-day empty"></div>';

        const todayString = todayDate.toISOString().split('T')[0];
        const currentPageId = currentPage ? currentPage.id : null;

        for (let day = 1; day <= daysInMonth; day++) {
            const date = `${currentDisplayYear}-${String(currentDisplayMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            let dayClasses = 'calendar-day';
            if (date === todayString) {
                dayClasses += ' today';
            }
            if (date === currentPageId) {
                dayClasses += ' selected-date';
            }
            html += `<div class="${dayClasses}" onclick="window.location.hash = '${date}'" data-date="${date}">${day}</div>`;
        }

        const totalCells = startingDay + daysInMonth;
        const remainingCells = (totalCells % 7 === 0) ? 0 : 7 - (totalCells % 7);
        for (let i = 0; i < remainingCells; i++) html += '<div class="calendar-day empty"></div>';

        html += '</div>';
        calendar.innerHTML = html;
    }

    window.renderCalendarForCurrentPage = renderCalendar;

    window.prevMonth = () => {
        currentDisplayMonth = (currentDisplayMonth === 0) ? 11 : currentDisplayMonth - 1;
        if (currentDisplayMonth === 11) currentDisplayYear--;
        renderCalendar();
    };
    window.nextMonth = () => {
        currentDisplayMonth = (currentDisplayMonth === 11) ? 0 : currentDisplayMonth + 1;
        if (currentDisplayMonth === 0) currentDisplayYear++;
        renderCalendar();
    };
    renderCalendar();
}

function showImageModal(src, alt) {
    const modal = document.createElement('div');
    modal.className = 'modal image-modal';
    modal.innerHTML = `<div class="image-modal-content"><img src="${src}" alt="${alt || 'Pasted Image'}">
                       <button class="close-modal">×</button></div>`;
    modal.onclick = (e) => { if (e.target === modal || e.target.classList.contains('close-modal')) document.body.removeChild(modal); };
    document.body.appendChild(modal);
}

function handleOutlineClick(event) {
    const target = event.target;
    const breadcrumbItem = target.closest('.breadcrumb-item');
    const breadcrumbBar = target.closest('.breadcrumb-bar');
    const noteElement = target.closest('.outline-item:not(.note-editor-wrapper .outline-item)');

    let action = target.dataset.action || target.closest('[data-action]')?.dataset.action;
    let noteIdForAction = null;

    if (noteElement) {
        noteIdForAction = noteElement.dataset.noteId;
        if (!isEditorOpen && !action) {
            setActiveBlock(noteElement);
        }
    }


    if (breadcrumbItem && breadcrumbItem.dataset.action) {
        action = breadcrumbItem.dataset.action;
        if (action === 'zoom-in') {
            noteIdForAction = breadcrumbItem.dataset.noteId;
        }
    } else if (breadcrumbBar && !breadcrumbItem && breadcrumbBar.dataset.action === 'zoom-out') {
        action = 'zoom-out';
    }


    if (action === 'zoom-out') {
        zoomOut();
        return;
    }

    if (action === 'zoom-in') {
        const targetId = noteIdForAction;
        if (targetId) {
            zoomInOnNote(targetId);
        } else {
            console.warn("Zoom-in clicked, but no target ID found.");
        }
        return;
    }

    if (!noteElement || !noteIdForAction) {
        if (!target.closest('.note-editor')) {
             clearActiveBlock();
        }
        return;
    }

    switch (action) {
        case 'toggle-children':
            if (noteElement.classList.contains('has-children')) toggleChildren(noteElement);
            break;
        case 'copy-block-id':
            const blockIdToCopy = noteElement.querySelector('.outline-content[data-block-id]')?.dataset.blockId;
            if (blockIdToCopy) {
                navigator.clipboard.writeText(`{{${blockIdToCopy}}}`)
                    .then(() => console.log('Block ID copied:', blockIdToCopy))
                    .catch(err => console.error('Failed to copy block ID:', err));
            }
            break;
        case 'add-child':
            createNote(noteIdForAction, parseInt(noteElement.dataset.level) + 1);
            break;
        case 'indent-note':
            handleIndentNote(noteIdForAction, noteElement);
            break;
        case 'edit': editNote(noteIdForAction, noteElement.dataset.content); break;
        case 'upload':
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.onchange = (e) => uploadFile(noteIdForAction, e.target.files[0]);
            fileInput.click();
            break;
        case 'delete': deleteNote(noteIdForAction); break;
        case 'toggle-favorite':
            if (target.classList.contains('favorite-star')) {
                toggleFavorite(noteIdForAction, target); // toggleFavorite is in ui.js
            }
            break;
        default:
            break;
    }
}

async function handleIndentNote(noteId, noteElement) {
    if (!currentPage || !currentPage.id) {
        console.error("Cannot indent: Current page context lost.");
        alert("Error: Current page context lost. Please refresh.");
        return;
    }

    let previousSiblingElement = noteElement.previousElementSibling;
    while (previousSiblingElement && !previousSiblingElement.matches('.outline-item')) {
        previousSiblingElement = previousSiblingElement.previousElementSibling;
    }

    if (!previousSiblingElement) {
        alert("Cannot indent this note further: no suitable preceding item found to become its parent.");
        return;
    }

    const newParentId = previousSiblingElement.dataset.noteId;
    if (!newParentId) {
        alert("Error: Preceding item is missing a note ID.");
        return;
    }

    const newParentChildrenContainer = previousSiblingElement.querySelector(':scope > .outline-children');
    let newIndex = 0;
    if (newParentChildrenContainer) {
        newIndex = newParentChildrenContainer.querySelectorAll(':scope > .outline-item').length;
    }

    const payload = {
        action: 'reorder_note',
        note_id: parseInt(noteId),
        new_parent_id: parseInt(newParentId),
        new_order: newIndex,
        page_id: currentPage.id // currentPage is global state
    };

    try {
        // Use reorderNoteAPI from api.js
        await reorderNoteAPI(payload.note_id, payload.new_parent_id, payload.new_order, payload.page_id);
        sessionStorage.setItem('lastActiveBlockIdBeforeReload', noteId); // UI/State
        // loadPage will handle re-activation
        loadPage(currentPage.id); // Coordination
    } catch (error) {
        console.error('Error indenting note:', error);
        alert('Error indenting note: ' + error.message + '. Please refresh.'); // UI
        loadPage(currentPage.id); // Coordination
    }
}

async function handleOutdentNote(noteId, noteElement) {
    if (!currentPage || !currentPage.id) {
        console.error("Cannot outdent: Current page context lost.");
        alert("Error: Current page context lost. Please refresh.");
        return;
    }

    const currentParentContainer = noteElement.parentElement;
    if (!currentParentContainer) return;

    const currentParentItem = currentParentContainer.closest('.outline-item');

    if (!currentParentItem) {
        alert("Cannot outdent this note further: it's already a top-level item.");
        return;
    }

    const newGrandparentId = currentParentItem.parentElement.closest('.outline-item')?.dataset.noteId || null;

    let newIndex = 0;
    // Find the position of currentParentItem among its siblings to insert the outdented note after it
    const siblingsAndParent = Array.from(currentParentItem.parentElement.children).filter(el => el.classList.contains('outline-item'));
    const parentIndexInSiblings = siblingsAndParent.indexOf(currentParentItem);

    if (parentIndexInSiblings !== -1) {
        newIndex = parentIndexInSiblings + 1; // Insert after the original parent
    } else {
        console.error("Could not determine new index for outdented note.");
        return;
    }

    const payload = {
        action: 'reorder_note',
        note_id: parseInt(noteId),
        new_parent_id: newGrandparentId ? parseInt(newGrandparentId) : null,
        new_order: newIndex,
        page_id: currentPage.id // currentPage is global state
    };

    try {
        // Use reorderNoteAPI from api.js
        await reorderNoteAPI(payload.note_id, payload.new_parent_id, payload.new_order, payload.page_id);
        sessionStorage.setItem('lastActiveBlockIdBeforeReload', noteId); // UI/State
        // loadPage will handle re-activation
        loadPage(currentPage.id); // Coordination
    } catch (error) {
        console.error('Error outdenting note:', error);
        alert('Error outdenting note: ' + error.message + '. Please refresh.'); // UI
        loadPage(currentPage.id); // Coordination
    }
}


function toggleChildren(noteElement) {
    noteElement.classList.toggle('children-hidden');
    const childrenContainer = noteElement.querySelector('.outline-children');
    if (childrenContainer) {
        if (noteElement.classList.contains('children-hidden')) {
            childrenContainer.style.display = 'none';
        } else {
            childrenContainer.style.display = '';
        }
    }
}

/**
 * Creates a new note editor in the UI, allowing the user to input content.
 * The editor can be placed as a child of an existing note, after a specific element, or at the top level.
 * Handles template selection, saving the new note to the backend, and updating the UI.
 * @param {string|null} [parentId=null] - The ID of the parent note. If null, the note is a top-level note.
 * @param {number} [level=0] - The indentation level of the new note.
 * @param {HTMLElement|null} [insertAfterElement=null] - The element after which to insert the new note editor.
 * @param {number|null} [intendedOrder=null] - The desired order/index for the new note among its siblings.
 * @returns {void}
 */
function createNote(parentId = null, level = 0, insertAfterElement = null, intendedOrder = null) {
    if (!currentPage) return;
    clearActiveBlock();
    isEditorOpen = true;

    const noteEditorContainer = document.createElement('div');
    if (parentId && !insertAfterElement) {
        noteEditorContainer.style.paddingLeft = `calc(var(--indentation-unit) * 1)`;
    }
    noteEditorContainer.className = 'note-editor-wrapper';
    const noteElement = document.createElement('div');
    noteElement.className = 'note-editor';
    let templateOptions = '<option value="">No Template</option>';
    if (noteTemplates && Object.keys(noteTemplates).length > 0) {
        templateOptions += Object.entries(noteTemplates).map(([key, _]) => `<option value="${key}">${key.charAt(0).toUpperCase() + key.slice(1)}</option>`).join('');
    }
    noteElement.innerHTML = `<textarea class="note-textarea" placeholder="Enter note content... (Ctrl+Enter to save)"></textarea>
        <div class="note-editor-actions">
            <button class="btn-primary save-note">Save</button>
            <button class="btn-secondary cancel-note">Cancel</button>
            ${Object.keys(noteTemplates).length > 0 ? `<div class="template-selector"><select>${templateOptions}</select></div>` : ''}
        </div>`;
    noteEditorContainer.appendChild(noteElement);
    const textarea = noteElement.querySelector('.note-textarea');
    const saveButton = noteElement.querySelector('.save-note');
    const cancelButton = noteElement.querySelector('.cancel-note');
    const templateSelect = noteElement.querySelector('select');

    // DOM PLACEMENT LOGIC for the editor
    if (insertAfterElement) {
        const container = insertAfterElement.parentElement;
        if (container) {
            container.insertBefore(noteEditorContainer, insertAfterElement.nextSibling);
        } else {
            console.warn("Could not find parent container for insertAfterElement. Appending to outlineContainer.");
            outlineContainer.appendChild(noteEditorContainer);
        }
    } else if (parentId) {
        const parentNoteElement = document.querySelector(`.outline-item[data-note-id="${parentId}"]`);
        if (parentNoteElement) {
            let childrenContainer = parentNoteElement.querySelector('.outline-children');
            if (!childrenContainer) {
                childrenContainer = document.createElement('div');
                childrenContainer.className = 'outline-children';
                parentNoteElement.appendChild(childrenContainer);
            }
            childrenContainer.appendChild(noteEditorContainer);
            parentNoteElement.classList.add('has-children');
            if (parentNoteElement.classList.contains('children-hidden')) {
                parentNoteElement.classList.remove('children-hidden');
                if(childrenContainer) childrenContainer.style.display = '';
            }
        } else {
            outlineContainer.appendChild(noteEditorContainer);
        }
    } else {
        outlineContainer.appendChild(noteEditorContainer);
    }

    textarea.focus();
    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
            handleSnippetReplacement(e);
        }
        if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); saveButton.click(); }
    });
    textarea.addEventListener('input', handleSnippetReplacement);
    textarea.addEventListener('input', handleAutoCloseBrackets);

    // Link autosuggestion event listeners
    textarea.addEventListener('input', (event) => {
        const currentTextarea = event.target;
        const cursorPos = currentTextarea.selectionStart;
        const textBeforeCursor = currentTextarea.value.substring(0, cursorPos);

        const linkPattern = /\[\[([a-zA-Z0-9_-\s]{2,})$/;
        const match = textBeforeCursor.match(linkPattern);

        if (match) {
            const searchTerm = match[1];
            // Use fetchPageSuggestionsAPI from api.js
            fetchPageSuggestionsAPI(searchTerm)
                .then(suggestions => {
                    if (suggestions.length > 0) {
                        renderSuggestions(suggestions, currentTextarea); // renderSuggestions will be moved to render.js
                    } else {
                        closeSuggestionsPopup(); // closeSuggestionsPopup will be moved to ui.js
                    }
                })
                .catch(error => {
                    console.error('Error fetching suggestions:', error);
                    closeSuggestionsPopup(); // closeSuggestionsPopup will be moved to ui.js
                });
        } else {
            if (textBeforeCursor.endsWith(']]')) {
                closeSuggestionsPopup();
            } else {
                const potentialLinkPattern = /\[\[([a-zA-Z0-9_-\s]*)$/;
                if (!textBeforeCursor.match(potentialLinkPattern)) {
                     closeSuggestionsPopup();
                }
            }
        }
    });

    textarea.addEventListener('keydown', (event) => {
        if (!suggestionsPopup || currentSuggestions.length === 0) return;

        const items = suggestionsPopup.querySelectorAll('.suggestion-item');
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex + 1) % items.length;
            updateHighlightedSuggestion(items);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex - 1 + items.length) % items.length;
            updateHighlightedSuggestion(items);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeSuggestionIndex >= 0 && activeSuggestionIndex < items.length) {
                const selectedTitle = currentSuggestions[activeSuggestionIndex].title;
                insertSuggestion(textarea, selectedTitle);
            }
            closeSuggestionsPopup();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeSuggestionsPopup();
        } else if (event.key === ']') {
            const nextChar = textarea.value.substring(textarea.selectionStart, textarea.selectionStart + 1);
            if (nextChar === ']') {
                 setTimeout(closeSuggestionsPopup, 50);
            }
        }
    });

    textarea.addEventListener('blur', (event) => {
        if (suggestionsPopup && !suggestionsPopup.contains(event.relatedTarget)) {
            setTimeout(() => closeSuggestionsPopup(), 150);
        }
    });


    if (templateSelect) {
        templateSelect.addEventListener('change', (e) => {
            const templateKey = e.target.value;
            if (templateKey && noteTemplates[templateKey]) {
                textarea.value = textarea.value ? textarea.value + '\n' + noteTemplates[templateKey] : noteTemplates[templateKey];
                textarea.focus(); textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
            }
        });
    }
    saveButton.onclick = async () => {
        const content = textarea.value.trim();
        const properties = {};
        const propertyRegex = /\{([^:]+)::([^}]+)\}/g; let match;
        let tempContent = content;
        while ((match = propertyRegex.exec(tempContent)) !== null) properties[match[1].trim()] = match[2].trim();

        try {
            // Use createNoteAPI from api.js
            // createNoteAPI now handles both creation and the conditional reorder
            const newNoteData = await createNoteAPI(
                currentPage.id, // pageId (currentPage is global state)
                content,        // content
                level,          // level
                parentId,       // parentId
                properties,     // properties
                (insertAfterElement && intendedOrder !== null) ? intendedOrder : null // intendedOrder
            );
            isEditorOpen = false; // state variable

            // newNoteData contains { id: newNoteId, ... }
            sessionStorage.setItem('lastActiveBlockIdBeforeReload', newNoteData.id.toString()); // UI/State
            loadPage(currentPage.id); // Coordination

        } catch (error) {
            isEditorOpen = false; // state variable
            console.error('Error creating note:', error);
            alert('Error creating note: ' + error.message); // UI interaction
        }
    };

    cancelButton.onclick = () => {
        noteEditorContainer.remove();
        isEditorOpen = false;

        let blockToReactivate = null;
        if (insertAfterElement) { 
            blockToReactivate = insertAfterElement;
        } else if (parentId) {   
            blockToReactivate = document.querySelector(`.outline-item[data-note-id="${parentId}"]`);
            if (blockToReactivate) {
                const childrenContainer = blockToReactivate.querySelector('.outline-children');
                if (childrenContainer && childrenContainer.children.length === 0) {
                    if (!Array.from(childrenContainer.children).some(childEl => childEl.matches('.outline-item'))) {
                        blockToReactivate.classList.remove('has-children');
                    }
                }
            }
        } else { 
            const prevSibling = noteEditorContainer.previousElementSibling;
            if (prevSibling && prevSibling.matches('.outline-item')) {
                blockToReactivate = prevSibling;
            }
        }

        if (blockToReactivate) {
            setActiveBlock(blockToReactivate, false);
        } else if (outlineContainer.querySelector('.outline-item:not(.note-editor-wrapper .outline-item)')) { 
            setActiveBlock(outlineContainer.querySelector('.outline-item:not(.note-editor-wrapper .outline-item)'), false);
        }
    };
}

/**
 * Opens an editor to modify an existing note.
 * Replaces the note's content with a textarea for editing and provides save/cancel buttons.
 * Handles saving the updated content or reverting to the original content.
 * @param {string} id - The ID of the note to edit.
 * @param {string} currentContentText - The current raw text content of the note.
 * @returns {void}
 */
function editNote(id, currentContentText) {
    const noteElement = document.querySelector(`.outline-item[data-note-id="${id}"]`);
    if (!noteElement || noteElement.querySelector('.note-editor-wrapper')) return;

    clearActiveBlock();
    isEditorOpen = true;
    const noteIdBeingEdited = id;

    const contentElement = noteElement.querySelector('.outline-content');
    if (!contentElement) return;
    const originalDisplay = contentElement.style.display;
    contentElement.style.display = 'none';
    const editorWrapper = document.createElement('div');
    editorWrapper.className = 'note-editor-wrapper edit-mode';
    const noteEditorDiv = document.createElement('div');
    noteEditorDiv.className = 'note-editor';
    noteEditorDiv.innerHTML = `<textarea class="note-textarea">${currentContentText}</textarea>
        <div class="note-editor-actions"><button class="btn-primary save-note">Save</button><button class="btn-secondary cancel-note">Cancel</button></div>`;
    editorWrapper.appendChild(noteEditorDiv);
    noteElement.insertBefore(editorWrapper, contentElement);

    const textarea = noteEditorDiv.querySelector('.note-textarea');
    const saveButton = noteEditorDiv.querySelector('.save-note');
    const cancelButton = noteEditorDiv.querySelector('.cancel-note');

    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = textarea.value.length;

    textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
            handleSnippetReplacement(e);
        }
        if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); saveButton.click(); }
    });
    textarea.addEventListener('input', handleSnippetReplacement);
    textarea.addEventListener('input', handleAutoCloseBrackets);

    // Add paste event listener for handling image pastes
    textarea.addEventListener('paste', (event) => {
        if (typeof window.handlePastedImage === 'function') {
            window.handlePastedImage(event, id); // 'id' is the noteId in editNote
        }
    });

    // Link autosuggestion event listeners (same as in createNote)
    textarea.addEventListener('input', (event) => {
        const currentTextarea = event.target;
        const cursorPos = currentTextarea.selectionStart;
        const textBeforeCursor = currentTextarea.value.substring(0, cursorPos);

        const linkPattern = /\[\[([a-zA-Z0-9_-\s]{2,})$/;
        const match = textBeforeCursor.match(linkPattern);

        if (match) {
            const searchTerm = match[1];
            // Use fetchPageSuggestionsAPI from api.js
            fetchPageSuggestionsAPI(searchTerm)
                .then(suggestions => {
                    if (suggestions.length > 0) {
                        renderSuggestions(suggestions, currentTextarea); // renderSuggestions will be moved to render.js
                    } else {
                        closeSuggestionsPopup(); // closeSuggestionsPopup will be moved to ui.js
                    }
                })
                .catch(error => {
                    console.error('Error fetching suggestions:', error);
                    closeSuggestionsPopup(); // closeSuggestionsPopup will be moved to ui.js
                });
        } else {
            if (textBeforeCursor.endsWith(']]')) {
                closeSuggestionsPopup();
            } else {
                const potentialLinkPattern = /\[\[([a-zA-Z0-9_-\s]*)$/;
                if (!textBeforeCursor.match(potentialLinkPattern)) {
                     closeSuggestionsPopup();
                }
            }
        }
    });

    textarea.addEventListener('keydown', (event) => {
        if (!suggestionsPopup || currentSuggestions.length === 0) return;

        const items = suggestionsPopup.querySelectorAll('.suggestion-item');
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex + 1) % items.length;
            updateHighlightedSuggestion(items);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeSuggestionIndex = (activeSuggestionIndex - 1 + items.length) % items.length;
            updateHighlightedSuggestion(items);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeSuggestionIndex >= 0 && activeSuggestionIndex < items.length) {
                const selectedTitle = currentSuggestions[activeSuggestionIndex].title;
                insertSuggestion(textarea, selectedTitle);
            }
            closeSuggestionsPopup();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeSuggestionsPopup();
        } else if (event.key === ']') {
            const nextChar = textarea.value.substring(textarea.selectionStart, textarea.selectionStart + 1);
            if (nextChar === ']') {
                 setTimeout(closeSuggestionsPopup, 50);
            }
        }
    });

    textarea.addEventListener('blur', (event) => {
        if (suggestionsPopup && !suggestionsPopup.contains(event.relatedTarget)) {
            setTimeout(() => closeSuggestionsPopup(), 150);
        }
    });
    
    saveButton.onclick = async () => {
        const newContent = textarea.value.trim();
        const properties = {}; const propertyRegex = /\{([^:]+)::([^}]+)\}/g; let match; let tempContent = newContent;
        while ((match = propertyRegex.exec(tempContent)) !== null) properties[match[1].trim()] = match[2].trim();
        try {
            // Use updateNoteAPI from api.js
            await updateNoteAPI(id, newContent, properties, parseInt(noteElement.dataset.level) || 0);
            isEditorOpen = false; // state variable
            sessionStorage.setItem('lastActiveBlockIdBeforeReload', noteIdBeingEdited); // UI/State
            loadPage(currentPage.id); // Coordination
        } catch (error) {
            isEditorOpen = false; // state variable
            console.error('Error updating note:', error);
            alert('Error updating note: ' + error.message); // UI interaction
        }
    };
    cancelButton.onclick = () => {
        editorWrapper.remove();
        contentElement.style.display = originalDisplay;
        isEditorOpen = false;
        const originalBlock = document.querySelector(`.outline-item[data-note-id="${noteIdBeingEdited}"]`);
        if (originalBlock) setActiveBlock(originalBlock, false);
    };
}

async function deleteNote(id) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `<div class="delete-confirmation-modal"><h3>Delete Note</h3>
        <p>Are you sure you want to delete this note and all its children?</p>
        <div class="button-group"><button class="btn-secondary cancel-delete">Cancel</button><button class="btn-primary confirm-delete">Delete</button></div></div>`;
    document.body.appendChild(modal);
    modal.querySelector('.cancel-delete').onclick = () => document.body.removeChild(modal); // UI
    modal.querySelector('.confirm-delete').onclick = async () => {
        try {
            // Use deleteNoteAPI from api.js
            await deleteNoteAPI(id);
            document.body.removeChild(modal); // UI
            clearActiveBlock(); // UI/State
            navigateToPage(currentPage.id); // Coordination
        } catch (error) {
            console.error('Error deleting note:', error);
            alert('Error deleting note: ' + error.message); // UI
            document.body.removeChild(modal); // UI
        }
    };
    modal.addEventListener('click', (e) => { if (e.target === modal) document.body.removeChild(modal); }); // UI
}

async function showSearchResults() {
    // This function in app.js is now primarily for coordination.
    // The actual rendering of the search results page is in js/render.js (showSearchResults function).
    // It will use global state like sessionStorage and call renderNoteContent from render.js.
    await showSearchResults(); // Corrected function name to call from render.js
}

// copySearchLink will be moved to js/ui.js
function copySearchLink() {
    const query = sessionStorage.getItem('searchQuery') || '';
    navigator.clipboard.writeText(`<<${query}>>`).then(() => alert('Search link copied!')).catch(err => alert('Failed to copy: ' + err));
}
async function executeSearchLink(query) {
    try {
        // Use executeSearchLinkAPI from api.js
        const results = await executeSearchLinkAPI(query);
        sessionStorage.setItem('searchResults', JSON.stringify(results)); // State/UI related
        sessionStorage.setItem('searchQuery', query); // State/UI related
        window.location.hash = 'search-results'; // Coordination
    } catch (error) {
        console.error('Error executing search link:', error);
        alert('Error executing search: ' + error.message); // UI interaction
    }
}

async function toggleTodo(blockId, isDone) {
    try {
        let currentNoteData;
        // Attempt to get data from prefetchedBlocks first
        if (prefetchedBlocks && prefetchedBlocks[blockId]) {
            const prefetched = prefetchedBlocks[blockId];
            currentNoteData = {
                id: prefetched.note_id, // This should be the main note ID, not the block_id if they differ
                content: prefetched.content,
                block_id: blockId, // Keep the block_id that was passed
                properties: prefetched.properties || {}, // Ensure properties exists
                level: prefetched.level || 0, // Ensure level exists
                parent_id: prefetched.parent_id !== undefined ? prefetched.parent_id : null // Ensure parent_id exists
            };
        } else {
            // Fallback to fetching the block by its specific ID if not in prefetched or if more details are needed
            // findBlockByIdAPI is designed to fetch a block by its block_id (which might be different from note_id)
            const blockData = await findBlockByIdAPI(blockId);
            if (!blockData) throw new Error('Note/Block not found for toggling TODO');
            currentNoteData = {
                id: blockData.note_id || blockData.id, // Prefer note_id if available, else id
                content: blockData.content,
                block_id: blockId, // Keep the original blockId
                properties: blockData.properties || {},
                level: blockData.level || 0,
                parent_id: blockData.parent_id !== undefined ? blockData.parent_id : null
            };
        }

        if (!currentNoteData || currentNoteData.id === undefined) throw new Error('Essential note data (like ID) is missing for toggling TODO');

        // The logic for constructing newContentString and updatedNoteProperties is now in toggleTodoAPI.
        // We pass the necessary raw parts to it.
        await toggleTodoAPI(
            currentNoteData.id,         // The main ID of the note/item to update
            blockId,                    // The specific block_id (can be same as note_id)
            currentNoteData.content,    // Raw content
            isDone,
            currentNoteData.properties, // Current properties
            currentNoteData.level,      // Current level
            currentNoteData.parent_id   // Current parent_id
        );

        navigateToPage(currentPage.id); // Coordination
    } catch (error) {
        console.error('Error updating todo status:', error);
        alert('Error updating task: ' + error.message); // UI interaction
        const checkbox = document.querySelector(`input[type="checkbox"][onchange*="'${blockId}'"]`);
        if (checkbox) checkbox.checked = !isDone;
    }
}

function showAdvancedSearch() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="advanced-search-content">
            <h3>Advanced Search</h3>
            <div class="help-text">
                Enter a SQL query to search based on block properties. Example:<br>
                <code>SELECT * FROM notes WHERE id IN (SELECT note_id FROM properties WHERE property_key = 'status' AND property_value = 'done')</code>
            </div>
            <textarea id="advanced-search-query" placeholder="Enter your SQL query..."></textarea>
            <div class="button-group">
                <button class="btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                <button class="btn-primary" onclick="executeAdvancedSearch()">Search</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    const textarea = modal.querySelector('#advanced-search-query');
    if (textarea) {
        textarea.focus();
    }

    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

// replacePropertyTags is now in js/render.js

async function executeAdvancedSearch() {
    const queryInput = document.getElementById('advanced-search-query');
    if (!queryInput) {
        console.error('Advanced search query input not found.');
        return;
    }
    const query = queryInput.value.trim();

    if (!query) {
        return;
    }

    try {
        // Use executeAdvancedSearchAPI from api.js
        const results = await executeAdvancedSearchAPI(query);

        sessionStorage.setItem('searchResults', JSON.stringify(results)); // State/UI
        sessionStorage.setItem('searchQuery', query); // State/UI

        const modal = document.querySelector('.advanced-search-content'); // UI
        if (modal && modal.closest('.modal')) {
            modal.closest('.modal').remove(); // UI
        }

        window.location.hash = 'search-results'; // Coordination
    } catch (error) {
        console.error('Error executing advanced search:', error);
        alert('Error executing search: ' + error.message); // UI
    }
}

// findNoteAndPath is now in js/utils.js
// adjustLevels is now in js/utils.js

// renderBreadcrumbs is now in js/render.js

// zoomInOnNote and zoomOut will be moved to ui.js (or app.js if more coordination heavy)
async function zoomInOnNote(targetNoteReference) {
    let noteIdToZoom;

    if (typeof targetNoteReference === 'string' || typeof targetNoteReference === 'number') {
        noteIdToZoom = String(targetNoteReference);
    } else if (targetNoteReference && targetNoteReference.dataset && targetNoteReference.dataset.noteId) {
        noteIdToZoom = targetNoteReference.dataset.noteId;
    } else {
        console.error("Invalid target for zoomInOnNote:", targetNoteReference);
        return;
    }

    if (!currentPage || !currentPage.notes) {
        console.error("Cannot zoom, currentPage data is missing. Attempting to recover.");
        await zoomOut();
        return;
    }

    const noteDataWithPath = findNoteAndPath(noteIdToZoom, currentPage.notes);

    if (!noteDataWithPath || !noteDataWithPath.note) {
        console.error("Note to zoom (ID: " + noteIdToZoom + ") not found in current page data. Zooming out.");
        await zoomOut();
        return;
    }

    const { note: noteInFullTree, path } = noteDataWithPath;
    window.breadcrumbPath = path;
    clearActiveBlock(); // Clear focus before re-rendering

    document.body.classList.add('logseq-focus-active');
    outlineContainer.classList.add('focused');

    const clonedFocusedNote = JSON.parse(JSON.stringify(noteInFullTree));
    clonedFocusedNote.level = 0;
    if (clonedFocusedNote.children) {
        adjustLevels(clonedFocusedNote.children, 1);
    }
    const focusedNotesArray = [clonedFocusedNote];

    pageProperties.style.display = 'none';
    document.getElementById('new-note').style.display = 'none';
    document.getElementById('backlinks-container').style.display = 'none';

    const breadcrumbsHtml = renderBreadcrumbs(path);
    outlineContainer.innerHTML = breadcrumbsHtml + (await renderOutline(focusedNotesArray, 0, prefetchedBlocks));
    initSortable(outlineContainer);

    const focusedDomNote = outlineContainer.querySelector('.outline-item[data-level="0"]');
    if (focusedDomNote) {
        setTimeout(() => {
             focusedDomNote.scrollIntoView({ behavior: 'smooth', block: 'start' });
             setActiveBlock(focusedDomNote, false); // Set active without re-scrolling
        }, 0);
    }
}

function initSortable(containerElement) {
    const sortableOptions = {
        group: 'nested',
        animation: 150,
        fallbackOnBody: true,
        swapThreshold: 0.65,
        handle: '.static-bullet',
        onEnd: function(evt) {
            handleNoteDrop(evt);
        }
    };

    if (containerElement && !containerElement.classList.contains('has-sortable')) {
        new Sortable(containerElement, sortableOptions);
        containerElement.classList.add('has-sortable');
    }

    const childContainers = containerElement.querySelectorAll('.outline-children');
    childContainers.forEach(childContainer => {
        if (!childContainer.classList.contains('has-sortable')) {
            new Sortable(childContainer, sortableOptions);
            childContainer.classList.add('has-sortable');
        }
    });
}


function updateDraggedItemLevel(draggedItem, newBaseLevel) {
    if (!draggedItem || !draggedItem.dataset) return;

    draggedItem.dataset.level = newBaseLevel;

    const childrenContainer = draggedItem.querySelector('.outline-children');
    if (childrenContainer) {
        const childItems = childrenContainer.querySelectorAll(':scope > .outline-item');
        childItems.forEach(child => {
            updateDraggedItemLevel(child, newBaseLevel + 1);
        });
    }
}


function handleNoteDrop(evt) {
    const draggedItem = evt.item;
    const draggedNoteId = draggedItem.dataset.noteId;

    const oldParentItem = evt.from.closest('.outline-item');
    const oldParentId = oldParentItem ? oldParentItem.dataset.noteId : null;
    const oldLevel = parseInt(draggedItem.dataset.level);
    const oldIndex = evt.oldIndex;

    let newParentIdCandidateEl = evt.to.closest('.outline-item');
    let newParentId = newParentIdCandidateEl ? newParentIdCandidateEl.dataset.noteId : null;
    let newIndex = evt.newIndex;
    let newLevel;

    const dropTargetItemElement = evt.originalEvent.target.closest('.outline-item');

    if (dropTargetItemElement && dropTargetItemElement !== draggedItem) {
        const parentListOfDropTarget = dropTargetItemElement.parentElement;

        if (evt.to === parentListOfDropTarget &&
            parentListOfDropTarget.children[evt.newIndex - 1] === dropTargetItemElement) {
            newParentId = dropTargetItemElement.dataset.noteId;
            newIndex = 0;
        }
    }

    if (newParentId === null) {
        newLevel = 0;
    } else {
        const finalNewParentDomItem = outlineContainer.querySelector(`.outline-item[data-note-id="${newParentId}"]`);
        if (finalNewParentDomItem) {
            newLevel = parseInt(finalNewParentDomItem.dataset.level) + 1;
        } else {
            console.error("Error: Could not find the final new parent DOM element for level calculation. Reloading page.");
            loadPage(currentPage.id);
            return;
        }
    }

    updateDraggedItemLevel(draggedItem, newLevel);

    if (!currentPage || !currentPage.id) {
        console.error("Current page information is not available. Cannot save reorder changes.");
        alert("Error: Current page context lost. Please refresh.");
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today);
        return;
    }

    const payload = {
        action: 'reorder_note',
        note_id: parseInt(draggedNoteId),
        new_parent_id: newParentId ? parseInt(newParentId) : null,
        new_order: parseInt(newIndex),
        page_id: currentPage.id // currentPage is global state
    };

    // Use reorderNoteAPI from api.js
    reorderNoteAPI(payload.note_id, payload.new_parent_id, payload.new_order, payload.page_id)
        .then(() => {
            sessionStorage.setItem('lastActiveBlockIdBeforeReload', draggedNoteId); // UI/State
            // loadPage will handle re-activation
            loadPage(currentPage.id); // Coordination
        })
        .catch(error => {
            console.error('Error reordering note:', error);
            alert('Error saving changes: ' + error.message + '. Please refresh.'); // UI
            loadPage(currentPage.id); // Coordination
        });
}

// zoomOut will be moved to ui.js (or app.js if more coordination heavy)
async function zoomOut() {
    document.body.classList.remove('logseq-focus-active');
    outlineContainer.classList.remove('focused');
    window.breadcrumbPath = null;
    clearActiveBlock(); // clearActiveBlock is in ui.js

    document.getElementById('new-note').style.display = 'block';
    document.getElementById('backlinks-container').style.display = 'block';

    if (currentPage && currentPage.id) {
        await loadPage(currentPage.id); // loadPage is in app.js (coordination)
    } else {
        console.warn("Zooming out but currentPage is not fully defined. Reloading to today's page.");
        const today = new Date().toISOString().split('T')[0];
        navigateToPage(today); // navigateToPage is in app.js (coordination)
    }
}

// handleGlobalKeyDown is now in js/ui.js
// navigateBlocks is now in js/ui.js

function handleSnippetReplacement(event) {
    const textarea = event.target;
    setTimeout(() => {
        const cursorPos = textarea.selectionStart;
        const text = textarea.value;
        let textBeforeCursor = text.substring(0, cursorPos);
        let replacementMade = false;
        let triggerChar = '';

        if(event.key === ' ' || event.key === 'Enter' || (event.data === ' ' && event.type === 'input')) {
             triggerChar = ' ';
        } else if (event.type === 'input' && event.data !== null) {
            return;
        } else {
            return;
        }

        if (textBeforeCursor.endsWith(':t' + triggerChar)) {
            const replacement = '{tag::}';
            const triggerFull = ':t' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length - 1;
            replacementMade = true;
        }
        else if (textBeforeCursor.endsWith(':r' + triggerChar)) {
            const now = new Date();
            const timeString = now.toISOString();
            const replacement = `{time::${timeString}} `;
            const triggerFull = ':r' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length;
            replacementMade = true;
        }
        else if (textBeforeCursor.endsWith(':d' + triggerChar)) {
            const now = new Date();
            const dateString = now.toISOString().split('T')[0];
            const replacement = `{date::${dateString}} `;
            const triggerFull = ':d' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length;
            replacementMade = true;
        }

    }, 0);
}

// --- Drag and Drop File Upload Handler ---
// Attached to window for now, assuming no ES6 modules for simplicity.
window.handleDroppedFiles = async function(noteId, files) {
    if (!files || files.length === 0) {
        return;
    }

    let allUploadsSuccessful = true;
    // Use a for...of loop to allow await within the loop
    for (const file of files) {
        try {
            // uploadFileAPI is defined in js/api.js and should return a promise.
            // It typically handles creating FormData and making the fetch request.
            await uploadFileAPI(noteId, file);
            console.log(`File ${file.name} uploaded successfully to note ${noteId}`);
        } catch (error) {
            allUploadsSuccessful = false;
            console.error('Error uploading file:', file.name, error);
            alert(`Error uploading file: ${file.name}\n${error.message || 'Unknown error'}`);
            // Continue to try uploading other files even if one fails.
        }
    }

    // Refresh the page to show new attachments, regardless of individual failures,
    // so successful uploads are displayed.
    // This matches the behavior of the single file upload via button.
    if (currentPage && currentPage.id) {
        navigateToPage(currentPage.id);
    } else {
        console.warn("Current page context lost after file drop. Cannot auto-refresh.");
        // If all uploads were intended to be successful before refresh, this alert might change.
        // But since we refresh even on partial success, this specific alert might only be relevant
        // if currentPage.id is truly lost for other reasons.
        if (allUploadsSuccessful) {
             alert("File(s) uploaded, but couldn't automatically refresh the page. Please refresh manually.");
        } else {
             alert("Some files uploaded, but couldn't automatically refresh. Please refresh manually to see changes.");
        }
    }
};