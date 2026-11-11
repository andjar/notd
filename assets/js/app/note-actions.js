/**
 * Keyboard handling for the note being edited. Handlers are synchronous:
 * each action is applied to the outline model immediately (app/outline.js),
 * so a fast Enter-then-Tab, or Tab pressed twice, always acts on current
 * state.
 *
 *   Enter               split the note at the caret
 *   Shift+Enter         line break inside the note
 *   Ctrl/Cmd+Enter      new child note
 *   Tab / Shift+Tab     indent / outdent
 *   Backspace at start  join with the note above
 *   Up / Down           previous / next note (from the first / last line)
 *   Alt+Shift+Up/Down   move the note among its siblings (also Ctrl/Cmd+Up/Down)
 *   Alt+Right / Left    zoom into the note / back out
 *   Ctrl/Cmd+Z, +Shift+Z or +Y   undo / redo
 *   Escape              stop editing
 *
 * @module app/note-actions
 */

import * as outline from './outline.js';
import * as view from '../ui/note-elements.js';
import * as autocomplete from '../ui/autocomplete.js';
import { readText, caretOffset, replaceRange } from '../ui/note-view.js';
import { zoomInto, zoomOut, zoomedNoteId } from '../ui/zoom.js';
import { handleAutocloseBrackets, localDate } from '../utils.js';

// Ctrl+Z undoes typing (the browser's own undo) when typing was the last
// thing that happened in the focused note, and outline actions otherwise.
let typedIn = null;

/** Records the text of the note being edited in the model. */
function sync(content) {
    const id = content.dataset.noteId;
    const text = readText(content);
    content.dataset.rawContent = text;
    if (outline.model.has(id)) outline.editNote(id, text);
    return text;
}

/** Input in a note: every keystroke updates the model; the outbox coalesces and sends. */
export function noteInput(content) {
    sync(content);
    typedIn = content.dataset.noteId;
}

/** A note received the focus. */
export function noteFocused(content) {
    if (typedIn !== content.dataset.noteId) typedIn = null;
}

export function addRootNote() {
    const zoomed = zoomedNoteId();
    return zoomed ? outline.createChild(zoomed, { focus: 'start' }) : outline.createAtEnd({ focus: 'start' });
}

// ------------------------------------------------- neighbours on screen

function previousVisible(id) {
    if (id === zoomedNoteId()) return null;
    const model = outline.model;
    let prev = model.prevSibling(id);
    if (!prev) {
        const parentId = model.get(id)?.parent_note_id ?? null;
        return parentId === null ? null : model.get(parentId);
    }
    while (!prev.collapsed && model.hasChildren(prev.id)) prev = model.children(prev.id).at(-1);
    return prev;
}

function nextVisible(id) {
    const model = outline.model;
    const note = model.get(id);
    if (!note) return null;
    if (!note.collapsed && model.hasChildren(id)) return model.children(id)[0];
    for (let n = note; n; n = n.parent_note_id === null ? null : model.get(n.parent_note_id)) {
        if (n.id === zoomedNoteId()) return null;
        const next = model.nextSibling(n.id);
        if (next) return next;
    }
    return null;
}

/** Is the caret on the first (up) or last (down) line of the note as displayed? */
function onEdgeLine(content, up) {
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount || !selection.isCollapsed) return false;
    const caret = selection.getRangeAt(0).getClientRects()[0];
    if (!caret) {
        const offset = caretOffset(content) ?? 0;
        const text = readText(content);
        return up ? !text.slice(0, offset).includes('\n') : !text.slice(offset).replace(/\n$/, '').includes('\n');
    }
    const box = content.getBoundingClientRect();
    const style = getComputedStyle(content);
    const line = parseFloat(style.lineHeight) || caret.height || 20;
    return up
        ? caret.top - box.top - (parseFloat(style.paddingTop) || 0) < line * 0.7
        : box.bottom - caret.bottom - (parseFloat(style.paddingBottom) || 0) < line * 0.7;
}

// ------------------------------------------------------------ shortcuts

/** ":d " and friends expand into property syntax. */
function expandShortcut(event, content) {
    if (event.key !== ' ') return false;
    const caret = caretOffset(content);
    if (caret === null || caret < 2) return false;
    const text = readText(content);
    const code = text.slice(caret - 2, caret);
    if (caret > 2 && !/\s/.test(text[caret - 3])) return false;
    let insert;
    let back = 0;
    if (code === ':t') { insert = '{tag::}'; back = 1; }
    else if (code === ':k') { insert = '{keyword::}'; back = 1; }
    else if (code === ':d') insert = `{date::${localDate()}}`;
    else if (code === ':r') insert = `{timestamp::${new Date().toISOString()}}`;
    else return false;
    event.preventDefault();
    replaceRange(content, caret - 2, caret, insert, back);
    return true;
}

function undoOrRedo(event, id, redo) {
    if (typedIn === id) return; // the browser undoes the typing
    event.preventDefault();
    if (redo) outline.redo(); else outline.undo();
}

function enter(content, id) {
    const text = sync(content);
    const model = outline.model;
    const note = model.get(id);
    // Enter on an empty nested note at the end of its list moves it out a level.
    if (text.trim() === '' && note.parent_note_id !== null && !model.hasChildren(id) && !model.nextSibling(id)
        && note.parent_note_id !== zoomedNoteId()) {
        outline.outdent(id);
        return;
    }
    const caret = caretOffset(content) ?? text.length;
    // Skip over a line break the browser keeps at the very end.
    outline.splitNote(id, text.slice(caret).trim() === '' ? text.length : caret);
}

function backspace(event, content, id) {
    const selection = window.getSelection();
    if (!selection || !selection.isCollapsed || caretOffset(content) !== 0) return;
    const model = outline.model;
    if (model.hasChildren(id)) return;
    const text = sync(content);
    const above = previousVisible(id);
    if (above) {
        event.preventDefault();
        outline.mergeInto(id, above.id);
        return;
    }
    if (text.trim() !== '') return;
    const below = nextVisible(id);
    if (!below) return; // the last note on the page stays
    event.preventDefault();
    outline.deleteNote(id);
    view.focusNote(below.id, 'start');
}

export function handleNoteKeyDown(event) {
    const content = event.target.closest?.('.note-content.edit-mode');
    if (!content || event.isComposing) return;
    const id = content.dataset.noteId;
    if (!outline.model.has(id)) return;

    if (autocomplete.handleKeyDown(event)) return;
    if (expandShortcut(event, content)) return;
    if (handleAutocloseBrackets(event)) return;

    const mod = event.ctrlKey || event.metaKey;
    const key = event.key;

    if (mod && !event.altKey && (key === 'z' || key === 'Z' || key === 'y')) {
        undoOrRedo(event, id, key === 'y' || event.shiftKey);
        return;
    }

    if (key === 'ArrowUp' || key === 'ArrowDown') {
        if ((event.altKey && event.shiftKey) || (mod && !event.shiftKey && !event.altKey)) {
            event.preventDefault();
            sync(content);
            typedIn = null;
            if (key === 'ArrowUp') outline.moveUp(id); else outline.moveDown(id);
            return;
        }
        if (event.shiftKey || event.altKey || mod) return;
        const up = key === 'ArrowUp';
        if (!onEdgeLine(content, up)) return;
        const neighbour = up ? previousVisible(id) : nextVisible(id);
        if (!neighbour) return;
        event.preventDefault();
        view.focusNote(neighbour.id, 'end');
        return;
    }

    if (event.altKey && !mod && !event.shiftKey && (key === 'ArrowRight' || key === 'ArrowLeft')) {
        if (key === 'ArrowRight') {
            event.preventDefault();
            sync(content);
            zoomInto(id);
            view.focusNote(id, 'end');
        } else if (zoomedNoteId()) {
            event.preventDefault();
            zoomOut();
            view.focusNote(id, 'end');
        }
        return;
    }

    switch (key) {
        case 'Enter':
            if (event.shiftKey) return; // line break inside the note
            event.preventDefault();
            typedIn = null;
            if (mod) {
                sync(content);
                outline.createChild(id, { focus: 'start' });
            } else {
                enter(content, id);
            }
            return;
        case 'Tab':
            event.preventDefault();
            sync(content);
            typedIn = null;
            if (event.shiftKey) outline.outdent(id); else outline.indent(id);
            return;
        case 'Backspace':
            if (!mod && !event.altKey) backspace(event, content, id);
            return;
        case 'Escape':
            event.preventDefault();
            content.blur();
            return;
    }
}
