// Templates. A template is a page called notd/templates/<name>; its blocks are
// what it puts in. Each one is in the slash menu as "Template: <name>": in an
// empty block, the template's first block takes its place (children and all)
// and the rest follow; in a block with text, the whole template follows it.
// One undo takes it all back. Placeholders are filled in as it goes in:
//
//   {{date}} 2026-11-27   {{time}} 14:05   {{datetime}} 2026-11-27 14:05   {{weekday}} Friday
//   {{year}} 2026   {{month}} 11   {{day}} 27   {{page}} the page it goes into   {{cursor}} the caret
//
// Anything else in double braces, such as {{query ...}}, is left as it is.
// "Save as a template" in a block's menu makes a template of a block and its children.

import { api } from '../lib/api.js';
import { cached, forgetCached } from '../lib/cache.js';
import { commands } from '../lib/commands.js';
import { notice } from '../lib/notice.js';
import { newId } from '../lib/keys.js';
import { fill, CURSOR } from '../lib/placeholders.js';

export const PREFIX = 'notd/templates/';
/** Puts the template on page `title` into the block ctx.id (see the top). */
export async function insertTemplate(ctx, title) {
  let data;
  try { data = await api.page(title); } catch (e) { notice(`The template could not be read: ${e.message}`, { error: true }); return; }
  const o = ctx.outline;
  const cur = o?.model.get(ctx.id);
  if (!cur) return;
  const kids = new Map();
  for (const b of data.blocks) { if (!kids.has(b.parent)) kids.set(b.parent, []); kids.get(b.parent).push(b); }
  const top = kids.get(data.page.id) ?? [];
  if (!top.length) { notice(`The template “${title.slice(PREFIX.length)}” has no blocks yet.`, { error: true }); return; }
  const opts = { page: ctx.page?.title ?? '' };
  let caret = null;
  const text = (b, id) => {
    const t = fill(b.text, opts);
    const at = t.indexOf(CURSOR);
    if (at >= 0 && !caret) caret = { id, at };
    return t.split(CURSOR).join('');
  };
  const children = (from, to) => {
    let after = null;
    for (const b of kids.get(from) ?? []) {
      const id = newId();
      o.create({ id, parent: to, after, text: text(b, id), collapsed: b.collapsed });
      children(b.id, id);
      after = id;
    }
  };
  let rest = top;
  let after = ctx.id;
  if (cur.text.trim() === '' && !o.model.hasChildren(ctx.id)) {   // an empty block becomes the first template block
    const t = text(top[0], ctx.id);
    if (ctx.editor?.editing === ctx.id) ctx.editor.replaceText(ctx.id, t);
    else o.edit(ctx.id, t, { undoable: true });
    children(top[0].id, ctx.id);
    rest = top.slice(1);
  }
  for (const b of rest) {
    const id = newId();
    o.create({ id, parent: cur.parent, after, text: text(b, id), collapsed: b.collapsed });
    children(b.id, id);
    after = id;
  }
  if (caret && ctx.editor) ctx.editor.edit(caret.id, caret.at);
}

/** A block and its children as a new template, named after its first line. */
export async function saveAsTemplate(ctx) {
  const o = ctx.outline;
  const root = o?.model.get(ctx.id);
  if (!root) return;
  const base = root.text.split('\n')[0].replace(/^#{1,3} /, '').replace(/\{[A-Za-z0-9_.-]+::[^}\n]*\}|[[\]#*_`]/g, '').trim().slice(0, 60) || 'Untitled';
  const taken = new Set((await cached('pages', () => api.pages())).map((p) => p.title.toLowerCase()));
  let name = base;
  for (let i = 2; taken.has((PREFIX + name).toLowerCase()); i++) name = `${base} ${i}`;
  const ops = [];
  const pageId = newId();
  ops.push({ op: 'create', kind: 'page', id: pageId, title: PREFIX + name });
  const copy = (id, parent) => {
    const n = o.model.get(id);
    const nid = newId();
    ops.push({ op: 'create', id: nid, parent, text: n.text, collapsed: n.collapsed });
    for (const c of o.model.children(id)) copy(c.id, nid);
  };
  copy(ctx.id, pageId);
  try {
    await api.ops(ops);
  } catch (e) { notice(`The template was not saved: ${e.message}`, { error: true }); return; }
  forgetCached();
  await syncTemplates();
  notice(`Saved as the template “${name}”; edit it on the page ${PREFIX}${name}`);
}

const registered = new Set();

/** Puts every template in the slash menu (and takes away those that are gone). */
export async function syncTemplates() {
  let pages;
  try { pages = await cached('pages', () => api.pages()); } catch { return; }
  const titles = pages.map((p) => p.title).filter((t) => t.toLowerCase().startsWith(PREFIX) && t.length > PREFIX.length);
  const ids = new Set(titles.map((t) => `template:${t.toLowerCase()}`));
  for (const id of registered) if (!ids.has(id)) { commands.unregister(id); registered.delete(id); }
  for (const t of titles) {
    const id = `template:${t.toLowerCase()}`;
    commands.register({ id, title: `Template: ${t.slice(PREFIX.length)}`, slash: true, slashOnly: true,
      run: (ctx) => { ctx.insert(''); return insertTemplate(ctx, t); } });
    registered.add(id);
  }
}
