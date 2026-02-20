/**
 * Utility functions for the NotTD application
 * @module utils
 */

/**
 * Creates a debounced version of a function that delays its execution
 * @param {Function} func - The function to debounce
 * @param {number} delay - Delay in milliseconds
 * @returns {Function} Debounced function
 */
export function debounce(func, delay) { // Added export keyword
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

/**
 * Escapes HTML special characters in a string
 * @param {string} str - String to escape
 * @returns {string} Escaped string
 */
function escapeHTML(str) {
    if (typeof str !== 'string') return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Gets today's date in YYYY-MM-DD format for journal pages
 * @returns {string} Date string in YYYY-MM-DD format
 */
function getTodaysJournalPageName() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Parses Markdown content for display, handling special NotTD features
 * @param {string} text - Raw Markdown text
 * @param {Object} options - Parsing options
 * @param {boolean} [options.allowBlockElements=false] - Whether to allow block-level elements
 * @param {boolean} [options.escapeHTML=true] - Whether to escape HTML
 * @returns {string} Parsed HTML
 */
function parseMarkdown(text, options = {}) {
    if (text === null || text === undefined) return '';
    
    const {
        allowBlockElements = false,
        escapeHTML = true
    } = options;

    // Escape HTML if needed
    let html = escapeHTML ? escapeHTML(text) : text;

    // Handle block references (transclusion)
    html = html.replace(/!{{(.*?)}}/g, (match, blockId) => {
        const trimmedId = blockId.trim();
        return `<span class="block-ref" data-block-id="${trimmedId}">!{{${trimmedId}}}</span>`;
    });

    // Handle page links
    html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => {
        const trimmedName = pageName.trim();
        return `<span class="page-link-bracket">[[</span><a href="page.php?page=${encodeURIComponent(trimmedName)}" class="page-link">${trimmedName}</a><span class="page-link-bracket">]]</span>`;
    });

    // Use marked.js for other Markdown formatting
    if (typeof marked === 'function') {
        try {
            const markedOptions = {
                breaks: true,
                gfm: true,
                headerIds: false,
                mangle: false,
                sanitize: false, // We handle sanitization ourselves
                smartLists: true,
                smartypants: true,
                xhtml: false
            };

            if (!allowBlockElements) {
                // Use inline parsing for single-line content
                html = marked.parseInline(html, markedOptions);
            } else {
                // Use full parsing for multi-line content
                html = marked.parse(html, markedOptions);
            }
        } catch (e) {
            console.warn('Marked.js parsing error:', e);
            // Fallback to basic line breaks
            html = html.replace(/\n/g, '<br>');
        }
    } else {
        // Fallback if marked.js is not available
        html = html.replace(/\n/g, '<br>');
    }

    return html;
}

/**
 * Parses content for display, including task markers
 * This is a higher-level function that uses parseMarkdown internally
 * @param {string} content - Raw content to parse
 * @returns {string} Parsed HTML with task markers
 */
function parseContentForDisplay(content) {
    if (content === null || content === undefined) return '';

    // Handle task markers first
    let html = content;
    if (html.startsWith('TODO ')) {
        html = `<span class="block-marker TODO">TODO</span>` + html.substring(5);
    } else if (html.startsWith('DONE ')) {
        html = `<span class="block-marker DONE">DONE</span><span class="done-text">` + html.substring(5) + `</span>`;
    }

    // Parse the rest using parseMarkdown
    return parseMarkdown(html, { allowBlockElements: false });
}

// Export functions for use in other modules
// module.exports might still be useful for CommonJS environments or specific bundler configs
// but ensure ES6 exports are primary for consistency in this project.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        escapeHTML,
        getTodaysJournalPageName,
        parseMarkdown,
        parseContentForDisplay
    };
}

// Also consider exporting other utilities if they'll be used by other ES6 modules.
// For now, only explicitly exporting debounce as per immediate requirement.
// export { generateTempId, uuidv4, escapeHTML, getTodaysJournalPageName, parseMarkdown, parseContentForDisplay };

/**
 * Safely adds an event listener to an element if it exists
 * @param {HTMLElement|null} element - The element to add the listener to
 * @param {string} event - The event type
 * @param {Function} handler - The event handler
 * @param {string} elementName - Name of the element for logging
 */
export function safeAddEventListener(element, event, handler, elementName) {
    if (!element) {
        console.warn(`Cannot add ${event} listener: ${elementName} element not found`);
        return;
    }
    element.addEventListener(event, handler);
}

/**
 * Inserts text at the current cursor position in a contentEditable element.
 * @param {string} text - The text to insert.
 * @param {number} [cursorOffset=0] - The offset from the end of the inserted text where the cursor should be placed.
 * @returns {boolean} True if text was inserted, false otherwise.
 */
export function insertTextAtCursor(text, cursorOffset = 0) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return false;

    const range = selection.getRangeAt(0);
    range.deleteContents(); // Delete any selected content

    const textNode = document.createTextNode(text);
    range.insertNode(textNode);

    // Set cursor position after insertion
    const newRange = document.createRange();
    newRange.setStart(textNode, text.length - cursorOffset);
    newRange.setEnd(textNode, text.length - cursorOffset);
    selection.removeAllRanges();
    selection.addRange(newRange);
    
    return true;
}

/**
 * Handles auto-closing of brackets/parentheses/braces.
 * @param {Event} e - The keyboard event.
 * @returns {boolean} True if a bracket was auto-closed, false otherwise.
 */
export function handleAutocloseBrackets(e) {
    let handled = false;
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) return false;
    const range = selection.getRangeAt(0);
    const editor = e.target; // contentEditable div

    const keyActionMap = { '[': '[]', '{': '{}', '(': '()' };

    if (keyActionMap[e.key]) {
        const textToInsert = keyActionMap[e.key];
        let cursorOffset = 1;

        e.preventDefault();
        insertTextAtCursor(textToInsert, cursorOffset);
        handled = true;
    }

    if (handled) {
        // Dispatch an input event so note-renderer's listeners (like for page link suggestions) are triggered
        editor.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
    }
    return handled;
}

/**
 * This file contains utility functions for cryptographic operations,
 * primarily for encrypting and decrypting note content.
 */

/**
 * Encrypts a text string with a password using SJCL.
 * This function uses PBKDF2 for key derivation.
 * @param {string} password The password.
 * @param {string} text The plaintext to encrypt.
 * @returns {string} The JSON-stringified encrypted data.
 */
export function encrypt(password, text) {
    // sjcl.encrypt handles key derivation (PBKDF2), salt, and IV generation.
    // The result is a JSON string containing all necessary components.
    const prp = {
        iter: 1000, // Iteration count for PBKDF2
        ks: 256, // Key size
        ts: 128, // Tag size for GCM
        mode: 'gcm', // Recommended mode
        v: 1 // Version
    };
    return sjcl.encrypt(password, text, prp);
}

/**
 * Decrypts a JSON string encrypted by the encrypt function.
 * @param {string} password The password.
 * @param {string} encryptedJson The JSON string from the encrypt function.
 * @returns {string} The decrypted plaintext.
 * @throws An error if decryption fails (e.g., wrong password).
 */
export function decrypt(password, encryptedJson) {
    console.log('[DEBUG] Decrypt function called with:', {
        passwordLength: password?.length,
        encryptedJsonLength: encryptedJson?.length,
        encryptedJsonStartsWith: encryptedJson?.substring(0, 20) + '...'
    });
    
    if (!password || !encryptedJson) {
        console.warn('[DEBUG] Decrypt called with missing password or encryptedJson');
        return null;
    }

    try {
        // sjcl.decrypt takes the password and the JSON string and handles the rest.
        const decrypted = sjcl.decrypt(password, encryptedJson);
        console.log('[DEBUG] Decryption successful, result:', {
            resultLength: decrypted?.length,
            resultStartsWith: decrypted?.substring(0, 20) + '...'
        });
        return decrypted;
    } catch (e) {
        console.error('[DEBUG] Decryption failed:', e);
        throw e;
    }
}