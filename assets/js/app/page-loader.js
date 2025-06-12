import {
    currentPageId,
    CACHE_MAX_AGE_MS,
    MAX_PREFETCH_PAGES,
    notesForCurrentPage,
    setCurrentPageId,
    setCurrentPageName,
    setNotesForCurrentPage,
    hasPageCache,
    getPageCache,
    setPageCache,
    deletePageCache,
    currentPageName
} from './state.js';

import { decrypt } from '../utils.js';
import { notesAPI, pagesAPI, searchAPI, queryAPI } from '../api_client.js';
import { handleAddRootNote } from './note-actions.js';
import { ui } from '../ui.js';

window.currentPageEncryptionKey = null;
window.decryptionPassword = null;

const notesContainer = document.querySelector('#notes-container');
const backlinksContainer = document.querySelector('#backlinks-container');

function getTodaysJournalPageName() {
    const today = new Date();
    return today.toISOString().split('T')[0];
}

export function getPreviousDayPageName(currentDateStr) {
    const date = new Date(currentDateStr);
    date.setDate(date.getDate() - 1);
    return date.toISOString().split('T')[0];
}

export function getNextDayPageName(currentDateStr) {
    const date = new Date(currentDateStr);
    date.setDate(date.getDate() + 1);
    return date.toISOString().split('T')[0];
}

export function getInitialPage() {
    return getTodaysJournalPageName();
}

export async function handleTransclusions() {
    const placeholders = document.querySelectorAll('.transclusion-placeholder');
    if (placeholders.length === 0) return;

    for (const placeholder of placeholders) {
        const blockId = placeholder.dataset.blockRef;
        if (!blockId) continue;
        try {
            const note = await notesAPI.getNote(blockId);
            if (note && note.content) {
                ui.renderTransclusion(placeholder, note.content, blockId);
            } else {
                placeholder.textContent = 'Block not found.';
                placeholder.classList.add('error');
            }
        } catch (error) {
            console.error(`Error fetching transclusion for block ID ${blockId}:`, error);
            placeholder.textContent = 'Error loading block.';
            placeholder.classList.add('error');
        }
    }
}

function displayBacklinks(backlinksData) {
    if (!backlinksContainer) return;
    if (!Array.isArray(backlinksData) || backlinksData.length === 0) {
        backlinksContainer.innerHTML = '<p>No backlinks found.</p>';
        return;
    }
    const html = backlinksData.map(link => `
        <div class="backlink-item">
            <a href="#" class="page-link" data-page-name="${link.page_name}">${link.page_name}</a>
            <div class="backlink-snippet">${link.content_snippet || ''}</div>
        </div>
    `).join('');
    backlinksContainer.innerHTML = html;
}

async function displayChildPages(namespace) {
    const container = document.getElementById('child-pages-container');
    if (!container) return;

    try {
        const response = await fetch(`api/v1/child_pages.php?namespace=${encodeURIComponent(namespace)}`);
        if (!response.ok) {
            console.warn(`Could not fetch child pages for ${namespace}: ${response.statusText}`);
            container.innerHTML = '';
            return;
        }

        const result = await response.json();
        const childPages = result.data || [];
        
        if (childPages.length > 0) {
            container.innerHTML = '<h3>Child Pages</h3>';
            const list = document.createElement('ul');
            list.className = 'child-page-list';
            childPages.forEach(page => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = '#';
                
                const displayName = page.name.includes('/') ? page.name.substring(page.name.lastIndexOf('/') + 1) : page.name;
                link.textContent = displayName;

                link.className = 'child-page-link';
                link.dataset.pageName = page.name;
                link.onclick = (e) => {
                    e.preventDefault();
                    loadPage(page.name); 
                };
                item.appendChild(link);
                list.appendChild(item);
            });
            container.appendChild(list);
        } else {
            container.innerHTML = '';
        }
    } catch (error) {
        console.error('Error fetching or displaying child pages:', error);
        container.innerHTML = '';
    }
}

export async function handleSqlQueries() {
    const placeholders = document.querySelectorAll('.sql-query-placeholder');
    for (const placeholder of placeholders) {
        const sqlQuery = placeholder.dataset.sqlQuery;
        if (!sqlQuery) {
            placeholder.textContent = 'Error: No SQL query provided.';
            continue;
        }
        try {
            const result = await queryAPI.queryNotes(sqlQuery);
            const notesArray = result?.data || [];
            
            placeholder.innerHTML = '';
            if (notesArray.length === 0) {
                placeholder.textContent = 'Query returned no results.';
            } else {
                const resultsContainer = document.createElement('div');
                resultsContainer.className = 'note-children sql-query-results';
                const parentNestingLevel = ui.getNestingLevel(placeholder.closest('.note-item')) + 1;
                notesArray.forEach(noteData => {
                    resultsContainer.appendChild(ui.renderNote(noteData, parentNestingLevel));
                });
                placeholder.appendChild(resultsContainer);
                if (typeof feather !== 'undefined') feather.replace();
            }
        } catch (error) {
            console.error('Error fetching SQL query results:', error);
            placeholder.textContent = `Error: ${error.message}`;
        }
    }
}

async function handleCreateAndFocusFirstNote() {
    if (!currentPageId) return;
    await handleAddRootNote();
}

/**
 * Renders the main content of a page after data is fetched and processed.
 */
async function _renderPageContent(pageData, pageProperties, focusFirstNote) {
    ui.renderPageInlineProperties(pageProperties, ui.domRefs.pagePropertiesContainer);
    ui.displayNotes(notesForCurrentPage, pageData.id);
    ui.updateActivePageLink(pageData.name);

    const backlinkData = await searchAPI.getBacklinks(pageData.name);
    displayBacklinks(backlinkData.results || []);

    await handleTransclusions();
    await handleSqlQueries();
    
    if (notesForCurrentPage.length === 0 && pageData.id) {
        await handleCreateAndFocusFirstNote();
    } else if (focusFirstNote && notesContainer) {
        const firstNoteContent = notesContainer.querySelector('.note-content');
        if (firstNoteContent) ui.switchToEditMode(firstNoteContent);
    }

    await prefetchLinkedPagesData();
}

/**
 * Processes page data, handles history, and orchestrates rendering (including decryption).
 */
async function _processAndRenderPage(pageData, updateHistory, focusFirstNote) {
    console.log("Raw pageData:", pageData);
    console.log("Page Properties:", pageData.properties);
    setCurrentPageName(pageData.name);
    setCurrentPageId(pageData.id);
    setNotesForCurrentPage(pageData.notes || []);

    if (updateHistory) {
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('page', pageData.name);
        history.pushState({ pageName: pageData.name }, '', newUrl.toString());
    }

    ui.updatePageTitle(pageData.name);
    displayChildPages(pageData.name);
    if (ui.calendarWidget && ui.calendarWidget.setCurrentPage) ui.calendarWidget.setCurrentPage(pageData.name);
    
    const pageProperties = pageData.properties || {};
    console.log("Page Properties:", pageProperties);

    // --- DECRYPTION FLOW ---
    const isEncrypted = pageProperties.encrypted?.some(p => String(p.value).toLowerCase() === 'true');

    if (isEncrypted) {
        if (!window.decryptionPassword) {
            try {
                window.decryptionPassword = await ui.promptForPassword();
            } catch (error) {
                console.warn(error.message);
                ui.displayNotes([{ id: 'error', content: 'Decryption cancelled. Cannot display page content.' }], pageData.id);
                return; // Stop rendering
            }
        }

        // Decrypt notes content
        const decryptedNotes = (pageData.notes || []).map(note => {
            if (note.content) {
                const decryptedContent = decrypt(note.content, window.decryptionPassword);
                if (decryptedContent === null) {
                    // Decryption failed (e.g., wrong password or corrupted data)
                    return { ...note, content: '[DECRYPTION FAILED]' };
                }
                return { ...note, content: decryptedContent };
            }
            return note;
        });
        setNotesForCurrentPage(decryptedNotes);
    } else {
        // Clear password if the page is not encrypted
        window.decryptionPassword = null;
    }
    
    await _renderPageContent(pageData, pageProperties, focusFirstNote);
}

/**
 * Fetches page data from the network using a "get-then-create" strategy.
 * @param {string} pageName - The name of the page to fetch or create.
 * @returns {Promise<Object>} The full page data object including notes.
 */
async function _fetchPageFromNetwork(pageName) {
    let pageDetails;
    try {
        pageDetails = await pagesAPI.getPageByName(pageName);
    } catch (error) {
        console.warn(`Page "${pageName}" not found, attempting to create. Original error:`, error.message);
        try {
            pageDetails = await pagesAPI.createPage(pageName);
        } catch (createError) {
            console.error(`Fatal: Failed to CREATE page "${pageName}" after GET failed:`, createError);
            throw createError;
        }
    }

    if (!pageDetails || !pageDetails.id) {
        throw new Error(`Could not resolve page data with a valid ID for "${pageName}".`);
    }

    // --- THIS IS THE FIX ---
    // Explicitly pass the options object to getPageData to avoid ambiguity.
    const notesArray = await notesAPI.getPageData(pageDetails.id, { include_internal: false });
    const combinedPageData = { ...pageDetails, notes: Array.isArray(notesArray) ? notesArray : [] };

    setPageCache(pageName, { ...combinedPageData, timestamp: Date.now() });
    return combinedPageData;
}


/**
 * Loads a page and its notes, using cache if available. This is the main public function.
 */
export async function loadPage(pageName, focusFirstNote = false, updateHistory = true, providedPageData = null) {
    if (window.blockPageLoad) return;
    window.blockPageLoad = true;
    
    pageName = pageName || getInitialPage();

    try {
        let pageData = providedPageData;
        const cachedPage = getPageCache(pageName);

        if (!pageData && cachedPage && (Date.now() - cachedPage.timestamp < CACHE_MAX_AGE_MS)) {
            pageData = cachedPage;
        }
        
        if (!pageData) {
            if (notesContainer) notesContainer.innerHTML = '<p>Loading page...</p>';
            pageData = await _fetchPageFromNetwork(pageName);
        }
        
        await _processAndRenderPage(pageData, updateHistory, focusFirstNote);
    } catch (error) {
        console.error(`Error loading page ${pageName}:`, error);
        setCurrentPageName(`Error: ${pageName}`);
        setCurrentPageId(null);
        ui.updatePageTitle(currentPageName);
        if (notesContainer) notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
    } finally {
        window.blockPageLoad = false;
    }
}

/**
 * Fetches data for pages linked from the current page to improve navigation speed.
 */
export async function prefetchLinkedPagesData() {
    if (!notesForCurrentPage) return;
    const linkedPageNames = new Set();
    const pageLinkRegex = /\[\[([^\]]+)\]\]/g;

    notesForCurrentPage.forEach(note => {
        if (note.content) {
            [...note.content.matchAll(pageLinkRegex)].forEach(match => {
                const pageName = match[1].trim().split('|')[0].trim();
                if (pageName) linkedPageNames.add(pageName);
            });
        }
    });

    let prefetchCounter = 0;
    for (const name of linkedPageNames) {
        if (prefetchCounter >= MAX_PREFETCH_PAGES) break;
        if (name === currentPageName || hasPageCache(name)) continue;
        
        try {
            await _fetchPageFromNetwork(name); // This will fetch and cache
            prefetchCounter++;
        } catch (error) {
            console.warn(`[Prefetch] Failed to prefetch linked page ${name}:`, error.message);
        }
    }
}

/**
 * Pre-fetches data for the most recently updated pages.
 */
export async function prefetchRecentPagesData() {
    try {
        const { pages } = await pagesAPI.getPages({ sort_by: 'updated_at', sort_order: 'desc', per_page: MAX_PREFETCH_PAGES });
        for (const page of pages) {
            if (page.name === currentPageName || hasPageCache(page.name)) continue;
            try {
                await _fetchPageFromNetwork(page.name);
            } catch (error) {
                console.warn(`[Prefetch] Failed to prefetch recent page ${page.name}:`, error.message);
            }
        }
    } catch (error) {
        console.error('Error in prefetchRecentPagesData:', error);
    }
}

/**
 * Fetches and displays the page list in the sidebar.
 */
export async function fetchAndDisplayPages(activePageName) {
    try {
        const { pages } = await pagesAPI.getPages({ per_page: 20, sort_by: 'updated_at', sort_order: 'desc' });
        ui.updatePageList(pages, activePageName || currentPageName);
    } catch (error) {
        console.error('Error fetching pages for sidebar:', error);
        if (ui.domRefs.pageListContainer) {
            ui.domRefs.pageListContainer.innerHTML = '<li>Error loading pages.</li>';
        }
    }
}