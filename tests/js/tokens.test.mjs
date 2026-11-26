// The colours in client/style/tokens.css: every colour used for text reads at 4.5:1
// or more on the page and on the sidebars, in light and in dark (WCAG AA), and the
// two dark blocks (system dark, and dark chosen in notd) are the same.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../client/style/tokens.css', import.meta.url), 'utf8');

/** The custom properties in the first rule after `selector` (inside an @media, the rule within it). */
function block(selector) {
  const at = css.indexOf(selector);
  assert.ok(at >= 0, selector);
  let open = css.indexOf('{', at);
  if (selector.startsWith('@media')) open = css.indexOf('{', open + 1);
  const body = css.slice(open + 1, css.indexOf('}', open));
  return Object.fromEntries([...body.matchAll(/--([\w-]+):\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));
}

function luminance(hex) {
  const [r, g, b] = hex.match(/^#(..)(..)(..)$/).slice(1).map((h) => parseInt(h, 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const contrast = (a, b) => { const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

const light = block(':root {');
const dark = block('@media (prefers-color-scheme: dark)');
const chosenDark = block(':root[data-theme="dark"]');
const TEXT = ['fg', 'fg-soft', 'fg-faint', 'accent', 'danger'];

test('the token blocks are read', () => {
  for (const t of [light, dark, chosenDark]) for (const k of ['bg', 'bg-soft', ...TEXT]) assert.match(t[k] ?? '', /^#[0-9a-f]{6}$/i, k);
});

for (const [name, t] of [['light', light], ['dark', dark]]) {
  test(`${name}: text colours read at 4.5:1 on the page and the sidebars`, () => {
    for (const k of TEXT) for (const bg of ['bg', 'bg-soft']) {
      assert.ok(contrast(t[k], t[bg]) >= 4.5, `--${k} ${t[k]} on --${bg} ${t[bg]}: ${contrast(t[k], t[bg]).toFixed(2)}:1`);
    }
    assert.ok(contrast(t['warn-fg'], t['warn-bg']) >= 4.5, 'waiting tasks');
    assert.ok(contrast(t.accent, t['accent-soft']) >= 4.5, 'accent on its soft tint');
  });
}

test('dark chosen in notd is the same as dark from the system', () => {
  assert.deepEqual(chosenDark, dark);
});
