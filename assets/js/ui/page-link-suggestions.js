// assets_js_ui_page-link-suggestions.js

import { pagesAPI } from '../api_client.js';

let suggestionBox;
let suggestionList;
let allPagesForSuggestions = []; // Cached page names
let suggestionStyleSheet = null; // To hold our dedicated stylesheet object

/**
 * Fetches all page names and caches them.
 * Should be called during application initialization.
 */
async function fetchAllPages() {
  let allFetchedPageObjects = []; // Store full page objects first
  let currentPage = 1;
  const perPage = 100; // Request 100 items per page (max allowed by API)
  let totalPages = 1; // Initialize totalPages to 1

  try {
    console.log(`[fetchAllPages] Starting fetch. Initial page: ${currentPage}, perPage: ${perPage}`);
    
    do {
      // Pass the current page and per_page to pagesAPI.getPages
      const response = await pagesAPI.getPages({ 
        excludeJournal: true, 
        followAliases: true, 
        page: currentPage, 
        per_page: perPage 
      });

      // Check if response and response.pages are valid
      if (response && Array.isArray(response.pages)) {
        if (response.pages.length > 0) {
          allFetchedPageObjects = allFetchedPageObjects.concat(response.pages);
          console.log(`[fetchAllPages] Fetched page ${currentPage}. Pages in this batch: ${response.pages.length}. Total fetched so far: ${allFetchedPageObjects.length}`);
        }
        // No specific console log if response.pages is empty, covered by later checks or logs.
        
        // Update totalPages from pagination info, if available
        if (response.pagination && response.pagination.total_pages) {
          totalPages = response.pagination.total_pages;
        } else {
          // If no pagination info but we got some pages in the first call, assume it's a single page response
          // If we got no pages and no pagination, totalPages remains 1, loop will terminate.
          // This also handles the case where total_pages might be 0 from the API.
          totalPages = currentPage; 
        }
        
        // Break if current page is greater or equal to total pages.
        // Also break if no pages were returned in this batch AND it's not the first page (to prevent infinite loop on faulty API) 
        // OR if totalPages from API was 0.
        if (currentPage >= totalPages || totalPages === 0) {
          if (currentPage >= totalPages && totalPages > 0) {
            console.log(`[fetchAllPages] All pages fetched or current page ${currentPage} reached total pages ${totalPages}.`);
          } else if (totalPages === 0) {
            console.log(`[fetchAllPages] API reported 0 total pages. Nothing to fetch.`);
          }
          break; 
        }
        currentPage++;
      } else {
        // If response or response.pages is invalid (e.g., null, not an array)
        console.warn(`[fetchAllPages] Invalid response or no 'pages' array for page ${currentPage}. Response:`, response);
        break; 
      }
    } while (currentPage <= totalPages && totalPages > 0); // Added totalPages > 0 to prevent loop if API says 0 total pages

    if (allFetchedPageObjects.length > 0) {
      // Map to names only after all page objects are fetched
      allPagesForSuggestions = allFetchedPageObjects.map(page => page.name);
      console.log('Page names fetched and cached for suggestions:', allPagesForSuggestions.length, 'pages from', totalPages, 'API pages.');
    } else {
      allPagesForSuggestions = [];
      console.warn('fetchAllPages resulted in an empty list of suggestions. Total objects fetched:', allFetchedPageObjects.length);
    }

  } catch (error) {
    console.error('Error fetching page names for suggestions:', error);
    allPagesForSuggestions = []; // Ensure it's an empty array on error
  }
}

/**
 * Initializes the suggestion UI elements and their dedicated styles.
 */
function initSuggestionUI() {
  suggestionBox = document.createElement('div');
  suggestionBox.id = 'page-link-suggestion-box';
  suggestionBox.style.display = 'none'; 
  suggestionBox.style.position = 'absolute'; 
  suggestionBox.style.border = '1px solid var(--color-border, #ccc)';
  suggestionBox.style.backgroundColor = 'var(--color-background, white)'; 
  suggestionBox.style.color = 'var(--color-text, black)'; 
  suggestionBox.style.zIndex = '1000'; 
  suggestionBox.style.maxHeight = '200px'; 
  suggestionBox.style.overflowY = 'auto';  
  suggestionBox.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)'; // Enhanced shadow

  suggestionList = document.createElement('ul');
  suggestionList.style.listStyleType = 'none';
  suggestionList.style.margin = '0';
  suggestionList.style.padding = '0';

  suggestionBox.appendChild(suggestionList);
  document.body.appendChild(suggestionBox);

  // Create and append a dedicated <style> element for suggestion box rules
  const styleEl = document.createElement('style');
  styleEl.id = 'page-link-suggestion-dynamic-styles'; // Give it an ID for clarity
  document.head.appendChild(styleEl);
  suggestionStyleSheet = styleEl.sheet; // Get the CSSStyleSheet object

  // Add CSS rules to our dedicated stylesheet
  try {
    if (suggestionStyleSheet) {
      // Rule for selected items
      const selectedRule = '#page-link-suggestion-box li.selected { background-color: var(--color-accent, #007bff) !important; color: var(--color-accent-contrast, white) !important; }';
      // Rule for hover effect (non-selected items)
      const hoverRule = '#page-link-suggestion-box li:not(.selected):hover { background-color: var(--color-background-hover, #f0f0f0); color: var(--color-text, black); }'; // Ensure text color on hover

      // Helper to check if a rule exists to prevent duplicates if init is called multiple times
      const ruleExists = (selector) => {
        if (!suggestionStyleSheet.cssRules) return false; // Should not happen with our own sheet
        for (let i = 0; i < suggestionStyleSheet.cssRules.length; i++) {
          if (suggestionStyleSheet.cssRules[i].selectorText === selector) {
            return true;
          }
        }
        return false;
      };

      if (!ruleExists('#page-link-suggestion-box li.selected')) {
        suggestionStyleSheet.insertRule(selectedRule, suggestionStyleSheet.cssRules.length);
      }
      if (!ruleExists('#page-link-suggestion-box li:not(.selected):hover')) {
        suggestionStyleSheet.insertRule(hoverRule, suggestionStyleSheet.cssRules.length);
      }
    }
  } catch (e) {
    console.error("Error inserting CSS rule into dedicated stylesheet for suggestion box:", e);
    // If this fails, highlighting might rely solely on inline styles or main CSS.
  }
}

/**
 * Shows suggestions based on the query and positions the suggestion box.
 * @param {string} query The search query.
 * @param {{top: number, left: number}} position The position to display the box.
 */
function showSuggestions(query, position) {
  suggestionList.innerHTML = ''; 

  if (!query) {
    hideSuggestions();
    return;
  }

  const lowerCaseQuery = query.toLowerCase();
  const matchedPages = allPagesForSuggestions.filter(page =>
    page.toLowerCase().includes(lowerCaseQuery)
  ).slice(0, 10); 

  if (matchedPages.length > 0) {
    matchedPages.forEach((pageName, index) => {
      const listItem = document.createElement('li');
      listItem.textContent = pageName;
      listItem.dataset.pageName = pageName;
      listItem.style.padding = '8px 12px'; 
      listItem.style.cursor = 'pointer';
      listItem.style.borderBottom = '1px solid var(--color-border-subtle, #eee)'; 
      // Remove last item's border bottom for cleaner look
      if (index === matchedPages.length - 1) {
        listItem.style.borderBottom = 'none';
      }

      listItem.addEventListener('mouseover', () => {
        Array.from(suggestionList.children).forEach(child => child.classList.remove('selected'));
        listItem.classList.add('selected');
      });
      // No mouseout needed to remove .selected, as hover and selection are distinct.
      // The :hover CSS rule handles visual hover state.

      listItem.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const selectedPageName = listItem.dataset.pageName;
        const selectEvent = new CustomEvent('suggestion-select', { 
            detail: { pageName: selectedPageName },
            bubbles: true, 
            cancelable: true 
        });
        // Dispatch from the list item itself or the suggestionBox
        suggestionBox.dispatchEvent(selectEvent); // Dispatch from suggestionBox for easier listening
      });

      suggestionList.appendChild(listItem);
    });
    
    suggestionBox.style.left = `${position.left}px`;
    suggestionBox.style.top = `${position.top}px`;
    suggestionBox.style.display = 'block';

    if (suggestionList.children.length > 0) {
      suggestionList.children[0].classList.add('selected');
    }
  } else {
    hideSuggestions();
  }
}

/**
 * Hides the suggestion box and clears its content.
 */
function hideSuggestions() {
  if (suggestionBox) {
    suggestionBox.style.display = 'none';
  }
  if (suggestionList) {
    suggestionList.innerHTML = '';
  }
}

/**
 * Navigates through the suggestions using keyboard arrows.
 * @param {'ArrowUp' | 'ArrowDown'} direction The direction of navigation.
 */
function navigateSuggestions(direction) {
  const items = Array.from(suggestionList.children);
  if (items.length === 0) return;

  let currentIndex = items.findIndex(item => item.classList.contains('selected'));

  items.forEach(item => item.classList.remove('selected'));

  if (direction === 'ArrowDown') {
    currentIndex = (currentIndex + 1) % items.length;
  } else if (direction === 'ArrowUp') {
    currentIndex = (currentIndex - 1 + items.length) % items.length;
  }

  if (currentIndex >= 0 && currentIndex < items.length) {
    items[currentIndex].classList.add('selected');
    // The .selected class is now styled by the rule in our dedicated stylesheet
    items[currentIndex].scrollIntoView({ block: 'nearest' });
  }
}

/**
 * Gets the page name of the currently selected suggestion.
 * @returns {string | null} The page name or null if no suggestion is selected.
 */
function getSelectedSuggestion() {
  const selectedItem = suggestionList.querySelector('li.selected');
  return selectedItem ? selectedItem.dataset.pageName : null;
}

export {
  initSuggestionUI,
  showSuggestions,
  hideSuggestions,
  navigateSuggestions,
  getSelectedSuggestion,
  fetchAllPages
};