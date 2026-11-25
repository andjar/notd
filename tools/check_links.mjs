// Checks that every internal link and image in the built docs site points at
// a file that exists. Usage: node tools/check_links.mjs <site dir> <base path>
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';

const [root = 'docs/public', base = '/'] = process.argv.slice(2);

function* htmlFiles(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* htmlFiles(p);
    else if (name.endsWith('.html')) yield p;
  }
}

function targetExists(path) {
  if (existsSync(path) && statSync(path).isFile()) return true;
  return existsSync(join(path, 'index.html'));
}

const broken = [];
let checked = 0;
for (const file of htmlFiles(root)) {
  const html = readFileSync(file, 'utf8');
  for (const [, url] of html.matchAll(/\s(?:href|src)=["']?([^"'\s>]+)/g)) {
    if (/^(?:[a-z]+:|\/\/|#)/i.test(url)) continue; // external, mailto, data, anchors
    const clean = decodeURIComponent(url.split('#')[0].split('?')[0]);
    if (!clean) continue;
    let path;
    if (clean.startsWith(base)) path = join(root, clean.slice(base.length));
    else if (clean.startsWith('/')) { broken.push(`${file}: ${url} (outside ${base})`); continue; }
    else path = resolve(dirname(file), clean);
    checked++;
    if (!targetExists(path)) broken.push(`${file}: ${url}`);
  }
}

console.log(`Checked ${checked} internal links.`);
if (broken.length) {
  console.error(`Broken links (${broken.length}):\n` + broken.join('\n'));
  process.exit(1);
}
