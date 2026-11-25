// The plugin host over HTTP, with the test plugins in tests/fixtures/plugins.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { join } from 'node:path';
import { startServer, ROOT } from './harness.mjs';

let server;
before(async () => { server = await startServer({ env: { NOTD_PLUGIN_DIRS: join(ROOT, 'tests/fixtures/plugins') } }); });
after(async () => { await server?.stop(); });

const byName = (list) => Object.fromEntries(list.map((p) => [p.name, p]));

test('plugins are listed with what to load, and problems are reported', async () => {
  const res = await server.get('/api/v1/plugins');
  assert.equal(res.status, 200);
  assert.equal(res.body.api, 1);
  const p = byName(res.body.plugins);
  assert.deepEqual(Object.keys(p).sort(), ['badserver', 'broken', 'hello', 'oldapi']);
  assert.equal(p.hello.enabled, true);
  assert.equal(p.hello.browser, 'plugins/hello/main.js');
  assert.deepEqual(p.hello.styles, ['plugins/hello/hello.css']);
  assert.match(p.oldapi.problem, /plugin API 0/);
  assert.match(p.badserver.problem, /broken on purpose/);
  assert.equal(p.hello.problem, null);
});

test('plugin files are served, but never PHP, plugin.json or anything outside the folder', async () => {
  const js = await server.get('/plugins/hello/main.js');
  assert.equal(js.status, 200);
  assert.match(js.headers.get('content-type'), /text\/javascript/);
  assert.equal((await server.get('/plugins/hello/lib/greeting.js')).status, 200);
  for (const bad of ['/plugins/hello/server.php', '/plugins/hello/plugin.json', '/plugins/hello/..%2fbroken/main.js', '/plugins/nope/main.js']) {
    assert.equal((await server.get(bad)).status, 404, bad);
  }
});

test('server code of plugins runs, except in safe mode', async () => {
  const page = '01900000-0000-7000-8000-0000000000a1';
  await server.post('/api/v1/ops', { ops: [{ op: 'create', kind: 'page', id: page, title: 'P' }] });
  await server.post('/api/v1/ops', { ops: [{ op: 'create', parent: page, text: 'x' }] }, { 'X-Notd-Safe': '1' });
  const res = await server.post('/api/v1/ops', { ops: [{ op: 'create', parent: page, text: 'y' }] });
  assert.equal(res.status, 200);
  // two changes counted (the page, the block "y"); the safe-mode block was not
  const { execFileSync } = await import('node:child_process');
  const count = execFileSync('php', ['-r', `echo (new PDO('sqlite:${server.dataDir}/notd.sqlite'))->query("SELECT value FROM meta WHERE key = 'hello_count'")->fetchColumn();`]).toString();
  assert.equal(count, '2');
  assert.equal((await server.get('/api/v1/plugins', { 'X-Notd-Safe': '1' })).body.safe, true);
});

test('a plugin can be switched off and on', async () => {
  let res = await server.post('/api/v1/plugins', { name: 'hello', enabled: false });
  assert.equal(byName(res.body.plugins).hello.enabled, false);
  res = await server.post('/api/v1/plugins', { name: 'hello', enabled: true });
  assert.equal(byName(res.body.plugins).hello.enabled, true);
  assert.equal((await server.post('/api/v1/plugins', { name: 'nope', enabled: true })).status, 404);
  assert.equal((await server.post('/api/v1/plugins', { name: 'hello' })).status, 400);
});

test('a plugin indexer adds fields, and its filter answers queries', async () => {
  const page = '01900000-0000-7000-8000-0000000000b1';
  await server.post('/api/v1/ops', { ops: [
    { op: 'create', kind: 'page', id: page, title: 'Urgent' },
    { op: 'create', parent: page, text: 'call the plumber !!' },
    { op: 'create', parent: page, text: 'water the plants' },
  ] });
  const urgent = await server.get('/api/v1/query?q=' + encodeURIComponent('urgent:yes page:urgent'));
  assert.deepEqual(urgent.body.nodes.map((n) => n.text), ['call the plumber !!']);
  assert.deepEqual(urgent.body.nodes[0].fields, { urgent: ['yes'] });
  const calm = await server.get('/api/v1/query?q=' + encodeURIComponent('urgent:no page:urgent'));
  assert.deepEqual(calm.body.nodes.map((n) => n.text), ['water the plants']);
  const safe = await server.get('/api/v1/query?q=' + encodeURIComponent('urgent:yes page:urgent'), { 'X-Notd-Safe': '1' });
  assert.deepEqual(safe.body.nodes.map((n) => n.text), ['call the plumber !!'], 'in safe mode "urgent:yes" is an ordinary field filter, and the indexed field is still there');
});
