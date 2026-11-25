// Link suggestions while typing: after [[ or #, a list of matching page titles
// (aliases count) appears at the caret. Arrow keys move, Enter or Tab picks,
// Escape closes. Picking a title that does not exist yet is fine: the link
// works and the page appears when it is first opened.

import { api } from '../lib/api.js';
import { typedLink, suggest, completion } from '../lib/links.js';
import { readText, getCaret, setCaret } from './editor.js';

let pages = null;
export function forgetPages() { pages = null; }
async function allPages() {
  pages ??= api.pages().catch(() => []);
  return pages;
}

export class Autocomplete {
  constructor(editor) {
    this.editor = editor;
    this.box = document.createElement('ul');
    this.box.className = 'suggest';
    this.box.setAttribute('role', 'listbox');
    this.box.hidden = true;
    document.body.append(this.box);
    this.items = [];
    this.active = 0;
    this.link = null;
    const root = editor.view.root;
    root.addEventListener('input', () => this.#update());
    root.addEventListener('keydown', (e) => this.#key(e), true); // before the editor's keys
    root.addEventListener('focusout', () => setTimeout(() => this.close(), 150));
    this.box.addEventListener('mousedown', (e) => {
      const li = e.target.closest('li');
      if (li) { e.preventDefault(); this.#pick(Number(li.dataset.i)); }
    });
  }

  get open() { return !this.box.hidden; }

  async #update() {
    const id = this.editor.editing;
    const content = id && this.editor.view.contentOf(id);
    if (!content) return this.close();
    const text = readText(content);
    const caret = getCaret(content);
    const link = typedLink(text, caret);
    if (!link || (link.kind === '#' && link.query === '')) return this.close();
    const list = suggest(await allPages(), link.query);
    if (this.editor.editing !== id) return;
    const q = link.query.trim();
    this.items = [...list.map((t) => ({ title: t })),
      ...(q && !list.some((t) => t.toLowerCase() === q.toLowerCase()) ? [{ title: q, create: true }] : [])];
    if (!this.items.length) return this.close();
    this.link = { ...link, id, caret };
    this.active = 0;
    this.#draw(content);
  }

  #draw(content) {
    this.box.replaceChildren(...this.items.map((it, i) => {
      const li = document.createElement('li');
      li.dataset.i = i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', String(i === this.active));
      li.className = i === this.active ? 'active' : '';
      li.textContent = it.create ? `New page: ${it.title}` : it.title;
      return li;
    }));
    const sel = getSelection();
    const r = sel.rangeCount ? sel.getRangeAt(0).getClientRects()[0] : null;
    const at = r ?? content.getBoundingClientRect();
    this.box.style.left = `${Math.min(at.left, innerWidth - 260)}px`;
    this.box.style.top = `${at.bottom + 4}px`;
    this.box.hidden = false;
  }

  #key(e) {
    if (!this.open) return;
    const stop = () => { e.preventDefault(); e.stopImmediatePropagation(); };
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      stop();
      this.active = (this.active + (e.key === 'ArrowDown' ? 1 : -1) + this.items.length) % this.items.length;
      this.#draw(this.editor.view.contentOf(this.link.id));
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      stop();
      this.#pick(this.active);
    } else if (e.key === 'Escape') {
      stop();
      this.close();
    }
  }

  #pick(i) {
    const item = this.items[i];
    const { id, kind, start, caret } = this.link ?? {};
    const content = id && this.editor.view.contentOf(id);
    this.close();
    if (!item || !content) return;
    const text = readText(content);
    let end = caret;
    if (kind === '[[' && text.slice(end, end + 2) === ']]') end += 2; // a closing ]] already typed
    const insert = completion(kind, item.title);
    const next = text.slice(0, start) + insert + text.slice(end);
    content.textContent = next;
    setCaret(content, start + insert.length);
    this.editor.outline.edit(id, next);
    if (item.create) forgetPages();
  }

  close() {
    this.box.hidden = true;
    this.link = null;
  }
}
