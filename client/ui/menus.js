// The three ways to pick a command with a list: the palette (Ctrl/Cmd+K), the
// slash menu (type / in a block) and a block's menu (right-click or long-press
// its bullet). They share one small list component.

import { commands, keyLabel } from '../lib/commands.js';
import { readText, getCaret, setCaret } from './editor.js';

const fold = (s) => s.toLocaleLowerCase();

class List {
  constructor(className) {
    this.el = document.createElement('div');
    this.el.className = 'menu ' + className;
    this.el.setAttribute('role', 'listbox');
    this.el.hidden = true;
    this.items = [];
    this.active = 0;
    this.onPick = null;
    this.el.addEventListener('mousedown', (e) => {
      const li = e.target.closest('[data-i]');
      if (li) { e.preventDefault(); this.pick(Number(li.dataset.i)); }
    });
    document.body.append(this.el);
  }

  show(items, onPick, at) {
    this.items = items;
    this.onPick = onPick;
    this.active = 0;
    this.draw();
    this.el.hidden = !items.length;
    if (at) {
      this.el.style.left = `${Math.max(4, Math.min(at.left, innerWidth - 280))}px`;
      this.el.style.top = `${Math.min(at.top, innerHeight - 40)}px`;
    }
  }

  draw() {
    const list = this.el.querySelector('ul') ?? this.el.appendChild(document.createElement('ul'));
    list.replaceChildren(...this.items.map((c, i) => {
      const li = document.createElement('li');
      li.dataset.i = i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', String(i === this.active));
      li.className = i === this.active ? 'active' : '';
      const t = document.createElement('span');
      t.textContent = c.title;
      li.append(t);
      if (c.key) {
        const k = document.createElement('kbd');
        k.textContent = keyLabel(c.key);
        li.append(k);
      }
      return li;
    }));
  }

  /** Arrow keys, Enter, Tab and Escape while the list is open. True when the key was used. */
  key(e) {
    if (this.el.hidden) return false;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      this.active = (this.active + (e.key === 'ArrowDown' ? 1 : -1) + this.items.length) % this.items.length;
      this.draw();
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      this.pick(this.active);
    } else if (e.key === 'Escape') {
      this.hide();
    } else {
      return false;
    }
    e.preventDefault();
    e.stopImmediatePropagation();
    return true;
  }

  pick(i) {
    const item = this.items[i];
    const fn = this.onPick;
    this.hide();
    if (item && fn) fn(item);
  }

  hide() { this.el.hidden = true; }
  get open() { return !this.el.hidden; }
}

/** Every word typed is somewhere in the command's title (or id): "query open" finds "Query: open tasks". */
const match = (q) => {
  const words = fold(q ?? '').split(/\s+/).filter(Boolean);
  return (c) => { const t = fold(c.title) + ' ' + fold(c.id); return words.every((w) => t.includes(w)); };
};

/** The command palette: every available command, filtered as you type. */
export class Palette {
  constructor() {
    this.list = new List('palette');
    this.input = document.createElement('input');
    this.input.type = 'text';
    this.input.placeholder = 'Run a command…';
    this.input.setAttribute('aria-label', 'Command');
    this.list.el.prepend(this.input);
    this.input.addEventListener('input', () => this.#filter());
    this.input.addEventListener('keydown', (e) => this.list.key(e));
    this.input.addEventListener('blur', () => setTimeout(() => this.list.hide(), 150));
  }

  open(ctx) {
    this.ctx = ctx;
    this.input.value = '';
    this.#filter();
    this.list.el.hidden = false;
    this.input.focus();
  }

  #filter() {
    const items = commands.available(this.ctx, (c) => !c.slashOnly && c.id !== 'palette.open').filter(match(this.input.value));
    this.list.show(items, (c) => commands.run(c.id, this.ctx));
    this.list.el.hidden = false;
  }
}

/** The slash menu in a block: "/" then a few letters lists matching commands. */
export class SlashMenu {
  constructor(editor) {
    this.editor = editor;
    this.list = new List('slash');
    const root = editor.view.root;
    root.addEventListener('input', () => this.#update());
    root.addEventListener('keydown', (e) => this.list.key(e), true);
    root.addEventListener('focusout', () => setTimeout(() => {
      if (!document.activeElement?.matches?.('.content[contenteditable]')) this.list.hide();
    }, 150));
  }

  #update() {
    const id = this.editor.editing;
    const content = id && this.editor.view.contentOf(id);
    if (!content) return this.list.hide();
    const text = readText(content);
    const caret = getCaret(content);
    const m = text.slice(0, caret).match(/(^|\s)\/([\p{L}\p{N} -]{0,20})$/u);
    if (!m || m[2].endsWith('  ')) return this.list.hide();
    const start = caret - m[2].length - 1;
    const ctx = { ...commands.context(id), ...this.#ctx(id, content, start, caret) };
    const items = commands.available(ctx, (c) => c.slash).filter(match(m[2].trim()));
    const r = getSelection().rangeCount ? getSelection().getRangeAt(0).getClientRects()[0] : null;
    const at = r ?? content.getBoundingClientRect();
    this.list.show(items, (c) => commands.run(c.id, this.#ctx(id, content, start, getCaret(content))), { left: at.left, top: at.bottom + 4 });
  }

  /** The context a slash command runs in: insert(text) replaces the typed /word with text. */
  #ctx(id, content, start, end) {  // eslint-disable-line no-param-reassign: start moves if a space goes
    return {
      id,
      insert: (insert, caretAt = insert.length) => {
        const text = readText(content);
        // Taking the /word out entirely also takes the space typed before it.
        const from = insert === '' && start > 0 && text[start - 1] === ' ' && (end === text.length || text[end] === ' ') ? start - 1 : start;
        const next = text.slice(0, from) + insert + text.slice(end);
        start = from;
        content.textContent = next;
        setCaret(content, start + caretAt);
        this.editor.outline.edit(id, next);
      },
    };
  }
}

/** A block's menu: right-click (or long-press) its bullet. */
export class BlockMenu {
  constructor() {
    this.list = new List('block-menu');
    document.addEventListener('contextmenu', (e) => {
      const bullet = e.target.closest('.block > .row > .bullet');
      if (!bullet) return;
      e.preventDefault();
      const id = bullet.closest('.block').dataset.id;
      const items = commands.available(commands.context(id), (c) => c.menu === 'block');
      this.list.show(items, (c) => commands.run(c.id, { id }), { left: e.clientX, top: e.clientY });
    });
    document.addEventListener('pointerdown', (e) => { if (!this.list.el.contains(e.target)) this.list.hide(); });
    addEventListener('keydown', (e) => this.list.key(e), true);
  }
}
