// The commands the core comes with. Plugins add theirs the same way.

import { commands } from './lib/commands.js';
import { today, pageLink } from './lib/route.js';
import { pickFiles, upload, insertIntoBlock } from './ui/attach.js';

export function registerCoreCommands({ palette, go }) {
  const reg = (c) => commands.register(c);
  const block = (ctx) => ctx.outline?.model.get(ctx.id) ?? null;

  reg({ id: 'palette.open', title: 'Command palette', key: 'Mod+k', run: (ctx) => palette.open(ctx) });
  reg({ id: 'search.focus', title: 'Search', key: 'Mod+/', run: () => document.querySelector('.search input')?.focus() });
  reg({ id: 'go.journal', title: 'Go to the journal', run: () => { location.hash = ''; } });
  reg({ id: 'go.today', title: "Open today's page", run: () => go(today()) });
  reg({
    id: 'theme.toggle', title: 'Switch between light and dark',
    run() {
      const dark = document.documentElement.dataset.theme
        ? document.documentElement.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
      document.documentElement.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem('notd.theme', document.documentElement.dataset.theme); } catch { /* not kept */ }
    },
  });

  // Undo and redo when no block is being edited (inside one, the editor handles the keys).
  const outside = (ctx) => !!ctx.outline && !ctx.editor?.editing;
  reg({ id: 'edit.undo', title: 'Undo', key: 'Mod+z', when: outside, run: (ctx) => ctx.outline.undo() });
  reg({ id: 'edit.redo', title: 'Redo', key: 'Mod+Shift+z', when: outside, run: (ctx) => ctx.outline.redo() });

  // In a block, through the slash menu.
  for (const level of [1, 2, 3]) {
    reg({
      id: `block.heading${level}`, title: `Heading ${level}`, slash: true, slashOnly: true,
      run(ctx) {
        ctx.insert('');
        const n = block(ctx);
        if (n) ctx.editor?.replaceText(ctx.id, '#'.repeat(level) + ' ' + n.text.replace(/^#{1,3} /, ''));
      },
    });
  }
  reg({ id: 'block.today', title: "Today's date", slash: true, slashOnly: true, run: (ctx) => ctx.insert(`[[${today()}]]`) });
  reg({ id: 'block.link', title: 'Link to a page', slash: true, slashOnly: true, run: (ctx) => ctx.insert('[[', 2) });
  reg({ id: 'block.code', title: 'Code block', slash: true, slashOnly: true, run: (ctx) => ctx.insert('```\n\n```', 4) });
  const attach = async (ctx) => {
    const files = await pickFiles();
    const links = files.length ? await upload(files) : [];
    if (links.length && ctx.editor) insertIntoBlock(ctx.editor, ctx.id, links.join(' '));
  };
  reg({ id: 'block.file', title: 'File or image', slash: true, slashOnly: true, run: (ctx) => { ctx.insert(''); return attach(ctx); } });

  // On a block, through its menu.
  reg({
    id: 'block.copy-ref', title: 'Copy block reference', menu: 'block',
    run: (ctx) => navigator.clipboard?.writeText(`((${ctx.id}))`),
  });
  reg({
    id: 'block.zoom', title: 'Zoom in', menu: 'block',
    run: (ctx) => { const p = ctx.page; if (p) location.hash = pageLink(p.title, ctx.id); },
  });
  reg({
    id: 'block.collapse-all', title: 'Fold everything under it', menu: 'block',
    run(ctx) { for (const id of ctx.outline.model.subtreeIds(ctx.id)) if (ctx.outline.model.hasChildren(id)) ctx.outline.setCollapsed(id, true); },
  });
  reg({
    id: 'block.expand-all', title: 'Unfold everything under it', menu: 'block',
    run(ctx) { for (const id of ctx.outline.model.subtreeIds(ctx.id)) ctx.outline.setCollapsed(id, false); },
  });
  reg({ id: 'block.attach', title: 'Attach a file', menu: 'block', run: attach });
  reg({ id: 'block.delete', title: 'Delete block', menu: 'block', run: (ctx) => ctx.outline.remove(ctx.id) });
}
