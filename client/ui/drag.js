// Moving blocks by dragging their bullet, with a mouse, pen or finger. A line
// shows where the block will go: above or below the block under the pointer,
// or, further to the right, as its first child. A bullet that is pressed and
// let go without moving is still a link (it zooms into the block).

const THRESHOLD = 5;

export class Drag {
  constructor(view, outline) {
    this.view = view;
    this.outline = outline;
    view.root.addEventListener('pointerdown', (e) => this.#down(e));
    view.root.addEventListener('click', (e) => {
      if (this.suppressClick && e.target.closest('.bullet')) { e.preventDefault(); e.stopPropagation(); }
      this.suppressClick = false;
    }, true);
  }

  #down(e) {
    const bullet = e.target.closest('.bullet');
    if (!bullet || e.button !== 0) return;
    const block = bullet.closest('.block');
    const id = block.dataset.id;
    if (id === this.view.zoom) return;
    const start = { x: e.clientX, y: e.clientY };
    let dragging = false;
    let target = null;
    const line = document.createElement('div');
    line.className = 'drop-line';

    const move = (ev) => {
      if (!dragging) {
        if (Math.hypot(ev.clientX - start.x, ev.clientY - start.y) < THRESHOLD) return;
        dragging = true;
        block.classList.add('dragging');
        document.body.append(line);
        bullet.setPointerCapture?.(ev.pointerId);
      }
      ev.preventDefault();
      target = this.#target(ev.clientX, ev.clientY, id);
      if (!target) { line.hidden = true; return; }
      const r = target.rect;
      line.hidden = false;
      line.style.left = `${target.left}px`;
      line.style.width = `${Math.max(40, r.right - target.left)}px`;
      line.style.top = `${target.where === 'before' ? r.top - 1 : r.bottom - 1}px`;
    };
    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      removeEventListener('pointercancel', up);
      line.remove();
      block.classList.remove('dragging');
      if (!dragging) return;
      this.suppressClick = true;
      if (target) this.#drop(id, target);
    };
    addEventListener('pointermove', move, { passive: false });
    addEventListener('pointerup', up);
    addEventListener('pointercancel', up);
  }

  /** Where a drop at (x, y) would put the block: { id, where: 'before' | 'after' | 'child', rect, left }. */
  #target(x, y, dragged) {
    const rows = [...this.view.root.querySelectorAll('.block > .row')].filter((r) => {
      const b = r.parentElement;
      return b.offsetParent !== null && !this.outline.model.isInSubtree(dragged, b.dataset.id);
    });
    let best = null;
    for (const row of rows) {
      const rect = row.getBoundingClientRect();
      if (y >= rect.top && y <= rect.bottom) { best = row; break; }
      if (y > rect.bottom) best = row;
    }
    if (!best) best = rows[0];
    if (!best) return null;
    const rect = best.getBoundingClientRect();
    const content = best.querySelector('.content').getBoundingClientRect();
    const id = best.parentElement.dataset.id;
    if (id === this.view.zoom) return { id, where: 'child', rect, left: content.left + 20 };
    const upper = y < rect.top + rect.height / 2;
    if (!upper && x > content.left + 40) return { id, where: 'child', rect, left: content.left + 20 };
    return { id, where: upper ? 'before' : 'after', rect, left: content.left - 20 };
  }

  #drop(id, t) {
    const m = this.outline.model;
    const ref = m.get(t.id);
    if (!ref || t.id === id) return;
    if (t.where === 'child') this.outline.move(id, ref.id, null);
    else if (t.where === 'after') {
      // After a block with visible children means before its first child, as the line shows.
      if (m.hasChildren(ref.id) && !ref.collapsed) this.outline.move(id, ref.id, null);
      else this.outline.move(id, ref.parent, ref.id);
    } else {
      const prev = m.prevSibling(ref.id);
      this.outline.move(id, ref.parent, prev && prev.id !== id ? prev.id : (prev?.id === id ? m.prevSibling(id)?.id ?? null : null));
    }
  }
}
