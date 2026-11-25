// Panels: the parts of the interface around the page (search, calendar,
// backlinks, ...), each placed in a named slot of the frame. The core registers
// its own panels the same way plugins will.
//
//   panels.register({ id: 'recent', slot: 'app.left', order: 20,
//                     render(el, ctx) { el.textContent = '...'; } });
//
// render() gets a plain element and the context ({ page, route }); it may use
// lit-html or anything else. A panel that throws shows an error in its own box
// and does not break the others. when(ctx) can hide a panel.

const registry = new Map();
let context = {};

export const panels = {
  register(panel) {
    if (!panel.id || !panel.slot || typeof panel.render !== 'function') throw new Error('A panel needs id, slot and render().');
    registry.set(panel.id, { order: 100, ...panel });
    if (document.querySelector(`[data-slot="${panel.slot}"]`)) mountSlot(panel.slot);
  },

  /** Renders every panel again with a new context (or the panels in one slot). */
  refresh(ctx = context, slot = null) {
    context = ctx;
    const slots = slot ? [slot] : [...new Set([...registry.values()].map((p) => p.slot))];
    for (const s of slots) mountSlot(s);
  },

  get context() { return context; },
};

function mountSlot(slot) {
  const host = document.querySelector(`[data-slot="${slot}"]`);
  if (!host) return;
  const list = [...registry.values()].filter((p) => p.slot === slot).sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
  const keep = new Set();
  for (const p of list) {
    let visible = true;
    try { visible = p.when ? p.when(context) !== false : true; } catch { visible = false; }
    let el = host.querySelector(`:scope > [data-panel="${p.id}"]`);
    if (!visible) { el?.remove(); continue; }
    if (!el) {
      el = document.createElement('section');
      el.className = 'panel';
      el.dataset.panel = p.id;
    }
    host.append(el); // keeps the order
    keep.add(el);
    try {
      p.render(el, context);
    } catch (e) {
      console.error(`panel ${p.id}:`, e);
      el.innerHTML = '';
      const msg = document.createElement('p');
      msg.className = 'panel-error';
      msg.textContent = `The panel "${p.id}" failed: ${e.message}`;
      el.append(msg);
    }
  }
  for (const el of host.querySelectorAll(':scope > [data-panel]')) if (!keep.has(el)) el.remove();
}
