/**
 * DOM view of the outline. Everything here is derived from the model in
 * app/outline.js: notes are placed by identity (before the element of their
 * next sibling), never by numeric position, so the DOM cannot drift from the
 * data.
 *
 * @module ui/note-elements
 */

import { renderNote, parseAndRenderContent, switchToEditMode, renderAttachments } from './note-renderer.js';
import * as outline from '../app/outline.js';

let blurSuppressed = 0;

/** True while the view moves a focused note (its blur must not end editing). */
export function isBlurSuppressed() {
    return blurSuppressed > 0;
}

function container() {
    return document.getElementById('notes-container');
}

export function getNoteElement(id) {
    if (id === null || id === undefined) return null;
    return container()?.querySelector(`.note-item[data-note-id="${CSS.escape(String(id))}"]`) || null;
}

function contentElementOf(id) {
    return getNoteElement(id)?.querySelector(':scope > .note-header-row .note-content') || null;
}

function childrenContainerOf(parentId) {
    if (parentId === null) return container();
    const el = getNoteElement(parentId);
    if (!el) return null;
    let kids = el.querySelector(':scope > .note-children');
    if (!kids) {
        kids = document.createElement('div');
        kids.className = 'note-children';
        el.appendChild(kids);
        initializeDragAndDrop(el);
    }
    return kids;
}

function setNestingLevels(el, level) {
    el.style.setProperty('--nesting-level', level);
    const kids = el.querySelector(':scope > .note-children');
    if (!kids) return;
    for (const child of kids.children) {
        if (child.classList.contains('note-item')) setNestingLevels(child, level + 1);
    }
}

function emptyMessage(show) {
    const c = container();
    if (!c) return;
    const msg = c.querySelector(':scope > .no-notes-message');
    if (show && !msg) {
        const p = document.createElement('p');
        p.className = 'no-notes-message';
        p.textContent = 'No notes on this page yet. Click the + button to add your first note.';
        c.appendChild(p);
    } else if (!show && msg) {
        msg.remove();
    }
}

// --------------------------------------------------------------- rendering

/** Re-renders the whole outline from the model. */
export function renderAll() {
    const c = container();
    if (!c) return;
    c.innerHTML = '';
    for (const node of outline.model.tree(null)) c.appendChild(renderNote(node, 0));
    emptyMessage(outline.model.children(null).length === 0);
    initializeDragAndDrop(c);
    if (typeof feather !== 'undefined') {
        try { feather.replace(); } catch { /* icons are cosmetic */ }
    }
}

/**
 * Back-compat entry point used by the page loader: loads `notesData` into
 * the model (when given) and renders it.
 */
export function displayNotes(notesData, pageId) {
    if (Array.isArray(notesData)) {
        outline.loadPage(pageId ?? outline.model.pageId, notesData);
    }
    renderAll();
}

/** Inserts or moves a note's element to where the model says it belongs. */
export function placeNote(id) {
    const n = outline.model.get(id);
    if (!n) return null;
    let el = getNoteElement(id);
    const target = childrenContainerOf(n.parent_note_id);
    if (!target) return null;
    if (!el) {
        el = renderNote({ ...n, children: outline.model.tree(n.id) }, outline.model.depth(n.id));
        initializeDragAndDrop(el);
    }
    const next = outline.model.nextSibling(id);
    const ref = next ? getNoteElement(next.id) : null;
    const before = ref && ref.parentElement === target ? ref : null;
    if (el.parentElement !== target || el.nextElementSibling !== before) {
        target.insertBefore(el, before);
    }
    setNestingLevels(el, outline.model.depth(id));
    refreshParentState(n.parent_note_id);
    emptyMessage(false);
    return el;
}

/** Updates the has-children / collapsed state of a note's element. */
export function refreshParentState(id) {
    if (id === null || id === undefined) {
        emptyMessage(outline.model.children(null).length === 0);
        return;
    }
    const el = getNoteElement(id);
    const n = outline.model.get(id);
    if (!el || !n) return;
    const has = outline.model.hasChildren(id);
    el.classList.toggle('has-children', has);
    el.classList.toggle('collapsed', !!n.collapsed);
    const kids = el.querySelector(':scope > .note-children');
    if (kids) kids.classList.toggle('collapsed', !!n.collapsed);
    const arrow = el.querySelector(':scope > .note-header-row .note-collapse-arrow');
    if (arrow) arrow.dataset.collapsed = n.collapsed ? 'true' : 'false';
}

export function removeNoteElement(id) {
    getNoteElement(id)?.remove();
}

/** Re-renders a note's content from the model (skipped while it is being edited). */
export function refreshNoteContent(id) {
    const el = contentElementOf(id);
    const n = outline.model.get(id);
    if (!el || !n || el.classList.contains('edit-mode')) return;
    el.dataset.rawContent = n.content || '';
    el.innerHTML = n.content && n.content.trim() ? parseAndRenderContent(n.content) : '';
}

/** Updates server-derived decorations (attachments). */
export function refreshNoteMeta(id) {
    const n = outline.model.get(id);
    const el = getNoteElement(id);
    if (!n || !el || !n.has_attachments) return;
    const wrapper = el.querySelector(':scope > .note-header-row .note-content-wrapper');
    if (wrapper && !wrapper.querySelector('.note-attachments')) {
        const att = document.createElement('div');
        att.className = 'note-attachments';
        wrapper.appendChild(att);
        renderAttachments(att, n.id, true);
    }
}

/** Reflects a local model change (see OutlineModel.apply) in the DOM. */
export function applyChange(change) {
    switch (change.kind) {
        case 'create':
            placeNote(change.id);
            break;
        case 'move':
            placeNote(change.id);
            refreshParentState(change.oldParent);
            break;
        case 'delete':
            removeNoteElement(change.id);
            refreshParentState(change.oldParent);
            break;
        case 'collapse':
            refreshParentState(change.id);
            break;
        case 'edit':
            refreshNoteContent(change.id);
            break;
    }
}

// ------------------------------------------------------------ focus/caret

export function editingNoteId() {
    const el = document.activeElement?.closest?.('.note-content.edit-mode');
    return el && container()?.contains(el) ? el.dataset.noteId : null;
}

export function isEditing(id) {
    return editingNoteId() === String(id);
}

export function currentText(id) {
    const el = contentElementOf(id);
    return el ? (el.dataset.rawContent ?? el.textContent ?? '') : null;
}

function caretOffset(el) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount || !el.contains(sel.anchorNode)) return null;
    const range = document.createRange();
    range.selectNodeContents(el);
    range.setEnd(sel.anchorNode, sel.anchorOffset);
    return range.toString().length;
}

function setCaret(el, offset) {
    const sel = window.getSelection();
    const range = document.createRange();
    let remaining = offset;
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    let placed = false;
    while (node) {
        if (remaining <= node.textContent.length) {
            range.setStart(node, remaining);
            placed = true;
            break;
        }
        remaining -= node.textContent.length;
        node = walker.nextNode();
    }
    if (!placed) {
        range.selectNodeContents(el);
        range.collapse(offset === 0);
    } else {
        range.collapse(true);
    }
    sel.removeAllRanges();
    sel.addRange(range);
}

/**
 * Runs `fn` (which may move or re-create the focused note's element) and
 * puts the caret back where it was.
 */
export function preserveFocus(fn) {
    const id = editingNoteId();
    if (!id) return fn();
    const before = contentElementOf(id);
    const offset = before ? caretOffset(before) : null;
    blurSuppressed++;
    let result;
    try {
        result = fn();
    } finally {
        blurSuppressed--;
    }
    const el = contentElementOf(id);
    if (el) {
        if (!el.classList.contains('edit-mode')) switchToEditMode(el);
        if (document.activeElement !== el) el.focus({ preventScroll: true });
        setCaret(el, offset ?? (el.textContent || '').length);
    }
    return result;
}

/** Puts a note in edit mode with the caret at 'start' or 'end'. */
export function focusNote(id, where = 'end') {
    const el = contentElementOf(id);
    if (!el) return;
    switchToEditMode(el);
    el.focus({ preventScroll: true });
    setCaret(el, where === 'start' ? 0 : (el.textContent || '').length);
    el.scrollIntoView({ block: 'nearest' });
}

// ----------------------------------------------------------- save status

export function setSaveStatus(status, title) {
    if (window.ui && typeof window.ui.updateSaveStatusIndicator === 'function') {
        window.ui.updateSaveStatusIndicator(status);
        const indicator = document.getElementById('save-status-indicator');
        if (indicator && title) indicator.title = title;
    }
}

// ------------------------------------------------------------ drag & drop

/** Attaches Sortable to every children container under `root` that lacks one. */
export function initializeDragAndDrop(root = container()) {
    if (typeof Sortable === 'undefined' || !root) return;
    const containers = [];
    if (root.id === 'notes-container' || root.classList?.contains('note-children')) containers.push(root);
    root.querySelectorAll?.('.note-children').forEach(c => containers.push(c));
    for (const c of containers) {
        if (c.dataset.sortable === '1') continue;
        c.dataset.sortable = '1';
        Sortable.create(c, {
            group: 'notes',
            animation: 150,
            handle: '.note-bullet',
            draggable: '.note-item',
            ghostClass: 'note-ghost',
            onEnd: handleNoteDrop,
        });
    }
}

/** Translates a finished drag into one move op (or snaps back if invalid). */
export function handleNoteDrop(evt) {
    const id = evt.item?.dataset.noteId;
    if (!id) return;
    if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;
    const parentEl = evt.to.closest('.note-item');
    const parentId = parentEl ? parentEl.dataset.noteId : null;
    let prev = evt.item.previousElementSibling;
    while (prev && !prev.classList.contains('note-item')) prev = prev.previousElementSibling;
    const afterId = prev ? prev.dataset.noteId : null;
    const oldParent = outline.model.get(id)?.parent_note_id ?? null;
    if (!outline.moveNote(id, parentId, afterId)) {
        placeNote(id); // invalid drop (e.g. into its own subtree): snap back
    }
    refreshParentState(oldParent);
}

if (typeof window !== 'undefined') {
    window.initializeDragAndDrop = initializeDragAndDrop;
}
