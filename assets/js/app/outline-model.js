/**
 * In-memory model of the notes on the current page: the single source of
 * truth on the client. The DOM is rendered from it and every edit is an
 * operation applied to it (and queued for the server by the outbox).
 *
 * Pure data, no DOM: it can be unit-tested in Node (tests/js/model.test.mjs).
 *
 * @module app/outline-model
 */

import { keyBetween, compareKeys } from './fractional-index.js';

const ROOT = '\u0000root';
const parentKey = (parentId) => (parentId === null || parentId === undefined ? ROOT : String(parentId));

function normalize(note) {
    return {
        ...note,
        id: String(note.id),
        parent_note_id: note.parent_note_id === null || note.parent_note_id === undefined || note.parent_note_id === ''
            ? null : String(note.parent_note_id),
        sort_key: note.sort_key || 'a0',
        content: note.content ?? '',
        collapsed: note.collapsed ? 1 : 0,
    };
}

/** Sibling order: sort_key, then id (identical to the server's ORDER BY). */
export function compareNotes(a, b) {
    return compareKeys(a.sort_key, b.sort_key) || compareKeys(a.id, b.id);
}

export class OutlineModel {
    constructor() {
        this.pageId = null;
        /** @type {Map<string, object>} */
        this.notes = new Map();
        /** @type {Map<string, Set<string>>} parentKey -> child ids */
        this.byParent = new Map();
    }

    // ------------------------------------------------------------ loading

    load(pageId, notes) {
        this.pageId = pageId;
        this.notes.clear();
        this.byParent.clear();
        for (const raw of notes || []) this._insert(normalize(raw));
        // Notes whose parent is missing (shouldn't happen) are shown at the top level.
        for (const n of this.notes.values()) {
            if (n.parent_note_id !== null && !this.notes.has(n.parent_note_id)) this._reparent(n, null);
        }
    }

    _insert(note) {
        this.notes.set(note.id, note);
        const k = parentKey(note.parent_note_id);
        if (!this.byParent.has(k)) this.byParent.set(k, new Set());
        this.byParent.get(k).add(note.id);
    }

    _reparent(note, newParentId) {
        this.byParent.get(parentKey(note.parent_note_id))?.delete(note.id);
        note.parent_note_id = newParentId === null ? null : String(newParentId);
        const k = parentKey(note.parent_note_id);
        if (!this.byParent.has(k)) this.byParent.set(k, new Set());
        this.byParent.get(k).add(note.id);
    }

    // ------------------------------------------------------------ queries

    has(id) { return this.notes.has(String(id)); }
    get(id) { return this.notes.get(String(id)) || null; }

    /** Children of `parentId` (null = top level), in display order. */
    children(parentId) {
        const set = this.byParent.get(parentKey(parentId));
        if (!set) return [];
        return [...set].map(id => this.notes.get(id)).sort(compareNotes);
    }

    hasChildren(id) {
        return (this.byParent.get(parentKey(id))?.size || 0) > 0;
    }

    siblings(id) {
        const n = this.get(id);
        return n ? this.children(n.parent_note_id) : [];
    }

    prevSibling(id) {
        const sibs = this.siblings(id);
        const i = sibs.findIndex(s => s.id === String(id));
        return i > 0 ? sibs[i - 1] : null;
    }

    nextSibling(id) {
        const sibs = this.siblings(id);
        const i = sibs.findIndex(s => s.id === String(id));
        return i >= 0 && i < sibs.length - 1 ? sibs[i + 1] : null;
    }

    depth(id) {
        let d = 0;
        let n = this.get(id);
        const seen = new Set();
        while (n && n.parent_note_id !== null && !seen.has(n.id)) {
            seen.add(n.id);
            d++;
            n = this.get(n.parent_note_id);
        }
        return d;
    }

    /** True when `id` is `rootId` or one of its descendants. */
    isInSubtree(rootId, id) {
        let n = this.get(id);
        const seen = new Set();
        while (n && !seen.has(n.id)) {
            if (n.id === String(rootId)) return true;
            seen.add(n.id);
            n = n.parent_note_id === null ? null : this.get(n.parent_note_id);
        }
        return false;
    }

    /** Ids of `id` and all its descendants (pre-order). */
    subtreeIds(id) {
        const out = [];
        const walk = (x) => { out.push(x); for (const c of this.children(x)) walk(c.id); };
        if (this.has(id)) walk(String(id));
        return out;
    }

    /** All notes in document (pre-order) order. */
    list() {
        const out = [];
        const walk = (parentId) => { for (const c of this.children(parentId)) { out.push(c); walk(c.id); } };
        walk(null);
        return out;
    }

    /** Tree of plain objects with `children` arrays, for rendering. */
    tree(parentId = null) {
        return this.children(parentId).map(n => ({ ...n, children: this.tree(n.id) }));
    }

    /**
     * Sort key for a note placed under `parentId` right after `afterId`
     * (null = first), ignoring `selfId`. Falls back gracefully when the
     * neighbours share a key (the server then spreads the group out).
     */
    keyFor(parentId, afterId, selfId = null) {
        const sibs = this.children(parentId).filter(s => s.id !== (selfId === null ? null : String(selfId)));
        let idx = -1;
        if (afterId !== null && afterId !== undefined) {
            idx = sibs.findIndex(s => s.id === String(afterId));
            if (idx === -1) idx = sibs.length - 1;
        }
        const lo = idx >= 0 ? sibs[idx].sort_key : null;
        const hi = idx + 1 < sibs.length ? sibs[idx + 1].sort_key : null;
        if (lo !== null && hi !== null && lo >= hi) return keyBetween(lo, null);
        return keyBetween(lo, hi);
    }

    // ---------------------------------------------------------- mutations

    /**
     * Applies an operation locally. Returns a description of what changed so
     * the view can update only what is needed.
     * @returns {{kind: string, id: string, oldParent?: string|null, removed?: string[]}|null}
     */
    apply(op) {
        switch (op.type) {
            case 'create': {
                if (this.has(op.id)) return null;
                const parent = op.parent_id !== null && op.parent_id !== undefined && this.has(op.parent_id) ? String(op.parent_id) : null;
                this._insert(normalize({
                    id: op.id, page_id: op.page_id, parent_note_id: parent, sort_key: op.sort_key,
                    content: op.plainContent ?? op.content ?? '', collapsed: op.collapsed ? 1 : 0,
                    properties: {}, has_attachments: false, version: 0,
                }));
                return { kind: 'create', id: String(op.id) };
            }
            case 'move': {
                const n = this.get(op.id);
                if (!n) return null;
                const parent = op.parent_id === null || op.parent_id === undefined ? null : String(op.parent_id);
                if (parent !== null && (!this.has(parent) || this.isInSubtree(n.id, parent))) return null;
                const oldParent = n.parent_note_id;
                this._reparent(n, parent);
                n.sort_key = op.sort_key;
                return { kind: 'move', id: n.id, oldParent };
            }
            case 'edit': {
                const n = this.get(op.id);
                if (!n) return null;
                n.content = op.plainContent ?? op.content;
                return { kind: 'edit', id: n.id };
            }
            case 'collapse': {
                const n = this.get(op.id);
                if (!n) return null;
                n.collapsed = op.collapsed ? 1 : 0;
                return { kind: 'collapse', id: n.id };
            }
            case 'delete': {
                const n = this.get(op.id);
                if (!n) return null;
                const removed = this.subtreeIds(n.id);
                const oldParent = n.parent_note_id;
                for (const id of removed) {
                    const x = this.notes.get(id);
                    this.byParent.get(parentKey(x.parent_note_id))?.delete(id);
                    this.byParent.delete(parentKey(id));
                    this.notes.delete(id);
                }
                return { kind: 'delete', id: n.id, oldParent, removed };
            }
            default:
                return null;
        }
    }

    /**
     * Merges an authoritative note from the server. Fields the client still
     * has unsent changes for are kept local.
     * @returns {{structural: boolean, content: boolean, created: boolean, oldParent: string|null}}
     */
    mergeServer(raw, { keepStructure = false, keepContent = false, keepCollapsed = false } = {}) {
        const s = normalize(raw);
        const n = this.get(s.id);
        if (!n) {
            if (s.page_id !== undefined && this.pageId !== null && Number(s.page_id) !== Number(this.pageId)) {
                return { structural: false, content: false, created: false, oldParent: null };
            }
            if (s.parent_note_id !== null && !this.has(s.parent_note_id)) s.parent_note_id = null;
            this._insert(s);
            return { structural: true, content: true, created: true, oldParent: null };
        }
        const oldParent = n.parent_note_id;
        let structural = false, content = false;
        if (!keepStructure && (n.parent_note_id !== s.parent_note_id || n.sort_key !== s.sort_key)
            && (s.parent_note_id === null || this.has(s.parent_note_id))) {
            this._reparent(n, s.parent_note_id);
            n.sort_key = s.sort_key;
            structural = true;
        }
        if (!keepContent && n.content !== s.content) {
            n.content = s.content;
            content = true;
        }
        if (!keepCollapsed) n.collapsed = s.collapsed;
        for (const f of ['properties', 'has_attachments', 'version', 'internal', 'page_id', 'created_at', 'updated_at']) {
            if (raw[f] !== undefined) n[f] = raw[f];
        }
        return { structural, content, created: false, oldParent };
    }
}
