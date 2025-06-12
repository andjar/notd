// FILE: assets/js/app/search.js

import { ui } from '../ui.js';
import { debounce, safeAddEventListener } from '../utils.js';
import { searchAPI, pagesAPI } from '../api_client.js';

// --- Global Variables for Page Search Modal ---
let allPagesForSearch = [];
let selectedSearchResultIndex = -1;

// --- Global Search (Sidebar) Logic ---

function highlightSearchTerms(text, searchTerm) {
    if (!searchTerm || !text) return text;
    // Escape special regex characters in the search term
    const escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(${escapedTerm})`, 'gi');
    return text.replace(regex, `<mark class="search-highlight">$1</mark>`);
}

function displayGlobalSearchResults(results) {
    const searchResultsEl = ui.domRefs.searchResults;
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

    // Add a one-time click listener to the container for this set of results
    searchResultsEl.addEventListener('click', (e) => {
        const resultItem = e.target.closest('.search-result-item');
        if (resultItem) {
            const pageName = resultItem.dataset.pageName;
            
            if (ui.domRefs.globalSearchInput) ui.domRefs.globalSearchInput.value = '';
            searchResultsEl.classList.remove('has-results');
            searchResultsEl.innerHTML = '';
            
            // Use the globally available loadPage function
            window.loadPage(pageName);
            // Focusing the specific note will be handled by loadPage or a future enhancement
        }
    }, { once: true }); // Use once to avoid multiple listeners
}

const debouncedGlobalSearch = debounce(async (query) => {
    const searchResultsEl = ui.domRefs.searchResults;
    if (!searchResultsEl) return;

    if (!query.trim()) {
        searchResultsEl.classList.remove('has-results');
        searchResultsEl.innerHTML = '';
        return;
    }
    
    try {
        const response = await searchAPI.search(query);
        displayGlobalSearchResults(response.results || []);
    } catch (error) {
        console.error('Global search error:', error);
        searchResultsEl.innerHTML = '<div class="search-result-item">Error during search.</div>';
        searchResultsEl.classList.add('has-results');
    }
}, 300);

export function initGlobalSearch() {
    if (ui.domRefs.globalSearchInput) {
        safeAddEventListener(ui.domRefs.globalSearchInput, 'input', (e) => {
            debouncedGlobalSearch(e.target.value);
        }, 'globalSearchInput');
    }
}


// --- Page Search Modal Logic ---

async function openSearchOrCreatePageModal() {
    if (!ui.domRefs.pageSearchModal) return;
    try {
        // Fetch all pages for the search list
        const { pages } = await pagesAPI.getPages({ excludeJournal: true });
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
    if (ui.domRefs.pageSearchModal) {
        ui.domRefs.pageSearchModal.classList.remove('active');
    }
    selectedSearchResultIndex = -1;
}

function renderPageSearchResults(query) {
    const resultsEl = ui.domRefs.pageSearchModalResults;
    if (!resultsEl) return;
    resultsEl.innerHTML = '';
    selectedSearchResultIndex = -1;

    const lowerCaseQuery = query.toLowerCase();
    const filteredPages = allPagesForSearch.filter(page => 
        page.name.toLowerCase().includes(lowerCaseQuery)
    );

    filteredPages.forEach(page => {
        const li = document.createElement('li');
        li.textContent = page.name;
        li.dataset.pageName = page.name;
        resultsEl.appendChild(li);
    });

    const exactMatch = allPagesForSearch.some(page => page.name.toLowerCase() === lowerCaseQuery);
    if (query.trim() !== '' && !exactMatch) {
        const li = document.createElement('li');
        li.classList.add('create-new-option');
        li.innerHTML = `Create page: <span>"${query}"</span>`;
        li.dataset.pageName = query;
        li.dataset.isCreate = 'true';
        resultsEl.appendChild(li);
    }
    
    if (resultsEl.children.length > 0) {
        selectedSearchResultIndex = 0;
        resultsEl.children[0].classList.add('selected');
    }
}

async function selectAndActionPageSearchResult(pageName, isCreate) {
    closeSearchOrCreatePageModal();
    if (isCreate) {
        try {
            // The API handles creation on getPageByName if it doesn't exist
            // but calling createPage is more explicit.
            const newPage = await pagesAPI.createPage(pageName);
            if (newPage && newPage.id) {
                // Refresh the page list in the sidebar
                if (window.fetchAndDisplayPages) window.fetchAndDisplayPages(newPage.name);
                // Load the new page
                window.loadPage(newPage.name, true);
            }
        } catch (error) {
            console.error('Error creating page from search modal:', error);
            alert(`Error creating page: ${error.message}`);
        }
    } else {
        window.loadPage(pageName, true);
    }
}

export function initPageSearchModal() {
    safeAddEventListener(ui.domRefs.openPageSearchModalBtn, 'click', openSearchOrCreatePageModal, 'openPageSearchModalBtn');
    safeAddEventListener(ui.domRefs.pageSearchModalCancel, 'click', closeSearchOrCreatePageModal, 'pageSearchModalCancel');
    
    const closeXBtn = ui.domRefs.pageSearchModal?.querySelector('.modal-close-x');
    safeAddEventListener(closeXBtn, 'click', closeSearchOrCreatePageModal, 'pageSearchModalCloseX');

    if (ui.domRefs.pageSearchModalInput) {
        ui.domRefs.pageSearchModalInput.addEventListener('input', (e) => {
            renderPageSearchResults(e.target.value);
        });

        ui.domRefs.pageSearchModalInput.addEventListener('keydown', (e) => {
            const items = ui.domRefs.pageSearchModalResults.children;
            if (items.length === 0) return;

            switch (e.key) {
                case 'ArrowDown':
                case 'ArrowUp':
                    e.preventDefault();
                    items[selectedSearchResultIndex]?.classList.remove('selected');
                    if (e.key === 'ArrowDown') {
                        selectedSearchResultIndex = (selectedSearchResultIndex + 1) % items.length;
                    } else {
                        selectedSearchResultIndex = (selectedSearchResultIndex - 1 + items.length) % items.length;
                    }
                    items[selectedSearchResultIndex]?.classList.add('selected');
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (selectedSearchResultIndex > -1 && items[selectedSearchResultIndex]) {
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

    // Add delegated click listener for the results list
    if (ui.domRefs.pageSearchModalResults) {
        ui.domRefs.pageSearchModalResults.addEventListener('click', (e) => {
            const selectedItem = e.target.closest('li');
            if (selectedItem) {
                selectAndActionPageSearchResult(selectedItem.dataset.pageName, selectedItem.dataset.isCreate === 'true');
            }
        });
    }

    // Global shortcut to open the modal
    document.addEventListener('keydown', (e) => {
        const activeElement = document.activeElement;
        const isInputFocused = activeElement && (activeElement.tagName === 'INPUT' || activeElement.isContentEditable);
        if (e.ctrlKey && e.code === 'Space' && !isInputFocused) {
            e.preventDefault();
            openSearchOrCreatePageModal();
        }
    });
}