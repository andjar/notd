// Attachments. A file pasted into a block, dropped onto one, or picked with
// "Attach a file" (the block's menu, or / in a block) is uploaded and linked in
// the block's text: an image as ![name](file:id), so it shows, anything else as
// [name](file:id). The server keeps one copy per content, so attaching the same
// file twice costs nothing. Dropped onto the page but not onto a block, the
// files get a new block at the end.

import { api } from '../lib/api.js';
import { notice } from '../lib/notice.js';
import { readText, getCaret } from './editor.js';

export const MAX_BYTES = 50 * 1024 * 1024;           // the server's limit (see docs/protocol/files.md)

/**
 * Plugins can take files of their own kind instead of having them stored (the mail plugin takes
 * .eml files): { accept(file) -> bool, handle(file, { block }) -> Promise<string> }, where block is
 * the block the file goes into (null when it was dropped onto the page) and the string is the text
 * to put there ('' for none).
 */
const handlers = [];
export function addFileHandler(h) {
  if (typeof h?.accept !== 'function' || typeof h?.handle !== 'function') throw new Error('A file handler needs accept() and handle().');
  handlers.push(h);
}

/** The Markdown that links an uploaded file node, under the name it was uploaded with (the same content may have come before under another). */
export function fileLink(node, name = node.title) {
  name = String(name || 'file').replace(/[[\]\n]/g, '');
  return `${String(node.mime ?? '').startsWith('image/') ? '!' : ''}[${name}](file:${node.id})`;
}

/** A pasted screenshot arrives as "image.png"; give it a name worth keeping. */
function named(file) {
  if (!/^image\.(png|jpe?g|gif|webp)$/i.test(file.name)) return file;
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  const stamp = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}.${p(d.getMinutes())}.${p(d.getSeconds())}`;
  return new File([file], `Pasted image ${stamp}.${file.name.split('.').pop().toLowerCase()}`, { type: file.type });
}

/** Uploads files one at a time; returns the links of those the server took. Says what happens in the status. */
export async function upload(files, { block = null } = {}) {
  const links = [];
  for (const raw of files) {
    const file = named(raw);
    const handler = handlers.find((h) => { try { return h.accept(file); } catch { return false; } });
    if (handler) {
      try {
        const text = await handler.handle(file, { block });
        if (text) links.push(text);
      } catch (e) {
        notice(`${file.name}: ${e.message}`, { error: true });
      }
      window.notd?.feed?.poll();             // whatever the plugin added shows now, not at the next poll
      continue;
    }
    if (file.size > MAX_BYTES) { notice(`${file.name} is larger than 50 MB, so it was not attached.`, { error: true }); continue; }
    notice(`Uploading ${file.name}…`, { sticky: true });
    try {
      links.push(fileLink(await api.upload(file), file.name));
    } catch (e) {
      notice(`${file.name} was not attached: ${e.message}`, { error: true });
      continue;
    }
    notice(`Attached ${file.name}`);
  }
  return links;
}

/** Puts text into a block: at the caret if the block is being edited (so the browser can undo it), otherwise at the end. */
export function insertIntoBlock(editor, id, text) {
  const content = editor.view?.contentOf(id);
  if (editor.editing === id && content) {
    content.focus({ preventScroll: true });
    const at = getCaret(content);
    const pad = at > 0 && !/\s/.test(readText(content)[at - 1]) ? ' ' : '';
    document.execCommand('insertText', false, pad + text);   // fires input, which saves it
    return;
  }
  const n = editor.outline.model.get(id);
  if (!n) return;
  editor.outline.edit(id, n.text ? `${n.text}${/\s$/.test(n.text) ? '' : ' '}${text}` : text, { undoable: true });
}

/** Asks for files (the system's file picker) and resolves with them; an empty list if none were picked. */
export function pickFiles() {
  return new Promise((resolve) => {
    const input = Object.assign(document.createElement('input'), { type: 'file', multiple: true });
    input.addEventListener('change', () => resolve([...input.files]), { once: true });
    input.addEventListener('cancel', () => resolve([]), { once: true });
    input.click();
  });
}

/**
 * Pasting and dropping files on one page screen. screen: { root, outline, editor, page }.
 * A paste with files and no text uploads them into the block being edited.
 */
export function acceptFiles(screen) {
  const root = screen.root;
  let over = null;
  const mark = (el) => { if (over !== el) { over?.classList.remove('drop-target'); over = el; over?.classList.add('drop-target'); } };
  const hasFiles = (e) => [...(e.dataTransfer?.types ?? [])].includes('Files');

  root.addEventListener('paste', async (e) => {
    const id = screen.editor?.editing;
    const files = [...(e.clipboardData?.files ?? [])];
    if (!id || !files.length || e.clipboardData.getData('text/plain')) return;   // text wins: copying from Word gives both
    e.preventDefault();
    const links = await upload(files, { block: id });
    if (links.length) insertIntoBlock(screen.editor, id, links.join(' '));
  });
  root.addEventListener('dragover', (e) => {
    if (!hasFiles(e) || !screen.page) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    mark(e.target.closest('.block')?.querySelector(':scope > .row') ?? root);
  });
  root.addEventListener('dragleave', (e) => { if (!root.contains(e.relatedTarget)) mark(null); });
  root.addEventListener('drop', async (e) => {
    if (!hasFiles(e) || !screen.page) return;
    e.preventDefault();
    mark(null);
    const files = [...e.dataTransfer.files];
    const target = e.target.closest('.block')?.dataset.id;
    const links = await upload(files, { block: target ?? null });
    if (!links.length) return;
    if (target && screen.outline.model.has(target)) { insertIntoBlock(screen.editor, target, links.join(' ')); return; }
    const last = screen.outline.model.children(screen.page.id).at(-1);
    screen.outline.create({ parent: screen.page.id, after: last?.id ?? null, text: links.join(' ') });
  });
}
