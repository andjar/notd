// assets/js/app/page-loader.js

import {
    setCurrentPageId,
    setCurrentPageName,
    setNotesForCurrentPage,
    hasPageCache,
    getPageCache,
    setPageCache,
    CACHE_MAX_AGE_MS,
    MAX_PREFETCH_PAGES,
    currentPageName,
    notesForCurrentPage,
    currentPageId,
    setCurrentPagePassword,
    getCurrentPagePassword
} from './state.js';

import { decrypt } from '../utils.js';
import { notesAPI, pagesAPI, searchAPI, queryAPI, apiRequest } from '../api_client.js';
import { handleAddRootNote } from './note-actions.js';
import { ui } from '../ui.js';
import { parseProperties, parseContent, cleanProperties } from '../utils/content-parser.js';
import { handleNoteAction } from './note-actions.js';
import { promptForPagePassword } from '../ui.js';

// --- Restored Utility Functions ---
export function getPreviousDayPageName(currentDateStr) {
    const date = new Date(currentDateStr + 'T12:00:00Z');
    date.setDate(date.getDate() - 1);
    return date.toISOString().split('T')[0];
}

export function getNextDayPageName(currentDateStr) {
    const date = new Date(currentDateStr + 'T12:00:00Z');
    date.setDate(date.getDate() + 1);
    return date.toISOString().split('T')[0];
}


// --- Feature Implementations (Restored) ---

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

async function displayBacklinks(pageName) {
    if (!ui.domRefs.backlinksContainer) {
        console.warn('Backlinks container DOM element not found.');
        return;
    }
    try {
        const response = await searchAPI.getBacklinks(pageName);
        const backlinksData = response.results || [];
        if (backlinksData.length === 0) {
            ui.domRefs.backlinksContainer.innerHTML = '<p>No backlinks found.</p>';
            return;
        }
        const html = backlinksData.map(link => `
            <div class="backlink-item">
                <a href="#" class="page-link" data-page-name="${link.page_name}">${link.page_name}</a>
                <div class="backlink-snippet">${link.content_snippet || ''}</div>
            </div>
        `).join('');
        ui.domRefs.backlinksContainer.innerHTML = html;
    } catch (error) {
        console.error("Error fetching backlinks:", error);
        ui.domRefs.backlinksContainer.innerHTML = '<p>Error loading backlinks.</p>';
    }
}

async function displayChildPages(namespace) {
    const container = document.getElementById('child-pages-container');
    if (!container) return;
    try {
        const childPages = await apiRequest(`child_pages.php?namespace=${encodeURIComponent(namespace)}`);
        
        container.innerHTML = '';
        if (childPages && childPages.length > 0) {
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
                item.appendChild(link);
                list.appendChild(item);
            });
            container.appendChild(list);
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
            const notesArray = result || [];
            
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

// --- Page Loading and Rendering ---

async function handleCreateAndFocusFirstNote() {
    if (!currentPageId) return;
    await handleAddRootNote();
}

async function _renderPageContent(pageData, pageProperties, focusFirstNote) {
    const pageContentDiv = ui.domRefs.pageContent;
    const pageTitleDiv = ui.domRefs.pageTitle;
    const pagePropertiesContainer = ui.domRefs.pagePropertiesContainer;

    if (!pageContentDiv || !pageTitleDiv || !pagePropertiesContainer) return;

    // Set page title
    pageTitleDiv.textContent = pageData.name;

    // Render properties
    ui.renderPageInlineProperties(pageProperties, pagePropertiesContainer);

    // Render main content
    const contentWithoutProperties = cleanProperties(pageData.content);
    pageContentDiv.innerHTML = parseContent(contentWithoutProperties); // Renders non-note content

    // Render notes
    if (pageData.notes && pageData.notes.length > 0) {
        let notesToRender = pageData.notes;
        const pageEncryptProperty = pageProperties.encrypt && Array.isArray(pageProperties.encrypt) && pageProperties.encrypt.length > 0
            ? pageProperties.encrypt[0]
            : null;

        if (pageEncryptProperty) {
            let password = getCurrentPagePassword();
            const pageHash = pageEncryptProperty.value;

            if (!password) {
                try {
                    password = await promptForPagePassword();
                    const passwordHash = sjcl.codec.hex.fromBits(sjcl.hash.sha256.hash(password));

                    if (passwordHash !== pageHash) {
                        alert("Wrong password!");
                        setCurrentPagePassword(null);
                        pageContentDiv.innerHTML = '<p>Decryption failed: Incorrect password.</p>';
                        setNotesForCurrentPage([]);
                        ui.displayNotes([], currentPageId);
                        return; // Stop rendering
                    }
                    setCurrentPagePassword(password);
                } catch (error) {
                    console.error("Password prompt cancelled or failed.", error);
                    pageContentDiv.innerHTML = '<p>Password entry cancelled. Cannot display page.</p>';
                    setNotesForCurrentPage([]);
                    ui.displayNotes([], currentPageId);
                    return; // Stop rendering
                }
            }

            const currentPassword = getCurrentPagePassword();
            const currentPasswordHash = currentPassword ? sjcl.codec.hex.fromBits(sjcl.hash.sha256.hash(currentPassword)) : null;

            if (currentPasswordHash !== pageHash) {
                setCurrentPagePassword(null);
                pageContentDiv.innerHTML = '<p>Decryption failed: Stored password is incorrect for this page. Please reload.</p>';
                setNotesForCurrentPage([]);
                ui.displayNotes([], currentPageId);
                return;
            }

            // Decrypt notes
            try {
                notesToRender = notesToRender.map(note => {
                    if (note.is_encrypted) {
                        return { ...note, content: decrypt(currentPassword, note.content) };
                    }
                    return note;
                });
            } catch (error) {
                console.error("Decryption failed for one or more notes:", error);
                alert("Decryption failed. The password may be incorrect or the data corrupted.");
                setCurrentPagePassword(null);
                pageContentDiv.innerHTML = '<p>Decryption failed. Data may be corrupted.</p>';
                setNotesForCurrentPage([]);
                ui.displayNotes([], currentPageId);
                return;
            }
        }
        
        setNotesForCurrentPage(notesToRender);
        ui.displayNotes(notesToRender, currentPageId);
        if (focusFirstNote && pageData.notes[0]) {
            handleNoteAction('focus', pageData.notes[0].id);
        }
    } else {
        setNotesForCurrentPage([]);
        ui.displayNotes([], currentPageId); // Clear notes view
        if (focusFirstNote) {
            await handleNoteAction('create', null);
        }
    }

    displayBacklinks(pageData.name);
    displayChildPages(pageData.name);
    handleTransclusions();
    handleSqlQueries();
    
    prefetchLinkedPagesData();
}

async function _processAndRenderPage(pageData, updateHistory, focusFirstNote) {
    setCurrentPageName(pageData.name);
    setCurrentPageId(pageData.id);
    setNotesForCurrentPage(pageData.notes || []);
    setCurrentPagePassword(null); // Reset password for new page

    if (updateHistory) {
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('page', pageData.name);
        history.pushState({ pageName: pageData.name }, '', newUrl.toString());
    }

    ui.updatePageTitle(pageData.name);
    if (ui.calendarWidget && ui.calendarWidget.setCurrentPage) ui.calendarWidget.setCurrentPage(pageData.name);
    
    const pageProperties = pageData.properties || {};
    
    await _renderPageContent(pageData, pageProperties, focusFirstNote);
}

async function _fetchPageFromNetwork(pageName) {
    let pageDetails;
    try {
        pageDetails = await pagesAPI.getPageByName(pageName);
    } catch (error) {
        if (String(error.message).includes('404')) {
             console.warn(`Page "${pageName}" not found, creating it.`);
             pageDetails = await pagesAPI.createPage(pageName);
        } else {
            console.error(`Fatal: Failed to GET page "${pageName}":`, error);
            throw error;
        }
    }

    if (!pageDetails || !pageDetails.id) {
        throw new Error(`Could not resolve page data with a valid ID for "${pageName}".`);
    }

    const pageDataWithNotes = await notesAPI.getPageData(pageDetails.id);

    // **FIX**: The previous code was incorrectly combining the pageDetails object and the notes array.
    // The notes array from the API needs to be assigned to the `notes` property of the page data object.
    const combinedPageData = { ...pageDetails, notes: pageDataWithNotes };

    // This check is now mostly for safety, as `notes` is explicitly assigned above.
    combinedPageData.notes = Array.isArray(combinedPageData.notes) ? combinedPageData.notes : [];
    
    setPageCache(pageName, { ...combinedPageData, timestamp: Date.now() });
    return combinedPageData;
}

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
            if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = '<p>Loading page...</p>';
            pageData = await _fetchPageFromNetwork(pageName);
        }
        
        await _processAndRenderPage(pageData, updateHistory, focusFirstNote);
    } catch (error) {
        console.error(`Error loading page ${pageName}:`, error);
        setCurrentPageName(`Error: ${pageName}`);
        setCurrentPageId(null);
        ui.updatePageTitle(currentPageName);
        if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
    } finally {
        window.blockPageLoad = false;
    }
}

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
            await _fetchPageFromNetwork(name);
        } catch (error) {
            console.warn(`[Prefetch] Failed to prefetch linked page ${name}:`, error.message);
        }
    }
}

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

export function getInitialPage() {
    return new Date().toISOString().split('T')[0];
}