// Functions and variables related to UI interactions
// (e.g., event handlers, DOM manipulation, modal logic)

// --- DOM Element Selections ---
// These constants store references to frequently used DOM elements.

/** @type {HTMLInputElement} Search input field. */
const searchInput = document.getElementById('search');
/** @type {HTMLElement} List element for recent pages. */
const recentPagesList = document.getElementById('recent-pages-list');
/** @type {HTMLButtonElement} Button to create a new page. */
const newPageButton = document.getElementById('new-page');
/** @type {HTMLElement} Element to display the current page's title. */
const pageTitle = document.getElementById('page-title');
/** @type {HTMLElement} Element to display page properties. */
const pageProperties = document.getElementById('page-properties');
/** @type {HTMLElement} Container for the main note outline. */
const outlineContainer = document.getElementById('outline-container');

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
        // This case should ideally be prevented by only adding the listener for existing notes,
        // but as a safeguard:
        // console.warn("handlePastedImage called without a noteId. Pasting images is only supported for existing notes being edited.");
        // alert("Pasting images is only supported for existing notes that are being edited.");
        // Allowing paste to proceed normally if no noteId (e.g. for new notes not yet saved)
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

    event.preventDefault(); // Prevent default paste action (e.g., inserting raw file path or base64)

    // Optional: give the file a more descriptive name (server will still make it unique)
    // The server (api/image.php) generates a unique name, so client-side name is mostly for local reference.
    const extension = imageFile.type.split('/')[1] || 'png'; // Get extension from MIME type
    const tempFilename = `pasted_image_${Date.now()}.${extension}`;

    try {
        // uploadFileAPI is expected to be in js/api.js
        // It should return a promise with { success: true, filename: "YYYY-MM-DD/unique_name.ext", ... }
        // The third argument (tempFilename) is passed to uploadFileAPI, which in turn passes it to api/image.php
        // if api/image.php is modified to use it as 'original_name' or part of its naming logic.
        // Current uploadFileAPI calls api/image.php which doesn't use the client's suggested filename for storage path,
        // but it might use it for the 'original_name' DB field.
        const responseData = await uploadFileAPI(noteId, imageFile, tempFilename); 

        if (!responseData || !responseData.success || !responseData.filename) {
            console.error("Upload response missing success flag or filename.", responseData);
            throw new Error("Upload response did not include a filename or indicate success.");
        }
        
        // responseData.filename is expected to be "YYYY-MM-DD/unique_server_generated_name.ext"
        const imageMarkdown = `![Pasted Image](uploads/${responseData.filename})`;
        
        const textarea = event.target;
        // const start = textarea.selectionStart;
        // const end = textarea.selectionEnd;
        
        // Use document.execCommand for inserting text to support undo/redo and better cursor handling.
        if (!document.execCommand("insertText", false, imageMarkdown)) {
            // Fallback if execCommand fails or is not supported for some reason (though unlikely for 'insertText')
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            textarea.value = textarea.value.substring(0, start) + imageMarkdown + textarea.value.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + imageMarkdown.length;
        }

        // Trigger input event for any frameworks/listeners that depend on it
        textarea.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));

        console.log(`Image pasted and uploaded as ${responseData.filename} to note ${noteId}`);
        // The image markdown is now in the textarea. The user needs to save the note
        // for this change to persist and for the image to be rendered on next load.

    } catch (error) {
        console.error('Error uploading pasted image:', error);
        alert(`Error uploading pasted image: ${error.message || 'Unknown error'}`);
    }
};

// --- Drag and Drop File Upload Logic ---
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
                window.handleDroppedFiles(noteId, files);
            } else {
                console.error('handleDroppedFiles function is not available.');
                alert('File drop handling is not properly configured.');
            }
        } else {
            alert('Please select a specific note block or drop directly onto a note block to attach files.');
        }
    });
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

    if (typedChar === null || typedChar === undefined || typedChar.length !== 1) {
        return;
    }

    let closeBracketChar = null;
    if (typedChar === '[') {
        closeBracketChar = ']';
    } else if (typedChar === '{') {
        closeBracketChar = '}';
    }

    if (closeBracketChar) {
        const cursorPos = textarea.selectionStart;
        const value = textarea.value;
        const textBeforeCursor = value.substring(0, cursorPos);
        const textAfterCursor = value.substring(cursorPos);
        textarea.value = textBeforeCursor + closeBracketChar + textAfterCursor;
        textarea.selectionStart = textarea.selectionEnd = cursorPos;
    }
}

function handleSnippetReplacement(event) {
    const textarea = event.target;
    setTimeout(() => {
        const cursorPos = textarea.selectionStart;
        const text = textarea.value;
        let textBeforeCursor = text.substring(0, cursorPos);
        // let replacementMade = false; // This variable was unused in original code
        let triggerChar = '';

        if(event.key === ' ' || event.key === 'Enter' || (event.data === ' ' && event.type === 'input')) {
             triggerChar = ' ';
        } else if (event.type === 'input' && event.data !== null) {
            return; // Only trigger on space or enter for typed snippets
        } else {
            return; // Ignore other event types like backspace, arrow keys etc. for snippet replacement logic
        }

        if (textBeforeCursor.endsWith(':t' + triggerChar)) {
            const replacement = '{tag::}';
            const triggerFull = ':t' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length - 1; // Place cursor inside {}
            // replacementMade = true;
        }
        else if (textBeforeCursor.endsWith(':r' + triggerChar)) {
            const now = new Date();
            const timeString = now.toISOString();
            const replacement = `{time::${timeString}} `;
            const triggerFull = ':r' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length;
            // replacementMade = true;
        }
        else if (textBeforeCursor.endsWith(':d' + triggerChar)) {
            const now = new Date();
            const dateString = now.toISOString().split('T')[0];
            const replacement = `{date::${dateString}} `;
            const triggerFull = ':d' + triggerChar;
            textarea.value = textBeforeCursor.slice(0, -triggerFull.length) + replacement + text.substring(cursorPos);
            textarea.selectionStart = textarea.selectionEnd = cursorPos - triggerFull.length + replacement.length;
            // replacementMade = true;
        }
    }, 0);
}

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
    // Ignore keydowns if a modal is active (e.g. search modal, image modal)
    if (document.querySelector('.modal')) {
        return;
    }

    // Handle Esc key within an active editor (e.g., to cancel editing)
    if (isEditorOpen) { // `isEditorOpen` from state.js
        if (event.key === 'Escape') {
            // Attempt to find and click a 'cancel' button in the active editor.
            // This assumes a specific structure for editor modals/forms.
            const cancelButton = document.querySelector('.note-editor .cancel-note');
            if (cancelButton) {
                cancelButton.click();
                event.preventDefault();
            }
        }
        return; // Other keys are likely handled by the textarea itself.
    }

    // If no block is active, ArrowDown/ArrowUp/Space might activate the first block or create a new one.
    if (!activeBlockElement && (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === ' ')) {
        const firstBlock = outlineContainer.querySelector('.outline-item:not(.note-editor-wrapper .outline-item)');
        if (firstBlock) {
            setActiveBlock(firstBlock); // Activate the first block.
             if (event.key === ' ') event.preventDefault(); // Prevent space from typing if activating.
        } else if (event.key === ' ' && currentPage && (!currentPage.notes || currentPage.notes.length === 0)) {
            // If space is pressed on an empty page, initiate new note creation.
            createNote(null, 0); // `createNote` (app.js) handles the process.
            event.preventDefault();
            return;
        } else {
            return; // No active block and no action to take.
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
