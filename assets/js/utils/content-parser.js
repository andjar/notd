/**
 * @file content-parser.js
 * @description Utilities for parsing page content, extracting properties, and preparing content for rendering.
 */

/**
 * Extracts key-value properties from a raw content string.
 * Properties are expected in the format {key:::value}.
 * @param {string} content The raw page or note content.
 * @returns {object} An object containing the parsed properties.
 */
export function parseProperties(content) {
    const properties = {};
    if (!content) return properties;

    // Regex to find all occurrences of {key:::value}
    const regex = /\{([^:]+):::(.*?)\}/g;
    let match;

    while ((match = regex.exec(content)) !== null) {
        const key = match[1].trim();
        const value = match[2].trim();
        properties[key] = value;
    }

    return properties;
}

/**
 * Removes property definitions from the content string.
 * @param {string} content The raw content containing properties.
 * @returns {string} The content with property lines removed.
 */
export function cleanProperties(content) {
    if (!content) return '';
    // This regex removes the entire line where a property is found, including the newline.
    return content.replace(/\{([^:]+):::(.*?)\}\s*\n?/g, '').trim();
}


/**
 * A simple markdown-like parser.
 * This function is basic and can be extended.
 * @param {string} text The text content to parse.
 * @returns {string} HTML-formatted string.
 */
export function parseContent(text) {
    if (!text) return "";

    let html = text;

    // Basic replacements (can be expanded)
    // Bold: **text**
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Italic: *text*
    html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
    // Code: `code`
    html = html.replace(/`(.*?)`/g, '<code>$1</code>');
    // Links: [title](url) - handle both external links and page links
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, title, url) => {
        // If it's a page link (no protocol), make it a page.php link
        if (!url.match(/^https?:\/\//) && !url.match(/^mailto:/) && !url.match(/^#/)) {
            return `<a href="page.php?page=${encodeURIComponent(url)}" class="page-link">${title}</a>`;
        }
        // Otherwise, treat as external link
        return `<a href="${url}" target="_blank" rel="noopener noreferrer">${title}</a>`;
    });
    // Newlines to <br>
    html = html.replace(/\n/g, '<br>');

    return html;
} 