// Conformance harness: starts notd under PHP's built-in server on a free port
// with a throw-away data folder, and offers small HTTP helpers.
//
//   const server = await startServer();
//   const res = await server.get('/api/v1/ping');   // { status, body }
//   await server.stop();
//
// NOTD_PHP picks the PHP binary (default: php).

import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

function freePort() {
  return new Promise((ok, fail) => {
    const s = createServer();
    s.once('error', fail);
    s.listen(0, '127.0.0.1', () => {
      const { port } = s.address();
      s.close(() => ok(port));
    });
  });
}

export async function startServer({ env = {} } = {}) {
  const dataDir = mkdtempSync(join(tmpdir(), 'notd-conformance-'));
  const port = await freePort();
  const base = `http://127.0.0.1:${port}`;
  const log = [];
  const child = spawn(process.env.NOTD_PHP || 'php', ['-S', `127.0.0.1:${port}`, 'index.php'], {
    cwd: ROOT,
    env: { ...process.env, NOTD_DATA_DIR: dataDir, ...env },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  child.stdout.on('data', (d) => log.push(String(d)));
  child.stderr.on('data', (d) => log.push(String(d)));

  async function request(method, path, body, headers = {}) {
    const init = { method, headers: { ...headers } };
    if (body !== undefined) {
      init.body = typeof body === 'string' ? body : JSON.stringify(body);
      init.headers['Content-Type'] ??= 'application/json';
    }
    const res = await fetch(base + path, init);
    const text = await res.text();
    let parsed = text;
    try { parsed = text === '' ? null : JSON.parse(text); } catch { /* not JSON: keep the text */ }
    return { status: res.status, headers: res.headers, body: parsed };
  }

  const server = {
    url: base,
    dataDir,
    log: () => log.join(''),
    request,
    get: (path, headers) => request('GET', path, undefined, headers),
    post: (path, body, headers) => request('POST', path, body ?? {}, headers),
    async stop() {
      child.kill();
      await new Promise((ok) => (child.exitCode !== null ? ok() : child.once('exit', ok)));
      rmSync(dataDir, { recursive: true, force: true });
    },
  };

  for (let i = 0; i < 100; i++) {
    if (child.exitCode !== null) break;
    try {
      const res = await request('GET', '/api/v1/ping');
      if (res.status === 200) return server;
    } catch { /* not listening yet */ }
    await new Promise((ok) => setTimeout(ok, 50));
  }
  await server.stop();
  throw new Error(`notd did not start on ${base}:\n${log.join('')}`);
}

/**
 * Does `actual` match `expected`? Objects match when every expected key is
 * present and matches (extra keys are allowed); arrays must have the same
 * length; the string "…" matches any value. Returns null when it matches,
 * otherwise a description of the first difference.
 */
export function mismatch(expected, actual, path = '$') {
  if (expected === '…') return null;
  if (Array.isArray(expected)) {
    if (!Array.isArray(actual)) return `${path}: expected an array, got ${JSON.stringify(actual)}`;
    if (expected.length !== actual.length) return `${path}: expected ${expected.length} items, got ${actual.length}`;
    for (let i = 0; i < expected.length; i++) {
      const m = mismatch(expected[i], actual[i], `${path}[${i}]`);
      if (m) return m;
    }
    return null;
  }
  if (expected !== null && typeof expected === 'object') {
    if (actual === null || typeof actual !== 'object' || Array.isArray(actual)) {
      return `${path}: expected an object, got ${JSON.stringify(actual)}`;
    }
    for (const key of Object.keys(expected)) {
      if (!(key in actual)) return `${path}.${key}: missing`;
      const m = mismatch(expected[key], actual[key], `${path}.${key}`);
      if (m) return m;
    }
    return null;
  }
  return Object.is(expected, actual) ? null : `${path}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`;
}

/**
 * Extracts request/response examples from a protocol page. An example is a
 * ```request block (first line "METHOD /path", then an optional JSON body)
 * followed by a ```response block (first line the status, then an optional
 * JSON body that the actual response must match, see mismatch()).
 */
export function extractExamples(markdown, file = 'page') {
  const blocks = [...markdown.matchAll(/^```(request|response)[^\n]*\n([\s\S]*?)^```/gm)];
  const examples = [];
  for (let i = 0; i < blocks.length; i++) {
    const [, kind, content] = blocks[i];
    const line = markdown.slice(0, blocks[i].index).split('\n').length;
    if (kind !== 'request') throw new Error(`${file}:${line}: a response block without a request before it`);
    const next = blocks[i + 1];
    if (!next || next[1] !== 'response') throw new Error(`${file}:${line}: a request block without a response after it`);
    i++;
    const [reqLine, ...reqBody] = content.split('\n');
    const m = reqLine.trim().match(/^(GET|POST) (\/\S*)$/);
    if (!m) throw new Error(`${file}:${line}: the first line must be "GET /path" or "POST /path", not "${reqLine}"`);
    const [statusLine, ...resBody] = next[2].split('\n');
    const status = Number(statusLine.trim());
    if (!Number.isInteger(status)) throw new Error(`${file}:${line}: the response must start with a status code`);
    const parse = (lines, what) => {
      const text = lines.join('\n').trim();
      if (text === '') return undefined;
      try { return JSON.parse(text); } catch (e) { throw new Error(`${file}:${line}: ${what} is not valid JSON: ${e.message}`); }
    };
    examples.push({
      line, method: m[1], path: m[2],
      body: parse(reqBody, 'the request body'),
      status, expected: parse(resBody, 'the response body'),
    });
  }
  return examples;
}

export function readText(path) {
  return readFileSync(path, 'utf8');
}
