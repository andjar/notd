// Blocks that are shown but are not on a page on screen: in a query's results,
// on a board. Commands that change a block (ticking a task) find it here, and
// the change goes straight to the server, which then reports it through the
// change feed like any other. It looks enough like an outline for commands:
// model.get(id), model.has(id) and edit(id, text).

import { api } from './api.js';

const nodes = new Map();

export const remote = {
  /** Nodes now on show, so commands can find them. */
  remember(list) { for (const n of list) nodes.set(n.id, { ...n }); },
  model: {
    get: (id) => nodes.get(id) ?? null,
    has: (id) => nodes.has(id),
    hasChildren: () => false,
  },
  async edit(id, text) {
    const n = nodes.get(id);
    if (!n || n.text === text) return;
    n.text = text;
    await api.ops([{ op: 'edit', id, text }]);
    document.dispatchEvent(new CustomEvent('notd:changed-elsewhere', { detail: { ids: [id] } }));
  },
};
