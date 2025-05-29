// Functions and variables related to UI interactions
// (e.g., event handlers, DOM manipulation, modal logic)

// --- Global State for UI related to Backlinks ---
let backlinksDataLoadedForCurrentPage = false;
let currentBacklinksOffset = 0;
const backlinksPerPage = 5; // Number of backlinks to load per page/batch

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

    initializeBacklinksToggle(); // Initialize the backlinks toggle functionality
    initializeNoteActions(); // Add this line

    // Attach drag and drop handlers here!
    if (outlineContainer) {
        outlineContainer.addEventListener('dragover', (event) => {
            event.preventDefault();
            // Check if items being dragged are files
            if (event.dataTransfer.types.includes('Files')) {
                // Check if the drag is over a valid drop target (an outline item or the container itself if empty)
                const potentialTarget = event.target.closest('.outline-item');
                if (potentialTarget || outlineContainer.children.length === 0 || event.target === outlineContainer) {
                     outlineContainer.classList.add('drag-over');
                } else {
                    // If dragging over gaps between items, don't show global drag-over
                    outlineContainer.classList.remove('drag-over');
                }
                // Optionally, add class to specific item being hovered over
                if(potentialTarget) {
                    potentialTarget.classList.add('drag-over-item');
                }
            }
        });

        outlineContainer.addEventListener('dragleave', (event) => {
            // Remove global drag-over indicator
            outlineContainer.classList.remove('drag-over');
            // Remove specific item drag-over indicator
            const potentialTarget = event.target.closest('.outline-item');
            if (potentialTarget) {
                potentialTarget.classList.remove('drag-over-item');
            }
            // More robust check if leaving the container or entering a child that is not a drop target
            if (!outlineContainer.contains(event.relatedTarget) || event.relatedTarget === null) {
                 outlineContainer.classList.remove('drag-over');
            }
            // Clean up any item-specific highlights if we truly left the container
            const highlightedItems = outlineContainer.querySelectorAll('.drag-over-item');
            highlightedItems.forEach(item => item.classList.remove('drag-over-item'));
        });

        outlineContainer.addEventListener('drop', async (event) => {
            event.preventDefault();
            outlineContainer.classList.remove('drag-over');
            const highlightedItems = outlineContainer.querySelectorAll('.drag-over-item');
            highlightedItems.forEach(item => item.classList.remove('drag-over-item'));

            const files = event.dataTransfer.files;
            if (files.length === 0) {
                return;
            }

            let targetNoteElement = null;
            // Attempt to find the specific outline item the drop occurred on
            let droppedOnItem = event.target.closest('.outline-item');

            if (droppedOnItem) {
                targetNoteElement = droppedOnItem;
            } else if (activeBlockElement && activeBlockElement.matches('.outline-item')) {
                // Fallback to the globally active block element if the drop was not on a specific item
                // but an item is active.
                targetNoteElement = activeBlockElement;
            }

            if (targetNoteElement && targetNoteElement.dataset.noteId) {
                const noteId = targetNoteElement.dataset.noteId;
                if (typeof window.handleDroppedFiles === 'function') {
                    console.log(`Drop successful on noteId: ${noteId}. Passing to handleDroppedFiles.`);
                    window.handleDroppedFiles(noteId, files);
                } else {
                    console.error('handleDroppedFiles function is not available.');
                    alert('File drop handling is not properly configured.');
                }
            } else {
                let message = 'To attach files, please drop them directly onto a specific note block.';
                if (!droppedOnItem && !activeBlockElement) {
                    message += ' No note is currently active or was directly targeted by the drop.';
                } else if (!droppedOnItem && activeBlockElement) {
                    // This case means the drop was in an empty area, but a block was "active" elsewhere.
                    // You might want to confirm if the user wants to attach to this active block.
                    message += ` The drop was not on a specific note. The currently active note is "${activeBlockElement.dataset.noteId}". Consider dropping directly onto the desired note.`;
                    // Example: if (confirm(message + "\n\nAttach to active note?")) { /* proceed with activeBlockElement */ }
                }
                console.warn('File drop failed to identify target note. Alerting user.');
                alert(message);
            }
        });
    }
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

// --- Paste Image Handling ---
window.handlePastedImage = async function(event, noteId) {
    if (!noteId) {
        return;
    }

    const items = (event.clipboardData || event.originalEvent.clipboardData).items;
    let imageFile = null;

    for (const item of items) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
            imageFile = item.getAsFile();
            break; // Handle first image found
        }
    }

    if (!imageFile) {
        return; // No image file found in paste items, let default paste action proceed.
    }

    event.preventDefault(); // Prevent default paste action

    // Generate a temporary unique ID for this upload
    const tempId = 'temp_' + Date.now();
    const extension = imageFile.type.split('/')[1] || 'png';
    const tempFilename = `${tempId}.${extension}`;
    
    // Insert temporary markdown immediately
    const tempMarkdown = `![Uploading...](uploads/temp/${tempFilename})`;
    const textarea = event.target;
    
    // Use document.execCommand for inserting text to support undo/redo and better cursor handling
    if (!document.execCommand("insertText", false, tempMarkdown)) {
        // Fallback if execCommand fails
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        textarea.value = textarea.value.substring(0, start) + tempMarkdown + textarea.value.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + tempMarkdown.length;
    }

    // Trigger input event for any frameworks/listeners that depend on it
    textarea.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));

    try {
        // Upload the file in the background
        const responseData = await uploadFileAPI(noteId, imageFile);

        if (!responseData || !responseData.success || !responseData.filename) {
            throw new Error("Upload response did not include a filename or indicate success.");
        }

        // Replace the temporary markdown with the actual one
        const finalMarkdown = `![Pasted Image](uploads/${responseData.filename})`;
        const currentValue = textarea.value;
        const tempMarkdownIndex = currentValue.indexOf(tempMarkdown);
        
        if (tempMarkdownIndex !== -1) {
            textarea.value = currentValue.substring(0, tempMarkdownIndex) + 
                           finalMarkdown + 
                           currentValue.substring(tempMarkdownIndex + tempMarkdown.length);
            
            // Trigger input event again for the final update
            textarea.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
        }

        console.log(`Image pasted and uploaded as ${responseData.filename} to note ${noteId}`);

    } catch (error) {
        console.error('Error uploading pasted image:', error);
        // Replace the temporary markdown with an error message
        const errorMarkdown = `[Error uploading image: ${error.message}]`;
        const currentValue = textarea.value;
        const tempMarkdownIndex = currentValue.indexOf(tempMarkdown);
        
        if (tempMarkdownIndex !== -1) {
            textarea.value = currentValue.substring(0, tempMarkdownIndex) + 
                           errorMarkdown + 
                           currentValue.substring(tempMarkdownIndex + tempMarkdown.length);
            
            // Trigger input event for the error update
            textarea.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
        }
    }
};

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

    // Ignore keydowns if user is typing in a textarea or input field
    if (event.target.tagName === 'TEXTAREA' || event.target.tagName === 'INPUT') {
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
 * Initializes the left sidebar toggle functionality.
 */
async function initializeSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    const toggleButton = document.getElementById('sidebar-toggle');
    const mainContent = document.querySelector('.main-content');
    
    if (!sidebar || !toggleButton || !mainContent) {
        console.warn("Left sidebar toggle elements not found. Skipping initialization.");
        return;
    }

    // Idempotency check for event listener
    if (toggleButton.dataset.initialized === 'true') {
        return;
    }
    toggleButton.dataset.initialized = 'true';

    // Subscribe to state changes
    UIState.subscribe('leftSidebarCollapsed', (isCollapsed) => {
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.remove('main-content-shifted-right');
            toggleButton.innerHTML = '☰';
        } else {
            sidebar.classList.remove('collapsed');
            mainContent.classList.add('main-content-shifted-right');
            toggleButton.innerHTML = '✕';
        }
    });

    // Apply initial state
    const isInitiallyCollapsed = UIState.get('leftSidebarCollapsed');
    if (isInitiallyCollapsed) {
        sidebar.classList.add('collapsed');
        mainContent.classList.remove('main-content-shifted-right');
        toggleButton.innerHTML = '☰';
    } else {
        sidebar.classList.remove('collapsed');
        mainContent.classList.add('main-content-shifted-right');
        toggleButton.innerHTML = '✕';
    }

    toggleButton.style.display = 'flex'; // Always visible

    toggleButton.addEventListener('click', async () => {
        const newIsCollapsed = !UIState.get('leftSidebarCollapsed');
        const success = await UIState.set('leftSidebarCollapsed', newIsCollapsed);
        if (!success) {
            // State update failed, UI will be reverted by the state system
            alert("Failed to save sidebar preference. Please try again.");
        }
    });

    // Handle window resize
    function handleResize() {
        toggleButton.style.display = 'flex';
    }
    window.addEventListener('resize', handleResize);
}

/**
 * Initializes the right sidebar toggle functionality.
 */
async function initializeRightSidebarToggle() {
    const rightSidebar = document.querySelector('.right-sidebar');
    const toggleButton = document.getElementById('right-sidebar-toggle');
    const mainContent = document.querySelector('.main-content');

    if (!rightSidebar || !toggleButton || !mainContent) {
        console.warn('Right sidebar toggle elements not found. Skipping initialization.');
        return;
    }

    // Idempotency check for event listener
    if (toggleButton.dataset.initialized === 'true') {
        return;
    }
    toggleButton.dataset.initialized = 'true';

    // Subscribe to state changes
    UIState.subscribe('rightSidebarCollapsed', (isCollapsed) => {
        if (isCollapsed) {
            rightSidebar.classList.add('collapsed');
            mainContent.classList.remove('main-content-shifted-left');
            toggleButton.innerHTML = '☰';
        } else {
            rightSidebar.classList.remove('collapsed');
            mainContent.classList.add('main-content-shifted-left');
            toggleButton.innerHTML = '✕';
        }
    });

    // Apply initial state
    const isInitiallyCollapsed = UIState.get('rightSidebarCollapsed');
    if (isInitiallyCollapsed) {
        rightSidebar.classList.add('collapsed');
        mainContent.classList.remove('main-content-shifted-left');
        toggleButton.innerHTML = '☰';
    } else {
        rightSidebar.classList.remove('collapsed');
        mainContent.classList.add('main-content-shifted-left');
        toggleButton.innerHTML = '✕';
    }

    toggleButton.addEventListener('click', async () => {
        const newIsCollapsed = !UIState.get('rightSidebarCollapsed');
        const success = await UIState.set('rightSidebarCollapsed', newIsCollapsed);
        if (!success) {
            // State update failed, UI will be reverted by the state system
            alert("Failed to save sidebar preference. Please try again.");
        }
    });
}

/**
 * Initializes the toolbar visibility functionality.
 */
async function initializeLeftSidebar() {
    const toolbarToggle = document.getElementById('toolbar-toggle');
    if (!toolbarToggle) return;

    // Subscribe to state changes
    UIState.subscribe('toolbarVisible', (isVisible) => {
        const noteActions = document.querySelectorAll('.note-actions');
        noteActions.forEach(action => {
            action.style.display = isVisible ? 'flex' : 'none';
        });
        toolbarToggle.textContent = isVisible ? 'Hide toolbar' : 'Show toolbar';
    });

    // Apply initial state
    const isInitiallyVisible = UIState.get('toolbarVisible');
    const noteActions = document.querySelectorAll('.note-actions');
    noteActions.forEach(action => {
        action.style.display = isInitiallyVisible ? 'flex' : 'none';
    });
    toolbarToggle.textContent = isInitiallyVisible ? 'Hide toolbar' : 'Show toolbar';

    toolbarToggle.addEventListener('click', async (e) => {
        e.preventDefault();
        const newVisibility = !UIState.get('toolbarVisible');
        const success = await UIState.set('toolbarVisible', newVisibility);
        if (!success) {
            // State update failed, UI will be reverted by the state system
            alert("Failed to save toolbar preference. Please try again.");
        }
    });
}

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
    // First ensure any existing modal is properly removed
    if (favoritesModal) {
        const existingModal = document.querySelector('.page-search-overlay');
        if (existingModal) {
            existingModal.remove();
        }
        favoritesModal = null;
    }
    
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
        if (Array.isArray(favorites) && favorites.length > 0) {
            favoritesListHtml = favorites.map(note => `
                <div class="favorite-item">
                    <div class="note-content">${note.content}</div>
                    <div class="note-actions">
                        <button class="btn-secondary" onclick="navigateToPage('${String(note.page_id).replace(/'/g, "\\'")}')">View</button>
                        <button class="btn-secondary" onclick="removeFavorite('${String(note.id).replace(/'/g, "\\'")}', this)">Remove</button>
                    </div>
                </div>
            `).join('');
        } else {
            favoritesListHtml = '<div class="favorites-empty-message" style="padding: 10px; text-align: center; color: #555;">No favorite notes yet</div>';
        }
        
        modal.innerHTML = `
            <h3>Favorite Notes</h3>
            <div class="favorites-list">
                ${favoritesListHtml}
            </div>
            <button class="btn-secondary close-favorites-btn" style="margin-top:15px;">Close</button>
        `;
        
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        favoritesModal = overlay;
        
        // Create a single close handler function
        const closeHandler = (e) => {
            if (e.target === overlay || e.target.classList.contains('close-favorites-btn')) {
                e.preventDefault();
                e.stopPropagation();
                closeFavoritesModal();
            }
        };
        
        // Add event listener for both overlay and close button
        overlay.addEventListener('click', closeHandler);
        
    } catch (error) {
        console.error('Error showing favorites:', error);
        alert('Failed to load favorites');
    }
}

function closeFavoritesModal() {
    if (!favoritesModal) return;
    
    // Store reference to the modal
    const modalToRemove = favoritesModal;
    
    // Clear the global reference first to prevent multiple close attempts
    favoritesModal = null;
    
    // Remove the modal from DOM
    if (modalToRemove && modalToRemove.parentNode) {
        modalToRemove.parentNode.removeChild(modalToRemove);
    }
    
    // Double-check if any modal is still in the DOM and remove it
    const remainingModal = document.querySelector('.page-search-overlay');
    if (remainingModal) {
        remainingModal.remove();
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

// --- Backlinks Section Toggle and Lazy Loading ---

/**
 * Initializes the event listener and functionality for the collapsible backlinks section.
 */
function initializeBacklinksToggle() {
    const toggleButton = document.getElementById('backlinks-toggle');
    const backlinksContainer = document.getElementById('backlinks-container');
    const toggleArrow = toggleButton ? toggleButton.querySelector('.toggle-arrow') : null;

    if (!toggleButton || !backlinksContainer || !toggleArrow) {
        console.warn('Backlinks toggle elements not found. Feature will not be initialized.');
        return;
    }

    toggleButton.addEventListener('click', async () => {
        const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';
        
        if (isExpanded) {
            // Collapse the section
            toggleButton.setAttribute('aria-expanded', 'false');
            backlinksContainer.style.display = 'none';
            toggleArrow.style.transform = 'rotate(0deg)';
        } else {
            // Expand the section
            toggleButton.setAttribute('aria-expanded', 'true');
            backlinksContainer.style.display = 'block';
            toggleArrow.style.transform = 'rotate(90deg)';

            if (!backlinksDataLoadedForCurrentPage && currentPage && currentPage.id) {
                currentBacklinksOffset = 0; // Reset offset for initial load
                // Call loadAndRenderBacklinks from render.js
                // Assumes currentPage is accessible from state.js (e.g., window.currentPage or a state object)
                await loadAndRenderBacklinks(currentPage.id, backlinksPerPage, currentBacklinksOffset, true); 
                backlinksDataLoadedForCurrentPage = true;
            } else if (!currentPage || !currentPage.id) {
                console.warn("Cannot load backlinks: currentPage or currentPage.id is not defined.");
                backlinksContainer.innerHTML = "<p>Could not load backlinks: Page context is missing.</p>";
            }
        }
    });
}

/**
 * Updates the backlinks meta information (count and "Load More" button).
 * @param {number} newlyLoadedCount - The number of items just loaded.
 * @param {number} totalThreads - The total number of backlink threads available.
 */
function updateBacklinksMeta(newlyLoadedCount, totalThreads) {
    const backlinksContainer = document.getElementById('backlinks-container');
    if (!backlinksContainer) return;

    let metaContainer = document.getElementById('backlinks-meta-container');
    if (!metaContainer) {
        metaContainer = document.createElement('div');
        metaContainer.id = 'backlinks-meta-container';
        metaContainer.className = 'backlinks-meta';
        // Insert meta container after the main content of backlinks, but before it's potentially closed.
        // If backlinksContainer directly holds items, this will append. If it has a sub-list div, adjust.
        backlinksContainer.appendChild(metaContainer);
    }

    let countTextElement = document.getElementById('backlinks-count-text');
    if (!countTextElement) {
        countTextElement = document.createElement('span');
        countTextElement.id = 'backlinks-count-text';
        metaContainer.appendChild(countTextElement);
    }

    let loadMoreButton = document.getElementById('load-more-backlinks');
    if (!loadMoreButton) {
        loadMoreButton = document.createElement('button');
        loadMoreButton.id = 'load-more-backlinks';
        loadMoreButton.textContent = 'Load More';
        // Event listener will be set (or re-set) below
        metaContainer.appendChild(loadMoreButton);
    }
    
    // Calculate the total number of backlinks currently shown
    // currentBacklinksOffset was the offset *before* this load.
    // So, new total shown = old offset + newlyLoadedCount
    const currentlyShownCount = currentBacklinksOffset + newlyLoadedCount;
    countTextElement.textContent = `Showing ${currentlyShownCount} out of ${totalThreads} backlinks`;

    if (currentlyShownCount < totalThreads) {
        loadMoreButton.style.display = 'block'; // Or 'inline-block' based on styling
        currentBacklinksOffset = currentlyShownCount; // Update offset for the *next* load
        
        // Remove old listener before adding new one to prevent duplicates if this function is called multiple times
        const newLoadMoreButton = loadMoreButton.cloneNode(true);
        loadMoreButton.parentNode.replaceChild(newLoadMoreButton, loadMoreButton);
        newLoadMoreButton.addEventListener('click', async () => {
            if (currentPage && currentPage.id) {
                // Disable button temporarily to prevent multiple clicks
                newLoadMoreButton.disabled = true; 
                newLoadMoreButton.textContent = 'Loading...';
                await loadAndRenderBacklinks(currentPage.id, backlinksPerPage, currentBacklinksOffset, false);
                // Re-enable should happen after loadAndRenderBacklinks calls updateBacklinksMeta again,
                // which will recreate the button or update its state.
            }
        });

    } else {
        loadMoreButton.style.display = 'none';
        currentBacklinksOffset = currentlyShownCount; // All items are loaded
    }
}


/**
 * Resets the state of the backlinks section.
 * This should be called when navigating to a new page.
 */
function resetBacklinksState() {
    backlinksDataLoadedForCurrentPage = false;
    currentBacklinksOffset = 0; // Reset offset

    const toggleButton = document.getElementById('backlinks-toggle');
    const backlinksContainer = document.getElementById('backlinks-container');
    const toggleArrow = toggleButton ? toggleButton.querySelector('.toggle-arrow') : null;

    if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', 'false');
    }
    if (backlinksContainer) {
        backlinksContainer.style.display = 'none';
        backlinksContainer.innerHTML = ''; // Clear previous backlinks and meta container
    }
    if (toggleArrow) {
        toggleArrow.style.transform = 'rotate(0deg)';
    }
    console.log("Backlinks state reset for new page.");
}
// Note: `resetBacklinksState()` needs to be called from `app.js` when a new page is loaded,
// for example, at the beginning of `navigateToPage(pageId)` or `loadPage(pageId)`.


// --- Right Sidebar Custom Notes Functionality ---

/**
 * Renders custom notes fetched by a SQL query into the specified container.
 * @param {Array<Object>} notesArray - An array of note objects to render.
 * @param {HTMLElement} containerElement - The DOM element to render the notes into.
 */
function renderCustomNotes(notesArray, containerElement) {
    containerElement.innerHTML = ''; // Clear previous results

    if (!notesArray || notesArray.length === 0) {
        const noResultsMessage = document.createElement('p');
        noResultsMessage.textContent = 'No notes found for this query, or query returned no results.';
        noResultsMessage.style.padding = '10px';
        containerElement.appendChild(noResultsMessage);
        return;
    }

    notesArray.forEach(note => {
        const noteDiv = document.createElement('div');
        noteDiv.className = 'right-sidebar-note-item'; // Use class from CSS for styling

        const titleSpan = document.createElement('span');
        titleSpan.style.fontWeight = 'bold';
        
        let contentPreview = note.content ? (note.content.substring(0, 100) + (note.content.length > 100 ? '...' : '')) : '(No content)';
        
        if (note.page_id) {
            const pageLink = document.createElement('a');
            pageLink.href = `#${note.page_id}`;
            pageLink.textContent = `Page: ${note.page_id}`;
            pageLink.title = `Go to page ${note.page_id}`;
            pageLink.addEventListener('click', (e) => {
                e.preventDefault();
                navigateToPage(note.page_id); // navigateToPage is in app.js
                // Optionally close right sidebar if desired UX
                // document.querySelector('.right-sidebar').classList.add('collapsed');
                // document.querySelector('.main-content').classList.remove('main-content-shifted-left');
                // document.getElementById('right-sidebar-toggle').innerHTML = 'SQL';
            });
            noteDiv.appendChild(pageLink);
            
            const contentLink = document.createElement('a');
            contentLink.href = `#${note.page_id}`; // Could be more specific if block_id is available and navigation supports it
            contentLink.textContent = contentPreview;
            contentLink.title = `View note on page ${note.page_id}`;
            contentLink.style.display = 'block';
            contentLink.style.marginTop = '5px';
            contentLink.addEventListener('click', (e) => {
                 e.preventDefault();
                 navigateToPage(note.page_id);
                 // Potentially add logic here to scroll to the specific note/block if block_id is available in `note`
            });
            noteDiv.appendChild(contentLink);

        } else {
            titleSpan.textContent = `ID: ${note.id || 'N/A'}`;
            noteDiv.appendChild(titleSpan);
            const contentP = document.createElement('p');
            contentP.textContent = contentPreview;
            contentP.style.marginTop = '5px';
            noteDiv.appendChild(contentP);
        }

        // Display other relevant fields safely
        Object.keys(note).forEach(key => {
            if (key !== 'page_id' && key !== 'content' && key !== 'id' && note[key] !== null && note[key] !== undefined) {
                const fieldP = document.createElement('p');
                fieldP.style.fontSize = '0.8em';
                fieldP.style.color = '#555';
                fieldP.textContent = `${key}: ${note[key]}`;
                noteDiv.appendChild(fieldP);
            }
        });
        
        containerElement.appendChild(noteDiv);
    });
}

/**
 * Fetches notes based on the provided SQL query and renders them in the sidebar.
 * @async
 * @param {string} query - The SQL query to execute.
 * @param {HTMLElement} containerElement - The DOM element to display notes/errors.
 * @param {HTMLButtonElement} runButton - The button that triggered the query.
 */
async function fetchAndRenderCustomNotes(query, containerElement, runButton = null) { // Made runButton optional
    containerElement.innerHTML = '<p style="padding:10px;">Loading...</p>'; // Show loading indicator
    let originalButtonText = '';
    if (runButton) {
        originalButtonText = runButton.textContent;
        runButton.disabled = true;
        runButton.textContent = 'Running...';
    }

    try {
        const response = await fetch('api/query_notes.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query: query })
        });

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }

        const data = await response.json();
        console.log('API Response:', data); // Debug log
        
        // Handle both array and object responses
        const notes = Array.isArray(data) ? data : (data.notes || []);
        
        if (data.error) {
            throw new Error(data.message || 'Unknown error occurred');
        }

        if (!notes || notes.length === 0) {
            console.log('No notes found in response'); // Debug log
            containerElement.innerHTML = '<p style="padding:10px;">No notes found for this query.</p>';
            return;
        }

        console.log('Number of notes received:', notes.length); // Debug log

        // Create a flat list of notes without children
        const flatNotes = notes.map(note => ({
            ...note,
            children: [] // Ensure no children are included
        }));

        // Render the notes using renderOutline with showControls: false
        // Pass an empty object for prefetchedBlocks as these notes are from a custom query
        // and should not rely on the main page's prefetchedBlocks context.
        const renderedHtml = await renderOutline(flatNotes, 0, {}, false);
        console.log('Rendered HTML length:', renderedHtml.length); // Debug log
        
        // Set the rendered content directly in the container
        // Padding is now handled by the CSS rule for #right-sidebar-notes-content
        containerElement.innerHTML = renderedHtml;

    } catch (error) {
        console.error('Error fetching or rendering custom notes:', error);
        containerElement.innerHTML = `<p style="padding:10px; color:red;">Error: ${error.message}</p>`;
    } finally {
        if (runButton) {
            runButton.disabled = false;
            runButton.textContent = originalButtonText;
        }
    }
}

/**
 * Shows the query editor modal for the right sidebar.
 */
async function showQueryEditorModal() { // Make async
    // Check if a modal already exists and remove it to prevent multiple modals
    const existingModal = document.querySelector('.modal.query-editor-dynamic-modal');
    if (existingModal) {
        existingModal.remove();
    }

    const modal = document.createElement('div');
    modal.className = 'modal query-editor-dynamic-modal';
    modal.innerHTML = `
        <div class="query-editor-modal-content">
            <h3>Edit Query</h3>
            <div class="help-text">
                Enter a SQL query to search based on block properties. Example:<br>
                <code>SELECT * FROM notes WHERE id IN (SELECT note_id FROM properties WHERE property_key = 'status' AND property_value = 'done')</code>
            </div>
            <textarea id="query-editor-input" placeholder="Enter your SQL query here...">${localStorage.getItem('customSQLQuery') || ''}</textarea>
            <div class="button-group">
                <button id="cancel-query-modal-btn" class="btn-secondary">Cancel</button>
                <button id="save-query-modal-btn" class="btn-primary">Save</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    const queryInput = modal.querySelector('#query-editor-input');
    const saveButton = modal.querySelector('#save-query-modal-btn');
    const cancelButton = modal.querySelector('#cancel-query-modal-btn');

    // Populate textarea
    let fetchedQuery = UIState.get('customSQLQuery');
    if (typeof fetchedQuery !== 'string' || fetchedQuery === null || typeof fetchedQuery === 'boolean') { // Check for boolean if UIState.get might return true on error
        fetchedQuery = ''; 
    }
    queryInput.value = fetchedQuery;
    queryInput.focus();

    // Save button functionality
    saveButton.addEventListener('click', async () => { 
        const queryValue = queryInput.value.trim();
        const success = await UIState.set('customSQLQuery', queryValue); 
        if (!success) {
            console.error("Failed to save custom SQL query using UIState.set.");
            alert("Failed to save your custom query. Please try again.");
            // Modal remains open for user to try again or cancel
            return; 
        }
        modal.remove();

        // Also trigger the execution of the new query
        const notesDisplayContainer = document.getElementById('right-sidebar-notes-content');
        const runQueryButton = document.getElementById('run-sql-query');
        
        if (notesDisplayContainer && runQueryButton) {
            // Update the main query input in the sidebar as well, if it exists
            const mainQueryInput = document.getElementById('sql-query-input');
            if (mainQueryInput) {
                mainQueryInput.value = queryValue;
            }
            fetchAndRenderCustomNotes(queryValue, notesDisplayContainer, runQueryButton);
        } else {
            console.warn('Could not find notes display container or run query button to refresh results after save.');
        }
    });

    // Cancel button functionality
    cancelButton.addEventListener('click', () => {
        modal.remove();
    });
    
    // Close modal on click outside (on the modal backdrop)
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

// --- Query Execution Frequency Selector ---

/**
 * Creates and appends the query execution frequency selector to the DOM.
 */
async function createExecutionFrequencySelector() { // Make async
    const container = document.querySelector('.query-frequency-container');
    if (!container) {
        console.warn('Query frequency container not found. Skipping selector creation.');
        return;
    }
    container.innerHTML = ''; // Clear any existing content

    const selectLabel = document.createElement('label');
    selectLabel.setAttribute('for', 'query-frequency-select');
    selectLabel.textContent = 'Refresh:';
    selectLabel.style.marginRight = '5px'; // Add some spacing

    const select = document.createElement('select');
    select.id = 'query-frequency-select';

    const options = [
        { value: 'manual', text: 'Manual' },
        { value: '5', text: 'Every 5 minutes' },
        { value: '15', text: 'Every 15 minutes' },
        { value: '60', text: 'Every 60 minutes' }
    ];

    options.forEach(opt => {
        const optionElement = document.createElement('option');
        optionElement.value = opt.value;
        optionElement.textContent = opt.text;
        select.appendChild(optionElement);
    });

    let savedFrequency = UIState.get('queryExecutionFrequency'); 
    if (typeof savedFrequency !== 'string' || savedFrequency === null || savedFrequency === '' || typeof savedFrequency === 'boolean') {
        savedFrequency = 'manual'; 
    }
    select.value = savedFrequency;

    // Idempotency: Check if listener already attached
    if (select.dataset.listenerAttached !== 'true') {
        select.addEventListener('change', async (event) => { 
            const success = await UIState.set('queryExecutionFrequency', event.target.value); 
            if (!success) {
                console.error("Failed to save query execution frequency using UIState.set.");
                alert("Failed to save query execution frequency. Please try again.");
                select.value = UIState.get('queryExecutionFrequency') || 'manual'; // Revert
                return;
            }
            await setupAutoQueryExecution(); 
        });
        select.dataset.listenerAttached = 'true';
    }
    
    container.appendChild(selectLabel);
    container.appendChild(select);
}

/**
 * Sets up or clears the interval for automatic query execution based on saved frequency.
 */
async function setupAutoQueryExecution() { // Make async
    if (window.autoQueryInterval) {
        clearInterval(window.autoQueryInterval);
        window.autoQueryInterval = null;
    }

    let frequency = UIState.get('queryExecutionFrequency'); 
    if (typeof frequency !== 'string' || frequency === null || frequency === '' || typeof frequency === 'boolean') {
        frequency = 'manual'; 
    }

    if (frequency === 'manual') {
        console.log("Query execution set to manual (from UIState). No interval started.");
        return;
    }

    const intervalMinutes = parseInt(frequency);
    if (isNaN(intervalMinutes) || intervalMinutes <= 0) {
        console.error('Invalid query execution frequency from UIState:', frequency);
        return;
    }

    const intervalMs = intervalMinutes * 60 * 1000;
    let query = UIState.get('customSQLQuery'); 
    if (typeof query !== 'string' || query === null || typeof query === 'boolean') {
        query = ''; 
    }


    if (!query || !query.trim()) {
        console.log('No custom query saved (from UIState). Auto execution not started.');
        return;
    }

    const notesDisplayContainer = document.getElementById('right-sidebar-notes-content');
    if (!notesDisplayContainer) {
        console.error('Notes display container for auto query execution not found.');
        return;
    }

    // Initial execution if sidebar is open and query exists for non-manual frequencies
    const rightSidebarEl = document.querySelector('.right-sidebar');
    if (rightSidebarEl && !rightSidebarEl.classList.contains('collapsed')) {
         // Run immediately, but without a button, so pass null.
        fetchAndRenderCustomNotes(query, notesDisplayContainer, null);
    }


    window.autoQueryInterval = setInterval(async () => { 
        let currentQuery = UIState.get('customSQLQuery'); 
        if (typeof currentQuery !== 'string' || currentQuery === null || typeof currentQuery === 'boolean') {
            currentQuery = ''; 
        }
        const currentRightSidebarEl = document.querySelector('.right-sidebar');
        if (currentRightSidebarEl && !currentRightSidebarEl.classList.contains('collapsed') && currentQuery && currentQuery.trim()) {
            console.log(`Auto-executing query every ${intervalMinutes} minutes (query from UIState).`);
            fetchAndRenderCustomNotes(currentQuery, notesDisplayContainer, null); 
        } else {
            console.log(`Skipping auto-execution: Sidebar collapsed or no query (from UIState).`);
        }
    }, intervalMs);

    console.log(`Query auto-execution set up for every ${intervalMinutes} minutes (query from UIState).`);
}


/**
 * Initializes the functionality for the right sidebar's custom notes query section.
 */
async function initializeRightSidebarNotes() { // Make async
    const runQueryButton = document.getElementById('run-sql-query');
    const notesDisplayContainer = document.getElementById('right-sidebar-notes-content');
    const editQueryPenButton = document.getElementById('edit-query-btn'); 

    if (!runQueryButton || !notesDisplayContainer || !editQueryPenButton) {
        console.warn('Essential right sidebar query elements not found. Skipping initialization.');
        return;
    }
    
    // Idempotency checks for event listeners
    if (editQueryPenButton.dataset.initialized !== 'true') {
        editQueryPenButton.addEventListener('click', async () => { 
            await showQueryEditorModal(); 
        });
        editQueryPenButton.dataset.initialized = 'true';
    }

    if (runQueryButton.dataset.initialized !== 'true') {
        runQueryButton.addEventListener('click', async () => { 
            let currentQuery = UIState.get('customSQLQuery'); 
            if (typeof currentQuery !== 'string' || currentQuery === null || typeof currentQuery === 'boolean') {
                currentQuery = ''; 
            }
            if (!currentQuery) {
                notesDisplayContainer.innerHTML = '<p style="padding:10px;">No query saved. Click the "Edit" (pen) button to set a query.</p>';
                return;
            }
            fetchAndRenderCustomNotes(currentQuery, notesDisplayContainer, runQueryButton);
        });
        runQueryButton.dataset.initialized = 'true';
    }

    let savedQuery = UIState.get('customSQLQuery'); 
    if (typeof savedQuery !== 'string' || savedQuery === null || typeof savedQuery === 'boolean') {
        savedQuery = ''; 
    }

    if (savedQuery && savedQuery.trim()) {
        // Initial run if sidebar is open, otherwise it will run when opened or via auto-refresh if configured
        const rightSidebarEl = document.querySelector('.right-sidebar');
        if (rightSidebarEl && !rightSidebarEl.classList.contains('collapsed')) {
            fetchAndRenderCustomNotes(savedQuery, notesDisplayContainer, runQueryButton);
        } else {
             notesDisplayContainer.innerHTML = '<p style="padding:10px;">Query loaded. Expand sidebar to view, or it will refresh automatically if configured.</p>';
        }
    } else {
        notesDisplayContainer.innerHTML = '<p style="padding:10px;">No query set (from UIState). Click the "Edit" (pen) button to create a custom query.</p>';
    }

    await createExecutionFrequencySelector(); 
    await setupAutoQueryExecution(); 
}

// Call to initializeRightSidebarNotes will be in app.js
// document.addEventListener('DOMContentLoaded', initializeRightSidebarNotes);

// Add this function after the DOMContentLoaded event listener
function initializeNoteActions() {
    if (outlineContainer) {
        outlineContainer.addEventListener('click', async (event) => {
            const actionButton = event.target.closest('[data-action]');
            if (!actionButton) return;

            const action = actionButton.dataset.action;
            const noteElement = actionButton.closest('.outline-item');
            if (!noteElement) return;

            const noteId = noteElement.dataset.noteId;
            if (!noteId) return;

            switch (action) {
                case 'add-child':
                    // Add a new child note
                    const newNote = await createNote('', noteId);
                    if (newNote) {
                        const newNoteElement = document.createElement('div');
                        newNoteElement.className = 'outline-item';
                        newNoteElement.dataset.noteId = newNote.id;
                        newNoteElement.innerHTML = await renderOutline([newNote], parseInt(noteElement.dataset.level) + 1);
                        const childrenContainer = noteElement.querySelector('.outline-children') || 
                            (() => {
                                const container = document.createElement('div');
                                container.className = 'outline-children';
                                noteElement.appendChild(container);
                                return container;
                            })();
                        childrenContainer.appendChild(newNoteElement);
                        setActiveBlock(newNoteElement);
                    }
                    break;

                case 'edit':
                    setActiveBlock(noteElement);
                    break;

                case 'delete':
                    if (confirm('Are you sure you want to delete this note?')) {
                        await deleteNote(noteId);
                        noteElement.remove();
                    }
                    break;

                case 'indent-note':
                    const prevNote = noteElement.previousElementSibling;
                    if (prevNote && prevNote.classList.contains('outline-item')) {
                        const prevNoteId = prevNote.dataset.noteId;
                        await updateNoteParent(noteId, prevNoteId);
                        const childrenContainer = prevNote.querySelector('.outline-children') || 
                            (() => {
                                const container = document.createElement('div');
                                container.className = 'outline-children';
                                prevNote.appendChild(container);
                                return container;
                            })();
                        childrenContainer.appendChild(noteElement);
                    }
                    break;

                case 'upload':
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.multiple = true;
                    fileInput.onchange = async (e) => {
                        const files = Array.from(e.target.files);
                        if (files.length > 0) {
                            await handleDroppedFiles(noteId, files);
                        }
                    };
                    fileInput.click();
                    break;

                case 'copy-block-id':
                    const blockId = noteElement.querySelector('.outline-content').dataset.blockId;
                    if (blockId) {
                        await navigator.clipboard.writeText(blockId);
                        const originalText = actionButton.textContent;
                        actionButton.textContent = '✓';
                        setTimeout(() => {
                            actionButton.textContent = originalText;
                        }, 1000);
                    }
                    break;

                case 'toggle-favorite':
                    await toggleFavorite(noteId, actionButton);
                    break;
            }
        });
    }
}
