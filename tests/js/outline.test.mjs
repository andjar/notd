// The client's model, outbox and outline controller, without a browser or server.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { OutlineModel } from '../../client/lib/model.js';
import { Outbox } from '../../client/lib/outbox.js';
import { Outline } from '../../client/lib/outline.js';

const P = 'page';
const lines = (m) => m.list().map((n) => '  '.repeat(m.depth(n.id)) + n.text);
const memory = () => { const s = new Map(); return { getItem: (k) => s.get(k) ?? null, setItem: (k, v) => s.set(k, v), removeItem: (k) => s.delete(k), s }; };
const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));
const micro = () => new Promise((r) => queueMicrotask(r));

function sample() {
  const m = new OutlineModel();
  m.load(P, [
    { id: 'A', parent: P, sort: 'a0', text: 'A' },
    { id: 'a1', parent: 'A', sort: 'a0', text: 'a1' },
    { id: 'a2', parent: 'A', sort: 'a1', text: 'a2' },
    { id: 'B', parent: P, sort: 'a1', text: 'B' },
    { id: 'orphan', parent: 'gone', sort: 'a2', text: 'orphan' },
  ]);
  return m;
}

test('model: load, order, siblings, orphans at the top', () => {
  const m = sample();
  assert.deepEqual(lines(m), ['A', '  a1', '  a2', 'B', 'orphan']);
  assert.equal(m.prevSibling('B').id, 'A');
  assert.equal(m.nextSibling('a1').id, 'a2');
  assert.deepEqual(m.subtreeIds('A'), ['A', 'a1', 'a2']);
});

test('model: moves, cycles refused, deletes take the subtree', () => {
  const m = sample();
  m.apply({ op: 'move', id: 'B', parent: 'A', sort: m.keyFor('A', 'a2', 'B') });
  assert.deepEqual(lines(m), ['A', '  a1', '  a2', '  B', 'orphan']);
  assert.equal(m.apply({ op: 'move', id: 'A', parent: 'B', sort: 'a0' }), null);
  const r = m.apply({ op: 'delete', id: 'A' });
  assert.deepEqual(r.removed, ['A', 'a1', 'a2', 'B']);
  assert.deepEqual(lines(m), ['orphan']);
});

test('model: visible leaves out children of collapsed blocks', () => {
  const m = sample();
  m.apply({ op: 'collapse', id: 'A', collapsed: true });
  assert.deepEqual(m.visible().map((n) => n.id), ['A', 'B', 'orphan']);
});

test('model: merge from the server, keeping what is still unsent', () => {
  const m = sample();
  m.apply({ op: 'edit', id: 'B', text: 'local' });
  m.merge({ id: 'B', kind: 'block', page: P, parent: P, sort: 'a1', text: 'server' }, { keepText: true });
  assert.equal(m.get('B').text, 'local');
  m.merge({ id: 'B', kind: 'block', page: P, parent: P, sort: 'Zz', text: 'server' });
  assert.equal(lines(m)[0], 'server');
  m.merge({ id: 'new', kind: 'block', page: P, parent: 'B', sort: 'a0', text: 'from elsewhere' });
  assert.equal(m.get('new').parent, 'B');
  assert.equal(m.merge({ id: 'x', kind: 'block', page: 'other', parent: 'other', sort: 'a0', text: '' }), null);
  m.merge({ id: 'B', kind: 'block', page: 'other', parent: 'other', sort: 'a0', text: '' });
  assert.equal(m.has('B'), false, 'moved to another page');
});

function outline(sendImpl) {
  const sent = [];
  const outbox = new Outbox({ storage: memory(), send: sendImpl ?? (async (ops) => { sent.push(...ops); return { seq: sent.length }; }) });
  const changes = [];
  const o = new Outline({ outbox, onChange: (c) => changes.push(c) });
  o.load({ id: P }, []);
  return { o, outbox, sent, changes };
}

test('outline: create, split, indent, outdent, shift, merge', async () => {
  const { o } = outline();
  const a = o.create({ text: 'hello world' });
  const b = o.split(a, 5);
  assert.deepEqual(lines(o.model), ['hello', ' world']);
  o.edit(b, 'world');
  assert.equal(o.indent(b), true);
  assert.deepEqual(lines(o.model), ['hello', '  world']);
  const c = o.split(a, 5);
  assert.deepEqual(lines(o.model), ['hello', '  ', '  world'], 'splitting a block with children makes a first child');
  o.remove(c);
  assert.equal(o.outdent(b), true);
  assert.deepEqual(lines(o.model), ['hello', 'world']);
  assert.equal(o.shift(b, -1), true);
  assert.deepEqual(lines(o.model), ['world', 'hello']);
  assert.equal(o.shift(b, -1), false);
  const child = o.create({ parent: a, text: 'kid' });
  assert.deepEqual(o.mergeUp(a), [b, 5]);
  assert.deepEqual(lines(o.model), ['worldhello', '  kid'], "children follow the merged block's text");
  assert.equal(o.model.get(child).parent, b);
  await micro();
});

test('outline: every key press is one undo step, and redo puts it back', async () => {
  const { o } = outline();
  const a = o.create({ text: 'one two' });
  await micro();
  const b = o.split(a, 3);
  await micro();
  o.indent(b);
  await micro();
  assert.deepEqual(lines(o.model), ['one', '   two']);
  o.undo();
  assert.deepEqual(lines(o.model), ['one', ' two']);
  o.undo();
  assert.deepEqual(lines(o.model), ['one two'], 'the split is undone in one step');
  o.redo();
  assert.deepEqual(lines(o.model), ['one', ' two']);
  o.remove(a);
  await micro();
  o.undo();
  assert.deepEqual(lines(o.model), ['one', ' two'], 'delete is undone with the same ids');
  assert.ok(o.model.has(a));
});

test('outline: typing is not an undo step, but an undoable edit is', async () => {
  const { o } = outline();
  const a = o.create({ text: 'x' });
  await micro();
  o.edit(a, 'xy');
  await micro();
  assert.equal(o.undoStack.length, 1);
  o.edit(a, 'DONE xy', { undoable: true });
  await micro();
  o.undo();
  assert.equal(o.model.get(a).text, 'xy');
});

test('outline: operations reach the outbox in the protocol shape', async () => {
  const { o, outbox, sent } = outline();
  const a = o.create({ text: 'a' });
  o.edit(a, 'ab');
  o.edit(a, 'abc');
  o.setCollapsed(a, true);
  await outbox.flush();
  assert.equal(sent.length, 2, 'edits folded into the waiting create');
  assert.deepEqual({ ...sent[0], opId: '…' }, { op: 'create', id: a, parent: P, sort: 'a0', text: 'abc', collapsed: false, opId: '…' });
  assert.deepEqual(Object.keys(sent[1]).sort(), ['collapsed', 'id', 'op', 'opId']);
  assert.match(sent[0].opId, /^op-/);
});

test('outline: a change from the server is merged unless the block has unsent changes', async () => {
  let release;
  const { o } = outline(() => new Promise((r) => { release = r; }));
  const a = o.create({ text: 'mine' });
  o.fromServer({ id: a, kind: 'block', page: P, parent: P, sort: 'a0', text: 'theirs' });
  assert.equal(o.model.get(a).text, 'mine');
  await tick(5);
  release({ seq: 1 });
  await tick(5);
  o.fromServer({ id: a, kind: 'block', page: P, parent: P, sort: 'a0', text: 'theirs' });
  assert.equal(o.model.get(a).text, 'theirs');
  o.fromServer({ id: a }, true);
  assert.equal(o.model.has(a), false);
});

test('outline: unsent operations survive a reload of the page', () => {
  const storage = memory();
  const box1 = new Outbox({ storage, send: () => new Promise(() => {}) });
  const o1 = new Outline({ outbox: box1 });
  o1.load({ id: P }, []);
  const a = o1.create({ text: 'not yet saved' });
  o1.create({ parent: a, text: 'child' });
  const box2 = new Outbox({ storage, send: () => new Promise(() => {}) });
  const o2 = new Outline({ outbox: box2 });
  o2.load({ id: P }, []);
  assert.deepEqual(lines(o2.model), ['not yet saved', '  child']);
  const o3 = new Outline({ outbox: box2 });
  o3.load({ id: 'another page' }, []);
  assert.deepEqual(lines(o3.model), [], 'operations for another page stay out');
});

test('outbox: kept after a network failure, sent after recovery, 4xx dropped', async () => {
  let fail = 'network';
  const sent = [];
  const rejected = [];
  const statuses = [];
  const box = new Outbox({
    storage: memory(),
    send: async (ops) => {
      if (fail === 'network') throw Object.assign(new Error('offline'), { status: 0 });
      if (fail === 'bad') throw Object.assign(new Error('bad'), { status: 400 });
      sent.push(ops.length);
      return {};
    },
    onRejected: (e, ops) => rejected.push(ops.length),
    onStatus: (s) => statuses.push(s),
  });
  box.enqueue({ op: 'create', id: 'a', opId: '1' });
  await box.flush().catch(() => {});
  assert.equal(box.size, 1);
  assert.ok(statuses.includes('offline'));
  fail = null;
  box.retryNow();
  await box.flush();
  assert.deepEqual([box.size, sent], [0, [1]]);
  fail = 'bad';
  box.enqueue({ op: 'delete', id: 'zzz', opId: '2' });
  await tick(5);
  assert.deepEqual([box.size, rejected], [0, [1]]);
  assert.equal(statuses.at(-1), 'saved');
});
