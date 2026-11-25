// Tests for the harness itself and for the HTTP basics every endpoint shares.

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, mismatch, extractExamples } from './harness.mjs';

test('mismatch: objects allow extra keys, arrays need the same length, … matches anything', () => {
  assert.equal(mismatch({ a: 1 }, { a: 1, b: 2 }), null);
  assert.equal(mismatch({ a: '…' }, { a: [1, 2] }), null);
  assert.equal(mismatch([1, { b: 2 }], [1, { b: 2, c: 3 }]), null);
  assert.equal(mismatch({ a: 1 }, { a: 2 }), '$.a: expected 1, got 2');
  assert.equal(mismatch({ a: 1 }, {}), '$.a: missing');
  assert.equal(mismatch([1], [1, 2]), '$: expected 1 items, got 2');
  assert.equal(mismatch({ a: { b: [1] } }, { a: { b: ['1'] } }), '$.a.b[0]: expected 1, got "1"');
});

test('extractExamples reads request and response pairs', () => {
  const md = [
    'Text', '```request', 'POST /api/v1/ops', '{"ops": []}', '```',
    '```response', '200', '{"applied": 0}', '```',
    '```request', 'GET /api/v1/ping', '```', '```response', '200', '```',
  ].join('\n');
  const ex = extractExamples(md);
  assert.equal(ex.length, 2);
  assert.deepEqual(ex[0], { line: 2, method: 'POST', path: '/api/v1/ops', body: { ops: [] }, status: 200, expected: { applied: 0 } });
  assert.equal(ex[1].expected, undefined);
});

test('extractExamples refuses a request without a response', () => {
  assert.throws(() => extractExamples('```request\nGET /x\n```\n'), /without a response/);
  assert.throws(() => extractExamples('```request\nPUT /x\n```\n```response\n200\n```\n'), /GET \/path/);
});

let server;
before(async () => { server = await startServer(); });
after(async () => { await server?.stop(); });

test('ping answers with the protocol version', async () => {
  const res = await server.get('/api/v1/ping');
  assert.equal(res.status, 200);
  assert.equal(res.body.name, 'notd');
  assert.equal(res.body.protocol, 1);
  assert.match(res.headers.get('content-type'), /application\/json/);
});

test('an unknown endpoint is a JSON 404', async () => {
  const res = await server.get('/api/v1/nope');
  assert.equal(res.status, 404);
  assert.equal(res.body.error.code, 'not_found');
});

test('a wrong method is a JSON 405', async () => {
  const res = await server.post('/api/v1/ping', {});
  assert.equal(res.status, 405);
  assert.equal(res.body.error.code, 'method_not_allowed');
});

test('server files and the data folder are never served as files', async () => {
  for (const path of ['/server/http.php', '/index.php', '/data/notd.sqlite', '/client/../server/http.php', '/README.md']) {
    const res = await server.get(path);
    assert.equal(res.status, 404, path);
    assert.equal(res.body?.error?.code, 'not_found', path);
  }
});

test('the app page is sent as it is, with a CSP that allows only scripts from notd itself', async () => {
  const { readFileSync } = await import('node:fs');
  const res = await fetch(server.url + '/');
  assert.equal(res.status, 200);
  assert.match(res.headers.get('content-type'), /text\/html/);
  assert.equal(await res.text(), readFileSync(new URL('../../client/index.html', import.meta.url), 'utf8'));
  const csp = Object.fromEntries(res.headers.get('content-security-policy').split(';')
    .map((d) => d.trim().split(/\s+/)).map(([name, ...values]) => [name, values.join(' ')]));
  assert.equal(csp['script-src'], "'self'");            // no hashes, no 'unsafe-inline', no eval
  assert.equal(csp['default-src'], "'self'");
  assert.equal(csp['object-src'], "'none'");
  assert.equal(csp['frame-ancestors'], "'none'");
});
