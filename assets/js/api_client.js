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

        // Check for API-level errors
        if (!response.ok) {
            const errorMessage = data.error || response.statusText;
            console.error(`API Error (${response.status}):`, errorMessage);
            throw new Error(errorMessage);
        }

        // Check for API success flag
        if (data.success === false) {
            console.error('API returned success=false:', data.error);
            throw new Error(data.error || 'API request failed');
        }

        console.log('[apiRequest] Response successful. Full data object:', JSON.parse(JSON.stringify(data)));
        console.log('[apiRequest] Returning data.data:', data.data);
        return data.data;
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
     * @returns {Promise<Array<{id: number, name: string, created_at: string, updated_at: string}>>}
     */
    getAllPages: () => apiRequest('pages.php'),

    /**
     * Get a page by name or ID
     * @param {string|number} identifier - Page name or ID
     * @returns {Promise<{id: number, name: string, created_at: string, updated_at: string}>}
     */
    getPage: (identifier) => apiRequest(`pages.php?${typeof identifier === 'number' ? 'id' : 'name'}=${encodeURIComponent(identifier)}`),

    /**
     * Create a new page
     * @param {{name: string}} pageData - Page data
     * @returns {Promise<{id: number, name: string, created_at: string, updated_at: string}>}
     */
    createPage: (pageData) => apiRequest('pages.php', 'POST', pageData),

    /**
     * Update a page
     * @param {number} pageId - Page ID
     * @param {{name: string}} pageData - Updated page data
     * @returns {Promise<{id: number, name: string, created_at: string, updated_at: string}>}
     */
    updatePage: (pageId, pageData) => {
        const bodyWithMethodOverride = {
            ...pageData,
            _method: 'PUT'
        };
        return apiRequest(`pages.php?id=${pageId}`, 'POST', bodyWithMethodOverride);
    },

    /**
     * Delete a page
     * @param {number} pageId - Page ID
     * @returns {Promise<null>}
     */
    deletePage: (pageId) => {
        const bodyWithMethodOverride = {
            _method: 'DELETE'
        };
        return apiRequest(`pages.php?id=${pageId}`, 'POST', bodyWithMethodOverride);
    }
};

/**
 * API functions for managing notes
 * @namespace notesAPI
 */
const notesAPI = {
    /**
     * Get notes for a page
     * @param {number} pageId - Page ID
     * @returns {Promise<Array<{id: number, content: string, page_id: number, created_at: string, updated_at: string, properties: Object}>>}
     */
    getNotesForPage: (pageId) => apiRequest(`notes.php?page_id=${pageId}`),

    /**
     * Get a specific note
     * @param {number} noteId - Note ID
     * @returns {Promise<{id: number, content: string, page_id: number, created_at: string, updated_at: string, properties: Object}>}
     */
    getNote: (noteId) => apiRequest(`notes.php?id=${noteId}`),

    /**
     * Create a new note
     * @param {{page_id: number, content: string}} noteData - Note data
     * @returns {Promise<{id: number, content: string, page_id: number, created_at: string, updated_at: string}>}
     */
    createNote: (noteData) => apiRequest('notes.php', 'POST', noteData),

    /**
     * Update a note
     * @param {number} noteId - Note ID
     * @param {{content: string}} noteData - Updated note data
     * @returns {Promise<{id: number, content: string, page_id: number, created_at: string, updated_at: string}>}
     */
    updateNote: (noteId, noteData) => {
        const bodyWithMethodOverride = {
            ...noteData,
            _method: 'PUT' // Add _method parameter for tunneling
        };
        return apiRequest(`notes.php?id=${noteId}`, 'POST', bodyWithMethodOverride);
    },

    /**
     * Delete a note
     * @param {number} noteId - Note ID
     * @returns {Promise<null>}
     */
    deleteNote: (noteId) => {
        const bodyWithMethodOverride = {
            _method: 'DELETE'
        };
        return apiRequest(`notes.php?id=${noteId}`, 'POST', bodyWithMethodOverride);
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
     * @returns {Promise<Object<string, string>>} Properties as key-value pairs
     */
    getProperties: (entityType, entityId) => 
        apiRequest(`properties.php?entity_type=${entityType}&entity_id=${entityId}`),

    /**
     * Set a property
     * @param {{entity_type: string, entity_id: number, name: string, value: string}} propertyData - Property data
     * @returns {Promise<{id: number, entity_type: string, entity_id: number, name: string, value: string}>}
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
        const bodyWithMethodOverride = {
            _method: 'DELETE'
        };
        return apiRequest(`properties.php?entity_type=${entityType}&entity_id=${entityId}&name=${encodeURIComponent(propertyName)}`, 'POST', bodyWithMethodOverride);
    }
};

/**
 * API functions for managing attachments
 * @namespace attachmentsAPI
 */
const attachmentsAPI = {
    /**
     * Upload an attachment
     * @param {FormData} formData - FormData containing the file and metadata
     * @returns {Promise<{id: number, filename: string, mime_type: string, size: number, created_at: string}>}
     */
    uploadAttachment: (formData) => apiRequest('attachments.php', 'POST', formData),

    /**
     * Get attachments for a specific note
     * @param {number} noteId - Note ID
     * @returns {Promise<Array<{id: number, name: string, path: string, type: string, created_at: string}>>}
     */
    getAttachmentsForNote: (noteId) => apiRequest(`attachments.php?note_id=${noteId}`),

    /**
     * Delete an attachment
     * @param {number} attachmentId - Attachment ID
     * @returns {Promise<null>}
     */
    deleteAttachment: (attachmentId) => {
        const bodyWithMethodOverride = {
            _method: 'DELETE'
        };
        return apiRequest(`attachments.php?id=${attachmentId}`, 'POST', bodyWithMethodOverride);
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

// Export all API namespaces
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        pagesAPI,
        notesAPI,
        propertiesAPI,
        attachmentsAPI,
        searchAPI
    };
}