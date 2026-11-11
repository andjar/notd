/**
 * The HTTP API, as functions. Every call returns the `data` of the response
 * or throws an Error whose message is the server's.
 *
 * Note writes do not go through here: they are queued by app/outbox.js and
 * sent to ops.php.
 *
 * @module api_client
 */

/** Resolved against the page, so notd works from any folder. */
export const API_BASE_URL = new URL('api/v1/', document.baseURI).href;

export async function apiRequest(endpoint, method = 'GET', body = null, { whole = false } = {}) {
    const options = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body instanceof FormData) {
        options.body = body;
    } else if (body !== null) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(API_BASE_URL + endpoint, options);
    const text = await response.text();
    let payload = null;
    try {
        payload = text ? JSON.parse(text) : null;
    } catch {
        throw new Error(`The server sent an unexpected answer (${response.status}).`);
    }
    if (!response.ok || !payload || payload.status === 'error') {
        const error = new Error(payload?.message || `Request failed (${response.status}).`);
        error.status = response.status;
        error.details = payload?.details;
        throw error;
    }
    return whole ? payload : payload.data;
}

const get = (endpoint) => apiRequest(endpoint);
const post = (endpoint, body) => apiRequest(endpoint, 'POST', body);
const query = (params) => {
    const q = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== null && value !== false) q.set(key, value === true ? '1' : String(value));
    }
    const s = q.toString();
    return s ? `?${s}` : '';
};

export const pagesAPI = {
    /** @returns {Promise<{pages: Array, pagination: Object}>} */
    async list({ page, perPage, excludeJournal } = {}) {
        const res = await apiRequest('pages.php' + query({ page, per_page: perPage, exclude_journal: excludeJournal }), 'GET', null, { whole: true });
        return { pages: res.data || [], pagination: res.pagination || null };
    },
    /** All page names; cheap, used for suggestions and the page finder. */
    names: ({ excludeJournal = false } = {}) => get('pages.php' + query({ names: 1, exclude_journal: excludeJournal })),
    /** Days of a month (YYYY-MM) that have a page: [{date, page_name}]. */
    datesInMonth: (month) => get('pages.php' + query({ month })),
    byId: (id) => get('pages.php' + query({ id })),
    /** Returns the page with this name, creating it if it does not exist. */
    byName: (name) => get('pages.php' + query({ name })),
    create: (name, content = null) => post('pages.php', { name, content }),
    /** `changes` may contain `content` and/or `name` (renaming rewrites links). */
    update: (id, changes) => post('pages.php', { _method: 'PUT', id, ...changes }),
    remove: (id) => post('pages.php', { _method: 'DELETE', id }),
    children: (namespace) => get('child_pages.php' + query({ namespace })),
    exportUrl: (name, download = true) => API_BASE_URL + 'export.php' + query({ page: name, download }),
};

export const notesAPI = {
    forPage: (pageId, { includeInternal = true } = {}) => get('notes.php' + query({ page_id: pageId, include_internal: includeInternal })),
    byId: (id, { includeInternal = true, children = false, parentProperties = false } = {}) =>
        get('notes.php' + query({ id, include_internal: includeInternal, include_children: children, include_parent_properties: parentProperties })),
    /** One batch of operations; see app/outbox.js. */
    /**
     * Sends operations to the write endpoint. Returns the raw Response: the
     * outbox tells "rejected" (4xx) from "try again" (network, 5xx) itself.
     */
    applyOps: (ops) => fetch(API_BASE_URL + 'ops.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ ops }),
    }),
};

export const attachmentsAPI = {
    forNote: (noteId) => get('attachments.php' + query({ note_id: noteId })),
    /** @returns {Promise<{attachments: Array, failed: Array}>} */
    upload: (formData) => post('attachments.php', formData),
    remove: (attachmentId) => post('attachments.php', { action: 'delete', attachment_id: attachmentId }),
};

export const searchAPI = {
    /** @returns {Promise<{results: Array, pagination: Object}>} */
    notes: (q, { parentProperties = false, perPage } = {}) =>
        get('search.php' + query({ q, include_parent_properties: parentProperties, per_page: perPage })),
    backlinks: (pageName, { parentProperties = false, perPage = 100 } = {}) =>
        get('search.php' + query({ backlinks_for_page_name: pageName, include_parent_properties: parentProperties, per_page: perPage })),
    tasks: (status, { perPage } = {}) => get('search.php' + query({ tasks: status, per_page: perPage })),
};

export const queryAPI = {
    /** @returns {Promise<{data: Array, pagination: Object}>} */
    async notes(sql, { page = 1, perPage = 50 } = {}) {
        const res = await apiRequest('query_notes.php', 'POST', { sql_query: sql, page, per_page: perPage }, { whole: true });
        return { data: res.data || [], pagination: res.pagination || null };
    },
};

export const templatesAPI = {
    /** Templates with date placeholders filled in for the browser's time zone. */
    list: (type = 'note') => get('templates.php' + query({ type, tz: Intl.DateTimeFormat().resolvedOptions().timeZone })),
};
