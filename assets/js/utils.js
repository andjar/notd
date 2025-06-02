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
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

/**
 * Generates a temporary ID for optimistic UI updates
 * Format: temp-{timestamp}-{random}
 * @returns {string} Temporary ID
 */
function generateTempId() {
    return 'temp-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
}

/**
 * Generates a UUID v4
 * @returns {string} UUID v4
 */
function uuidv4() {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
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
        return `<span class="page-link-bracket">[[</span><a href="#" class="page-link" data-page-name="${trimmedName}">${trimmedName}</a><span class="page-link-bracket">]]</span>`;
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
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        debounce,
        generateTempId,
        uuidv4,
        escapeHTML,
        getTodaysJournalPageName,
        parseMarkdown,
        parseContentForDisplay
    };
}