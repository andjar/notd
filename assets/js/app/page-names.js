/**
 * The names of all pages, fetched once per page view when first needed
 * (link suggestions, the page finder).
 *
 * @module app/page-names
 */

import { pagesAPI } from '../api_client.js';

let request = null;

/** @returns {Promise<string[]>} */
export function pageNames() {
    if (!request) {
        request = pagesAPI.names().catch((error) => {
            request = null; // try again next time
            console.warn('[pages] could not load page names', error);
            return [];
        });
    }
    return request;
}

/** Ranks `names` for a typed `query`: exact, then prefix, then substring matches. */
export function matchNames(names, query, limit = 10) {
    const q = query.trim().toLowerCase();
    if (!q) return names.slice(0, limit);
    const exact = [];
    const prefix = [];
    const inside = [];
    for (const name of names) {
        const lower = name.toLowerCase();
        if (lower === q) exact.push(name);
        else if (lower.startsWith(q) || lower.includes('/' + q)) prefix.push(name);
        else if (lower.includes(q)) inside.push(name);
        if (prefix.length >= limit && exact.length) break;
    }
    return [...exact, ...prefix, ...inside].slice(0, limit);
}
