// The browser code loads without an import map and under the app page's CSP
// (script-src 'self'): every module is reached by a relative path to a .js file that
// exists, and the page itself has no inline script. A bare name such as 'lit-html'
// would need an import map, and an inline import map needs a hash in the CSP that
// breaks when the page's bytes change on the way (a Windows checkout with CRLF).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** Every module specifier in a file: static imports and re-exports, bare imports and import('…') with a string.
    Only comments that start a line are skipped, so a "//" or "/*" inside a string or a regular expression
    never hides the code after it. */
function specifiers(source) {
  const code = source
    .replace(/^[ \t]*\/\*[\s\S]*?\*\//gm, ' ')
    .replace(/^[ \t]*\/\/.*$/gm, ' ');
  const found = [];
  const patterns = [
    /\b(?:import|export)\s*(?:[\w*$\s{},]*?\bfrom\s*)?(['"])([^'"\n]+)\1/g,   // import x from '…', export { y } from '…', import '…'
    /\bimport\s*\(\s*(['"])([^'"\n]+)\1\s*\)/g,                               // import('…')
  ];
  for (const re of patterns) for (const m of code.matchAll(re)) found.push(m[2]);
  return found;
}

function* files(dir) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) { if (name !== 'tests' && name !== 'node_modules') yield* files(path); }
    else if (/\.m?js$/.test(name)) yield path;
  }
}

/** Browser modules: the client (vendored libraries included) and each plugin's browser code. */
const browserFiles = () => [...files(join(ROOT, 'client')), ...files(join(ROOT, 'plugins'))];

test('the specifier finder sees every form of import', () => {
  assert.deepEqual(specifiers(`
    import a from './a.js'; import { b, c as d } from "../b.js";
    import * as e from './e.js'; import './f.js'; export { g } from './g.js'; export * from './h.js';
    import{ i }from'lit-html';
    const j = await import('./j.js');
    // import k from 'commented-out';
    /* import l from 'also-commented'; */
    /**
     * import n from 'in-a-doc-comment';
     */
    const url = "http://example.com/"; import m from './m.js'; const re = /\/*/; import o from './o.js';
  `), ['./a.js', '../b.js', './e.js', './f.js', './g.js', './h.js', 'lit-html', './m.js', './o.js', './j.js']);
});

test('browser modules import only by relative path, to .js files that exist', () => {
  const problems = [];
  for (const file of browserFiles()) {
    const where = relative(ROOT, file);
    if (file.endsWith('.mjs')) problems.push(`${where}: a browser module must end in .js (some servers send .mjs with a MIME type the browser refuses)`);
    for (const spec of specifiers(readFileSync(file, 'utf8'))) {
      if (!/^\.\.?\//.test(spec)) { problems.push(`${where}: imports '${spec}' by a bare name; use a relative path`); continue; }
      const target = resolve(dirname(file), spec);
      if (!target.endsWith('.js')) problems.push(`${where}: imports '${spec}', which is not a .js file`);
      else if (!existsSync(target)) problems.push(`${where}: imports '${spec}', which does not exist`);
    }
  }
  assert.deepEqual(problems, []);
});

test('the app reaches the libraries it needs from its entry point', () => {
  const seen = new Set();
  const walk = (file) => {
    if (seen.has(file)) return;
    seen.add(file);
    for (const spec of specifiers(readFileSync(file, 'utf8'))) if (/^\.\.?\//.test(spec)) walk(resolve(dirname(file), spec));
  };
  walk(join(ROOT, 'client/app.js'));
  const reached = [...seen].map((f) => relative(ROOT, f).replaceAll('\\', '/'));
  for (const lib of ['client/vendor/lit-html/lit-html.js', 'client/vendor/marked/marked.esm.js', 'client/vendor/dompurify/purify.js']) {
    assert.ok(reached.includes(lib), `${lib} is not reached from client/app.js`);
  }
});

test('the app page has no inline script, no import map and no inline event handler', () => {
  const page = readFileSync(join(ROOT, 'client/index.html'), 'utf8');
  const scripts = [...page.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)];
  assert.ok(scripts.length > 0);
  for (const [tag, attrs, body] of scripts) {
    assert.match(attrs, /\bsrc=/, `inline script: ${tag.slice(0, 60)}`);
    assert.doesNotMatch(attrs, /importmap/, 'import map');
    assert.equal(body.trim(), '', `a script with src has a body: ${tag.slice(0, 60)}`);
  }
  assert.doesNotMatch(page, /\son[a-z]+\s*=/i, 'inline event handler');
  assert.doesNotMatch(page, /javascript:/i, 'javascript: URL');
});

test('every plugin\'s browser entry is a .js file that exists', () => {
  for (const name of readdirSync(join(ROOT, 'plugins'))) {
    const manifest = join(ROOT, 'plugins', name, 'plugin.json');
    if (!existsSync(manifest)) continue;
    const { browser } = JSON.parse(readFileSync(manifest, 'utf8'));
    if (!browser) continue;
    assert.match(browser, /\.js$/, `${name}: browser entry ${browser}`);
    assert.ok(existsSync(join(ROOT, 'plugins', name, browser)), `${name}: ${browser} is missing`);
  }
});
