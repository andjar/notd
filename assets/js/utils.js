/**
 * Small shared helpers: debounce, text insertion, bracket auto-close, encryption.
 * @module utils
 */

/**
 * Creates a debounced version of a function that delays its execution
 * @param {Function} func - The function to debounce
 * @param {number} delay - Delay in milliseconds
 * @returns {Function} Debounced function
 */
export function debounce(func, delay) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

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
    
    if (!password || !encryptedJson) {
        console.warn('[DEBUG] Decrypt called with missing password or encryptedJson');
        return null;
    }

    try {
        // sjcl.decrypt takes the password and the JSON string and handles the rest.
        const decrypted = sjcl.decrypt(password, encryptedJson);
        return decrypted;
    } catch (e) {
        console.error('[DEBUG] Decryption failed:', e);
        throw e;
    }
}