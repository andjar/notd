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
            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}. Response: ${text}`);
            }
            throw new Error(`Invalid response format: expected JSON but got ${contentType || 'unknown content type'}. Response: ${text}`);
        }

        const data = await response.json();

        if (data && data.status === 'error' && data.message && data.message.includes('PHP Error:')) {
            throw new Error('Server error: PHP error in response');
        }
        
        if (data && data.status === 'error') {
            let errorMessage = data.message || 'API request failed (status:error)';
            if (data.details) {
                errorMessage += ` Details: ${JSON.stringify(data.details)}`;
            }
            throw new Error(errorMessage);
        }

        if (!response.ok) {
            let errorMessage = data?.message || data?.error?.message || data?.error || response.statusText;
            if (typeof errorMessage === 'object') {
                errorMessage = JSON.stringify(errorMessage);
            }
            throw new Error(errorMessage);
        }
        
        // Per API Spec, all successful responses have a `data` key.
        // This function now consistently returns ONLY the content of the `data` key.
        return data.data;

    } catch (error) {
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
            
            return { pages: [], pagination: null };

        } catch (error) {
            return { pages: [], pagination: null };
        }
    },
    getPageById: (id) => apiRequest(`pages.php?id=${id}`),
    getPageByName: (name) => apiRequest(`pages.php?name=${encodeURIComponent(name)}`),
    /**
     * Get child pages for a namespace
     * @param {string} namespace - The namespace to get child pages for
     * @returns {Promise<Array>} Array of child page objects
     */
    getChildPages: (namespace) => apiRequest(`child_pages.php?namespace=${encodeURIComponent(namespace)}`),
    /**
     * Get recent pages from server
     * @returns {Promise<Array>} Array of recent page objects
     */
    getRecentPages: () => apiRequest('recent_pages.php'),
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
    deleteAttachment: (attachmentId, noteId) => {
        return apiRequest('attachments.php', 'POST', {
            action: 'delete',
            attachment_id: attachmentId,
            note_id: noteId
        });
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
        if (options.includeParentProps !== undefined) params.append('include_parent_properties', options.includeParentProps ? '1' : '0');
        return apiRequest(`search.php?${params.toString()}`);
    },
    getBacklinks: (pageName, options = {}) => {
        const params = new URLSearchParams({ backlinks_for_page_name: pageName });
        if (options.includeParentProps) params.append('include_parent_properties', '0');
        return apiRequest(`search.php?${params.toString()}`);
    },
    getTasks: (status, options = {}) => {
        const params = new URLSearchParams({ tasks: status });
        if (options.includeParentProps) params.append('include_parent_properties', '1');
        return apiRequest(`search.php?${params.toString()}`);
    },
    getFavorites: () => apiRequest('search.php?favorites=1')
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

/**
 * API functions for managing favorites
 * @namespace favoritesAPI
 */
const favoritesAPI = {
    getFavorites: () => apiRequest('favorites.php'),
    addFavorite: (pageName) => apiRequest('favorites.php', 'POST', { page_name: pageName }),
    removeFavorite: (pageName) => apiRequest('favorites.php', 'POST', { _method: 'DELETE', page_name: pageName })
};

export {
    pagesAPI,
    notesAPI,
    propertiesAPI,
    attachmentsAPI,
    searchAPI,
    templatesAPI,
    queryAPI,
    favoritesAPI,
    apiRequest
};