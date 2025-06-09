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


/**
 * Loads a page and its notes
 * @param {string} pageNameParam - Name of the page to load
 * @param {boolean} [focusFirstNote=false] - Whether to focus the first note
 * @param {boolean} [updateHistory=true] - Whether to update browser history
 * @param {Object} [providedPageData=null] - Optional pre-fetched page data
 */
export async function loadPage(pageNameParam, focusFirstNote = false, updateHistory = true, providedPageData = null) {
    currentPageEncryptionKey = null;
    decryptionPassword = null;
    let pageNameToLoad = pageNameParam;
    console.log(`Loading page: ${pageNameToLoad}, focusFirstNote: ${focusFirstNote}, updateHistory: ${updateHistory}, providedPageData: ${providedPageData ? providedPageData.name : null}`);
    if (window.blockPageLoad) {
        console.warn('Page load blocked, possibly due to unsaved changes or ongoing operation.');
        return;
    }
    window.blockPageLoad = true;

    let pageDataFromSource = null; // Will hold page data from provided, cache, or API

    // 1. Check for providedPageData
    if (providedPageData && providedPageData.name === pageNameToLoad) {
        console.log(`Using provided page data for: ${pageNameToLoad}`);
        pageDataFromSource = providedPageData;
        // Ensure this provided data is cached for subsequent loads
        // Notes and properties should be part of providedPageData if it's complete
        const cacheEntry = {
            id: providedPageData.id,
            name: providedPageData.name,
            alias: providedPageData.alias,
            notes: providedPageData.notes || [], // Ensure notes array exists
            properties: providedPageData.properties || {}, // Ensure properties object exists
            timestamp: Date.now() // Update timestamp as it's being "loaded" now
        };
        setPageCache(pageNameToLoad, cacheEntry);
        console.log(`Provided page data for ${pageNameToLoad} has been cached/updated in cache.`);
    } 
    // 2. Check cache if not using providedPageData
    else if (hasPageCache(pageNameToLoad) && (Date.now() - getPageCache(pageNameToLoad).timestamp < CACHE_MAX_AGE_MS)) {
        const cachedData = getPageCache(pageNameToLoad);
        console.log(`Using cached data for page: ${pageNameToLoad}`);
        pageDataFromSource = cachedData;
    }

    // If we have data from provided source or cache
    if (pageDataFromSource) {
        try {
            setCurrentPageName(pageDataFromSource.name);
            setCurrentPageId(pageDataFromSource.id);

            if (updateHistory) {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('page', pageDataFromSource.name);
                history.pushState({ pageName: pageDataFromSource.name }, '', newUrl.toString());
            }

            window.ui.updatePageTitle(pageDataFromSource.name);
            if (window.ui.calendarWidget && typeof window.ui.calendarWidget.setCurrentPage === 'function') {
                window.ui.calendarWidget.setCurrentPage(pageDataFromSource.name);
            }

            // pageDetails are directly from pageDataFromSource
            const pageProperties = pageDataFromSource.properties || {};
            setNotesForCurrentPage(pageDataFromSource.notes || []);

            if (pageProperties && pageProperties.encrypt) {
                const encryptionPropValue = Array.isArray(pageProperties.encrypt) ? pageProperties.encrypt[0].value : pageProperties.encrypt;
                currentPageEncryptionKey = encryptionPropValue;

                // Clear existing notes and show password prompt
                if (notesContainer) notesContainer.innerHTML = ''; // Clear "Loading page..." or old notes
                if (window.ui.domRefs.pagePropertiesContainer) window.ui.domRefs.pagePropertiesContainer.innerHTML = ''; // Clear properties display too
                
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
                    // Potentially append to main content or body as a fallback, though notesContainer is expected.
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
                        // Hash the entered password
                        const hashedPassword = sjcl.hash.sha256.hash(enteredPassword);
                        const hashedPasswordHex = sjcl.codec.hex.fromBits(hashedPassword);

                        if (hashedPasswordHex === currentPageEncryptionKey) {
                            decryptionPassword = enteredPassword; // Store for note decryption
                            
                            // Remove password prompt
                            passwordPromptContainer.remove();
                            
                            // Now display the page properties and notes (which will be decrypted by note-renderer)
                            // This logic is similar to the non-encrypted path, but uses the already fetched data.
                            // We need to ensure that the original notes (potentially encrypted) are passed to displayNotes.

                            // Re-render page properties (if they were cleared)
                            if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
                                window.ui.renderPageInlineProperties(pageProperties, window.ui.domRefs.pagePropertiesContainer);
                            }
                            
                            // Display notes (these are the original notes, decryption happens in note-renderer)
                            // The notes are already in `notesForCurrentPage` (set by `setNotesForCurrentPage` from pageDataFromSource.notes)
                            let notesToDisplay = notesForCurrentPage;
                            window.ui.displayNotes(notesToDisplay, currentPageId); // currentPageId is set

                            // Handle transclusions and SQL queries as usual after notes are displayed
                            await handleTransclusions(notesToDisplay); 
                            await handleSqlQueries();

                            // Restore focus if needed
                            if (focusFirstNote && notesContainer) {
                                const firstNoteEl = notesContainer.querySelector('.note-content');
                                if (firstNoteEl) firstNoteEl.focus();
                            }

                        } else {
                            errorMessageElement.textContent = 'Incorrect password.';
                            passwordInput.value = ''; // Clear the input
                        }
                    } catch (error) {
                        console.error('Decryption error:', error);
                        errorMessageElement.textContent = 'An error occurred during decryption.';
                    }
                };

                decryptButton.addEventListener('click', handleDecrypt);
                passwordInput.addEventListener('keypress', (event) => {
                    if (event.key === 'Enter') {
                        handleDecrypt();
                    }
                });
                
                // Important: Stop further execution of normal page rendering for encrypted pages until decrypted
                window.blockPageLoad = false; // Release the block we set at the start
                return; // Exit loadPage early, decryption handler will continue
            } else {
                // This is the non-encrypted path, ensure keys are null
                currentPageEncryptionKey = null;
                decryptionPassword = null;
            }

            console.log(`Page details for (${pageDataFromSource === providedPageData ? 'provided' : 'cached'})`, pageDataFromSource.name, ':', pageDataFromSource);
            console.log(`Page properties for (${pageDataFromSource === providedPageData ? 'provided' : 'cached'})`, pageDataFromSource.name, ':', pageProperties);
            console.log(`Notes for (${pageDataFromSource === providedPageData ? 'provided' : 'cached'})`, pageDataFromSource.name, ':', notesForCurrentPage.length);
            
            if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
                window.ui.renderPageInlineProperties(pageProperties, window.ui.domRefs.pagePropertiesContainer);
            }
            window.ui.displayNotes(notesForCurrentPage, pageDataFromSource.id);
            window.ui.updateActivePageLink(pageDataFromSource.name);

            const backlinks = await searchAPI.getBacklinks(pageDataFromSource.name);
            displayBacklinks(backlinks);
            await handleTransclusions();
            await handleSqlQueries();

            if (focusFirstNote && notesContainer) {
                const firstNoteEl = notesContainer.querySelector('.note-content');
                if (firstNoteEl) firstNoteEl.focus();
            }
            window.blockPageLoad = false;
            return;
        } catch (error) {
            console.error('Error loading page from provided data or cache, falling back to network if appropriate:', error);
            // If the error was with providedPageData, we might not want to delete cache yet,
            // but if it was a cache integrity issue, then deleting is good.
            // For now, let's assume if pageDataFromSource was set and failed, it's safer to try fetching.
            // If providedPageData was the source, pageNameToLoad might still be in cache correctly.
            if (pageDataFromSource !== providedPageData) { // Only delete cache if it was the source of the error
                 deletePageCache(pageNameToLoad);
            }
            pageDataFromSource = null; // Ensure we fetch from network
        }
    }

    // 3. Fetch from network if not loaded from providedPageData or cache
    // This block executes if pageDataFromSource is still null
    if (!pageDataFromSource) {
        try {
            if (!pageNameToLoad || pageNameToLoad.trim() === '') {
                console.warn('loadPage called with empty pageName, defaulting to initial page.');
                pageNameToLoad = getInitialPage();
            }

            if (notesContainer) notesContainer.innerHTML = '<p>Loading page...</p>';
            if (window.ui.domRefs.pagePropertiesContainer) window.ui.domRefs.pagePropertiesContainer.innerHTML = '';

            let fetchedPageData;
            try {
                fetchedPageData = await pagesAPI.getPageByName(pageNameToLoad);
            } catch (error) {
                // If page not found and it's a journal page (matches YYYY-MM-DD format), try to create it
                if (error.message === 'Page not found' && /^\d{4}-\d{2}-\d{2}$/.test(pageNameToLoad)) {
                    console.log(`Journal page ${pageNameToLoad} not found, attempting to create it...`);
                    try {
                        fetchedPageData = await pagesAPI.createPage(pageNameToLoad);
                        console.log(`Successfully created journal page ${pageNameToLoad}`);
                    } catch (createError) {
                        console.error(`Failed to create journal page ${pageNameToLoad}:`, createError);
                        throw new Error(`Failed to create journal page: ${createError.message}`);
                    }
                } else {
                    throw error; // Re-throw if not a journal page or other error
                }
            }

            if (!fetchedPageData) {
                throw new Error(`Page "${pageNameToLoad}" not found and could not be created.`);
            }
            pageDataFromSource = fetchedPageData; // Assign to pageDataFromSource for consistency

            setCurrentPageName(pageDataFromSource.name);
            setCurrentPageId(pageDataFromSource.id);

            if (updateHistory) {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('page', pageDataFromSource.name);
                history.pushState({ pageName: pageDataFromSource.name }, '', newUrl.toString());
            }

            window.ui.updatePageTitle(pageDataFromSource.name);
            if (window.ui.calendarWidget && typeof window.ui.calendarWidget.setCurrentPage === 'function') {
                window.ui.calendarWidget.setCurrentPage(pageDataFromSource.name);
            }

            console.log(`Fetching full page data (including notes) for page: ${pageDataFromSource.name} (ID: ${pageDataFromSource.id})`);
            // notesAPI.getPageData is expected to return the 'data' part of the API response.
            // For notes.php?page_id=X, the backend's 'data' field (which apiRequest returns) 
            // should be an object like { page: {...}, notes: [...] } or just the notes array
            // depending on the exact backend implementation of notes.php for this GET request.
            // Given the previous working version and the structure of notesAPI.getPageData in api_client.js,
            // it's most likely that notesAPI.getPageData already returns the notes array directly
            // if the backend's 'data' field was just the array.
            // Let's re-verify based on original working code:
            // Original: notesArrayFromAPI = await notesAPI.getPageData(pageData.id, { include_internal: false });
            // This implies notesAPI.getPageData returns the array directly.

            let notesArrayFromAPI;
            try {
                // Assuming notesAPI.getPageData directly returns the array of notes
                // as suggested by the original working code and the comment in api_client.js
                // for notesAPI.getPageData when it was just notes.php?page_id=X
                // Let's stick to the original successful pattern for fetching notes:
                notesArrayFromAPI = await notesAPI.getPageData(pageDataFromSource.id, { include_internal: false });
                
                if (!Array.isArray(notesArrayFromAPI)) {
                    console.warn(`Expected an array from notesAPI.getPageData for page ${pageDataFromSource.name}, but received:`, notesArrayFromAPI, '. Treating as empty notes list.');
                    notesArrayFromAPI = [];
                }
            } catch (error) {
                console.error(`Error fetching notes for page ${pageDataFromSource.name} (ID: ${pageDataFromSource.id}):`, error.message);
                notesArrayFromAPI = []; // Default to empty notes on error
            }
            pageDataFromSource.notes = notesArrayFromAPI; // Attach notes to pageDataFromSource

            const pageProperties = pageDataFromSource.properties || {};
            setNotesForCurrentPage(notesArrayFromAPI);

            if (pageProperties && pageProperties.encrypt) {
                const encryptionPropValue = Array.isArray(pageProperties.encrypt) ? pageProperties.encrypt[0].value : pageProperties.encrypt;
                currentPageEncryptionKey = encryptionPropValue;

            // Clear existing notes and show password prompt
            if (notesContainer) notesContainer.innerHTML = ''; // Clear "Loading page..." or old notes
            if (window.ui.domRefs.pagePropertiesContainer) window.ui.domRefs.pagePropertiesContainer.innerHTML = ''; // Clear properties display too
            
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
                // Potentially append to main content or body as a fallback, though notesContainer is expected.
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
                    // Hash the entered password
                    const hashedPassword = sjcl.hash.sha256.hash(enteredPassword);
                    const hashedPasswordHex = sjcl.codec.hex.fromBits(hashedPassword);

                    if (hashedPasswordHex === currentPageEncryptionKey) {
                        decryptionPassword = enteredPassword; // Store for note decryption
                        
                        // Remove password prompt
                        passwordPromptContainer.remove();
                        
                        // Now display the page properties and notes (which will be decrypted by note-renderer)
                        // This logic is similar to the non-encrypted path, but uses the already fetched data.
                        // We need to ensure that the original notes (potentially encrypted) are passed to displayNotes.

                        // Re-render page properties (if they were cleared)
                        if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
                            window.ui.renderPageInlineProperties(pageProperties, window.ui.domRefs.pagePropertiesContainer);
                        }
                        
                        // Display notes (these are the original notes, decryption happens in note-renderer)
                        // The notes are already in `notesForCurrentPage` (set by `setNotesForCurrentPage` from pageDataFromSource.notes)
                        let notesToDisplay = notesForCurrentPage;
                        window.ui.displayNotes(notesToDisplay, currentPageId); // currentPageId is set

                        // Handle transclusions and SQL queries as usual after notes are displayed
                        await handleTransclusions(notesToDisplay); 
                        await handleSqlQueries();

                        // Restore focus if needed
                        if (focusFirstNote && notesContainer) {
                            const firstNoteEl = notesContainer.querySelector('.note-content');
                            if (firstNoteEl) firstNoteEl.focus();
                        }

                    } else {
                        errorMessageElement.textContent = 'Incorrect password.';
                        passwordInput.value = ''; // Clear the input
                    }
                } catch (error) {
                    console.error('Decryption error:', error);
                    errorMessageElement.textContent = 'An error occurred during decryption.';
                }
            };

            decryptButton.addEventListener('click', handleDecrypt);
            passwordInput.addEventListener('keypress', (event) => {
                if (event.key === 'Enter') {
                    handleDecrypt();
                }
            });
            
            // Important: Stop further execution of normal page rendering for encrypted pages until decrypted
            window.blockPageLoad = false; // Release the block we set at the start
            return; // Exit loadPage early, decryption handler will continue
        } else {
                // This is the non-encrypted path, ensure keys are null
                currentPageEncryptionKey = null;
                decryptionPassword = null;
            }

            // Cache the newly fetched pageDataFromSource (which now includes notes)
            if (pageDataFromSource.id && pageDataFromSource.name) {
                const cacheEntry = {
                    id: pageDataFromSource.id,
                    name: pageDataFromSource.name,
                    alias: pageDataFromSource.alias,
                    notes: pageDataFromSource.notes, // notes are now part of pageDataFromSource
                    properties: pageDataFromSource.properties || {},
                    timestamp: Date.now()
                };
                setPageCache(pageDataFromSource.name, cacheEntry);
                console.log(`Page data for ${pageDataFromSource.name} fetched from network and cached.`);
            }
            
            console.log('Page properties for (network)', pageDataFromSource.name, ':', pageDataFromSource.properties);
            if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
                window.ui.renderPageInlineProperties(pageDataFromSource.properties || {}, window.ui.domRefs.pagePropertiesContainer);
            }
            
            window.ui.displayNotes(notesForCurrentPage, pageDataFromSource.id);
            window.ui.updateActivePageLink(pageDataFromSource.name);
            
            const backlinks = await searchAPI.getBacklinks(pageDataFromSource.name);
            displayBacklinks(backlinks);
            await handleTransclusions();
            await handleSqlQueries();

            if (focusFirstNote && notesContainer) {
                const firstNoteEl = notesContainer.querySelector('.note-content');
                if (firstNoteEl) firstNoteEl.focus();
            }

        } catch (error) {
            console.error('Error loading page from network:', error);
            setCurrentPageName(`Error: ${pageNameToLoad}`);
            setCurrentPageId(null);
            window.ui.updatePageTitle(currentPageName);
            if (notesContainer) {
                notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
            }
        }
    } // End of network fetch block

    // Common finalization logic (runs if page was loaded from any source or if an error occurred in network fetch)
    window.blockPageLoad = false;

    // This check should only run if pageDataFromSource was successfully populated and not an error page
    if (pageDataFromSource && pageDataFromSource.id && notesForCurrentPage.length === 0 && currentPageId) {
        console.log('[PAGE LOAD] No notes found, creating first note');
        await handleCreateAndFocusFirstNote(currentPageId);
        return; // Exit after creating first note to prevent further processing
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
