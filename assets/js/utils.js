/**
 * Small shared helpers.
 * @module utils
 */

export function debounce(func, delay) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** Text made safe for insertion into HTML, in content or in a quoted attribute. */
export function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, c => HTML_ESCAPES[c]);
}

/** A date as YYYY-MM-DD in the user's own time zone (toISOString() would give the UTC day). */
export function localDate(date = new Date()) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The journal page `days` after (or before) the one named `name` (YYYY-MM-DD). */
export function shiftDate(name, days) {
    const [y, m, d] = name.split('-').map(Number);
    return localDate(new Date(y, m - 1, d + days));
}

export const isDateName = (name) => /^\d{4}-\d{2}-\d{2}$/.test(name || '');

/** The URL of a page (optionally of one note on it). */
export function pageUrl(name, noteId = null) {
    return `page.php?page=${encodeURIComponent(name)}${noteId ? `#note-${encodeURIComponent(noteId)}` : ''}`;
}

/** Replaces <i data-feather="..."> placeholders under `root` with icons. */
export function renderIcons(root = document) {
    if (typeof feather === 'undefined') return;
    for (const el of root.querySelectorAll('[data-feather]')) {
        const icon = feather.icons[el.dataset.feather];
        if (!icon) continue;
        const attrs = { class: el.getAttribute('class') || '' };
        if (el.id) attrs.id = el.id;
        if (el.title) attrs.title = el.title;
        const holder = document.createElement('span');
        holder.innerHTML = icon.toSvg(attrs);
        const svg = holder.firstElementChild;
        if (el.title) {
            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = el.title;
            svg.prepend(title);
        }
        el.replaceWith(svg);
    }
}

// ------------------------------------------------------------ caret & text

/** Inserts text at the caret of the focused contenteditable; the caret ends `cursorOffset` characters before its end. */
export function insertTextAtCursor(text, cursorOffset = 0) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return false;
    const range = selection.getRangeAt(0);
    range.deleteContents();
    const textNode = document.createTextNode(text);
    range.insertNode(textNode);
    textNode.parentNode?.normalize?.();
    const node = textNode.isConnected ? textNode : null;
    if (node) {
        const caret = document.createRange();
        caret.setStart(node, text.length - cursorOffset);
        caret.collapse(true);
        selection.removeAllRanges();
        selection.addRange(caret);
    }
    return true;
}

/** Typing an opening bracket inserts the pair with the caret between. */
export function handleAutocloseBrackets(e) {
    const pair = { '[': '[]', '{': '{}', '(': '()' }[e.key];
    if (!pair || e.ctrlKey || e.metaKey || e.altKey) return false;
    e.preventDefault();
    const selection = window.getSelection();
    const selected = selection && !selection.isCollapsed ? selection.toString() : '';
    // With a selection, wrap it; otherwise insert the empty pair.
    insertTextAtCursor(pair[0] + selected + pair[1], 1);
    e.target.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
}

// --------------------------------------------------------------- encryption

/** Encrypts text with a password (SJCL: PBKDF2 key derivation, AES-GCM). Returns SJCL's JSON string. */
export function encrypt(password, text) {
    return sjcl.encrypt(password, text, { iter: 1000, ks: 256, ts: 128, mode: 'gcm', v: 1 });
}

/** Decrypts what encrypt() produced. Throws if the password is wrong or the data is damaged. */
export function decrypt(password, encryptedJson) {
    if (!password || !encryptedJson) return null;
    return sjcl.decrypt(password, encryptedJson);
}

/** True if `content` looks like the output of encrypt(). */
export function looksEncrypted(content) {
    return typeof content === 'string' && content.startsWith('{"iv":') && content.includes('"ct":');
}
