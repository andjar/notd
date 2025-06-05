import {
    currentPageId,
    CACHE_MAX_AGE_MS,
    MAX_PREFETCH_PAGES,
    notesForCurrentPage,
    setCurrentPageId,
    setCurrentPageName,
    setNotesForCurrentPage,
    addNoteToCurrentPage,
    hasPageCache,
    getPageCache,
    setPageCache,
    deletePageCache,
    currentPageName
} from './state.js';

// Import UI module
import { ui } from '../ui.js';

// Import API clients
import { notesAPI, pagesAPI, searchAPI } from '../api_client.js';

// Import note actions
import { saveNoteImmediately } from './note-actions.js';

// Assuming ui object is globally available or imported if/when ui.js becomes a module
// For example: import ui from '../ui.js';
// For now, we'll use ui directly as if it's global.
// Destructure necessary domRefs, assuming 'ui' is accessible.
const { notesContainer, backlinksContainer } = ui.domRefs;


// Assuming API objects are globally available or imported if/when api_client.js becomes a module
// For example: import { pagesAPI, notesAPI, searchAPI } from '../api_client.js';
// For now, we'll use them as if they are global (e.g. pagesAPI.getPages).

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
export function getInitialPage() { // <-- EXPORT ADDED
    return getTodaysJournalPageName();
}

/**
 * Handles transclusions in notes
 * @param {Array} notes - Array of notes to process (optional, defaults to current page notes read from state)
 */
async function handleTransclusions(notesToProcess = notesForCurrentPage) {
    const placeholders = document.querySelectorAll('.transclusion-placeholder');
    if (placeholders.length === 0) {
        console.log('No transclusion placeholders found.');
        return;
    }
    console.log(`Found ${placeholders.length} transclusion placeholders.`);

    const blockIdsSet = new Set();
    placeholders.forEach(placeholder => {
        const blockRef = placeholder.dataset.blockRef;
        if (blockRef && blockRef.trim() !== '') { 
            blockIdsSet.add(String(blockRef)); 
        } else {
            console.warn('Placeholder found with invalid or missing data-block-ref', placeholder);
            placeholder.textContent = 'Invalid block reference (missing ref)';
            placeholder.classList.add('error');
        }
    });

    const blockIdsArray = Array.from(blockIdsSet);

    if (blockIdsArray.length === 0) {
        console.log('No valid block IDs to fetch for transclusions.');
        return;
    }

    console.log(`Fetching content for ${blockIdsArray.length} unique block IDs for transclusion.`);

    try {
        const notesMap = {};
        for (const blockId of blockIdsArray) {
            try {
                const note = await notesAPI.getNote(blockId); 
                if (note) {
                    notesMap[blockId] = note;
                } else {
                    console.warn(`Note not found (or null response) for blockId during individual fetch: ${blockId}`);
                }
            } catch (fetchError) {
                console.error(`Error fetching individual note for blockId ${blockId}:`, fetchError);
            }
        }
        
        placeholders.forEach(placeholder => {
            const blockRef = placeholder.dataset.blockRef;
            if (!blockRef || blockRef.trim() === '') {
                return;
            }
            const note = notesMap[blockRef];
            if (note && note.content) {
                ui.renderTransclusion(placeholder, note.content, blockRef);
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
            if (placeholder.dataset.blockRef && placeholder.dataset.blockRef.trim() !== '') {
                placeholder.textContent = 'Error loading block';
                placeholder.classList.add('error');
            }
        });
    }
}

/**
 * Displays backlinks for the current page
 * @param {Array} backlinksData - Array of backlink objects
 */
function displayBacklinks(backlinksData) {
    if (!backlinksContainer) { // backlinksContainer is from ui.domRefs
        console.warn('Backlinks container not found in DOM to display backlinks.');
        return;
    }

    if (!backlinksData || backlinksData.length === 0) {
        backlinksContainer.innerHTML = '<p>No backlinks found.</p>';
        return;
    }

    const html = backlinksData.map(link => `
        <div class="backlink-item">
            <a href="#" class="page-link" data-page-name="${link.source_page_name}">
                ${link.source_page_name}
            </a>
            <div class="backlink-snippet">${link.content_snippet}</div>
        </div>
    `).join('');

    backlinksContainer.innerHTML = html;
}


/**
 * Loads a page and its notes
 * @param {string} pageNameParam - Name of the page to load
 * @param {boolean} [focusFirstNote=false] - Whether to focus the first note
 * @param {boolean} [updateHistory=true] - Whether to update browser history
 */
export async function loadPage(pageNameParam, focusFirstNote = false, updateHistory = true) {
    let pageNameToLoad = pageNameParam;
    console.log(`Loading page: ${pageNameToLoad}, focusFirstNote: ${focusFirstNote}, updateHistory: ${updateHistory}`);
    if (window.blockPageLoad) {
        console.warn('Page load blocked, possibly due to unsaved changes or ongoing operation.');
        return;
    }
    window.blockPageLoad = true;

    if (hasPageCache(pageNameToLoad) && (Date.now() - getPageCache(pageNameToLoad).timestamp < CACHE_MAX_AGE_MS)) {
        const cachedData = getPageCache(pageNameToLoad);
        console.log(`Using cached data for page: ${pageNameToLoad}`);
        
        try {
            setCurrentPageName(cachedData.name);
            setCurrentPageId(cachedData.id);

            if (updateHistory) {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('page', cachedData.name);
                history.pushState({ pageName: cachedData.name }, '', newUrl.toString());
            }

            ui.updatePageTitle(cachedData.name);
            if (ui.calendarWidget && typeof ui.calendarWidget.setCurrentPage === 'function') {
                 ui.calendarWidget.setCurrentPage(cachedData.name);
            }

            const pageDetails = { 
                id: cachedData.id,
                name: cachedData.name,
                alias: cachedData.alias 
            };
            const pageProperties = cachedData.properties;
            setNotesForCurrentPage(cachedData.notes);

            console.log('Page details for (cached)', cachedData.name, ':', pageDetails);
            console.log('Page properties for (cached)', cachedData.name, ':', pageProperties);
            console.log('Notes for (cached)', cachedData.name, ':', notesForCurrentPage.length);
            
            if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
                ui.renderPageInlineProperties(pageProperties, ui.domRefs.pagePropertiesContainer);
            }
            ui.displayNotes(notesForCurrentPage, cachedData.id);
            ui.updateActivePageLink(cachedData.name);

            const backlinks = await searchAPI.getBacklinks(cachedData.name);
            displayBacklinks(backlinks);
            await handleTransclusions(); 

            if (focusFirstNote && notesContainer) { // Ensure notesContainer is available
                const firstNoteEl = notesContainer.querySelector('.note-content');
                if (firstNoteEl) firstNoteEl.focus();
            }
            window.blockPageLoad = false;
            return; 
        } catch (error) {
            console.error('Error loading page from cache, falling back to network:', error);
            deletePageCache(pageNameToLoad);
        }
    }

    try {
        if (!pageNameToLoad || pageNameToLoad.trim() === '') {
            console.warn('loadPage called with empty pageName, defaulting to initial page.');
            pageNameToLoad = getInitialPage();
        }

        if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = '<p>Loading page...</p>';
        if (ui.domRefs.pagePropertiesContainer) ui.domRefs.pagePropertiesContainer.innerHTML = ''; 

        const pageData = await pagesAPI.getPageByName(pageNameToLoad);
        if (!pageData) {
            throw new Error(`Page "${pageNameToLoad}" not found and could not be created.`);
        }

        setCurrentPageName(pageData.name);
        setCurrentPageId(pageData.id);

        if (updateHistory) {
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('page', pageData.name);
            history.pushState({ pageName: pageData.name }, '', newUrl.toString());
        }

        ui.updatePageTitle(pageData.name);
        if (ui.calendarWidget && typeof ui.calendarWidget.setCurrentPage === 'function') {
            ui.calendarWidget.setCurrentPage(pageData.name);
        }

        console.log(`Fetching page data using notesAPI.getPageData for: ${pageData.name} (ID: ${pageData.id})`);
        const pageResponse = await notesAPI.getPageData(pageData.id, { include_internal: false });

        if (!pageResponse || !pageResponse.page || !pageResponse.notes) {
            throw new Error('Invalid response structure from getPageData');
        }

        const pageDetails = pageResponse.page;
        const pageProperties = pageResponse.page.properties || {};
        setNotesForCurrentPage(pageResponse.notes);

        setCurrentPageId(pageDetails.id); 
        setCurrentPageName(pageDetails.name); 
        
        const backlinks = await searchAPI.getBacklinks(pageDetails.name); 
        console.log(`Fetched backlinks for page: ${pageDetails.name}`);

        if (pageDetails.id && pageDetails.name) { 
            const cacheEntry = {
                id: pageDetails.id,
                name: pageDetails.name, 
                alias: pageDetails.alias, 
                notes: pageResponse.notes,    
                properties: pageProperties,    
                timestamp: Date.now()
            };
            setPageCache(pageDetails.name, cacheEntry);
            console.log(`Page data for ${pageDetails.name} fetched from network and cached.`);
        }

        console.log('Page properties for ', pageDetails.name, ':', pageProperties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(pageProperties, ui.domRefs.pagePropertiesContainer);
        }

        ui.displayNotes(notesForCurrentPage, pageDetails.id); 
        ui.updateActivePageLink(pageDetails.name);
        displayBacklinks(backlinks);
        await handleTransclusions(); 

        if (focusFirstNote && notesContainer) { // Ensure notesContainer
            const firstNoteEl = notesContainer.querySelector('.note-content');
            if (firstNoteEl) firstNoteEl.focus();
        }
    } catch (error) {
        console.error('Error loading page:', error);
        setCurrentPageName(`Error: ${pageNameToLoad}`);
        ui.updatePageTitle(currentPageName); 
        if (notesContainer) { 
            notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
        }
    } finally {
        window.blockPageLoad = false; 
    }

    // Use currentPageId from state for the condition
    if (notesForCurrentPage.length === 0 && currentPageId) { 
        await handleCreateAndFocusFirstNote(currentPageId); 
    }
}

/**
 * Creates the very first note on an empty page and focuses it.
 */
async function handleCreateAndFocusFirstNote(pageIdToUse) { 
    if (!pageIdToUse) {
        console.warn("Cannot create first note without a pageIdToUse.");
        return;
    }
    try {
        const savedNote = await notesAPI.createNote({
            page_id: pageIdToUse, 
            content: ' ', 
            parent_note_id: null
        });

        if (savedNote) {
            addNoteToCurrentPage(savedNote);

            if (notesContainer) { // Ensure notesContainer
                if(notesContainer.innerHTML.includes("empty-page-hint") || notesContainer.children.length === 0) {
                    notesContainer.innerHTML = ''; 
                }
                const noteEl = ui.renderNote(savedNote, 0); // Assuming ui.renderNote exists
                notesContainer.appendChild(noteEl);
                
                const contentDiv = noteEl.querySelector('.note-content');
                if (contentDiv) {
                    contentDiv.dataset.rawContent = savedNote.content;
                    contentDiv.textContent = '';
                    ui.switchToEditMode(contentDiv);
                    
                    const initialInputHandler = async (e) => {
                        const currentContent = contentDiv.textContent.trim();
                        if (currentContent !== '') {
                            contentDiv.dataset.rawContent = currentContent;
                            await saveNoteImmediately(noteEl); // saveNoteImmediately needs to be imported
                            contentDiv.removeEventListener('input', initialInputHandler);
                        }
                    };
                    contentDiv.addEventListener('input', initialInputHandler);
                }
            }
            if (typeof feather !== 'undefined' && feather.replace) {
                feather.replace();
            }
        }
    } catch (error) {
        console.error('Error creating the first note for the page:', error);
        if (notesContainer) { // Ensure notesContainer
            notesContainer.innerHTML = '<p>Error creating the first note. Please try reloading.</p>';
        }
    }
}


/**
 * Pre-fetches data for recently updated pages to improve perceived performance.
 * Caches page details, notes, and properties.
 */
export async function prefetchRecentPagesData() {
    console.log("Starting pre-fetch for recent pages.");
    try {
        const allPages = await pagesAPI.getPages({
            include_details: true, 
            include_internal: false, 
            followAliases: true,     
            excludeJournal: false    
        });
        
        allPages.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));

        const recentPagesToPrefetch = allPages.slice(0, MAX_PREFETCH_PAGES); 

        for (const page of recentPagesToPrefetch) {
            if (!hasPageCache(page.name) ||
                (Date.now() - (getPageCache(page.name)?.timestamp || 0) > CACHE_MAX_AGE_MS)) {
                
                console.log(`Pre-fetching data for page: ${page.name}`);
                try {
                    if (!page.id || !page.name) {
                        console.warn(`Page object for pre-fetch is missing id or name. Skipping.`, page);
                        continue;
                    }
                    
                    const notes = page.notes || []; 
                    const properties = page.properties || {}; 

                    setPageCache(page.name, {
                        id: page.id,
                        name: page.name, 
                        alias: page.alias, 
                        notes: notes,      
                        properties: properties, 
                        timestamp: Date.now()
                    });
                    console.log(`Successfully pre-fetched and cached data for page: ${page.name}`);
                } catch (error) {
                    console.error(`Error pre-fetching data for page ${page.name}:`, error);
                    if (hasPageCache(page.name)) {
                        deletePageCache(page.name);
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

/**
 * Fetches and displays the page list
 * @param {string} [activePageName] - Name of the page to mark as active
 */
export async function fetchAndDisplayPages(activePageName) {
    // Ensure ui.domRefs.pageListContainer is available
    const pageListContainer = ui.domRefs.pageListContainer; 
    if (!pageListContainer) {
        console.error("pageListContainer not found in fetchAndDisplayPages");
        return;
    }
    try {
        const pages = await pagesAPI.getPages(); // Assumes pagesAPI is global
        ui.updatePageList(pages, activePageName || currentPageName); // Assumes ui.updatePageList is global
    } catch (error) {
        console.error('Error fetching pages:', error);
        pageListContainer.innerHTML = '<li>Error loading pages.</li>';
    }
}
