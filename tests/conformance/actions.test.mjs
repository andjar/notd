// Actions over HTTP: they run inside the change, and not in safe mode.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { join } from 'node:path';
import { startServer, ROOT } from './harness.mjs';

let server;
before(async () => { server = await startServer({ env: { NOTD_ACTIONS_DIR: join(ROOT, 'tests/fixtures/actions') } }); });
after(async () => { await server?.stop(); });

const P = '01900000-0000-7000-8000-0000000000a1';

test('an action changes the block in the same request, and the feed shows the result', async () => {
  await server.post('/api/v1/ops', { ops: [{ op: 'create', kind: 'page', id: P, title: 'Films' }] });
  const before = (await server.get('/api/v1/changes?since=999999')).body.next;
  const res = await server.post('/api/v1/ops', { ops: [{ op: 'create', parent: P, text: 'Dune {type::movie}' }] });
  const id = res.body.results[0].id;
  const node = (await server.get('/api/v1/node?id=' + id)).body.node;
  assert.equal(node.text, 'Dune {type::movie} #needs-rating');
  const feed = (await server.get('/api/v1/changes?since=' + before)).body.changes;
  assert.equal(feed.at(-1).node.text, 'Dune {type::movie} #needs-rating');
});

test('in safe mode actions do not run', async () => {
  const res = await server.post('/api/v1/ops', { ops: [{ op: 'create', parent: P, text: 'Heat {type::movie}' }] }, { 'X-Notd-Safe': '1' });
  const node = (await server.get('/api/v1/node?id=' + res.body.results[0].id)).body.node;
  assert.equal(node.text, 'Heat {type::movie}');
});
