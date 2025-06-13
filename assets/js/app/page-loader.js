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
    // Only try to display backlinks if the container exists
    if (!ui.domRefs.backlinksContainer) {
        return; // Silently return if container doesn't exist
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
        if (ui.domRefs.backlinksContainer) {
            ui.domRefs.backlinksContainer.innerHTML = '<p>Error loading backlinks.</p>';
        }
    }
}

async function displayChildPages(namespace) {
    const container = document.getElementById('child-pages-container');
    if (!container) return;

    try {
        const childPages = await window.pagesAPI.getChildPages(namespace);
        container.innerHTML = '';
        
        if (childPages && childPages.length > 0) {
            container.style.display = 'block';
            container.innerHTML = '<h3>Child Pages</h3>';
            const list = document.createElement('ul');
            list.className = 'child-page-list';
            childPages.forEach(page => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = '#';
                const displayName = page.name.includes('/') ? 
                    page.name.substring(page.name.lastIndexOf('/') + 1) : 
                    page.name;
                link.textContent = displayName;
                link.className = 'child-page-link';
                link.dataset.pageName = page.name;
                item.appendChild(link);
                list.appendChild(item);
            });
            container.appendChild(list);
        } else {
            container.style.display = 'none';
        }
    } catch (error) {
        console.error('Error fetching or displaying child pages:', error);
        container.style.display = 'none';
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
    console.log('[DEBUG] Starting _renderPageContent with:', { 
        pageName: pageData.name, 
        hasNotes: Boolean(pageData.notes?.length),
        hasProperties: Boolean(pageProperties),
        focusFirstNote 
    });

    const pageContentDiv = ui.domRefs.pageContent;
    const pageTitleDiv = ui.domRefs.pageTitle;
    const pagePropertiesContainer = ui.domRefs.pagePropertiesContainer;

    if (!pageContentDiv || !pageTitleDiv || !pagePropertiesContainer) {
        console.error('[DEBUG] Missing required DOM elements:', { 
            hasPageContent: Boolean(pageContentDiv),
            hasPageTitle: Boolean(pageTitleDiv),
            hasPropertiesContainer: Boolean(pagePropertiesContainer)
        });
        return;
    }

    // Set page title
    pageTitleDiv.textContent = pageData.name;

    // Render properties
    ui.renderPageInlineProperties(pageProperties, pagePropertiesContainer);

    // Render main content - clean properties from content before rendering
    const contentWithoutProperties = pageData.content ? pageData.content.replace(/\{[^}]+\}/g, '').trim() : '';
    pageContentDiv.innerHTML = contentWithoutProperties ? parseContent(contentWithoutProperties) : '';

    // Update sidebars
    ui.displayFavorites();
    ui.displayBacklinksInSidebar(pageData.name);
    ui.displayChildPagesInSidebar(pageData.name);

    // Render notes
    if (pageData.notes && pageData.notes.length > 0) {
        console.log('[DEBUG] Processing notes for rendering:', { 
            noteCount: pageData.notes.length,
            hasEncryptProperty: Boolean(pageProperties.encrypt)
        });

        let notesToRender = pageData.notes;
        const pageEncryptProperty = pageProperties.encrypt && Array.isArray(pageProperties.encrypt) && pageProperties.encrypt.length > 0
            ? pageProperties.encrypt[0]
            : null;

        if (pageEncryptProperty) {
            console.log('[DEBUG] Page has encryption property:', { 
                hasValue: Boolean(pageEncryptProperty.value),
                valueLength: pageEncryptProperty.value?.length
            });

            let password = getCurrentPagePassword();
            const pageHash = pageEncryptProperty.value;

            if (!password) {
                console.log('[DEBUG] No stored password, prompting user');
                try {
                    password = await promptForPagePassword();
                    console.log('[DEBUG] Password received from prompt');
                    const passwordHash = sjcl.codec.hex.fromBits(sjcl.hash.sha256.hash(password));

                    if (passwordHash !== pageHash) {
                        console.error('[DEBUG] Password hash mismatch:', {
                            providedHash: passwordHash,
                            expectedHash: pageHash
                        });
                        alert("Wrong password!");
                        setCurrentPagePassword(null);
                        pageContentDiv.innerHTML = '<p>Decryption failed: Incorrect password.</p>';
                        setNotesForCurrentPage([]);
                        ui.displayNotes([], currentPageId);
                        return; // Stop rendering
                    }
                    console.log('[DEBUG] Password verified, setting current password');
                    setCurrentPagePassword(password);
                } catch (error) {
                    console.error("[DEBUG] Password prompt failed:", error);
                    pageContentDiv.innerHTML = '<p>Password entry cancelled. Cannot display page.</p>';
                    setNotesForCurrentPage([]);
                    ui.displayNotes([], currentPageId);
                    return; // Stop rendering
                }
            }

            const currentPassword = getCurrentPagePassword();
            const currentPasswordHash = currentPassword ? sjcl.codec.hex.fromBits(sjcl.hash.sha256.hash(currentPassword)) : null;

            if (currentPasswordHash !== pageHash) {
                console.error('[DEBUG] Stored password hash mismatch:', {
                    storedHash: currentPasswordHash,
                    expectedHash: pageHash
                });
                setCurrentPagePassword(null);
                pageContentDiv.innerHTML = '<p>Decryption failed: Stored password is incorrect for this page. Please reload.</p>';
                setNotesForCurrentPage([]);
                ui.displayNotes([], currentPageId);
                return;
            }

            // Decrypt notes
            console.log('[DEBUG] Starting note decryption');
            const decryptedNotes = [];
            let decryptionErrorOccurred = false;
            for (const note of notesToRender) {
                if (note.is_encrypted && note.content) {
                    try {
                        console.log('[DEBUG] Decrypting note:', { noteId: note.id });
                        const decryptedContent = decrypt(currentPassword, note.content);
                        decryptedNotes.push({ ...note, content: decryptedContent, is_encrypted: false });
                    } catch (e) {
                        console.error(`[DEBUG] Decryption failed for note ${note.id}:`, e);
                        decryptedNotes.push({ ...note, content: "[DECRYPTION FAILED]", is_corrupted: true });
                        decryptionErrorOccurred = true;
                    }
                } else {
                    decryptedNotes.push(note);
                }
            }
            notesToRender = decryptedNotes;
            console.log('[DEBUG] Note decryption complete:', { 
                totalNotes: notesToRender.length,
                decryptionErrors: decryptionErrorOccurred
            });
        }
        
        console.log('[DEBUG] Setting notes for current page and displaying');
        setNotesForCurrentPage(notesToRender);
        ui.displayNotes(notesToRender, currentPageId);
        if (focusFirstNote && pageData.notes[0]) {
            handleNoteAction('focus', pageData.notes[0].id);
        }
    } else {
        console.log('[DEBUG] No notes to render');
        setNotesForCurrentPage([]);
        ui.displayNotes([], currentPageId); // Clear notes view
        if (focusFirstNote) {
            await handleNoteAction('create', null);
        }
    }

    console.log('[DEBUG] Rendering additional page elements');
    displayBacklinks(pageData.name);
    displayChildPages(pageData.name);
    handleTransclusions();
    handleSqlQueries();
    
    console.log('[DEBUG] Starting prefetch of linked pages');
    prefetchLinkedPagesData();
    console.log('[DEBUG] _renderPageContent complete');

    if (notesForCurrentPage.length === 0 && pageData.id) {
        await handleCreateAndFocusFirstNote();
    } else if (focusFirstNote && ui.domRefs.notesContainer) {
        const firstNoteContent = ui.domRefs.notesContainer.querySelector('.note-content');
        if (firstNoteContent) ui.switchToEditMode(firstNoteContent);
    }
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
            console.log('[page-loader] Attempting to show loading message. notesContainer:', ui.domRefs.notesContainer);
            if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = '<p>Loading page...</p>';
            pageData = await _fetchPageFromNetwork(pageName);
        }
        
        await _processAndRenderPage(pageData, updateHistory, focusFirstNote);
    } catch (error) {
        console.error('[page-loader] Error object received:', error);
        console.error(`Error loading page ${pageName}:`, error);
        setCurrentPageName(`Error: ${pageName}`);
        setCurrentPageId(null);
        ui.updatePageTitle(currentPageName);
        console.log('[page-loader] Attempting to display error message. notesContainer:', ui.domRefs.notesContainer);
        try {
            if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = `<p>Error loading page: ${error.message}</p>`;
        } catch (innerError) {
            console.error("Failed to display specific error message:", innerError);
            if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = "<p>An error occurred while loading the page.</p>";
        }
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