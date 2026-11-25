import { test } from 'node:test';
import assert from 'node:assert/strict';
import { typedLink, suggest, completion, rewriteLinks } from '../../client/lib/links.js';

test('typedLink finds the link being typed before the caret', () => {
  assert.deepEqual(typedLink('see [[Øst', 9), { kind: '[[', start: 4, query: 'Øst' });
  assert.deepEqual(typedLink('see [[', 6), { kind: '[[', start: 4, query: '' });
  assert.deepEqual(typedLink('a #ide', 6), { kind: '#', start: 2, query: 'ide' });
  assert.equal(typedLink('see [[done]] x', 14), null);
  assert.equal(typedLink('C#', 2), null);
  assert.equal(typedLink('plain', 5), null);
});

test('suggest puts titles that start with the query first, and finds aliases', () => {
  const pages = [{ title: 'Project Alpha' }, { title: 'Alpha centauri' }, { title: 'New York City', aliases: ['NYC'] }, { title: 'alpha' }];
  assert.deepEqual(suggest(pages, 'alpha'), ['alpha', 'Alpha centauri', 'Project Alpha']);
  assert.deepEqual(suggest(pages, 'nyc'), ['New York City']);
  assert.deepEqual(suggest(pages, 'ØSTFOLD'), []);
});

test('completion makes a link or a tag, with brackets when needed', () => {
  assert.equal(completion('[[', 'New York'), '[[New York]]');
  assert.equal(completion('#', 'idea'), '#idea');
  assert.equal(completion('#', 'two words'), '#[[two words]]');
});

test('rewriteLinks follows a rename, whatever the case, and leaves the rest', () => {
  assert.equal(rewriteLinks('see [[old name]] and [[Old Name]]', 'Old name', 'New name'), 'see [[New name]] and [[New name]]');
  assert.equal(rewriteLinks('#idea #ideas #[[idea]]', 'idea', 'Big idea'), '#[[Big idea]] #ideas #[[Big idea]]');
  assert.equal(rewriteLinks('#idea', 'idea', 'thought'), '#thought');
  assert.equal(rewriteLinks('{{embed [[Østfold]]}} [[Other]]', 'østfold', 'Viken'), '{{embed [[Viken]]}} [[Other]]');
  assert.equal(rewriteLinks('C#idea', 'idea', 'x'), 'C#idea');
});
