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
import { notesAPI, pagesAPI, searchAPI } from '../api_client.js';

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
    currentPageEncryptionKey = null;
    decryptionPassword = null;
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

            window.ui.updatePageTitle(cachedData.name);
            if (window.ui.calendarWidget && typeof window.ui.calendarWidget.setCurrentPage === 'function') {
                window.ui.calendarWidget.setCurrentPage(cachedData.name);
            }

            const pageDetails = { 
                id: cachedData.id,
                name: cachedData.name,
                alias: cachedData.alias 
            };
            const pageProperties = cachedData.properties;
            setNotesForCurrentPage(cachedData.notes);

            if (pageProperties && pageProperties.encrypt) {
                // Ensure it's a string and not an array/object if properties can have multiple values
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
                            // The notes are already in `notesForCurrentPage` (set by `setNotesForCurrentPage`)
                            // or `cachedData.notes` if using cache.
                            let notesToDisplay = notesForCurrentPage; // Default to notes from state
                            if (hasPageCache(pageNameToLoad) && getPageCache(pageNameToLoad).notes) { // Check if we were in cache path
                                notesToDisplay = getPageCache(pageNameToLoad).notes;
                            }
                            
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

            console.log('Page details for (cached)', cachedData.name, ':', pageDetails);
            console.log('Page properties for (cached)', cachedData.name, ':', pageProperties);
            console.log('Notes for (cached)', cachedData.name, ':', notesForCurrentPage.length);
            
            if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
                window.ui.renderPageInlineProperties(pageProperties, window.ui.domRefs.pagePropertiesContainer);
            }
            window.ui.displayNotes(notesForCurrentPage, cachedData.id);
            window.ui.updateActivePageLink(cachedData.name);

            const backlinks = await searchAPI.getBacklinks(cachedData.name);
            displayBacklinks(backlinks);
            await handleTransclusions(); 
            await handleSqlQueries(); // Call the new SQL query handler

            if (focusFirstNote && notesContainer) {
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

        if (notesContainer) notesContainer.innerHTML = '<p>Loading page...</p>';
        if (window.ui.domRefs.pagePropertiesContainer) window.ui.domRefs.pagePropertiesContainer.innerHTML = ''; 

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

        window.ui.updatePageTitle(pageData.name);
        if (window.ui.calendarWidget && typeof window.ui.calendarWidget.setCurrentPage === 'function') {
            window.ui.calendarWidget.setCurrentPage(pageData.name);
        }

        console.log(`Fetching page data using notesAPI.getPageData for: ${pageData.name} (ID: ${pageData.id})`);
        
        let notesArrayFromAPI; // Renamed for clarity
        try {
            // notesAPI.getPageData returns the array of notes directly due to how apiRequest is structured
            notesArrayFromAPI = await notesAPI.getPageData(pageData.id, { include_internal: false });
            console.log('Notes array received from notesAPI.getPageData:', notesArrayFromAPI);

            // Ensure notesArrayFromAPI is actually an array.
            // If apiRequest threw an error that was caught and returned something else, or if API legitimately returned non-array
            if (!Array.isArray(notesArrayFromAPI)) {
                console.warn(`Expected an array from notesAPI.getPageData for page ${pageData.name}, but received:`, notesArrayFromAPI, '. Treating as empty notes list.');
                notesArrayFromAPI = []; // Default to empty array in case of unexpected non-array response
            }
        } catch (error) {
            // This catch block handles errors from the notesAPI.getPageData call itself (e.g., network error, 500, or 404 that wasn't caught inside apiRequest)
            console.error(`Error fetching notes for page ${pageData.name} (ID: ${pageData.id}):`, error.message);
             // If page_id was valid but no notes exist, backend returns {success:true, data:[]}, so notesArrayFromAPI would be [].
             // A 404 here might mean pageData.id itself is problematic or API endpoint for notes has an issue for this page_id.
            notesArrayFromAPI = []; // Default to empty notes on error
            // Optionally, you could display a more specific error to the user here or re-throw
        }

        const pageDetails = pageData; // We already have page details
        const pageProperties = pageData.properties || {};
        setNotesForCurrentPage(notesArrayFromAPI); // Use the fetched notes array

        if (pageProperties && pageProperties.encrypt) {
            // Ensure it's a string and not an array/object if properties can have multiple values
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
                        // The notes are already in `notesForCurrentPage` (set by `setNotesForCurrentPage`)
                        // or `cachedData.notes` if using cache.
                        let notesToDisplay = notesForCurrentPage; // Default to notes from state
                        // No need to check cache here as this is the network path
                        
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

        setCurrentPageId(pageDetails.id); 
        setCurrentPageName(pageDetails.name); 
        
        const backlinks = await searchAPI.getBacklinks(pageDetails.name); 
        console.log(`Fetched backlinks for page: ${pageDetails.name}`);

        if (pageDetails.id && pageDetails.name) { 
            const cacheEntry = {
                id: pageDetails.id,
                name: pageDetails.name, 
                alias: pageDetails.alias, 
                notes: notesArrayFromAPI,    
                properties: pageProperties,    
                timestamp: Date.now()
            };
            setPageCache(pageDetails.name, cacheEntry);
            console.log(`Page data for ${pageDetails.name} fetched from network and cached.`);
        }

        console.log('Page properties for ', pageDetails.name, ':', pageProperties);
        if (window.ui.domRefs.pagePropertiesContainer && typeof window.ui.renderPageInlineProperties === 'function') {
            window.ui.renderPageInlineProperties(pageProperties, window.ui.domRefs.pagePropertiesContainer);
        }

        window.ui.displayNotes(notesForCurrentPage, pageDetails.id); 
        window.ui.updateActivePageLink(pageDetails.name);
        displayBacklinks(backlinks);
        await handleTransclusions(); 
        await handleSqlQueries(); // Call the new SQL query handler

        if (focusFirstNote && notesContainer) {
            const firstNoteEl = notesContainer.querySelector('.note-content');
            if (firstNoteEl) firstNoteEl.focus();
        }
    } catch (error) {
        console.error('Error loading page:', error);
        setCurrentPageName(`Error: ${pageNameToLoad}`); // Existing line
        setCurrentPageId(null); // ADD THIS LINE
        window.ui.updatePageTitle(currentPageName); 
        if (notesContainer) { 
            notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
        }
    } finally {
        window.blockPageLoad = false; 
    }

    if (notesForCurrentPage.length === 0 && currentPageId) { 
        await handleCreateAndFocusFirstNote(currentPageId); 
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
            // Make an API call to 'api/query_notes.php'
            const response = await fetch('api/query_notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sql_query: sqlQuery })
            });

            if (!response.ok) {
                // Try to get error message from response body
                let errorMsg = `HTTP error! status: ${response.status}`;
                try {
                    const errorResult = await response.json();
                    if (errorResult && errorResult.error) {
                        errorMsg = errorResult.error;
                    }
                } catch (e) { /* Ignore if response is not json */ }
                throw new Error(errorMsg);
            }
            
            const result = await response.json();

            if (result.success && result.data) {
                placeholder.innerHTML = ''; // Clear "Loading..."
                if (result.data.length === 0) {
                    placeholder.textContent = 'Query returned no results.';
                } else {
                    const childrenContainer = document.createElement('div');
                    childrenContainer.className = 'note-children sql-query-results'; // Added specific class

                    result.data.forEach(noteData => {
                        const parentNoteItem = placeholder.closest('.note-item');
                        let nestingLevel = 0;
                        if (parentNoteItem) {
                            const currentNesting = parseInt(parentNoteItem.style.getPropertyValue('--nesting-level') || '0');
                            nestingLevel = currentNesting + 1;
                        }
                        // Assuming window.ui.renderNote is available, as seen in other parts of page-loader.js
                        if (window.ui && typeof window.ui.renderNote === 'function') {
                            const noteElement = window.ui.renderNote(noteData, nestingLevel);
                            childrenContainer.appendChild(noteElement);
                        } else {
                            console.error('window.ui.renderNote is not available to render SQL query results.');
                            placeholder.textContent = 'Error: UI function to render notes is missing.';
                            placeholder.classList.add('error');
                            return; // Stop processing this placeholder
                        }
                    });
                    placeholder.appendChild(childrenContainer);
                    if (typeof feather !== 'undefined' && feather.replace) {
                         feather.replace(); // Refresh icons if any were added
                    }
                }
            } else {
                placeholder.textContent = `Error: ${result.error || 'Failed to execute SQL query.'}`;
                placeholder.classList.add('error');
            }
            placeholder.classList.add('loaded'); // Add loaded class after processing
        } catch (error) {
            console.error('Error fetching SQL query results for query:', sqlQuery, error);
            placeholder.textContent = `Error loading query results: ${error.message}`;
            placeholder.classList.add('error');
            placeholder.classList.add('loaded'); // Add loaded class even on error
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
        const savedNote = await notesAPI.createNote({
            page_id: pageIdToUse, 
            content: ' '
            // Removed parent_note_id as it's not expected in POST requests
        });

        if (savedNote) {
            addNoteToCurrentPage(savedNote);

            if (notesContainer) { // Ensure notesContainer
                if(notesContainer.innerHTML.includes("empty-page-hint") || notesContainer.children.length === 0) {
                    notesContainer.innerHTML = ''; 
                }
                const noteEl = window.ui.renderNote(savedNote, 0); // Assuming ui.renderNote exists
                notesContainer.appendChild(noteEl);
                
                const contentDiv = noteEl.querySelector('.note-content');
                if (contentDiv) {
                    contentDiv.dataset.rawContent = savedNote.content;
                    contentDiv.textContent = '';
                    window.ui.switchToEditMode(contentDiv);
                    
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
