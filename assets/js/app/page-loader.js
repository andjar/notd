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

window.currentPageEncryptionKey = null;
window.decryptionPassword = null;

// Import API clients
import { notesAPI, pagesAPI, searchAPI, queryAPI } from '../api_client.js';

// Import note actions
import { saveNoteImmediately } from './note-actions.js';

// Remove direct destructuring of ui.domRefs
// const { notesContainer, backlinksContainer } = ui.domRefs;
const notesContainer = document.querySelector('#notes-container');
const backlinksContainer = document.querySelector('#backlinks-container');

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
 * Gets the previous day's date in YYYY-MM-DD format
 * @param {string} currentDateStr - Date string in YYYY-MM-DD format
 * @returns {string} Date string for the previous day in YYYY-MM-DD format
 */
export function getPreviousDayPageName(currentDateStr) {
    const [year, month, day] = currentDateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day); // Month is 0-indexed
    date.setDate(date.getDate() - 1);
    const prevYear = date.getFullYear();
    const prevMonth = String(date.getMonth() + 1).padStart(2, '0');
    const prevDay = String(date.getDate()).padStart(2, '0');
    return `${prevYear}-${prevMonth}-${prevDay}`;
}

/**
 * Gets the next day's date in YYYY-MM-DD format
 * @param {string} currentDateStr - Date string in YYYY-MM-DD format
 * @returns {string} Date string for the next day in YYYY-MM-DD format
 */
export function getNextDayPageName(currentDateStr) {
    const [year, month, day] = currentDateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day); // Month is 0-indexed
    date.setDate(date.getDate() + 1);
    const nextYear = date.getFullYear();
    const nextMonth = String(date.getMonth() + 1).padStart(2, '0');
    const nextDay = String(date.getDate()).padStart(2, '0');
    return `${nextYear}-${nextMonth}-${nextDay}`;
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
export async function handleTransclusions(notesToProcess = notesForCurrentPage) {
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
                    // Update placeholders for this blockId
                    placeholders.forEach(placeholder => {
                        if (placeholder.dataset.blockRef === blockId) {
                            placeholder.textContent = 'Block not found';
                            placeholder.classList.add('error');
                        }
                    });
                    // Ensure this blockId is not in notesMap or is handled in the rendering loop
                    delete notesMap[blockId]; 
                }
            } catch (fetchError) {
                console.error(`Error fetching individual note for blockId ${blockId}:`, fetchError);
                // Update placeholders for this blockId
                placeholders.forEach(placeholder => {
                    if (placeholder.dataset.blockRef === blockId) {
                        placeholder.textContent = 'Error loading block';
                        placeholder.classList.add('error');
                    }
                });
                // Ensure this blockId is not in notesMap or is handled in the rendering loop
                delete notesMap[blockId]; 
            }
        }

        placeholders.forEach(placeholder => {
            const blockRef = placeholder.dataset.blockRef;
            if (!blockRef || blockRef.trim() === '') {
                // This case is already handled when blockIdsSet is populated, 
                // but as a safeguard, we skip further processing.
                return;
            }

            // If placeholder already has an error class, it means it was handled in the fetch loop
            if (placeholder.classList.contains('error')) {
                return;
            }
            
            const note = notesMap[blockRef];
            if (note && note.content) {
                window.ui.renderTransclusion(placeholder, note.content, blockRef);
            } else if (note) { // Note exists but content might be empty/missing
                console.warn(`Content is empty or missing for blockRef ${blockRef}. Note data:`, note);
                placeholder.textContent = 'Block content is empty';
                placeholder.classList.add('error');
            } else { 
                // This case should ideally be covered by the error handling within the fetch loop.
                // However, if a blockId made it here without being in notesMap (e.g., due to deletion),
                // it implies it wasn't found or an error occurred.
                console.warn(`Block not found for blockRef ${blockRef} in rendering loop (should have been caught earlier).`);
                placeholder.textContent = 'Block not found'; // Or 'Error loading block' if more appropriate
                placeholder.classList.add('error');
            }
        });
    } catch (error) { // This is a general error for the whole transclusion process
        console.error('Error loading multiple transclusions:', error);
        placeholders.forEach(placeholder => {
            // Only update placeholders that haven't already been marked with an error
            if (placeholder.dataset.blockRef && placeholder.dataset.blockRef.trim() !== '' && !placeholder.classList.contains('error')) {
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

    // New check for array type
    if (!Array.isArray(backlinksData)) {
        console.error('displayBacklinks: backlinksData is not an array. Received:', backlinksData);
        backlinksContainer.innerHTML = '<p>Error loading backlinks or no backlinks found.</p>';
        return;
    }

    // Existing check, now only for length after confirming it's an array
    if (backlinksData.length === 0) {
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

// --- Helper Functions ---

/**
 * Resolves page data from provided data or cache.
 * @param {string} pageName - Name of the page to load.
 * @param {Object} [providedPageData=null] - Optional pre-fetched page data.
 * @returns {Object|null} Page data object if found and valid, otherwise null.
 */
function _resolvePageDataSource(pageName, providedPageData = null) {
    if (providedPageData && providedPageData.name === pageName) {
        console.log(`Using provided page data for: ${pageName}`);
        // Ensure this provided data is cached for subsequent loads
        const cacheEntry = {
            id: providedPageData.id,
            name: providedPageData.name,
            alias: providedPageData.alias,
            notes: providedPageData.notes || [],
            properties: providedPageData.properties || {},
            timestamp: Date.now()
        };
        setPageCache(pageName, cacheEntry);
        console.log(`Provided page data for ${pageName} has been cached/updated in cache.`);
        return cacheEntry; // Return the processed cache entry
    }
    if (hasPageCache(pageName) && (Date.now() - getPageCache(pageName).timestamp < CACHE_MAX_AGE_MS)) {
        const cachedData = getPageCache(pageName);
        console.log(`Using cached data for page: ${pageName}`);
        return cachedData;
    }
    return null;
}

/**
 * Fetches page data (details and notes) from the network.
 * Creates journal pages if they don't exist.
 * Caches the fetched data.
 * @param {string} pageName - Name of the page to fetch.
 * @returns {Promise<Object>} Combined page data object (details and notes).
 * @throws {Error} If page not found/creatable or notes fetching fails.
 */
async function _fetchPageFromNetwork(pageName) {
    if (!pageName || pageName.trim() === '') {
        console.warn('_fetchPageFromNetwork called with empty pageName, defaulting to initial page.');
        pageName = getInitialPage();
    }

    let pageDetails;
    // isNewPage declaration is removed as client-side creation decision is removed.
    // The backend now handles page creation if it doesn't exist,
    // and will return 201 if created, or 200 if existing.
    // If an actual error occurs (not related to page existence), it will be caught.
    try {
        pageDetails = await pagesAPI.getPageByName(pageName);
        // Optional: If pagesAPI.getPageByName starts returning the full response object
        // including status, we could determine if it was newly created:
        // isNewPage = (pageDetails.status === 201); 
        // For now, we assume pageDetails is just the page data.
    } catch (error) {
        // Catch actual errors (network, server errors other than auto-creation path)
        console.error(`Error fetching page data for ${pageName} via getPageByName:`, error);
        throw error; // Re-throw the error to be handled by the caller loadPage
    }

    if (!pageDetails) {
        // This check might become less relevant if getPageByName always returns a page 
        // (created or existing) or throws a critical error that's caught above.
        // However, keeping it as a safeguard for unexpected scenarios.
        throw new Error(`Page "${pageName}" could not be fetched or created by the backend.`);
    }

    let notesArray;
    try {
        notesArray = await notesAPI.getPageData(pageDetails.id, { include_internal: false });
        if (!Array.isArray(notesArray)) {
            console.warn(`Expected an array from notesAPI.getPageData for page ${pageDetails.name}, received:`, notesArray);
            notesArray = [];
        }
    } catch (error) {
        console.error(`Error fetching notes for page ${pageDetails.name} (ID: ${pageDetails.id}):`, error.message);
        notesArray = [];
    }

    const combinedPageData = {
        ...pageDetails, // Includes id, name, alias, properties
        notes: notesArray
        // isNewPage flag is removed from here.
        // The _processAndRenderPage function's isNewPage parameter will default to false
        // or could be adjusted if the API provides creation status.
        // Critical logic like creating the first note relies on notesForCurrentPage.length === 0,
        // which remains effective.
    };

    // Cache the newly fetched data
    if (combinedPageData.id && combinedPageData.name) {
        const cacheEntry = {
            id: combinedPageData.id,
            name: combinedPageData.name,
            alias: combinedPageData.alias,
            notes: combinedPageData.notes,
            properties: combinedPageData.properties || {},
            timestamp: Date.now()
        };
        setPageCache(combinedPageData.name, cacheEntry);
        console.log(`Page data for ${combinedPageData.name} fetched from network and cached.`);
    }
    return combinedPageData;
}

/**
 * Renders the main content of a page (properties, notes, backlinks, dynamic content).
 * @param {Object} pageData - The page data object (must include notes and properties).
 * @param {Object} pageProperties - Extracted page properties.
 * @param {boolean} focusFirstNote - Whether to focus the first note.
 * @param {boolean} [isNewPage=false] - Whether the page was just created.
 */
async function _renderPageContent(pageData, pageProperties, focusFirstNote, isNewPage = false) { // isNewPage param still here, will default to false
    // Update UI titles (already done in _processAndRenderPage for history state)
    // window.ui.updatePageTitle(pageData.name); // Redundant if called in _processAndRenderPage
    // if (window.ui.calendarWidget) window.ui.calendarWidget.setCurrentPage(pageData.name); // Redundant

    if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
        window.ui.renderPageInlineProperties(pageProperties, window.ui.domRefs.pagePropertiesContainer);
    }
    
    // notesForCurrentPage state is already set by _processAndRenderPage
    window.ui.displayNotes(notesForCurrentPage, pageData.id);
    window.ui.updateActivePageLink(pageData.name); // Ensure active link is updated

    const backlinks = await searchAPI.getBacklinks(pageData.name);
    displayBacklinks(backlinks);
    await handleTransclusions(); // Uses notesForCurrentPage from state
    await handleSqlQueries();

    if (focusFirstNote && notesContainer) {
        const firstNoteEl = notesContainer.querySelector('.note-content');
        if (firstNoteEl) window.ui.switchToEditMode(firstNoteEl); // Focus by switching to edit mode
    }
    
    // Handle creation of first note if page is new (and empty) or just empty
    // The `isNewPage` check here was mostly for logging. The core logic `notesForCurrentPage.length === 0`
    // correctly determines if a first note should be created.
    if (notesForCurrentPage.length === 0 && pageData.id) {
        console.log(`[_renderPageContent] Page is empty (isNewPage status from client: ${isNewPage}), creating first note.`);
        await handleCreateAndFocusFirstNote(pageData.id);
    }
}


/**
 * Handles the password prompt and decryption flow for encrypted pages.
 * @param {Object} pageData - The page data object.
 * @param {Object} pageProperties - Extracted page properties.
 * @param {boolean} focusFirstNote - Whether to focus the first note.
 */
async function _promptForDecryptionAndRender(pageData, pageProperties, focusFirstNote) {
    const encryptionPropValue = Array.isArray(pageProperties.encrypt) ? pageProperties.encrypt[0].value : pageProperties.encrypt;
    currentPageEncryptionKey = encryptionPropValue;

    if (notesContainer) notesContainer.innerHTML = '';
    if (window.ui.domRefs.pagePropertiesContainer) window.ui.domRefs.pagePropertiesContainer.innerHTML = '';
    
    const passwordPromptContainer = document.createElement('div');
    passwordPromptContainer.id = 'password-prompt-container';
    passwordPromptContainer.style.padding = '20px';
    passwordPromptContainer.innerHTML = `
        <p>This page is encrypted. Please enter the password to decrypt.</p>
        <input type="password" id="decryption-password-input" placeholder="Password" style="margin-right: 10px; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
        <button id="decrypt-button" style="padding: 8px 12px; background-color: var(--color-accent, #007bff); color: white; border: none; border-radius: 4px; cursor: pointer;">Decrypt</button>
        <p id="decryption-error" style="color: red; margin-top: 10px;"></p>
    `;
    if (notesContainer) {
        notesContainer.appendChild(passwordPromptContainer);
    } else {
        console.error("Notes container not found for password prompt.");
        return; // Cannot proceed
    }

    const decryptButton = document.getElementById('decrypt-button');
    const passwordInput = document.getElementById('decryption-password-input');
    const errorMessageElement = document.getElementById('decryption-error');

    const handleDecrypt = async () => {
        const enteredPassword = passwordInput.value;
        if (!enteredPassword) {
            errorMessageElement.textContent = 'Please enter a password.';
            return;
        }
        try {
            const hashedPassword = sjcl.hash.sha256.hash(enteredPassword);
            const hashedPasswordHex = sjcl.codec.hex.fromBits(hashedPassword);

            if (hashedPasswordHex === currentPageEncryptionKey) {
                decryptionPassword = enteredPassword;
                passwordPromptContainer.remove();
                // Call _renderPageContent to render the (now decryptable) page
                // pageData.isNewPage is no longer set by _fetchPageFromNetwork, will be undefined or false.
                // This is fine as _renderPageContent's isNewPage param defaults to false.
                await _renderPageContent(pageData, pageProperties, focusFirstNote, pageData.isNewPage);
            } else {
                errorMessageElement.textContent = 'Incorrect password.';
                passwordInput.value = '';
            }
        } catch (error) {
            console.error('Decryption error:', error);
            errorMessageElement.textContent = 'An error occurred during decryption.';
        }
    };

    decryptButton.addEventListener('click', handleDecrypt);
    passwordInput.addEventListener('keypress', (event) => {
        if (event.key === 'Enter') handleDecrypt();
    });
}

/**
 * Processes page data and orchestrates rendering, including encryption handling.
 * @param {Object} pageData - The page data object (must include notes and properties).
 * @param {boolean} updateHistory - Whether to update browser history.
 * @param {boolean} focusFirstNote - Whether to focus the first note.
 * @param {boolean} [isNewPage=false] - Whether the page was just created.
 */
async function _processAndRenderPage(pageData, updateHistory, focusFirstNote, isNewPage = false) {
    setCurrentPageName(pageData.name);
    setCurrentPageId(pageData.id);
    setNotesForCurrentPage(pageData.notes || []); // Ensure notes state is set before any rendering
    // The isNewPage parameter for _processAndRenderPage is removed from the call in loadPage,
    // so it will default to false. This is acceptable as its primary use was logging.

    // Breadcrumb generation
    const breadcrumbsContainer = document.querySelector('#breadcrumbs-container');
    if (breadcrumbsContainer) {
        breadcrumbsContainer.innerHTML = ''; // Clear existing breadcrumbs
        const pageName = pageData.name;

        if (pageName && pageName.includes('/')) {
            const parts = pageName.split('/');
            let currentNamespacePath = '';
            for (let i = 0; i < parts.length - 1; i++) {
                const part = parts[i];
                if (currentNamespacePath !== '') {
                    currentNamespacePath += '/';
                }
                currentNamespacePath += part;

                const link = document.createElement('a');
                link.href = `?page=${encodeURIComponent(currentNamespacePath)}`;
                link.textContent = part;
                link.classList.add('namespace-breadcrumb'); // For styling
                // Add click handler to load page, preventing full page reload if not already handled by global listeners
                link.addEventListener('click', function(event) {
                    event.preventDefault(); // Prevent full page reload
                    loadPage(currentNamespacePath); // Call your existing loadPage function
                });
                breadcrumbsContainer.appendChild(link);

                const separator = document.createTextNode(' / ');
                breadcrumbsContainer.appendChild(separator);
            }
            // Add the current page name (non-linked)
            const pageNameText = document.createTextNode(parts[parts.length - 1]);
            breadcrumbsContainer.appendChild(pageNameText);
        } else if (pageName) {
            // For top-level pages, display just the page name as text
            const pageNameText = document.createTextNode(pageName);
            breadcrumbsContainer.appendChild(pageNameText);
        }
    } else {
        console.warn('#breadcrumbs-container not found in the DOM.');
    }

    if (updateHistory) {
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('page', pageData.name);
        history.pushState({ pageName: pageData.name }, '', newUrl.toString());
    }

    window.ui.updatePageTitle(pageData.name);
    if (window.ui.calendarWidget && typeof window.ui.calendarWidget.setCurrentPage === 'function') {
        window.ui.calendarWidget.setCurrentPage(pageData.name);
    }
    
    const pageProperties = pageData.properties || {};

    if (pageProperties && pageProperties.encrypt) {
        await _promptForDecryptionAndRender(pageData, pageProperties, focusFirstNote);
    } else {
        currentPageEncryptionKey = null;
        decryptionPassword = null;
        await _renderPageContent(pageData, pageProperties, focusFirstNote, isNewPage);
        // Display child pages if the current page acts as a namespace
        try {
            // pagesAPI.getPages is designed to always return an array of page objects.
            const pagesArray = await pagesAPI.getPages({ excludeJournal: true, per_page: 500, include_details: false });
            
            // We now expect pagesArray to be a valid array (even if empty).
            // The displayChildPages function already handles an empty array correctly.
            await displayChildPages(pageData.name, pagesArray);

        } catch (error) {
            console.error('Error fetching or processing pages for child page display:', error);
            // Optionally, clear or hide the child pages container in case of error
            const childPagesContainer = document.getElementById('child-pages-container');
            if (childPagesContainer) {
                // Clear it or provide a user-friendly error message
                childPagesContainer.innerHTML = '<p>Error loading pages for this namespace.</p>';
            }
        }
    }
}

/**
 * Displays direct child pages for a given parent page name.
 * @param {string} currentPageName - The name of the current page (potential parent).
 * @param {Array<Object>} pagesArray - Array of page objects. Each object is expected to have a 'name' property.
 */
async function displayChildPages(currentPageName, pagesArray) {
    if (!Array.isArray(pagesArray)) {
        console.error('displayChildPages: pagesArray is not an array. Received:', pagesArray);
        // Ensure container is cleared if it exists and invalid data was passed
        const childPagesContainerOnError = document.getElementById('child-pages-container');
        if (childPagesContainerOnError) {
            childPagesContainerOnError.innerHTML = '';
        }
        return;
    }

    const directChildren = pagesArray.filter(page => {
        if (!page || typeof page.name !== 'string') return false;
        // Check if the page name starts with currentPageName + "/"
        if (page.name.startsWith(currentPageName + '/')) {
            // Get the part of the name after "currentPageName/"
            const childPart = page.name.substring((currentPageName + '/').length);
            // Ensure it's a direct child (no further slashes in childPart)
            return childPart && !childPart.includes('/');
        }
        return false;
    });

    let childPagesContainer = document.getElementById('child-pages-container');

    // If no children, ensure container is empty or removed, then return
    if (directChildren.length === 0) {
        if (childPagesContainer) {
            childPagesContainer.innerHTML = ''; // Clear if exists
            // Optionally remove it: childPagesContainer.remove();
        }
        return;
    }

    // If container doesn't exist, create and append it
    if (!childPagesContainer) {
        childPagesContainer = document.createElement('div');
        childPagesContainer.id = 'child-pages-container';
        // Append it after notes container or similar logical place
        const mainContent = document.getElementById('main-content'); // Assuming main-content div exists
        const notesContainerElement = document.getElementById('notes-container'); // notesContainer is a JS var, use ID
        const addRootNoteBtn = document.getElementById('add-root-note-btn');
        
        let referenceNode = addRootNoteBtn || notesContainerElement; // Prefer to insert after add button or notes

        if (mainContent && referenceNode && referenceNode.parentNode === mainContent) {
             // Insert after the referenceNode
            mainContent.insertBefore(childPagesContainer, referenceNode.nextSibling);
        } else if (mainContent) { // Fallback: append to main-content if specific anchor not found
            mainContent.appendChild(childPagesContainer);
        } else {
            console.warn('Could not find #main-content or suitable anchor to append #child-pages-container.');
            // As a last resort, append to document.body, or handle error appropriately
            document.body.appendChild(childPagesContainer); 
        }
    }

    childPagesContainer.innerHTML = ''; // Clear previous content

    const heading = document.createElement('h3'); // Or h2, h4 as appropriate
    heading.textContent = 'Pages in this namespace:';
    childPagesContainer.appendChild(heading);

    const listElement = document.createElement('ul'); // Using a list for better structure
    listElement.classList.add('child-page-list');
    directChildren.forEach(childPage => {
        const listItem = document.createElement('li');
        const link = document.createElement('a');
        link.href = `?page=${encodeURIComponent(childPage.name)}`;
        // Display only the short name (part after the last '/')
        const shortName = childPage.name.substring(childPage.name.lastIndexOf('/') + 1);
        link.textContent = shortName;
        link.classList.add('child-page-link');
        link.dataset.pageName = childPage.name; // Store full name for handler

        link.addEventListener('click', function(event) {
            event.preventDefault();
            loadPage(childPage.name); // Use the full childPage.name
        });
        listItem.appendChild(link);
        listElement.appendChild(listItem);
    });
    childPagesContainer.appendChild(listElement);
}


/**
 * Loads a page and its notes
 * @param {string} pageNameParam - Name of the page to load
 * @param {boolean} [focusFirstNote=false] - Whether to focus the first note
 * @param {boolean} [updateHistory=true] - Whether to update browser history
 * @param {Object} [providedPageData=null] - Optional pre-fetched page data
 */
export async function loadPage(pageNameParam, focusFirstNote = false, updateHistory = true, providedPageData = null) {
    let pageNameToLoad = pageNameParam || getInitialPage();
    console.log(`Loading page: ${pageNameToLoad}, focusFirstNote: ${focusFirstNote}, updateHistory: ${updateHistory}, providedPageData: ${providedPageData ? providedPageData.name : null}`);

    if (window.blockPageLoad) {
        console.warn('Page load blocked, possibly due to unsaved changes or ongoing operation.');
        return;
    }
    window.blockPageLoad = true;
    
    // Reset global encryption state for the new page
    currentPageEncryptionKey = null;
    decryptionPassword = null;

    try {
        let pageData = _resolvePageDataSource(pageNameToLoad, providedPageData);
        let source = 'cache_or_provided';

        if (!pageData) {
            if (notesContainer) notesContainer.innerHTML = '<p>Loading page...</p>';
            if (window.ui.domRefs.pagePropertiesContainer) window.ui.domRefs.pagePropertiesContainer.innerHTML = '';
            
            pageData = await _fetchPageFromNetwork(pageNameToLoad);
            source = 'network';
            console.log(`Page data for ${pageNameToLoad} fetched from ${source}.`);
        } else {
            console.log(`Page data for ${pageNameToLoad} resolved from ${source}.`);
        }
        
        if (pageData) {
            // pageData.isNewPage is no longer set by _fetchPageFromNetwork.
            // The third argument to _processAndRenderPage (isNewPage) will be undefined here,
            // and will default to false within _processAndRenderPage. This is acceptable.
            await _processAndRenderPage(pageData, updateHistory, focusFirstNote, pageData.isNewPage);
        } else {
            // This case should ideally be caught by errors in _fetchPageFromNetwork
            throw new Error(`Failed to obtain page data for ${pageNameToLoad} from any source.`);
        }

    } catch (error) {
        console.error(`Error loading page ${pageNameToLoad}:`, error);
        setCurrentPageName(`Error: ${pageNameToLoad}`);
        setCurrentPageId(null);
        if (typeof window !== 'undefined' && window.ui) { // Ensure ui is available
           window.ui.updatePageTitle(currentPageName); // Use state's currentPageName
        }
        if (notesContainer) {
            notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
        }
    } finally {
        window.blockPageLoad = false;
    }
}


/**
 * Handles SQL query placeholders and fetches/renders their results.
 */
export async function handleSqlQueries() {
    const placeholders = document.querySelectorAll('.sql-query-placeholder');
    if (placeholders.length === 0) {
        // console.log('No SQL query placeholders found.');
        return;
    }
    console.log(`Found ${placeholders.length} SQL query placeholders.`);

    for (const placeholder of placeholders) {
        const sqlQuery = placeholder.dataset.sqlQuery;
        if (!sqlQuery) {
            placeholder.textContent = 'Error: No SQL query provided in data attribute.';
            placeholder.classList.add('error');
            continue;
        }

        try {
            // Call queryAPI.queryNotes and ensure we get the notes array
            const response = await queryAPI.queryNotes(sqlQuery);
            
            // Ensure we have a valid array of notes
            const notesArray = Array.isArray(response) ? response : 
                             (response && Array.isArray(response.data) ? response.data : []);
            
            placeholder.innerHTML = ''; 
            if (notesArray.length === 0) {
                placeholder.textContent = 'Query returned no results.';
            } else {
                const childrenContainer = document.createElement('div');
                childrenContainer.className = 'note-children sql-query-results';

                notesArray.forEach(noteData => {
                    const parentNoteItem = placeholder.closest('.note-item');
                    let nestingLevel = 0;
                    if (parentNoteItem) {
                        const currentNesting = parseInt(parentNoteItem.style.getPropertyValue('--nesting-level') || '0');
                        nestingLevel = currentNesting + 1;
                    }
                    if (window.ui && typeof window.ui.renderNote === 'function') {
                        const noteElement = window.ui.renderNote(noteData, nestingLevel);
                        childrenContainer.appendChild(noteElement);
                    } else {
                        console.error('window.ui.renderNote is not available to render SQL query results.');
                        placeholder.textContent = 'Error: UI function to render notes is missing.';
                        placeholder.classList.add('error');
                        return; 
                    }
                });
                placeholder.appendChild(childrenContainer);
                if (typeof feather !== 'undefined' && feather.replace) {
                     feather.replace(); 
                }
            }
            placeholder.classList.add('loaded'); 
        } catch (error) {
            console.error('Error fetching SQL query results for query:', sqlQuery, error);
            placeholder.textContent = `Error loading query results: ${error.message}`;
            placeholder.classList.add('error');
            placeholder.classList.add('loaded'); 
        }
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
        console.log(`[NOTE CREATION] Creating first note for page ${pageIdToUse}`);
        const savedNote = await notesAPI.createNote({
            page_id: pageIdToUse, 
            content: '',
            order_index: 0 // Explicitly set order_index to 0 for first note
        });

        if (savedNote) {
            console.log(`[NOTE CREATION] Received from server: id=${savedNote.id}, server_assigned_order_index=${savedNote.order_index}, content="${savedNote.content}"`);
            
            // Clear any existing notes in the container
            if (notesContainer) {
                notesContainer.innerHTML = '';
            }
            
            // Update the global state with just this one note
            setNotesForCurrentPage([savedNote]);
            
            // Render the note
            const noteEl = window.ui.renderNote(savedNote, 0);
            if (notesContainer) {
                notesContainer.appendChild(noteEl);
            }
            
            // Focus the new note
            const contentDiv = noteEl.querySelector('.note-content');
            if (contentDiv) {
                contentDiv.dataset.rawContent = '';
                window.ui.switchToEditMode(contentDiv);
                
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
        
        allPages.sort((a, b) => {
            // Assuming the structure is { page: { updated_at: ... } }
            // If updated_at is directly on the outer object, this needs adjustment
            const dateA = a.page ? new Date(a.page.updated_at) : 0;
            const dateB = b.page ? new Date(b.page.updated_at) : 0;
            return dateB - dateA;
        });

        const recentPagesToPrefetch = allPages.slice(0, MAX_PREFETCH_PAGES); 

        for (const pageWithNotes of recentPagesToPrefetch) { // Renamed 'page' to 'pageWithNotes' for clarity
            const actualPage = pageWithNotes.page; // Extract the actual page object
            if (!actualPage) {
                console.warn('Page object within wrapper is missing during pre-fetch. Skipping.', pageWithNotes);
                continue;
            }

            if (!hasPageCache(actualPage.name) ||
                (Date.now() - (getPageCache(actualPage.name)?.timestamp || 0) > CACHE_MAX_AGE_MS)) {
                
                console.log(`Pre-fetching data for page: ${actualPage.name}`);
                try {
                    if (!actualPage.id || !actualPage.name) {
                        console.warn(`Actual page object for pre-fetch is missing id or name. Skipping.`, actualPage);
                        continue;
                    }
                    
                    // notes are at pageWithNotes.notes, properties are at actualPage.properties (or pageWithNotes.page.properties)
                    const notes = pageWithNotes.notes || []; 
                    const properties = actualPage.properties || {}; 

                    setPageCache(actualPage.name, {
                        id: actualPage.id,
                        name: actualPage.name, 
                        alias: actualPage.alias, 
                        notes: notes,      
                        properties: properties, 
                        timestamp: Date.now()
                    });
                    console.log(`Successfully pre-fetched and cached data for page: ${actualPage.name}`);
                } catch (error) {
                    console.error(`Error pre-fetching data for page ${actualPage.name}:`, error);
                    if (hasPageCache(actualPage.name)) {
                        deletePageCache(actualPage.name);
                    }
                }
            } else {
                console.log(`Page ${actualPage.name} is already in cache and recent.`);
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
    const pageListContainer = window.ui.domRefs.pageListContainer; 
    if (!pageListContainer) {
        console.error("pageListContainer not found in fetchAndDisplayPages");
        return;
    }
    try {
        const pages = await pagesAPI.getPages();
        window.ui.updatePageList(pages, activePageName || currentPageName);
    } catch (error) {
        console.error('Error fetching pages:', error);
        pageListContainer.innerHTML = '<li>Error loading pages.</li>';
    }
}
