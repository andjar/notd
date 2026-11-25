// The tasks plugin in the browser: draws the keyword as a checkbox and a word,
// adds commands and an agenda on journal pages.

import { html, render } from 'lit-html';
import { LEAD, toggle, cycle, nextState, setKeyword, setField, keyword, stamp } from './lib/tasks.js';

const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

export function activate(notd) {
  notd.syntax.register({
    name: 'keyword', at: 'start', pattern: LEAD,
    render: (m) => {
      const k = m[1];
      const done = k === 'DONE' || k === 'CANCELLED';
      return `<input type="checkbox" class="task-box" data-command="tasks.toggle" aria-label="Done"${done ? ' checked' : ''}${k === 'CANCELLED' ? ' disabled' : ''}>`
        + `<button type="button" class="task-keyword task-${k.toLowerCase()}" data-command="tasks.next" title="Change state">${esc(k)}</button> `;
    },
  });

  /** Changes a block's text through the editor when it is open, else through the outline (both undoable). */
  const change = (ctx, fn) => {
    const n = ctx.outline?.model.get(ctx.id);
    if (!n) return;
    const text = fn(n.text);
    if (text === n.text) return;
    if (ctx.editor?.editing === ctx.id) ctx.editor.replaceText(ctx.id, text);
    else ctx.outline.edit(ctx.id, text, { undoable: true });
  };

  notd.commands.register({ id: 'toggle', title: 'Tick or untick the task', menu: 'block', run: (ctx) => change(ctx, (t) => toggle(t)) });
  notd.commands.register({ id: 'next', title: 'Next task state (TODO, DOING, WAITING)', run: (ctx) => change(ctx, (t) => nextState(t)) });
  notd.commands.register({ id: 'cycle', title: 'Make a task, tick it, or make it a block again', key: 'Mod+Enter', when: (ctx) => !!ctx.id, run: (ctx) => change(ctx, (t) => cycle(t)) });
  for (const k of ['TODO', 'DOING', 'WAITING', 'DONE', 'CANCELLED']) {
    notd.commands.register({ id: k.toLowerCase(), title: k, slash: true, slashOnly: true, run: (ctx) => { ctx.insert(''); change(ctx, (t) => setKeyword(t, k)); } });
  }
  const today = () => stamp().slice(0, 10);
  notd.commands.register({ id: 'scheduled', title: 'Scheduled for today', slash: true, slashOnly: true, run: (ctx) => { ctx.insert(''); change(ctx, (t) => setField(keyword(t) ? t : `TODO ${t}`, 'scheduled', today())); } });
  notd.commands.register({ id: 'deadline', title: 'Deadline today', slash: true, slashOnly: true, run: (ctx) => { ctx.insert(''); change(ctx, (t) => setField(keyword(t) ? t : `TODO ${t}`, 'deadline', today())); } });

  notd.panels.register({ id: 'agenda', slot: 'page.before', order: 10, when: (ctx) => !!ctx.page && /^\d{4}-\d{2}-\d{2}$/.test(ctx.page.title), render: (el, ctx) => agenda(notd, el, ctx.page.title) });
}

/** On a journal page: what is scheduled or due on that day or overdue, and what is in progress. */
async function agenda(notd, el, day) {
  const q = (s) => notd.api.query(s).then((r) => r.nodes).catch(() => []);
  const [scheduled, due, doing] = await Promise.all([
    q(`task:open scheduled<=${day} sort:scheduled limit:50`),
    q(`task:open deadline<=${day} sort:deadline limit:50`),
    q('task:doing sort:start limit:50'),
  ]);
  const seen = new Set();
  const uniq = (list) => list.filter((n) => !seen.has(n.id) && seen.add(n.id));
  const groups = [['Scheduled', uniq(scheduled), 'scheduled'], ['Deadlines', uniq(due), 'deadline'], ['In progress', uniq(doing), 'start']].filter((g) => g[1].length);
  if (!groups.length) { render('', el); el.hidden = true; return; }
  el.hidden = false;
  const label = (n, f) => {
    const v = n.fields?.[f]?.[0] ?? '';
    return v.slice(0, 10) < day && f !== 'start' ? html`<span class="overdue">${v}</span>` : html`<span>${v}</span>`;
  };
  render(html`<div class="agenda">${groups.map(([title, list, f]) => html`
    <h2>${title}</h2>
    <ul>${list.map((n) => html`<li><a href=${`#/page/${encodeURIComponent(n.pageTitle)}/${n.id}`}>${n.text.replace(LEAD, '').replace(/ ?\{[a-z]+::[^}]*\}/gi, '').split('\n')[0]}</a> ${label(n, f)}
      <span class="agenda-page">${n.pageTitle}</span></li>`)}</ul>`)}</div>`, el);
}
