/**
 * Main application module for NotTD
 * Handles state management, event handling, and coordination between UI and API
 * @module app
 */

// Core state management
import {
    currentPageName,
    saveStatus,
    pageDataCache,
    CACHE_MAX_AGE_MS,
    MAX_PREFETCH_PAGES,
    notesForCurrentPage,
    currentFocusedNoteId,
    // Setters
    setCurrentPageId,
    setCurrentPageName,
    setSaveStatus,
    setNotesForCurrentPage,
    addNoteToCurrentPage,
    removeNoteFromCurrentPageById,
    updateNoteInCurrentPage,
    setCurrentFocusedNoteId,
    // Cache functions
    setPageCache,
    getPageCache,
    hasPageCache,
    deletePageCache,
    clearPageCache
} from './app/state.js';

// Page management
import {
    loadPage,
    prefetchRecentPagesData,
    getInitialPage
} from './app/page-loader.js';

// UI and event handling
import { sidebarState } from './app/sidebar.js';
import { initGlobalEventListeners } from './app/event-handlers.js';
import { debounce, safeAddEventListener } from './utils.js';
import { ui } from './ui.js';

// Note actions
import {
    handleAddRootNote,
    handleNoteKeyDown,
    handleTaskCheckboxClick,
    getNoteDataById,
    getNoteElementById,
    debouncedSaveNote // Added import
} from './app/note-actions.js';

// Import API clients
import { notesAPI, propertiesAPI, attachmentsAPI, searchAPI, templatesAPI, pagesAPI } from './api_client.js';

// Import app initialization
import { initializeApp } from './app/app-init.js';

// Make some variables globally accessible for drag and drop
// window.currentPageId, window.notesForCurrentPage, window.currentFocusedNoteId are set in state.js
window.notesAPI = notesAPI;

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
    // backlinksContainer, // Used in page-loader.js
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
    // backlinksContainer: !!backlinksContainer, 
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
 * Fetches and displays the page list
 * @param {string} [activePageName] - Name of the page to mark as active
 */
async function fetchAndDisplayPages(activePageName) {
    try {
        const pages = await pagesAPI.getPages();
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
    const todayPageName = getInitialPage(); // Use imported getInitialPage
    await loadPage(todayPageName, true); // Imported from page-loader
    await fetchAndDisplayPages(todayPageName); // Local function using imported currentPageName
}

// getNoteDataById and getNoteElementById are now primarily in note-actions.js
// If other parts of app.js still need them, they can import them from note-actions.js
// For now, assuming they are not directly called from app.js after refactoring.

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
safeAddEventListener(addRootNoteBtn, 'click', handleAddRootNote, 'addRootNoteBtn');

// Note keyboard navigation and editing
if (notesContainer) { // Ensure notesContainer is available before adding listeners
    notesContainer.addEventListener('keydown', handleNoteKeyDown);

    // Note interactions (task markers)
    notesContainer.addEventListener('click', (e) => {
        if (e.target.matches('.task-checkbox')) {
            handleTaskCheckboxClick(e);
        }
        // Other click interactions on notesContainer could be handled here or in other modules
    });

    // Input event for debounced save (moved from direct anonymous function)
    // This needs to be handled carefully: handleNoteKeyDown might already trigger saves.
    // This was originally:
    // safeAddEventListener(notesContainer, 'input', (e) => {
    //     if (e.target.matches('.note-content.edit-mode')) {
    //         const noteItem = e.target.closest('.note-item');
    //         if (noteItem) {
    //             const contentDiv = e.target;
    //             const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
    //             contentDiv.dataset.rawContent = ui.normalizeNewlines(rawTextValue);
    //             debouncedSaveNote(noteItem); // debouncedSaveNote would be imported from note-actions
    //         }
    //     }
    // }, 'notesContainer');
    // For now, this specific input listener is effectively part of handleNoteKeyDown or direct saves.
    // If separate debouncing on general input is still desired, it needs to import debouncedSaveNote.

    // Add the new input event listener here
    safeAddEventListener(notesContainer, 'input', (e) => {
        if (e.target.matches('.note-content.edit-mode')) {
            const noteItem = e.target.closest('.note-item');
            if (noteItem) {
                const contentDiv = e.target;
                // Ensure ui object and its methods are correctly referenced.
                // Assuming 'ui' is imported and available in this scope.
                const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
                const normalizedContent = ui.normalizeNewlines(rawTextValue);
                contentDiv.dataset.rawContent = normalizedContent;
                debouncedSaveNote(noteItem);
            }
        }
    }, 'notesContainerInput'); // Added a unique name for safeAddEventListener
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

    // Clear existing content and event listeners
    pagePropertiesList.innerHTML = '';
    
    if (!properties || Object.keys(properties).length === 0) {
        console.log('No properties to display in modal');
        pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
        return;
    }

    Object.entries(properties).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            // Handle array properties - show each value separately but allow editing
            value.forEach((singleValue, index) => {
                const propItem = document.createElement('div');
                propItem.className = 'page-property-item';
                propItem.innerHTML = `
                    <span class="page-property-key" contenteditable="true" data-original-key="${key}" data-is-array="true" data-array-index="${index}">${key}</span>
                    <span class="page-property-separator">:</span>
                    <input type="text" class="page-property-value" data-property="${key}" data-array-index="${index}" data-original-value="${singleValue}" value="${singleValue}" />
                    <button class="page-property-delete" data-property="${key}" data-array-index="${index}" title="Delete this ${key} value">×</button>
                `;
                pagePropertiesList.appendChild(propItem);
            });
        } else {
            // Handle single value properties
            const propItem = document.createElement('div');
            propItem.className = 'page-property-item';
            propItem.innerHTML = `
                <span class="page-property-key" contenteditable="true" data-original-key="${key}">${key}</span>
                <span class="page-property-separator">:</span>
                <input type="text" class="page-property-value" data-property="${key}" data-original-value="${value || ''}" value="${value || ''}" />
                <button class="page-property-delete" data-property="${key}" title="Delete ${key} property">×</button>
            `;
            pagePropertiesList.appendChild(propItem);
        }
    });

    // Remove any existing event listeners to prevent duplicates
    const existingListener = pagePropertiesList._propertyEventListener;
    if (existingListener) {
        pagePropertiesList.removeEventListener('blur', existingListener, true);
        pagePropertiesList.removeEventListener('keydown', existingListener);
        pagePropertiesList.removeEventListener('click', existingListener);
        pagePropertiesList.removeEventListener('change', existingListener);
    }

    // Create new event listener function
    const propertyEventListener = async (e) => {
        // Handle property value editing (change event for input fields)
        if (e.type === 'change' && e.target.matches('.page-property-value')) {
            const key = e.target.dataset.property;
            const newValue = e.target.value.trim();
            const originalValue = e.target.dataset.originalValue;
            const arrayIndex = e.target.dataset.arrayIndex;
            
            if (newValue !== originalValue) {
                if (arrayIndex !== undefined) {
                    // Handle array property value update
                    await updateArrayPropertyValue(key, parseInt(arrayIndex), newValue);
                } else {
                    // Handle single property value update
                    await updatePageProperty(key, newValue);
                }
                e.target.dataset.originalValue = newValue;
            }
        }
        
        // Handle property key editing (blur event)
        else if (e.type === 'blur' && e.target.matches('.page-property-key')) {
            const originalKey = e.target.dataset.originalKey;
            const newKey = e.target.textContent.trim();
            const isArray = e.target.dataset.isArray === 'true';
            const arrayIndex = e.target.dataset.arrayIndex;
            
            if (newKey !== originalKey && newKey !== '') {
                if (isArray) {
                    // For array properties, we need to handle renaming more carefully
                    await renameArrayPropertyKey(originalKey, newKey, parseInt(arrayIndex));
                } else {
                    // Handle single property key rename
                    await renamePropertyKey(originalKey, newKey);
                }
                e.target.dataset.originalKey = newKey;
            } else if (newKey === '') {
                // Reset to original key if empty
                e.target.textContent = originalKey;
            }
        }
        
        // Handle Enter key to commit changes
        else if (e.type === 'keydown' && e.key === 'Enter') {
            if (e.target.matches('.page-property-value')) {
                // For input fields, trigger change event
                e.target.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (e.target.matches('.page-property-key')) {
                // For contenteditable keys, trigger blur
                e.target.blur();
            }
        }
        
        // Handle property deletion (click event)
        else if (e.type === 'click' && e.target.matches('.page-property-delete')) {
            const key = e.target.dataset.property;
            const arrayIndex = e.target.dataset.arrayIndex;
            
            let confirmMessage;
            if (arrayIndex !== undefined) {
                confirmMessage = `Are you sure you want to delete this "${key}" value?`;
            } else {
                confirmMessage = `Are you sure you want to delete the property "${key}"?`;
            }
            
            const confirmed = await ui.showGenericConfirmModal('Delete Property', confirmMessage);
            if (confirmed) {
                if (arrayIndex !== undefined) {
                    await deleteArrayPropertyValue(key, parseInt(arrayIndex));
                } else {
                    await deletePageProperty(key);
                }
            }
        }
    };

    // Store reference to the listener for cleanup
    pagePropertiesList._propertyEventListener = propertyEventListener;

    // Add event listeners
    pagePropertiesList.addEventListener('blur', propertyEventListener, true);
    pagePropertiesList.addEventListener('keydown', propertyEventListener);
    pagePropertiesList.addEventListener('click', propertyEventListener);
    pagePropertiesList.addEventListener('change', propertyEventListener); // Add change listener for input fields

    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace(); // Ensure Feather icons are re-applied
    }
}

/**
 * Renames a property key
 * @param {string} oldKey - Original property key
 * @param {string} newKey - New property key
 */
async function renamePropertyKey(oldKey, newKey) {
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        const value = properties[oldKey];
        
        if (value === undefined) {
            console.warn(`Property ${oldKey} not found for renaming`);
            return;
        }

        // Delete old property and create new one
        await propertiesAPI.deleteProperty('page', pageIdToUse, oldKey);
        await propertiesAPI.setProperty({
            entity_type: 'page',
            entity_id: pageIdToUse,
            name: newKey,
            value: value
        });

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error renaming property key:', error);
        alert('Failed to rename property');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

/**
 * Renames a property key for array properties
 * @param {string} oldKey - Original property key
 * @param {string} newKey - New property key  
 * @param {number} arrayIndex - Index of the array value being edited
 */
async function renameArrayPropertyKey(oldKey, newKey, arrayIndex) {
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        const values = properties[oldKey];
        
        if (!Array.isArray(values)) {
            console.warn(`Property ${oldKey} is not an array for renaming`);
            return;
        }

        // For array properties, we need to move all values to the new key
        await propertiesAPI.deleteProperty('page', pageIdToUse, oldKey);
        
        // Add all values under the new key
        for (const value of values) {
            await propertiesAPI.setProperty({
                entity_type: 'page',
                entity_id: pageIdToUse,
                name: newKey,
                value: value
            });
        }

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error renaming array property key:', error);
        alert('Failed to rename property');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

/**
 * Updates a specific value in an array property
 * @param {string} key - Property key
 * @param {number} arrayIndex - Index of the value to update
 * @param {string} newValue - New value
 */
async function updateArrayPropertyValue(key, arrayIndex, newValue) {
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        const values = properties[key];
        
        if (!Array.isArray(values) || arrayIndex >= values.length) {
            console.warn(`Invalid array property update: ${key}[${arrayIndex}]`);
            return;
        }

        // Delete all values for this key
        await propertiesAPI.deleteProperty('page', pageIdToUse, key);
        
        // Re-add all values with the updated one
        for (let i = 0; i < values.length; i++) {
            const value = i === arrayIndex ? newValue : values[i];
            await propertiesAPI.setProperty({
                entity_type: 'page',
                entity_id: pageIdToUse,
                name: key,
                value: value
            });
        }

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error updating array property value:', error);
        alert('Failed to update property value');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

/**
 * Deletes a specific value from an array property
 * @param {string} key - Property key
 * @param {number} arrayIndex - Index of the value to delete
 */
async function deleteArrayPropertyValue(key, arrayIndex) {
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        const values = properties[key];
        
        if (!Array.isArray(values) || arrayIndex >= values.length) {
            console.warn(`Invalid array property deletion: ${key}[${arrayIndex}]`);
            return;
        }

        // Delete all values for this key
        await propertiesAPI.deleteProperty('page', pageIdToUse, key);
        
        // Re-add all values except the one being deleted
        const remainingValues = values.filter((_, i) => i !== arrayIndex);
        for (const value of remainingValues) {
            await propertiesAPI.setProperty({
                entity_type: 'page',
                entity_id: pageIdToUse,
                name: key,
                value: value
            });
        }

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error deleting array property value:', error);
        alert('Failed to delete property value');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
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
        allPagesForSearch = await pagesAPI.getPages({ excludeJournal: true });
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

// Start the application
document.addEventListener('DOMContentLoaded', async () => {
    // Ensure UI module is loaded
    if (typeof ui === 'undefined') {
        console.error('UI module not loaded. Please check script loading order.');
        return;
    }
    
    try {
        await initializeApp();
    } catch (error) {
        console.error('Failed to initialize application:', error);
        // Show error in UI if needed
    }
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
            const pageIdToUse = currentPageId; // From imported state
            if (!pageIdToUse) return; // Should not happen if note exists
            const freshNotes = await notesAPI.getNotesForPage(pageIdToUse);
            setNotesForCurrentPage(freshNotes);

            ui.displayNotes(notesForCurrentPage, pageIdToUse);

            const focusedNoteId = currentFocusedNoteId; // From imported state
            if (focusedNoteId) {
                const focusedNoteStillExists = notesForCurrentPage.some(n => String(n.id) === String(focusedNoteId));
                if (focusedNoteStillExists) {
                    ui.focusOnNote(focusedNoteId);
                } else {
                    ui.showAllNotes();
                }
            }
        }
    } catch (error) {
        console.error('Error handling file uploads:', error);
    }
});

/**
 * Adds a new page property
 * @param {string} key - Property key
 * @param {string} value - Property value
 */
async function addPageProperty(key, value) {
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        await propertiesAPI.setProperty({
            entity_type: 'page',
            entity_id: pageIdToUse,
            name: key,
            value: value
        });

        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
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
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        await propertiesAPI.setProperty({
            entity_type: 'page',
            entity_id: pageIdToUse,
            name: key,
            value: value
        });

        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
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
    const pageIdToUse = currentPageId; // From imported state
    if (!pageIdToUse) return;

    try {
        await propertiesAPI.deleteProperty('page', pageIdToUse, key);
        const properties = await propertiesAPI.getProperties('page', pageIdToUse);
        displayPageProperties(properties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error deleting page property:', error);
        alert('Failed to delete property');
    }
}

/**
 * Updates the visual save status indicator based on the global saveStatus.
 */
function updateSaveStatusIndicatorVisuals() {
    const indicator = document.getElementById('save-status-indicator');
    if (!indicator) {
        console.warn('Save status indicator element not found.');
        return;
    }

    // Global saveStatus (already updated by a setter) is used here for visuals
    const currentSaveStatus = saveStatus; 

    const splashScreen = document.getElementById('splash-screen');
    const isSplashVisible = splashScreen && !splashScreen.classList.contains('hidden');

    if (isSplashVisible) {
        indicator.classList.add('status-hidden');
        indicator.innerHTML = ''; // Clear content when hidden by splash
        return;
    } else {
        indicator.classList.remove('status-saved', 'status-pending', 'status-error');
        indicator.classList.add(`status-${currentSaveStatus}`);
    }

    let iconHtml = '';
    switch (currentSaveStatus) {
        case 'saved':
            iconHtml = '<i data-feather="check-circle"></i>';
            indicator.title = 'All changes saved';
            break;
        case 'pending':
            iconHtml = `
                <div class="dot-spinner">
                    <div class="dot-spinner__dot"></div>
                    <div class="dot-spinner__dot"></div>
                    <div class="dot-spinner__dot"></div>
                </div>`;
            indicator.title = 'Saving changes...';
            break;
        case 'error':
            iconHtml = '<i data-feather="alert-triangle"></i>';
            indicator.title = 'Error saving changes. Please try again.';
            break;
        default: 
            console.warn(`Unknown save status: ${currentSaveStatus}. Defaulting visual to 'saved'.`);
            indicator.classList.remove('status-pending', 'status-error'); 
            indicator.classList.add(`status-saved`);
            iconHtml = '<i data-feather="check-circle"></i>';
            indicator.title = 'All changes saved';
            break;
    }
    indicator.innerHTML = iconHtml;

    if (currentSaveStatus === 'saved' || currentSaveStatus === 'error') {
        if (typeof feather !== 'undefined' && feather.replace) {
            feather.replace({
                width: '18px',
                height: '18px',
                'stroke-width': '2' 
            });
        } else {
            console.warn('Feather Icons library not found. Icons for "saved" or "error" status might not render.');
            if (currentSaveStatus === 'saved') indicator.textContent = '✓'; 
            if (currentSaveStatus === 'error') indicator.textContent = '!'; 
        }
    }
}