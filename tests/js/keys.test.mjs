import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { keyBetween, keysBetween, isValidKey, newId, ID_PATTERN } from '../../client/lib/keys.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/sort_keys.json', import.meta.url), 'utf8'));

test('keyBetween gives the fixture keys, each valid and strictly between', () => {
  for (const [a, b, k] of fixture.between) {
    assert.equal(keyBetween(a, b), k);
    assert.ok(isValidKey(k), k);
    assert.ok((a === null || a < k) && (b === null || k < b), `${a} < ${k} < ${b}`);
  }
});

test('keysBetween gives the fixture keys in ascending order', () => {
  for (const [a, b, n, keys] of fixture.many) {
    assert.deepEqual(keysBetween(a, b, n), keys);
    for (let i = 1; i < keys.length; i++) assert.ok(keys[i - 1] < keys[i]);
  }
});

test('keys stay short under random inserts', () => {
  const longest = Math.max(...fixture.between.slice(0, 1500).map(([, , k]) => k.length));
  assert.ok(longest <= 12, `longest key ${longest}`);
});

test('invalid keys and reversed bounds are refused', () => {
  for (const bad of ['', 'a', 'a00', 'a0 ', '!', 'A00000000000000000000000000']) assert.equal(isValidKey(bad), false, bad);
  assert.throws(() => keyBetween('a1', 'a0'), /a1 >= a0/);
  assert.throws(() => keyBetween('a1', 'a1'));
  assert.throws(() => keyBetween('bad!', null), /invalid/);
});

test('newId makes version 7 UUIDs that sort by time', () => {
  const a = newId(1700000000000);
  const b = newId(1700000000001);
  assert.match(a, ID_PATTERN);
  assert.equal(a[14], '7');
  assert.ok('89ab'.includes(a[19]));
  assert.ok(a < b);
  assert.equal(a.slice(0, 13).replace('-', ''), (1700000000000).toString(16).padStart(12, '0'));
});
