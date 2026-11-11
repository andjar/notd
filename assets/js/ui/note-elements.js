/**
 * DOM view of the outline. Everything here is derived from the model in
 * app/outline.js: notes are placed by identity (before the element of their
 * next sibling), never by numeric position, so the DOM cannot drift from the
 * data.
 *
 * @module ui/note-elements
 */

import { renderNote, enterEditMode, leaveEditMode, showContent, caretOffset, setCaret } from './note-view.js';
import { renderAttachments } from './attachments.js';
import { hydrate } from './embeds.js';
import { renderIcons } from '../utils.js';
import * as outline from '../app/outline.js';

/** Set by other modules that need to know when notes were re-rendered, moved or removed. */
export const hooks = { structureChanged: null };

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
        initializeDragAndDrop(kids);
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
    const fragment = document.createDocumentFragment();
    for (const node of outline.model.tree(null)) fragment.appendChild(renderNote(node, 0));
    c.replaceChildren(fragment);
    emptyMessage(outline.model.children(null).length === 0);
    initializeDragAndDrop(c);
    hydrate(c);
    hooks.structureChanged?.();
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
        const fresh = el;
        queueMicrotask(() => hydrate(fresh));
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
    if (el && n) showContent(el, n.content || '');
}

/** Shows `text` as the note's text, also while the note is being edited. */
export function setText(id, text) {
    const el = contentElementOf(id);
    if (!el) return;
    if (el.classList.contains('edit-mode')) {
        const caret = document.activeElement === el ? Math.min(caretOffset(el) ?? text.length, text.length) : null;
        el.textContent = text;
        el.dataset.rawContent = text;
        if (caret !== null) setCaret(el, caret);
    } else {
        showContent(el, text);
    }
}

/** Ends the edit in progress on a note and records its text. */
export function finishEditing(id) {
    const el = contentElementOf(id);
    if (!el || !el.classList.contains('edit-mode')) return;
    blurSuppressed++;
    try {
        const text = leaveEditMode(el);
        outline.editNote(id, text);
        if (document.activeElement === el) el.blur();
    } finally {
        blurSuppressed--;
    }
}

/** Updates server-derived decorations (attachments). */
export function refreshNoteMeta(id) {
    const n = outline.model.get(id);
    const el = getNoteElement(id);
    if (!n || !el || !n.has_attachments) return;
    if (!el.querySelector(':scope > .note-header-row .note-attachments')) renderAttachments(n.id);
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
            hooks.structureChanged?.();
            break;
        case 'delete':
            removeNoteElement(change.id);
            refreshParentState(change.oldParent);
            hooks.structureChanged?.();
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
        if (!el.classList.contains('edit-mode')) enterEditMode(el);
        if (document.activeElement !== el) el.focus({ preventScroll: true });
        setCaret(el, offset ?? (el.textContent || '').length);
    }
    return result;
}

/** Puts a note in edit mode with the caret at 'start', 'end' or a character offset. */
export function focusNote(id, where = 'end') {
    const el = contentElementOf(id);
    if (!el) return;
    enterEditMode(el);
    el.focus({ preventScroll: true });
    const length = (el.textContent || '').length;
    setCaret(el, where === 'start' ? 0 : where === 'end' ? length : Math.max(0, Math.min(Number(where) || 0, length)));
    el.scrollIntoView({ block: 'nearest' });
}

// ----------------------------------------------------------- save status

const STATUS = {
    saved: ['check-circle', 'All changes saved'],
    pending: [null, 'Saving...'],
    error: ['alert-triangle', 'Changes could not be saved yet'],
};

/** Shows the save state in the corner indicator: 'saved', 'pending' or 'error'. */
export function setSaveStatus(status, title) {
    const indicator = document.getElementById('save-status-indicator');
    if (!indicator) return;
    if (!STATUS[status]) status = 'saved';
    const [icon, defaultTitle] = STATUS[status];
    if (indicator.dataset.status !== status) {
        indicator.dataset.status = status;
        indicator.className = `save-status-indicator status-${status}`;
        indicator.innerHTML = icon
            ? `<i data-feather="${icon}"></i>`
            : '<div class="dot-spinner"><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div></div>';
        renderIcons(indicator);
    }
    indicator.title = title || defaultTitle;
}

// ------------------------------------------------------------ drag & drop

function makeSortable(c) {
    if (c.dataset.sortable === '1') return;
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

let pendingSortables = [];
let sortableTimer = null;

function drainSortables(deadline) {
    sortableTimer = null;
    let budget = 200;
    while (pendingSortables.length && budget-- > 0 && (!deadline || deadline.didTimeout || deadline.timeRemaining() > 2)) {
        const c = pendingSortables.pop();
        if (c.isConnected) makeSortable(c);
    }
    if (pendingSortables.length) scheduleSortables();
}

function scheduleSortables() {
    if (sortableTimer !== null) return;
    sortableTimer = typeof requestIdleCallback === 'function'
        ? requestIdleCallback(drainSortables, { timeout: 2000 })
        : setTimeout(() => drainSortables(null), 50);
}

/**
 * Makes every children container under `root` a drop target. Setting up
 * thousands of containers takes a while, so on big pages it happens in idle
 * time; a drag that starts earlier finishes the job first (ensureDragReady).
 */
export function initializeDragAndDrop(root = container()) {
    if (typeof Sortable === 'undefined' || !root) return;
    if (root.id === 'notes-container' || root.classList?.contains('note-children')) makeSortable(root);
    const containers = root.querySelectorAll?.('.note-children') || [];
    if (containers.length <= 100) {
        containers.forEach(makeSortable);
        return;
    }
    pendingSortables.push(...containers);
    scheduleSortables();
}

/** Called when a drag is about to start: all drop targets must exist now. */
export function ensureDragReady() {
    while (pendingSortables.length) {
        const c = pendingSortables.pop();
        if (c.isConnected) makeSortable(c);
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

