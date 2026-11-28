// Draws the outline from the model, and redraws only what a change touched.
//
// Every block is a row of three boxes: the fold toggle, the bullet, and the
// content. The toggle and bullet boxes are exactly one first line tall (taller
// for a heading), so the bullet sits on the middle of the first line whatever
// the text is. Children are indented by one --indent, and the guide line runs
// down the middle of the parent's bullet.
//
// A block with {view:: …} shows its children through that view (a query of
// them, drawn by the view option) instead of as the tree; zoomed into, it shows
// the tree again, to edit them. The tree is one way to show notes, not the only one.

import { viewOf } from '../lib/model.js';

export const HEADING = /^(#{1,3}) /;

export class OutlineView {
  constructor(root, outline, { renderText = defaultText, link = (id) => '#', zoom = null, view = null } = {}) {
    this.view = view; // (element, node, view) draws a block's children through its view; returns a function that runs it again
    this.again = new Map(); // id -> timer: a view runs again once for a batch of changes
    this.root = root;
    this.zoom = zoom; // when set, only this block and what is under it are shown
    this.outline = outline;
    this.renderText = renderText;
    this.link = link;
    this.els = new Map(); // id -> block element
  }

  get model() { return this.outline.model; }

  /** Draws everything from scratch. */
  render() {
    this.els.clear();
    const list = document.createElement('div');
    list.className = 'children outline';
    list.setAttribute('role', 'tree');
    const top = this.zoom ? [this.model.get(this.zoom)].filter(Boolean) : this.model.children(this.model.pageId);
    for (const n of top) list.append(this.#block(n));
    this.root.replaceChildren(list);
    if (this.zoom) this.els.get(this.zoom)?.classList.add('zoomed');
  }

  /** The blocks that can be seen, in order (within the zoomed block when zoomed). */
  visible() {
    if (!this.zoom) return this.model.visible();
    const root = this.model.get(this.zoom);
    return root ? [root, ...this.model.visible(root.id)] : [];
  }

  /** Redraws what a change from the outline touched. */
  update(change) {
    const m = this.model;
    switch (change.kind) {
      case 'create':
        this.#place(change.id);
        break;
      case 'edit':
        this.#refreshContent(change.id);
        break;
      case 'collapse':
        this.#refreshFold(change.id);
        break;
      case 'move':
      case 'merge':
        if (change.kind === 'move' || change.structural) {
          this.#place(change.id);
          this.#refreshFold(change.oldParent);
        }
        if (change.kind === 'merge' && change.text) this.#refreshContent(change.id);
        break;
      case 'delete':
        this.els.get(change.id)?.remove();
        for (const id of change.removed) this.els.delete(id);
        this.#refreshFold(change.oldParent);
        break;
    }
    if (m.has(change.id)) this.#refreshFold(m.get(change.id).parent);
    const parent = m.get(change.id)?.parent ?? change.oldParent;
    if (change.kind === 'edit' || change.kind === 'delete' || change.kind === 'merge') this.#refreshView(parent);
  }

  element(id) { return this.els.get(id) ?? null; }
  contentOf(id) { return this.els.get(id)?.querySelector(':scope > .row > .content') ?? null; }

  #block(n) {
    const el = document.createElement('div');
    el.className = 'block';
    el.dataset.id = n.id;
    el.setAttribute('role', 'treeitem');
    const row = document.createElement('div');
    row.className = 'row';
    const toggle = document.createElement('button');
    toggle.className = 'toggle';
    toggle.tabIndex = -1;
    toggle.dataset.action = 'toggle';
    const bullet = document.createElement('a');
    bullet.className = 'bullet';
    bullet.tabIndex = -1;
    bullet.href = this.link(n.id);
    bullet.setAttribute('aria-label', 'Open this block');
    bullet.draggable = false; // dragging is ours (ui/drag.js), not the browser's link drag
    const content = document.createElement('div');
    content.className = 'content';
    row.append(toggle, bullet, content);
    const kids = document.createElement('div');
    kids.className = 'children';
    kids.setAttribute('role', 'group');
    el.append(row, kids);
    this.els.set(n.id, el);
    this.#fillContent(el, n);
    this.#fillChildren(el, n);
    this.#fold(el, n);
    return el;
  }

  /** The view a block's children are shown through here, or null for the tree. */
  #viewFor(n) {
    return this.view && n.id !== this.zoom ? viewOf(n.text) : null;
  }

  /** The children as blocks, or through the block's view. */
  #fillChildren(el, n) {
    const kids = el.querySelector(':scope > .children');
    const view = this.#viewFor(n);
    for (const id of this.model.subtreeIds(n.id).slice(1)) this.els.delete(id);
    kids.classList.toggle('child-view', view !== null);
    if (view === null) {
      delete el.dataset.view;
      kids.replaceChildren(...this.model.children(n.id).map((c) => this.#block(c)));
      return;
    }
    el.dataset.view = view;
    kids.replaceChildren();
    el.runView = this.view(kids, n, view);
  }

  /** Runs a block's view again, in place, after its children changed (once for a batch of changes). */
  #refreshView(id) {
    const el = this.els.get(id);
    if (el?.dataset.view === undefined || this.again.has(id)) return;
    this.again.set(id, setTimeout(() => {
      this.again.delete(id);
      if (el.isConnected) el.runView?.();
    }, 100));
  }

  #fillContent(el, n) {
    const row = el.firstElementChild;
    const h = n.text.match(HEADING);
    row.className = 'row' + (h ? ' h' + h[1].length : '');
    this.renderText(row.querySelector('.content'), n.text, n);
  }

  #fold(el, n) {
    const has = this.model.hasChildren(n.id);
    el.classList.toggle('has-children', has);
    el.classList.toggle('collapsed', has && n.collapsed);
    el.setAttribute('aria-expanded', has ? String(!n.collapsed) : 'false');
    const toggle = el.querySelector(':scope > .row > .toggle');
    toggle.setAttribute('aria-label', n.collapsed ? 'Show children' : 'Hide children');
    toggle.hidden = !has;
  }

  #refreshContent(id) {
    const el = this.els.get(id);
    const n = this.model.get(id);
    if (!el || !n || el.classList.contains('editing')) return;
    this.#fillContent(el, n);
    if ((el.dataset.view ?? null) !== this.#viewFor(n)) this.#fillChildren(el, n);   // a view put in or taken out
  }

  #refreshFold(id) {
    const el = this.els.get(id);
    const n = this.model.get(id);
    if (el && n) this.#fold(el, n);
  }

  /** Puts a block (new or moved) where the model says it belongs. */
  #place(id) {
    const n = this.model.get(id);
    if (!n) return;
    const el = this.els.get(id) ?? this.#block(n);
    if (this.zoom && !this.model.isInSubtree(this.zoom, id)) { el.remove(); return; } // moved out of view
    if (this.els.get(n.parent)?.dataset.view !== undefined) { el.remove(); this.#refreshView(n.parent); return; }
    const container = id === this.zoom ? this.root.querySelector(':scope > .outline')
      : n.parent === this.model.pageId ? (this.zoom ? null : this.root.querySelector(':scope > .outline'))
      : this.els.get(n.parent)?.querySelector(':scope > .children');
    if (!container) return;
    const next = this.model.nextSibling(id);
    container.insertBefore(el, next ? this.els.get(next.id) ?? null : null);
  }
}

/** Plain text, line breaks kept, a heading's hashes left out. */
export function defaultText(el, text) {
  el.textContent = text.replace(HEADING, '');
}
