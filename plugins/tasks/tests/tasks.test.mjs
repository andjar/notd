import { test } from 'node:test';
import assert from 'node:assert/strict';
import { keyword, toggle, cycle, nextState, setKeyword, setField, getField } from '../lib/tasks.js';

const at = new Date(2026, 10, 25, 14, 5);

test('the keyword is the first word, and only these', () => {
  assert.equal(keyword('TODO buy milk'), 'TODO');
  assert.equal(keyword('todo buy milk'), null);
  assert.equal(keyword('TODOs are good'), null);
  assert.equal(keyword('A TODO later'), null);
});

test('ticking: open becomes DONE with a done time; DONE becomes TODO again without it', () => {
  assert.equal(toggle('TODO buy milk', at), 'DONE buy milk {done:: 2026-11-25 14:05}');
  assert.equal(toggle('DOING write {start:: 2026-11-24 09:00}', at), 'DONE write {start:: 2026-11-24 09:00} {done:: 2026-11-25 14:05}');
  assert.equal(toggle('DONE buy milk {done:: 2026-11-25 14:05}', at), 'TODO buy milk');
  assert.equal(toggle('CANCELLED old idea', at), 'TODO old idea');
});

test('Ctrl+Enter cycles: none, TODO, DONE, none', () => {
  assert.equal(cycle('plain', at), 'TODO plain');
  assert.equal(cycle('TODO plain', at), 'DONE plain {done:: 2026-11-25 14:05}');
  assert.equal(cycle('DONE plain {done:: 2026-11-25 14:05}', at), 'plain');
});

test('the keyword button: TODO, DOING (which sets start once), WAITING, TODO', () => {
  const doing = nextState('TODO write', at);
  assert.equal(doing, 'DOING write {start:: 2026-11-25 14:05}');
  const waiting = nextState(doing, new Date(2026, 10, 26));
  assert.equal(waiting, 'WAITING write {start:: 2026-11-25 14:05}');
  assert.equal(nextState(nextState(waiting, at), new Date(2027, 0, 1)), 'DOING write {start:: 2026-11-25 14:05}', 'start is kept');
  assert.equal(nextState('DONE x', at), 'DONE x');
});

test('fields go at the end of the first line, and replace an old value', () => {
  assert.equal(setField('TODO a\nmore lines', 'scheduled', '2026-12-01'), 'TODO a {scheduled:: 2026-12-01}\nmore lines');
  assert.equal(setField('TODO a {scheduled:: 2026-12-01}', 'scheduled', '2026-12-24'), 'TODO a {scheduled:: 2026-12-24}');
  assert.equal(setField('TODO a {scheduled:: 2026-12-01} b', 'scheduled', null), 'TODO a b');
  assert.equal(getField('TODO a {Deadline:: 2026-12-01}', 'deadline'), '2026-12-01');
  assert.equal(setKeyword('TODO a', null, at), 'a');
});
