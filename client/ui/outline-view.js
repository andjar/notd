// Draws the outline from the model, and redraws only what a change touched.
//
// Every block is a row of three boxes: the fold toggle, the bullet, and the
// content. The toggle and bullet boxes are exactly one first line tall (taller
// for a heading), so the bullet sits on the middle of the first line whatever
// the text is. Children are indented by one --indent, and the guide line runs
// down the middle of the parent's bullet.

export const HEADING = /^(#{1,3}) /;

export class OutlineView {
  constructor(root, outline, { renderText = defaultText, link = (id) => '#' } = {}) {
    this.root = root;
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
    for (const n of this.model.children(this.model.pageId)) list.append(this.#block(n));
    this.root.replaceChildren(list);
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
    const content = document.createElement('div');
    content.className = 'content';
    row.append(toggle, bullet, content);
    const kids = document.createElement('div');
    kids.className = 'children';
    kids.setAttribute('role', 'group');
    el.append(row, kids);
    this.els.set(n.id, el);
    this.#fillContent(el, n);
    for (const c of this.model.children(n.id)) kids.append(this.#block(c));
    this.#fold(el, n);
    return el;
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
    if (el && n && !el.classList.contains('editing')) this.#fillContent(el, n);
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
    const container = n.parent === this.model.pageId ? this.root.querySelector(':scope > .outline')
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
