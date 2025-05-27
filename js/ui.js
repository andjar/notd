// Functions and variables related to UI interactions
// (e.g., event handlers, DOM manipulation, modal logic)

// Initialize highlight.js
// Removed hljs.highlightAll() from here as content is loaded dynamically.
// Highlighting is handled by the marked.setOptions in app.js for dynamic content.

// --- DOM Element Selections ---
// These constants store references to frequently used DOM elements.
let searchInput;
let recentPagesList;
let newPageButton;
let pageTitle;
let pageProperties;
let outlineContainer;

document.addEventListener('DOMContentLoaded', () => {
    searchInput = document.getElementById('search');
    recentPagesList = document.getElementById('recent-pages-list');
    newPageButton = document.getElementById('new-page');
    pageTitle = document.getElementById('page-title');
    pageProperties = document.getElementById('page-properties');
    outlineContainer = document.getElementById('outline-container');
});

// Placeholder for UI functions that will be moved here
// function exampleUiFunction() {}

// --- Suggestion Popup Logic ---

/**
 * Closes the suggestions popup if it is open.
 * Resets suggestion-related state variables.
 * Depends on global state: `suggestionsPopup`, `activeSuggestionIndex`, `currentSuggestions` (from state.js).
 */
function closeSuggestionsPopup() {
    if (suggestionsPopup) {
        suggestionsPopup.remove();
        suggestionsPopup = null; // Reset global state
    }
    // Reset suggestion tracking state
    activeSuggestionIndex = -1;
    currentSuggestions = [];
}

/**
 * Positions the suggestions popup relative to a textarea element.
 * @param {HTMLTextAreaElement} textarea - The textarea to position the popup under.
 * Depends on global state: `suggestionsPopup` (from state.js).
 */
function positionSuggestionsPopup(textarea) {
    if (!suggestionsPopup) return;

    const rect = textarea.getBoundingClientRect();
    // Position popup below the textarea
    suggestionsPopup.style.position = 'absolute';
    suggestionsPopup.style.top = `${window.scrollY + rect.bottom}px`;
    suggestionsPopup.style.left = `${window.scrollX + rect.left}px`;
    suggestionsPopup.style.zIndex = '1000'; // Ensure it's above other content
}

/**
 * Inserts a selected suggestion (page title) into a textarea, replacing the link trigger.
 * @param {HTMLTextAreaElement} textarea - The textarea element.
 * @param {string} title - The title of the page to insert as a link.
 * Depends on: `closeSuggestionsPopup` (from this file).
 */
function insertSuggestion(textarea, title) {
    const currentValue = textarea.value;
    const cursorPos = textarea.selectionStart;

    let startIndex = -1;
    for (let i = cursorPos - 1; i >= 0; i--) {
        if (currentValue.substring(i, i + 2) === '[[') {
            const partAfterOpenBrackets = currentValue.substring(i + 2, cursorPos);
            if (!partAfterOpenBrackets.includes(']]')) {
                startIndex = i;
                break;
            }
        }
    }

    if (startIndex === -1) {
        console.warn("Could not reliably find start of link for insertion. Inserting at cursor, but this might be incorrect.");
        
        const lastOpenBracket = currentValue.lastIndexOf('[[', cursorPos -1);
        if (lastOpenBracket !== -1 && !currentValue.substring(lastOpenBracket + 2, cursorPos).includes(']]')) {
            startIndex = lastOpenBracket;
        } else {
            return;
        }
    }

    const textBeforeLinkStart = currentValue.substring(0, startIndex);

    let endIndex = cursorPos;
    if (currentValue.substring(cursorPos, cursorPos + 2) === ']]') {
        endIndex = cursorPos + 2;
    }

    const newLinkContent = `[[${title}]]`;
    const textAfterReplacedPart = currentValue.substring(endIndex);

    textarea.value = textBeforeLinkStart + newLinkContent + textAfterReplacedPart;

    const newCursorPos = startIndex + newLinkContent.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;
    textarea.focus();
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    closeSuggestionsPopup(); // Call it after insertion
}

function updateHighlightedSuggestion(items) {
    // Depends on global state: `suggestionsPopup`, `activeSuggestionIndex` (from state.js).
    if (!suggestionsPopup || !items || items.length === 0) return;

    items.forEach((item, index) => {
        if (index === activeSuggestionIndex) {
            item.classList.add('highlighted');
            item.scrollIntoView({ block: 'nearest' }); // Ensure highlighted item is visible
        } else {
            item.classList.remove('highlighted');
        }
    });
}

// --- Block Focus and Keyboard Navigation ---

/**
 * Sets a given element as the actively focused block for keyboard navigation.
 * Removes focus from any previously active block.
 * @param {HTMLElement} element - The DOM element representing the note block to focus.
 * @param {boolean} [scrollIntoView=true] - Whether to scroll the element into view.
 * Depends on global state: `activeBlockElement` (from state.js).
 */
function setActiveBlock(element, scrollIntoView = true) {
    if (activeBlockElement) {
        // Remove focus from the currently active block
        activeBlockElement.classList.remove('block-keyboard-focus');
    }
    activeBlockElement = element; // Update global state
    if (activeBlockElement) {
        activeBlockElement.classList.add('block-keyboard-focus');
        if (scrollIntoView) {
            // Scroll the new active block (or its textarea if present) into view
            const targetToScroll = activeBlockElement.querySelector('.note-textarea') || activeBlockElement;
            targetToScroll.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}

// --- Modal and Direct UI Interaction Functions ---

/**
 * Displays a modal with a larger view of an image.
 * @param {string} src - The source URL of the image.
 * @param {string} alt - The alt text for the image.
 */
function showImageModal(src, alt) {
    // Create modal elements dynamically
    const modal = document.createElement('div');
    modal.className = 'modal image-modal'; // Apply CSS classes for styling
    modal.innerHTML = `<div class="image-modal-content"><img src="${src}" alt="${alt || 'Pasted Image'}">
                       <button class="close-modal">×</button></div>`;
    // Close modal on click outside the image or on the close button
    modal.onclick = (e) => { 
        if (e.target === modal || e.target.classList.contains('close-modal')) {
            document.body.removeChild(modal);
        }
    };
    document.body.appendChild(modal);
}

/**
 * Copies the current search query (as a search link, e.g., "<<query>>") to the clipboard.
 * Depends on `sessionStorage` for `searchQuery`.
 */
function copySearchLink() {
    const query = sessionStorage.getItem('searchQuery') || '';
    navigator.clipboard.writeText(`<<${query}>>`)
        .then(() => alert('Search link copied!')) // User feedback
        .catch(err => alert('Failed to copy: ' + err)); // Error feedback
}

/**
 * Displays the advanced search modal.
 * The actual search execution is handled by `executeAdvancedSearch` (app.js).
 */
function showAdvancedSearch() {
    // Create modal elements dynamically
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="advanced-search-content">
            <h3>Advanced Search</h3>
            <div class="help-text">
                Enter a SQL query to search based on block properties. Example:<br>
                <code>SELECT * FROM notes WHERE id IN (SELECT note_id FROM properties WHERE property_key = 'status' AND property_value = 'done')</code>
            </div>
            <textarea id="advanced-search-query" placeholder="Enter your SQL query..."></textarea>
            <div class="button-group">
                <button class="btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                <button class="btn-primary" onclick="executeAdvancedSearch()">Search</button> 
            </div>
        </div>
    `;
    // `executeAdvancedSearch()` is expected to be a global function or defined in app.js for coordination.

    document.body.appendChild(modal);
    const textarea = modal.querySelector('#advanced-search-query');
    if (textarea) {
        textarea.focus(); // Auto-focus the query input
    }
    // Close modal on click outside the content area
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

/**
 * Fetches and displays a modal with a list of all pages.
 * TODO: The fetch call within this function should be moved to `api.js`.
 * @async
 * Depends on `navigateToPage` (from app.js).
 */
async function showAllPages() {
    try {
        // Direct API call - consider moving to api.js for consistency
        const response = await fetch('api/all_pages.php'); 
        if (!response.ok) throw new Error(`Failed to fetch all pages: ${response.statusText}`);
        const pages = await response.json();

        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `<div class="page-list-modal"><h3>All Pages</h3><div class="page-list">
            ${pages.map(page => 
                // Each list item navigates to the page and closes the modal on click
                `<div class="page-list-item" onclick="navigateToPage('${page.id.replace(/'/g, "\\'")}'); this.closest('.modal').remove();">
                    ${page.title || decodeURIComponent(page.id)}
                 </div>`
            ).join('')}
            </div><button class="btn-secondary" style="margin-top:15px;" onclick="this.closest('.modal').remove();">Close</button></div>`;
        
        // Close modal if backdrop is clicked
        modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
        document.body.appendChild(modal);
    } catch (error) { 
        console.error('Error loading all pages:', error); 
        alert('Failed to load all pages.'); // User feedback
    }
}

/**
 * Automatically closes brackets ([, {) when typed in a textarea.
 * @param {InputEvent} event - The input event object from the textarea.
 */
function handleAutoCloseBrackets(event) {
    const textarea = event.target;
    const typedChar = event.data; // The character that was just typed

    // Ensure it's a single character input
    if (typedChar === null || typedChar === undefined || typedChar.length !== 1) {
        return;
    }

    let closeBracketChar = null;
    if (typedChar === '[') {
        closeBracketChar = ']';
    } else if (typedChar === '{') {
        closeBracketChar = '}';
    }
    // Could be extended for other bracket types like '(' or '<'

    if (closeBracketChar) {
        const cursorPos = textarea.selectionStart; // Cursor position before auto-closing
        const value = textarea.value;

        // Insert the closing bracket
        const textBeforeCursor = value.substring(0, cursorPos);
        const textAfterCursor = value.substring(cursorPos);
        textarea.value = textBeforeCursor + closeBracketChar + textAfterCursor;

        // Restore cursor position to be between the brackets
        textarea.selectionStart = textarea.selectionEnd = cursorPos;
    }
}

/**
 * Handles snippet replacement in a textarea (e.g., :t -> {tag::}).
 * Triggers on space or enter after specific keywords.
 * @param {InputEvent|KeyboardEvent} event - The input or keydown event from the textarea.
 */
function handleSnippetReplacement(event) {
    const textarea = event.target;
    // Using setTimeout to allow the typed character (space/enter) to actually appear in the textarea
    // before we check the value. This makes the logic more reliable.
    setTimeout(() => {
        const cursorPos = textarea.selectionStart;
        const text = textarea.value;
        let textBeforeCursor = text.substring(0, cursorPos);
        
        let triggerChar = '';

        // Determine if the event that triggered this was a space or enter key.
        if(event.key === ' ' || event.key === 'Enter' || (event.data === ' ' && event.type === 'input')) {
             triggerChar = ' '; // Using space as the canonical trigger char for endsWith check
        } else if (event.type === 'input' && event.data !== null) {
            // If it's an input event but not a space (e.g. regular char typing), do nothing.
            return; 
        } else {
            // If it's another type of event (e.g. backspace, arrow keys), do nothing.
            return; 
        }

        let replacementMade = false; // To track if any snippet was replaced

        // Snippet for tags: :t<space/enter>
        if (textBeforeCursor.endsWith(':t' + triggerChar)) {
            const replacement = '{tag::}';
            const triggerFull = ':t' + triggerChar;
            // Replace the trigger text with the snippet
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            // Position cursor inside the snippet for easy typing
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length - 1; 
            replacementMade = true;
        }
        // Snippet for ISO timestamp: :r<space/enter> (r for record time)
        else if (textBeforeCursor.endsWith(':r' + triggerChar)) {
            const now = new Date();
            const timeString = now.toISOString();
            const replacement = `{time::${timeString}} `; // Add a trailing space for convenience
            const triggerFull = ':r' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length;
            replacementMade = true;
        }
        // Snippet for ISO date: :d<space/enter>
        else if (textBeforeCursor.endsWith(':d' + triggerChar)) {
            const now = new Date();
            const dateString = now.toISOString().split('T')[0];
            const replacement = `{date::${dateString}} `; // Add a trailing space
            const triggerFull = ':d' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length;
            replacementMade = true;
        }
        // Note: The `replacementMade` variable was in original code but not used to gate further logic.
        // If multiple snippets could be triggered, or if default behavior after snippet replacement
        // needs to be conditional, this variable would be useful.
    }, 0);
}

/**
 * Toggles the visibility of children of a note element in the outline.
 * @param {HTMLElement} noteElement - The DOM element of the note whose children are to be toggled.
 */
function toggleChildren(noteElement) {
    // noteElement is a DOM element
    noteElement.classList.toggle('children-hidden');
    const childrenContainer = noteElement.querySelector('.outline-children');
    if (childrenContainer) {
        if (noteElement.classList.contains('children-hidden')) {
            childrenContainer.style.display = 'none';
        } else {
            childrenContainer.style.display = '';
        }
    }
}

function clearActiveBlock() {
    // Add or remove 'children-hidden' class to control visibility via CSS
    noteElement.classList.toggle('children-hidden');
    const childrenContainer = noteElement.querySelector('.outline-children');
    if (childrenContainer) {
        // Explicitly set display style for immediate feedback, complementing the class toggle
        if (noteElement.classList.contains('children-hidden')) {
            childrenContainer.style.display = 'none';
        } else {
            childrenContainer.style.display = ''; // Reset to default display (block or flex)
        }
    }
}

/**
 * Clears the currently active (keyboard-focused) block.
 * Depends on global state: `activeBlockElement` (from state.js).
 */
function clearActiveBlock() {
    if (activeBlockElement) {
        activeBlockElement.classList.remove('block-keyboard-focus');
        activeBlockElement = null; // Reset global state
    }
}

/**
 * Handles global keydown events for keyboard navigation and shortcuts.
 * @param {KeyboardEvent} event - The keyboard event.
 * Depends on global state: `isEditorOpen`, `activeBlockElement`, `currentPage` (from state.js).
 * Depends on DOM elements: `outlineContainer` (from this file).
 * Calls coordinating functions from app.js: `createNote`, `editNote`, `handleOutdentNote`, `handleIndentNote`.
 * Calls UI functions from this file: `setActiveBlock`, `clearActiveBlock`, `navigateBlocks`.
 */
function handleGlobalKeyDown(event) {
    console.log('Global keydown listener triggered by key:', event.key, 'Ctrl:', event.ctrlKey, 'Shift:', event.shiftKey, 'Target:', event.target.tagName, 'at:', new Date().toLocaleTimeString());
    // Ignore keydowns if a modal is active (e.g. search modal, image modal)
    if (document.querySelector('.modal')) {
        return;
    }

    // Handle Control+Space and Control+D first
    if (event.ctrlKey && event.code === 'Space') {
        event.preventDefault();
        showPageSearchModal();
        return;
    }
    if (event.ctrlKey && event.key === 'd') {
        event.preventDefault();
        showFavoritesModal();
        return;
    }

    // Handle Shift+Enter first
    if (event.shiftKey && event.key === 'Enter') {
        event.preventDefault();
        showFavoritesModal();
        return;
    }

    // Handle Esc key within an active editor (e.g., to cancel editing)
    if (isEditorOpen) {
        if (event.key === 'Escape') {
            const cancelButton = document.querySelector('.note-editor .cancel-note');
            if (cancelButton) {
                cancelButton.click();
                event.preventDefault();
            }
        }
        return;
    }

    // If no block is active, ArrowDown/ArrowUp/Space might activate the first block or create a new one.
    if (!activeBlockElement && (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === ' ')) {
        const firstBlock = outlineContainer.querySelector('.outline-item:not(.note-editor-wrapper .outline-item)');
        if (firstBlock) {
            setActiveBlock(firstBlock);
            if (event.key === ' ') event.preventDefault();
        } else if (event.key === ' ' && currentPage && (!currentPage.notes || currentPage.notes.length === 0)) {
            createNote(null, 0);
            event.preventDefault();
            return;
        } else {
            return;
        }
    }

    if (!activeBlockElement) return; // Subsequent actions require an active block.

    // Keyboard shortcuts for an active block
    switch (event.key) {
        case 'ArrowUp':
            navigateBlocks(-1); // Move focus up. `navigateBlocks` is in this file.
            event.preventDefault();
            break;
        case 'ArrowDown':
            navigateBlocks(1);  // Move focus down.
            event.preventDefault();
            break;
        case ' ': // Space key to edit the active block
            if (activeBlockElement) {
                const noteId = activeBlockElement.dataset.noteId;
                const content = activeBlockElement.dataset.content; // Assumes raw content is stored on dataset
                editNote(noteId, content); // `editNote` (app.js) handles the process.
                event.preventDefault();
            }
            break;
        case 'Enter': // Enter key to create new notes
            if (activeBlockElement) {
                if (event.ctrlKey || event.metaKey) { // Ctrl/Cmd + Enter: Create a child note
                    const parentId = activeBlockElement.dataset.noteId;
                    const currentLevel = parseInt(activeBlockElement.dataset.level) || 0;
                    createNote(parentId, currentLevel + 1, null, null);
                } else { // Enter: Create a sibling note after the current one
                    const currentLevel = parseInt(activeBlockElement.dataset.level) || 0;
                    const parentOfActiveElement = activeBlockElement.parentElement.closest('.outline-item');
                    const siblingParentId = parentOfActiveElement ? parentOfActiveElement.dataset.noteId : null;

                    // Determine the order for the new sibling note
                    let intendedOrderForSibling = 0;
                    const siblingsContainer = activeBlockElement.parentElement;
                    const actualSiblings = Array.from(siblingsContainer.children)
                                               .filter(el => el.matches('.outline-item'));
                    const activeBlockIndexInActualSiblings = actualSiblings.indexOf(activeBlockElement);

                    if (activeBlockIndexInActualSiblings !== -1) {
                        intendedOrderForSibling = activeBlockIndexInActualSiblings + 1;
                    } else {
                        // Fallback if active block isn't found (should not happen in normal flow)
                        intendedOrderForSibling = actualSiblings.length;
                        console.warn("Active block not found among its actual siblings for order calculation. Appending.");
                    }
                    createNote(siblingParentId, currentLevel, activeBlockElement, intendedOrderForSibling);
                }
                event.preventDefault();
            }
            break;
        case 'Tab': // Tab for indenting, Shift+Tab for outdenting
            if (activeBlockElement) {
                if (event.shiftKey) {
                    handleOutdentNote(activeBlockElement.dataset.noteId, activeBlockElement); // `handleOutdentNote` (app.js)
                } else {
                    handleIndentNote(activeBlockElement.dataset.noteId, activeBlockElement); // `handleIndentNote` (app.js)
                }
                event.preventDefault();
            }
            break;
        case 'Escape': // Escape to clear active block focus
            clearActiveBlock();
            event.preventDefault();
            break;
    }
}

/**
 * Navigates between visible note blocks in the outline.
 * @param {number} direction - -1 to navigate up, 1 to navigate down.
 * Depends on global state: `activeBlockElement` (from state.js).
 * Depends on DOM elements: `outlineContainer` (from this file).
 * Calls UI function `setActiveBlock` (from this file).
 */
function navigateBlocks(direction) {
    // If no block is active and navigating down, activate the first visible block.
    if (!activeBlockElement && direction === 1) {
        const firstBlock = outlineContainer.querySelector('.outline-item:not(.note-editor-wrapper .outline-item)');
        if (firstBlock) setActiveBlock(firstBlock);
        return;
    }
    if (!activeBlockElement) return; // Cannot navigate without an active starting point.

    // Collect all potential items for navigation, excluding those within an active editor.
    let allItems = Array.from(outlineContainer.querySelectorAll('.outline-item'));
    allItems = allItems.filter(item => !item.closest('.note-editor-wrapper') || item === activeBlockElement);

    // Filter to get only currently visible items based on display style and parent visibility.
    const trulyVisibleItems = [];
    function getVisibleItemsRecursive(element) {
        if (element.matches('.outline-item') && getComputedStyle(element).display !== 'none') {
            trulyVisibleItems.push(element);
            // If an item has children and is not collapsed, recurse into its children.
            if (!element.classList.contains('children-hidden')) {
                const childrenContainer = element.querySelector(':scope > .outline-children');
                if (childrenContainer) {
                    Array.from(childrenContainer.children).forEach(child => getVisibleItemsRecursive(child));
                }
            }
        } else if(element.matches('.outline-children') && getComputedStyle(element).display !== 'none'){
            // If a children container itself is visible, recurse into its direct children.
             Array.from(element.children).forEach(child => getVisibleItemsRecursive(child));
        }
    }

    // Determine the starting point for collecting visible items based on zoom state.
    if (document.body.classList.contains('logseq-focus-active')) { // Zoomed-in view
        const focusedRootItem = outlineContainer.querySelector('.outline-item[data-level="0"]');
        if (focusedRootItem) {
            // Start with the root item of the focused view.
            if (trulyVisibleItems.indexOf(focusedRootItem) === -1 && getComputedStyle(focusedRootItem).display !== 'none') {
                 trulyVisibleItems.push(focusedRootItem);
            }
            // Then add its visible children.
            const childrenContainerOfFocused = focusedRootItem.querySelector(':scope > .outline-children');
            if (childrenContainerOfFocused && !focusedRootItem.classList.contains('children-hidden')) {
                 Array.from(childrenContainerOfFocused.children).forEach(child => getVisibleItemsRecursive(child));
            }
        }
    } else { // Normal (zoomed-out) view
        Array.from(outlineContainer.children).forEach(child => getVisibleItemsRecursive(child));
    }

    if (trulyVisibleItems.length === 0) return; // No visible items to navigate.

    let currentIndex = trulyVisibleItems.indexOf(activeBlockElement);

    // If current active block is not in the list (e.g., it became hidden), determine a sensible start.
    if (currentIndex === -1) {
        if (trulyVisibleItems.length > 0) {
            currentIndex = (direction === 1) ? -1 : trulyVisibleItems.length; // Start before first or after last.
        } else { 
            return; // Should not happen if trulyVisibleItems.length > 0.
        }
    }

    const nextIndex = currentIndex + direction;

    // Set focus to the next valid item, clamping to bounds.
    if (nextIndex >= 0 && nextIndex < trulyVisibleItems.length) {
        setActiveBlock(trulyVisibleItems[nextIndex]);
    } else if (nextIndex < 0 && trulyVisibleItems.length > 0) {
        setActiveBlock(trulyVisibleItems[0]); // Wrap to first if navigating up from first.
    } else if (nextIndex >= trulyVisibleItems.length && trulyVisibleItems.length > 0) {
        setActiveBlock(trulyVisibleItems[trulyVisibleItems.length - 1]); // Wrap to last if navigating down from last.
    }
}

// --- Sidebar Toggle Functionality ---

/**
 * Toggles the sidebar visibility on mobile devices and handles the collapse state.
 * The sidebar will automatically collapse on mobile devices and can be toggled with a button.
 */
function initializeSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    const toggleButton = document.getElementById('sidebar-toggle');
    let isCollapsed = false;

    function toggleSidebar() {
        isCollapsed = !isCollapsed;
        sidebar.classList.toggle('collapsed');
        toggleButton.innerHTML = isCollapsed ? '✕' : '☰';
    }

    toggleButton.addEventListener('click', toggleSidebar);

    // Handle window resize
    function handleResize() {
        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            sidebar.classList.add('collapsed');
            toggleButton.innerHTML = '✕';
            toggleButton.style.display = 'flex';
        } else {
            sidebar.classList.remove('collapsed');
            toggleButton.innerHTML = '☰';
            toggleButton.style.display = 'none';
        }
    }

    // Initial setup
    handleResize();
    window.addEventListener('resize', handleResize);
}

// Initialize sidebar toggle when the DOM is loaded
document.addEventListener('DOMContentLoaded', initializeSidebarToggle);

// --- Page Search Functionality ---

let pageSearchModal = null;
let pageSearchInput = null;
let pageSearchResults = null;
let selectedIndex = -1;
let searchTimeout = null;

/**
 * Shows the page search modal and initializes the search functionality.
 * The modal can be triggered with Control+Space and allows searching through pages.
 */
function showPageSearchModal() {
    if (pageSearchModal) return; // Don't show if already visible

    // Create modal elements
    const overlay = document.createElement('div');
    overlay.className = 'page-search-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'page-search-modal';
    modal.innerHTML = `
        <input type="text" class="page-search-input" placeholder="Search pages...">
        <div class="page-search-results"></div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    // Store references
    pageSearchModal = modal;
    pageSearchInput = modal.querySelector('.page-search-input');
    pageSearchResults = modal.querySelector('.page-search-results');
    
    // Focus input
    pageSearchInput.focus();
    
    // Add event listeners
    pageSearchInput.addEventListener('input', handlePageSearch);
    pageSearchInput.addEventListener('keydown', handlePageSearchKeydown);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closePageSearchModal();
        }
    });
}

/**
 * Closes the page search modal and cleans up.
 */
function closePageSearchModal() {
    if (!pageSearchModal) return;
    
    pageSearchModal.parentElement.remove();
    pageSearchModal = null;
    pageSearchInput = null;
    pageSearchResults = null;
    selectedIndex = -1;
}

/**
 * Handles the search input and updates results.
 * @param {Event} event - The input event.
 */
async function handlePageSearch(event) {
    const query = event.target.value.trim();
    
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    
    searchTimeout = setTimeout(async () => {
        if (!query) {
            pageSearchResults.innerHTML = '';
            return;
        }
        
        try {
            const response = await fetch(`api/search_pages.php?q=${encodeURIComponent(query)}`);
            if (!response.ok) throw new Error('Search failed');
            
            const pages = await response.json();
            renderPageSearchResults(pages);
        } catch (error) {
            console.error('Search error:', error);
            pageSearchResults.innerHTML = '<div class="page-search-item">Error performing search</div>';
        }
    }, 150);
}

/**
 * Renders the search results in the modal.
 * @param {Array} pages - Array of page objects with id, title, and date properties.
 */
function renderPageSearchResults(pages) {
    if (!pages.length) {
        pageSearchResults.innerHTML = '<div class="page-search-item">No results found</div>';
        return;
    }
    
    pageSearchResults.innerHTML = pages.map((page, index) => `
        <div class="page-search-item ${index === selectedIndex ? 'selected' : ''}" data-index="${index}">
            <span class="page-title">${page.title || decodeURIComponent(page.id)}</span>
            <span class="page-date">${new Date(page.date).toLocaleDateString()}</span>
        </div>
    `).join('');
    
    // Add click handlers
    pageSearchResults.querySelectorAll('.page-search-item').forEach(item => {
        item.addEventListener('click', () => {
            const index = parseInt(item.dataset.index);
            navigateToPage(pages[index].id);
            closePageSearchModal();
        });
    });
}

/**
 * Handles keyboard navigation in the search modal.
 * @param {KeyboardEvent} event - The keyboard event.
 */
function handlePageSearchKeydown(event) {
    const items = pageSearchResults.querySelectorAll('.page-search-item');
    
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
            updateSelectedItem(items);
            break;
            
        case 'ArrowUp':
            event.preventDefault();
            selectedIndex = Math.max(selectedIndex - 1, 0);
            updateSelectedItem(items);
            break;
            
        case 'Enter':
            event.preventDefault();
            if (selectedIndex >= 0 && items[selectedIndex]) {
                items[selectedIndex].click();
            }
            break;
            
        case 'Escape':
            event.preventDefault();
            closePageSearchModal();
            break;
    }
}

/**
 * Updates the selected item in the search results.
 * @param {NodeList} items - The list of search result items.
 */
function updateSelectedItem(items) {
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedIndex);
        if (index === selectedIndex) {
            item.scrollIntoView({ block: 'nearest' });
        }
    });
}

// Add global keyboard shortcut
document.addEventListener('keydown', (event) => {
    if (event.ctrlKey && event.code === 'Space') {
        event.preventDefault();
        showPageSearchModal();
    }
});

// --- Favorites Functionality ---

let favoritesModal = null;

/**
 * Toggles the favorite status of a note.
 * @param {string} noteId - The ID of the note to toggle.
 * @param {HTMLElement} starButton - The star button element.
 */
async function toggleFavorite(noteId, starButton) {
    console.log('Toggling favorite for note ID:', noteId); // Log API Request
    try {
        const response = await fetch('api/toggle_favorite.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ note_id: noteId })
        });
        
        if (!response.ok) throw new Error('Failed to toggle favorite');
        
        const result = await response.json();
        console.log('API response for toggle favorite:', result); // Log API Response
        if (result.error) throw new Error(result.error);
        
        console.log('toggleFavorite API response - result.is_favorite:', result.is_favorite); // Added log
        starButton.classList.toggle('active', result.is_favorite);
        console.log('Star button classList after toggle (noteId: ' + noteId + '):', starButton.classList);
        console.log('Raw starButton element:', starButton); // To inspect its properties in console
        
    } catch (error) {
        console.error('Error toggling favorite:', error);
        alert('Failed to update favorite status');
    }
}

/**
 * Shows the favorites modal with all favorited notes.
 */
async function showFavoritesModal() {
    console.log('showFavoritesModal CALLED at:', new Date().toLocaleTimeString());
    if (favoritesModal) return;
    
    try {
        const response = await fetch('api/get_favorites.php');
        if (!response.ok) throw new Error('Failed to fetch favorites');
        
        const favorites = await response.json();
        if (favorites.error) throw new Error(favorites.error);
        
        const overlay = document.createElement('div');
        overlay.className = 'page-search-overlay';
        
        const modal = document.createElement('div');
        modal.className = 'favorites-modal';
        
        let favoritesListHtml;
        // Ensure favorites is an array before trying to access its length or map over it.
        if (Array.isArray(favorites) && favorites.length > 0) {
            favoritesListHtml = favorites.map(note => {
                console.log('Favorites Modal: Setting up View button for page_id:', note.page_id, 'Processed page_id for onclick:', String(note.page_id).replace(/'/g, "\\'"));
                return `
                <div class="favorite-item">
                    <div class="note-content">${note.content}</div>
                    <div class="note-actions">
                        <button class="btn-secondary" onclick="navigateToPage('${String(note.page_id).replace(/'/g, "\\'")}')">View</button>
                        <button class="btn-secondary" onclick="removeFavorite('${String(note.id).replace(/'/g, "\\'")}', this)">Remove</button>
                    </div>
                </div>`;
            }).join('');
        } else {
            favoritesListHtml = '<div class="favorites-empty-message" style="padding: 10px; text-align: center; color: #555;">No favorite notes yet</div>';
        }
        
        modal.innerHTML = `
            <h3>Favorite Notes</h3>
            <div class="favorites-list">
                ${favoritesListHtml}
            </div>
            <button class="btn-secondary" style="margin-top:15px;" onclick="closeFavoritesModal()">Close</button>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        favoritesModal = overlay; // Assign the created overlay to the global variable
        
        // Close on overlay click - Listener attached to the global favoritesModal reference
        favoritesModal.addEventListener('click', (e) => {
            // Check if the direct target of the click is the overlay itself
            if (e.target === favoritesModal) { 
                closeFavoritesModal();
            }
        });
        
    } catch (error) {
        console.error('Error showing favorites:', error);
        
        // Ensure a modal structure exists to display the error
        if (!favoritesModal) {
            favoritesModal = document.createElement('div');
            favoritesModal.className = 'page-search-overlay'; // Use the same class as successful path for consistency
            document.body.appendChild(favoritesModal);
            // Add click listener for this new overlay
            favoritesModal.addEventListener('click', (e) => {
                if (e.target === favoritesModal) {
                    closeFavoritesModal();
                }
            });
        }

        let modalContentDiv = favoritesModal.querySelector('.favorites-modal');
        if (!modalContentDiv) {
            modalContentDiv = document.createElement('div');
            modalContentDiv.className = 'favorites-modal';
            favoritesModal.innerHTML = ''; // Clear any partial content from the overlay
            favoritesModal.appendChild(modalContentDiv);
        }
        
        modalContentDiv.innerHTML = `
            <h3>Favorite Notes</h3>
            <p class="error-message" style="color: red; padding: 10px;">Failed to load favorites: ${error.message}</p>
            <button class="btn-secondary" style="margin-top:15px;" onclick="closeFavoritesModal()">Close</button>
        `;
    }
}

/**
 * Closes the favorites modal.
 */
function closeFavoritesModal() {
    console.log('Attempting to close favorites modal NOW. Current favoritesModal:', favoritesModal);
    if (!favoritesModal) {
        console.log('favoritesModal is null or undefined, cannot remove.');
        return;
    }

    console.log('Before removing favoritesModal. Parent node:', favoritesModal.parentNode);
    // Try the standard .remove() first
    favoritesModal.remove();
    console.log('After favoritesModal.remove(). Is it still in DOM (document.body.contains)?:', document.body.contains(favoritesModal));

    // If .remove() failed, try parentNode.removeChild() as a fallback
    if (document.body.contains(favoritesModal)) {
        console.warn('favoritesModal.remove() did not remove the element from document.body. Trying parentNode.removeChild().');
        if (favoritesModal.parentNode) {
            console.log('Parent node exists. Attempting parentNode.removeChild().');
            favoritesModal.parentNode.removeChild(favoritesModal);
            console.log('After parentNode.removeChild(). Is it still in DOM?:', document.body.contains(favoritesModal));
        } else {
            console.error('Cannot use parentNode.removeChild() because favoritesModal.parentNode is null.');
        }
    }

    // Only set to null if successfully removed or if it's already not in DOM
    if (!document.body.contains(favoritesModal)) {
        console.log('Modal successfully removed from DOM (or was already gone). Setting favoritesModal to null.');
        favoritesModal = null;
    } else {
        console.error('Modal is STILL in the DOM after all removal attempts!');
    }
}

/**
 * Removes a note from favorites.
 * @param {string} noteId - The ID of the note to remove.
 * @param {HTMLElement} button - The remove button element.
 */
async function removeFavorite(noteId, button) {
    try {
        const response = await fetch('api/toggle_favorite.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ note_id: noteId })
        });
        
        if (!response.ok) throw new Error('Failed to remove favorite');
        
        const result = await response.json();
        if (result.error) throw new Error(result.error);
        
        // Remove the item from the list
        const favoriteItem = button.closest('.favorite-item');
        if (favoriteItem) {
            favoriteItem.remove();
        }
        
        // If no favorites left, show message
        const list = document.querySelector('.favorites-modal .favorites-list');
        if (list && list.children.length === 0) {
            list.innerHTML = '<div class="favorites-empty-message" style="padding: 10px; text-align: center; color: #555;">No favorite notes yet</div>';
        }
        
    } catch (error) {
        console.error('Error removing favorite:', error);
        alert('Failed to remove favorite: ' + error.message);
    }
}

// Update the global keyboard shortcut
document.addEventListener('keydown', (event) => {
    if (event.ctrlKey && event.key === 'd') {
        event.preventDefault();
        showFavoritesModal();
    }
});
