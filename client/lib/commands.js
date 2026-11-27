// Commands: named actions that a key, the command palette (Ctrl/Cmd+K), the
// slash menu (/ in a block), a block's menu (right-click its bullet) or a
// click on an element with data-command can run.
//
//   commands.register({ id: 'tasks.toggle', title: 'Toggle task', key: 'Mod+Enter',
//                       slash: true, menu: 'block', run(ctx) { ... } });
//
// ctx: { id (the block, if any), outline, editor, page, element, insert(text) }.
// key: "Mod+K" (Ctrl, or Cmd on a Mac), "Alt+Shift+T", "Escape", ...

const registry = new Map();
let contextFor = () => ({});

export const commands = {
  register(cmd) {
    if (!cmd.id || typeof cmd.run !== 'function') throw new Error('A command needs id and run().');
    registry.set(cmd.id, { title: cmd.id, ...cmd });
  },
  get: (id) => registry.get(id) ?? null,
  all: () => [...registry.values()],

  /** Runs a command by id; extra is merged into the context. Returns whatever it returns. */
  run(id, extra = {}) {
    const cmd = registry.get(id);
    if (!cmd) { console.warn(`No command "${id}".`); return undefined; }
    const ctx = { ...contextFor(extra.id), ...extra };
    if (cmd.when && cmd.when(ctx) === false) return undefined;
    try {
      return cmd.run(ctx);
    } catch (e) {
      console.error(`command ${id}:`, e);
      return undefined;
    }
  },

  /** Commands usable in this context, optionally only those with a flag (slash, menu). */
  available(ctx, filter = () => true) {
    return commands.all().filter((c) => filter(c) && (!c.when || safeWhen(c, ctx)));
  },

  /** The context for a block id (or, without one, the block being edited): { id, outline, editor, page }. */
  context: (id) => contextFor(id),

  /** The app tells the registry how to find the current block, editor and page. */
  setContext(fn) { contextFor = fn; },
};

function safeWhen(c, ctx) {
  try { return c.when(ctx) !== false; } catch { return false; }
}

const MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

/** Does a keyboard event match "Mod+Shift+K"? */
export function matches(e, spec) {
  const parts = spec.split('+');
  const key = parts.pop();
  const want = new Set(parts.map((p) => p.toLowerCase()));
  const mod = MAC ? e.metaKey : e.ctrlKey;
  if (want.has('mod') !== mod) return false;
  if (want.has('alt') !== e.altKey || want.has('shift') !== e.shiftKey) return false;
  if (!want.has('mod') && (MAC ? e.ctrlKey : e.metaKey) && !want.has('ctrl') && !want.has('meta')) return false;
  return e.key.toLowerCase() === key.toLowerCase() || e.code === key;
}

/** How a key looks in menus: "Ctrl+K" or "⌘K". */
export function keyLabel(spec) {
  return MAC ? spec.replace('Mod+', '⌘').replace('Shift+', '⇧').replace('Alt+', '⌥') : spec.replace('Mod+', 'Ctrl+');
}

export function installKeys() {
  addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.isComposing) return;
    for (const c of registry.values()) {
      if (c.key && matches(e, c.key)) {
        const ctx = contextFor();
        if (c.when ? !safeWhen(c, ctx) : (c.needsBlock && !ctx.id)) continue;
        e.preventDefault();
        commands.run(c.id);
        return;
      }
    }
  });
  // One listener for every clickable thing that names a command.
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-command]');
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();
    commands.run(el.dataset.command, { id: el.closest('[data-node], .block')?.dataset.node ?? el.closest('.block')?.dataset.id ?? el.dataset.id ?? null, element: el });
  }, true);
}
