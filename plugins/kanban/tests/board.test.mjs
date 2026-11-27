import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boardQuery, columns, moved, cardText } from '../lib/board.js';

test('the query sent for a board', () => {
  assert.deepEqual(boardQuery(''), { field: 'status', sent: 'task:any group:status sort:-updated' });
  assert.deepEqual(boardQuery('page:Kitchen'), { field: 'status', sent: 'task:any page:Kitchen group:status sort:-updated' });
  assert.deepEqual(boardQuery('task:open sort:deadline'), { field: 'status', sent: 'task:open sort:deadline group:status' });
  assert.deepEqual(boardQuery('tag:project group:Stage columns:Idea,Doing,Shipped'),
    { field: 'stage', sent: 'tag:project group:Stage columns:Idea,Doing,Shipped sort:-updated' });
});

const n = (id) => ({ id, text: id });

test('columns by task state', () => {
  const groups = [{ value: 'doing', nodes: [n('a')] }, { value: 'todo', nodes: [n('b'), n('c')] }];
  assert.deepEqual(columns('status', groups).map((c) => [c.value, c.label, c.nodes.length]),
    [['TODO', 'To do', 2], ['DOING', 'Doing', 1], ['WAITING', 'Waiting', 0], ['DONE', 'Done', 0]]);
  assert.deepEqual(columns('status', [...groups, { value: 'cancelled', nodes: [n('d')] }]).map((c) => c.value),
    ['TODO', 'DOING', 'WAITING', 'DONE', 'CANCELLED']);
  assert.deepEqual(columns('status', groups, 'todo,done,nonsense').map((c) => c.value), ['TODO', 'DONE']);
});

test('columns by a field: the ones asked for, then the others, then cards without it', () => {
  const groups = [{ value: 'Doing', nodes: [n('a')] }, { value: 'Later', nodes: [n('b')] }, { value: null, nodes: [n('c')] }];
  assert.deepEqual(columns('stage', groups, 'Idea, doing').map((c) => [c.value, c.label, c.nodes.length]),
    [['Idea', 'Idea', 0], ['doing', 'doing', 1], ['Later', 'Later', 1], [null, 'No stage', 1]]);
  assert.deepEqual(columns('stage', groups.slice(0, 2)).map((c) => c.value), ['Doing', 'Later']);
});

test('moving a card changes its keyword or its field', () => {
  const now = new Date(2026, 10, 27, 9, 5);
  assert.equal(moved('TODO Paint the door', 'status', 'DOING', now), 'DOING Paint the door {start:: 2026-11-27 09:05}');
  assert.equal(moved('DOING Paint {start:: 2026-11-26 10:00}', 'status', 'DONE', now), 'DONE Paint {start:: 2026-11-26 10:00} {done:: 2026-11-27 09:05}');
  assert.equal(moved('DONE Paint {done:: 2026-11-27 09:05}', 'status', 'TODO', now), 'TODO Paint');
  assert.equal(moved('Launch {stage:: Idea} #project\nnotes', 'stage', 'Shipped'), 'Launch {stage:: Shipped} #project\nnotes');
  assert.equal(moved('Launch #project', 'stage', 'Idea'), 'Launch #project {stage:: Idea}');
  assert.equal(moved('Launch {stage:: Idea} #project', 'stage', null), 'Launch #project');
});

test('what a card shows', () => {
  assert.equal(cardText('DOING Paint the door {start:: 2026-11-27 09:05}\nwith a brush', 'status'), 'Paint the door');
  assert.equal(cardText('TODO Launch {stage:: Idea} #project', 'stage'), 'TODO Launch #project');
});
