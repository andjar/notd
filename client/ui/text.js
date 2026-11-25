// Draws a block's text into the page: Markdown through DOMPurify, then block
// references and embeds filled in from the server (cached per page load).

import DOMPurify from 'dompurify';
import { toHtml, isPlain, splitStart, HEADING, pageHref } from '../lib/markdown.js';
import { api } from '../lib/api.js';

const PURIFY = {
  ADD_ATTR: ['target', 'data-page', 'data-ref', 'data-key', 'data-embed-block', 'data-embed-page', 'loading', 'data-command'],
  FORBID_TAGS: ['style', 'form', 'textarea', 'select'],
  ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|tel:|#|api\/v1\/file\?id=|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
};
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A' && node.getAttribute('target') === '_blank') node.setAttribute('rel', 'noopener noreferrer');
});

const nodeCache = new Map();
export function forgetNodes() { nodeCache.clear(); }

function fetchNode(id) {
  if (!nodeCache.has(id)) nodeCache.set(id, api.node(id).catch(() => null));
  return nodeCache.get(id);
}

/** Renders text into el. depth limits embeds inside embeds. */
export function renderText(el, text, _node, depth = 0) {
  if (isPlain(text) && !splitStart(text).html) {
    el.classList.remove('md');
    el.textContent = text;
    return;
  }
  el.classList.add('md');                       // Markdown's own line breaks, not the text's
  el.innerHTML = DOMPurify.sanitize(toHtml(text).trim(), PURIFY);
  for (const a of el.querySelectorAll('a.block-ref[data-ref]')) fillRef(a);
  for (const e of el.querySelectorAll('.embed')) fillEmbed(e, depth);
}

async function fillRef(a) {
  const r = await fetchNode(a.dataset.ref);
  if (!r) { a.textContent = '((missing block))'; a.classList.add('broken'); return; }
  const page = await pageTitle(r.node.page);
  a.textContent = r.node.text.replace(HEADING, '').split('\n')[0] || '(empty block)';
  if (page) a.href = pageHref(page) + '/' + r.node.id;
}

async function pageTitle(id) {
  const r = await fetchNode(id);
  return r?.node.title ?? null;
}

async function fillEmbed(el, depth) {
  if (depth > 2) { el.textContent = '(embed too deep)'; return; }
  let root;
  let blocks;
  if (el.dataset.embedBlock) {
    const r = await fetchNode(el.dataset.embedBlock);
    if (!r) { el.textContent = '(missing block)'; return; }
    root = r.node;
    blocks = [r.node, ...r.descendants];
  } else {
    try {
      const r = await api.page(el.dataset.embedPage);
      root = r.page;
      blocks = r.blocks;
    } catch { el.textContent = `(no page “${el.dataset.embedPage}”)`; return; }
  }
  const byParent = new Map();
  for (const b of blocks) {
    if (b.id === root.id) continue;
    if (!byParent.has(b.parent)) byParent.set(b.parent, []);
    byParent.get(b.parent).push(b);
  }
  const list = (parent) => {
    const ul = document.createElement('ul');
    for (const b of byParent.get(parent) ?? []) {
      const li = document.createElement('li');
      const div = document.createElement('div');
      renderText(div, b.text, b, depth + 1);
      li.append(div);
      if (byParent.has(b.id)) li.append(list(b.id));
      ul.append(li);
    }
    return ul;
  };
  el.replaceChildren();
  if (root.kind === 'block') {
    const head = document.createElement('div');
    renderText(head, root.text, root, depth + 1);
    el.append(head);
  } else {
    const a = document.createElement('a');
    a.href = pageHref(root.title);
    a.className = 'embed-title';
    a.textContent = root.title;
    el.append(a);
  }
  el.append(list(root.id));
}
