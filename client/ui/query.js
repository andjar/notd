// {{query ...}} in a block: the notes the query finds, drawn in place and kept
// up to date. The language is the server's (docs/protocol/queries.md); the
// options view:table and columns:a,b give a table instead of a list, and
// group:field groups the results under headings, and group:field:day (or
// :hour, :month, :year) by the day or hour of a date and time: by hour, under a
// heading per day. The block holding the query is never in its own results.
// A block's {view:: …} is drawn here too (asView): its children, without the
// query line or the page each note is on. Tasks in the results can be ticked: the change
// goes to the server (lib/remote.js) and every query on screen runs again.

import { api } from '../lib/api.js';
import { remote } from '../lib/remote.js';
import { pageLink, dayLabel } from '../lib/route.js';

const live = new Set();          // query widgets on screen, run again when notes change
let again = 0;

function refreshAll() {
  clearTimeout(again);
  again = setTimeout(() => {
    for (const w of live) {
      if (!w.el.isConnected) live.delete(w);
      else w.run();
    }
  }, 250);
}
document.addEventListener('notd:changes', refreshAll);
document.addEventListener('notd:changed-elsewhere', refreshAll);

const el = (tag, props = {}, ...children) => {
  const e = Object.assign(document.createElement(tag), props);
  e.append(...children.filter((c) => c !== null && c !== undefined));
  return e;
};

/** Fills a widget element with the results of query q. ctx: { block, depth, renderText, asView }. */
export function mountQuery(host, [, q], { block, depth = 0, renderText, asView = false }) {
  host.classList.add('query');
  if (depth > 1) { host.textContent = '(a query inside a query is not run)'; return; }
  if (!q) { host.append(el('span', { className: 'query-note', textContent: 'Write a query after {{query, such as tag:project or task:open.' })); return; }
  const w = { el: host, run: () => run(host, q, block, depth, renderText, asView) };
  live.add(w);
  w.run();
}

async function run(host, q, block, depth, renderText, asView) {
  let r;
  try {
    r = await api.query(q);
  } catch (e) {
    host.replaceChildren(el('span', { className: 'query-note error', textContent: `${q}: ${e.message}` }));
    return;
  }
  const groups = r.groups ?? [{ value: undefined, nodes: r.nodes }];
  for (const g of groups) g.nodes = g.nodes.filter((n) => n.id !== block?.id);
  const all = groups.flatMap((g) => g.nodes);
  remote.remember(all);
  const options = r.options ?? {};
  const head = asView ? null : el('div', { className: 'query-head widget-edit', title: 'Click to change the query' },
    el('code', { textContent: q }), ` · ${all.length === 1 ? '1 note' : `${all.length} notes`}`);
  const [, field, by] = /(?:^|\s)group:([^\s:]+)(?::(day|hour|month|year))?(?=\s|$)/i.exec(q)?.map((x) => x?.toLowerCase()) ?? [];
  const strip = asView && field ? new RegExp(` ?\\{${field.replace(/[^a-z0-9_-]/g, '\\$&')}::[^}\\n]*\\}`, 'gi') : null;   // the heading says it already
  let day = null;
  const body = groups.flatMap((g) => {
    const list = options.view === 'table' ? table(g.nodes, options.columns, renderText, depth) : items(g.nodes, renderText, depth, strip);
    if (g.value === undefined) return [list];
    if (by !== 'hour' || g.value === null) return [el('section', {}, el('h4', { textContent: heading(g.value, by) }), list)];
    const out = g.value.slice(0, 10) === day ? [] : [el('h4', { className: 'query-day', textContent: dayLabel(g.value.slice(0, 10)) })];
    day = g.value.slice(0, 10);
    return [...out, el('section', {}, el('h5', { textContent: `${g.value.slice(11, 13)}:00` }), list)];
  });
  const empty = el('p', { className: 'query-note', textContent: asView ? 'Nothing here yet.' : 'Nothing matches.' });
  const more = all.length >= Number(/(?:^|\s)limit:(\d+)/.exec(q)?.[1] ?? 100)
    ? el('p', { className: 'query-note', textContent: `The first ${all.length}${asView ? '; open the block to see them all' : ''}.` }) : null;
  host.classList.toggle('as-view', asView);
  host.replaceChildren(...[head, ...(all.length ? body : [empty]), more].filter(Boolean));
}

/** A group's heading: a day as a date in the reader's language, a month as "November 2026". */
function heading(value, by) {
  if (value === null) return by ? '(no date)' : '(none)';
  if (by === 'day') return dayLabel(value);
  if (by === 'month' && /^\d{4}-\d{2}$/.test(value)) return new Date(`${value}-01T00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  return value;
}

const where = (n) => n.kind === 'page' ? pageLink(n.title) : pageLink(n.pageTitle ?? '', n.id);

function items(nodes, renderText, depth, strip = null) {
  return el('ul', { className: 'query-items' }, ...nodes.map((n) => {
    const li = el('li', { className: 'query-item' });
    li.dataset.node = n.id;
    if (n.kind === 'page') {
      li.append(el('a', { className: 'query-page', href: pageLink(n.title), textContent: dayLabel(n.title) }));
      return li;
    }
    const text = el('div', { className: 'query-text' });
    renderText(text, strip ? n.text.replace(strip, '') : n.text, n, depth + 1);
    li.append(text, el('a', { className: 'query-where', href: where(n), textContent: dayLabel(n.pageTitle ?? '') }));
    return li;
  }));
}

function table(nodes, columns, renderText, depth) {
  const keys = columns ? String(columns).split(',').map((c) => c.trim()).filter(Boolean)
    : [...new Set(nodes.flatMap((n) => Object.keys(n.fields ?? {})))].slice(0, 6);
  const head = el('tr', {}, el('th', { textContent: 'Note' }), ...keys.map((k) => el('th', { textContent: k })), el('th', { textContent: 'Page' }));
  const rows = nodes.map((n) => {
    const first = el('td');
    if (n.kind === 'page') first.append(el('a', { href: pageLink(n.title), textContent: dayLabel(n.title) }));
    else renderText(first, n.text.split('\n')[0].replace(/\{[A-Za-z0-9_.-]+::[^}\n]*\}/g, '').trim(), n, depth + 1);
    const tr = el('tr', {}, first, ...keys.map((k) => el('td', { textContent: (n.fields?.[k.toLowerCase()] ?? []).join(', ') })),
      el('td', {}, n.kind === 'page' ? null : el('a', { href: where(n), textContent: dayLabel(n.pageTitle ?? '') })));
    tr.dataset.node = n.id;
    return tr;
  });
  return el('div', { className: 'query-table' }, el('table', {}, el('thead', {}, head), el('tbody', {}, ...rows)));
}
