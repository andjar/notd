// The core size budget: counts non-blank lines in the core and fails when a
// limit is passed, and checks that the core never imports a plugin.
// Usage: node tools/budget.mjs [root]. Writes a table to $GITHUB_STEP_SUMMARY.

import { readFileSync, readdirSync, statSync, existsSync, appendFileSync } from 'node:fs';
import { join, relative, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export const LIMITS = [
  { name: 'PHP', dirs: ['server', 'index.php'], ext: ['.php'], limit: 2500 },
  { name: 'JavaScript', dirs: ['client'], ext: ['.js', '.mjs'], limit: 5000 },
  { name: 'CSS', dirs: ['client'], ext: ['.css'], limit: 2000 },
];
const SKIP = new Set(['vendor', 'node_modules']);

function* files(path) {
  if (!existsSync(path)) return;
  if (statSync(path).isFile()) { yield path; return; }
  for (const name of readdirSync(path)) {
    if (SKIP.has(name)) continue;
    yield* files(join(path, name));
  }
}

export function countLines(text) {
  return text.split('\n').filter((l) => l.trim() !== '').length;
}

/** Lines in the core that load code from plugins/ directly. */
export function pluginImports(text) {
  const found = [];
  text.split('\n').forEach((line, i) => {
    if (/\b(?:import|from|require|require_once|include|include_once)\b[^;\n]*['"][^'"]*\bplugins\//.test(line)) found.push(i + 1);
  });
  return found;
}

export function measure(root) {
  const rows = [];
  const violations = [];
  for (const { name, dirs, ext, limit } of LIMITS) {
    let lines = 0;
    let count = 0;
    for (const d of dirs) {
      for (const f of files(join(root, d))) {
        if (!ext.some((e) => f.endsWith(e))) continue;
        const text = readFileSync(f, 'utf8');
        lines += countLines(text);
        count++;
        for (const line of pluginImports(text)) violations.push(`${relative(root, f)}:${line} imports from plugins/`);
      }
    }
    rows.push({ name, files: count, lines, limit, ok: lines <= limit });
  }
  return { rows, violations };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = resolve(process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '..'));
  const { rows, violations } = measure(root);
  const table = ['| Core | Files | Lines | Limit | |', '| --- | ---: | ---: | ---: | --- |',
    ...rows.map((r) => `| ${r.name} | ${r.files} | ${r.lines} | ${r.limit} | ${r.ok ? 'within' : '**over**'} |`)].join('\n');
  console.log(table);
  if (violations.length) console.log('\nThe core must not import plugins:\n' + violations.join('\n'));
  if (process.env.GITHUB_STEP_SUMMARY) {
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, `## Core size budget\n\n${table}\n` + (violations.length ? `\n${violations.join('\n')}\n` : ''));
  }
  if (rows.some((r) => !r.ok) || violations.length) process.exit(1);
}
