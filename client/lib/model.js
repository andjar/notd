// The blocks of the page on screen, in memory: the client's single source of
// truth. The view is drawn from it, and every change is an operation applied
// here first and then sent to the server by the outbox. Pure data, no DOM, so it
// is tested in Node (tests/js/model.test.mjs). Ported from notd 3.

import { keyBetween } from './keys.js';

/** Sibling order: sort key, then id, exactly as the server orders them. */
export function compareNodes(a, b) {
  return a.sort < b.sort ? -1 : a.sort > b.sort ? 1 : a.id < b.id ? -1 : a.id > b.id ? 1 : 0;
}

export class OutlineModel {
  constructor() {
    this.pageId = null;
    this.nodes = new Map();      // id -> { id, parent, sort, text, collapsed }
    this.byParent = new Map();   // parent id -> Set of child ids
  }

  load(pageId, blocks) {
    this.pageId = pageId;
    this.nodes.clear();
    this.byParent.clear();
    for (const b of blocks) this.#insert({ id: b.id, parent: b.parent, sort: b.sort || 'a0', text: b.text ?? '', collapsed: !!b.collapsed });
    for (const n of this.nodes.values()) {
      if (n.parent !== pageId && !this.nodes.has(n.parent)) this.#reparent(n, pageId); // orphans show at the top
    }
  }

  #insert(node) {
    this.nodes.set(node.id, node);
    if (!this.byParent.has(node.parent)) this.byParent.set(node.parent, new Set());
    this.byParent.get(node.parent).add(node.id);
  }

  #reparent(node, parent) {
    this.byParent.get(node.parent)?.delete(node.id);
    node.parent = parent;
    if (!this.byParent.has(parent)) this.byParent.set(parent, new Set());
    this.byParent.get(parent).add(node.id);
  }

  has(id) { return this.nodes.has(id); }
  get(id) { return this.nodes.get(id) ?? null; }

  /** Children in display order; the page id gives the top level. */
  children(parent) {
    const set = this.byParent.get(parent);
    return set ? [...set].map((id) => this.nodes.get(id)).sort(compareNodes) : [];
  }

  hasChildren(id) { return (this.byParent.get(id)?.size ?? 0) > 0; }
  siblings(id) { const n = this.get(id); return n ? this.children(n.parent) : []; }

  prevSibling(id) {
    const s = this.siblings(id);
    const i = s.findIndex((x) => x.id === id);
    return i > 0 ? s[i - 1] : null;
  }

  nextSibling(id) {
    const s = this.siblings(id);
    const i = s.findIndex((x) => x.id === id);
    return i >= 0 && i < s.length - 1 ? s[i + 1] : null;
  }

  depth(id) {
    let d = 0;
    for (let n = this.get(id); n && n.parent !== this.pageId; n = this.get(n.parent)) d++;
    return d;
  }

  /** True when id is root or one of its descendants. */
  isInSubtree(root, id) {
    for (let n = this.get(id); n; n = this.get(n.parent)) if (n.id === root) return true;
    return false;
  }

  /** id and its descendants, parents first. */
  subtreeIds(id) {
    const out = [];
    const walk = (x) => { out.push(x); for (const c of this.children(x)) walk(c.id); };
    if (this.has(id)) walk(id);
    return out;
  }

  /** Every block in outline order. */
  list(parent = this.pageId) {
    const out = [];
    const walk = (p) => { for (const c of this.children(p)) { out.push(c); walk(c.id); } };
    walk(parent);
    return out;
  }

  /** Blocks that can be seen: children of collapsed blocks left out. */
  visible(parent = this.pageId) {
    const out = [];
    const walk = (p) => { for (const c of this.children(p)) { out.push(c); if (!c.collapsed) walk(c.id); } };
    walk(parent);
    return out;
  }

  /** A sort key for a block under parent right after `after` (null: first), leaving `self` out. */
  keyFor(parent, after, self = null) {
    const sibs = this.children(parent).filter((s) => s.id !== self);
    let i = -1;
    if (after) { i = sibs.findIndex((s) => s.id === after); if (i === -1) i = sibs.length - 1; }
    const lo = i >= 0 ? sibs[i].sort : null;
    const hi = i + 1 < sibs.length ? sibs[i + 1].sort : null;
    return lo !== null && hi !== null && lo >= hi ? keyBetween(lo, null) : keyBetween(lo, hi);
  }

  /**
   * Applies an operation (the protocol's shape). Returns what changed, for the
   * view, or null when the operation does not apply.
   */
  apply(op) {
    switch (op.op) {
      case 'create': {
        if (this.has(op.id)) return null;
        const parent = op.parent === this.pageId || this.has(op.parent) ? op.parent : this.pageId;
        this.#insert({ id: op.id, parent, sort: op.sort, text: op.text ?? '', collapsed: !!op.collapsed });
        return { kind: 'create', id: op.id };
      }
      case 'move': {
        const n = this.get(op.id);
        if (!n || (op.parent !== this.pageId && (!this.has(op.parent) || this.isInSubtree(n.id, op.parent)))) return null;
        const oldParent = n.parent;
        this.#reparent(n, op.parent);
        n.sort = op.sort;
        return { kind: 'move', id: n.id, oldParent };
      }
      case 'edit': {
        const n = this.get(op.id);
        if (!n) return null;
        n.text = op.text;
        return { kind: 'edit', id: n.id };
      }
      case 'collapse': {
        const n = this.get(op.id);
        if (!n) return null;
        n.collapsed = !!op.collapsed;
        return { kind: 'collapse', id: n.id };
      }
      case 'delete': {
        const n = this.get(op.id);
        if (!n) return null;
        const removed = this.subtreeIds(n.id);
        for (const id of removed) {
          this.byParent.get(this.nodes.get(id).parent)?.delete(id);
          this.byParent.delete(id);
          this.nodes.delete(id);
        }
        return { kind: 'delete', id: n.id, oldParent: n.parent, removed };
      }
      default:
        return null;
    }
  }

  /**
   * Takes a node from the server (the change feed). Parts the client still has
   * unsent changes for are kept. Returns what changed.
   */
  merge(node, { keepStructure = false, keepText = false, keepCollapsed = false } = {}) {
    const n = this.get(node.id);
    if (!n) {
      if (node.kind !== 'block' || node.page !== this.pageId) return null;
      this.#insert({ id: node.id, parent: this.has(node.parent) ? node.parent : this.pageId, sort: node.sort, text: node.text, collapsed: !!node.collapsed });
      return { kind: 'create', id: node.id };
    }
    if (node.page !== this.pageId) return this.apply({ op: 'delete', id: n.id }); // moved to another page
    const change = { kind: 'merge', id: n.id, oldParent: n.parent, structural: false, text: false };
    if (!keepStructure && (n.parent !== node.parent || n.sort !== node.sort) && (node.parent === this.pageId || this.has(node.parent))
        && !this.isInSubtree(n.id, node.parent)) {
      this.#reparent(n, node.parent);
      n.sort = node.sort;
      change.structural = true;
    }
    if (!keepText && n.text !== node.text) { n.text = node.text; change.text = true; }
    if (!keepCollapsed) n.collapsed = !!node.collapsed;
    return change;
  }
}
