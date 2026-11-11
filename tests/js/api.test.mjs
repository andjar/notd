// API tests for everything except ops.php (see ops_api.test.mjs), against a running server.
// Usage: NOTD_URL=http://127.0.0.1:8765/ node tests/js/api.test.mjs

const ROOT = process.env.NOTD_URL || 'http://127.0.0.1:8765/';
const BASE = ROOT + 'api/v1/';
let failures = 0, passes = 0;
const ok = (cond, msg) => { if (cond) passes++; else { failures++; console.log('  FAIL:', msg); } };
const eq = (a, b, msg) => ok(JSON.stringify(a) === JSON.stringify(b), `${msg}\n    expected ${JSON.stringify(b)}\n    got      ${JSON.stringify(a)}`);

async function raw(path, body, headers = {}) {
  const r = await fetch(BASE + path, {
    method: body !== undefined ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, json, text, headers: r.headers };
}
const get = async (path) => (await raw(path)).json;
let n = 0;
const uniq = (s) => `${s}-${Date.now().toString(36)}-${n++}`;
const append = async (pageName, notes) => (await raw('append_to_page.php', { page_name: pageName, notes })).json.data;
const edit = (id, content) => raw('ops.php', { ops: [{ op_id: uniq('op'), type: 'edit', id, content }] });
const outline = async (pageId) => {
  const notes = (await get(`notes.php?page_id=${pageId}`)).data;
  const kids = {};
  for (const x of notes) (kids[x.parent_note_id ?? ''] ??= []).push(x);
  const out = [];
  const walk = (p, d) => (kids[p] || []).forEach(x => { out.push(' '.repeat(d) + x.content); walk(x.id, d + 1); });
  walk('', 0);
  return out;
};

async function test(name, fn) {
  const before = failures;
  try { await fn(); } catch (e) { failures++; console.log('  ERROR:', e.stack || e.message); }
  console.log(`${failures === before ? 'ok  ' : 'FAIL'} ${name}`);
}

// ------------------------------------------------------------------ pages

await test('pages: create, read, list, types are stable', async () => {
  const name = uniq('Page A');
  const created = await raw('pages.php', { name, content: '{favorite::true} {x::1}' });
  eq(created.status, 201, 'created');
  const page = created.json.data;
  eq(typeof page.id, 'number', 'page id is a number');
  eq(page.properties.favorite, [{ value: 'true', internal: false }], 'properties indexed');
  eq((await raw('pages.php', { name: name.toUpperCase() })).status, 409, 'names are unique without regard to case');
  eq((await get(`pages.php?name=${encodeURIComponent(name.toUpperCase())}`)).data.id, page.id, 'lookup ignores case');
  eq((await get(`pages.php?id=${page.id}`)).data.name, name, 'by id');
  eq((await raw('pages.php?id=99999999')).status, 404, 'unknown id');
  const list = await get('pages.php?per_page=5');
  ok(list.data.some(p => p.id === page.id), 'listed first (most recent)');
  eq(typeof list.pagination.total_items, 'number', 'pagination');
  for (const bad of ['', '   ', 'a[b]', 'x'.repeat(300)]) {
    eq((await raw('pages.php', { name: bad })).status, 400, `rejects name ${JSON.stringify(bad.slice(0, 10))}`);
  }
});

await test('pages: properties follow the content, also when it is emptied', async () => {
  const page = (await raw('pages.php', { name: uniq('Props'), content: '{favorite::true} {x::1}' })).json.data;
  const isFavorite = async () => (await get('search.php?favorites=1&per_page=100')).data.results.some(r => r.page_id === page.id);
  ok(await isFavorite(), 'favorite listed');
  let updated = (await raw('pages.php', { _method: 'PUT', id: page.id, content: '{x::2}' })).json.data;
  eq(updated.properties, { x: [{ value: '2', internal: false }] }, 'replaced');
  ok(!(await isFavorite()), 'no longer a favorite');
  updated = (await raw('pages.php', { _method: 'PUT', id: page.id, content: '' })).json.data;
  eq(updated.properties, [], 'emptied');
});

await test('pages: a date name is a journal page', async () => {
  const date = `19${String(10 + Math.floor(Math.random() * 89))}-0${1 + Math.floor(Math.random() * 9)}-1${Math.floor(Math.random() * 9)}`;
  const page = (await get(`pages.php?name=${date}`)).data;
  eq(page.properties.type, [{ value: 'journal', internal: false }], 'journal type from content');
  ok(page.content.includes('{type::journal}'), 'the type lives in the content');
  const withJournals = (await get('pages.php?per_page=5000')).data.some(p => p.id === page.id);
  const withoutJournals = (await get('pages.php?per_page=5000&exclude_journal=1')).data.some(p => p.id === page.id);
  eq([withJournals, withoutJournals], [true, false], 'exclude_journal');
  await raw('pages.php', { _method: 'DELETE', id: page.id });
});

await test('pages: rename rewrites links, renames child pages, refuses clashes', async () => {
  const old = uniq('Old Name'), neu = uniq('New Name');
  const page = (await raw('pages.php', { name: old })).json.data;
  const child = (await raw('pages.php', { name: `${old}/Child` })).json.data;
  const other = uniq('Linker');
  const notes = (await append(other, [
    `see [[${old}]] and [[${old.toLowerCase()}]]`,
    `child: [[${old}/Child]] but not [[${old}x]]`,
    'unrelated',
  ])).appended_notes;
  const taken = (await raw('pages.php', { name: uniq('Taken') })).json.data;

  eq((await raw('pages.php', { _method: 'PUT', id: page.id, name: taken.name })).status, 409, 'cannot take an existing name');
  eq((await get(`pages.php?id=${page.id}`)).data.name, old, 'unchanged after refusal');

  const renamed = await raw('pages.php', { _method: 'PUT', id: page.id, name: neu });
  eq(renamed.status, 200, 'renamed');
  eq(renamed.json.data.name, neu, 'new name');
  eq(renamed.json.data.notes_updated, 2, 'two notes rewritten');
  eq((await get(`pages.php?id=${child.id}`)).data.name, `${neu}/Child`, 'child page follows');
  const after = await Promise.all(notes.map(x => get(`notes.php?id=${x.id}`)));
  eq(after.map(r => r.data.content), [
    `see [[${neu}]] and [[${neu}]]`,
    `child: [[${neu}/Child]] but not [[${old}x]]`,
    'unrelated',
  ], 'links rewritten');
  eq((await get(`search.php?backlinks_for_page_name=${encodeURIComponent(neu)}`)).data.results.map(r => r.note_id), [notes[0].id], 'backlinks follow');
  eq((await get(`search.php?backlinks_for_page_name=${encodeURIComponent(old)}`)).data.results, [], 'none left for the old name');
  eq((await raw('pages.php', { _method: 'PUT', id: page.id, name: neu.toUpperCase() })).json.data.name, neu.toUpperCase(), 'changing only the case works');
});

await test('pages: delete removes notes; child pages listing', async () => {
  const ns = uniq('NS');
  await raw('pages.php', { name: ns });
  const a = (await raw('pages.php', { name: `${ns}/A` })).json.data;
  await raw('pages.php', { name: `${ns}/A/Deep` });
  await raw('pages.php', { name: `${ns}x/NotAChild` });
  eq((await get(`child_pages.php?namespace=${encodeURIComponent(ns)}`)).data.map(p => p.name), [`${ns}/A`], 'direct children only');
  const note = (await append(`${ns}/A`, ['bye'])).appended_notes[0];
  eq((await raw('pages.php', { _method: 'DELETE', id: a.id })).status, 200, 'deleted');
  eq((await raw(`notes.php?id=${note.id}`)).status, 404, 'its notes are gone');
  eq((await raw('pages.php', { _method: 'DELETE', id: a.id })).status, 404, 'deleting twice');
  eq((await raw('pages.php', { _method: 'DELETE', id: 'abc' })).status, 404, 'bad id');
});

// ---------------------------------------------------------- notes & search

await test('append: strings and nested objects; note shape', async () => {
  const name = uniq('Append');
  const data = await append(name, ['one', { content: 'two {k::v}', children: ['two-a', { content: 'two-b', children: ['deep'] }] }, 'three']);
  eq(await outline(data.page.id), ['one', 'two {k::v}', ' two-a', ' two-b', '  deep', 'three'], 'tree');
  const note = data.appended_notes.find(x => x.content.startsWith('two {'));
  eq([typeof note.id, typeof note.page_id, typeof note.collapsed, typeof note.version], ['string', 'number', 'number', 'number'], 'types');
  ok(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(note.id), 'server ids are UUIDv7');
  eq(note.properties, { k: [{ value: 'v', internal: false }] }, 'properties (no parent_properties key inside)');
  eq((await raw('append_to_page.php', { page_name: name, notes: [{ nope: 1 }] })).status, 400, 'bad item');
  eq((await outline(data.page.id)).length, 6, 'nothing added by the failed request');
  await append(name, 'a single string');
  eq((await outline(data.page.id)).at(-1), 'a single string', 'string form');
});

await test('notes: children and parent properties', async () => {
  const data = await append(uniq('Tree'), [{ content: 'root {area::work}', children: [{ content: 'mid {area::home} {p:::hidden}', children: ['leaf'] }] }]);
  const [root, mid, leaf] = data.appended_notes;
  const nested = (await get(`notes.php?id=${root.id}&include_children=1`)).data;
  eq(nested.children[0].children[0].content, 'leaf', 'nested subtree');
  const withParents = (await get(`notes.php?id=${leaf.id}&include_parent_properties=1`)).data;
  eq(withParents.parent_properties, { area: [{ value: 'home' }, { value: 'work' }] }, 'nearest ancestor first, public only');
  const internal = (await get(`notes.php?id=${leaf.id}&include_parent_properties=1&include_internal=1`)).data;
  eq(internal.parent_properties.p, [{ value: 'hidden' }], 'internal on request');
  eq((await get(`notes.php?id=${mid.id}`)).data.parent_properties, null, 'null unless asked');
  eq((await get(`properties.php?entity_type=note&entity_id=${mid.id}`)).data, { area: [{ value: 'home', internal: false }] }, 'properties endpoint takes string ids');
  eq(Object.keys((await get(`properties.php?entity_type=note&entity_id=${mid.id}&include_hidden=1`)).data).sort(), ['area', 'p'], 'hidden on request');
  eq((await raw('properties.php?entity_type=note&entity_id=nope')).status, 404, 'unknown note');
  eq((await raw('properties.php?entity_type=thing&entity_id=1')).status, 400, 'bad type');
});

await test('search: any input is a valid query; unicode; prefix', async () => {
  const word = uniq('zebra').replace(/-/g, '');
  const data = await append(uniq('Search'), [`A ${word} crossing`, `Ærlig ${word}Ørret talk`, 'nothing here']);
  const find = async (q) => (await get(`search.php?q=${encodeURIComponent(q)}&per_page=100`)).data.results.map(r => r.note_id);
  eq((await find(word)).sort(), [data.appended_notes[0].id, data.appended_notes[1].id].sort(), 'word');
  eq(await find(`${word} crossing`), [data.appended_notes[0].id], 'all words must match');
  eq(await find(word.slice(0, -3)), (await find(word)), 'last word matches by prefix');
  eq(await find(`ærlig ${word}ørret`), [data.appended_notes[1].id], 'case-insensitive beyond ASCII');
  for (const q of ['"unbalanced', 'a*b OR', 'NEAR(', 'x AND', '(((', `${word} -crossing`, 'col:on', '*', '""']) {
    const r = await raw(`search.php?q=${encodeURIComponent(q)}`);
    ok(r.status === 200 || r.status === 400, `no server error for ${JSON.stringify(q)} (got ${r.status})`);
    ok(r.json && r.json.status, 'JSON answer');
  }
  eq((await raw('search.php?q=%20')).status, 400, 'empty term');
  eq((await raw('search.php')).status, 400, 'no parameter');
});

await test('search: tasks are found by their current state only', async () => {
  const data = await append(uniq('Tasks'), ['TODO write report', 'DONE old thing', 'not a task TODO later', 'intro\nTODO second line']);
  const [todo, done, , multi] = data.appended_notes;
  const tasks = async (s) => (await get(`search.php?tasks=${s}&per_page=100`)).data.results.filter(r => r.page_id === data.page.id).map(r => [r.note_id, r.status]);
  eq((await tasks('TODO')).sort(), [[todo.id, 'TODO'], [multi.id, 'TODO']].sort(), 'TODO tasks');
  await edit(todo.id, 'DOING write report');
  eq(await tasks('TODO'), [[multi.id, 'TODO']], 'a task that moved on is no longer TODO');
  eq(await tasks('DOING'), [[todo.id, 'DOING']], 'it is DOING');
  await edit(todo.id, 'write report, no longer a task');
  eq(await tasks('DOING'), [], 'a note that stopped being a task is in no column');
  eq((await tasks('all')).sort(), [[done.id, 'DONE'], [multi.id, 'TODO']].sort(), 'all');
  eq((await raw('search.php?tasks=BOGUS')).status, 400, 'unknown state');
});

await test('properties: unchanged content writes nothing; done_at is kept; logs do not grow', async () => {
  const data = await append(uniq('Idx'), ['TODO task {priority::high}']);
  const id = data.appended_notes[0].id;
  const props = async () => (await get(`properties.php?entity_type=note&entity_id=${id}&include_hidden=1`)).data;
  await edit(id, 'DONE task {priority::high}');
  const first = await props();
  eq(first.status.map(s => s.value), ['TODO', 'DONE'], 'status is a log');
  ok(/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(first.done_at[0].value), 'done_at set');
  await new Promise(r => setTimeout(r, 1100));
  await edit(id, 'DONE task, reworded {priority::high}');
  await edit(id, 'DONE task, reworded again {priority::high}');
  const second = await props();
  eq(second.done_at, first.done_at, 'done_at keeps the time of completion');
  eq(second.status.map(s => s.value), ['TODO', 'DONE'], 'editing does not add log entries');
  await edit(id, 'TODO task {priority::low}');
  const third = await props();
  eq([third.done_at, third.priority[0].value, third.status.map(s => s.value)], [undefined, 'low', ['TODO', 'DONE', 'TODO']], 'reopened');
});

await test('query_notes: order is the query\'s; unsafe queries are refused', async () => {
  const tag = uniq('q').replace(/-/g, '');
  const data = await append(uniq('Query'), [`b ${tag}`, `c ${tag}`, `a ${tag}`]);
  const q = async (sql, extra = {}) => raw('query_notes.php', { sql_query: sql, ...extra });
  const asc = await q(`SELECT id FROM Notes WHERE content LIKE '% ${tag}' ORDER BY content ASC`);
  eq(asc.json.data.map(x => x.content[0]), ['a', 'b', 'c'], 'ascending');
  const desc = await q(`SELECT id FROM Notes WHERE content LIKE '% ${tag}' ORDER BY content DESC`, { per_page: 2 });
  eq(desc.json.data.map(x => x.content[0]), ['c', 'b'], 'descending, paged');
  eq(desc.json.pagination.total_items, 3, 'total');
  for (const sql of [
    'SELECT * FROM Webhooks',
    "SELECT id FROM Notes WHERE 1=1 UNION SELECT secret FROM Webhooks",
    "SELECT id FROM Notes WHERE id IN (SELECT name FROM sqlite_master)",
    "SELECT id FROM Notes WHERE 1=1; DROP TABLE Notes",
    "DELETE FROM Notes",
    "SELECT id FROM Notes WHERE id IN (SELECT name FROM Pages)",
  ]) {
    eq((await q(sql)).status, 400, `refused: ${sql.slice(0, 45)}`);
  }
  eq((await q('SELECT id FROM Notes WHERE nonsense(')).status, 400, 'syntax errors are a 400, not a 500');
  eq((await outline(data.page.id)).length, 3, 'data intact');
});

// ------------------------------------------------------------ attachments

async function upload(noteId, name, bytes, type) {
  const form = new FormData();
  form.append('note_id', noteId);
  form.append('attachmentFile', new Blob([bytes], { type }), name);
  const r = await fetch(BASE + 'attachments.php', { method: 'POST', body: form });
  return { status: r.status, json: await r.json() };
}
const PNG = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), c => c.charCodeAt(0));

await test('attachments: upload, list, fetch, delete; only safe file types', async () => {
  const note = (await append(uniq('Files'), ['holder'])).appended_notes[0];
  const up = await upload(note.id, 'my pic (1).png', PNG, 'image/png');
  eq(up.status, 200, 'uploaded');
  const att = up.json.data.attachments[0];
  eq([att.name, att.type, att.size], ['my pic (1).png', 'image/png', PNG.length], 'recorded');
  ok(/\.png$/.test(att.path) && !/[ ()]/.test(att.path), 'stored under a safe name');
  const file = await fetch(new URL(att.url, ROOT));
  eq([file.status, (await file.arrayBuffer()).byteLength], [200, PNG.length], 'the url serves the file');
  eq((await get(`attachments.php?note_id=${note.id}`)).data.map(a => a.id), [att.id], 'listed for the note');
  ok((await get(`notes.php?id=${note.id}`)).data.has_attachments, 'note knows it has attachments');
  ok((await get('attachments.php?per_page=100&filter_by_type=image/png')).data.data.some(a => a.id === att.id), 'listed globally');

  for (const [name, bytes, type] of [
    ['shell.php', '<?php echo 1;', 'text/plain'],
    ['notes.txt.php', 'plain text', 'text/plain'],
    ['page.html', '<script>1</script>', 'text/html'],
    ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'],
    ['fake.png', 'this is not a png', 'image/png'],
    ['noextension', 'x', 'text/plain'],
  ]) {
    const r = await upload(note.id, name, bytes, type);
    eq(r.status, 415, `refused: ${name}`);
    eq(r.json.status, 'error', 'with an error message');
  }
  eq((await upload('nope-nope', 'a.txt', 'x', 'text/plain')).status, 404, 'unknown note');
  eq((await upload(note.id, 'ok.txt', 'hello', 'text/plain')).status, 200, 'text is fine');
  eq((await get(`attachments.php?note_id=${note.id}`)).data.length, 2, 'only the two valid files were stored');

  eq((await raw('attachments.php', { action: 'delete', attachment_id: att.id })).status, 200, 'deleted');
  eq((await fetch(new URL(att.url, ROOT))).status, 404, 'file removed from disk');
  eq((await raw('attachments.php', { action: 'delete', attachment_id: att.id })).status, 404, 'deleting twice');
  eq((await raw('attachments.php?file=' + encodeURIComponent('../config.php'))).status, 404, 'no path traversal');
});

// --------------------------------------------------------------- templates

await test('templates: plain text, never executed; names cannot escape the folder', async () => {
  const list = (await get('templates.php?type=note&tz=Europe/Oslo')).data;
  ok(list.length >= 5 && list.every(t => typeof t.content === 'string' && !t.content.includes('<?php')), 'bundled templates load as text');
  const name = uniq('tpl').replace(/-/g, '_');
  const body = '<?php echo "EXECUTED"; ?> on {{date}} at {{date:d.m.Y}} {{unknown}}';
  eq((await raw('templates.php', { type: 'note', name, content: body })).status, 201, 'created');
  const made = (await get('templates.php?type=note')).data.find(t => t.name === name);
  ok(made.content.includes('<?php echo "EXECUTED"; ?>'), 'PHP in a template is just text');
  ok(/on \d{4}-\d\d-\d\d at \d\d\.\d\d\.\d{4} \{\{unknown\}\}$/.test(made.content), 'placeholders filled, unknown ones left');
  eq(made.raw, body, 'raw text available');
  const direct = await fetch(`${ROOT}assets/template/note/${name}.php`);
  ok(direct.status === 404 || !(await direct.text()).includes('EXECUTED'), 'not reachable as a script');
  eq((await raw('templates.php', { type: 'note', name, content: 'x' })).status, 409, 'no silent overwrite');
  for (const bad of ['../../evil', 'a/b', 'x.php', '', '..', 'a\\b']) {
    eq((await raw('templates.php', { type: 'note', name: bad, content: 'x' })).status, 400, `refused name ${JSON.stringify(bad)}`);
  }
  eq((await raw('templates.php', { _method: 'PUT', type: 'note', current_name: name, new_name: name + '_2', content: 'new' })).status, 200, 'renamed');
  const names = (await get('templates.php?type=note')).data.map(t => t.name);
  eq([names.includes(name), names.includes(name + '_2')], [false, true], 'old gone, new there');
  eq((await raw('templates.php', { _method: 'DELETE', type: 'note', name: name + '_2' })).status, 200, 'deleted');
  eq((await raw('templates.php', { _method: 'DELETE', type: 'note', name: name + '_2' })).status, 404, 'deleting twice');
  eq((await raw('templates.php?type=bogus')).status, 400, 'bad type');
});

// ---------------------------------------------------------------- security

await test('requests from other websites cannot change data', async () => {
  const name = uniq('CSRF');
  const body = { page_name: name, notes: ['x'] };
  eq((await raw('append_to_page.php', body, { 'Sec-Fetch-Site': 'cross-site' })).status, 403, 'cross-site refused');
  eq((await raw('append_to_page.php', body, { Origin: 'https://evil.example' })).status, 403, 'foreign Origin refused');
  eq((await raw('append_to_page.php', body, { Origin: 'null' })).status, 403, 'opaque Origin refused');
  eq((await raw('ops.php', { ops: [] }, { 'Sec-Fetch-Site': 'same-site' })).status, 403, 'same-site (another port or subdomain) refused');
  const host = new URL(ROOT).host;
  eq((await raw('append_to_page.php', body, { 'Sec-Fetch-Site': 'same-origin' })).status, 200, 'same-origin accepted');
  eq((await raw('append_to_page.php', body, { Origin: `http://${host}` })).status, 200, 'own Origin accepted');
  eq((await raw('append_to_page.php', body)).status, 200, 'scripts (no browser headers) accepted');
  eq((await raw('search.php?q=x', undefined, { 'Sec-Fetch-Site': 'cross-site' })).status, 200, 'reads are not affected');
});

await test('errors are JSON and do not leak internals', async () => {
  for (const path of ['pages.php', 'ops.php', 'append_to_page.php', 'query_notes.php', 'templates.php', 'webhooks.php']) {
    const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{not json' });
    const text = await r.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* */ }
    ok(json && json.status === 'error' && r.status >= 400 && r.status < 500, `${path}: malformed body is a 4xx JSON error (got ${r.status} ${text.slice(0, 60)})`);
    ok(!/\.php|Stack trace|#0 /.test(text), `${path}: no file names or traces`);
  }
  for (const file of ['db/database.sqlite', 'db/schema.sql', 'config.local.php', 'tests/js/api.test.mjs']) {
    const r = await fetch(ROOT + file);
    ok(r.status === 403 || r.status === 404, `${file} is not served (got ${r.status})`);
  }
});

console.log(`\n${passes} assertions passed, ${failures} failed`);
process.exit(failures ? 1 : 0);
