// The kanban plugin in the browser: {{kanban query}} in a block shows the notes
// the query finds as cards in columns, by task state or by a field. Dragging a
// card to another column (or Shift+Left/Right on a focused card) changes its
// keyword or field; the board runs again when notes change.

import { remote } from '../../client/lib/remote.js';
import { boardQuery, columns, moved, cardText } from './lib/board.js';

const el = (tag, props = {}, ...children) => {
  const e = Object.assign(document.createElement(tag), props);
  e.append(...children.filter((c) => c !== null && c !== undefined));
  return e;
};

export function activate(notd) {
  const live = new Set();
  let again = 0;
  const refreshAll = () => {
    clearTimeout(again);
    again = setTimeout(() => { for (const b of live) b.host.isConnected ? b.run() : live.delete(b); }, 250);
  };
  document.addEventListener('notd:changes', refreshAll);
  document.addEventListener('notd:changed-elsewhere', refreshAll);

  // Moves go through the command registry, so a card on the page on screen is
  // changed through its outline (one undo step) and any other through the server.
  notd.commands.register({
    id: 'move', title: 'Move the card to another column', when: (ctx) => ctx.to !== undefined,
    run: (ctx) => {
      const n = ctx.outline?.model.get(ctx.id);
      if (!n) return;
      const text = moved(n.text, ctx.field, ctx.to);
      if (text !== n.text) ctx.outline.edit(ctx.id, text, { undoable: true });
    },
  });

  notd.syntax.register({
    name: 'board', pattern: /\{\{kanban(?:\s+([^\n]*?))?\s*\}\}/,
    mount(host, match, { block, depth = 0, renderText }) {
      host.classList.add('kanban');
      if (depth > 1) { host.textContent = '(a board inside a query is not shown)'; return; }
      const b = { host, run: () => draw(notd, host, (match[1] ?? '').trim(), block, depth, renderText) };
      live.add(b);
      b.run();
    },
  });

  notd.commands.register({ id: 'new', title: 'Kanban board of tasks', slash: true, slashOnly: true, run: (ctx) => ctx.insert('{{kanban }}') });
  notd.commands.register({
    id: 'here', title: 'Kanban board of tasks on this page', slash: true, slashOnly: true,
    run: (ctx) => {
      const t = ctx.page?.title ?? '';
      ctx.insert(`{{kanban page:${/[\s"]/.test(t) ? `"${t.replace(/"/g, '')}"` : t}}}`);
    },
  });
}

async function draw(notd, host, q, block, depth, renderText) {
  const { field, sent } = boardQuery(q);
  let r;
  try {
    r = await notd.api.query(sent);
  } catch (e) {
    host.replaceChildren(el('span', { className: 'query-note error', textContent: `${q || 'kanban'}: ${e.message}` }));
    return;
  }
  const groups = (r.groups ?? [{ value: null, nodes: r.nodes ?? [] }]).map((g) => ({ ...g, nodes: g.nodes.filter((n) => n.id !== block?.id) }));
  const cols = columns(field, groups, r.options?.columns);
  const cards = cols.flatMap((c) => c.nodes);
  remote.remember(cards);
  const head = el('div', { className: 'query-head widget-edit', title: 'Click to change the board' },
    el('code', { textContent: q ? `kanban ${q}` : 'kanban' }), ` · ${cards.length === 1 ? '1 card' : `${cards.length} cards`}`);
  const board = el('div', { className: 'kanban-columns' });
  board.setAttribute('aria-description', 'Drag a card to another column, or focus it and press Shift with the left or right arrow.');
  for (const c of cols) {
    const list = el('ul', { className: 'kanban-cards' }, ...c.nodes.map((n) => card(n, field, renderText, depth)));
    const col = el('section', { className: 'kanban-column' },
      el('h4', {}, c.label, el('span', { className: 'kanban-count', textContent: String(c.nodes.length) })), list);
    col.dataset.value = JSON.stringify(c.value);
    board.append(col);
  }
  host.replaceChildren(head, board);
  if (!host.dataset.wired) { wire(notd, host); host.dataset.wired = '1'; }
  host.kanbanField = field;
}

function card(n, field, renderText, depth) {
  const li = el('li', { className: 'kanban-card', tabIndex: 0 });
  li.dataset.node = n.id;
  const text = el('div', { className: 'kanban-text' });
  renderText(text, cardText(n.text, field) || '(empty)', n, depth + 1);
  const where = n.pageTitle ?? '';
  li.append(text, el('a', { className: 'kanban-where', href: `#/page/${encodeURIComponent(where)}/${n.id}`, textContent: where }));
  return li;
}

/** Moves a card to a column: on screen at once, then in its text. */
function move(notd, host, li, col) {
  if (!col || col === li.closest('.kanban-column')) return;
  const from = li.closest('.kanban-column');
  col.querySelector('.kanban-cards').prepend(li);
  for (const c of [from, col]) c.querySelector('.kanban-count').textContent = String(c.querySelectorAll('.kanban-card').length);
  li.focus({ preventScroll: true });
  notd.commands.run('kanban.move', { id: li.dataset.node, field: host.kanbanField, to: JSON.parse(col.dataset.value) });
}

/** Dragging with a mouse or pen at once, with a finger after holding it still a moment; Shift+arrows on a focused card. */
function wire(notd, host) {
  host.addEventListener('keydown', (e) => {
    const li = e.target.closest?.('.kanban-card');
    if (!li || !e.shiftKey || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
    e.preventDefault();
    const col = li.closest('.kanban-column');
    move(notd, host, li, e.key === 'ArrowLeft' ? col.previousElementSibling : col.nextElementSibling);
  });
  host.addEventListener('click', (e) => { if (host.dropped) { e.preventDefault(); e.stopPropagation(); } }, { capture: true });
  let drag = null;
  host.addEventListener('touchmove', (e) => { if (drag?.armed) e.preventDefault(); }, { passive: false });

  const onMove = (e) => {
    if (e.pointerId !== drag.id) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!drag.on) {
      if (Math.hypot(dx, dy) < 5) return;
      if (!drag.armed) { end(false); return; }      // a finger moving straight away scrolls
      drag.on = true;
      drag.li.classList.add('kanban-dragging');
    }
    drag.li.style.transform = `translate(${dx}px, ${dy}px) rotate(1deg)`;
    const col = document.elementFromPoint(e.clientX, e.clientY)?.closest('.kanban-column');
    const target = col && host.contains(col) ? col : null;
    if (target !== drag.target) {
      drag.target?.classList.remove('kanban-over');
      target?.classList.add('kanban-over');
      drag.target = target;
    }
  };
  const onUp = () => end(true);
  const onCancel = () => end(false);
  const end = (drop) => {
    removeEventListener('pointermove', onMove);
    removeEventListener('pointerup', onUp);
    removeEventListener('pointercancel', onCancel);
    const { li, on, target, timer } = drag;
    clearTimeout(timer);
    drag = null;
    li.classList.remove('kanban-dragging', 'kanban-held');
    li.style.transform = '';
    target?.classList.remove('kanban-over');
    if (on && drop) {
      host.dropped = true;                           // the click that ends a drag opens nothing
      setTimeout(() => { host.dropped = false; });
      move(notd, host, li, target);
    }
  };
  host.addEventListener('pointerdown', (e) => {
    const li = e.target.closest('.kanban-card');
    if (drag || !li || e.button !== 0 || e.target.closest('a, button, input')) return;
    drag = { li, x: e.clientX, y: e.clientY, id: e.pointerId, on: false, armed: e.pointerType !== 'touch', target: null };
    if (!drag.armed) drag.timer = setTimeout(() => { if (drag) { drag.armed = true; li.classList.add('kanban-held'); } }, 350);
    addEventListener('pointermove', onMove);
    addEventListener('pointerup', onUp);
    addEventListener('pointercancel', onCancel);
  });
}
