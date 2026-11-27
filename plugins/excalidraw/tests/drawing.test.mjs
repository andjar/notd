import { test } from 'node:test';
import assert from 'node:assert/strict';
import { drawingsIn, replaced, appended, linkFor, svgName, newName, langCode } from '../lib/drawing.js';

const A = '0190aaaa-0000-7000-8000-000000000001';
const B = '0190aaaa-0000-7000-8000-000000000002';

test('drawings in a block: images of .excalidraw.svg files and links to .excalidraw files', () => {
  const text = `Plan ![Kitchen.excalidraw.svg](file:${A}) and [old.excalidraw](file:${B.toUpperCase()}) but not ![photo.svg](file:${A})`;
  assert.deepEqual(drawingsIn(text), [{ name: 'Kitchen.excalidraw.svg', file: A }, { name: 'old.excalidraw', file: B }]);
  assert.deepEqual(drawingsIn('no drawings [x](file:nope)'), []);
});

test('saving a drawing replaces its link, or adds it at the end', () => {
  const text = `Plan ![Kitchen.excalidraw.svg](file:${A}) and [old.excalidraw](file:${B})`;
  const link = linkFor({ id: 'NEW' }, 'old.excalidraw.svg');
  assert.equal(replaced(text, B, link), `Plan ![Kitchen.excalidraw.svg](file:${A}) and ![old.excalidraw.svg](file:NEW)`);
  assert.equal(replaced(text, 'other', link), text);
  assert.equal(appended('Sketch  ', link), 'Sketch ![old.excalidraw.svg](file:NEW)');
  assert.equal(appended('', link), link);
});

test('names', () => {
  assert.equal(svgName('Kitchen.excalidraw'), 'Kitchen.excalidraw.svg');
  assert.equal(svgName('Kitchen.excalidraw.svg'), 'Kitchen.excalidraw.svg');
  assert.equal(svgName('.excalidraw'), 'Drawing.excalidraw.svg');
  assert.equal(linkFor({ id: A }, 'a [b].excalidraw.svg'), `![a b.excalidraw.svg](file:${A})`);
  assert.equal(newName(new Date(2026, 10, 27, 9, 5)), 'Drawing 2026-11-27 09.05.excalidraw.svg');
});

test('the editor speaks the browser\'s language when it can', () => {
  const codes = ['en', 'nb-NO', 'nn-NO', 'de-DE', 'pt-PT', 'pt-BR'];
  assert.equal(langCode('nb', codes), 'nb-NO');
  assert.equal(langCode('no-NO', codes), 'nb-NO');
  assert.equal(langCode('nn', codes), 'nn-NO');
  assert.equal(langCode('de-AT', codes), 'de-DE');
  assert.equal(langCode('pt-BR', codes), 'pt-BR');
  assert.equal(langCode('xx', codes), 'en');
  assert.equal(langCode(undefined, codes), 'en');
});
