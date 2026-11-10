// Unit tests for the client model and outbox (no browser, no server).
// Usage: node tests/js/model.test.mjs
import { OutlineModel } from '../../assets/js/app/outline-model.js';
import { Outbox } from '../../assets/js/app/outbox.js';

let failures = 0;
const eq = (a, b, msg) => {
  if (JSON.stringify(a) !== JSON.stringify(b)) { failures++; console.log(`FAIL ${msg}\n  expected ${JSON.stringify(b)}\n  got      ${JSON.stringify(a)}`); }
};
const lines = (m) => m.list().map(n => '  '.repeat(m.depth(n.id)) + n.content);

// --- model
{
  const m = new OutlineModel();
  m.load(1, [
    { id: 'A', parent_note_id: null, sort_key: 'a0', content: 'A' },
    { id: 'a1', parent_note_id: 'A', sort_key: 'a0', content: 'a1' },
    { id: 'a2', parent_note_id: 'A', sort_key: 'a1', content: 'a2' },
    { id: 'B', parent_note_id: null, sort_key: 'a1', content: 'B' },
    { id: 7, parent_note_id: null, sort_key: 'a2', content: 'legacy numeric id' },
  ]);
  eq(lines(m), ['A', '  a1', '  a2', 'B', 'legacy numeric id'], 'load order');
  eq(m.prevSibling('B').id, 'A', 'prevSibling');
  eq(m.nextSibling('a1').id, 'a2', 'nextSibling');
  eq(m.get('7').id, '7', 'numeric ids normalized to strings');

  // indent B under A (last child)
  m.apply({ type: 'move', id: 'B', parent_id: 'A', sort_key: m.keyFor('A', 'a2', 'B') });
  eq(lines(m), ['A', '  a1', '  a2', '  B', 'legacy numeric id'], 'indent');
  // outdent a1 to right after A
  m.apply({ type: 'move', id: 'a1', parent_id: null, sort_key: m.keyFor(null, 'A', 'a1') });
  eq(lines(m), ['A', '  a2', '  B', 'a1', 'legacy numeric id'], 'outdent');
  // cycle refused
  eq(m.apply({ type: 'move', id: 'A', parent_id: 'B', sort_key: 'a0' }), null, 'cycle refused');
  // create + delete subtree
  m.apply({ type: 'create', id: 'n', parent_id: 'B', sort_key: 'a0', content: 'n' });
  eq(m.subtreeIds('A'), ['A', 'a2', 'B', 'n'], 'subtree');
  const r = m.apply({ type: 'delete', id: 'A' });
  eq(r.removed, ['A', 'a2', 'B', 'n'], 'delete removes subtree');
  eq(lines(m), ['a1', 'legacy numeric id'], 'after delete');
  // keyFor with tied neighbours does not throw
  m.load(1, [{ id: 'x', sort_key: 'a0', content: 'x' }, { id: 'y', sort_key: 'a0', content: 'y' }]);
  const k = m.keyFor(null, 'x');
  eq(typeof k, 'string', 'keyFor with ties returns a key');
  // server merge keeps local content when asked
  m.apply({ type: 'edit', id: 'x', content: 'local' });
  m.mergeServer({ id: 'x', parent_note_id: null, sort_key: 'a0', content: 'server' }, { keepContent: true });
  eq(m.get('x').content, 'local', 'keepContent');
  m.mergeServer({ id: 'x', parent_note_id: null, sort_key: 'Zz', content: 'server' });
  eq(lines(m)[0], 'server', 'merge moves and updates content');
}

// --- outbox
const memory = () => { const s = new Map(); return { getItem: k => s.get(k) ?? null, setItem: (k, v) => s.set(k, v), removeItem: k => s.delete(k), _s: s }; };
const response = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

{
  const sent = [];
  const storage = memory();
  const box = new Outbox({ storage, send: async (ops) => { sent.push(ops.map(o => o.type + ':' + o.id + (o.content ? '=' + o.content : ''))); return response(200, { status: 'success', data: { notes: [] } }); } });
  box.enqueue({ op_id: '1', type: 'create', id: 'n', content: '' });
  box.enqueue({ op_id: '2', type: 'edit', id: 'n', content: 'h' });
  box.enqueue({ op_id: '3', type: 'edit', id: 'n', content: 'hello' });
  box.enqueue({ op_id: '4', type: 'move', id: 'n', parent_id: null });
  box.enqueue({ op_id: '5', type: 'edit', id: 'n', content: 'hello!' });
  await box.flush();
  eq(sent, [['create:n=hello!', 'move:n']], 'edits folded into create, order kept');
  eq(storage._s.size, 0, 'storage cleared when empty');
}
{
  let fail = true; const sent = [];
  const box = new Outbox({ storage: memory(), send: async (ops) => { if (fail) throw new Error('offline'); sent.push(ops.length); return response(200, { status: 'success', data: {} }); } });
  box.enqueue({ op_id: '1', type: 'create', id: 'a' });
  await box.flush().catch(() => {});
  eq(box.size, 1, 'kept after network failure');
  fail = false;
  box.retryNow();
  await box.flush();
  eq([box.size, sent], [0, [1]], 'sent after recovery');
}
{
  const storage = memory();
  const box1 = new Outbox({ storage, send: async () => { throw new Error('offline'); } });
  box1.enqueue({ op_id: '1', type: 'create', id: 'a', content: 'survives reload' });
  await tick(5);
  const box2 = new Outbox({ storage, send: async () => response(200, { status: 'success', data: {} }) });
  eq(box2.pending().map(o => o.content), ['survives reload'], 'persisted across instances');
}
{
  let rejected = null;
  const box = new Outbox({ storage: memory(), send: async () => response(400, { status: 'error', message: 'bad op' }), onRejected: (e) => { rejected = e.message; } });
  box.enqueue({ op_id: '1', type: 'move', id: 'a' });
  await box.flush();
  eq([rejected, box.size], ['bad op', 0], '4xx drops batch and reports');
}

console.log(failures ? `${failures} failure(s)` : 'model + outbox: all tests passed');
process.exit(failures ? 1 : 0);
