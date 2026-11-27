// The excalidraw plugin in the browser. "/Drawing" in a block opens a new
// drawing; clicking a drawing in a note (or "Edit drawing" in its block's menu)
// opens it. Saving uploads the drawing as an .excalidraw.svg file and puts its
// picture in the block, in place of the old one.

import { drawingsIn, replaced, appended, linkFor, svgName, newName } from './lib/drawing.js';
import { openEditor } from './editor.js';

export function activate(notd) {
  // Changing the block goes through the command registry, so a block on the page
  // on screen changes through its outline (one undo step) and any other through the server.
  notd.commands.register({
    id: 'put', title: 'Put a drawing into the block', when: (ctx) => typeof ctx.change === 'function',
    run: (ctx) => {
      const n = ctx.outline?.model.get(ctx.id);
      if (!n) throw new Error('The block is not on screen any more.');
      const text = ctx.change(n.text);
      if (text === n.text) return;
      if (ctx.editor?.editing === ctx.id) ctx.editor.replaceText(ctx.id, text);
      else ctx.outline.edit(ctx.id, text, { undoable: true });
    },
  });

  let open = false;
  /** Opens a drawing of block `id`: an existing one ({ file, name }) or a new one. */
  const edit = async (id, drawing = null) => {
    if (open) return;
    open = true;
    try {
      const blob = drawing ? await fetch(`api/v1/file?id=${drawing.file}`).then((r) => {
        if (!r.ok) throw new Error(`The drawing could not be read (${r.status}).`);
        return r.blob();
      }) : null;
      const name = drawing ? svgName(drawing.name) : newName();
      await openEditor({
        blob, name, title: name.replace(/\.excalidraw\.svg$/i, ''),
        save: async (file) => {
          const link = linkFor(await notd.api.upload(file), name);
          notd.commands.run('excalidraw.put', { id, change: (text) => (drawing ? replaced(text, drawing.file, link) : appended(text, link)) });
        },
      });
    } catch (e) {
      notd.notice(`Drawing: ${e.message}`, { error: true });
    } finally {
      open = false;
    }
  };

  notd.commands.register({
    id: 'new', title: 'Drawing', slash: true, slashOnly: true,
    run: (ctx) => { ctx.insert(''); if (ctx.id) edit(ctx.id); },
  });
  notd.commands.register({
    id: 'edit', title: 'Edit drawing', menu: 'block',
    when: (ctx) => drawingsIn(ctx.outline?.model.get(ctx.id)?.text ?? '').length > 0,
    run: (ctx) => edit(ctx.id, drawingsIn(ctx.outline.model.get(ctx.id).text)[0]),
  });

  // A click on a drawing in a note opens it, instead of editing the block's text.
  document.addEventListener('click', (e) => {
    const target = e.target.closest?.('img[alt$=".excalidraw.svg" i], a[href^="api/v1/file?id="]');
    if (!target || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    const name = target.tagName === 'IMG' ? target.getAttribute('alt') : target.textContent.trim();
    if (target.tagName === 'A' && !/\.excalidraw$/i.test(name)) return;
    const block = target.closest('[data-node], .block');
    const file = (target.getAttribute('src') ?? target.getAttribute('href')).match(/id=([0-9a-f-]{36})/i)?.[1];
    if (!block || !file) return;
    e.preventDefault();
    e.stopPropagation();
    edit(block.dataset.node ?? block.dataset.id, { file: file.toLowerCase(), name });
  }, true);
}
