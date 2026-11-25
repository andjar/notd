// Search in the top bar: results as you type, Enter opens the first, and a page
// that does not exist yet can be created from here.

import { html, render } from '../lib/html.js';
import { api } from '../lib/api.js';
import { newId } from '../lib/keys.js';
import { unsafeHTML } from '../lib/html.js';

export function searchPanel({ go }) {
  let results = [];
  let q = '';
  let active = 0;
  let timer = 0;
  let host = null;

  function draw() {
    const exact = results.some((r) => (r.node.title ?? '').toLowerCase() === q.trim().toLowerCase());
    const items = [...results.map((r) => ({ title: r.node.title ?? r.pageTitle, snippet: r.snippet, block: r.node.kind === 'block' ? r.node.id : null })),
      ...(q.trim() && !exact ? [{ title: q.trim(), create: true }] : [])];
    active = Math.min(active, Math.max(0, items.length - 1));
    render(html`
      <div class="search">
        <input type="search" placeholder="Search" aria-label="Search" .value=${q} autocomplete="off"
          @input=${(e) => { q = e.target.value; clearTimeout(timer); timer = setTimeout(find, 150); }}
          @keydown=${(e) => key(e, items)} @blur=${() => setTimeout(close, 150)}>
        ${q.trim() ? html`<ul class="search-results" role="listbox">
          ${items.map((it, i) => html`<li role="option" aria-selected=${i === active} class=${i === active ? 'active' : ''}
              @mousedown=${(e) => { e.preventDefault(); open(it); }}>
            ${it.create ? html`<span class="create">Create page <b>${it.title}</b></span>`
              : html`<b class="result-title">${it.title}</b>${it.block ? html`<span class="snippet">${unsafeHTML(it.snippet)}</span>` : ''}`}
          </li>`)}
        </ul>` : ''}
      </div>`, host);
  }

  async function find() {
    const asked = q;
    try {
      const r = q.trim() ? await api.search(q, 20) : [];
      if (asked === q) { results = r; active = 0; draw(); }
    } catch (e) { console.warn('search:', e.message); }
  }

  function key(e, items) {
    if (e.key === 'ArrowDown') { active = Math.min(active + 1, items.length - 1); draw(); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { active = Math.max(active - 1, 0); draw(); e.preventDefault(); }
    else if (e.key === 'Enter' && items[active]) { open(items[active]); e.preventDefault(); }
    else if (e.key === 'Escape') { close(); e.target.blur(); }
  }

  async function open(item) {
    close();
    if (item.create) {
      try { await api.ops([{ op: 'create', kind: 'page', id: newId(), title: item.title }]); } catch (e) {
        if (e.code !== 'title_taken') { console.warn('create page:', e.message); return; }
      }
    }
    go(item.title, item.block);
  }

  function close() {
    q = ''; results = []; draw();
  }

  return {
    id: 'search', slot: 'app.top', order: 10,
    render(el) {
      if (host !== el) { host = el; draw(); }
    },
  };
}
