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
        
        // console.log(`[apiRequest] Making ${method} request to: ${API_BASE_URL + endpoint}`);
        // if (body) {
        //     console.log('[apiRequest] Request body:', body);
        // }
        
        if (response.status === 204) {
            return undefined;
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}. Response: ${text}`);
            }
            throw new Error(`Invalid response format: expected JSON but got ${contentType || 'unknown content type'}. Response: ${text}`);
        }

        const data = await response.json();

        if (typeof data === 'string' && data.includes('<br />')) {
            console.error('PHP Error in response:', data);
            throw new Error('Server error: PHP error in response');
        }
        
        if (data && data.status === 'error') {
            let errorMessage = data.message || 'API request failed (status:error)';
            if (data.details) {
                errorMessage += ` Details: ${JSON.stringify(data.details)}`;
            }
            console.error('API Error (data.status === "error"):', errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
            throw new Error(errorMessage);
        }

        if (!response.ok) {
            let errorMessage = data?.message || data?.error?.message || data?.error || response.statusText;
            if (typeof errorMessage === 'object') {
                errorMessage = JSON.stringify(errorMessage);
            }
            console.error(`API Error HTTP (${response.status}):`, errorMessage);
            console.error('Full error response data:', JSON.stringify(data, null, 2));
            throw new Error(errorMessage);
        }
        
        // The API spec may return paginated data with 'data' and 'pagination' as siblings.
        // Or a single object with 'data'.
        // This structure allows the caller to handle either case.
        if (data.hasOwnProperty('pagination') && data.hasOwnProperty('data')) {
            return data;
        }

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
     * @param {boolean} [options.include_details=false] - Whether to include page properties and notes
     * @param {boolean} [options.include_internal=false] - Whether to include internal properties
     * @returns {Promise<{pages: Array, pagination: Object|null}>} Object with pages array and pagination info
     */
    getPages: async (options = {}) => {
        const params = new URLSearchParams();
        if (options.excludeJournal) params.append('exclude_journal', '1');
        if (options.followAliases === false) params.append('follow_aliases', '0');
        if (options.include_details) params.append('include_details', '1');
        if (options.include_internal) params.append('include_internal', '1');
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        if (options.sort_by) params.append('sort_by', options.sort_by);
        if (options.sort_order) params.append('sort_order', options.sort_order);
        
        const queryString = params.toString();
        try {
            const response = await apiRequest(`pages.php${queryString ? '?' + queryString : ''}`);
            
            // Handle paginated vs non-paginated responses
            if (response && response.pagination && Array.isArray(response.data)) {
                return { pages: response.data, pagination: response.pagination };
            } else if (Array.isArray(response)) {
                return { pages: response, pagination: null };
            } else {
                console.warn('[pagesAPI.getPages] Unexpected response format:', response);
                return { pages: [], pagination: null };
            }
        } catch (error) {
            console.error('[pagesAPI.getPages] Error fetching pages:', error);
            return { pages: [], pagination: null };
        }
    },

    /**
     * Get page by ID
     * @param {number} id - Page ID
     * @returns {Promise<Object>} Page object
     */
    getPageById: (id) => apiRequest(`pages.php?id=${id}`),

    /**
     * Get page by name
     * @param {string} name - Page name
     * @returns {Promise<Object>} Page object
     */
    getPageByName: (name) => apiRequest(`pages.php?name=${encodeURIComponent(name)}`),

    /**
     * Create a new page.
     * @param {string} pageName - The name for the new page.
     * @param {string|null} [content=null] - Optional initial content for the page.
     * @returns {Promise<Object>} Created page object.
     */
    createPage: (pageName, content = null) => {
        const payload = { name: pageName };
        if (content !== null) {
            payload.content = content;
        }
        return apiRequest('pages.php', 'POST', payload);
    },

    /**
     * Update a page's name or content.
     * @param {number} id - Page ID
     * @param {{name?: string, content?: string}} pageData - Updated page data
     * @returns {Promise<Object>} Updated page object
     */
    updatePage: (id, pageData) => {
        const body = {
            _method: 'PUT',
            id,
            ...pageData
        };
        return apiRequest('pages.php', 'POST', body);
    },

    /**
     * Delete a page
     * @param {number} id - Page ID
     * @returns {Promise<Object>} Delete confirmation
     */
    deletePage: (id) => {
        return apiRequest('pages.php', 'POST', { _method: 'DELETE', id });
    }
};

/**
 * API functions for managing notes
 * @namespace notesAPI
 */
const notesAPI = {
    /**
     * Get full page data including notes.
     * @param {number} pageId - Page ID
     * @returns {Promise<Array<Object>>} Array of notes with their properties.
     */
    getPageData: (pageId) => apiRequest(`notes.php?page_id=${pageId}`),

    /**
     * Get a specific note
     * @param {number} noteId - Note ID
     * @returns {Promise<Object>} Note object
     */
    getNote: (noteId) => apiRequest(`notes.php?id=${noteId}`),

    /**
     * Create a new note using the batch endpoint for consistency.
     * @param {{page_id: number, content: string, parent_note_id?: number|null, order_index: number, client_temp_id: string}} noteData
     * @returns {Promise<Object>} The result of the create operation from the batch response.
     */
    createNote: async (noteData) => {
        const operations = [{ type: 'create', payload: noteData }];
        const response = await notesAPI.batchUpdateNotes(operations);
        if (response && response.results && response.results[0] && response.results[0].status === 'success') {
            return response.results[0].note;
        }
        throw new Error(response?.results?.[0]?.error_message || 'Failed to create note.');
    },

    /**
     * Update a note using the batch endpoint for consistency.
     * @param {number} noteId - Note ID
     * @param {Object} noteUpdateData - Updated note data.
     * @returns {Promise<Object>} The updated note object from the server.
     */
    updateNote: async (noteId, noteUpdateData) => {
        const payload = { id: noteId, ...noteUpdateData };
        const operations = [{ type: 'update', payload }];
        const response = await notesAPI.batchUpdateNotes(operations);
        const result = response?.results?.[0];

        if (result?.status === 'success' && result.note) {
            return result.note;
        }
        throw new Error(result?.error_message || `Failed to update note ${noteId}.`);
    },

    /**
     * Delete a note using the batch endpoint for consistency.
     * @param {number} noteId - Note ID
     * @returns {Promise<Object>} The result of the delete operation from the batch response.
     */
    deleteNote: async (noteId) => {
        const operations = [{ type: 'delete', payload: { id: noteId } }];
        const response = await notesAPI.batchUpdateNotes(operations);
        const result = response?.results?.[0];
        if (result?.status === 'success') {
            return result;
        }
        throw new Error(result?.error_message || `Failed to delete note ${noteId}.`);
    },

    /**
     * Perform batch operations on notes.
     * @param {Array<Object>} operations - Array of operations {type, payload}.
     * @returns {Promise<Object>} A promise that resolves to the full batch response object.
     */
    batchUpdateNotes: (operations) => {
        const body = { action: 'batch', operations };
        return apiRequest('notes.php', 'POST', body);
    }
};

/**
 * API functions for reading properties (write operations are deprecated)
 * @namespace propertiesAPI
 */
const propertiesAPI = {
    /**
     * Get properties for an entity (read-only)
     * @param {string} entityType - Entity type ('note' or 'page')
     * @param {number} entityId - Entity ID
     * @returns {Promise<Object>} Properties object.
     */
    getProperties: (entityType, entityId) => {
        const params = new URLSearchParams({
            entity_type: entityType,
            entity_id: entityId.toString()
        });
        return apiRequest(`properties.php?${params.toString()}`);
    }
};

/**
 * API functions for managing attachments
 * @namespace attachmentsAPI
 */
const attachmentsAPI = {
    /**
     * Get attachments for a specific note
     * @param {number} noteId - Note ID
     * @returns {Promise<Array>} Array of attachment objects
     */
    getNoteAttachments: (noteId) => apiRequest(`attachments.php?note_id=${noteId}`),

    /**
     * Upload a new attachment
     * @param {FormData} formData - FormData object containing note_id and attachmentFile
     * @returns {Promise<Object>} Created attachment object
     */
    uploadAttachment: (formData) => apiRequest('attachments.php', 'POST', formData),

    /**
     * Delete an attachment
     * @param {number} attachmentId - Attachment ID
     * @returns {Promise<Object>} Delete confirmation
     */
    deleteAttachment: (attachmentId) => {
        return apiRequest('attachments.php', 'POST', { _method: 'DELETE', id: attachmentId });
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
     * @param {Object} [options={}] - Pagination options
     * @returns {Promise<Object>} Response object with results and pagination
     */
    search: (query, options = {}) => {
        const params = new URLSearchParams({ q: query });
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        return apiRequest(`search.php?${params.toString()}`);
    },

    /**
     * Get backlinks for a page
     * @param {string} pageName - Page name
     * @returns {Promise<Array>} Array of backlink objects
     */
    getBacklinks: (pageName) => apiRequest(`search.php?backlinks_for_page_name=${encodeURIComponent(pageName)}`)
};

/**
 * API functions for managing templates
 * @namespace templatesAPI
 */
const templatesAPI = {
    /**
     * Get available templates
     * @param {string} type - Template type ('note' or 'page')
     * @returns {Promise<Array>} Array of template objects.
     */
    getTemplates: (type) => apiRequest(`templates.php?type=${type}`),

    /**
     * Create a new template
     * @param {{type: string, name: string, content: string}} templateData - Template data
     * @returns {Promise<Object>} Creation confirmation
     */
    createTemplate: (templateData) => apiRequest('templates.php', 'POST', { _method: 'POST', ...templateData }),

    /**
     * Delete a template
     * @param {string} type - Template type ('note' or 'page')
     * @param {string} name - Template name
     * @returns {Promise<Object>} Deletion confirmation
     */
    deleteTemplate: (type, name) => apiRequest('templates.php', 'POST', { _method: 'DELETE', type, name })
};

/**
 * API functions for custom queries
 * @namespace queryAPI
 */
const queryAPI = {
    /**
     * Executes a custom SQL query for notes.
     * @param {string} sqlQuery - The SQL query string.
     * @param {Object} [options={}] - Optional parameters for pagination.
     * @returns {Promise<Object>} Object containing 'data' (array of notes) and 'pagination' info.
     */
    queryNotes: (sqlQuery, options = {}) => {
        const body = {
            sql_query: sqlQuery,
            page: options.page || 1,
            per_page: options.per_page || 10
        };
        return apiRequest('query_notes.php', 'POST', body);
    }
};

export {
    pagesAPI,
    notesAPI,
    propertiesAPI,
    attachmentsAPI,
    searchAPI,
    templatesAPI,
    queryAPI
};