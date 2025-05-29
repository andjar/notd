// Functions related to API communication
// (e.g., fetching data from api/*.php endpoints)

/**
 * Loads note templates from the server and stores them.
 * Assumes `noteTemplates` is a globally accessible variable (e.g., in state.js).
 * @async
 */
async function loadTemplates() {
    try {
        const response = await fetch('api/templates.php');
        if (!response.ok) { // Check if response was successful (status in the range 200-299)
            throw new Error(`HTTP error loading templates! status: ${response.status}`);
        }
        const templates = await response.json();
        noteTemplates = templates; // `noteTemplates` is expected to be a global state variable.
    } catch (error) {
        console.error('Error loading templates:', error);
        // Potentially set noteTemplates to an empty object or handle error more gracefully
        noteTemplates = {};
    }
}

/**
 * Fetches page data for a given page ID from the server.
 * @async
 * @param {string} pageId - The ID of the page to fetch.
 * @returns {Promise<Object>} A promise that resolves with the page data object.
 * @throws {Error} If the fetch operation fails or the server returns an error.
 */
async function fetchPageData(pageId) {
    const response = await fetch(`api/page.php?id=${pageId}`);
    if (!response.ok) throw new Error(`HTTP error fetching page data! status: ${response.status}`);
    const text = await response.text();
    if (!text) throw new Error('Empty response from server when fetching page data.');
    const data = JSON.parse(text);
    if (data.error) { // Check for application-level errors returned in the JSON response
        throw new Error(data.error);
    }
    return data;
}

// ... (other existing API functions like fetchRecentPages, searchPages, etc. remain unchanged) ...
// Assume all functions up to executeCustomQueryNotes are here and unchanged.

/**
 * Fetches the list of recent pages from the server.
 * @async
 * @returns {Promise<Array<Object>>} A promise that resolves with an array of recent page objects.
 * @throws {Error} If the fetch operation fails or the server returns an error.
 */
async function fetchRecentPages() {
    const response = await fetch('api/recent_pages.php');
    if (!response.ok) throw new Error(`HTTP error fetching recent pages! status: ${response.status}`);
    const data = await response.json();
    if (data.error) {
        console.error('Server error fetching recent pages:', data.error);
        throw new Error(data.error);
    }
    return data;
}

/**
 * Performs a basic search for pages based on a query string.
 * @async
 * @param {string} query - The search query.
 * @returns {Promise<Array<Object>>} A promise that resolves with an array of search result objects.
 * @throws {Error} If the fetch operation fails.
 */
async function searchPages(query) {
    const response = await fetch(`api/search.php?q=${encodeURIComponent(query)}`);
    if (!response.ok) throw new Error(`HTTP error during page search! status: ${response.status}`);
    const results = await response.json();
    return results;
}

/**
 * Creates a new page on the server.
 * @async
 * @param {string} pageId - The desired ID for the new page.
 * @param {string} type - The type of the page (e.g., 'note', 'journal').
 * @param {Object} properties - An object containing page properties.
 * @returns {Promise<Object>} A promise that resolves with the server's response data.
 * @throws {Error} If the fetch operation fails or the server returns an error.
 */
async function createNewPageAPI(pageId, type, properties) {
    const response = await fetch('api/page.php', {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: encodeURIComponent(pageId), title: pageId, type, properties })
    });
    if (!response.ok) throw new Error(`HTTP error creating new page! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Fetches a specific block's data by its ID.
 * @async
 * @param {string} blockId - The ID of the block to fetch.
 * @returns {Promise<Object|null>} A promise that resolves with the block data object, or null if an error occurs.
 */
async function findBlockByIdAPI(blockId) {
    try {
        const response = await fetch(`api/block.php?id=${blockId}`);
        if (!response.ok) throw new Error(`HTTP error finding block by ID! status: ${response.status}`);
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        return data;
    } catch (error) {
        console.error('Error finding block by ID:', blockId, error);
        return null; 
    }
}

/**
 * Fetches data for multiple blocks in a batch.
 * @async
 * @param {Array<string>} blockIdsArray - An array of block IDs to fetch.
 * @returns {Promise<Object>} A promise that resolves with an object mapping block IDs to their data.
 */
async function fetchBatchBlocksAPI(blockIdsArray) {
    if (!blockIdsArray || blockIdsArray.length === 0) return {}; 
    try {
        const response = await fetch(`api/batch_blocks.php?ids=${blockIdsArray.join(',')}`);
        if (response.ok) {
            return await response.json();
        } else {
            console.error('Failed to fetch batch blocks:', response.status, await response.text());
            return {}; 
        }
    } catch (error) {
        console.error('Error fetching batch blocks:', error);
        return {}; 
    }
}

/**
 * Updates the properties of an existing page.
 * @async
 */
async function updatePagePropertiesAPI(pageId, title, type, properties) {
    const response = await fetch(`api/page.php?id=${pageId}`, {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', title: title, type: type || 'note', properties: properties })
    });
    if (!response.ok) throw new Error(`HTTP error updating page properties! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Fetches backlinks for a given page ID.
 * @async
 */
async function fetchBacklinksAPI(pageId) {
    const response = await fetch(`api/backlinks.php?page_id=${pageId}`);
    if (!response.ok) throw new Error(`Network response error fetching backlinks: ${response.statusText}`);
    const threads = await response.json();
    if (threads.error) throw new Error(threads.error);
    return threads;
}

/**
 * Updates the server-side list of recent pages by adding the given pageId.
 * @async
 */
async function updateRecentPagesAPI(pageId) {
    const response = await fetch('api/recent_pages.php', {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ page_id: pageId })
    });
    if (!response.ok) throw new Error(`HTTP error updating recent pages! status: ${response.status}`);
}

/**
 * Uploads a file as an attachment to a specific note.
 * @async
 */
async function uploadFileAPI(noteId, file) {
    if (!file) return; 
    const formData = new FormData();
    formData.append('file', file); 
    formData.append('note_id', noteId);

    const response = await fetch('api/attachment.php', { method: 'POST', body: formData });
    if (!response.ok) throw new Error(`HTTP error uploading file! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return { ...data, success: true };
}

/**
 * Deletes an attachment by its ID.
 * @async
 */
async function deleteAttachmentAPI(attachmentId) {
    const response = await fetch(`api/attachment.php`, {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: attachmentId })
    });
    if (!response.ok) throw new Error(`HTTP error deleting attachment! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Toggles the TODO state of a note/block.
 * @async
 */
async function toggleTodoAPI(noteId, blockId, currentContent, isDone, currentProperties, level, parentId) {
    let taskTextWithProperties = "";
    if (currentContent.startsWith('TODO ')) {
        taskTextWithProperties = currentContent.substring(5);
    } else if (currentContent.startsWith('DONE ')) {
        taskTextWithProperties = currentContent.substring(5);
    } else {
        taskTextWithProperties = currentContent;
    }
    const taskSpecificProperties = {};
    let cleanTaskDescription = taskTextWithProperties.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
        taskSpecificProperties[key.trim()] = value.trim();
        return '';
    }).trim();
    let newStatusPrefix = isDone ? 'DONE ' : 'TODO ';
    let newContentString = newStatusPrefix + cleanTaskDescription;
    const updatedNoteProperties = { ...(currentProperties || {}) };
    if (isDone) {
        taskSpecificProperties['done-at'] = new Date().toISOString();
    } else {
        delete taskSpecificProperties['done-at'];
    }
    for (const [key, value] of Object.entries(taskSpecificProperties)) {
        newContentString += ` {${key}::${value}}`;
        updatedNoteProperties[key] = value;
    }
    if (!isDone) {
        delete updatedNoteProperties['done-at'];
    }
    const response = await fetch(`api/note.php?id=${noteId}`, {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update',
            content: newContentString.trim(),
            properties: updatedNoteProperties,
            level: level, 
            parent_id: parentId
        })
    });
    if (!response.ok) throw new Error(`HTTP error toggling TODO status! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Executes a search based on a "search link" query.
 * @async
 */
async function executeSearchLinkAPI(query) {
    const response = await fetch('api/advanced_search.php', { 
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' }, 
        body: JSON.stringify({ query }) 
    });
    if (!response.ok) throw new Error(`HTTP error executing search link! status: ${response.status}`);
    const results = await response.json();
    if (results.error) throw new Error(results.error);
    return results;
}

/**
 * Executes an advanced search using a raw SQL-like query.
 * @async
 */
async function executeAdvancedSearchAPI(query) {
    const response = await fetch('api/advanced_search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', },
        body: JSON.stringify({ query })
    });
    if (!response.ok) {
        let errorMsg = `HTTP error during advanced search! status: ${response.status}`;
        try {
            const errorData = await response.json();
            if (errorData && errorData.error) { errorMsg = errorData.error; }
        } catch (e) { /* Ignore */ }
        throw new Error(errorMsg);
    }
    const results = await response.json();
    if (results.error) throw new Error(results.error);
    return results;
}

/**
 * Reorders a note.
 * @async
 */
async function reorderNoteAPI(noteId, newParentId, newOrder, pageId) {
    const payload = {
        action: 'reorder_note',
        note_id: parseInt(noteId),
        new_parent_id: newParentId ? parseInt(newParentId) : null,
        new_order: parseInt(newOrder),
        page_id: pageId
    };
    const response = await fetch('api/note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    if (!response.ok) throw new Error(`HTTP error reordering note! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Creates a new note.
 * @async
 */
async function createNoteAPI(pageId, content, level, parentId, properties, intendedOrder) {
     const createResponse = await fetch('api/note.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            page_id: pageId,
            content: content,
            level: level,
            parent_id: parentId,
            properties: properties
        })
    });
    if (!createResponse.ok) throw new Error(`HTTP error creating note! status: ${createResponse.status}`);
    const createData = await createResponse.json();
    if (createData.error) throw new Error(`Create error: ${createData.error}`);
    if (!createData.id) throw new Error('Note created but no ID returned.');
    const newNoteId = createData.id;
    if (intendedOrder !== null && intendedOrder !== undefined) {
        try {
            await reorderNoteAPI(newNoteId, parentId, intendedOrder, pageId);
        } catch (reorderError) {
            console.warn(`Note created (ID: ${newNoteId}), but reorder failed: ${reorderError.message}.`);
        }
    }
    return { id: newNoteId, ...createData };
}

/**
 * Updates an existing note.
 * @async
 */
async function updateNoteAPI(noteId, content, properties, level) {
    const response = await fetch(`api/note.php?id=${noteId}`, {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', content: content, properties: properties, level: level })
    });
    if (!response.ok) throw new Error(`HTTP error updating note! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Deletes a note.
 * @async
 */
async function deleteNoteAPI(noteId) {
    const response = await fetch(`api/note.php?id=${noteId}`, {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete' })
    });
    if (!response.ok) throw new Error(`HTTP error deleting note! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return data;
}

/**
 * Fetches page title suggestions.
 * @async
 */
async function fetchPageSuggestionsAPI(searchTerm) {
    const response = await fetch(`api/suggest_pages.php?q=${encodeURIComponent(searchTerm)}`);
    if (!response.ok) throw new Error(`HTTP error fetching page suggestions! status: ${response.status}`);
    const suggestions = await response.json();
    return suggestions;
}

/**
 * Fetches notes based on a custom SQL query.
 * @async
 */
async function fetchCustomQueryNotes(sqlQuery) {
    const response = await fetch('api/query_notes.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: sqlQuery })
    });
    if (!response.ok) {
        const errorData = await response.json().catch(() => ({ error: 'Network response was not ok and failed to parse error JSON.' }));
        throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
    }
    return response.json(); 
}

// --- NEW Generic Settings API Functions ---

/**
 * Fetches multiple settings from the backend.
 * @param {string[]} keysArray - An array of setting keys to fetch.
 * @returns {Promise<Object>} A promise that resolves to an object containing the fetched settings (key-value pairs).
 *                            Returns an empty object {} on API error or if data.success is false.
 */
async function fetchSettings(keysArray) {
    if (!Array.isArray(keysArray) || keysArray.length === 0) {
        console.warn('fetchSettings: keysArray must be a non-empty array. Returning empty settings.');
        return {};
    }
    const params = new URLSearchParams();
    keysArray.forEach(key => params.append('key[]', key));
    const queryString = params.toString();

    try {
        const response = await fetch(`api/user_settings.php?action=get&${queryString}`);
        if (!response.ok) {
            console.error(`API Error - fetchSettings response not OK for keys [${keysArray.join(', ')}]:`, response.status, await response.text());
            return {}; 
        }
        const data = await response.json();
        if (data.success && data.settings) {
            return data.settings;
        } else {
            console.error(`API Error - fetchSettings returned success=false or no settings object for keys [${keysArray.join(', ')}]:`, data.message || 'Unknown API error');
            return {};
        }
    } catch (error) {
        console.error(`Network or JSON Error - fetchSettings for keys [${keysArray.join(', ')}]:`, error);
        return {};
    }
}

/**
 * Updates a single setting on the backend.
 * @param {string} key - The setting key to update.
 * @param {string|boolean|number} value - The new value for the setting. Will be converted to a string.
 * @returns {Promise<boolean>} A promise that resolves to true if the update was successful, false otherwise.
 */
async function updateSetting(key, value) {
    try {
        const response = await fetch('api/user_settings.php?action=set', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ key: key, value: String(value) }), // Ensure value is stringified
        });
        if (!response.ok) {
            console.error(`API Error - updateSetting response not OK for key [${key}]:`, response.status, await response.text());
            return false;
        }
        const data = await response.json();
        if (data.success) {
            return true;
        } else {
            console.error(`API Error - updateSetting returned success=false for key [${key}]:`, data.message || 'Unknown API error');
            return false;
        }
    } catch (error) {
        console.error(`Network or JSON Error - updateSetting for key [${key}]:`, error);
        return false;
    }
}

// --- Refactored Original Settings API Functions ---

/**
 * Fetches the toolbar visibility setting from the backend.
 * @returns {Promise<boolean>} A promise that resolves to true if the toolbar should be visible, false otherwise.
 */
async function fetchToolbarVisibilityAPI() {
    // The backend defaults 'toolbarVisible' to 'true' (string) if not found.
    const settings = await fetchSettings(['toolbarVisible']);
    // If settings.toolbarVisible is "true", return true, otherwise (e.g., "false", undefined, or settings is empty) return false.
    // This maintains the boolean return type expected by consumers of this specific function.
    return settings && settings.toolbarVisible === 'true';
}

/**
 * Updates the toolbar visibility setting on the backend.
 * @param {boolean} isVisible - True if the toolbar should be visible, false otherwise.
 * @returns {Promise<boolean>} A promise that resolves to true if the update was successful, false otherwise.
 */
async function updateToolbarVisibilityAPI(isVisible) {
    return await updateSetting('toolbarVisible', isVisible ? 'true' : 'false');
}
