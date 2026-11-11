/**
 * What the app knows about the page that is open. Plain module state: one
 * page per document (navigation between pages is a normal page load).
 *
 * @module app/page-state
 */

export const page = {
    /** @type {number|null} */ id: null,
    /** @type {string} */ name: '',
    /** Page content: where page-level properties are written. */
    content: '',
    /** Indexed properties: name => [{value, internal}] */
    properties: {},
    /** Password of an encrypted page, kept in memory only while the page is open. */
    password: null,
};

export function setPage(data) {
    page.id = data.id;
    page.name = data.name;
    page.content = data.content || '';
    page.properties = data.properties && !Array.isArray(data.properties) ? data.properties : {};
}

export function pageProperty(name) {
    return page.properties[name]?.[0]?.value ?? null;
}

/** Settings handed over by the server in page.php. */
export const config = Object.freeze({
    renderInternalProperties: false,
    taskStates: ['TODO', 'DOING', 'DONE', 'SOMEDAY', 'WAITING', 'CANCELLED'],
    activeExtensions: [],
    ...(typeof window !== 'undefined' && window.NOTD_CONFIG ? window.NOTD_CONFIG : {}),
});
