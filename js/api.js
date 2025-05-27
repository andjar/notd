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
    // Assuming results do not contain an 'error' field in the same way other endpoints do.
    // If they might, add error checking here: if (results.error) throw new Error(results.error);
    return results;
}

/**
 * Creates a new page on the server.
 * @async
 * @param {string} pageId - The desired ID for the new page.
 * @param {string} type - The type of the page (e.g., 'note', 'journal').
 * @param {Object} properties - An object containing page properties.
 * @returns {Promise<Object>} A promise that resolves with the server's response data (usually includes the created page info).
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
        return null; // Return null to indicate failure, allowing caller to handle.
    }
}

/**
 * Fetches data for multiple blocks in a batch.
 * @async
 * @param {Array<string>} blockIdsArray - An array of block IDs to fetch.
 * @returns {Promise<Object>} A promise that resolves with an object mapping block IDs to their data. Returns empty object on failure or if input array is empty.
 */
async function fetchBatchBlocksAPI(blockIdsArray) {
    if (!blockIdsArray || blockIdsArray.length === 0) return {}; // No IDs to fetch.
    try {
        const response = await fetch(`api/batch_blocks.php?ids=${blockIdsArray.join(',')}`);
        if (response.ok) {
            return await response.json();
        } else {
            console.error('Failed to fetch batch blocks:', response.status, await response.text());
            return {}; // Return empty object on HTTP error.
        }
    } catch (error) {
        console.error('Error fetching batch blocks:', error);
        return {}; // Return empty object on network or other errors.
    }
}

/**
 * Updates the properties of an existing page.
 * @async
 * @param {string} pageId - The ID of the page to update.
 * @param {string} title - The new title for the page.
 * @param {string} type - The new type for the page.
 * @param {Object} properties - An object containing the new page properties.
 * @returns {Promise<Object>} A promise that resolves with the server's response.
 * @throws {Error} If the fetch operation fails or the server returns an error.
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
 * @param {string} pageId - The ID of the page for which to fetch backlinks.
 * @returns {Promise<Array<Object>>} A promise that resolves with an array of backlink thread objects.
 * @throws {Error} If the fetch operation fails or the server returns an error.
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
 * @param {string} pageId - The ID of the page to add to recent pages.
 * @returns {Promise<void>}
 * @throws {Error} If the fetch operation fails.
 */
async function updateRecentPagesAPI(pageId) {
    const response = await fetch('api/recent_pages.php', {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ page_id: pageId })
    });
    if (!response.ok) throw new Error(`HTTP error updating recent pages! status: ${response.status}`);
    // No specific JSON data is expected on success, but one might check response.ok or similar.
}

/**
 * Uploads a file as an attachment to a specific note.
 * @async
 * @param {string} noteId - The ID of the note to attach the file to.
 * @param {File} file - The file object to upload.
 * @returns {Promise<Object|undefined>} A promise that resolves with server response data, or undefined if no file is provided.
 * @throws {Error} If the fetch operation fails or the server returns an error.
 */
async function uploadFileAPI(noteId, file) {
    if (!file) return; // Early exit if no file provided.
    const formData = new FormData();
    formData.append('file', file); 
    formData.append('note_id', noteId);

    const response = await fetch('api/attachment.php', { method: 'POST', body: formData });
    if (!response.ok) throw new Error(`HTTP error uploading file! status: ${response.status}`);
    const data = await response.json();
    if (data.error) throw new Error(data.error);
    return { ...data, success: true }; // Add success flag to response
}

/**
 * Deletes an attachment by its ID.
 * @async
 * @param {number} attachmentId - The ID of the attachment to delete.
 * @returns {Promise<Object>} A promise that resolves with the server's response.
 * @throws {Error} If the fetch operation fails or the server returns an error.
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
 * Toggles the TODO state of a note/block and updates its content and properties on the server.
 * This function includes logic to parse and reconstruct the note content with TODO markers and properties.
 * @async
 * @param {string} noteId - The main ID of the note item to update.
 * @param {string} blockId - The specific block ID (might be same as noteId or a sub-block).
 * @param {string} currentContent - The current raw content of the note/block.
 * @param {boolean} isDone - The new TODO state (true for DONE, false for TODO).
 * @param {Object} currentProperties - The current properties object of the note/block.
 * @param {number} level - The indentation level of the note/block.
 * @param {string|null} parentId - The ID of the parent note, if any.
 * @returns {Promise<Object>} A promise that resolves with the server's response.
 * @throws {Error} If the fetch operation fails or the server returns an error.
 */
async function toggleTodoAPI(noteId, blockId, currentContent, isDone, currentProperties, level, parentId) {
    // Logic to parse current content and extract task description vs. inline properties
    let taskTextWithProperties = "";
    if (currentContent.startsWith('TODO ')) {
        taskTextWithProperties = currentContent.substring(5);
    } else if (currentContent.startsWith('DONE ')) {
        taskTextWithProperties = currentContent.substring(5);
    } else {
        // If no TODO/DONE prefix, assume the whole content is the task.
        taskTextWithProperties = currentContent;
    }

    const taskSpecificProperties = {}; // To store properties found within the task text itself
    // Regex to find {key::value} properties within the task description
    let cleanTaskDescription = taskTextWithProperties.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
        taskSpecificProperties[key.trim()] = value.trim();
        return ''; // Remove the property from the task description
    }).trim();

    // Construct the new content string
    let newStatusPrefix = isDone ? 'DONE ' : 'TODO ';
    let newContentString = newStatusPrefix + cleanTaskDescription;

    // Merge existing properties with any task-specific ones found and the new 'done-at' property
    const updatedNoteProperties = { ...(currentProperties || {}) };

    if (isDone) {
        taskSpecificProperties['done-at'] = new Date().toISOString(); // Add 'done-at' timestamp
    } else {
        delete taskSpecificProperties['done-at']; // Remove 'done-at' if unchecking
    }

    // Append task-specific properties back to the content string and update the main properties object
    for (const [key, value] of Object.entries(taskSpecificProperties)) {
        newContentString += ` {${key}::${value}}`; // Add back to content string
        updatedNoteProperties[key] = value;      // Ensure it's in the main properties object
    }
    // If unchecking, ensure 'done-at' is also removed from the main properties object
    if (!isDone) {
        delete updatedNoteProperties['done-at'];
    }

    // API call to update the note
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
 * Executes a search based on a "search link" query (e.g., "<<query>>").
 * @async
 * @param {string} query - The search query extracted from the link.
 * @returns {Promise<Array<Object>>} A promise that resolves with an array of search result objects.
 * @throws {Error} If the fetch operation fails or the server returns an error.
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
 * @param {string} query - The SQL-like query string.
 * @returns {Promise<Array<Object>>} A promise that resolves with an array of search result objects.
 * @throws {Error} If the fetch operation fails, the server returns an error, or the response is not ok.
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
            // Attempt to parse error from JSON response if available
            const errorData = await response.json();
            if (errorData && errorData.error) { errorMsg = errorData.error; }
        } catch (e) { /* Ignore parsing error if response is not JSON */ }
        throw new Error(errorMsg);
    }
    const results = await response.json();
    if (results.error) throw new Error(results.error);
    return results;
}

/**
 * Reorders a note (changes its parent or position among siblings).
 * @async
 * @param {number} noteId - The ID of the note to reorder.
 * @param {number|null} newParentId - The ID of the new parent note, or null for top-level.
 * @param {number} newOrder - The new 0-indexed position of the note among its siblings.
 * @param {string} pageId - The ID of the page where the reordering is happening.
 * @returns {Promise<Object>} A promise that resolves with the server's response.
 * @throws {Error} If the fetch operation fails or the server returns an error.
 */
async function reorderNoteAPI(noteId, newParentId, newOrder, pageId) {
    const payload = {
        action: 'reorder_note',
        note_id: parseInt(noteId),
        new_parent_id: newParentId ? parseInt(newParentId) : null,
        new_order: parseInt(newOrder),
        page_id: pageId // Current page context for the operation
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
 * Creates a new note on the server. If `intendedOrder` is provided, it subsequently
 * attempts to reorder the note to that position.
 * @async
 * @param {string} pageId - The ID of the page where the note will be created.
 * @param {string} content - The content of the new note.
 * @param {number} level - The indentation level of the new note.
 * @param {string|null} parentId - The ID of the parent note, or null if top-level.
 * @param {Object} properties - An object containing properties for the new note.
 * @param {number|null} intendedOrder - The desired 0-indexed position among siblings after creation.
 * @returns {Promise<Object>} A promise that resolves with the created note data (including its new ID).
 * @throws {Error} If the note creation fetch operation fails or the server returns an error.
 */
async function createNoteAPI(pageId, content, level, parentId, properties, intendedOrder) {
    // Initial request to create the note
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
    if (!createData.id) throw new Error('Note created but no ID returned.'); // Essential for further operations
    
    const newNoteId = createData.id;

    // If an intendedOrder is specified, make a second call to reorder the newly created note.
    if (intendedOrder !== null && intendedOrder !== undefined) {
        try {
            // Call reorderNoteAPI to place the note correctly.
            // This implicitly uses another fetch call.
            await reorderNoteAPI(newNoteId, parentId, intendedOrder, pageId);
        } catch (reorderError) {
            // Log a warning if reordering fails, but the note was still created.
            console.warn(`Note created (ID: ${newNoteId}), but reorder failed: ${reorderError.message}. The note might not be in the exact intended position.`);
            // Depending on requirements, this could be escalated or handled more gracefully.
        }
    }
    return { id: newNoteId, ...createData }; // Return all data from the initial creation response.
}

/**
 * Updates an existing note on the server.
 * @async
 * @param {string} noteId - The ID of the note to update.
 * @param {string} content - The new content for the note.
 * @param {Object} properties - The new properties object for the note.
 * @param {number} level - The (potentially new) indentation level of the note.
 * @returns {Promise<Object>} A promise that resolves with the server's response.
 * @throws {Error} If the fetch operation fails or the server returns an error.
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
 * Deletes a note from the server.
 * @async
 * @param {string} noteId - The ID of the note to delete.
 * @returns {Promise<Object>} A promise that resolves with the server's response.
 * @throws {Error} If the fetch operation fails or the server returns an error.
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
 * Fetches page title suggestions from the server based on a search term.
 * Used for auto-completing page links (e.g., [[Page Title]]).
 * @async
 * @param {string} searchTerm - The term to search for.
 * @returns {Promise<Array<Object>>} A promise that resolves with an array of suggestion objects (typically {id, title}).
 * @throws {Error} If the fetch operation fails.
 */
async function fetchPageSuggestionsAPI(searchTerm) {
    const response = await fetch(`api/suggest_pages.php?q=${encodeURIComponent(searchTerm)}`);
    if (!response.ok) throw new Error(`HTTP error fetching page suggestions! status: ${response.status}`);
    const suggestions = await response.json();
    // It's good practice to check if suggestions is indeed an array or if it might contain an error field.
    // if (suggestions.error) throw new Error(suggestions.error);
    return suggestions;
}