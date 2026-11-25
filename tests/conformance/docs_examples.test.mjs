// Runs every request/response example in the protocol docs against a fresh
// server, one server (and database) per page, examples in page order.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, startServer, extractExamples, mismatch, readText } from './harness.mjs';

const dir = join(ROOT, 'docs/content/docs/protocol');
const pages = readdirSync(dir).filter((f) => f.endsWith('.md')).sort();

for (const page of pages) {
  const examples = extractExamples(readText(join(dir, page)), page);
  if (examples.length === 0) continue;
  test(`protocol examples in ${page}`, async (t) => {
    const server = await startServer();
    try {
      for (const ex of examples) {
        await t.test(`${page}:${ex.line} ${ex.method} ${ex.path}`, async () => {
          const res = await server.request(ex.method, ex.path, ex.method === 'POST' ? (ex.body ?? {}) : undefined);
          assert.equal(res.status, ex.status, `status for ${ex.method} ${ex.path}; body: ${JSON.stringify(res.body)}`);
          if (ex.expected !== undefined) {
            const diff = mismatch(ex.expected, res.body);
            assert.equal(diff, null, `${diff}\nactual body: ${JSON.stringify(res.body)}`);
          }
        });
      }
    } finally {
      await server.stop();
    }
  });
}
