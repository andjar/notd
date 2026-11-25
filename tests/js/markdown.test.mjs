// The Markdown and syntax part of drawing a block (no DOM; sanitising is tested in the browser).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toHtml, isPlain } from '../../client/lib/markdown.js';

const ID = '01900000-0000-7000-8000-00000000000a';
const html = (t) => toHtml(t).trim();

test('plain text needs no Markdown', () => {
  for (const t of ['hello world', 'Østfold 2026', 'a, b; c?']) assert.equal(isPlain(t), true, t);
  for (const t of ['[[a]]', '#tag', '*x*', 'a\nb', '- item', '1. item', '`c`', '{a::b}', 'see: x']) assert.equal(isPlain(t), false, t);
});

test('page links, tags and two-word tags', () => {
  assert.equal(html('see [[Østfold]]'), '<p>see <a class="page-ref" data-page="Østfold" href="#/page/%C3%98stfold">Østfold</a></p>');
  assert.equal(html('#idea and #[[two words]]'),
    '<p><a class="tag" data-page="idea" href="#/page/idea">#idea</a> and <a class="tag" data-page="two words" href="#/page/two%20words">#two words</a></p>');
  assert.equal(html('C# and a#b and &#39;'), "<p>C# and a#b and &#39;</p>");
});

test('a heading marker is left to the row', () => {
  assert.equal(html('## Plan'), '<p>Plan</p>');
});

test('references, embeds and fields', () => {
  assert.equal(html(`((${ID}))`), `<p><a class="block-ref" data-ref="${ID}" href="#">…</a></p>`);
  assert.equal(html(`{{embed ((${ID.toUpperCase()}))}}`), `<p><span class="embed" data-embed-block="${ID}"></span></p>`);
  assert.equal(html('{{embed [[Reading list]]}}'), '<p><span class="embed" data-embed-page="Reading list"></span></p>');
  assert.equal(html('{rating:: **5**}'), '<p><span class="field" data-key="rating"><span class="field-key">rating</span> <strong>5</strong></span></p>');
});

test('nothing is syntax inside code', () => {
  assert.equal(html('`[[not]] #no`'), '<p><code>[[not]] #no</code></p>');
  assert.match(html('```\n[[not]]\n```'), /<pre><code>\[\[not\]\]\n<\/code><\/pre>/);
});

test('files become file URLs; external links open in a new tab', () => {
  assert.equal(html(`![cat](file:${ID})`), `<p><img src="api/v1/file?id=${ID}" alt="cat" loading="lazy"></p>`);
  assert.equal(html(`[report](file:${ID})`), `<p><a href="api/v1/file?id=${ID}">report</a></p>`);
  assert.equal(html('[x](https://example.org)'), '<p><a href="https://example.org" target="_blank" rel="noopener noreferrer">x</a></p>');
});

test('line breaks and Markdown lists', () => {
  assert.equal(html('a\nb'), '<p>a<br>b</p>');
  assert.match(html('- one\n- two'), /<ul>\n<li>one<\/li>\n<li>two<\/li>\n<\/ul>/);
});

test('plugin syntax: at the start of a block, and anywhere in it', async () => {
  const { addSyntax, toHtml: html2, isPlain: plain2 } = await import('../../client/lib/markdown.js');
  addSyntax({ name: 'task', at: 'start', pattern: /^(TODO|DONE) /, render: (m) => `<input type="checkbox" data-command="tasks.toggle"${m[1] === 'DONE' ? ' checked' : ''}>` });
  addSyntax({ name: 'cite', pattern: /\{\{@([\w-]+)\}\}/, render: (m) => `<cite>${m[1]}</cite>` });
  assert.equal(html2('TODO buy *milk*'), '<p><input type="checkbox" data-command="tasks.toggle">buy <em>milk</em></p>');
  assert.equal(html2('DONE x'), '<p><input type="checkbox" data-command="tasks.toggle" checked>x</p>');
  assert.equal(html2('not TODO here'), '<p>not TODO here</p>');
  assert.equal(html2('as {{@knuth84}} says'), '<p>as <cite>knuth84</cite> says</p>');
  assert.equal(plain2('as {{@knuth84}} says'), false);
  assert.equal(html2('`{{@code}}`'), '<p><code>{{@code}}</code></p>', 'not inside code');
});
