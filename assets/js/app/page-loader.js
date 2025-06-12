import {
    setCurrentPageId,
    setCurrentPageName,
    setNotesForCurrentPage,
    hasPageCache,
    getPageCache,
    setPageCache,
    CACHE_MAX_AGE_MS,
    currentPageName as getCurrentPageName // Import for logging/comparison
} from './state.js';

import { notesAPI, pagesAPI, searchAPI, queryAPI } from '../api_client.js';

// This file no longer imports or uses the `ui` object.

function getTodaysJournalPageName() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export function getPreviousDayPageName(currentDateStr) {
    const [year, month, day] = currentDateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() - 1);
    const prevYear = date.getFullYear();
    const prevMonth = String(date.getMonth() + 1).padStart(2, '0');
    const prevDay = String(date.getDate()).padStart(2, '0');
    return `${prevYear}-${prevMonth}-${prevDay}`;
}

export function getNextDayPageName(currentDateStr) {
    const [year, month, day] = currentDateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + 1);
    const nextYear = date.getFullYear();
    const nextMonth = String(date.getMonth() + 1).padStart(2, '0');
    const nextDay = String(date.getDate()).padStart(2, '0');
    return `${nextYear}-${nextMonth}-${nextDay}`;
}

export function getInitialPage() {
    return getTodaysJournalPageName();
}

/**
 * Fetches page data from the network. It calls the API, which handles creation if the page doesn't exist.
 * @param {string} pageName - Name of the page to fetch.
 * @returns {Promise<Object>} A complete page data object.
 * @throws {Error} If the API call fails or returns invalid data.
 */
async function _fetchPageFromNetwork(pageName) {
    console.log(`[page-loader] Fetching '${pageName}' from network.`);
    // The API's getPageByName is robust: it gets or creates the page details.
    const pageDetails = await pagesAPI.getPageByName(pageName, { include_details: true });
    if (!pageDetails || !pageDetails.id) {
        throw new Error(`API returned invalid page details for "${pageName}".`);
    }

    // Now that we have a guaranteed page ID, fetch its notes.
    // The 'pages' API with include_details=true now returns notes, making this more efficient.
    const notes = pageDetails.notes || [];

    const combinedData = {
        ...pageDetails,
        notes: notes
    };

    // Cache the newly fetched data.
    setPageCache(combinedData.name, {
        ...combinedData,
        timestamp: Date.now()
    });

    console.log(`[page-loader] Fetched and cached '${pageName}'.`);
    return combinedData;
}

/**
 * The primary function to load a page. It checks cache, fetches from network if needed,
 * updates the global state, and returns the complete page data for rendering.
 * @param {string} pageNameParam - The name of the page to load.
 * @param {Object} [providedPageData=null] - Optional pre-fetched data.
 * @returns {Promise<Object|null>} The complete page data object, or null on critical failure.
 */
export async function fetchAndProcessPageData(pageNameParam, providedPageData = null) {
    const pageNameToLoad = pageNameParam || getInitialPage();
    console.log(`[page-loader] Processing data for page: ${pageNameToLoad}`);

    // 1. Resolve Data Source (Provided, Cache, or Network)
    let pageData;
    if (providedPageData && providedPageData.name === pageNameToLoad) {
        pageData = providedPageData;
        setPageCache(pageNameToLoad, { ...pageData, timestamp: Date.now() });
    } else if (hasPageCache(pageNameToLoad) && (Date.now() - getPageCache(pageNameToLoad).timestamp < CACHE_MAX_AGE_MS)) {
        pageData = getPageCache(pageNameToLoad);
        console.log(`[page-loader] Using cached data for: ${pageNameToLoad}`);
    } else {
        pageData = await _fetchPageFromNetwork(pageNameToLoad);
    }

    if (!pageData) {
        throw new Error(`Failed to obtain page data for ${pageNameToLoad} from any source.`);
    }

    // 2. Update Application State
    setCurrentPageId(pageData.id);
    setCurrentPageName(pageData.name);
    setNotesForCurrentPage(pageData.notes || []);

    // 3. Return the complete data for the controller (app.js) to handle UI rendering.
    return pageData;
}

// Functions like handleTransclusions, displayBacklinks, handleSqlQueries etc.
// should be moved to a different module (e.g., `ui-dynamic-content.js`) or be called
// from `app.js` after the main rendering is done. For now, let's assume `app.js` will handle them.
// We are keeping this file focused on *loading* the primary page data.
