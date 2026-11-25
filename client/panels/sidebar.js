// The core sidebar panels: favourites, recent pages, the calendar, and the save
// status. Favourites live in the notebook, as links on the page
// "notd/favourites", so every device sees the same; recent pages are per device.

import { html, render } from 'lit-html';
import { api } from '../lib/api.js';
import { newId } from '../lib/keys.js';
import { pageLink, today } from '../lib/route.js';

export const FAVOURITES = 'notd/favourites';

const store = {
  get(key, fallback) { try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; } },
  set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* not kept; fine */ } },
};

/** Titles linked from notd/favourites, in order, with the block that holds each. */
export async function favourites() {
  const { nodes } = await api.query(`page:"${FAVOURITES}" sort:created limit:1000`);
  return { items: nodes.filter((b) => b.parent === b.page).sort((a, b) => (a.sort < b.sort ? -1 : 1))
    .flatMap((b) => [...b.text.matchAll(/\[\[([^\]]+)\]\]/g)].map((m) => ({ title: m[1], block: b.id }))) };
}

/** Adds or removes a page from the favourites. */
export async function toggleFavourite(title) {
  const { items } = await favourites();
  const found = items.find((i) => i.title.toLowerCase() === title.toLowerCase());
  if (found) return api.ops([{ op: 'delete', id: found.block }]);
  let pageId = (await api.pages()).find((p) => p.title.toLowerCase() === FAVOURITES)?.id;
  if (!pageId) {
    pageId = newId();
    await api.ops([{ op: 'create', kind: 'page', id: pageId, title: FAVOURITES }]);
  }
  return api.ops([{ op: 'create', id: newId(), parent: pageId, text: `[[${title}]]` }]);
}

export function favouritesPanel() {
  return {
    id: 'favourites', slot: 'app.left', order: 10,
    async render(el) {
      const { items } = await favourites().catch(() => ({ items: [] }));
      render(html`<h2>Favourites</h2>${items.length ? html`<ul class="links">${items.map((i) => html`<li><a href=${pageLink(i.title)}>${i.title}</a></li>`)}</ul>`
        : html`<p class="hint">Star a page to keep it here.</p>`}`, el);
    },
  };
}

export function recentPanel() {
  return {
    id: 'recent', slot: 'app.left', order: 20,
    render(el, ctx) {
      let recent = store.get('notd.recent', []);
      const title = ctx.route?.title;
      if (title) {
        recent = [title, ...recent.filter((t) => t.toLowerCase() !== title.toLowerCase())].slice(0, 12);
        store.set('notd.recent', recent);
      }
      render(html`<h2>Recent</h2><ul class="links">${recent.map((t) => html`<li><a href=${pageLink(t)}>${t}</a></li>`)}</ul>`, el);
    },
  };
}

export function calendarPanel() {
  let shown = null; // first day of the month on display
  let dots = new Set();
  let host = null;
  let selected = '';

  async function load() {
    const month = `${shown.getFullYear()}-${String(shown.getMonth() + 1).padStart(2, '0')}`;
    try { dots = new Set(await api.journalDays(month)); } catch { dots = new Set(); }
    draw();
  }

  function move(delta) {
    shown = new Date(shown.getFullYear(), shown.getMonth() + delta, 1);
    load();
  }

  function draw() {
    const first = (shown.getDay() + 6) % 7; // Monday first
    const days = new Date(shown.getFullYear(), shown.getMonth() + 1, 0).getDate();
    const cells = [...Array(first).fill(null), ...Array.from({ length: days }, (_, i) => today(new Date(shown.getFullYear(), shown.getMonth(), i + 1)))];
    const now = today();
    render(html`
      <div class="calendar-head">
        <button class="icon" @click=${() => move(-1)} aria-label="Previous month">‹</button>
        <h2>${shown.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</h2>
        <button class="icon" @click=${() => move(1)} aria-label="Next month">›</button>
      </div>
      <div class="calendar" role="grid">
        ${['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d) => html`<span class="dow">${d}</span>`)}
        ${cells.map((d) => d === null ? html`<span></span>` : html`<a href=${pageLink(d)} data-day=${d}
            class=${[d === now ? 'today' : '', d === selected ? 'selected' : '', dots.has(d) ? 'dot' : ''].join(' ')}>${Number(d.slice(8))}</a>`)}
      </div>`, host);
  }

  return {
    id: 'calendar', slot: 'app.left', order: 30,
    render(el, ctx) {
      host = el;
      const t = ctx.route?.title ?? '';
      selected = /^\d{4}-\d{2}-\d{2}$/.test(t) ? t : '';
      const base = selected ? new Date(selected + 'T00:00') : new Date();
      const month = new Date(base.getFullYear(), base.getMonth(), 1);
      if (!shown || shown.getTime() !== month.getTime()) { shown = month; load(); } else { load(); }
    },
  };
}

/** The save status in the status line; the outbox reports through 'notd:saving' events. */
export function statusPanel() {
  let state = { pending: 0, offline: false };
  let host = null;
  document.addEventListener('notd:saving', (e) => { state = e.detail; if (host) draw(); });
  function draw() {
    const text = state.offline ? `Offline · ${state.pending} change${state.pending === 1 ? '' : 's'} waiting`
      : state.pending ? 'Saving…' : 'Saved';
    render(html`<span class=${state.offline ? 'warn' : ''} role="status">${text}</span>`, host);
  }
  return { id: 'status', slot: 'app.status', order: 10, render(el) { host = el; draw(); } };
}

/** The page title, with a star that adds it to or removes it from the favourites. */
export function headerPanel() {
  let fav = false;
  return {
    id: 'title', slot: 'page.header', order: 10,
    async render(el, ctx) {
      const title = ctx.route?.title ?? '';
      const draw = () => render(html`<div class="page-title">
          <h1>${title}</h1>
          <button class="icon star ${fav ? 'on' : ''}" aria-pressed=${fav} title=${fav ? 'Remove from favourites' : 'Add to favourites'}
            @click=${async () => { await toggleFavourite(title); fav = !fav; draw(); document.dispatchEvent(new CustomEvent('notd:favourites')); }}>${fav ? '★' : '☆'}</button>
        </div>`, el);
      draw();
      const { items } = await favourites().catch(() => ({ items: [] }));
      fav = items.some((i) => i.title.toLowerCase() === title.toLowerCase());
      draw();
    },
  };
}
