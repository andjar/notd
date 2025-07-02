import { pagesAPI, searchAPI } from '../api_client.js';
import { loadPage, fetchAndDisplayPages } from './page-loader.js';
import { calendarCache } from './calendar-cache.js';
import { debounce, safeAddEventListener, decrypt } from '../utils.js';
import { ui } from '../ui.js';
import { getCurrentPagePassword } from './state.js';

// --- Global Search (Sidebar) ---

function highlightSearchTerms(text, searchTerm) {
    if (!searchTerm || !text) return text;
    const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<span class="search-result-highlight">$1</span>');
}

function displaySearchResults(results) {
    const searchResultsEl = ui.domRefs.searchResults;
    if (!searchResultsEl) return;

    if (!results || results.length === 0) {
        searchResultsEl.innerHTML = '<div class="search-result-item">No results found</div>';
        searchResultsEl.classList.add('has-results');
        return;
    }

    const html = results.map(result => {
        let snippet = result.content_snippet;
        let isEncrypted = false;

        // Check if the page has an 'encrypted' property set to 'true'
        // Check both direct properties and parent properties
        const isEncryptedInProps = (props) => props && Array.isArray(props.encrypted) && 
            props.encrypted.some(p => String(p.value).toLowerCase() === 'true');
        
        isEncrypted = isEncryptedInProps(result.properties) || 
                     isEncryptedInProps(result.parent_properties);

        if (isEncrypted) {
            const password = getCurrentPagePassword();
            if (password) {
                try {
                    snippet = decrypt(snippet, password);
                    if (snippet === null) {
                        snippet = '[DECRYPTION FAILED]';
                    }
                } catch (e) {
                    console.error('Failed to decrypt search result snippet:', e);
                    snippet = '[DECRYPTION FAILED]';
                }
            } else {
                snippet = '[ENCRYPTED CONTENT - Enter password to view]';
            }
        }

        const encryptedIcon = isEncrypted ? '<i data-feather="lock" class="encrypted-icon"></i> ' : '';
        
        // Add parent properties display if they exist
        const parentPropsHtml = result.parent_properties && Object.keys(result.parent_properties).length > 0 
            ? `<div class="search-result-parent-props">
                ${Object.entries(result.parent_properties).map(([key, values]) => 
                    `<span class="parent-prop">${key}: ${values.map(v => v.value).join(', ')}</span>`
                ).join('')}
               </div>`
            : '';
        
        return `
            <div class="search-result-item">
                <a href="page.php?page=${encodeURIComponent(result.page_name)}" class="search-result-link">
                    <div class="search-result-title">${encryptedIcon}${result.page_name}</div>
                    ${parentPropsHtml}
                    <div class="search-result-snippet">${highlightSearchTerms(snippet, ui.domRefs.globalSearchInput.value)}</div>
                </a>
            </div>
        `;
    }).join('');

    searchResultsEl.innerHTML = html;
    searchResultsEl.classList.add('has-results');

    // Ensure Feather icons are rendered after new HTML is added
    if (typeof feather !== 'undefined') {
        feather.replace();
    }

    // No need for click handler - links will navigate directly
}

const debouncedSearch = debounce(async (query, includeParentProps = false) => {
    const searchResultsEl = ui.domRefs.searchResults;
    if (!searchResultsEl) return;

    if (!query.trim()) {
        searchResultsEl.classList.remove('has-results');
        searchResultsEl.innerHTML = '';
        return;
    }
    
    try {
        const response = await searchAPI.search(query, { includeParentProps });
        if (response && Array.isArray(response.results)) {
            displaySearchResults(response.results);
        } else {
            console.warn('Search API returned unexpected format:', response);
            displaySearchResults([]);
        }
    } catch (error) {
        console.error('Search error:', error);
        searchResultsEl.innerHTML = '<div class="search-result-item">Error performing search</div>';
        searchResultsEl.classList.add('has-results');
    }
}, 300);

export function initGlobalSearch() {
    if (ui.domRefs.globalSearchInput) {
        // Add a checkbox for including parent properties
        const searchContainer = ui.domRefs.globalSearchInput.closest('.search-container');
        if (searchContainer && !searchContainer.querySelector('.include-parent-props')) {
            const checkboxContainer = document.createElement('div');
            checkboxContainer.className = 'include-parent-props';
            checkboxContainer.innerHTML = `
                <label>
                    <input type="checkbox" id="includeParentProps">
                    Include parent properties
                </label>
            `;
            searchContainer.appendChild(checkboxContainer);
            
            const checkbox = checkboxContainer.querySelector('#includeParentProps');
            safeAddEventListener(checkbox, 'change', (e) => {
                debouncedSearch(ui.domRefs.globalSearchInput.value, e.target.checked);
            }, 'includeParentPropsCheckbox');
        }

        safeAddEventListener(ui.domRefs.globalSearchInput, 'input', (e) => {
            const includeParentProps = document.querySelector('#includeParentProps')?.checked || false;
            debouncedSearch(e.target.value, includeParentProps);
        }, 'globalSearchInput');
    }
}


// --- Page Search Modal ---

let allPagesForSearch = [];
let selectedSearchResultIndex = -1;

async function openSearchOrCreatePageModal() {
    if (!ui.domRefs.pageSearchModal) return;
    try {
        // **FIXED**: Destructure the `pages` array from the response object.
        const { pages } = await pagesAPI.getPages({ excludeJournal: true, per_page: 5000 });
        allPagesForSearch = pages || [];
    } catch (error) {
        console.error('Failed to fetch pages for search modal:', error);
        allPagesForSearch = []; 
    }
    ui.domRefs.pageSearchModalInput.value = '';
    renderPageSearchResults('');
    ui.domRefs.pageSearchModal.classList.add('active');
    ui.domRefs.pageSearchModalInput.focus();
}

function closeSearchOrCreatePageModal() {
    if (ui.domRefs.pageSearchModal) ui.domRefs.pageSearchModal.classList.remove('active');
    selectedSearchResultIndex = -1;
}

function renderPageSearchResults(query) {
    if (!ui.domRefs.pageSearchModalResults) return;
    ui.domRefs.pageSearchModalResults.innerHTML = '';
    selectedSearchResultIndex = -1; 

    const lowerCaseQuery = query.toLowerCase();
    const filteredPages = allPagesForSearch.filter(page => 
        page.name.toLowerCase().includes(lowerCaseQuery)
    );

    filteredPages.slice(0, 10).forEach(page => {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.href = `page.php?page=${encodeURIComponent(page.name)}`;
        link.textContent = page.name;
        link.className = 'page-search-result-link';
        li.appendChild(link);
        ui.domRefs.pageSearchModalResults.appendChild(li);
    });

    const exactMatch = allPagesForSearch.some(page => page.name.toLowerCase() === lowerCaseQuery);
    if (query.trim() !== '' && !exactMatch) {
        const li = document.createElement('li');
        li.classList.add('create-new-option');
        const link = document.createElement('a');
        link.href = `page.php?page=${encodeURIComponent(query)}`;
        link.innerHTML = `Create page: <span>"${query}"</span>`;
        link.className = 'page-search-result-link create-new-link';
        li.appendChild(link);
        ui.domRefs.pageSearchModalResults.appendChild(li);
    }
    
    if (ui.domRefs.pageSearchModalResults.children.length > 0) {
        selectedSearchResultIndex = 0;
        ui.domRefs.pageSearchModalResults.children[0].classList.add('selected');
    }
}

async function selectAndActionPageSearchResult(pageName, isCreate) {
    closeSearchOrCreatePageModal();
    if (isCreate) {
        try {
            const newPage = await pagesAPI.createPage(pageName);
            if (newPage && newPage.id) {
                // Add new page to calendar cache if it's a date-based page
                calendarCache.addPage(newPage);
                
                await fetchAndDisplayPages(newPage.name); 
                await loadPage(newPage.name, true); 
            } else {
                alert(`Failed to create page: ${pageName}`);
            }
        } catch (error) {
            console.error('Error creating page from search modal:', error);
            alert(`Error creating page: ${error.message}`);
        }
    } else {
        await loadPage(pageName);
    }
}

export function initPageSearchModal() {
    if (ui.domRefs.openPageSearchModalBtn) {
        safeAddEventListener(ui.domRefs.openPageSearchModalBtn, 'click', openSearchOrCreatePageModal, 'openPageSearchModalBtn');
    }
    if (ui.domRefs.pageSearchModalCancel) {
        safeAddEventListener(ui.domRefs.pageSearchModalCancel, 'click', closeSearchOrCreatePageModal, 'pageSearchModalCancel');
    }

    if (ui.domRefs.pageSearchModalInput) {
        ui.domRefs.pageSearchModalInput.addEventListener('input', (e) => renderPageSearchResults(e.target.value));

        ui.domRefs.pageSearchModalInput.addEventListener('keydown', (e) => {
            const items = ui.domRefs.pageSearchModalResults.children;
            if (e.key !== 'Enter' && items.length === 0) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (selectedSearchResultIndex < items.length - 1) {
                        items[selectedSearchResultIndex]?.classList.remove('selected');
                        selectedSearchResultIndex++;
                        items[selectedSearchResultIndex]?.classList.add('selected');
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (selectedSearchResultIndex > 0) {
                        items[selectedSearchResultIndex]?.classList.remove('selected');
                        selectedSearchResultIndex--;
                        items[selectedSearchResultIndex]?.classList.add('selected');
                    }
                    break;
                case 'Enter':
                    e.preventDefault();
                    const pageName = ui.domRefs.pageSearchModalInput.value.trim();
                    if (!pageName) {
                        closeSearchOrCreatePageModal();
                        return;
                    }
                    
                    if (selectedSearchResultIndex > -1 && items[selectedSearchResultIndex]) {
                        const selectedItem = items[selectedSearchResultIndex];
                        if (pageName.toLowerCase() === selectedItem.dataset.pageName.toLowerCase()) {
                           selectAndActionPageSearchResult(selectedItem.dataset.pageName, selectedItem.dataset.isCreate === 'true');
                           return;
                        }
                    }
                    const exactMatch = allPagesForSearch.find(p => p.name.toLowerCase() === pageName.toLowerCase());
                    if (exactMatch) {
                        selectAndActionPageSearchResult(exactMatch.name, false);
                    } else {
                        selectAndActionPageSearchResult(pageName, true);
                    }
                    break;
                case 'Escape':
                    closeSearchOrCreatePageModal();
                    break;
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.code === 'Space') {
            e.preventDefault();
            openSearchOrCreatePageModal();
        }
    });
}

// --- Note Search Modal ---

let noteSearchSelectedResultIndex = -1;

function openNoteSearchModal() {
    if (!ui.domRefs.noteSearchModal) return;
    ui.domRefs.noteSearchModalInput.value = '';
    if (ui.domRefs.noteSearchModalResults) ui.domRefs.noteSearchModalResults.innerHTML = '';
    noteSearchSelectedResultIndex = -1;
    ui.domRefs.noteSearchModal.classList.add('active');
    ui.domRefs.noteSearchModalInput.focus();
}

function closeNoteSearchModal() {
    if (ui.domRefs.noteSearchModal) ui.domRefs.noteSearchModal.classList.remove('active');
    noteSearchSelectedResultIndex = -1;
}

function renderNoteSearchResults(results) {
    const searchResultsEl = ui.domRefs.noteSearchModalResults;
    if (!searchResultsEl) return;

    searchResultsEl.innerHTML = ''; // Clear previous results
    noteSearchSelectedResultIndex = -1;

    if (!results || results.length === 0) {
        searchResultsEl.innerHTML = '<li class="no-results-item">No notes found</li>';
        return;
    }

    const html = results.map(result => {
        let snippet = result.content_snippet || result.content; // Fallback to full content if snippet is missing
        let isEncrypted = false;

        const isEncryptedInProps = (props) => props && Array.isArray(props.encrypted) &&
            props.encrypted.some(p => String(p.value).toLowerCase() === 'true');

        isEncrypted = isEncryptedInProps(result.properties) ||
                     isEncryptedInProps(result.parent_properties);

        if (isEncrypted) {
            const password = getCurrentPagePassword(); // Assuming this function is available and works
            if (password) {
                try {
                    snippet = decrypt(snippet, password); // Assuming decrypt is available
                    if (snippet === null) {
                        snippet = '[DECRYPTION FAILED]';
                    }
                } catch (e) {
                    console.error('Failed to decrypt search result snippet:', e);
                    snippet = '[DECRYPTION FAILED]';
                }
            } else {
                snippet = '[ENCRYPTED CONTENT - Enter password to view]';
            }
        }

        const encryptedIcon = isEncrypted ? '<i data-feather="lock" class="encrypted-icon"></i> ' : '';
        const pageNamePrefix = result.page_name ? `<span class="search-result-page-name">${result.page_name}</span> > ` : '';

        // Use result.note_id for data-note-id if available, otherwise it's a page result from global search
        const noteIdAttr = result.note_id ? `data-note-id="${result.note_id}"` : '';

        return `
            <li class="page-search-result-item" data-page-name="${encodeURIComponent(result.page_name)}" ${noteIdAttr}>
                <a href="page.php?page=${encodeURIComponent(result.page_name)}" class="page-search-result-link">
                    <div class="search-result-title">${pageNamePrefix}${encryptedIcon}${highlightSearchTerms(result.content.split('\\n')[0], ui.domRefs.noteSearchModalInput.value)}</div>
                    <div class="search-result-snippet">${highlightSearchTerms(snippet, ui.domRefs.noteSearchModalInput.value)}</div>
                </a>
            </li>
        `;
    }).join('');

    searchResultsEl.innerHTML = html;

    if (typeof feather !== 'undefined') {
        feather.replace();
    }

    if (searchResultsEl.children.length > 0) {
        noteSearchSelectedResultIndex = 0;
        searchResultsEl.children[0].classList.add('selected');
    }
}

const debouncedNoteSearch = debounce(async (query) => {
    const searchResultsEl = ui.domRefs.noteSearchModalResults;
    if (!searchResultsEl) return;

    if (!query.trim()) {
        searchResultsEl.innerHTML = '';
        noteSearchSelectedResultIndex = -1;
        return;
    }

    try {
        // For note search, we don't need includeParentProps typically, but the API might handle it.
        // The main goal is to search note content.
        const response = await searchAPI.search(query, { includeParentProps: false }); // or true, depending on desired behavior
        if (response && Array.isArray(response.results)) {
            renderNoteSearchResults(response.results);
        } else {
            console.warn('Note Search API returned unexpected format:', response);
            renderNoteSearchResults([]);
        }
    } catch (error) {
        console.error('Note search error:', error);
        searchResultsEl.innerHTML = '<li class="search-result-item error-item">Error performing search</li>';
    }
}, 300);

export function initNoteSearchModal() {
    if (ui.domRefs.noteSearchModal && ui.domRefs.noteSearchModalInput && ui.domRefs.noteSearchModalCancel && ui.domRefs.noteSearchModalResults) {
        safeAddEventListener(ui.domRefs.noteSearchModalInput, 'input', (e) => {
            debouncedNoteSearch(e.target.value);
        }, 'noteSearchModalInput');

        safeAddEventListener(ui.domRefs.noteSearchModalInput, 'keydown', (e) => {
            const items = ui.domRefs.noteSearchModalResults.children;
            if (items.length === 0 && e.key !== 'Escape') return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (noteSearchSelectedResultIndex < items.length - 1) {
                        items[noteSearchSelectedResultIndex]?.classList.remove('selected');
                        noteSearchSelectedResultIndex++;
                        items[noteSearchSelectedResultIndex]?.classList.add('selected');
                        items[noteSearchSelectedResultIndex]?.scrollIntoView({ block: 'nearest' });
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (noteSearchSelectedResultIndex > 0) {
                        items[noteSearchSelectedResultIndex]?.classList.remove('selected');
                        noteSearchSelectedResultIndex--;
                        items[noteSearchSelectedResultIndex]?.classList.add('selected');
                        items[noteSearchSelectedResultIndex]?.scrollIntoView({ block: 'nearest' });
                    }
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (noteSearchSelectedResultIndex > -1 && items[noteSearchSelectedResultIndex]) {
                        const selectedLink = items[noteSearchSelectedResultIndex].querySelector('a');
                        if (selectedLink && selectedLink.href) {
                            window.location.href = selectedLink.href; // Navigate to the page
                            closeNoteSearchModal();
                        }
                    }
                    break;
                case 'Escape':
                    closeNoteSearchModal();
                    break;
            }
        });

        safeAddEventListener(ui.domRefs.noteSearchModalCancel, 'click', closeNoteSearchModal, 'noteSearchModalCancel');

        // Global listener for Shift+Space
        document.addEventListener('keydown', (e) => {
            if (e.shiftKey && e.code === 'Space') {
                // Prevent default browser behavior for Shift+Space (e.g., page scroll)
                // And prevent triggering if an input/textarea is focused
                if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.isContentEditable)) {
                    return;
                }
                e.preventDefault();
                openNoteSearchModal();
            }
        });
    } else {
        console.warn("Note Search Modal elements not fully found in domRefs. Initialization skipped.");
    }
}