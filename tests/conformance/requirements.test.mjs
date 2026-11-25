// A PHP without the extensions notd needs (phpdesktop's php.ini leaves mbstring,
// intl and fileinfo out) gets a clear answer naming them, from the API and from
// the tools, not an internal error. Run with `php -n`, which loads no php.ini and
// so none of the extensions that are built as loadable modules.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { startServer, freePort, PHP, ROOT } from './harness.mjs';

const NEEDED = ['pdo_sqlite', 'mbstring', 'intl', 'fileinfo'];
const probe = spawnSync(PHP, ['-n', '-r', `echo json_encode(array_values(array_filter(${JSON.stringify(NEEDED)}, fn($e) => !extension_loaded($e))));`], { encoding: 'utf8' });
const missing = JSON.parse(probe.stdout || '[]');
const skip = missing.length === 0 && 'this PHP has every needed extension built in, so php -n cannot leave one out';

test('the API names the PHP extensions that are missing', { skip }, async () => {
  const server = await startServer({ phpArgs: ['-n'] });
  try {
    const res = await server.get('/api/v1/ping');
    assert.equal(res.status, 500);
    assert.equal(res.body.error.code, 'php_extensions');
    for (const e of missing) assert.match(res.body.error.message, new RegExp(`extension=${e}\\b`));
    for (const e of NEEDED.filter((x) => !missing.includes(x))) assert.doesNotMatch(res.body.error.message, new RegExp(`\\b${e}\\b`));
    const page = await fetch(server.url + '/');        // the app page itself still loads, and shows the message
    assert.equal(page.status, 200);
  } finally {
    await server.stop();
  }
});

test('the tools name the PHP extensions that are missing', { skip }, () => {
  const run = spawnSync(PHP, ['-n', 'tools/verify.php'], { cwd: ROOT, encoding: 'utf8', env: { ...process.env, NOTD_DATA_DIR: '/nonexistent' } });
  assert.equal(run.status, 1);
  assert.match(run.stderr, /^notd: PHP lacks extensions notd needs: /);
  for (const e of missing) assert.match(run.stderr, new RegExp(`extension=${e}\\b`));
});

test('the tools and the test runner do nothing when a web server runs them', async () => {
  // phpdesktop's web server runs any .php file it is asked for; so does php -S without a router.
  const { mkdtempSync, readdirSync, rmSync } = await import('node:fs');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');
  const data = mkdtempSync(join(tmpdir(), 'notd-web-tools-'));
  const port = await freePort();
  const child = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', ROOT], { env: { ...process.env, NOTD_DATA_DIR: data }, stdio: 'ignore' });
  try {
    const scripts = [...readdirSync(join(ROOT, 'tools')).filter((f) => f.endsWith('.php')).map((f) => `tools/${f}`), 'tests/php/run.php'];
    assert.ok(scripts.length >= 8, scripts.join(', '));
    for (let i = 0; ; i++) {
      try { await fetch(`http://127.0.0.1:${port}/`); break; } catch { if (i > 100) throw new Error('php -S did not start'); }
      await new Promise((ok) => setTimeout(ok, 50));
    }
    for (const script of scripts) {
      const res = await fetch(`http://127.0.0.1:${port}/${script}`);
      assert.equal(await res.text(), 'Run this from the command line.\n', script);
    }
    assert.deepEqual(readdirSync(data), [], 'nothing was written to the data folder');
  } finally {
    child.kill();
    rmSync(data, { recursive: true, force: true });
  }
});
