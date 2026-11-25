// The page on screen: loads it, draws its outline, and keeps one outbox for the
// whole app so unsent changes survive moving between pages.

import { html, render } from 'lit-html';
import { api, ApiError } from '../lib/api.js';
import { Outbox } from '../lib/outbox.js';
import { Outline } from '../lib/outline.js';
import { newId } from '../lib/keys.js';
import { pageLink, isJournal } from '../lib/route.js';
import { OutlineView } from './outline-view.js';

export const outbox = new Outbox({
  send: (ops) => api.ops(ops),
  onStatus: (status, size) => document.dispatchEvent(new CustomEvent('notd:saving', {
    detail: { pending: size, offline: status === 'offline' || status === 'error' },
  })),
  onRejected: (e) => { console.error('The server refused changes:', e.message); current?.reload(); },
});
addEventListener('online', () => outbox.retryNow());

let current = null;

export class PageScreen {
  constructor(root) {
    this.root = root;
    this.outline = new Outline({ outbox, onChange: (c) => this.view?.update(c) });
    this.view = null;
    this.page = null;
    this.title = null;
    current = this;
  }

  /** Shows the page with this title. */
  async show(title) {
    this.title = title;
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
    this.view = new OutlineView(host, this.outline, { link: (id) => pageLink(page.title, id) });
    this.view.render();
    this.root.dispatchEvent(new CustomEvent('notd:page', { bubbles: true, detail: { page } }));
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
