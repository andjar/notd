/**
 * API Client for NotTD application
 * Provides functions for making asynchronous requests to the PHP backend API
 * @module api_client
 */

/**
 * Base URL for API requests, derived from <base> tag or defaulting to root
 * @type {string}
 */
const API_BASE_URL = (document.querySelector('base[href]')?.href || '/') + 'api/';

/**
 * Generic function to make API requests
 * @param {string} endpoint - API endpoint path
 * @param {string} [method='GET'] - HTTP method
 * @param {Object|FormData|null} [body=null] - Request body
 * @returns {Promise<any>} API response data
 * @throws {Error} If the request fails or returns an error
 */
async function apiRequest(endpoint, method = 'GET', body = null) {
    const options = {
        method,
        headers: {
            'Accept': 'application/json'
        },
        credentials: 'same-origin' // Include cookies for session handling
    };

    // Handle request body
    if (body) {
        if (body instanceof FormData) {
            options.body = body;
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
    }

    try {
        const response = await fetch(API_BASE_URL + endpoint, options);
        
        // Debug logging
        console.log(`[apiRequest] Making ${method} request to: ${API_BASE_URL + endpoint}`);
        if (body) {
            console.log('[apiRequest] Request body:', body);
        }
        
        // Handle empty responses (e.g., 204 No Content)
        if (response.status === 204) {
            return { success: true, data: null };
        }

        // Check content type before parsing
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            throw new Error(`Invalid response format: expected JSON but got ${contentType || 'unknown content type'}`);
        }

        // Parse response as JSON
        const data = await response.json();

        // Check for PHP errors in the response
        if (typeof data === 'string' && data.includes('<br />')) {
            console.error('PHP Error in response:', data);
            throw new Error('Server error: PHP error in response');
        }

        // Check for API-level errors or explicit error status
        let errorMessage = null;
        if (!response.ok) { // HTTP error status (4xx, 5xx)
            errorMessage = data?.error?.message || data?.message || data?.error || response.statusText;
            // Fallback if error is still an object
            if (typeof errorMessage === 'object') {
                errorMessage = JSON.stringify(errorMessage);
            }
            console.error(`API Error HTTP (${response.status}):`, errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
        } else if (data?.success === false) { // Explicit success:false pattern
            errorMessage = data?.error?.message || data?.message || data?.error || 'API request failed (success:false)';
            // Fallback if error is still an object
            if (typeof errorMessage === 'object') {
                errorMessage = JSON.stringify(errorMessage);
            }
            console.error('API Error (success:false):', errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
        } else if (data?.status === 'error') { // Explicit status:'error' pattern (even with HTTP 200 OK)
            errorMessage = data.message || data.error || 'API request failed (status:error)';
            // Fallback if error is still an object
            if (typeof errorMessage === 'object') {
                errorMessage = JSON.stringify(errorMessage);
            }
            console.error('API Error (status:error):', errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
        }

        if (errorMessage) {
            throw new Error(errorMessage);
        }
        
        // If we reach here, the request is considered successful by the API's own reporting.
        // The API spec sometimes has data directly, sometimes under a 'data' field in the response.
        // The original apiRequest returned `data.data`. We should be careful here.
        // Most APIs in the spec return { "status": "success", "data": ... } or { "success": true, "data": ... }
        // For these, `data.data` is correct.
        // However, the Attachments API on success POST returns:
        // { "status": "success", "message": "File uploaded successfully.", "data": { ... } }
        // And on DELETE: { "status": "success", "message": "Attachment deleted successfully." } (NO data.data field)
        // The Templates API on GET (after client modification) was { success: true, data: [...] }
        // The original `apiRequest` returned `data.data`. This might be problematic for DELETE Attachments.
        // Let's assume for now that callers handle cases where `data.data` might be undefined if the primary success indicator is `status:'success'` or `success:true`.
        // The problem description was about *error handling*.

        console.log('[apiRequest] Response successful. Full data object:', JSON.parse(JSON.stringify(data)));
        // The problem statement for `templatesAPI.getTemplates` mentioned it re-wrapped `response` if it was an array.
        // `response` there was `data.data` from `apiRequest`.
        // This implies `apiRequest` should consistently return the "payload" part.
        // For responses like `{ "status": "success", "message": "..." }` (e.g., Attachment DELETE), `data.data` is undefined.
        // This should be acceptable; the promise resolves, and the caller gets `undefined`, signifying success without specific data.

        return data.data; // Keep the original return statement for the data payload
    } catch (error) {
        // Handle network errors or JSON parsing failures
        console.error('[apiRequest] Error details:', { endpoint, method, error: error.message, stack: error.stack });
        if (error instanceof SyntaxError) {
            console.error('Failed to parse API response:', error);
            throw new Error('Invalid API response format: ' + error.message);
        }
        // Re-throw other errors
        throw error;
    }
}

/**
 * API functions for managing pages
 * @namespace pagesAPI
 */
const pagesAPI = {
    /**
     * Get all pages
     * @param {Object} [options={}] - Query options
     * @param {boolean} [options.excludeJournal=false] - Whether to exclude journal pages
     * @param {boolean} [options.followAliases=true] - Whether to follow page aliases
     * @param {boolean} [options.include_details=false] - Whether to include page properties and notes (new backend feature)
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties and notes (if include_details is true)
     * @returns {Promise<Array>} Array of page objects (potentially detailed if include_details is true)
     */
    getPages: (options = {}) => {
        const params = new URLSearchParams();
        if (options.excludeJournal) params.append('exclude_journal', '1');
        if (options.followAliases === false) params.append('follow_aliases', '0');
        if (options.include_details) params.append('include_details', '1');
        if (options.include_internal) params.append('include_internal', '1');
        
        const queryString = params.toString();
        return apiRequest(`pages.php${queryString ? '?' + queryString : ''}`);
    },

    /**
     * Get page by ID
     * @param {number} id - Page ID
     * @param {Object} [options={}] - Query options
     * @param {boolean} [options.followAliases=true] - Whether to follow page aliases.
     * @param {boolean} [options.include_details=false] - Whether to include page properties and notes.
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties (if include_details is true).
     * @returns {Promise<Object>} Page object (potentially detailed if include_details is true).
     */
    getPageById: (id, options = {}) => {
        const params = new URLSearchParams({ id: id.toString() });
        if (options.followAliases === false) params.append('follow_aliases', '0');
        if (options.include_details) params.append('include_details', '1');
        if (options.include_internal) params.append('include_internal', '1'); // Server expects '1' for true
        
        return apiRequest(`pages.php?${params.toString()}`);
    },

    /**
     * Get page by name
     * @param {string} name - Page name
     * @param {Object} [options={}] - Query options
     * @param {boolean} [options.followAliases=true] - Whether to follow page aliases.
     * @param {boolean} [options.include_details=false] - Whether to include page properties and notes.
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties (if include_details is true).
     * @returns {Promise<Object>} Page object (potentially detailed if include_details is true).
     */
    getPageByName: (name, options = {}) => {
        const params = new URLSearchParams({ name });
        if (options.followAliases === false) params.append('follow_aliases', '0');
        if (options.include_details) params.append('include_details', '1');
        if (options.include_internal) params.append('include_internal', '1'); // Server expects '1' for true

        return apiRequest(`pages.php?${params.toString()}`);
    },

    /**
     * Create a new page
     * @param {{name: string, alias?: string}} pageData - Page data
     * @returns {Promise<Object>} Created page object
     */
    createPage: (pageData) => apiRequest('pages.php', 'POST', pageData),

    /**
     * Update a page
     * @param {number} id - Page ID
     * @param {{name?: string, alias?: string}} pageData - Updated page data
     * @returns {Promise<Object>} Updated page object
     */
    updatePage: (id, pageData) => apiRequest(`pages.php?id=${id}`, 'PUT', pageData),

    /**
     * Delete a page
     * @param {number} id - Page ID
     * @returns {Promise<Object>} Delete confirmation
     */
    deletePage: (id) => apiRequest(`pages.php?id=${id}`, 'DELETE')
};

/**
 * API functions for managing notes
 * @namespace notesAPI
 */
const notesAPI = {
    /**
     * Get full page data including page details, notes, and properties.
     * @param {number} pageId - Page ID
     * @param {Object} [options={}] - Query options
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties and notes.
     * @returns {Promise<{page: Object, notes: Array<Object>}>} Object containing page details and an array of notes with their properties.
     * Expected structure: { page: { ...page_details, properties: { ...page_properties } }, notes: [ { ...note_details, properties: { ...note_properties } }, ... ] }
     */
    getPageData: (pageId, options = {}) => {
        const params = new URLSearchParams();
        params.append('page_id', pageId.toString());
        if (options.include_internal) {
            params.append('include_internal', '1');
        }
        return apiRequest(`notes.php?${params.toString()}`);
    },

    /**
     * Get a specific note
     * @param {number} noteId - Note ID
     * @param {Object} [options={}] - Query options
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties.
     * @returns {Promise<{id: number, content: string, page_id: number, created_at: string, updated_at: string, properties: Object}>}
     */
    getNote: (noteId, options = {}) => {
        const params = new URLSearchParams();
        params.append('id', noteId.toString());
        if (options.include_internal) {
            params.append('include_internal', '1');
        }
        return apiRequest(`notes.php?${params.toString()}`);
    },

    /**
     * Create a new note
     * @param {{page_id: number, content: string, parent_note_id?: number|null}} noteData - Note data
     * @returns {Promise<{id: number, content: string, page_id: number, parent_note_id: number|null, created_at: string, updated_at: string}>}
     */
    createNote: (noteData) => apiRequest('notes.php', 'POST', noteData),

    /**
     * Update a note
     * @param {number} noteId - Note ID
     * @param {Object} noteUpdateData - Updated note data.
     * @param {string} [noteUpdateData.content] - The new content for the note.
     * @param {number|null} [noteUpdateData.parent_note_id] - The ID of the parent note, or null.
     * @param {number} [noteUpdateData.order_index] - The display order of the note.
     * @param {number} [noteUpdateData.collapsed] - 0 for expanded, 1 for collapsed.
     * @param {Object} [noteUpdateData.properties_explicit] - Explicit properties to set.
     * @returns {Promise<Object>} The updated note object. The structure matches the getNote response.
     */
    updateNote: (noteId, noteUpdateData) => {
        // noteUpdateData can now include:
        // content, parent_note_id, order_index, collapsed, properties_explicit
        const bodyWithMethodOverride = {
            ...noteUpdateData, // Spread all fields from noteUpdateData
            id: noteId,       // Ensure noteId is in the body as per existing logic
            _method: 'PUT'    // Method override
        };
        // Remove any potential undefined fields that might have been explicitly passed
        Object.keys(bodyWithMethodOverride).forEach(key => {
            if (bodyWithMethodOverride[key] === undefined) {
                delete bodyWithMethodOverride[key];
            }
        });
        return apiRequest('notes.php', 'POST', bodyWithMethodOverride);
    },

    /**
     * Delete a note
     * @param {number} noteId - Note ID
     * @returns {Promise<null>}
     */
    deleteNote: (noteId) => {
        // const pageId = window.currentPageId; // No longer needed for the request body
        // if (!pageId) { // This check might be relevant for UI logic, but not for the API call itself if page_id is not part of the API.
        //     return Promise.reject(new Error('Page ID is required')); // Consider if this check should remain for other reasons or be removed. Assuming removal for pure API alignment.
        // }

        const bodyWithMethodOverride = {
            id: noteId,
            _method: 'DELETE'
            // page_id: pageId // REMOVED
        };
        return apiRequest('notes.php', 'POST', bodyWithMethodOverride);
    }
};

/**
 * API functions for managing properties
 * @namespace propertiesAPI
 */
const propertiesAPI = {
    /**
     * Get properties for an entity
     * @param {string} entityType - Entity type ('note' or 'page')
     * @param {number} entityId - Entity ID
     * @param {Object} [options={}] - Query options.
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties.
     * @returns {Promise<Object>} Properties object. Structure may vary based on include_internal.
     */
    getProperties: (entityType, entityId, options = {}) => {
        const params = new URLSearchParams({
            entity_type: entityType,
            entity_id: entityId.toString()
        });
        if (options.include_internal) {
            params.append('include_internal', '1');
        }
        return apiRequest(`properties.php?${params.toString()}`);
    },

    /**
     * Set a property. If the property already exists, its value is updated.
     * @param {Object} propertyData - Property data.
     * @param {string} propertyData.entity_type - Entity type ('note' or 'page').
     * @param {number} propertyData.entity_id - Entity ID.
     * @param {string} propertyData.name - Property name.
     * @param {any} propertyData.value - Property value.
     * @param {0|1} [propertyData.internal] - Optional. Explicitly set internal status (0 or 1). If undefined, backend determines automatically.
     * @returns {Promise<Object>} The created or updated property object.
     */
    setProperty: (propertyData) => apiRequest('properties.php', 'POST', propertyData),

    /**
     * Delete a property
     * @param {string} entityType - Entity type ('note' or 'page')
     * @param {number} entityId - Entity ID
     * @param {string} propertyName - Property name
     * @returns {Promise<null>}
     */
    deleteProperty: (entityType, entityId, propertyName) => {
        const deleteData = {
            action: 'delete',
            entity_type: entityType,
            entity_id: entityId,
            name: propertyName
        };
        return apiRequest('properties.php', 'POST', deleteData);
    }
};

/**
 * API functions for managing attachments
 * @namespace attachmentsAPI
 */
const attachmentsAPI = {
    /**
     * Get all attachments with optional filtering and pagination
     * @param {Object} [params={}] - Query parameters
     * @param {number} [params.page=1] - Page number
     * @param {number} [params.per_page=10] - Items per page
     * @param {string} [params.sort_by='created_at'] - Field to sort by
     * @param {string} [params.sort_order='desc'] - Sort order ('asc' or 'desc')
     * @param {string} [params.filter_by_name] - Filter by name (partial match)
     * @param {string} [params.filter_by_type] - Filter by type (exact match)
     * @returns {Promise<{attachments: Array, pagination: Object}>} Object containing attachments array and pagination info
     */
    getAllAttachments: (params = {}) => {
        const queryParams = new URLSearchParams();
        
        // Add all provided parameters
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                queryParams.append(key, value);
            }
        });
        
        return apiRequest(`attachments.php?${queryParams.toString()}`);
    },

    /**
     * Get attachments for a specific note
     * @param {number} noteId - Note ID
     * @returns {Promise<Array>} Array of attachment objects
     */
    getNoteAttachments: (noteId) => {
        return apiRequest(`attachments.php?note_id=${noteId}`);
    },

    /**
     * Upload a new attachment
     * @param {FormData} formData - FormData object containing note_id and attachmentFile
     * @returns {Promise<Object>} Created attachment object
     */
    uploadAttachment: (formData) => {
        return apiRequest('attachments.php', 'POST', formData);
    },

    /**
     * Delete an attachment
     * @param {number} attachmentId - Attachment ID
     * @returns {Promise<Object>} Delete confirmation
     */
    deleteAttachment: (attachmentId) => {
        return apiRequest(`attachments.php?id=${attachmentId}`, 'DELETE');
    }
};

/**
 * API functions for search operations
 * @namespace searchAPI
 */
const searchAPI = {
    /**
     * Perform a full-text search
     * @param {string} query - Search query
     * @returns {Promise<Array<{note_id: number, content: string, page_id: number, page_name: string, content_snippet: string}>>}
     */
    search: (query) => apiRequest(`search.php?q=${encodeURIComponent(query)}`),

    /**
     * Get backlinks for a page
     * @param {string} pageName - Page name
     * @returns {Promise<Array<{note_id: number, content: string, page_id: number, source_page_name: string, content_snippet: string}>>}
     */
    getBacklinks: (pageName) => 
        apiRequest(`search.php?backlinks_for_page_name=${encodeURIComponent(pageName)}`),

    /**
     * Get tasks by status
     * @param {'todo'|'done'} status - Task status
     * @returns {Promise<Array<{note_id: number, content: string, page_id: number, page_name: string, content_snippet: string, properties: Object}>>}
     */
    getTasks: (status) => apiRequest(`search.php?tasks=${status}`)
};

/**
 * API functions for managing templates
 * @namespace templatesAPI
 */
const templatesAPI = {
    /**
     * Get available templates
     * @param {string} type - Template type ('note' or 'page')
     * @returns {Promise<Array<{name: string, content: string}>>} Array of template objects.
     */
    getTemplates: (type) => apiRequest(`templates.php?type=${type}`),

    /**
     * Create a new template
     * @param {{type: string, name: string, content: string}} templateData - Template data
     * @returns {Promise<Object>} Creation confirmation
     */
    createTemplate: (templateData) => apiRequest('templates.php', 'POST', templateData),

    /**
     * Delete a template
     * @param {string} type - Template type ('note' or 'page')
     * @param {string} name - Template name
     * @returns {Promise<Object>} Deletion confirmation
     */
    deleteTemplate: (type, name) => apiRequest(`templates.php?type=${type}&name=${name}`, 'DELETE')
};

// Export all API namespaces using ES6 export syntax
export {
    pagesAPI,
    notesAPI,
    propertiesAPI,
    attachmentsAPI,
    searchAPI,
    templatesAPI
};
