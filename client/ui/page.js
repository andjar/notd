// The page on screen: loads it, draws its outline, and keeps one outbox for the
// whole app so unsent changes survive moving between pages.

import { html, render } from 'lit-html';
import { api, ApiError } from '../lib/api.js';
import { Outbox } from '../lib/outbox.js';
import { Outline } from '../lib/outline.js';
import { newId } from '../lib/keys.js';
import { pageLink, isJournal } from '../lib/route.js';
import { OutlineView } from './outline-view.js';
import { Editor } from './editor.js';
import { renderText, forgetNodes } from './text.js';
import { Autocomplete } from './autocomplete.js';
import { Drag } from './drag.js';

export const outbox = new Outbox({
  send: (ops) => api.ops(ops),
  onStatus: (status, size) => document.dispatchEvent(new CustomEvent('notd:saving', {
    detail: { pending: size, offline: status === 'offline' || status === 'error' },
  })),
  onRejected: (e) => { console.error('The server refused changes:', e.message); for (const s of live) s.reload(); },
});
addEventListener('online', () => outbox.retryNow());

const live = new Set();

/** One page: its outline, editor and link suggestions. announce: tell the app which page is shown. */
export class PageScreen {
  constructor(root, { announce = true } = {}) {
    this.root = root;
    this.announce = announce;
    this.outline = new Outline({ outbox, onChange: (c) => this.view?.update(c) });
    this.view = null;
    this.page = null;
    this.title = null;
    live.add(this);
  }

  /** Stops using this screen (another page or the journal took its place). */
  dispose() {
    if (this.editor?.editing) this.editor.finish();
    this.autocomplete?.close();
    this.autocomplete?.box.remove();
    live.delete(this);
    this.title = null;
  }

  /** Shows the page with this title; with a block id, zoomed into that block. */
  async show(title, block = null) {
    this.title = title;
    this.zoom = block;
    let data;
    try {
      data = await api.page(title);
    } catch (e) {
      if (this.title !== title) return;
      if (e instanceof ApiError && e.code === 'not_found') return this.#missing(title);
      render(html`<p class="missing">The page could not be loaded: ${e.message}</p>`, this.root);
      return;
    }
    if (this.title !== title) return; // another page was asked for meanwhile
    this.#draw(data);
  }

  reload() { if (this.title) this.show(this.title); }

  #draw({ page, blocks }) {
    this.page = page;
    this.outline.load(page, blocks);
    this.root.replaceChildren();
    const host = document.createElement('div');
    this.root.append(host);
    if (this.announce) forgetNodes();
    const zoom = this.zoom && this.outline.model.has(this.zoom) ? this.zoom : null;
    if (zoom) this.root.prepend(this.#crumbs(page, zoom));
    else if (this.zoom) this.root.prepend(Object.assign(document.createElement('p'), { className: 'missing', textContent: 'That block is not on this page any more.' }));
    this.view = new OutlineView(host, this.outline, { link: (id) => pageLink(page.title, id), renderText, zoom });
    this.view.render();
    this.editor = new Editor(this.view, this.outline);
    this.autocomplete?.close();
    this.autocomplete?.box.remove();
    this.autocomplete = new Autocomplete(this.editor);
    new Drag(this.view, this.outline);
    const add = document.createElement('button');
    add.className = 'add-block';
    add.textContent = blocks.length ? '' : 'Click here to write';
    add.hidden = !!zoom;
    add.setAttribute('aria-label', 'Add a block');
    add.addEventListener('click', () => this.#addAtEnd());
    this.root.append(add);
    if (this.announce) this.root.dispatchEvent(new CustomEvent('notd:page', { bubbles: true, detail: { page } }));
  }

  /** Page › ancestor › ... links above a zoomed block. */
  #crumbs(page, id) {
    const nav = document.createElement('nav');
    nav.className = 'crumbs';
    nav.setAttribute('aria-label', 'Where this block is');
    const link = (text, href) => Object.assign(document.createElement('a'), { textContent: text, href });
    const parts = [link(page.title, pageLink(page.title))];
    const chain = [];
    for (let n = this.outline.model.get(this.outline.model.get(id).parent); n; n = this.outline.model.get(n.parent)) chain.unshift(n);
    for (const n of chain) parts.push(link(shortText(n.text), pageLink(page.title, n.id)));
    for (const [i, a] of parts.entries()) { if (i) nav.append(' › '); nav.append(a); }
    return nav;
  }

  /** A new block at the end of the page, unless the last one is still empty. */
  #addAtEnd() {
    const top = this.outline.model.children(this.page.id);
    const last = top.at(-1);
    const id = last && last.text === '' && !this.outline.model.hasChildren(last.id) ? last.id
      : this.outline.create({ parent: this.page.id, after: last?.id ?? null });
    this.root.querySelector('.add-block').textContent = '';
    this.editor.edit(id, 'end');
  }

  #missing(title) {
    this.page = null;
    this.view = null;
    render(html`<div class="missing"><p>There is no page called “${title}” yet.</p>
      <button @click=${() => this.#create(title)}>Create it</button></div>`, this.root);
  }

  async #create(title) {
    await api.ops([{ op: 'create', kind: 'page', id: newId(), title }]);
    this.show(title);
  }
}

export { isJournal };

function shortText(text) {
  const line = text.replace(/^#{1,3} /, '').split('\n')[0];
  return line.length > 40 ? line.slice(0, 39) + '…' : line || '(empty)';
}
