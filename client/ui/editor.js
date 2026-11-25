// Editing blocks. One block is edited at a time: clicking its text turns it
// into a plain-text editable box holding the block's raw text; leaving it draws
// the text again. Keys follow other outliners:
//
//   Enter            split the block at the caret       Shift+Enter  a new line in the block
//   Tab / Shift+Tab  indent / outdent                   Escape       stop editing
//   Backspace at the start: join with the block above (an empty block is removed)
//   Delete at the end: join the block below onto this one
//   Up / Down on the first / last line: the block above / below
//   Alt+Shift+Up / Down: move the block                 Ctrl+Up / Down: fold / unfold
//   Ctrl+Z / Ctrl+Shift+Z (Cmd on a Mac): undo / redo; text typed in the block is undone by the browser first

export class Editor {
  constructor(view, outline) {
    this.view = view;
    this.outline = outline;
    this.editing = null;     // id of the block being edited
    this.startText = '';     // its text when editing began
    const root = view.root;
    root.addEventListener('click', (e) => this.#click(e));
    root.addEventListener('keydown', (e) => this.#key(e));
    root.addEventListener('input', (e) => this.#input(e));
    root.addEventListener('focusout', (e) => this.#blur(e));
  }

  get model() { return this.outline.model; }

  /** Starts editing a block with the caret at a position ('start', 'end' or a number). */
  edit(id, caret = 'end') {
    if (this.editing && this.editing !== id) this.finish();
    const content = this.view.contentOf(id);
    const n = this.model.get(id);
    if (!content || !n) return;
    if (this.editing !== id) {
      this.editing = id;
      this.startText = n.text;
      this.view.element(id).classList.add('editing');
      content.classList.remove('md');
      content.contentEditable = 'plaintext-only';
      content.textContent = n.text;
      content.spellcheck = true;
    }
    content.focus({ preventScroll: true });
    setCaret(content, caret === 'start' ? 0 : caret === 'end' ? content.textContent.length : caret);
    content.scrollIntoView({ block: 'nearest' });
  }

  /** Stops editing and draws the block's text again. */
  finish() {
    const id = this.editing;
    if (!id) return;
    this.editing = null;
    const content = this.view.contentOf(id);
    if (content) {
      this.outline.edit(id, readText(content));
      content.removeAttribute('contenteditable');
    }
    this.view.element(id)?.classList.remove('editing');
    if (this.model.has(id)) this.view.update({ kind: 'edit', id });
    this.outline.outbox.flushSoon?.();
  }

  #click(e) {
    const toggle = e.target.closest('.toggle');
    if (toggle) {
      const id = toggle.closest('.block').dataset.id;
      this.outline.setCollapsed(id, !this.model.get(id).collapsed);
      return;
    }
    const content = e.target.closest('.content');
    if (!content || e.target.closest('a, button, input')) return;
    const id = content.closest('.block').dataset.id;
    if (this.editing === id) return;
    this.edit(id, clickOffset(e, content, this.model.get(id).text));
  }

  #input(e) {
    if (!this.editing) return;
    this.outline.edit(this.editing, readText(e.target));
  }

  #blur(e) {
    if (!this.editing || this.busy) return;
    const content = this.view.contentOf(this.editing);
    if (e.target === content && !content.contains(e.relatedTarget)) this.finish();
  }

  #key(e) {
    const id = this.editing;
    if (!id || e.isComposing) return;
    const content = this.view.contentOf(id);
    const o = this.outline;
    const m = this.model;
    const caret = getCaret(content);
    const text = readText(content);
    const mod = e.ctrlKey || e.metaKey;
    const go = (fn) => {
      e.preventDefault();
      this.busy = true;            // moving a focused element blurs it; that is not leaving the block
      try { fn(); } finally { this.busy = false; }
    };

    if (e.key === 'Enter' && !e.shiftKey && !mod && !e.altKey) {
      return go(() => {
        o.edit(id, text);
        const created = o.split(id, caret, id === this.view.zoom);  // the zoomed block gets a child, not a sibling out of view
        if (created) { this.#settle(id); this.edit(created, 'start'); }
      });
    }
    if (e.key === 'Tab') {
      return go(() => {
        o.edit(id, text);
        if (id === this.view.zoom) return;            // the zoomed block stays where it is
        if (e.shiftKey && this.model.get(id).parent === this.view.zoom) return;
        if (e.shiftKey ? o.outdent(id) : o.indent(id)) this.#refocus(id, caret);
      });
    }
    if (e.key === 'Escape') return go(() => { this.finish(); content.blur(); });
    if (e.key === 'Backspace' && caret === 0 && !hasSelection()) {
      return go(() => {
        o.edit(id, text);
        if (text === '' && !m.hasChildren(id)) {
          const prev = this.#neighbour(id, -1);
          if (!prev) return;
          this.editing = null;
          o.remove(id);
          this.edit(prev, 'end');
          return;
        }
        if (!this.#neighbour(id, -1)) return;          // nothing above in view (the zoomed block itself)
        const r = o.mergeUp(id);
        if (r) { this.editing = null; this.#settle(r[0]); this.edit(r[0], r[1]); }
      });
    }
    if (e.key === 'Delete' && caret === text.length && !hasSelection()) {
      const next = this.#neighbour(id, 1);
      if (!next) return;
      return go(() => {
        o.edit(id, text);
        this.finish();
        const r = o.mergeUp(next);
        if (r) this.edit(r[0], r[1]);
      });
    }
    if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && e.altKey && e.shiftKey) {
      return go(() => { o.edit(id, text); if (o.shift(id, e.key === 'ArrowUp' ? -1 : 1)) this.#refocus(id, caret); });
    }
    if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && mod && !e.shiftKey && !e.altKey) {
      return go(() => o.setCollapsed(id, e.key === 'ArrowUp'));
    }
    if (e.key === 'ArrowUp' && !e.shiftKey && !mod && !e.altKey && onFirstLine(content)) {
      const prev = this.#neighbour(id, -1);
      if (prev) go(() => this.edit(prev, 'end'));
      return;
    }
    if (e.key === 'ArrowDown' && !e.shiftKey && !mod && !e.altKey && onLastLine(content)) {
      const next = this.#neighbour(id, 1);
      if (next) go(() => this.edit(next, 'start'));
      return;
    }
    if (mod && !e.altKey && (e.key === 'z' || e.key === 'Z' || e.key === 'y')) {
      const redo = e.key === 'y' || e.shiftKey;
      if (!redo && text !== this.startText) return; // the browser undoes typing first
      return go(() => {
        this.finish();
        const touched = redo ? o.redo() : o.undo();
        if (typeof touched === 'string') this.edit(touched, 'end');
      });
    }
  }

  /** The visible block before (-1) or after (+1). */
  #neighbour(id, dir) {
    const vis = this.view.visible();
    const i = vis.findIndex((b) => b.id === id);
    return vis[i + dir]?.id ?? null;
  }

  /** After a move the element was re-inserted; focus is lost, so put the caret back. */
  #refocus(id, caret) {
    const content = this.view.contentOf(id);
    if (document.activeElement !== content) content.focus({ preventScroll: true });
    setCaret(content, caret);
  }

  /** Draws a block that has just been edited by a key, before editing moves on. */
  #settle(id) {
    const content = this.view.contentOf(id);
    if (this.editing === id) { this.editing = null; content?.removeAttribute('contenteditable'); }
    this.view.element(id)?.classList.remove('editing');
    this.view.update({ kind: 'edit', id });
  }
}

/** The text of an editable box (plaintext-only gives text nodes and, in some browsers, <br>s). */
export function readText(el) {
  let out = '';
  for (const node of el.childNodes) out += node.nodeName === 'BR' ? '\n' : node.textContent;
  return el.lastChild?.nodeName === 'BR' ? out.slice(0, -1) : out;
}

export function getCaret(el) {
  const sel = getSelection();
  if (!sel.rangeCount || !el.contains(sel.anchorNode)) return 0;
  const r = document.createRange();
  r.selectNodeContents(el);
  r.setEnd(sel.focusNode, sel.focusOffset);
  return r.toString().length;
}

export function setCaret(el, offset) {
  offset = Math.max(0, Math.min(offset, el.textContent.length));
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  let node;
  let left = offset;
  while ((node = walker.nextNode())) {
    if (left <= node.length) break;
    left -= node.length;
  }
  const r = document.createRange();
  if (node) r.setStart(node, left); else r.setStart(el, el.childNodes.length);
  r.collapse(true);
  const sel = getSelection();
  sel.removeAllRanges();
  sel.addRange(r);
}

function hasSelection() {
  const s = getSelection();
  return s.rangeCount > 0 && !s.isCollapsed;
}

function caretRect(el) {
  const sel = getSelection();
  if (!sel.rangeCount) return null;
  const r = sel.getRangeAt(0).cloneRange();
  r.collapse(true);
  const rect = r.getClientRects()[0];
  if (rect) return rect;
  return el.textContent === '' ? el.getBoundingClientRect() : null;
}

function onFirstLine(el) {
  const c = caretRect(el);
  if (!c) return true;
  const lh = parseFloat(getComputedStyle(el).lineHeight) || 20;
  return c.top - el.getBoundingClientRect().top < lh * 0.8;
}

function onLastLine(el) {
  const c = caretRect(el);
  if (!c) return true;
  const lh = parseFloat(getComputedStyle(el).lineHeight) || 20;
  return el.getBoundingClientRect().bottom - c.bottom < lh * 0.8;
}

/** Where in the raw text a click landed, estimated from the drawn text. */
function clickOffset(e, content, raw) {
  let node = null;
  let offset = 0;
  if (document.caretPositionFromPoint) {
    const p = document.caretPositionFromPoint(e.clientX, e.clientY);
    if (p) { node = p.offsetNode; offset = p.offset; }
  } else if (document.caretRangeFromPoint) {
    const r = document.caretRangeFromPoint(e.clientX, e.clientY);
    if (r) { node = r.startContainer; offset = r.startOffset; }
  }
  if (!node || !content.contains(node)) return 'end';
  const r = document.createRange();
  r.selectNodeContents(content);
  r.setEnd(node, offset);
  const drawn = r.toString();
  // The drawn text leaves out markup; find where the text before the click ends in the raw text.
  const tail = drawn.slice(-12);
  if (!tail) return 0;
  const at = raw.indexOf(tail, Math.max(0, drawn.length - tail.length - 4));
  return at >= 0 ? at + tail.length : Math.min(raw.length, drawn.length + (raw.length - content.textContent.length));
}
