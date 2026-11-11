/**
 * The DOM of one note, and switching its text between rendered and editable.
 *
 *   .note-item[data-note-id]
 *     .note-header-row
 *       .note-controls       collapse arrow, bullet (drag handle, menu, zoom)
 *       .note-content-wrapper
 *         .note-content      rendered HTML, or the raw text while editing
 *         .note-attachments  (only when the note has attachments)
 *     .note-children         child notes
 *
 * No listeners are attached here; ui/note-events.js handles all events for
 * the whole outline.
 *
 * @module ui/note-view
 */

import { renderContent } from './markdown.js';
import { hydrate } from './embeds.js';
import { renderAttachments } from './attachments.js';

const ARROW_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';

/** Builds the element for a note and, recursively, its `children`. */
export function renderNote(note, nestingLevel = 0) {
    const item = document.createElement('div');
    item.className = 'note-item';
    item.dataset.noteId = note.id;
    item.style.setProperty('--nesting-level', nestingLevel);
    const hasChildren = !!(note.children && note.children.length);
    if (hasChildren) item.classList.add('has-children');
    if (note.collapsed) item.classList.add('collapsed');

    const controls = document.createElement('div');
    controls.className = 'note-controls';
    // The arrow is always there; CSS shows it only on .has-children, so a note
    // that gains children later can be collapsed right away.
    const arrow = document.createElement('span');
    arrow.className = 'note-collapse-arrow';
    arrow.dataset.collapsed = note.collapsed ? 'true' : 'false';
    arrow.innerHTML = ARROW_SVG;
    const bullet = document.createElement('span');
    bullet.className = 'note-bullet';
    bullet.dataset.noteId = note.id;
    controls.append(arrow, bullet);

    const wrapper = document.createElement('div');
    wrapper.className = 'note-content-wrapper';
    const content = document.createElement('div');
    content.className = 'note-content rendered-mode';
    content.dataset.placeholder = 'Type to add content...';
    content.dataset.noteId = note.id;
    content.dataset.rawContent = note.content || '';
    content.innerHTML = renderContent(note.content);
    wrapper.appendChild(content);
    if (note.has_attachments) {
        // Filled in once the element is in the document.
        queueMicrotask(() => renderAttachments(note.id));
    }

    const header = document.createElement('div');
    header.className = 'note-header-row';
    header.append(controls, wrapper);

    const children = document.createElement('div');
    children.className = 'note-children' + (note.collapsed ? ' collapsed' : '');
    if (hasChildren) {
        for (const child of note.children) children.appendChild(renderNote(child, nestingLevel + 1));
    }

    item.append(header, children);
    return item;
}

// ------------------------------------------------------------------ editing

// "plaintext-only" keeps pasted formatting and stray <div>s out of the text.
const EDITABLE = (() => {
    const probe = document.createElement('div');
    try {
        probe.contentEditable = 'plaintext-only';
        return probe.contentEditable === 'plaintext-only' ? 'plaintext-only' : 'true';
    } catch {
        return 'true';
    }
})();

/** The text of an element being edited, with line breaks as \n. */
export function readText(element) {
    let text = '';
    for (const node of element.childNodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            text += node.textContent;
        } else if (node.nodeName === 'BR') {
            text += '\n';
        } else if (node.nodeName === 'DIV' || node.nodeName === 'P') {
            if (text.length > 0 && !text.endsWith('\n')) text += '\n';
            text += readText(node);
            if (!text.endsWith('\n')) text += '\n';
        } else {
            text += readText(node);
        }
    }
    return text;
}

/** Tidies text when editing ends: no surrounding whitespace, at most one blank line in a row. */
export function normalizeText(text) {
    return String(text ?? '').replace(/\r\n?/g, '\n').replace(/\n[ \t]*\n[ \t]*\n+/g, '\n\n').trim();
}

/** The caret position as an offset into the element's text, or null if the caret is elsewhere. */
export function caretOffset(element) {
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount || !element.contains(selection.anchorNode)) return null;
    const range = document.createRange();
    range.selectNodeContents(element);
    range.setEnd(selection.focusNode, selection.focusOffset);
    return range.toString().length;
}

/** Puts the caret at `offset` characters into the element's text. */
export function setCaret(element, offset) {
    const selection = window.getSelection();
    const range = document.createRange();
    let remaining = offset;
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    let placed = false;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (remaining <= node.textContent.length) {
            range.setStart(node, remaining);
            placed = true;
            break;
        }
        remaining -= node.textContent.length;
    }
    if (!placed) {
        range.selectNodeContents(element);
        range.collapse(offset === 0);
    } else {
        range.collapse(true);
    }
    selection.removeAllRanges();
    selection.addRange(range);
}

/** Replaces the text being edited and places the caret. */
export function setEditingText(element, text, caret = text.length) {
    element.textContent = text;
    element.dataset.rawContent = text;
    setCaret(element, caret);
}

/** Shows the raw text of a note for editing. Does not move the focus. */
export function enterEditMode(content) {
    if (content.classList.contains('edit-mode')) return;
    content.classList.remove('rendered-mode');
    content.classList.add('edit-mode');
    content.contentEditable = EDITABLE;
    content.spellcheck = true;
    content.textContent = content.dataset.rawContent || '';
}

/**
 * Ends editing: renders the text again.
 * @returns {string} the note's text
 */
export function leaveEditMode(content) {
    if (!content.classList.contains('edit-mode')) return content.dataset.rawContent || '';
    const text = normalizeText(readText(content));
    content.dataset.rawContent = text;
    content.classList.remove('edit-mode');
    content.classList.add('rendered-mode');
    content.removeAttribute('contenteditable');
    content.innerHTML = renderContent(text);
    hydrate(content);
    return text;
}

/** Re-renders a note's text (no effect while it is being edited). */
export function showContent(content, text) {
    if (content.classList.contains('edit-mode')) return;
    content.dataset.rawContent = text || '';
    content.innerHTML = renderContent(text);
    hydrate(content);
}

function positionAt(element, offset) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    let remaining = offset;
    let last = null;
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (remaining <= node.textContent.length) return [node, remaining];
        remaining -= node.textContent.length;
        last = node;
    }
    return last ? [last, last.textContent.length] : [element, 0];
}

/**
 * Replaces characters [start, end) of the text being edited with `text` and
 * puts the caret `caretBack` characters before the end of the insertion.
 * Goes through the browser's editing command, so Ctrl+Z undoes it.
 */
export function replaceRange(element, start, end, text, caretBack = 0) {
    const range = document.createRange();
    range.setStart(...positionAt(element, start));
    range.setEnd(...positionAt(element, end));
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    let done = false;
    try {
        done = document.execCommand('insertText', false, text);
    } catch { /* fall through */ }
    if (!done) {
        const current = element.textContent || '';
        element.textContent = current.slice(0, start) + text + current.slice(end);
    }
    setCaret(element, start + text.length - caretBack);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}
