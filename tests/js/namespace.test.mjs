import { test } from 'node:test';
import assert from 'node:assert/strict';

test('the pages directly under a page, with pages that only have pages under them', async () => {
  const { childrenOf } = await import('../../client/lib/namespace.js');
  const titles = ['Projects', 'Projects/Alpha', 'projects/beta', 'Projects/Alpha/Notes', 'Projects/Gamma/Plan', 'Projects/Gamma/Budget', 'Projectsx', 'Other/Projects/x'];
  assert.deepEqual(childrenOf('Projects', titles), [
    { title: 'Projects/Alpha', below: 1 },
    { title: 'projects/beta', below: 0 },
    { title: 'Projects/Gamma', below: 2 },
  ]);
  assert.deepEqual(childrenOf('Projects/Alpha', titles), [{ title: 'Projects/Alpha/Notes', below: 0 }]);
  assert.deepEqual(childrenOf('Nothing', titles), []);
});
