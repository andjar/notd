// assets/js/app/page-loader.js

// Get Alpine store reference
function getAppStore() {
    return window.Alpine.store('app');
}

import { decrypt } from '../utils.js';
import { notesAPI, pagesAPI, searchAPI, queryAPI, apiRequest } from '../api_client.js';
import { handleAddRootNote } from './note-actions.js';
import { ui } from '../ui.js';
import { parseProperties, parseContent, cleanProperties } from '../utils/content-parser.js';
import { handleNoteAction } from './note-actions.js';
import { promptForPagePassword } from '../ui.js';
import { pageCache } from './page-cache.js';
import { recentHistory } from './recent-history.js';
import { calendarCache } from './calendar-cache.js';

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
            // Fetch the note with all its children
            const noteWithChildren = await notesAPI.getNoteWithChildren(blockId);
            if (noteWithChildren) {
                await ui.renderTransclusion(placeholder, noteWithChildren, blockId, 0);
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
                <a href="page.php?page=${encodeURIComponent(link.page_name)}" class="page-link">${link.page_name}</a>
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
                link.href = `page.php?page=${encodeURIComponent(page.name)}`;
                const displayName = page.name.includes('/') ? 
                    page.name.substring(page.name.lastIndexOf('/') + 1) : 
                    page.name;
                link.textContent = displayName;
                link.className = 'child-page-link';
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
    const appStore = getAppStore();
    if (!appStore.currentPageId) return;
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

    if (!pageContentDiv || !pageTitleDiv) {
        console.error('[DEBUG] Missing required DOM elements:', { 
            hasPageContent: Boolean(pageContentDiv),
            hasPageTitle: Boolean(pageTitleDiv)
        });
        return;
    }

    // Page title is now set by PHP, no need to set it here

    // Properties are now rendered server-side in page.php, no need for client-side rendering

    // Render main content - clean properties from content before rendering
    const contentWithoutProperties = pageData.content ? pageData.content.replace(/\{[^}]+\}/g, '').trim() : '';
    if (contentWithoutProperties) {
        pageContentDiv.innerHTML = parseContent(contentWithoutProperties);
        pageContentDiv.style.display = 'block'; // Show the element when it has content
    } else {
        pageContentDiv.style.display = 'none'; // Hide the element when it's empty
    }

    // Update sidebars - disabled old DOM manipulation that conflicts with Alpine.js
    // ui.displayFavorites();
    // ui.displayBacklinksInSidebar(pageData.name);
    // ui.displayChildPagesInSidebar(pageData.name);

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

            const appStore = getAppStore();
            let password = appStore.pagePassword;
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
                        appStore.setPagePassword(null);
                        pageContentDiv.innerHTML = '<p>Decryption failed: Incorrect password.</p>';
                        appStore.setNotes([]);
                        ui.displayNotes([], appStore.currentPageId);
                        return; // Stop rendering
                    }
                    console.log('[DEBUG] Password verified, setting current password');
                    appStore.setPagePassword(password);
                } catch (error) {
                    console.error("[DEBUG] Password prompt failed:", error);
                    pageContentDiv.innerHTML = '<p>Password entry cancelled. Cannot display page.</p>';
                    appStore.setNotes([]);
                    ui.displayNotes([], appStore.currentPageId);
                    return; // Stop rendering
                }
            }

            const currentPassword = appStore.pagePassword;
            const currentPasswordHash = currentPassword ? sjcl.codec.hex.fromBits(sjcl.hash.sha256.hash(currentPassword)) : null;

            if (currentPasswordHash !== pageHash) {
                console.error('[DEBUG] Stored password hash mismatch:', {
                    storedHash: currentPasswordHash,
                    expectedHash: pageHash
                });
                appStore.setPagePassword(null);
                pageContentDiv.innerHTML = '<p>Decryption failed: Stored password is incorrect for this page. Please reload.</p>';
                appStore.setNotes([]);
                ui.displayNotes([], appStore.currentPageId);
                return;
            }

            // Decrypt notes
            console.log('[DEBUG] Starting note decryption');
            const decryptedNotes = [];
            let decryptionErrorOccurred = false;
            for (const note of notesToRender) {
                // Check if content is in encrypted JSON format
                const isEncryptedContent = typeof note.content === 'string' && 
                    note.content.startsWith('{') && 
                    note.content.includes('"iv"') && 
                    note.content.includes('"ct"');

                if ((note.is_encrypted || isEncryptedContent) && note.content) {
                    try {
                        console.log('[DEBUG] Before decryption:', { 
                            noteId: note.id, 
                            content: note.content,
                            isEncrypted: note.is_encrypted,
                            isEncryptedContent
                        });
                        const decryptedContent = decrypt(currentPassword, note.content);
                        console.log('[DEBUG] After decryption:', { 
                            noteId: note.id, 
                            decryptedContent,
                            isEncrypted: false 
                        });
                        decryptedNotes.push({ ...note, content: decryptedContent, is_encrypted: false });
                    } catch (e) {
                        console.error(`[DEBUG] Decryption failed for note ${note.id}:`, e);
                        decryptedNotes.push({ ...note, content: "[DECRYPTION FAILED]", is_corrupted: true });
                        decryptionErrorOccurred = true;
                    }
                } else {
                    console.log('[DEBUG] Note not encrypted:', { 
                        noteId: note.id, 
                        content: note.content,
                        isEncrypted: note.is_encrypted,
                        isEncryptedContent
                    });
                    decryptedNotes.push(note);
                }
            }
            notesToRender = decryptedNotes;
            console.log('[DEBUG] Note decryption complete:', { 
                totalNotes: notesToRender.length,
                decryptionErrors: decryptionErrorOccurred,
                sampleNote: notesToRender[0] // Log first note to see its state
            });
        }
        
        console.log('[DEBUG] Setting notes for current page and displaying');
        const appStore = getAppStore();
        appStore.setNotes(notesToRender);
        ui.displayNotes(notesToRender, appStore.currentPageId);
        if (focusFirstNote && pageData.notes[0]) {
            handleNoteAction('focus', pageData.notes[0].id);
        }
    } else {
        console.log('[DEBUG] No notes to render');
        const appStore = getAppStore();
        appStore.setNotes([]);
        ui.displayNotes([], appStore.currentPageId); // Clear notes view
        if (focusFirstNote) {
            await handleNoteAction('create', null);
        }
    }

    console.log('[DEBUG] Rendering additional page elements');
    // displayBacklinks(pageData.name); // Disabled - conflicts with Alpine.js
    // displayChildPages(pageData.name); // Disabled - conflicts with Alpine.js
    handleTransclusions();
    handleSqlQueries();
    
    // Handle URL anchor highlighting after notes are rendered
    handleUrlAnchor();
    
    console.log('[DEBUG] Starting prefetch of linked pages');
    prefetchLinkedPagesData();
    console.log('[DEBUG] _renderPageContent complete');

    const appStore = getAppStore();
    if (appStore.notes.length === 0 && pageData.id) {
        await handleCreateAndFocusFirstNote();
    } else if (focusFirstNote && ui.domRefs.notesContainer) {
        const firstNoteContent = ui.domRefs.notesContainer.querySelector('.note-content');
        if (firstNoteContent) ui.switchToEditMode(firstNoteContent);
    }
}

async function _processAndRenderPage(pageData, focusFirstNote) {
    const appStore = getAppStore();
    appStore.setCurrentPageName(pageData.name);
    appStore.setCurrentPageId(pageData.id);
    appStore.setNotes(pageData.notes || []);
    appStore.setPagePassword(null); // Reset password for new page

    // Add to recent history
    recentHistory.addPage(pageData.name);

    ui.updatePageTitle(pageData.name);
    // Calendar current page is handled by Alpine.js component
    
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
             
             // Add new page to calendar cache if it's a date-based page
             if (pageDetails && pageDetails.id) {
                 calendarCache.addPage(pageDetails);
             }
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
    
    // Cache the page data for future loads
    pageCache.setPage(pageName, combinedPageData);
    
    return combinedPageData;
}

export async function loadPage(pageName, focusFirstNote = false, providedPageData = null) {
    if (window.blockPageLoad) return;
    window.blockPageLoad = true;
    
    pageName = pageName || getInitialPage();

    try {
        let pageData = providedPageData;
        
        // Check localStorage cache first
        if (!pageData) {
            pageData = pageCache.getPage(pageName);
        }
        
        if (!pageData) {
            console.log('[page-loader] Attempting to show loading message. notesContainer:', ui.domRefs.notesContainer);
            if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = '<p>Loading page...</p>';
            pageData = await _fetchPageFromNetwork(pageName);
        } else {
            console.log('[page-loader] Using cached page data for:', pageName);
            // Validate cached data against server to avoid stale IDs after DB reset
            try {
                const fresh = await pagesAPI.getPageByName(pageName);
                if (!fresh || !fresh.id || (pageData.id && fresh.id !== pageData.id)) {
                    console.warn('[page-loader] Cache invalidated due to server mismatch or reset. Refetching.', { cachedId: pageData.id, freshId: fresh?.id });
                    pageCache.removePage(pageName);
                    pageData = await _fetchPageFromNetwork(pageName);
                }
            } catch (validationError) {
                console.warn('[page-loader] Cache validation failed, refetching from network.', validationError);
                pageCache.removePage(pageName);
                pageData = await _fetchPageFromNetwork(pageName);
            }
        }
        
        await _processAndRenderPage(pageData, focusFirstNote);
    } catch (error) {
        console.error('[page-loader] Error object received:', error);
        console.error(`Error loading page ${pageName}:`, error);
        const appStore = getAppStore();
        appStore.setCurrentPageName(`Error: ${pageName}`);
        appStore.setCurrentPageId(null);
        ui.updatePageTitle(appStore.currentPageName);
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
    const appStore = getAppStore();
    if (!appStore.notes) return;
    const linkedPageNames = new Set();
    const pageLinkRegex = /\[\[([^\]]+)\]\]/g;

    appStore.notes.forEach(note => {
        if (note.content) {
            [...note.content.matchAll(pageLinkRegex)].forEach(match => {
                const pageName = match[1].trim().split('|')[0].trim();
                if (pageName) linkedPageNames.add(pageName);
            });
        }
    });

    let prefetchCounter = 0;
    for (const name of linkedPageNames) {
        if (prefetchCounter >= appStore.MAX_PREFETCH_PAGES) break;
        if (name === appStore.currentPageName || appStore.hasPageCache(name)) continue;
        
        try {
            await _fetchPageFromNetwork(name);
        } catch (error) {
            console.warn(`[Prefetch] Failed to prefetch linked page ${name}:`, error.message);
        }
    }
}

export async function prefetchRecentPagesData() {
    try {
        const appStore = getAppStore();
        const { pages } = await pagesAPI.getPages({ sort_by: 'updated_at', sort_order: 'desc', per_page: appStore.MAX_PREFETCH_PAGES });
        for (const page of pages) {
            if (page.name === appStore.currentPageName || appStore.hasPageCache(page.name)) continue;
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
        const appStore = getAppStore();
        const { pages } = await pagesAPI.getPages({ per_page: 20, sort_by: 'updated_at', sort_order: 'desc' });
        ui.updatePageList(pages, activePageName || appStore.currentPageName);
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

/**
 * Handles URL anchor highlighting for specific notes
 * Parses the URL hash for #note-{id} and highlights the corresponding note
 */
export function handleUrlAnchor() {
    const hash = window.location.hash;
    if (!hash) return;
    
    // Parse hash for note anchor (e.g., #note-532)
    const noteMatch = hash.match(/^#note-(\d+)$/);
    if (!noteMatch) return;
    
    const noteId = noteMatch[1];
    console.log(`[URL Anchor] Looking for note with ID: ${noteId}`);
    
    // Find the note element in the DOM
    const noteElement = document.querySelector(`[data-note-id="${noteId}"]`);
    if (!noteElement) {
        console.warn(`[URL Anchor] Note element with ID ${noteId} not found in DOM`);
        // Try again after a short delay in case the note is still loading
        setTimeout(() => {
            const retryElement = document.querySelector(`[data-note-id="${noteId}"]`);
            if (retryElement) {
                console.log(`[URL Anchor] Found note ${noteId} on retry`);
                highlightNoteElement(retryElement);
            }
        }, 500);
        return;
    }
    
    highlightNoteElement(noteElement);
}

/**
 * Highlights a note element with animation and scrolling
 * @param {HTMLElement} noteElement - The note element to highlight
 */
function highlightNoteElement(noteElement) {
    const noteId = noteElement.dataset.noteId;
    
    // Add highlighting class
    noteElement.classList.add('anchor-highlight');
    
    // Scroll to the note with smooth animation and offset
    const headerHeight = 80; // Approximate header height
    const elementTop = noteElement.offsetTop;
    const elementHeight = noteElement.offsetHeight;
    const windowHeight = window.innerHeight;
    
    // Calculate scroll position to center the element with some offset from top
    const scrollTop = elementTop - headerHeight - (windowHeight / 2) + (elementHeight / 2);
    
    window.scrollTo({
        top: Math.max(0, scrollTop),
        behavior: 'smooth'
    });
    
    // Remove the highlight class after animation completes
    setTimeout(() => {
        noteElement.classList.remove('anchor-highlight');
    }, 3000); // Keep highlight for 3 seconds
    
    console.log(`[URL Anchor] Successfully highlighted note ${noteId}`);
}