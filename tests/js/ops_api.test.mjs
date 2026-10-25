// API tests for /api/v1/ops.php against a running server.
// Usage: NOTD_URL=http://127.0.0.1:8765/ node tests/js/ops_api.test.mjs
import { keyBetween } from '../../assets/js/app/fractional-index.js';

const BASE = (process.env.NOTD_URL || 'http://127.0.0.1:8765/') + 'api/v1/';
let failures = 0, passes = 0;
const ok = (cond, msg) => { if (cond) passes++; else { failures++; console.log('  FAIL:', msg); } };
const eq = (a, b, msg) => ok(JSON.stringify(a) === JSON.stringify(b), `${msg}\n    expected ${JSON.stringify(b)}\n    got      ${JSON.stringify(a)}`);

async function raw(path, body) {
  const r = await fetch(BASE + path, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json() };
}
async function ops(list) {
  const r = await raw('ops.php', { ops: list });
  if (r.status !== 200) throw new Error(`ops failed ${r.status}: ${JSON.stringify(r.json)}`);
  return r.json.data;
}
let n = 0;
const opId = () => `t${Date.now().toString(36)}-${n++}`;
const id = () => Math.random().toString(16).slice(2, 10) + Math.random().toString(16).slice(2, 10);

async function page(name) {
  const r = await raw(`pages.php?name=${encodeURIComponent(name + '-' + Date.now() + '-' + n++)}`);
  return r.json.data.id;
}
async function tree(pageId) {
  const notes = (await raw(`notes.php?page_id=${pageId}`)).json.data;
  const byParent = {};
  for (const x of notes) (byParent[x.parent_note_id ?? ''] ??= []).push(x);
  for (const k in byParent) byParent[k].sort((a, b) => a.sort_key < b.sort_key ? -1 : a.sort_key > b.sort_key ? 1 : a.id < b.id ? -1 : 1);
  const out = [];
  const walk = (p, d) => (byParent[p] || []).forEach(x => { out.push('  '.repeat(d) + x.content); walk(x.id, d + 1); });
  walk('', 0);
  return { lines: out, notes };
}
// Creates notes from an indented spec like ['A', ' a1', ' a2', 'B'] (one space per level)
async function seed(pageId, spec) {
  const ids = {}, stack = [], lastKey = {}, lastId = {};
  const list = [];
  for (const line of spec) {
    const depth = line.length - line.trimStart().length, name = line.trim();
    stack.length = depth;
    const parent = depth ? stack[depth - 1] : null;
    const k = keyBetween(lastKey[parent] ?? null, null);
    const nid = id();
    list.push({ op_id: opId(), type: 'create', id: nid, page_id: pageId, parent_id: parent, after: lastId[parent] ?? null, sort_key: k, content: name });
    lastKey[parent] = k; lastId[parent] = nid; ids[name] = nid; stack[depth] = nid;
  }
  await ops(list);
  return ids;
}

async function test(name, fn) {
  const before = failures;
  try { await fn(); } catch (e) { failures++; console.log('  ERROR:', e.message); }
  console.log(`${failures === before ? 'ok  ' : 'FAIL'} ${name}`);
}

await test('create + nested create in one batch, ordered by sort_key', async () => {
  const p = await page('t-create');
  await seed(p, ['A', ' a1', ' a2', 'B', 'C']);
  eq((await tree(p)).lines, ['A', '  a1', '  a2', 'B', 'C'], 'tree');
});

await test('move with stale/missing sort_key hint: server computes key from `after`', async () => {
  const p = await page('t-move');
  const ids = await seed(p, ['A', 'B', 'C', 'D']);
  await ops([{ op_id: opId(), type: 'move', id: ids.D, parent_id: null, after: ids.A }]);
  eq((await tree(p)).lines, ['A', 'D', 'B', 'C'], 'D after A');
  await ops([{ op_id: opId(), type: 'move', id: ids.C, parent_id: null, after: null, sort_key: 'zzz' }]);
  eq((await tree(p)).lines, ['C', 'A', 'D', 'B'], 'C first despite bogus hint');
});

await test('indent then outdent a middle child keeps every other note in place', async () => {
  const p = await page('t-indent');
  const ids = await seed(p, ['A', ' a1', ' a2', ' a3', 'B', 'C']);
  await ops([{ op_id: opId(), type: 'move', id: ids.a2, parent_id: null, after: ids.A }]); // outdent
  eq((await tree(p)).lines, ['A', '  a1', '  a3', 'a2', 'B', 'C'], 'outdent lands right after A');
  await ops([{ op_id: opId(), type: 'move', id: ids.C, parent_id: ids.B, after: null }]); // indent
  eq((await tree(p)).lines, ['A', '  a1', '  a3', 'a2', 'B', '  C'], 'indent C under B, A subtree untouched');
});

await test('two drags in a row (the old corruption repro)', async () => {
  const p = await page('t-drag');
  const ids = await seed(p, ['A', ' a1', ' a2', ' a3', 'B', 'C', 'D']);
  await ops([{ op_id: opId(), type: 'move', id: ids.a3, parent_id: ids.A, after: null }]);
  await ops([{ op_id: opId(), type: 'move', id: ids.C, parent_id: null, after: ids.A }]);
  eq((await tree(p)).lines, ['A', '  a3', '  a1', '  a2', 'C', 'B', 'D'], 'tree');
});

await test('cycle and self-parent moves are rejected (skipped with warning)', async () => {
  const p = await page('t-cycle');
  const ids = await seed(p, ['A', ' a1', '  x', 'B']);
  const r = await ops([{ op_id: opId(), type: 'move', id: ids.A, parent_id: ids.x, after: null },
                       { op_id: opId(), type: 'move', id: ids.B, parent_id: ids.B, after: null }]);
  eq(r.warnings.map(w => w.code), ['cycle', 'cycle'], 'warnings');
  eq((await tree(p)).lines, ['A', '  a1', '    x', 'B'], 'unchanged');
});

await test('cross-page parent is rejected', async () => {
  const p1 = await page('t-x1'), p2 = await page('t-x2');
  const a = await seed(p1, ['A']), b = await seed(p2, ['B']);
  const r = await ops([{ op_id: opId(), type: 'move', id: b.B, parent_id: a.A, after: null }]);
  eq(r.warnings.map(w => w.code), ['parent_missing'], 'warning');
});

await test('malformed op rolls back the whole batch (atomic)', async () => {
  const p = await page('t-atomic');
  const ids = await seed(p, ['A', 'B']);
  const r = await raw('ops.php', { ops: [
    { op_id: opId(), type: 'edit', id: ids.A, content: 'A edited' },
    { op_id: opId(), type: 'frobnicate', id: ids.B },
  ] });
  eq(r.status, 400, 'status 400');
  eq((await tree(p)).lines, ['A', 'B'], 'edit not applied');
});

await test('ops on vanished notes are skipped but the rest of the batch applies', async () => {
  const p = await page('t-vanish');
  const ids = await seed(p, ['A', 'B']);
  const r = await ops([
    { op_id: opId(), type: 'edit', id: 'doesnotexist', content: 'x' },
    { op_id: opId(), type: 'edit', id: ids.B, content: 'B edited' },
  ]);
  eq(r.warnings.map(w => w.code), ['missing'], 'warning for missing note');
  eq((await tree(p)).lines, ['A', 'B edited'], 'other edit applied');
});

await test('retrying a batch is idempotent (same op_ids)', async () => {
  const p = await page('t-idem');
  const batch = [{ op_id: opId(), type: 'create', id: id(), page_id: p, parent_id: null, after: null, sort_key: 'a0', content: 'once' }];
  await ops(batch);
  const r = await ops(batch);
  eq(r.results.map(x => x.status), ['duplicate'], 'second is duplicate');
  eq((await tree(p)).lines, ['once'], 'only one note');
});

await test('delete removes the subtree and reports all ids', async () => {
  const p = await page('t-del');
  const ids = await seed(p, ['A', ' a1', '  x', 'B']);
  const r = await ops([{ op_id: opId(), type: 'delete', id: ids.A }]);
  eq(r.deleted.sort(), [ids.A, ids.a1, ids.x].sort(), 'deleted ids');
  eq((await tree(p)).lines, ['B'], 'tree');
});

await test('client can create a child under a parent created earlier in the same batch, then edit and move it', async () => {
  const p = await page('t-chain');
  const P = id(), c = id();
  await ops([
    { op_id: opId(), type: 'create', id: P, page_id: p, parent_id: null, after: null, sort_key: 'a0', content: 'P' },
    { op_id: opId(), type: 'create', id: c, page_id: p, parent_id: P, after: null, sort_key: 'a0', content: '' },
    { op_id: opId(), type: 'edit', id: c, content: 'child' },
    { op_id: opId(), type: 'move', id: c, parent_id: null, after: P, sort_key: 'a1' },
  ]);
  eq((await tree(p)).lines, ['P', 'child'], 'tree');
});

await test('tied sort keys (two tabs) are spread out when inserting between them', async () => {
  const p = await page('t-tie');
  const A = id(), B = id(), C = id();
  await ops([
    { op_id: opId(), type: 'create', id: A, page_id: p, parent_id: null, after: null, sort_key: 'a0', content: 'A' },
    // a second tab that did not know about A also used 'a0'
    { op_id: opId(), type: 'create', id: B, page_id: p, parent_id: null, after: null, sort_key: 'a1', content: 'B' },
  ]);
  // force a tie directly through a move whose hint equals A's key
  await ops([{ op_id: opId(), type: 'move', id: B, parent_id: null, after: A, sort_key: 'a0' }]);
  await ops([{ op_id: opId(), type: 'create', id: C, page_id: p, parent_id: null, after: A, content: 'C' }]);
  const t = await tree(p);
  eq(t.lines, ['A', 'C', 'B'], 'C between A and B');
  const keys = t.notes.map(x => x.sort_key);
  ok(new Set(keys).size === keys.length, 'all keys distinct');
});

await test('properties are re-indexed from content; removed tags disappear', async () => {
  const p = await page('t-props');
  const ids = await seed(p, ['task {tag::alpha}']);
  let note = (await raw(`notes.php?id=${ids['task {tag::alpha}']}`)).json.data;
  ok(note.properties.tag?.[0]?.value === 'alpha', 'tag indexed');
  await ops([{ op_id: opId(), type: 'edit', id: note.id, content: 'task without tag' }]);
  note = (await raw(`notes.php?id=${note.id}`)).json.data;
  ok(!note.properties.tag, 'stale tag removed');
});

await test('concurrent batches serialize (no lost writes, no 5xx)', async () => {
  const p = await page('t-conc');
  const ids = await seed(p, ['A']);
  const results = await Promise.all(Array.from({ length: 12 }, (_, i) => raw('ops.php', { ops: [
    { op_id: opId(), type: 'create', id: id(), page_id: p, parent_id: ids.A, after: null, content: 'c' + i },
  ] })));
  eq(results.map(r => r.status).filter(s => s !== 200), [], 'all 200');
  eq((await tree(p)).notes.length, 13, '13 notes');
});

await test('notes.php rejects writes', async () => {
  const r = await raw('notes.php', { action: 'batch', operations: [] });
  eq(r.status, 405, '405');
});

console.log(`\n${passes} assertions passed, ${failures} failed`);
process.exit(failures ? 1 : 0);
