// This module manages the UI for page link auto-suggestions.
import { pagesAPI } from '../api_client.js';

let suggestionBox;
let suggestionList;
let allPagesForSuggestions = []; // Cached page names

/**
 * Fetches all page names and caches them.
 * Should be called during application initialization.
 */
async function fetchAllPages() {
  try {
    const pages = await pagesAPI.getPages({ excludeJournal: true, followAliases: true });
    if (pages && Array.isArray(pages)) {
      allPagesForSuggestions = pages.map(page => page.name);
      console.log('Page names fetched and cached for suggestions:', allPagesForSuggestions.length);
    } else {
      allPagesForSuggestions = [];
      console.warn('fetchAllPages received unexpected data:', pages);
    }
  } catch (error) {
    console.error('Error fetching page names for suggestions:', error);
    allPagesForSuggestions = []; // Ensure it's an empty array on error
  }
}

/**
 * Initializes the suggestion UI elements.
 */
function initSuggestionUI() {
  suggestionBox = document.createElement('div');
  suggestionBox.id = 'page-link-suggestion-box';
  suggestionBox.style.display = 'none'; // Initially hidden
  suggestionBox.style.position = 'absolute'; // For positioning near the query
  suggestionBox.style.border = '1px solid #ccc';
  suggestionBox.style.backgroundColor = 'white';
  suggestionBox.style.zIndex = '1000'; // Ensure it's on top

  suggestionList = document.createElement('ul');
  suggestionList.style.listStyleType = 'none';
  suggestionList.style.margin = '0';
  suggestionList.style.padding = '0';

  suggestionBox.appendChild(suggestionList);
  document.body.appendChild(suggestionBox);
}

/**
 * Shows suggestions based on the query and positions the suggestion box.
 * @param {string} query The search query.
 * @param {{top: number, left: number}} position The position to display the box.
 */
function showSuggestions(query, position) {
  suggestionList.innerHTML = ''; // Clear existing items

  if (!query) {
    hideSuggestions();
    return;
  }

  const lowerCaseQuery = query.toLowerCase();
  const matchedPages = allPagesForSuggestions.filter(page =>
    page.toLowerCase().includes(lowerCaseQuery)
  );

  if (matchedPages.length > 0) {
    matchedPages.forEach(pageName => {
      const listItem = document.createElement('li');
      listItem.textContent = pageName;
      listItem.dataset.pageName = pageName;
      listItem.style.padding = '5px';
      listItem.style.cursor = 'pointer';
      listItem.addEventListener('mouseover', () => {
        // Remove 'selected' from other items
        Array.from(suggestionList.children).forEach(child => child.classList.remove('selected'));
        // Add 'selected' to the current item
        listItem.classList.add('selected');
      });
      suggestionList.appendChild(listItem);
    });

    suggestionBox.style.left = `${position.left}px`;
    suggestionBox.style.top = `${position.top}px`;
    suggestionBox.style.display = 'block';

    // Select the first suggestion by default
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
  suggestionBox.style.display = 'none';
  suggestionList.innerHTML = '';
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
    // Ensure the selected item is visible
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

// Export functions to be used by other modules
export {
  initSuggestionUI,
  showSuggestions,
  hideSuggestions,
  navigateSuggestions,
  getSelectedSuggestion,
  fetchAllPages // Export fetchAllPages
};

// The setAllPagesForSuggestions function is now removed as per requirements.
// fetchAllPages directly populates the module-level allPagesForSuggestions.
