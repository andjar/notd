import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { countLines, pluginImports, measure, LIMITS } from './budget.mjs';

test('countLines ignores blank lines', () => {
  assert.equal(countLines('a\n\n  \nb\n'), 2);
});

test('pluginImports finds static imports and requires of plugins/', () => {
  assert.deepEqual(pluginImports("import x from '../plugins/tasks/main.js';\nconst ok = 'plugins';\nrequire __DIR__ . '/../plugins/git/x.php';"), [1, 3]);
  assert.deepEqual(pluginImports("const dir = root + '/plugins/';"), []);
});

test('measure counts the core, skips vendor, and reports overruns', () => {
  const root = mkdtempSync(join(tmpdir(), 'notd-budget-'));
  try {
    mkdirSync(join(root, 'server'));
    mkdirSync(join(root, 'client/vendor'), { recursive: true });
    writeFileSync(join(root, 'server/a.php'), '<?php\n\necho 1;\n');
    writeFileSync(join(root, 'index.php'), '<?php\n');
    writeFileSync(join(root, 'client/app.js'), "import t from '/plugins/tasks/x.js';\n");
    writeFileSync(join(root, 'client/vendor/big.js'), 'x\n'.repeat(10000));
    writeFileSync(join(root, 'client/app.css'), 'a{}\n'.repeat(LIMITS[2].limit + 1));
    const { rows, violations } = measure(root);
    assert.deepEqual(rows.map((r) => [r.name, r.files, r.lines, r.ok]), [
      ['PHP', 2, 3, true], ['JavaScript', 1, 1, true], ['CSS', 1, LIMITS[2].limit + 1, false],
    ]);
    assert.deepEqual(violations, ['client/app.js:1 imports from plugins/']);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
