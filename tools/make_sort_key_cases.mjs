// Writes tests/fixtures/sort_keys.json: sort key cases made by the JavaScript
// implementation, which the PHP and JavaScript tests both check against.
// Run again only when the algorithm changes on purpose.
import { writeFileSync } from 'node:fs';
import { keyBetween, keysBetween } from '../client/lib/keys.js';

let seed = 12345;
const rand = (n) => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed % n; };
const cases = [];
const list = [];
for (let i = 0; i < 1500; i++) {
  const pos = rand(list.length + 1);
  const a = pos > 0 ? list[pos - 1] : null;
  const b = pos < list.length ? list[pos] : null;
  const k = keyBetween(a, b);
  cases.push([a, b, k]);
  list.splice(pos, 0, k);
}
// Adversarial: always at the front, always at the back, always between the same pair.
for (const mode of ['front', 'back', 'squeeze']) {
  let lo = null; let hi = null; const keys = [];
  for (let i = 0; i < 200; i++) {
    const a = mode === 'front' ? null : mode === 'back' ? (keys.at(-1) ?? null) : lo;
    const b = mode === 'front' ? (keys[0] ?? null) : mode === 'back' ? null : hi;
    const k = keyBetween(a, b);
    cases.push([a, b, k]);
    if (mode === 'front') keys.unshift(k); else if (mode === 'back') keys.push(k); else if (i % 2) lo = k; else hi = k;
  }
}
const many = [['a0', 'a1', 50], [null, 'a0', 20], ['a0', null, 20]].map(([a, b, n]) => [a, b, n, keysBetween(a, b, n)]);
writeFileSync(new URL('../tests/fixtures/sort_keys.json', import.meta.url), JSON.stringify({ between: cases, many }) + '\n');
console.log(`${cases.length} cases written`);
