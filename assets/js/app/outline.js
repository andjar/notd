/**
 * Outline controller: the only code that changes notes.
 *
 * Every user action becomes one intent operation that is
 *   1. applied to the in-memory model synchronously,
 *   2. reflected in the DOM (placed by identity, never by index),
 *   3. queued in the persisted outbox, which sends ops in order.
 * The server's answer is merged back into the same model. There are no
 * temporary ids and no rollbacks: ids are created here, and a failed request
 * is simply retried.
 *
 * @module app/outline
 */

import { OutlineModel } from './outline-model.js';
import { Outbox } from './outbox.js';
import { newId, newOpId } from './ids.js';
import { encrypt, decrypt, looksEncrypted } from '../utils.js';
import { notesAPI } from '../api_client.js';
import { page } from './page-state.js';
import * as view from '../ui/note-elements.js';

export const model = new OutlineModel();

let resyncWanted = false;
let resyncTimer = null;
let bannerEl = null;

// ------------------------------------------------------------- encryption

function pagePassword() {
    return page.password;
}

/** Content as it should be stored on the server (encrypted on locked pages). */
export function encodeContent(plain) {
    const pw = pagePassword();
    return pw ? encrypt(pw, plain) : plain;
}

/** Content as the user sees it. */
export function decodeContent(stored) {
    const pw = pagePassword();
    if (pw && looksEncrypted(stored)) {
        try { return decrypt(pw, stored) ?? '[DECRYPTION FAILED]'; } catch { return '[DECRYPTION FAILED]'; }
    }
    return stored ?? '';
}

// ----------------------------------------------------------------- outbox

export const outbox = new Outbox({
    send: (ops) => notesAPI.applyOps(ops),
    onApplied: handleApplied,
    onRejected: (error) => {
        console.error('[outline] server rejected a batch:', error);
        showBanner(`Some changes were rejected by the server (${error.message}). The page was reloaded from the server.`, 'error');
        scheduleResync(0);
    },
    onStatus: (status, info) => {
        const indicator = status === 'offline' ? 'error' : status;
        view.setSaveStatus(indicator, status === 'offline'
            ? `Offline — changes are kept and will be sent (retry in ${Math.round((info?.retryIn || 0) / 1000)} s)`
            : status === 'error' ? `Server error — retrying (${info?.error?.message || ''})` : undefined);
        if (status === 'saved') hideBanner();
        if (status === 'offline' && outbox.size) {
            showBanner('Working offline. Your changes are saved in this browser and will be sent when the server is reachable.', 'warning');
        }
    },
});

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => outbox.retryNow());
    // Pages used to be cached in localStorage; drop those stale entries.
    try {
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const k = localStorage.key(i);
            if (k && k.startsWith('notd_page_cache_')) localStorage.removeItem(k);
        }
    } catch { /* storage unavailable */ }
}

const channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('notd-sync') : null;
if (channel) {
    channel.onmessage = (e) => {
        if (e.data?.type === 'changed' && model.pageId !== null && (e.data.pageIds || []).map(Number).includes(Number(model.pageId))) {
            scheduleResync(250);
        }
    };
}

function handleApplied(data, sentOps) {
    const pageIds = new Set();
    for (const raw of data.notes || []) {
        if (raw.page_id !== undefined) pageIds.add(Number(raw.page_id));
        if (model.pageId === null || Number(raw.page_id) !== Number(model.pageId)) continue;
        const id = String(raw.id);
        const keepStructure = outbox.hasPending(id, ['create', 'move']);
        const keepContent = outbox.hasPending(id, ['create', 'edit']) || view.isEditing(id);
        const keepCollapsed = outbox.hasPending(id, ['collapse']);
        const r = model.mergeServer({ ...raw, content: decodeContent(raw.content) }, { keepStructure, keepContent, keepCollapsed });
        if (r.created || r.structural) {
            view.preserveFocus(() => view.placeNote(id));
            if (r.oldParent !== null) view.refreshParentState(r.oldParent);
        } else if (r.content) {
            view.refreshNoteContent(id);
        }
        view.refreshNoteMeta(id);
    }
    for (const id of data.deleted || []) {
        if (model.has(id) && !outbox.hasPending(id, ['create'])) {
            const change = model.apply({ type: 'delete', id });
            if (change) view.applyChange(change);
        }
    }
    if (model.pageId !== null && sentOps.length) pageIds.add(Number(model.pageId));
    if ((data.warnings || []).length) {
        console.warn('[outline] server skipped some operations:', data.warnings);
        resyncWanted = true;
    }
    if (resyncWanted && outbox.size === 0) scheduleResync(0);
    channel?.postMessage({ type: 'changed', pageIds: [...pageIds] });
}

// ----------------------------------------------------------- page loading

/**
 * Replaces the model with a page's notes (as returned by notes.php, content
 * already decrypted) and re-applies any ops still waiting in the outbox.
 */
export function loadPage(pageId, notes) {
    model.load(pageId, notes);
    for (const op of outbox.pending()) {
        if (op.type === 'create' && Number(op.page_id) !== Number(pageId)) continue;
        const plain = op.content !== undefined ? decodeContent(op.content) : undefined;
        model.apply({ ...op, plainContent: plain });
    }
}

/** Waits (briefly) for queued ops to reach the server, e.g. before loading a page. */
export async function settle(timeoutMs = 2500) {
    if (!outbox.size) return;
    await Promise.race([outbox.flush().catch(() => {}), new Promise(r => setTimeout(r, timeoutMs))]);
}

/** Throws if queued ops cannot be confirmed (used before uploads that need the note on the server). */
export async function ensureSynced() {
    await outbox.flush();
}

function scheduleResync(delay) {
    clearTimeout(resyncTimer);
    resyncTimer = setTimeout(resync, delay);
}

/** Reloads the current page's notes from the server, keeping focus and unsent edits. */
export async function resync() {
    if (model.pageId === null) return;
    if (outbox.size) {
        resyncWanted = true;
        try { await outbox.flush(); } catch { return; }
    }
    resyncWanted = false;
    let notes;
    try {
        notes = await notesAPI.forPage(model.pageId);
    } catch (e) {
        console.warn('[outline] resync failed', e);
        return;
    }
    const editing = view.editingNoteId();
    const editingText = editing ? view.currentText(editing) : null;
    loadPage(model.pageId, (notes || []).map(n => ({ ...n, content: decodeContent(n.content) })));
    if (editing && model.has(editing) && editingText !== null && model.get(editing).content !== editingText) {
        editNote(editing, editingText); // the local, unsaved text wins
    }
    view.preserveFocus(() => view.renderAll());
}

// ---------------------------------------------------------------- actions

function commit(op, plain, { undoable = true } = {}) {
    const inverse = undoable && !replaying ? invert(op, plain) : null;
    const change = model.apply(plain === undefined ? op : { ...op, plainContent: plain });
    if (!change) return null;
    if (inverse) record(inverse);
    outbox.enqueue(op);
    view.preserveFocus(() => view.applyChange(change));
    return change;
}

// -------------------------------------------------------------- undo/redo
//
// Each structural action records the actions that reverse it. Everything a
// single key press or click does is one undo step. Typing inside a note is
// not recorded here: the browser's own undo handles text while a note is
// being edited.

const undoStack = [];
const redoStack = [];
let recording = null;
let replaying = false;
const UNDO_LIMIT = 200;

/** The actions that undo `op`, computed from the model before the op is applied. */
function invert(op, plain) {
    const n = model.get(op.id);
    switch (op.type) {
        case 'create':
            return n ? [] : [{ type: 'delete', id: String(op.id) }];
        case 'delete':
            return model.subtreeIds(op.id).map((id) => {
                const x = model.get(id);
                const prev = model.prevSibling(id);
                return { type: 'create', id, parent_id: x.parent_note_id, after: prev ? prev.id : null, content: x.content || '', collapsed: x.collapsed ? 1 : 0 };
            });
        case 'move': {
            if (!n) return [];
            const prev = model.prevSibling(n.id);
            return [{ type: 'move', id: n.id, parent_id: n.parent_note_id, after: prev ? prev.id : null }];
        }
        case 'edit':
            return n && n.content !== plain ? [{ type: 'edit', id: n.id, content: n.content || '' }] : [];
        default:
            return []; // collapsing is not worth an undo step
    }
}

function record(inverse) {
    if (!inverse.length) return;
    if (!recording) {
        recording = [];
        queueMicrotask(() => {
            undoStack.push(recording);
            if (undoStack.length > UNDO_LIMIT) undoStack.shift();
            redoStack.length = 0;
            recording = null;
        });
    }
    recording.unshift(...inverse);
}

/** Runs recorded actions and returns the actions that reverse them. */
function replay(actions) {
    const inverse = [];
    let touched = null;
    replaying = true;
    try {
        for (const a of actions) {
            let op = null;
            let plain;
            if (a.type === 'create' && !model.has(a.id)) {
                const parentId = a.parent_id === null || model.has(a.parent_id) ? a.parent_id : null;
                const after = a.after !== null && model.get(a.after)?.parent_note_id === parentId ? a.after : null;
                plain = a.content;
                op = { op_id: newOpId(), type: 'create', id: a.id, page_id: model.pageId, parent_id: parentId, after,
                       sort_key: model.keyFor(parentId, after), content: encodeContent(a.content), collapsed: a.collapsed };
            } else if (a.type === 'delete' && model.has(a.id)) {
                op = { op_id: newOpId(), type: 'delete', id: a.id };
            } else if (a.type === 'move' && model.has(a.id)) {
                const parentId = a.parent_id === null || model.has(a.parent_id) ? a.parent_id : null;
                if (parentId !== null && model.isInSubtree(a.id, parentId)) continue;
                const after = a.after !== null && a.after !== a.id && model.get(a.after)?.parent_note_id === parentId ? a.after : null;
                op = { op_id: newOpId(), type: 'move', id: a.id, parent_id: parentId, after, sort_key: model.keyFor(parentId, after, a.id) };
            } else if (a.type === 'edit' && model.has(a.id)) {
                plain = a.content;
                op = { op_id: newOpId(), type: 'edit', id: a.id, content: encodeContent(a.content) };
            }
            if (!op) continue;
            const back = invert(op, plain);
            const change = model.apply(plain === undefined ? op : { ...op, plainContent: plain });
            if (!change) continue;
            inverse.unshift(...back);
            outbox.enqueue(op);
            view.applyChange(change);
            if (op.type === 'edit') view.setText(op.id, plain);
            if (op.type !== 'delete') touched = op.id;
        }
    } finally {
        replaying = false;
    }
    return { inverse, touched };
}

function step(from, to) {
    // Finish the edit in progress first, so its text is in the model.
    const editing = view.editingNoteId();
    if (editing) view.finishEditing(editing);
    const actions = from.pop();
    if (!actions) return false;
    const { inverse, touched } = replay(actions);
    if (inverse.length) to.push(inverse);
    if (touched && model.has(touched)) view.focusNote(touched, 'end');
    return true;
}

/** Undoes the last structural action (create, delete, move, indent, split, merge, task toggle). */
export function undo() {
    return step(undoStack, redoStack);
}

export function redo() {
    return step(redoStack, undoStack);
}

export function canUndo() {
    return undoStack.length > 0;
}

/** Creates a note under `parentId` right after `afterId` (null = first). Returns its id. */
export function createNote({ parentId = null, afterId = null, content = '', focus = 'start' } = {}) {
    if (model.pageId === null) return null;
    if (parentId !== null && model.get(parentId)?.collapsed) setCollapsed(parentId, false);
    const id = newId();
    const op = {
        op_id: newOpId(), type: 'create', id, page_id: model.pageId,
        parent_id: parentId, after: afterId, sort_key: model.keyFor(parentId, afterId),
        content: encodeContent(content), collapsed: 0,
    };
    if (!commit(op, content)) return null;
    if (focus) view.focusNote(id, focus);
    return id;
}

export function createSiblingAfter(id, opts = {}) {
    const n = model.get(id);
    if (!n) return null;
    return createNote({ parentId: n.parent_note_id, afterId: n.id, ...opts });
}

export function createChild(id, opts = {}) {
    if (!model.has(id)) return null;
    const last = model.children(id).at(-1);
    return createNote({ parentId: String(id), afterId: last ? last.id : null, ...opts });
}

export function createAtEnd(opts = {}) {
    const last = model.children(null).at(-1);
    return createNote({ parentId: null, afterId: last ? last.id : null, ...opts });
}

/** Moves a note (with its subtree) under `parentId` right after `afterId`. */
export function moveNote(id, parentId, afterId) {
    const n = model.get(id);
    if (!n) return false;
    parentId = parentId === null || parentId === undefined ? null : String(parentId);
    afterId = afterId === null || afterId === undefined ? null : String(afterId);
    if (parentId !== null && model.isInSubtree(id, parentId)) return false;
    if (afterId === n.id) return false;
    if (n.parent_note_id === parentId) {
        const prev = model.prevSibling(id);
        if ((prev ? prev.id : null) === afterId) {
            view.placeNote(n.id); // no-op move (e.g. a drag dropped in place)
            return false;
        }
    }
    const op = {
        op_id: newOpId(), type: 'move', id: n.id, parent_id: parentId, after: afterId,
        sort_key: model.keyFor(parentId, afterId, n.id),
    };
    return !!commit(op);
}

/** Tab: make the note the last child of its previous sibling. */
export function indent(id) {
    const prev = model.prevSibling(id);
    if (!prev) return false;
    if (prev.collapsed) setCollapsed(prev.id, false);
    const last = model.children(prev.id).at(-1);
    return moveNote(id, prev.id, last ? last.id : null);
}

/** Shift+Tab: place the note right after its parent. */
export function outdent(id) {
    const n = model.get(id);
    if (!n || n.parent_note_id === null) return false;
    const parent = model.get(n.parent_note_id);
    return moveNote(id, parent.parent_note_id, parent.id);
}

export function moveUp(id) {
    const n = model.get(id);
    const prev = n && model.prevSibling(id);
    if (!prev) return false;
    const before = model.prevSibling(prev.id);
    return moveNote(id, n.parent_note_id, before ? before.id : null);
}

export function moveDown(id) {
    const n = model.get(id);
    const next = n && model.nextSibling(id);
    if (!next) return false;
    return moveNote(id, n.parent_note_id, next.id);
}

/**
 * Records new content for a note (plain text). Cheap to call on every
 * keystroke. Typing is not an undo step (the browser undoes text); pass
 * `undoable` for changes made on the user's behalf, such as ticking a task.
 */
export function editNote(id, plain, { undoable = false } = {}) {
    const n = model.get(id);
    if (!n || n.content === plain) return false;
    return !!commit({ op_id: newOpId(), type: 'edit', id: n.id, content: encodeContent(plain) }, plain, { undoable });
}

/**
 * Enter: splits a note at `offset` of its text. The text after the caret
 * moves to a new note, which gets the focus. At the very start of a
 * non-empty note an empty note is inserted above instead.
 */
export function splitNote(id, offset) {
    const n = model.get(id);
    if (!n) return null;
    const text = n.content || '';
    offset = Math.max(0, Math.min(offset ?? text.length, text.length));
    if (offset === 0 && text !== '') {
        const prev = model.prevSibling(id);
        return createNote({ parentId: n.parent_note_id, afterId: prev ? prev.id : null, content: '', focus: null });
    }
    const before = text.slice(0, offset);
    const after = text.slice(offset);
    if (after !== '') {
        editNote(id, before, { undoable: true });
        view.setText(id, before);
    }
    // Below an open parent the new note becomes its first child, so it appears right under the caret.
    if (model.hasChildren(id) && !n.collapsed) {
        return createNote({ parentId: n.id, afterId: null, content: after, focus: 'start' });
    }
    return createNote({ parentId: n.parent_note_id, afterId: n.id, content: after, focus: 'start' });
}

/**
 * Backspace at the start of a note: appends its text to `intoId` (the note
 * above) and removes it. Only notes without children are merged.
 */
export function mergeInto(id, intoId) {
    const n = model.get(id);
    const target = model.get(intoId);
    if (!n || !target || n.id === target.id || model.hasChildren(id)) return false;
    const joinAt = (target.content || '').length;
    const merged = (target.content || '') + (n.content || '');
    deleteNote(id);
    editNote(target.id, merged, { undoable: true });
    view.setText(target.id, merged);
    view.focusNote(target.id, joinAt);
    return true;
}

export function setCollapsed(id, collapsed) {
    const n = model.get(id);
    if (!n || !!n.collapsed === !!collapsed) return false;
    return !!commit({ op_id: newOpId(), type: 'collapse', id: n.id, collapsed: collapsed ? 1 : 0 });
}

/** Deletes a note and its whole subtree. */
export function deleteNote(id) {
    if (!model.has(id)) return false;
    return !!commit({ op_id: newOpId(), type: 'delete', id: String(id) });
}

/**
 * Sends every note on the page again, encoded for the page's current lock
 * state (used when a page is locked or unlocked). The model keeps plain text.
 */
export function reencryptAllNotes() {
    for (const n of model.list()) {
        outbox.enqueue({ op_id: newOpId(), type: 'edit', id: n.id, content: encodeContent(n.content || '') });
    }
    return outbox.flush();
}

// ----------------------------------------------------------------- banner

function showBanner(message, kind = 'warning') {
    if (typeof document === 'undefined') return;
    if (!bannerEl) {
        bannerEl = document.createElement('div');
        bannerEl.className = 'sync-banner';
        bannerEl.setAttribute('role', 'status');
        document.body.appendChild(bannerEl);
    }
    bannerEl.textContent = message;
    bannerEl.dataset.kind = kind;
    bannerEl.hidden = false;
}

function hideBanner() {
    if (bannerEl) bannerEl.hidden = true;
}

if (typeof window !== 'undefined') {
    window.notdOutline = { model, outbox, resync, undo, redo };
}
