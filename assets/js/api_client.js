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
        
        // Per API Spec, all successful responses have a `data` key.
        // This function now consistently returns ONLY the content of the `data` key.
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
            // apiRequest returns the `data` object, which for this endpoint contains `data` (the array) and `pagination`.
            const responsePayload = await apiRequest(`pages.php${queryString ? '?' + queryString : ''}`);
            
            if (responsePayload && Array.isArray(responsePayload.data) && responsePayload.pagination) {
                return { pages: responsePayload.data, pagination: responsePayload.pagination };
            }
            if (Array.isArray(responsePayload)) { // Fallback for non-paginated
                 return { pages: responsePayload, pagination: null };
            }
            
            console.warn('[pagesAPI.getPages] Unexpected response format:', responsePayload);
            return { pages: [], pagination: null };

        } catch (error) {
            console.error('[pagesAPI.getPages] Error fetching pages:', error);
            return { pages: [], pagination: null };
        }
    },
    getPageById: (id) => apiRequest(`pages.php?id=${id}`),
    getPageByName: (name) => apiRequest(`pages.php?name=${encodeURIComponent(name)}`),
    createPage: (pageName, content = null) => {
        const payload = { name: pageName };
        if (content !== null) {
            payload.content = content;
        }
        return apiRequest('pages.php', 'POST', payload);
    },
    updatePage: (id, pageData) => {
        const body = { _method: 'PUT', id, ...pageData };
        return apiRequest('pages.php', 'POST', body);
    },
    deletePage: (id) => {
        return apiRequest('pages.php', 'POST', { _method: 'DELETE', id });
    }
};

/**
 * API functions for managing notes
 * @namespace notesAPI
 */
const notesAPI = {
    getPageData: (pageId) => apiRequest(`notes.php?page_id=${pageId}`),
    getNote: (noteId) => apiRequest(`notes.php?id=${noteId}`),
    batchUpdateNotes: (operations) => {
        const body = { action: 'batch', operations };
        return apiRequest('notes.php', 'POST', body);
    },
    // The individual create, update, delete wrappers were removed as they were causing confusion.
    // All note modifications should go through note-actions.js which correctly uses the batch endpoint.
};

/**
 * API functions for properties
 * @namespace propertiesAPI
 */
const propertiesAPI = {
    getProperties: (entityType, entityId) => {
        const params = new URLSearchParams({ entity_type: entityType, entity_id: entityId.toString() });
        return apiRequest(`properties.php?${params.toString()}`);
    }
};

/**
 * API functions for managing attachments
 * @namespace attachmentsAPI
 */
const attachmentsAPI = {
    getNoteAttachments: (noteId) => apiRequest(`attachments.php?note_id=${noteId}`),
    uploadAttachment: (formData) => apiRequest('attachments.php', 'POST', formData),
    deleteAttachment: (attachmentId) => {
        return apiRequest('attachments.php', 'POST', { _method: 'DELETE', id: attachmentId });
    }
};

/**
 * API functions for search operations
 * @namespace searchAPI
 */
const searchAPI = {
    search: (query, options = {}) => {
        const params = new URLSearchParams({ q: query });
        if (options.page) params.append('page', options.page.toString());
        if (options.per_page) params.append('per_page', options.per_page.toString());
        return apiRequest(`search.php?${params.toString()}`);
    },
    getBacklinks: (pageName) => apiRequest(`search.php?backlinks_for_page_name=${encodeURIComponent(pageName)}`)
};

/**
 * API functions for managing templates
 * @namespace templatesAPI
 */
const templatesAPI = {
    getTemplates: (type) => apiRequest(`templates.php?type=${type}`),
    createTemplate: (templateData) => apiRequest('templates.php', 'POST', { _method: 'POST', ...templateData }),
    deleteTemplate: (type, name) => apiRequest('templates.php', 'POST', { _method: 'DELETE', type, name })
};

/**
 * API functions for custom queries
 * @namespace queryAPI
 */
const queryAPI = {
    queryNotes: (sqlQuery, options = {}) => {
        const body = { sql_query: sqlQuery, page: options.page || 1, per_page: options.per_page || 10 };
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