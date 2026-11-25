// Note text to HTML: Markdown (marked) plus notd's own syntax. The HTML still
// has to go through DOMPurify before it reaches the page (see ui/text.js);
// this part has no DOM, so it is tested in Node.
//
// Syntax is a list of marked inline extensions. The core's are below; plugins
// will add theirs through the same list (step 3.3).
//
//   [[Page]]  #tag  #[[two words]]  ((block id))  {{embed ((id))}}  {{embed [[Page]]}}
//   {key::value}  ![name](file:id)  [name](file:id)

import { Marked } from '../vendor/marked/marked.esm.js';

const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
export const HEADING = /^(#{1,3}) /;

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const pageHref = (title) => '#/page/' + encodeURIComponent(title);

/** The core syntax, as marked inline extensions. */
export const syntax = [
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

/** Does the text need Markdown at all? Plain words are drawn as text, much faster. */
export function isPlain(text) {
  return !/[\\`*_~[\]()<>{}#!|&:]|^\s*([-+>]|\d+[.)])\s|^ {4}|\n/m.test(text);
}

/** A block's text as HTML (not yet sanitised). A leading heading marker is left to the row. */
export function toHtml(text) {
  return marked.parse(text.replace(HEADING, ''));
}
