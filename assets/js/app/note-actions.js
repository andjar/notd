/**
 * Keyboard and UI handlers for notes. Handlers are synchronous: each user
 * action is applied to the outline model immediately (see app/outline.js),
 * so a fast Enter-then-Tab, or Tab pressed twice, always acts on current state.
 *
 * @module app/note-actions
 */

import * as outline from './outline.js';
import { handleAutocloseBrackets, insertTextAtCursor } from '../utils.js';
import { ui } from '../ui.js';

const notesContainer = document.querySelector('#notes-container');

// --- Accessors (kept for other modules) ---

export function getNoteDataById(noteId) {
    return noteId ? outline.model.get(noteId) : null;
}

export function getNoteElementById(noteId) {
    if (!notesContainer || !noteId) return null;
    return notesContainer.querySelector(`.note-item[data-note-id="${CSS.escape(String(noteId))}"]`);
}

/** Reads a note's current text from its element. */
function readContent(noteEl) {
    const contentDiv = noteEl?.querySelector(':scope > .note-header-row .note-content') || noteEl?.querySelector('.note-content');
    if (!contentDiv) return null;
    if (contentDiv.classList.contains('edit-mode')) {
        contentDiv.dataset.rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
    }
    return contentDiv.dataset.rawContent ?? '';
}

/**
 * Records a note's text from the DOM into the model and asks the outbox to
 * send soon. Synchronous; returns a resolved promise for older callers.
 */
export function saveNoteImmediately(noteEl) {
    const id = noteEl?.dataset.noteId;
    const text = readContent(noteEl);
    if (id && text !== null) {
        outline.editNote(id, text);
        outline.outbox.flushSoon();
    }
    return Promise.resolve(id ? outline.model.get(id) : null);
}

/** Input handler: every keystroke updates the model; the outbox coalesces and sends. */
export function handleNoteInput(e) {
    if (!e.target.matches('.note-content.edit-mode')) return;
    const noteEl = e.target.closest('.note-item');
    const id = noteEl?.dataset.noteId;
    const text = readContent(noteEl);
    if (id && text !== null) outline.editNote(id, text);
}

export function handleAddRootNote() {
    return outline.createAtEnd({ focus: 'start' });
}

export async function handleNoteAction(action, noteId) {
    switch (action) {
        case 'create':
            handleAddRootNote();
            break;
        case 'focus': {
            const contentDiv = getNoteElementById(noteId)?.querySelector('.note-content');
            if (contentDiv) ui.switchToEditMode(contentDiv);
            break;
        }
        default:
            console.warn(`Unknown note action: ${action}`);
    }
}

// --- Keyboard ---

function visibleContentElements() {
    return Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden) .note-content'))
        .filter(el => !el.closest('.note-children.collapsed'));
}

function focusContent(contentEl, where = 'end') {
    const id = contentEl?.closest('.note-item')?.dataset.noteId;
    if (id) ui.focusNote(id, where);
}

function handleArrowKey(e, contentDiv) {
    const all = visibleContentElements();
    const i = all.indexOf(contentDiv);
    const target = e.key === 'ArrowUp' ? all[i - 1] : all[i + 1];
    if (!target) return;
    e.preventDefault();
    focusContent(target, 'end');
}

function handleShortcutExpansion(e) {
    if (e.key !== ' ') return false;
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return false;
    const range = selection.getRangeAt(0);
    const cursorPos = range.startOffset;
    const textNode = range.startContainer;
    if (!textNode || textNode.nodeType !== Node.TEXT_NODE || cursorPos < 2) return false;

    const preceding = textNode.textContent.substring(cursorPos - 2, cursorPos);
    let replacement = '';
    if (preceding === ':t') replacement = '{tag::}';
    else if (preceding === ':d') replacement = `{date::${new Date().toISOString().slice(0, 10)}}`;
    else if (preceding === ':r') replacement = `{timestamp::${new Date().toISOString()}}`;
    else if (preceding === ':k') replacement = '{keyword::}';
    if (!replacement) return false;

    e.preventDefault();
    range.setStart(textNode, cursorPos - 2);
    range.deleteContents();
    insertTextAtCursor(replacement, 1);
    e.target.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
}

export function handleNoteKeyDown(e) {
    if (!e.target.matches('.note-content')) return;
    // The page-link suggestion box handles its own Enter/Tab/arrows.
    if (e.defaultPrevented || e.isComposing) return;
    const noteItem = e.target.closest('.note-item');
    const contentDiv = e.target;
    const id = noteItem?.dataset.noteId;
    if (!id || !outline.model.has(id)) return;

    if (handleShortcutExpansion(e)) return;
    if (handleAutocloseBrackets(e)) return;

    const mod = e.ctrlKey || e.metaKey;

    // Move among siblings: Alt+Shift+Up/Down (Logseq) or Ctrl/Cmd+Up/Down.
    if ((e.altKey && e.shiftKey) || (mod && !e.shiftKey && !e.altKey)) {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            saveNoteImmediately(noteItem);
            if (e.key === 'ArrowUp') outline.moveUp(id); else outline.moveDown(id);
            return;
        }
    }

    if (mod && e.key === 'Enter') {
        e.preventDefault();
        saveNoteImmediately(noteItem);
        outline.createChild(id, { focus: 'start' });
        return;
    }

    switch (e.key) {
        case 'Enter':
            if (e.shiftKey) return; // newline inside the note
            e.preventDefault();
            saveNoteImmediately(noteItem);
            outline.createSiblingAfter(id, { focus: 'start' });
            return;
        case 'Tab':
            e.preventDefault();
            saveNoteImmediately(noteItem);
            if (e.shiftKey) outline.outdent(id); else outline.indent(id);
            return;
        case 'Backspace': {
            const text = readContent(noteItem) ?? '';
            if (text.trim() !== '') return; // only empty notes are removed
            if (outline.model.hasChildren(id)) return;
            e.preventDefault();
            const all = visibleContentElements();
            const prev = all[all.indexOf(contentDiv) - 1];
            outline.deleteNote(id);
            if (prev) focusContent(prev, 'end');
            return;
        }
        case 'ArrowUp':
        case 'ArrowDown':
            if (e.shiftKey || e.altKey || mod) return;
            handleArrowKey(e, contentDiv);
            return;
    }
}
