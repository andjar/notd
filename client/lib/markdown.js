// Note text to HTML: Markdown (marked) plus notd's own syntax. The HTML still
// has to go through DOMPurify before it reaches the page (see ui/text.js);
// this part has no DOM, so it is tested in Node.
//
// Syntax is a list of marked inline extensions. The core's are below; plugins
// will add theirs through the same list (step 3.3).
//
//   [[Page]]  #tag  #[[two words]]  ((block id))  {{embed ((id))}}  {{embed [[Page]]}}
//   {key::value}  ![name](file:id)  [name](file:id)  {{query ...}}
//
// Syntax that shows something live (a query's results, a plugin's board) is drawn as an
// empty widget element, <span class="widget" data-widget="name" data-match="[...]">, which
// ui/text.js fills once the text is on the page (see widgets below).

import { Marked } from '../vendor/marked/marked.esm.js';

const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
export const HEADING = /^(#{1,3}) /;

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const pageHref = (title) => '#/page/' + encodeURIComponent(title);

/** Live parts of a block, by name: mount(el, match, { block, depth }) fills el. */
export const widgets = new Map();
const widget = (name, m) => `<span class="widget" data-widget="${esc(name)}" data-match="${esc(JSON.stringify([...m]))}"></span>`;

/** The core syntax, as marked inline extensions. */
export const syntax = [
  {
    name: 'query', level: 'inline',
    start: (src) => src.indexOf('{{query'),
    tokenizer(src) {
      const m = src.match(/^\{\{query(?:\s+([^\n]*?))?\s*\}\}/);
      if (m) return { type: 'query', raw: m[0], m: [m[0], (m[1] ?? '').trim()] };
    },
    renderer: (t) => widget('query', t.m),
  },
  {
    name: 'embed', level: 'inline',
    start: (src) => src.indexOf('{{embed'),
    tokenizer(src) {
      const m = src.match(new RegExp(`^\\{\\{embed\\s+(?:\\(\\((${UUID})\\)\\)|\\[\\[([^\\]\\n]+)\\]\\])\\s*\\}\\}`));
      if (m) return { type: 'embed', raw: m[0], id: m[1]?.toLowerCase(), title: m[2] };
    },
    renderer: (t) => t.id ? `<span class="embed" data-embed-block="${t.id}"></span>`
      : `<span class="embed" data-embed-page="${esc(t.title)}"></span>`,
  },
  {
    name: 'pageLink', level: 'inline',
    start: (src) => src.indexOf('[['),
    tokenizer(src) {
      const m = src.match(/^\[\[([^\]\n]+)\]\]/);
      if (m) return { type: 'pageLink', raw: m[0], title: m[1] };
    },
    renderer: (t) => `<a class="page-ref" data-page="${esc(t.title)}" href="${pageHref(t.title)}">${esc(t.title)}</a>`,
  },
  {
    name: 'tag', level: 'inline',
    start(src) {
      const m = src.match(/(^|[^\p{L}\p{N}_#&/])#(?=\[\[|[\p{L}\p{N}_])/u);
      return m ? m.index + m[1].length : undefined;
    },
    tokenizer(src) {
      const m = src.match(/^#(?:\[\[([^\]\n]+)\]\]|([\p{L}\p{N}_][\p{L}\p{N}_/-]*))/u);
      if (m) return { type: 'tag', raw: m[0], title: m[1] ?? m[2] };
    },
    renderer: (t) => `<a class="tag" data-page="${esc(t.title)}" href="${pageHref(t.title)}">#${esc(t.title)}</a>`,
  },
  {
    name: 'blockRef', level: 'inline',
    start: (src) => src.indexOf('(('),
    tokenizer(src) {
      const m = src.match(new RegExp(`^\\(\\((${UUID})\\)\\)`));
      if (m) return { type: 'blockRef', raw: m[0], id: m[1].toLowerCase() };
    },
    renderer: (t) => `<a class="block-ref" data-ref="${t.id}" href="#">…</a>`,
  },
  {
    name: 'field', level: 'inline',
    start: (src) => src.search(/\{[A-Za-z0-9_.-]+::/),
    tokenizer(src) {
      const m = src.match(/^\{([A-Za-z0-9_.-]+)::([^}\n]*)\}/);
      if (m) return { type: 'field', raw: m[0], key: m[1], tokens: this.lexer.inlineTokens(m[2].trim()) };
    },
    renderer(t) { return `<span class="field" data-key="${esc(t.key.toLowerCase())}"><span class="field-key">${esc(t.key)}</span> ${this.parser.parseInline(t.tokens)}</span>`; },
  },
];

const marked = new Marked({
  gfm: true,
  breaks: true,
  extensions: syntax,
  renderer: {
    link({ href, title, tokens }) {
      const text = this.parser.parseInline(tokens);
      const fileId = href.match(new RegExp(`^file:(${UUID})$`))?.[1];
      const url = fileId ? `api/v1/file?id=${fileId.toLowerCase()}` : href;
      const external = /^https?:/i.test(url);
      return `<a href="${esc(url)}"${title ? ` title="${esc(title)}"` : ''}${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>${text}</a>`;
    },
    image({ href, title, text }) {
      const fileId = href.match(new RegExp(`^file:(${UUID})$`))?.[1];
      const url = fileId ? `api/v1/file?id=${fileId.toLowerCase()}` : href;
      return `<img src="${esc(url)}" alt="${esc(text)}"${title ? ` title="${esc(title)}"` : ''} loading="lazy">`;
    },
  },
});

/**
 * Syntax added by plugins. A pattern found anywhere in the text (at: 'inline', the default)
 * becomes a marked extension; one that must open the block (at: 'start'), such as a task's
 * TODO, is matched before Markdown and drawn in front of the rest.
 *
 *   addSyntax({ name: 'task', pattern: /^(TODO|DONE) /, at: 'start', render: (m) => '<input ...>' })
 */
const startSyntax = [];
const inlineTriggers = [];

export function addSyntax({ name, pattern, at = 'inline', render, mount }) {
  if (!name || !(pattern instanceof RegExp) || (typeof render !== 'function' && typeof mount !== 'function')) throw new Error('Syntax needs name, pattern and render() or mount().');
  if (mount) {                                   // live: drawn as a widget that mount() fills on the page
    widgets.set(name, mount);
    render = (m) => widget(name, m);
  }
  const anchored = new RegExp('^(?:' + pattern.source.replace(/^\^/, '') + ')', pattern.flags.replace('g', ''));
  if (at === 'start') { startSyntax.push({ name, pattern: anchored, render }); return; }
  const loose = new RegExp(pattern.source.replace(/^\^/, ''), pattern.flags.replace('g', ''));
  inlineTriggers.push(loose);
  marked.use({ extensions: [{
    name, level: 'inline',
    start: (src) => { const i = src.search(loose); return i < 0 ? undefined : i; },
    tokenizer(src) { const m = src.match(anchored); if (m) return { type: name, raw: m[0], m }; },
    renderer: (t) => String(render(t.m)),
  }] });
}

/** The part of a block's text that start syntax claims, drawn as HTML, and the rest. */
export function splitStart(text) {
  for (const s of startSyntax) {
    const m = text.match(s.pattern);
    if (m) return { html: String(s.render(m, text)), rest: text.slice(m[0].length) };
  }
  return { html: '', rest: text };
}

/** Does the text need Markdown at all? Plain words are drawn as text, much faster. */
export function isPlain(text) {
  return !/[\\`*_~[\]()<>{}#!|&:]|^\s*([-+>]|\d+[.)])\s|^ {4}|\n/m.test(text) && !inlineTriggers.some((p) => p.test(text));
}

/** A block's text as HTML (not yet sanitised). A leading heading marker is left to the row. */
export function toHtml(text) {
  const { html, rest } = splitStart(text.replace(HEADING, ''));
  const body = marked.parse(rest).trim();
  if (!html) return body;
  return body.startsWith('<p>') ? '<p>' + html + body.slice(3) : '<p>' + html + '</p>' + body;
}
