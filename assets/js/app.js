/**
 * Main application module for NotTD
 * Handles state management, event handling, and coordination between UI and API
 * @module app
 */

// State
let currentPageId = null;
let currentPageName = null;
let saveStatus = 'saved'; // Can be 'saved', 'pending', 'error'

// NEW: Pre-fetching cache
const pageDataCache = new Map(); // Using a Map for easier key management
const CACHE_MAX_AGE_MS = 10 * 60 * 1000; // 10 minutes
const MAX_PREFETCH_PAGES = 10;
let notesForCurrentPage = []; // Flat list of notes with properties

// Make some variables globally accessible for drag and drop
window.currentPageId = null;
window.notesForCurrentPage = [];
window.notesAPI = notesAPI;
window.currentFocusedNoteId = null; // Initialize focused note ID

/**
 * Helper functions to replace deprecated document.execCommand
 */

/**
 * Inserts text at the current cursor position and positions cursor
 * @param {string} text - Text to insert
 * @param {number} cursorOffset - Position cursor relative to start of inserted text
 */
function insertTextAtCursor(text, cursorOffset = 0) {
    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;
    
    const range = selection.getRangeAt(0);
    const textNode = document.createTextNode(text);
    range.insertNode(textNode);
    
    // Position cursor
    range.setStart(textNode, cursorOffset);
    range.setEnd(textNode, cursorOffset);
    selection.removeAllRanges();
    selection.addRange(range);
}

/**
 * Replaces text by deleting characters before cursor and inserting new text
 * @param {number} deleteCount - Number of characters to delete before cursor
 * @param {string} newText - Text to insert
 * @param {number} cursorOffset - Position cursor relative to start of inserted text
 */
function replaceTextAtCursor(deleteCount, newText, cursorOffset = 0) {
    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;
    
    const range = selection.getRangeAt(0);
    
    // Delete characters before cursor
    range.setStart(range.startContainer, range.startOffset - deleteCount);
    range.deleteContents();
    
    // Insert new text
    const textNode = document.createTextNode(newText);
    range.insertNode(textNode);
    
    // Position cursor
    range.setStart(textNode, cursorOffset);
    range.setEnd(textNode, cursorOffset);
    selection.removeAllRanges();
    selection.addRange(range);
}

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
    console.log(`Loading page: ${pageName}, focusFirstNote: ${focusFirstNote}, updateHistory: ${updateHistory}`);
    if (window.blockPageLoad) {
        console.warn('Page load blocked, possibly due to unsaved changes or ongoing operation.');
        return;
    }
    window.blockPageLoad = true;

    // NEW: Check cache first
    if (pageDataCache.has(pageName) && (Date.now() - pageDataCache.get(pageName).timestamp < CACHE_MAX_AGE_MS)) {
        const cachedData = pageDataCache.get(pageName);
        console.log(`Using cached data for page: ${pageName}`);
        
        try {
            currentPageName = cachedData.name; // Use canonical name from cache
            currentPageId = cachedData.id;
            window.currentPageId = currentPageId;
            window.currentPageName = currentPageName;

            if (updateHistory) {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('page', currentPageName);
                history.pushState({ pageName: currentPageName }, '', newUrl.toString());
            }

            ui.updatePageTitle(currentPageName);
            if (ui.calendarWidget && typeof ui.calendarWidget.setCurrentPage === 'function') {
                 ui.calendarWidget.setCurrentPage(currentPageName);
            }

            // Use cached page details, properties, and notes
            const pageDetails = { // Reconstruct pageDetails from cache
                id: cachedData.id,
                name: cachedData.name,
                alias: cachedData.alias // Ensure alias is cached
                // Add other relevant page attributes if they are stored in cache
            };
            const pageProperties = cachedData.properties;
            notesForCurrentPage = cachedData.notes; // These already include their properties

            console.log('Page details for (cached)', currentPageName, ':', pageDetails);
            console.log('Page properties for (cached)', currentPageName, ':', pageProperties);
            console.log('Notes for (cached)', currentPageName, ':', notesForCurrentPage.length);
            
            window.notesForCurrentPage = notesForCurrentPage; // Update global state
            
            // Render UI
            if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
                ui.renderPageInlineProperties(pageProperties, ui.domRefs.pagePropertiesContainer);
            }
            ui.displayNotes(notesForCurrentPage, currentPageId);
            ui.updateActivePageLink(currentPageName);

            // Backlinks are still fetched live
            const backlinks = await searchAPI.getBacklinks(currentPageName);
            displayBacklinks(backlinks);
            await handleTransclusions();

            if (focusFirstNote) {
                const firstNote = notesContainer.querySelector('.note-content');
                if (firstNote) firstNote.focus();
            }
            window.blockPageLoad = false;
            return; // Exit early as page loaded from cache
        } catch (error) {
            console.error('Error loading page from cache, falling back to network:', error);
            // Clear potentially corrupted cache entry
            pageDataCache.delete(pageName);
            // Continue to network fetch
        }
    }
    // End of NEW cache check

    try {
        if (!pageName || pageName.trim() === '') {
            console.warn('loadPage called with empty pageName, defaulting to initial page.');
            pageName = getInitialPage();
        }

        // Clear existing content and show loading state if applicable
        if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = '<p>Loading page...</p>';
        if (ui.domRefs.pagePropertiesContainer) ui.domRefs.pagePropertiesContainer.innerHTML = ''; // Clear inline props

        // Step 1: Get initial page data (ID and canonical name)
        const pageData = await pagesAPI.getPageByName(pageName);
        if (!pageData) {
            throw new Error(`Page "${pageName}" not found and could not be created.`);
        }

        currentPageName = pageData.name; // pageData.name is the canonical name
        currentPageId = pageData.id;
        window.currentPageId = currentPageId; // Make globally available for other modules
        window.currentPageName = currentPageName; // Make globally available for other modules

        // Update browser URL if requested
        if (updateHistory) {
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('page', currentPageName);
            history.pushState({ pageName: currentPageName }, '', newUrl.toString());
        }

        ui.updatePageTitle(currentPageName);

        // Update calendar widget to show current page
        if (ui.calendarWidget && typeof ui.calendarWidget.setCurrentPage === 'function') {
            ui.calendarWidget.setCurrentPage(currentPageName);
        }

        // Step 2: Fetch combined page data (details, notes with properties, page properties)
        console.log(`Fetching page data using notesAPI.getPageData for: ${currentPageName} (ID: ${currentPageId})`);
        const pageResponse = await notesAPI.getPageData(currentPageId, { include_internal: false });

        if (!pageResponse || !pageResponse.page || !pageResponse.notes) {
            throw new Error('Invalid response structure from getPageData');
        }

        // Unpack data
        const pageDetails = pageResponse.page;
        const pageProperties = pageResponse.page.properties || {};
        notesForCurrentPage = pageResponse.notes; // These notes already include their properties
        window.notesForCurrentPage = notesForCurrentPage; // Update global

        // Ensure currentPageId and currentPageName are updated from the definitive source
        currentPageId = pageDetails.id;
        currentPageName = pageDetails.name; // This should be the canonical name
        window.currentPageId = currentPageId;
        window.currentPageName = currentPageName;
        
        // Fetch backlinks separately
        const backlinks = await searchAPI.getBacklinks(currentPageName);
        console.log(`Fetched backlinks for page: ${currentPageName}`);

        // Step 3: Update the cache with fetched data
        if (currentPageId && currentPageName) {
            const cacheEntry = {
                id: pageDetails.id,
                name: pageDetails.name, // Canonical name
                alias: pageDetails.alias, // Store alias if present
                // Potentially store other pageDetails attributes if needed for cache restoration
                notes: notesForCurrentPage,    // Already includes note properties
                properties: pageProperties,    // Page properties
                timestamp: Date.now()
            };
            console.log(`Page data for ${currentPageName} fetched from network and cached.`);
        }

        // Step 4: Render the page content
        console.log('Page properties for ', currentPageName, ':', pageProperties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(pageProperties, ui.domRefs.pagePropertiesContainer);
        }

        ui.displayNotes(notesForCurrentPage, currentPageId);
        ui.updateActivePageLink(currentPageName);
        displayBacklinks(backlinks);

        // Step 5: Handle transclusions (depends on notes being rendered)
        await handleTransclusions(); // Pass notesForCurrentPage if it needs it

        if (focusFirstNote) {
            const firstNote = notesContainer.querySelector('.note-content');
            if (firstNote) firstNote.focus();
        }
    } catch (error) {
        console.error('Error loading page:', error);
        currentPageName = `Error: ${pageName}`;
        window.currentPageName = currentPageName; // Expose globally
        ui.updatePageTitle(currentPageName); // Use ui.updatePageTitle
        if (notesContainer) { // Ensure notesContainer is defined
            notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
        }
    } finally {
        window.blockPageLoad = false; // Allow subsequent loads
    }

    // If notesForCurrentPage is empty after loading, create the first note.
    if (notesForCurrentPage.length === 0 && currentPageId) {
        await handleCreateAndFocusFirstNote();
    }
}

/**
 * Creates the very first note on an empty page and focuses it.
 */
async function handleCreateAndFocusFirstNote() {
    if (!currentPageId) {
        console.warn("Cannot create first note without a currentPageId.");
        return;
    }
    try {
        // Create note with a placeholder content to ensure it's saved
        const savedNote = await notesAPI.createNote({
            page_id: currentPageId,
            content: ' ', // Use a space instead of empty string to ensure it's saved
            parent_note_id: null
            // order_index will be handled by the backend
        });

        if (savedNote) {
            notesForCurrentPage.push(savedNote);
            window.notesForCurrentPage = notesForCurrentPage; // Update global

            // Ensure notesContainer is empty or ready for the first note
            if(notesContainer.innerHTML.includes("empty-page-hint") || notesContainer.children.length === 0) {
                notesContainer.innerHTML = ''; // Clear any hints or placeholders
            }

            const noteEl = ui.renderNote(savedNote, 0);
            if (notesContainer) {
                notesContainer.appendChild(noteEl);
            }

            const contentDiv = noteEl.querySelector('.note-content');
            if (contentDiv) {
                // Set initial rawContent to match the saved content
                contentDiv.dataset.rawContent = savedNote.content;
                // Clear the content div before switching to edit mode
                contentDiv.textContent = '';
                ui.switchToEditMode(contentDiv);
                
                // Add a one-time input handler to ensure first content is saved
                const initialInputHandler = async (e) => {
                    const currentContent = contentDiv.textContent.trim();
                    if (currentContent !== '') {
                        contentDiv.dataset.rawContent = currentContent;
                        await saveNoteImmediately(noteEl);
                        contentDiv.removeEventListener('input', initialInputHandler);
                    }
                };
                contentDiv.addEventListener('input', initialInputHandler);
            }
             // Initialize Feather icons if new elements with icons were added
            if (typeof feather !== 'undefined' && feather.replace) {
                feather.replace();
            }
        }
    } catch (error) {
        console.error('Error creating the first note for the page:', error);
        if (notesContainer) {
            notesContainer.innerHTML = '<p>Error creating the first note. Please try reloading.</p>';
        }
    }
}

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
    if (placeholders.length === 0) {
        console.log('No transclusion placeholders found.');
        return;
    }
    console.log(`Found ${placeholders.length} transclusion placeholders.`);

    const blockIdsSet = new Set();
    placeholders.forEach(placeholder => {
        const blockRef = placeholder.dataset.blockRef;
        if (blockRef && blockRef.trim() !== '') { // Basic validation: not empty
            blockIdsSet.add(String(blockRef)); // Ensure string IDs
        } else {
            console.warn('Placeholder found with invalid or missing data-block-ref', placeholder);
            placeholder.textContent = 'Invalid block reference (missing ref)';
            placeholder.classList.add('error');
        }
    });

    const blockIdsArray = Array.from(blockIdsSet);

    if (blockIdsArray.length === 0) {
        console.log('No valid block IDs to fetch for transclusions.');
        // Placeholders with invalid refs already updated, so just return
        return;
    }

    console.log(`Fetching content for ${blockIdsArray.length} unique block IDs for transclusion.`);

    try {
        // Fetch notes individually and build the notesMap
        const notesMap = {};
        for (const blockId of blockIdsArray) {
            try {
                // Assuming a singular getNote method exists, e.g., notesAPI.getNote(id)
                // Adjust if the actual singular fetch method is named differently (e.g., notesAPI.getNoteById)
                const note = await notesAPI.getNote(blockId); 
                if (note) {
                    notesMap[blockId] = note;
                } else {
                    console.warn(`Note not found (or null response) for blockId during individual fetch: ${blockId}`);
                    // Optionally, add a placeholder or skip if note is not found
                    // notesMap[blockId] = { content: 'Block not found via individual fetch' }; 
                }
            } catch (fetchError) {
                console.error(`Error fetching individual note for blockId ${blockId}:`, fetchError);
                // Optionally, add an error placeholder to notesMap
                // notesMap[blockId] = { content: 'Error loading block via individual fetch' };
            }
        }
        
        placeholders.forEach(placeholder => {
            const blockRef = placeholder.dataset.blockRef;
            // Skip placeholders already marked as invalid
            if (!blockRef || blockRef.trim() === '') {
                return;
            }

            const note = notesMap[blockRef]; // Assumes notesMap is an object/map
            
            if (note && note.content) {
                // console.log(`Content found for blockRef ${blockRef}:`, note.content.substring(0, 100) + "...");
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
        });

    } catch (error) {
        console.error('Error loading multiple transclusions:', error);
        placeholders.forEach(placeholder => {
            // Only update placeholders that haven't been marked with an invalid ref error
            if (placeholder.dataset.blockRef && placeholder.dataset.blockRef.trim() !== '') {
                placeholder.textContent = 'Error loading block';
                placeholder.classList.add('error');
            }
        });
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

async function prefetchRecentPagesData() {
    console.log("Starting pre-fetch for recent pages.");
    try {
        // Fetch pages with all details included
        const allPages = await pagesAPI.getPages({
            include_details: true, 
            include_internal: false, // Keep pre-fetched data lean
            followAliases: true,     // Cache against canonical names
            excludeJournal: false    // Include journal pages if recent
        });
        
        // Sort pages by updated_at descending if not already sorted by API
        // Assuming 'updated_at' is a string like 'YYYY-MM-DD HH:MM:SS'
        allPages.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));

        const recentPagesToPrefetch = allPages.slice(0, MAX_PREFETCH_PAGES);

        for (const page of recentPagesToPrefetch) {
            if (!pageDataCache.has(page.name) || 
                (Date.now() - (pageDataCache.get(page.name)?.timestamp || 0) > CACHE_MAX_AGE_MS)) {
                
                console.log(`Pre-fetching data for page: ${page.name}`);
                try {
                    // The 'page' object from pagesAPI.getPages with include_details: true 
                    // should already contain 'notes' and 'properties'.
                    // 'page.name' should be canonical due to followAliases: true.
                    // 'page.id' should also be present.

                    if (!page.id || !page.name) {
                        console.warn(`Page object for pre-fetch is missing id or name. Skipping.`, page);
                        continue;
                    }
                    
                    // notes and properties are directly on the page object
                    const notes = page.notes || []; // Default to empty array if not present
                    const properties = page.properties || {}; // Default to empty object

                    pageDataCache.set(page.name, {
                        id: page.id,
                        name: page.name, 
                        alias: page.alias, // Store alias if available
                        notes: notes,      // These notes should already have their properties
                        properties: properties, // Page-level properties
                        timestamp: Date.now()
                    });
                    console.log(`Successfully pre-fetched and cached data for page: ${page.name}`);
                } catch (error) {
                    console.error(`Error pre-fetching data for page ${page.name}:`, error);
                    // Optionally remove from cache if fetching failed partially
                    if (pageDataCache.has(page.name)) {
                        pageDataCache.delete(page.name);
                    }
                }
            } else {
                console.log(`Page ${page.name} is already in cache and recent.`);
            }
        }
        console.log("Pre-fetching for recent pages completed.");
    } catch (error) {
        console.error('Error fetching page list for pre-fetching:', error);
    }
}

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

    try {
        // 1. Call notesAPI.createNote first
        const savedNote = await notesAPI.createNote({
            page_id: currentPageId,
            content: '', // Initial content is empty
            parent_note_id: null
        });

        if (savedNote) {
            // a. Add savedNote to the notesForCurrentPage array
            notesForCurrentPage.push(savedNote);
            window.notesForCurrentPage = notesForCurrentPage; // Update global

            // b. Call ui.renderNote to create the noteEl
            // Assuming root notes have nesting level 0
            // const noteEl = ui.renderNote(savedNote, 0); // Old way
            // if (notesContainer) { // Old way
            //     notesContainer.appendChild(noteEl); // Old way
            // }
            const noteEl = ui.addNoteElement(savedNote, notesContainer, 0); // New way

            // d. Get the contentDiv from noteEl
            const contentDiv = noteEl ? noteEl.querySelector('.note-content') : null;

            // e. Call ui.switchToEditMode to make it editable and focused
            if (contentDiv) {
                // Set initial rawContent to empty string to ensure proper tracking
                contentDiv.dataset.rawContent = '';
                ui.switchToEditMode(contentDiv);
                
                // Add a one-time input handler to ensure first content is saved
                const initialInputHandler = async (e) => {
                    const currentContent = contentDiv.textContent;
                    if (currentContent !== '') {
                        contentDiv.dataset.rawContent = currentContent;
                        await saveNoteImmediately(noteEl);
                        contentDiv.removeEventListener('input', initialInputHandler);
                    }
                };
                contentDiv.addEventListener('input', initialInputHandler);
            }
        }
    } catch (error) {
        console.error('Error creating root note:', error);
        alert('Failed to save new note. Please try again.');
    }
}, 'addRootNoteBtn');

// Note content editing
const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) return;

    const contentDiv = noteEl.querySelector('.note-content');
    const rawContent = contentDiv.dataset.rawContent || contentDiv.textContent;
    
    const noteData = getNoteDataById(noteId);
    // Only save if content has actually changed, or if it's a new note without existing noteData (though temp- notes are excluded)
    if (noteData && noteData.content === rawContent && !noteId.startsWith('new-')) return; // Added check for new-

    try {
        updateSaveStatusIndicator('pending'); 
        console.log('[DEBUG SAVE] Attempting to save noteId:', noteId);
        console.log('[DEBUG SAVE] Raw content being sent for noteId ' + noteId + ':', JSON.stringify(rawContent));
        
        const updatedNote = await notesAPI.updateNote(noteId, { content: rawContent });
        
        console.log('[DEBUG SAVE] Received updatedNote from server for noteId ' + noteId + '. Content:', JSON.stringify(updatedNote.content));
        const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(updatedNote.id)); 
        if (noteIndex > -1) {
            notesForCurrentPage[noteIndex] = { ...notesForCurrentPage[noteIndex], ...updatedNote }; // Merge, preserve children if any from local
        } else {
            console.warn('[DEBUG SAVE] Note with ID ' + updatedNote.id + ' not found in notesForCurrentPage after update. Adding it.');
            notesForCurrentPage.push(updatedNote);
        }
        window.notesForCurrentPage = notesForCurrentPage;
        
        // contentDiv.dataset.rawContent = updatedNote.content; // Old way
        ui.updateNoteElement(updatedNote.id, updatedNote); // New way to update DOM
        updateSaveStatusIndicator('saved');
        
    } catch (error) {
        console.error('Error updating note (debounced):', error);
        updateSaveStatusIndicator('error'); 
    }
}, 1000);

/**
 * Saves a note immediately without debouncing
 * @param {HTMLElement} noteEl - Note element
 */
async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) {
        console.warn('Attempted to save temporary note immediately. This should be handled by createNote flow.');
        return; // Usually, temp notes are saved via createNote which then updates their ID.
    }

    const contentDiv = noteEl.querySelector('.note-content');
    // Use the new UI helpers to get and normalize content for saving
    const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
    const rawContent = ui.normalizeNewlines(rawTextValue); 
    
    const noteData = getNoteDataById(noteId);
    // Allow save even if content appears same, server might have other reasons or it handles idempotency.
    // if (noteData && noteData.content === rawContent) return; 

    try {
        updateSaveStatusIndicator('pending');
        console.log('[DEBUG IMMEDIATE SAVE] Attempting to save noteId:', noteId);
        console.log('[DEBUG IMMEDIATE SAVE] Raw content being sent for noteId ' + noteId + ':', JSON.stringify(rawContent));

        const updatedNote = await notesAPI.updateNote(noteId, { content: rawContent });
        
        console.log('[DEBUG IMMEDIATE SAVE] Received updatedNote from server for noteId ' + noteId + '. Content:', JSON.stringify(updatedNote.content));
        const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(updatedNote.id));
        if (noteIndex > -1) {
            notesForCurrentPage[noteIndex] = { ...notesForCurrentPage[noteIndex], ...updatedNote }; // Merge
        } else {
            console.warn('[DEBUG IMMEDIATE SAVE] Note with ID ' + updatedNote.id + ' not found in notesForCurrentPage after immediate save. Adding it.');
            notesForCurrentPage.push(updatedNote);
        }
        window.notesForCurrentPage = notesForCurrentPage;
        
        // contentDiv.dataset.rawContent = updatedNote.content; // Old way
        ui.updateNoteElement(updatedNote.id, updatedNote); // New way to update DOM
        updateSaveStatusIndicator('saved');
        
    } catch (error) {
        console.error('Error updating note (immediately):', error);
        updateSaveStatusIndicator('error');
    }
}

safeAddEventListener(notesContainer, 'input', (e) => {
    if (e.target.matches('.note-content.edit-mode')) {
        const noteItem = e.target.closest('.note-item');
        if (noteItem) {
            // Update the raw content data attribute using the new UI helper
            const contentDiv = e.target;
            const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
            contentDiv.dataset.rawContent = ui.normalizeNewlines(rawTextValue);
            debouncedSaveNote(noteItem);
        }
    }
}, 'notesContainer');

// Helper function for handling shortcut expansions
async function handleShortcutExpansion(e, contentDiv) {
    if (e.key !== ' ') return false;

    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return false;

    const range = selection.getRangeAt(0);
    const cursorPos = range.startOffset;
    const textNode = range.startContainer;

    if (!textNode || textNode.nodeType !== Node.TEXT_NODE || cursorPos < 2) return false;

    const textContent = textNode.textContent;
    const precedingText2Chars = textContent.substring(cursorPos - 2, cursorPos);
    let shortcutHandled = false;

    if (precedingText2Chars === ':t') {
        e.preventDefault();
        replaceTextAtCursor(2, '{tag::}', 6);
        shortcutHandled = true;
    } else if (precedingText2Chars === ':d') {
        e.preventDefault();
        const today = new Date().toISOString().slice(0, 10);
        replaceTextAtCursor(2, `{date::${today}}`, 18);
        shortcutHandled = true;
    } else if (precedingText2Chars === ':r') {
        e.preventDefault();
        const now = new Date().toISOString();
        replaceTextAtCursor(2, `{timestamp::${now}}`, 12 + now.length + 1);
        shortcutHandled = true;
    } else if (precedingText2Chars === ':k') {
        e.preventDefault();
        replaceTextAtCursor(2, '{keyword::}', 10);
        shortcutHandled = true;
    }

    if (shortcutHandled) {
        const noteItemForShortcut = contentDiv.closest('.note-item');
        if (noteItemForShortcut) {
            const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
            contentDiv.dataset.rawContent = ui.normalizeNewlines(rawTextValue);
            debouncedSaveNote(noteItemForShortcut);
        }
        return true; // Shortcut was handled
    }
    return false; // Shortcut not handled
}

// Helper function for handling autoclosing brackets
function handleAutocloseBrackets(e, contentDiv) {
    let handled = false;
    if (e.key === '[') {
        e.preventDefault();
        insertTextAtCursor('[]', 1);
        handled = true;
    } else if (e.key === '{') {
        e.preventDefault();
        insertTextAtCursor('{}', 1);
        handled = true;
    } else if (e.key === '(') {
        e.preventDefault();
        insertTextAtCursor('()', 1);
        handled = true;
    }
    return handled;
}

// Helper function for 'Enter' key
async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    // If in rendered mode, switch to edit mode
    if (contentDiv.classList.contains('rendered-mode')) {
        e.preventDefault();
        ui.switchToEditMode(contentDiv);
        return;
    }

    // Handle shift+enter for multi-line notes
    if (e.shiftKey) {
        const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
        contentDiv.dataset.rawContent = ui.normalizeNewlines(rawTextValue);
        debouncedSaveNote(noteItem);
        return; // Let default behavior for newline occur if not prevented
    }
    
    // For regular enter, prevent default and create new note
    e.preventDefault();
    
    if (!noteData) {
        console.error("Cannot create new note with Enter: current noteData is missing.");
        return;
    }

    const newNoteData = {
        page_id: currentPageId,
        content: '',
        parent_note_id: noteData.parent_note_id,
        order_index: noteData.order_index + 1
    };

    try {
        const savedNote = await notesAPI.createNote(newNoteData);
        notesForCurrentPage.splice(notesForCurrentPage.findIndex(n => n.id === noteData.id) + 1, 0, savedNote);
        window.notesForCurrentPage = notesForCurrentPage; 

        let newNoteNestingLevel = 0;
        let parentChildrenContainer = notesContainer; 

        if (savedNote.parent_note_id) {
            const parentNoteEl = getNoteElementById(savedNote.parent_note_id);
            if (parentNoteEl) {
                parentChildrenContainer = parentNoteEl.querySelector('.note-children');
                if (!parentChildrenContainer) { 
                    parentChildrenContainer = document.createElement('div');
                    parentChildrenContainer.className = 'note-children';
                    parentNoteEl.appendChild(parentChildrenContainer);
                    if (typeof Sortable !== 'undefined') {
                        Sortable.create(parentChildrenContainer, { group: 'notes', animation: 150, handle: '.note-bullet', ghostClass: 'note-ghost', chosenClass: 'note-chosen', dragClass: 'note-drag', onEnd: handleNoteDrop });
                    }
                }
                newNoteNestingLevel = ui.getNestingLevel(parentNoteEl) + 1;
            }
        } else {
             const rootNotes = Array.from(notesContainer.children).filter(child => child.classList.contains('note-item'));
             if(rootNotes.length > 0) newNoteNestingLevel = parseInt(rootNotes[0].style.getPropertyValue('--nesting-level') || '0');
        }
        
        let beforeElement = null;
        const siblingsInDom = Array.from(parentChildrenContainer.children)
            .filter(child => child.classList.contains('note-item'))
            .map(childEl => {
                const siblingNote = notesForCurrentPage.find(n => String(n.id) === String(childEl.dataset.noteId));
                return { element: childEl, order_index: siblingNote ? siblingNote.order_index : Infinity };
            })
            .sort((a, b) => a.order_index - b.order_index);

        for (const sibling of siblingsInDom) {
            if (savedNote.order_index <= sibling.order_index) {
                beforeElement = sibling.element;
                break;
            }
        }
        
        const newNoteEl = ui.addNoteElement(savedNote, parentChildrenContainer, newNoteNestingLevel, beforeElement);
        
        const newContentDiv = newNoteEl ? newNoteEl.querySelector('.note-content') : null;
        if (newContentDiv) {
            ui.switchToEditMode(newContentDiv);
        }
    } catch (error) {
        console.error('Error creating sibling note:', error);
    }
}

// Helper function for 'Tab' key
async function handleTabKey(e, noteItem, noteData, contentDiv) {
    e.preventDefault();
    if (!noteData) return;

    const currentContentForTab = contentDiv.dataset.rawContent || contentDiv.textContent;
    if (currentContentForTab !== noteData.content) {
        await saveNoteImmediately(noteItem);
        noteData.content = currentContentForTab;
    }
    
    const originalNoteData = JSON.parse(JSON.stringify(noteData));
    const originalParentDomElement = noteItem.parentElement.closest('.note-item') || notesContainer;
    const originalNextSibling = noteItem.nextElementSibling;
    const originalNestingLevel = ui.getNestingLevel(noteItem);

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const parentNoteData = getNoteDataById(noteData.parent_note_id);
        if (!parentNoteData) return;
        const newParentNoteId = parentNoteData.parent_note_id;
        const newParentDomElement = newParentNoteId ? getNoteElementById(newParentNoteId) : notesContainer;
        if (!newParentDomElement) return;

        const oldParentId = noteData.parent_note_id;
        noteData.parent_note_id = newParentNoteId;
        noteData.order_index = parentNoteData.order_index + 1;
        notesForCurrentPage.filter(n => n.parent_note_id === oldParentId && n.order_index > parentNoteData.order_index)
            .forEach(n => n.order_index--);
        const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(noteData.id));
        if (noteIndex > -1) notesForCurrentPage[noteIndex] = noteData;
        window.notesForCurrentPage = notesForCurrentPage;

        const newNestingLevel = ui.getNestingLevel(newParentDomElement) + (newParentDomElement.id === 'notes-container' ? 0 : 1);
        const parentNoteElement = getNoteElementById(oldParentId);
        ui.moveNoteElement(noteItem, newParentDomElement, newNestingLevel, parentNoteElement ? parentNoteElement.nextElementSibling : null);
        ui.switchToEditMode(contentDiv);

        try {
            await notesAPI.updateNote(noteData.id, { content: noteData.content, parent_note_id: newParentNoteId, order_index: noteData.order_index });
        } catch (error) {
            console.error('Error outdenting note (API):', error);
            alert('Error outdenting note. Reverting changes.');
            notesForCurrentPage[noteIndex] = originalNoteData;
            window.notesForCurrentPage = notesForCurrentPage;
            ui.moveNoteElement(noteItem, originalParentDomElement, originalNestingLevel, originalNextSibling);
            ui.switchToEditMode(contentDiv);
        }
    } else { // Indent
        const siblings = notesForCurrentPage.filter(n => n.parent_note_id === noteData.parent_note_id && n.order_index < noteData.order_index).sort((a, b) => b.order_index - a.order_index);
        if (siblings.length === 0) return;
        const newParentNoteData = siblings[0];
        const newParentDomElement = getNoteElementById(newParentNoteData.id);
        if (!newParentDomElement) return;

        noteData.parent_note_id = newParentNoteData.id;
        noteData.order_index = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(newParentNoteData.id)).length;
        const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(noteData.id));
        if (noteIndex > -1) notesForCurrentPage[noteIndex] = noteData;
        window.notesForCurrentPage = notesForCurrentPage;

        const newNestingLevel = ui.getNestingLevel(newParentDomElement) + 1;
        ui.moveNoteElement(noteItem, newParentDomElement, newNestingLevel);
        ui.switchToEditMode(contentDiv);

        try {
            await notesAPI.updateNote(noteData.id, { content: noteData.content, parent_note_id: noteData.parent_note_id, order_index: noteData.order_index });
        } catch (error) {
            console.error('Error indenting note (API):', error);
            alert('Error indenting note. Reverting changes.');
            notesForCurrentPage[noteIndex] = originalNoteData;
            window.notesForCurrentPage = notesForCurrentPage;
            ui.moveNoteElement(noteItem, originalParentDomElement, originalNestingLevel, originalNextSibling);
            ui.switchToEditMode(contentDiv);
        }
    }
}

// Helper function for 'Backspace' key
async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if (!noteData) return;
    if (contentDiv.classList.contains('edit-mode') && (contentDiv.dataset.rawContent || contentDiv.textContent).trim() === '') {
        const children = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.id));
        if (children.length > 0) {
            console.log('Note has children, not deleting on backspace.');
            return; 
        }
        const isRootNote = !noteData.parent_note_id;
        const rootNotesCount = notesForCurrentPage.filter(n => !n.parent_note_id).length;
        if (isRootNote && rootNotesCount === 1 && notesForCurrentPage.length === 1) {
            console.log('Cannot delete the only note on the page via Backspace.');
            return;
        }
        
        let noteToFocusAfterDelete = null;
        const allNoteElements = Array.from(notesContainer.querySelectorAll('.note-item'));
        const currentNoteIndexInDOM = allNoteElements.findIndex(el => el.dataset.noteId === noteData.id);
        if (currentNoteIndexInDOM > 0) {
            noteToFocusAfterDelete = allNoteElements[currentNoteIndexInDOM - 1];
        } else if (allNoteElements.length > 1) {
            noteToFocusAfterDelete = allNoteElements[currentNoteIndexInDOM + 1];
        } else if (noteData.parent_note_id) {
            noteToFocusAfterDelete = getNoteElementById(noteData.parent_note_id);
        }

        e.preventDefault();
        try {
            await notesAPI.deleteNote(noteData.id);
            notesForCurrentPage = notesForCurrentPage.filter(n => String(n.id) !== String(noteData.id));
            window.notesForCurrentPage = notesForCurrentPage;
            ui.removeNoteElement(noteData.id);
            
            if (noteToFocusAfterDelete) {
                const contentDivToFocus = noteToFocusAfterDelete.querySelector('.note-content');
                if (contentDivToFocus) ui.switchToEditMode(contentDivToFocus);
            } else if (notesForCurrentPage.length === 0 && currentPageId) {
                console.log("All notes deleted. Page is empty.");
            }
        } catch (error) {
            console.error('Error deleting note:', error);
        }
    }
}

// Helper function for Arrow keys
function handleArrowKey(e, contentDiv) {
    e.preventDefault();
    const allVisibleNotesContent = Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden) .note-content'));
    const currentVisibleIndex = allVisibleNotesContent.indexOf(contentDiv); // Use contentDiv directly
    let nextVisibleIndex = -1;

    if (e.key === 'ArrowUp') {
        if (currentVisibleIndex > 0) nextVisibleIndex = currentVisibleIndex - 1;
    } else { // ArrowDown
        if (currentVisibleIndex < allVisibleNotesContent.length - 1) nextVisibleIndex = currentVisibleIndex + 1;
    }

    if (nextVisibleIndex !== -1) {
        const nextNoteContent = allVisibleNotesContent[nextVisibleIndex];
        ui.switchToEditMode(nextNoteContent);
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(nextNoteContent);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
    }
}


// Note keyboard navigation and editing
notesContainer.addEventListener('keydown', async (e) => {
    if (!e.target.matches('.note-content')) return;

    const noteItem = e.target.closest('.note-item');
    const noteId = noteItem.dataset.noteId;
    const contentDiv = e.target; // e.target is the .note-content div
    const noteData = getNoteDataById(noteId); // Relies on notesForCurrentPage being up-to-date

    // Handle shortcuts and auto-close brackets first if in edit mode
    if (contentDiv.classList.contains('edit-mode')) {
        if (await handleShortcutExpansion(e, contentDiv)) return; // If shortcut handled, exit
        if (handleAutocloseBrackets(e, contentDiv)) return; // If autoclosing handled, exit
    }

    // General guards for operations that require noteData or non-temp notes
    if (!noteData || noteId.startsWith('temp-')) {
        if (e.key === 'Enter' && contentDiv.classList.contains('rendered-mode')) {
            // Allow Enter to switch to edit mode on rendered notes even if data is temporarily missing
        } else if (noteId.startsWith('temp-') && ['Enter', 'Tab', 'Backspace'].includes(e.key)) {
            console.warn('Action (' + e.key + ') blocked on temporary note ID: ' + noteId);
            return;
        } else if (!noteData && !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
            // If noteData is missing, only allow navigation.
            console.warn('Note data not found for ID: ' + noteId + '. Key: ' + e.key + '. Blocking non-navigation action.');
            return;
        }
    }
    
    // Main switch statement for key handling
    switch (e.key) {
        case 'Enter':
            await handleEnterKey(e, noteItem, noteData, contentDiv);
            break;
        case 'Tab':
            await handleTabKey(e, noteItem, noteData, contentDiv);
            break;
        case 'Backspace':
            await handleBackspaceKey(e, noteItem, noteData, contentDiv);
            break;
        case 'ArrowUp':
        case 'ArrowDown':
            handleArrowKey(e, contentDiv); // Pass contentDiv (e.target)
            break;
        // Default case: do nothing for other keys, let browser handle
    }
});

// Note interactions (task markers)
notesContainer.addEventListener('click', async (e) => {
    // Task checkbox click
    if (e.target.matches('.task-checkbox')) {
        const checkbox = e.target;
        const noteItem = checkbox.closest('.note-item');
        if (!noteItem) return;
        
        const noteId = noteItem.dataset.noteId;
        const contentDiv = noteItem.querySelector('.note-content');
        const noteData = getNoteDataById(noteId);

        if (!noteData || !contentDiv || noteId.startsWith('temp-')) {
            console.error('Note data, contentDiv not found, or temp note for task checkbox click', { noteId, noteData, contentDiv });
            checkbox.checked = !checkbox.checked;
            return;
        }
        
        let rawContent = contentDiv.dataset.rawContent || contentDiv.textContent;
        let newRawContent, newStatus, doneAt = null;
        const isChecked = checkbox.checked;
        const markerType = checkbox.dataset.markerType;

        // Handle different task statuses
        switch (markerType) {
            case 'TODO':
                if (isChecked) {
                    newRawContent = 'DONE ' + rawContent.substring(5);
                    newStatus = 'DONE';
                    doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
                } else {
                    newRawContent = rawContent;
                    newStatus = 'TODO';
                }
                break;

            case 'DOING':
                if (isChecked) {
                    newRawContent = 'DONE ' + rawContent.substring(6);
                    newStatus = 'DONE';
                    doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
                } else {
                    newRawContent = 'TODO ' + rawContent.substring(6);
                    newStatus = 'TODO';
                }
                break;

            case 'SOMEDAY':
                if (isChecked) {
                    newRawContent = 'DONE ' + rawContent.substring(8);
                    newStatus = 'DONE';
                    doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
                } else {
                    newRawContent = 'TODO ' + rawContent.substring(8);
                    newStatus = 'TODO';
                }
                break;

            case 'DONE':
                if (!isChecked) {
                    newRawContent = 'TODO ' + rawContent.substring(5);
                    newStatus = 'TODO';
                } else {
                    newRawContent = rawContent;
                    newStatus = 'DONE';
                    doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
                }
                break;

            case 'WAITING':
                if (isChecked) {
                    newRawContent = 'DONE ' + rawContent.substring(8);
                    newStatus = 'DONE';
                    doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
                } else {
                    newRawContent = 'TODO ' + rawContent.substring(8);
                    newStatus = 'TODO';
                }
                break;

            case 'CANCELLED':
            case 'NLR':
                // These statuses are not interactive
                checkbox.checked = true;
                return;

            default:
                console.warn("Unknown task marker type:", markerType);
                checkbox.checked = !checkbox.checked;
                return;
        }

        try {
            const updatedNoteServer = await notesAPI.updateNote(noteId, { content: newRawContent });
            noteData.content = updatedNoteServer.content;
            noteData.updated_at = updatedNoteServer.updated_at;
            contentDiv.dataset.rawContent = updatedNoteServer.content;
            
            if (contentDiv.classList.contains('edit-mode')) {
                contentDiv.textContent = updatedNoteServer.content;
            } else {
                contentDiv.innerHTML = ui.parseAndRenderContent(updatedNoteServer.content);
            }

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
                try {
                    await propertiesAPI.deleteProperty('note', parseInt(noteId), 'done_at');
                } catch (delError) {
                    console.warn('Could not delete done_at:', delError);
                }
            }

            const updatedProperties = await propertiesAPI.getProperties('note', parseInt(noteId));
            noteData.properties = updatedProperties;
            updateNoteInCurrentPage(noteData);
            console.log('Task status updated:', { noteId, newStatus, newRawContent, doneAt });
        } catch (error) {
            console.error('Error updating task status:', error);
            alert('Failed to update task status: ' + error.message);
            checkbox.checked = !checkbox.checked;
            contentDiv.dataset.rawContent = noteData.content;
            if (contentDiv.classList.contains('edit-mode')) {
                contentDiv.textContent = noteData.content;
            } else {
                contentDiv.innerHTML = ui.parseAndRenderContent(noteData.content);
            }
        }
    }
});

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
    if (!currentPageId) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const value = properties[oldKey];
        
        if (value === undefined) {
            console.warn(`Property ${oldKey} not found for renaming`);
            return;
        }

        // Delete old property and create new one
        await propertiesAPI.deleteProperty('page', currentPageId, oldKey);
        await propertiesAPI.setProperty({
            entity_type: 'page',
            entity_id: currentPageId,
            name: newKey,
            value: value
        });

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error renaming property key:', error);
        alert('Failed to rename property');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', currentPageId);
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
    if (!currentPageId) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const values = properties[oldKey];
        
        if (!Array.isArray(values)) {
            console.warn(`Property ${oldKey} is not an array for renaming`);
            return;
        }

        // For array properties, we need to move all values to the new key
        await propertiesAPI.deleteProperty('page', currentPageId, oldKey);
        
        // Add all values under the new key
        for (const value of values) {
            await propertiesAPI.setProperty({
                entity_type: 'page',
                entity_id: currentPageId,
                name: newKey,
                value: value
            });
        }

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error renaming array property key:', error);
        alert('Failed to rename property');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', currentPageId);
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
    if (!currentPageId) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const values = properties[key];
        
        if (!Array.isArray(values) || arrayIndex >= values.length) {
            console.warn(`Invalid array property update: ${key}[${arrayIndex}]`);
            return;
        }

        // Delete all values for this key
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        
        // Re-add all values with the updated one
        for (let i = 0; i < values.length; i++) {
            const value = i === arrayIndex ? newValue : values[i];
            await propertiesAPI.setProperty({
                entity_type: 'page',
                entity_id: currentPageId,
                name: key,
                value: value
            });
        }

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error updating array property value:', error);
        alert('Failed to update property value');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', currentPageId);
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
    if (!currentPageId) return;

    try {
        // Get current properties
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const values = properties[key];
        
        if (!Array.isArray(values) || arrayIndex >= values.length) {
            console.warn(`Invalid array property deletion: ${key}[${arrayIndex}]`);
            return;
        }

        // Delete all values for this key
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        
        // Re-add all values except the one being deleted
        const remainingValues = values.filter((_, i) => i !== arrayIndex);
        for (const value of remainingValues) {
            await propertiesAPI.setProperty({
                entity_type: 'page',
                entity_id: currentPageId,
                name: key,
                value: value
            });
        }

        // Refresh display
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        
        // Also update inline properties display
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error deleting array property value:', error);
        alert('Failed to delete property value');
        // Refresh to restore original state
        const properties = await propertiesAPI.getProperties('page', currentPageId);
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

/**
 * Initializes the application
 */
async function initializeApp() {
    const splashScreen = document.getElementById('splash-screen');
    if (splashScreen) {
        splashScreen.classList.remove('hidden'); // Ensure it's visible
    }
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

        // Load page list (this was already here, ensuring it's before prefetch)
        await fetchAndDisplayPages(initialPageName);
            
        // NEW: Start pre-fetching, but don't await it if it should run in background
        prefetchRecentPagesData(); 
        
        // Add delegated click listener for content images (pasted or from Markdown)
        // This listener setup should ideally be idempotent or managed to avoid multiple additions if initializeApp can be called multiple times.
        // For now, assuming initializeApp is called once.
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
        
        // Before hiding splash, ensure save indicator is in a clean initial state
        const initialSaveIndicator = document.getElementById('save-status-indicator');
        if (initialSaveIndicator) {
            initialSaveIndicator.className = 'status-saved status-hidden'; // Base classes
            initialSaveIndicator.innerHTML = '<i data-feather="check-circle"></i>';
            initialSaveIndicator.title = 'All changes saved';
            if (typeof feather !== 'undefined' && feather.replace) {
                feather.replace();
            }
        }

        if (splashScreen) {
            if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
                window.splashAnimations.stop();
            }
            splashScreen.classList.add('hidden');
        }
        console.log('App initialized successfully');
        
        // Initialize splash screen toggle
        const toggleSplashBtn = document.getElementById('toggle-splash-btn');
        let isSplashActive = false;

        function showSplashScreen() {
            splashScreen.classList.remove('hidden');
            isSplashActive = true;
            document.body.style.overflow = 'hidden';
            if (window.splashAnimations && typeof window.splashAnimations.start === 'function') {
                window.splashAnimations.start();
            }
        }

        function hideSplashScreen() {
            splashScreen.classList.add('hidden');
            isSplashActive = false;
            document.body.style.overflow = '';
            if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
                window.splashAnimations.stop();
            }
        }

        // Handle splash screen toggle button click
        safeAddEventListener(toggleSplashBtn, 'click', (e) => {
            e.stopPropagation();
            if (isSplashActive) {
                hideSplashScreen();
            } else {
                showSplashScreen();
            }
        }, 'toggle-splash-btn');

        // Handle click anywhere on splash screen to hide it
        safeAddEventListener(splashScreen, 'click', () => {
            if (isSplashActive) {
                hideSplashScreen();
            }
        }, 'splash-screen');
    } catch (error) {
        console.error('Failed to initialize app:', error);
        if (splashScreen) {
            if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
                window.splashAnimations.stop(); // Also stop on error if splash was visible
            }
            splashScreen.classList.add('hidden');
        }
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
    if (!currentPageId) return;

    try {
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        const properties = await propertiesAPI.getProperties('page', currentPageId);
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
 * Updates the visual save status indicator.
 * @param {string} newStatus - The new status: 'saved', 'pending', or 'error'.
 */
function updateSaveStatusIndicator(newStatus) {
    const indicator = document.getElementById('save-status-indicator');
    if (!indicator) {
        console.warn('Save status indicator element not found.');
        return;
    }

    saveStatus = newStatus; // Update global status tracker

    const splashScreen = document.getElementById('splash-screen');
    const isSplashVisible = splashScreen && !splashScreen.classList.contains('hidden');

    if (isSplashVisible) {
        indicator.classList.add('status-hidden');
        indicator.innerHTML = ''; // Clear content when hidden by splash
        return;
    } else {
        indicator.classList.remove('status-hidden');
    }

    indicator.classList.remove('status-saved', 'status-pending', 'status-error');
    indicator.classList.add(`status-${newStatus}`);

    let iconHtml = '';
    switch (newStatus) {
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
        default: // Fallback, e.g., to saved state
            console.warn(`Unknown save status: ${newStatus}. Defaulting to 'saved'.`);
            saveStatus = 'saved'; // Correct the global status
            indicator.classList.remove('status-pending', 'status-error'); // Clean up other classes
            indicator.classList.add(`status-saved`);
            iconHtml = '<i data-feather="check-circle"></i>';
            indicator.title = 'All changes saved';
            break;
    }
    indicator.innerHTML = iconHtml;

    // Process Feather Icons for 'saved' and 'error' states
    if (newStatus === 'saved' || newStatus === 'error') {
        if (typeof feather !== 'undefined' && feather.replace) {
            feather.replace({
                width: '18px',
                height: '18px',
                'stroke-width': '2' // Ensure consistent stroke width
            });
        } else {
            // Fallback or warning if Feather Icons is not available
            console.warn('Feather Icons library not found. Icons for "saved" or "error" status might not render.');
            if (newStatus === 'saved') indicator.textContent = '✓'; // Simple text fallback
            if (newStatus === 'error') indicator.textContent = '!'; // Simple text fallback
        }
    }
}

/**
 * Gets today's date in YYYY-MM-DD format for journal pages
 * @returns {string} Date string in YYYY-MM-DD format
 */
function getTodaysJournalPageName() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Gets the initial page name to load
 * @returns {string} The name of the initial page (today's journal page by default)
 */
function getInitialPage() {
    return getTodaysJournalPageName();
}