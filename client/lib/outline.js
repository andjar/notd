// The outline controller: the one place blocks change on the client. It applies
// each operation to the model, records how to undo it, queues it in the outbox,
// and tells the view what changed. No DOM, so it is tested in Node.
//
//   const o = new Outline({ outbox, onChange: (change) => view.update(change) });
//   o.load(page, blocks);
//   const id = o.create({ parent: page.id, after: null, text: 'hello' });
//   o.indent(id); o.undo();
//
// Every structural action (create, delete, move, split, merge, a toggled task)
// is one undo step; everything one key press does is one step. Typing inside a
// block is not recorded here: the browser undoes text while a block is edited.

import { OutlineModel } from './model.js';
import { newId } from './keys.js';

const UNDO_LIMIT = 200;
let opCounter = 0;
const opId = () => `op-${Date.now().toString(36)}-${(opCounter++).toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

export class Outline {
  constructor({ outbox, onChange = () => {} }) {
    this.model = new OutlineModel();
    this.outbox = outbox;
    this.onChange = onChange;
    this.undoStack = [];
    this.redoStack = [];
    this.recording = null;
    this.replaying = false;
  }

  get pageId() { return this.model.pageId; }

  load(page, blocks) {
    this.model.load(page.id, blocks);
    this.undoStack.length = 0;
    this.redoStack.length = 0;
    // Unsent changes from before a reload are applied on top of what the server sent.
    for (const op of this.outbox.pending()) {
      const here = op.op === 'create' ? op.parent === page.id || this.model.has(op.parent) : this.model.has(op.id);
      if (here) this.model.apply(op);
    }
  }

  // ---------------------------------------------------------------- actions

  create({ parent = this.pageId, after = null, text = '', collapsed = false, id = newId() } = {}) {
    if (parent !== this.pageId && this.model.get(parent)?.collapsed) this.setCollapsed(parent, false);
    const op = { op: 'create', id, parent, sort: this.model.keyFor(parent, after), text, collapsed };
    return this.#commit(op) ? id : null;
  }

  /** Changes a block's text. Typing is not an undo step; pass undoable for changes made for the user. */
  edit(id, text, { undoable = false } = {}) {
    const n = this.model.get(id);
    if (!n || n.text === text) return false;
    return !!this.#commit({ op: 'edit', id, text }, undoable);
  }

  move(id, parent, after = null) {
    if (!this.model.has(id) || (parent !== this.pageId && !this.model.has(parent)) || this.model.isInSubtree(id, parent)) return false;
    return !!this.#commit({ op: 'move', id, parent, sort: this.model.keyFor(parent, after, id) });
  }

  remove(id) {
    return this.model.has(id) && !!this.#commit({ op: 'delete', id });
  }

  setCollapsed(id, collapsed) {
    const n = this.model.get(id);
    if (!n || n.collapsed === collapsed) return false;
    return !!this.#commit({ op: 'collapse', id, collapsed }, false);
  }

  /** Makes a block the last child of the sibling above it. */
  indent(id) {
    const prev = this.model.prevSibling(id);
    if (!prev) return false;
    if (prev.collapsed) this.setCollapsed(prev.id, false);
    const last = this.model.children(prev.id).at(-1);
    return this.move(id, prev.id, last?.id ?? null);
  }

  /** Moves a block out to right after its parent. */
  outdent(id) {
    const n = this.model.get(id);
    if (!n || n.parent === this.pageId) return false;
    return this.move(id, this.model.get(n.parent).parent, n.parent);
  }

  /** Moves a block above its previous sibling (-1) or below its next (+1). */
  shift(id, dir) {
    const n = this.model.get(id);
    if (!n) return false;
    if (dir < 0) {
      const prev = this.model.prevSibling(id);
      return prev ? this.move(id, n.parent, this.model.prevSibling(prev.id)?.id ?? null) : false;
    }
    const next = this.model.nextSibling(id);
    return next ? this.move(id, n.parent, next.id) : false;
  }

  /** Splits a block at a position: the text after it becomes a new block below. Returns the new id. */
  split(id, at, asChild = false) {
    const n = this.model.get(id);
    if (!n) return null;
    const before = n.text.slice(0, at);
    const after = n.text.slice(at);
    this.edit(id, before, { undoable: true });
    // A block with visible children gets the new block as its first child, like every outliner.
    if (asChild || (this.model.hasChildren(id) && !n.collapsed)) return this.create({ parent: id, after: null, text: after });
    return this.create({ parent: n.parent, after: id, text: after });
  }

  /** Joins a block onto the visible block above it; its children follow. Returns [target id, caret position]. */
  mergeUp(id) {
    const vis = this.model.visible();
    const i = vis.findIndex((b) => b.id === id);
    if (i <= 0) return null;
    const target = vis[i - 1];
    const n = this.model.get(id);
    const at = target.text.length;
    let after = this.model.children(target.id).at(-1)?.id ?? null;
    for (const c of this.model.children(id)) {
      this.move(c.id, target.id, after);
      after = c.id;
    }
    this.edit(target.id, target.text + n.text, { undoable: true });
    this.remove(id);
    return [target.id, at];
  }

  // -------------------------------------------------------------- undo/redo

  undo() { return this.#step(this.undoStack, this.redoStack); }
  redo() { return this.#step(this.redoStack, this.undoStack); }

  /** The operations that reverse op, worked out from the model before op is applied. */
  #invert(op) {
    const m = this.model;
    const n = m.get(op.id);
    switch (op.op) {
      case 'create': return n ? [] : [{ op: 'delete', id: op.id }];
      case 'delete': return m.subtreeIds(op.id).map((id) => {
        const x = m.get(id);
        return { op: 'create', id, parent: x.parent, after: m.prevSibling(id)?.id ?? null, text: x.text, collapsed: x.collapsed };
      });
      case 'move': return n ? [{ op: 'move', id: n.id, parent: n.parent, after: m.prevSibling(n.id)?.id ?? null }] : [];
      case 'edit': return n && n.text !== op.text ? [{ op: 'edit', id: n.id, text: n.text }] : [];
      default: return [];
    }
  }

  #commit(op, undoable = true) {
    const inverse = undoable && !this.replaying ? this.#invert(op) : null;
    const change = this.model.apply(op);
    if (!change) return null;
    if (inverse?.length) this.#record(inverse);
    this.outbox.enqueue({ ...op, opId: opId() });
    this.onChange(change);
    return change;
  }

  #record(inverse) {
    if (!this.recording) {
      this.recording = [];
      queueMicrotask(() => {
        this.undoStack.push(this.recording);
        if (this.undoStack.length > UNDO_LIMIT) this.undoStack.shift();
        this.redoStack.length = 0;
        this.recording = null;
      });
    }
    this.recording.unshift(...inverse);
  }

  /** Runs recorded actions; returns { inverse, touched }. */
  #replay(actions) {
    const m = this.model;
    const inverse = [];
    let touched = null;
    this.replaying = true;
    try {
      for (const a of actions) {
        let op = null;
        const parentOk = (p) => (p === this.pageId || m.has(p) ? p : this.pageId);
        if (a.op === 'create' && !m.has(a.id)) {
          const parent = parentOk(a.parent);
          const after = a.after && m.get(a.after)?.parent === parent ? a.after : null;
          op = { op: 'create', id: a.id, parent, sort: m.keyFor(parent, after), text: a.text, collapsed: a.collapsed };
        } else if (a.op === 'delete' && m.has(a.id)) {
          op = { op: 'delete', id: a.id };
        } else if (a.op === 'move' && m.has(a.id)) {
          const parent = parentOk(a.parent);
          if (m.isInSubtree(a.id, parent)) continue;
          const after = a.after && a.after !== a.id && m.get(a.after)?.parent === parent ? a.after : null;
          op = { op: 'move', id: a.id, parent, sort: m.keyFor(parent, after, a.id) };
        } else if (a.op === 'edit' && m.has(a.id)) {
          op = { op: 'edit', id: a.id, text: a.text };
        }
        if (!op) continue;
        const back = this.#invert(op);
        const change = m.apply(op);
        if (!change) continue;
        inverse.unshift(...back);
        this.outbox.enqueue({ ...op, opId: opId() });
        this.onChange(change);
        if (op.op !== 'delete') touched = op.id;
      }
    } finally {
      this.replaying = false;
    }
    return { inverse, touched };
  }

  #step(from, to) {
    const actions = from.pop();
    if (!actions) return null;
    const { inverse, touched } = this.#replay(actions);
    if (inverse.length) to.push(inverse);
    return touched && this.model.has(touched) ? touched : true;
  }

  // ------------------------------------------------------- server changes

  /** A node from the change feed: merged unless the client still has unsent changes to it. */
  fromServer(node, deleted = false) {
    if (deleted) {
      if (!this.model.has(node.id) || this.outbox.hasPending(node.id)) return null;
      const change = this.model.apply({ op: 'delete', id: node.id });
      if (change) this.onChange(change);
      return change;
    }
    const pending = (kinds) => this.outbox.hasPending(node.id, kinds);
    const change = this.model.merge(node, { keepStructure: pending(['create', 'move']), keepText: pending(['create', 'edit']), keepCollapsed: pending(['collapse']) });
    if (change && (change.kind !== 'merge' || change.structural || change.text)) this.onChange(change);
    return change;
  }
}
