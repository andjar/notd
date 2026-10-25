// Generates random keyBetween cases with the JS implementation, checks ordering
// invariants, and verifies the PHP port returns identical keys.
// Usage: node tests/js/ordering_parity.mjs
import { keyBetween, keysBetween, isValidKey } from '../../assets/js/app/fractional-index.js';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
let seed = 12345;
const rand = (n) => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed % n; };
const cases = [];
let list = [];
for (let i = 0; i < 4000; i++) {
  const pos = rand(list.length + 1);
  const a = pos > 0 ? list[pos - 1] : null;
  const b = pos < list.length ? list[pos] : null;
  const k = keyBetween(a, b);
  if (!isValidKey(k)) throw new Error('invalid key ' + k);
  if ((a !== null && !(a < k)) || (b !== null && !(k < b))) throw new Error(`order violated ${a} ${k} ${b}`);
  cases.push([a, b, k]);
  list.splice(pos, 0, k);
}
// adversarial: always insert at front / at back / repeatedly between the same pair
for (const mode of ['front', 'back', 'squeeze']) {
  let lo = null, hi = null, keys = [];
  for (let i = 0; i < 300; i++) {
    let a, b;
    if (mode === 'front') { a = null; b = keys[0] ?? null; }
    else if (mode === 'back') { a = keys.at(-1) ?? null; b = null; }
    else { a = lo; b = hi; }
    const k = keyBetween(a, b);
    cases.push([a, b, k]);
    if (mode === 'front') keys.unshift(k); else if (mode === 'back') keys.push(k); else { if (i % 2) lo = k; else hi = k; if (lo && hi && lo >= hi) throw new Error('squeeze'); }
  }
}
const sorted = [...list].sort();
if (sorted.join() !== list.join()) throw new Error('list not sorted');
const multi = keysBetween('a0', 'a1', 50);
for (let i = 1; i < multi.length; i++) if (!(multi[i - 1] < multi[i])) throw new Error('keysBetween order');
cases.push(['__multi', 'a0', 'a1', 50, multi]);
const file = path.join(here, 'ordering_cases.json');
writeFileSync(file, JSON.stringify(cases));
const out = execFileSync('php', [path.join(here, 'ordering_parity.php'), file]).toString();
console.log(`JS invariants ok over ${cases.length} cases; max key length ${Math.max(...list.map(k => k.length))}`);
console.log(out.trim());
