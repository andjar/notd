import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fill, CURSOR } from '../../client/lib/placeholders.js';

test('template placeholders are filled in; other double braces are left alone', () => {
  const now = new Date(2026, 10, 27, 9, 5);
  assert.equal(fill('Meeting {{date}} {{time}} on [[{{page}}]]', { page: 'Kitchen', now }), 'Meeting 2026-11-27 09:05 on [[Kitchen]]');
  assert.equal(fill('{{year}}-{{month}}-{{day}} / {{datetime}}', { now }), '2026-11-27 / 2026-11-27 09:05');
  assert.equal(fill('{{query tag:x}} {{embed [[A]]}} {{unknown}}', { now }), '{{query tag:x}} {{embed [[A]]}} {{unknown}}');
  assert.equal(fill('Notes: {{cursor}}', { now }), `Notes: ${CURSOR}`);
  assert.match(fill('{{weekday}}', { now }), /\w/);
});
