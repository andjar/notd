// Uploading and serving files over HTTP.

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { startServer } from './harness.mjs';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');
let server;
before(async () => { server = await startServer(); });
after(async () => { await server?.stop(); });

function upload(bytes, name, type = 'application/octet-stream') {
  const form = new FormData();
  form.append('file', new Blob([bytes], { type }), name);
  return server.request('POST', '/api/v1/files', form);
}

test('an uploaded image comes back as a file node and is served as it was', async () => {
  const res = await upload(PNG, 'dot.png', 'image/png');
  assert.equal(res.status, 200, JSON.stringify(res.body));
  assert.equal(res.body.kind, 'file');
  assert.equal(res.body.sha256, createHash('sha256').update(PNG).digest('hex'));
  const file = await server.get('/' + res.body.url);
  assert.equal(file.status, 200);
  assert.equal(file.headers.get('content-type'), 'image/png');
  assert.equal(file.headers.get('x-content-type-options'), 'nosniff');
  assert.match(file.headers.get('content-disposition'), /^inline; filename\*=UTF-8''dot\.png$/);
  assert.deepEqual(file.body, PNG);
  const again = await server.get('/' + res.body.url, { 'If-None-Match': `"${res.body.sha256}"` });
  assert.equal(again.status, 304);
});

test('the same content uploaded twice is one file', async () => {
  const a = await upload('same bytes', 'a.txt');
  const b = await upload('same bytes', 'b.txt');
  assert.equal(a.body.id, b.body.id);
});

test('files that are not inline types are downloads, and SVG is sandboxed', async () => {
  const svg = await upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg');
  assert.equal(svg.status, 200, JSON.stringify(svg.body));
  const file = await server.get('/' + svg.body.url);
  assert.match(file.headers.get('content-disposition'), /^attachment/);
  assert.match(file.headers.get('content-security-policy'), /sandbox/);
});

test('wrong types and missing files are refused', async () => {
  assert.equal((await upload('MZ', 'tool.exe')).body.error.code, 'bad_type');
  assert.equal((await upload('not a png', 'fake.png')).body.error.code, 'bad_type');
  const none = await server.request('POST', '/api/v1/files', new FormData());
  assert.equal(none.body.error.code, 'bad_request');
  const missing = await server.get('/api/v1/file?id=01900000-0000-7000-8000-000000000000');
  assert.equal(missing.status, 404);
});
