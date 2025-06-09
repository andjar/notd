/**
 * API Client for NotTD application
 * Provides functions for making asynchronous requests to the PHP backend API
 * @module api_client
 */

/**
 * Base URL for API requests, derived from <base> tag or defaulting to root
 * @type {string}
 */
const API_BASE_URL = (document.querySelector('base[href]')?.href || '/') + 'api/v1/';

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
        credentials: 'same-origin'
    };

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
        
        console.log(`[apiRequest] Making ${method} request to: ${API_BASE_URL + endpoint}`);
        if (body) {
            console.log('[apiRequest] Request body:', body);
        }
        
        if (response.status === 204) {
            // For 204 No Content, the API spec implies a success with no data to return.
            // The new spec usually has a JSON body like { status: "success", message: "..." } for these.
            // However, if a true 204 is returned, this is fine.
            return undefined; // Or return { status: "success", data: undefined } if consistency is desired
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            // If the response is not OK and not JSON, throw an error with the text.
            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}. Response: ${text}`);
            }
            // If response is OK but not JSON, this is unexpected based on spec.
            throw new Error(`Invalid response format: expected JSON but got ${contentType || 'unknown content type'}. Response: ${text}`);
        }

        const data = await response.json();

        if (typeof data === 'string' && data.includes('<br />')) {
            console.error('PHP Error in response:', data);
            throw new Error('Server error: PHP error in response');
        }

        // Primary error check based on new API spec (status: "error")
        // This also covers cases where HTTP status might be 200 but operation failed.
        if (data && data.status === 'error') {
            let errorMessage = data.message || 'API request failed (status:error)';
            if (data.details) { // Include details if available
                errorMessage += ` Details: ${JSON.stringify(data.details)}`;
            }
            console.error('API Error (data.status === "error"):', errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
            throw new Error(errorMessage);
        }

        // Handle HTTP errors (4xx, 5xx) that might not have the {status:"error"} body,
        // or if they do, the message extraction above would have caught it.
        if (!response.ok) {
            let errorMessage = data?.message || data?.error?.message || data?.error || response.statusText;
            if (typeof errorMessage === 'object') {
                errorMessage = JSON.stringify(errorMessage);
            }
            console.error(`API Error HTTP (${response.status}):`, errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
            throw new Error(errorMessage);
        }
        
        // If data.status is "success" or not present (for older endpoints not yet updated),
        // and response.ok is true, proceed.
        // The new spec always includes `status: "success"` on success.
        // It's good practice to check for data.status === "success" if all endpoints conform.
        // For now, not strictly enforcing data.status === "success" to maintain compatibility
        // if some old endpoints are called. The main change is handling data.status === "error".

        console.log('[apiRequest] Response successful. Full data object:', JSON.parse(JSON.stringify(data)));
        
        // The API spec states responses will be like:
        // { "status": "success", "data": { ... } }
        // OR { "status": "success", "message": "...", "data": { ... } } (e.g. Attachment upload)
        // OR { "status": "success", "message": "..." } (e.g. Attachment delete)
        // The function should return the content of the "data" field.
        // If "data" field is not present (like in attachment delete), it will return undefined, which is fine.
        return data.data;

    } catch (error) {
        console.error('[apiRequest] Error details:', { endpoint, method, error: error.message, stack: error.stack });
        if (error instanceof SyntaxError) {
            console.error('Failed to parse API response:', error);
            throw new Error('Invalid API response format: ' + error.message);
        }
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
    getPages: async (options = {}) => { // Added async
        const params = new URLSearchParams();
        if (options.excludeJournal) params.append('exclude_journal', '1');
        if (options.followAliases === false) params.append('follow_aliases', '0'); // Note: API spec default is 1 (true)
        if (options.include_details) params.append('include_details', '1');
        if (options.include_internal) params.append('include_internal', '1');
        
        // Add pagination and sorting parameters from options
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        if (options.sort_by) params.append('sort_by', options.sort_by);
        if (options.sort_order) params.append('sort_order', options.sort_order);
        
        const queryString = params.toString();
        const responseData = await apiRequest(`pages.php${queryString ? '?' + queryString : ''}`);

        // 1. Direct array check (ideal case per spec and apiRequest returning data.data)
        if (Array.isArray(responseData)) {
            return responseData;
        }

        // 2. Object checks for common deviations or nested structures
        if (responseData && typeof responseData === 'object') {
            // 2a. Handles if apiRequest returned { status, data: { pages: [...] } }
            // or if pages.php wrapped the array in a 'data' field like { data: [...] }
            if (responseData.data && Array.isArray(responseData.data)) {
                console.log('[pagesAPI.getPages] Found array in responseData.data');
                return responseData.data;
            }
            // 2b. Handles if pages.php wrapped the array in a 'pages' field like { pages: [...] }
            if (responseData.pages && Array.isArray(responseData.pages)) {
                console.log('[pagesAPI.getPages] Found array in responseData.pages');
                return responseData.pages;
            }

            // 3. Generic keysToTry loop as a further fallback
            const keysToTry = ['items', 'results']; // 'data' and 'pages' already checked
            for (const key of keysToTry) {
                if (responseData.hasOwnProperty(key) && Array.isArray(responseData[key])) {
                    console.log(`[pagesAPI.getPages] Found array in responseData.${key}`);
                    return responseData[key];
                }
            }
        }

        // 4. Warning and fallback
        console.warn('[pagesAPI.getPages] Response was not an array and no known data key was found. Response:', responseData);
        return []; 
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
     * Create a new page with a given name.
     * @param {string} pageName - The name for the new page.
     * @returns {Promise<Object>} Created page object (typically {id, name, title, ...})
     * @throws {Error} If pageName is not a non-empty string or if API request fails.
     */
    createPage: async (pageName) => {
        if (typeof pageName !== 'string' || pageName.trim() === '') {
            // apiRequest would likely reject or the API would return an error,
            // but explicit client-side check is good practice.
            return Promise.reject(new Error('Page name must be a non-empty string.'));
        }
        // apiRequest handles JSON.stringify for the body { name: pageName }
        // and returns a promise that resolves with the parsed JSON response data (data.data)
        // or rejects with an error.
        return apiRequest('pages.php', 'POST', { name: pageName });
    },

    /**
     * Update a page
     * @param {number} id - Page ID
     * @param {{name?: string, alias?: string, properties_explicit?: {}}} pageData - Updated page data
     * @returns {Promise<Object>} Updated page object
     */
    updatePage: (id, pageData) => {
        const body = {
            action: 'update',
            id: id,
            ...pageData // Should contain name, alias, or properties_explicit
        };
        // Remove undefined keys from pageData to keep payload clean
        Object.keys(body).forEach(key => {
            if (body[key] === undefined) {
                delete body[key];
            }
        });
        return apiRequest('pages.php', 'POST', body);
    },

    /**
     * Delete a page
     * @param {number} id - Page ID
     * @returns {Promise<Object>} Delete confirmation
     */
    deletePage: (id) => {
        const body = {
            action: 'delete',
            id: id
        };
        return apiRequest('pages.php', 'POST', body);
    }
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
        // Add pagination and sorting parameters for the notes list from options
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        if (options.sort_by) params.append('sort_by', options.sort_by); // e.g., 'created_at', 'order_index'
        if (options.sort_order) params.append('sort_order', options.sort_order); // 'asc' or 'desc'
        
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
        const body = {
            _method: 'PUT', // Add this line
            action: 'update', // This might be redundant if _method is PUT, but backend might use it
            id: noteId,
            ...noteUpdateData
        };
        // Remove any potential undefined fields that might have been explicitly passed
        Object.keys(body).forEach(key => {
            if (body[key] === undefined) {
                delete body[key];
            }
        });
        return apiRequest('notes.php', 'POST', body);
    },

    /**
     * Delete a note
     * @param {number} noteId - Note ID
     * @returns {Promise<null>}
     */
    deleteNote: (noteId) => {
        const body = {
            action: 'delete',
            id: noteId
        };
        return apiRequest('notes.php', 'POST', body);
    },

    /**
     * Batch update notes
     * @param {Array<Object>} operations - Array of operations
     * @returns {Promise<any>} API response data
     */
    batchUpdateNotes: (operations) => {
        const body = {
            action: 'batch',
            operations: operations
        };
        return apiRequest('notes.php', 'POST', body);
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
    getNoteAttachments: (noteId, options = {}) => {
        const params = new URLSearchParams();
        params.append('note_id', noteId.toString());

        // Add pagination, sorting, and filtering parameters from options
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        if (options.sort_by) params.append('sort_by', options.sort_by);
        if (options.sort_order) params.append('sort_order', options.sort_order);
        if (options.filter_by_name) params.append('filter_by_name', options.filter_by_name);
        if (options.filter_by_type) params.append('filter_by_type', options.filter_by_type);
        
        const queryString = params.toString();
        return apiRequest(`attachments.php?${queryString}`);
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
        const body = {
            action: 'delete',
            id: attachmentId
        };
        return apiRequest('attachments.php', 'POST', body);
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
    search: (query, options = {}) => {
        const params = new URLSearchParams();
        params.append('q', query); // query is already URI encoded by URLSearchParams
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        return apiRequest(`search.php?${params.toString()}`);
    },

    /**
     * Get backlinks for a page
     * @param {string} pageName - Page name
     * @returns {Promise<Array<{note_id: number, content: string, page_id: number, source_page_name: string, content_snippet: string}>>}
     */
    getBacklinks: async (pageName, options = {}) => { // Added async
        const params = new URLSearchParams();
        params.append('backlinks_for_page_name', pageName); // pageName is already URI encoded
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        const responseData = await apiRequest(`search.php?${params.toString()}`); // Added await and stored in responseData

        if (Array.isArray(responseData)) {
            return responseData;
        }

        if (responseData && typeof responseData === 'object') {
            const keysToTry = ['backlinks', 'items', 'results', 'data'];
            for (const key of keysToTry) {
                if (responseData.hasOwnProperty(key) && Array.isArray(responseData[key])) {
                    return responseData[key];
                }
            }
        }

        console.warn('[searchAPI.getBacklinks] Response was not an array and no known data key was found. Response:', responseData);
        return []; // Return empty array as a fallback
    },

    /**
     * Get tasks by status
     * @param {'todo'|'done'} status - Task status
     * @returns {Promise<Array<{note_id: number, content: string, page_id: number, page_name: string, content_snippet: string, properties: Object}>>}
     */
    getTasks: (status, options = {}) => {
        const params = new URLSearchParams();
        params.append('tasks', status);
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        return apiRequest(`search.php?${params.toString()}`);
    }
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
    getTemplates: (type, options = {}) => {
        const params = new URLSearchParams();
        params.append('type', type);
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        return apiRequest(`templates.php?${params.toString()}`);
    },

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
    deleteTemplate: (type, name) => {
        const body = {
            action: 'delete',
            type: type,
            name: name
        };
        return apiRequest('templates.php', 'POST', body);
    }
};

/**
 * API functions for custom queries
 * @namespace queryAPI
 */
const queryAPI = {
    /**
     * Executes a custom SQL query for notes.
     * @param {string} sqlQuery - The SQL query string.
     * @param {Object} [options={}] - Optional parameters.
     * @param {boolean} [options.include_properties=false] - Whether to include properties for each note.
     * @param {number} [options.page=1] - Page number for query results.
     * @param {number} [options.per_page=10] - Items per page for query results.
     * @returns {Promise<Object>} Object containing 'data' (array of notes) and 'pagination' info.
     *                            The apiRequest will return data.data, which itself is the array of notes.
     *                            The API spec says: { "status": "success", "data": [...notes...], "pagination": {...} }
     *                            So, apiRequest(data.data) would return [...notes...].
     *                            The original fetch call in handleSqlQueries expected result.data and result.success.
     *                            The new apiRequest handles success/error and returns the payload.
     *                            If the API returns { status: "success", "data": [], "pagination": {} },
     *                            apiRequest will return the array `[]`. The pagination info is part of the full `data` object.
     *                            For this specific case, we might want the whole {data, pagination} structure.
     *                            However, to keep apiRequest consistent (returning data.data),
     *                            the caller of queryNotes (handleSqlQueries) will receive just the array of notes.
     *                            If pagination is needed from query_notes.php, apiRequest would need adjustment,
     *                            OR queryNotes would need to use fetch directly (undesirable),
     *                            OR the backend for query_notes.php needs to return { data: { notes: [], pagination: {} } }.
     *                            Assuming the spec means "data" field contains the array of notes, and pagination is a sibling.
     *                            The current apiRequest returns data.data. So, if the response is
     *                            { "status": "success", "data": [note1, note2], "pagination": {...} },
     *                            then apiRequest returns [note1, note2].
     *                            The original handleSqlQueries was: `const result = await response.json(); if (result.success && result.data)`
     *                            This implies result.data was the array. So this should be fine.
     */
    queryNotes: (sqlQuery, options = {}) => {
        const body = {
            sql_query: sqlQuery,
            include_properties: options.include_properties || false,
            page: options.page || 1,
            per_page: options.per_page || 10
        };
        return apiRequest('query_notes.php', 'POST', body);
    }
};

// Export all API namespaces using ES6 export syntax
export {
    pagesAPI,
    notesAPI,
    propertiesAPI,
    attachmentsAPI,
    searchAPI,
    templatesAPI,
    queryAPI
};
