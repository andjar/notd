// Import UI module
import { ui } from '../ui.js';

// Import page management
import { loadPage, fetchAndDisplayPages } from './page-loader.js';

// Import utilities
import { debounce, safeAddEventListener } from '../utils.js';

// Import API clients
import { searchAPI, pagesAPI } from '../api_client.js';

// --- Variables ---
let allPagesForSearch = [];
let selectedSearchResultIndex = -1; // Specific to page search modal

// --- Global Search Logic ---

function highlightSearchTerms(text, searchTerm) {
    if (!searchTerm || !text) return text;
    const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, `<span class="search-result-highlight">$1</span>`);
}

function displaySearchResults(results) {
    const searchResultsEl = ui.domRefs.searchResults; // Use ui.domRefs
    if (!searchResultsEl) return;

    if (!results || results.length === 0) {
        searchResultsEl.innerHTML = '<div class="search-result-item">No results found</div>';
        searchResultsEl.classList.add('has-results');
        return;
    }

    const html = results.map(result => `
        <div class="search-result-item" data-page-name="${result.page_name}" data-note-id="${result.note_id}">
            <div class="search-result-title">${result.page_name}</div>
            <div class="search-result-snippet">${highlightSearchTerms(result.content_snippet, ui.domRefs.globalSearchInput.value)}</div>
        </div>
    `).join('');

    searchResultsEl.innerHTML = html;
    searchResultsEl.classList.add('has-results');

    searchResultsEl.addEventListener('click', (e) => {
        const resultItem = e.target.closest('.search-result-item');
        if (resultItem) {
            const pageName = resultItem.dataset.pageName;
            const noteId = resultItem.dataset.noteId;
            
            if (ui.domRefs.globalSearchInput) ui.domRefs.globalSearchInput.value = '';
            searchResultsEl.classList.remove('has-results');
            searchResultsEl.innerHTML = '';
            
            loadPage(pageName).then(() => {
                if (noteId) {
                    const noteElement = document.querySelector(`[data-note-id="${noteId}"]`);
                    if (noteElement) {
                        noteElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        const contentDiv = noteElement.querySelector('.note-content');
                        if (contentDiv) {
                            setTimeout(() => contentDiv.focus(), 100);
                        }
                    }
                }
            });
        }
    });
}

const debouncedSearch = debounce(async (query) => {
    const searchResultsEl = ui.domRefs.searchResults; // Use ui.domRefs
    if (!searchResultsEl) return;

    if (!query.trim()) {
        searchResultsEl.classList.remove('has-results');
        searchResultsEl.innerHTML = '';
        return;
    }
    
    try {
        const response = await searchAPI.search(query); // Assumes searchAPI is global
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
        safeAddEventListener(ui.domRefs.globalSearchInput, 'input', (e) => {
            debouncedSearch(e.target.value);
        }, 'globalSearchInput');
    }
}

// --- Page Search Modal Logic ---

async function openSearchOrCreatePageModal() {
    if (!ui.domRefs.pageSearchModal || !ui.domRefs.pageSearchModalInput || !ui.domRefs.pageSearchModalResults || !ui.domRefs.pageSearchModalCancel) {
        console.error('Page search modal elements not found!');
        return;
    }
    try {
        allPagesForSearch = await pagesAPI.getPages({ excludeJournal: true }); // Assumes pagesAPI is global
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
    if (ui.domRefs.pageSearchModal) {
        ui.domRefs.pageSearchModal.classList.remove('active');
    }
    selectedSearchResultIndex = -1; 
}

function renderPageSearchResults(query) {
    if (!ui.domRefs.pageSearchModalResults) return;
    ui.domRefs.pageSearchModalResults.innerHTML = '';
    selectedSearchResultIndex = -1; 

    const filteredPages = allPagesForSearch.filter(page => 
        page.name.toLowerCase().includes(query.toLowerCase())
    );

    filteredPages.forEach(page => {
        const li = document.createElement('li');
        li.textContent = page.name;
        li.dataset.pageName = page.name;
        li.addEventListener('click', () => selectAndActionPageSearchResult(page.name, false));
        ui.domRefs.pageSearchModalResults.appendChild(li);
    });

    const exactMatch = allPagesForSearch.some(page => page.name.toLowerCase() === query.toLowerCase());
    if (query.trim() !== '' && !exactMatch) {
        const li = document.createElement('li');
        li.classList.add('create-new-option');
        li.innerHTML = `Create page: <span>"${query}"</span>`;
        li.dataset.pageName = query; 
        li.dataset.isCreate = 'true';
        li.addEventListener('click', () => selectAndActionPageSearchResult(query, true));
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
            const newPage = await pagesAPI.createPage(pageName); // Pass pageName directly as string
            if (newPage && newPage.id) {
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
        await loadPage(pageName, true);
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
        ui.domRefs.pageSearchModalInput.addEventListener('input', (e) => {
            renderPageSearchResults(e.target.value);
        });

        ui.domRefs.pageSearchModalInput.addEventListener('keydown', (e) => {
            const items = ui.domRefs.pageSearchModalResults.children;
            if (items.length === 0) return;

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
                    if (selectedSearchResultIndex !== -1 && items[selectedSearchResultIndex]) {
                        const selectedItem = items[selectedSearchResultIndex];
                        selectAndActionPageSearchResult(selectedItem.dataset.pageName, selectedItem.dataset.isCreate === 'true');
                    } else if (ui.domRefs.pageSearchModalInput.value.trim() !== '') {
                        selectAndActionPageSearchResult(ui.domRefs.pageSearchModalInput.value.trim(), true);
                    }
                    break;
                case 'Escape':
                    closeSearchOrCreatePageModal();
                    break;
            }
        });
    }

    // Global Ctrl+Space listener
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.code === 'Space') {
            e.preventDefault();
            openSearchOrCreatePageModal();
        }
    });
}
