// Who may talk to the server: local only without a password; login cookie with one;
// no POSTs from other sites.

import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { startServer } from './harness.mjs';

const REMOTE = { 'X-Forwarded-For': '203.0.113.9' }; // what a proxied request from another device looks like

describe('without a password', () => {
  let server;
  before(async () => { server = await startServer(); });
  after(async () => { await server?.stop(); });

  test('this computer is let in', async () => {
    const ping = await server.get('/api/v1/ping');
    assert.equal(ping.body.passwordSet, false);
    assert.equal(ping.body.authenticated, true);
    assert.equal((await server.get('/api/v1/pages')).status, 200);
  });

  test('other devices are refused until a password is set', async () => {
    const res = await server.get('/api/v1/pages', REMOTE);
    assert.equal(res.status, 403);
    assert.equal(res.body.error.code, 'password_required');
    assert.equal((await server.get('/api/v1/ping', REMOTE)).status, 200, 'ping stays open');
  });

  test('POSTs from another site are refused, from the same origin allowed', async () => {
    const cross = await server.post('/api/v1/ops', { ops: [] }, { Origin: 'https://evil.example' });
    assert.equal(cross.body.error.code, 'cross_origin');
    const fetchMeta = await server.post('/api/v1/ops', { ops: [] }, { 'Sec-Fetch-Site': 'cross-site' });
    assert.equal(fetchMeta.body.error.code, 'cross_origin');
    const same = await server.post('/api/v1/ops', { ops: [] }, { Origin: server.url });
    assert.equal(same.status, 200);
  });
});

describe('with a password', () => {
  let server;
  const hash = execFileSync('php', ['-r', 'echo password_hash("correct horse", PASSWORD_DEFAULT);']).toString();
  before(async () => { server = await startServer({ env: { NOTD_PASSWORD_HASH: hash } }); });
  after(async () => { await server?.stop(); });

  test('every endpoint but ping and login needs the cookie, even locally', async () => {
    const res = await server.get('/api/v1/pages');
    assert.equal(res.status, 401);
    assert.equal(res.body.error.code, 'login_required');
    assert.equal((await server.get('/api/v1/ping')).body.passwordSet, true);
  });

  test('logging in sets a cookie that opens the door, logging out closes it', async () => {
    const wrong = await server.post('/api/v1/login', { password: 'nope' }, REMOTE);
    assert.equal(wrong.body.error.code, 'wrong_password');
    const ok = await server.post('/api/v1/login', { password: 'correct horse' }, REMOTE);
    assert.equal(ok.status, 200);
    const cookieHeader = ok.headers.get('set-cookie');
    assert.match(cookieHeader, /notd_login=\d+\.[0-9a-f]{64}; .*HttpOnly/i);
    assert.match(cookieHeader, /SameSite=Lax/i);
    const cookie = cookieHeader.split(';')[0];
    assert.equal((await server.get('/api/v1/pages', { ...REMOTE, Cookie: cookie })).status, 200);
    const forged = cookie.replace(/.$/, (c) => (c === '0' ? '1' : '0'));
    assert.equal((await server.get('/api/v1/pages', { ...REMOTE, Cookie: forged })).status, 401);
    const out = await server.post('/api/v1/logout', {}, { ...REMOTE, Cookie: cookie });
    assert.match(out.headers.get('set-cookie'), /notd_login=deleted|notd_login=;/);
  });

  test('five wrong passwords lock the address for a while', async () => {
    const other = { 'X-Forwarded-For': '198.51.100.7' };
    for (let i = 0; i < 5; i++) await server.post('/api/v1/login', { password: 'guess' + i }, other);
    const locked = await server.post('/api/v1/login', { password: 'correct horse' }, other);
    assert.equal(locked.status, 429);
    assert.equal(locked.body.error.code, 'too_many_attempts');
  });
});
