// The core sidebar panels: favourites, recent pages, the calendar, and the save
// status. Favourites live in the notebook, as links on the page
// "notd/favourites", so every device sees the same; recent pages are per device.

import { html, render, nothing } from '../lib/html.js';
import { api } from '../lib/api.js';
import { cached, forgetCached } from '../lib/cache.js';
import { newId } from '../lib/keys.js';
import { pageLink, today, dayLabel } from '../lib/route.js';
import { rewriteLinks } from '../lib/links.js';

export const FAVOURITES = 'notd/favourites';

const store = {
  get(key, fallback) { try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; } },
  set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* not kept; fine */ } },
};

/** Titles linked from notd/favourites, in order, with the block that holds each (read once until the notebook changes). */
export function favourites() {
  return cached('favourites', async () => {
    const { nodes } = await api.query(`page:"${FAVOURITES}" sort:created limit:1000`);
    return { items: nodes.filter((b) => b.parent === b.page).sort((a, b) => (a.sort < b.sort ? -1 : 1))
      .flatMap((b) => [...b.text.matchAll(/\[\[([^\]]+)\]\]/g)].map((m) => ({ title: m[1], block: b.id }))) };
  });
}

/** Adds or removes a page from the favourites. */
export async function toggleFavourite(title) {
  const { items } = await favourites();
  const found = items.find((i) => i.title.toLowerCase() === title.toLowerCase());
  try {
    if (found) return await api.ops([{ op: 'delete', id: found.block }]);
    let pageId = (await api.pages()).find((p) => p.title.toLowerCase() === FAVOURITES)?.id;
    if (!pageId) {
      pageId = newId();
      await api.ops([{ op: 'create', kind: 'page', id: pageId, title: FAVOURITES }]);
    }
    return await api.ops([{ op: 'create', id: newId(), parent: pageId, text: `[[${title}]]` }]);
  } finally {
    forgetCached();                         // the sidebar shows the change now, not at the next feed
  }
}

/** A list of pages to go to, the one on screen marked; days are shown as dates. */
const pageList = (titles, current) => html`<ul class="links">${titles.map((t) => html`<li><a href=${pageLink(t)} title=${t}
  aria-current=${t.toLowerCase() === current?.toLowerCase() ? 'page' : 'false'}>${dayLabel(t, { short: true })}</a></li>`)}</ul>`;

export function favouritesPanel() {
  return {
    id: 'favourites', slot: 'app.left', order: 10,
    async render(el, ctx) {
      const { items } = await favourites().catch(() => ({ items: [] }));
      render(html`<h2>Favourites</h2>${items.length ? pageList(items.map((i) => i.title), ctx.route?.title)
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
      render(html`<h2>Recent</h2>${pageList(recent, title)}`, el);
    },
  };
}

export function calendarPanel() {
  let shown = null; // first day of the month on display
  let dots = new Set();
  let host = null;
  let selected = '';
  let routeTitle = null;
  const monthOf = (d) => today(d).slice(0, 7);

  async function load() {
    const month = monthOf(shown);
    let days = [];
    try { days = await cached(`journal-days:${month}`, () => api.journalDays(month)); } catch { /* no dots, then */ }
    if (month !== monthOf(shown)) return;   // moved to another month meanwhile
    dots = new Set(days);
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
        <h2>${shown.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</h2>
        <button class="icon" @click=${() => move(-1)} aria-label="Previous month" title="Previous month">
          <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12 5-5 5 5 5"/></svg></button>
        <button class="icon" @click=${() => move(1)} aria-label="Next month" title="Next month">
          <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m8 5 5 5-5 5"/></svg></button>
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
      if (t !== routeTitle) {                 // a new page: show its month; otherwise keep the month browsed to
        routeTitle = t;
        selected = /^\d{4}-\d{2}-\d{2}$/.test(t) ? t : '';
        const base = selected ? new Date(selected + 'T00:00') : new Date();
        shown = new Date(base.getFullYear(), base.getMonth(), 1);
      }
      load();
    },
  };
}

/** The save status in the status line; the outbox reports through 'notd:saving' events. */
export function statusPanel() {
  let state = { pending: 0, offline: false };
  let host = null;
  let message = null;                       // a notice (lib/notice.js) shown instead, for a while
  let timer = 0;
  document.addEventListener('notd:saving', (e) => { state = e.detail; if (host) draw(); });
  document.addEventListener('notd:notice', (e) => {
    message = e.detail;
    clearTimeout(timer);
    if (!message.sticky) timer = setTimeout(() => { message = null; if (host) draw(); }, message.error ? 8000 : 4000);
    if (host) draw();
  });
  function draw() {
    if (message) { render(html`<span class=${message.error ? 'warn' : ''} title=${message.text} role=${message.error ? 'alert' : 'status'}>${message.text}</span>`, host); return; }
    const text = state.offline ? `Offline · ${state.pending} change${state.pending === 1 ? '' : 's'} waiting`
      : state.pending ? 'Saving…' : 'Saved';
    render(html`<span class=${state.offline ? 'warn' : ''} role="status">${text}</span>`, host);
  }
  return { id: 'status', slot: 'app.status', order: 10, render(el) { host = el; draw(); } };
}

/** The page title, with a star for the favourites. Clicking the title renames the page. */
export function headerPanel() {
  let fav = false;
  let error = '';
  return {
    id: 'title', slot: 'page.header', order: 10,
    async render(el, ctx) {
      const title = ctx.page?.title ?? ctx.route?.title ?? '';
      const page = ctx.page;
      const draw = () => el.querySelector('h1[contenteditable]') ? null : render(html`<div class="page-title">
          <h1 data-title=${title} title=${page ? 'Click to rename' : ''} @click=${(e) => page && startRename(e.target)}
            @keydown=${keys} @blur=${(e) => finishRename(e.target)}>${dayLabel(title)}</h1>
          <button class="icon star ${fav ? 'on' : ''}" aria-pressed=${fav} title=${fav ? 'Remove from favourites' : 'Add to favourites'}
            @click=${async () => { await toggleFavourite(title); fav = !fav; draw(); document.dispatchEvent(new CustomEvent('notd:favourites')); }}><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m10 2.9 2.1 4.4 4.8.6-3.5 3.3.9 4.8L10 13.7 5.7 16l.9-4.8-3.5-3.3 4.8-.6Z"/></svg></button>
        </div>${error ? html`<p class="error" role="alert">${error}</p>` : ''}`, el);
      const startRename = (h) => {
        if (h.isContentEditable) return;
        error = '';
        h.textContent = title;                // a day is shown as a date, but renamed by its title
        h.contentEditable = 'plaintext-only';
        h.focus();
        getSelection().selectAllChildren(h);
      };
      const keys = (e) => {
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        if (e.key === 'Escape') { e.target.textContent = title; e.target.blur(); }
      };
      const finishRename = async (h) => {
        if (!h.isContentEditable) return;
        h.removeAttribute('contenteditable');
        const next = h.textContent.trim().replace(/\s+/g, ' ');
        render(nothing, el);                  // typing replaced lit-html's text in the title: draw it afresh
        draw();
        if (!next || next === title) return;
        try {
          await renamePage(page, next);
          document.dispatchEvent(new CustomEvent('notd:renamed', { detail: { from: title, to: next } }));
        } catch (e) {
          error = e.code === 'title_taken' ? `There is already a page called “${next}”.` : e.message;
          draw();
        }
      };
      draw();
      const { items } = await favourites().catch(() => ({ items: [] }));
      fav = items.some((i) => i.title.toLowerCase() === title.toLowerCase());
      draw();
    },
  };
}

/** Renames a page and rewrites every link to it, in one batch: all or nothing. */
export async function renamePage(page, title) {
  const groups = await api.backlinks(page.id);
  const ops = [{ op: 'edit', id: page.id, title }];
  for (const g of groups) {
    for (const b of g.blocks) {
      const text = rewriteLinks(b.text, page.title, title);
      if (text !== b.text) ops.push({ op: 'edit', id: b.id, text });
    }
  }
  return api.ops(ops);
}
