"""
Feature smoke tests: the main features still work on top of the new write path.

    python3 tests/browser/features_test.py   (server as in outline_test.py)
"""
import os, sys, tempfile
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from outline_test import (Ctx, seed, api, server_tree, dom, focus, wait_synced, check, results, set_latency, RUN, BASE,
                          sync_playwright, uuid, urllib)


def test_global_search(ctx):
    word = 'zebra' + RUN
    pg, ids = seed(f'F-search-{RUN}', [f'find the {word} here', 'other'])
    page = ctx.open(pg['name'])
    page.fill('#global-search-input', word)
    page.wait_for_selector('#search-results .search-result-item', timeout=5000)
    text = page.locator('#search-results').inner_text()
    check('global search finds the note (FTS keyed by rid)', word in text, text[:200])


def test_properties_indexed(ctx):
    pg, ids = seed(f'F-props-{RUN}', ['note with {status::active} and {priority::high}'])
    page = ctx.open(pg['name'])
    focus(page, 'note with'); page.keyboard.type(' {owner::me}')
    page.locator('body').click(position={'x': 5, 'y': 5})
    wait_synced(page)
    note = api(f'notes.php?id={ids["note with {status::active} and {priority::high}"]}')
    props = note['properties']
    check('properties indexed from typed content', all(k in props for k in ('status', 'priority', 'owner')), str(list(props)))


def test_attachment_on_brand_new_note(ctx):
    pg, ids = seed(f'F-att-{RUN}', ['A'])
    set_latency(300)
    page = ctx.open(pg['name'])
    focus(page, 'A'); page.keyboard.press('Enter'); page.keyboard.type('has a file')
    new_id = page.evaluate("() => window.notdOutline.model.list().at(-1).id")
    with tempfile.NamedTemporaryFile('w', suffix='.txt', delete=False) as f:
        f.write('hello attachment'); path = f.name
    # Right-click the bullet -> Upload, immediately (the note may not be on the server yet)
    page.locator(f'.note-bullet[data-note-id="{new_id}"]').click(button='right')
    with page.expect_file_chooser() as fc:
        page.locator('.menu-item[data-action="upload"]').click()
    fc.value.set_files(path)
    page.wait_for_selector(f'.note-item[data-note-id="{new_id}"] .note-attachment-item', timeout=10000)
    set_latency(0)
    atts = api(f'attachments.php?note_id={new_id}')
    check('attachment uploaded to a note created a moment ago', len(atts) == 1, str(atts)[:200])
    check('page not wiped after upload', dom(page) == ['A', 'has a file'], str(dom(page)))


def test_encryption_round_trip(ctx):
    pg, ids = seed(f'F-enc-{RUN}', ['secret one', 'secret two'])
    page = ctx.open(pg['name'])
    page.locator('#page-properties-gear').click()
    page.locator('#page-encryption-button').click()
    page.fill('#new-encryption-password', 'pw123')
    page.fill('#confirm-encryption-password', 'pw123')
    page.locator('#confirm-encryption-btn').click()
    page.wait_for_timeout(1500)
    wait_synced(page)
    stored = [n['content'] for n in api(f'notes.php?page_id={pg["id"]}')]
    check('notes stored encrypted', all(c.startswith('{') and '"ct"' in c for c in stored), str([c[:30] for c in stored]))
    focus(page, 'secret two'); page.keyboard.type(' edited')
    page.locator('body').click(position={'x': 5, 'y': 5})
    wait_synced(page)
    stored = [n['content'] for n in api(f'notes.php?page_id={pg["id"]}')]
    check('edits on a locked page are stored encrypted', all('"ct"' in c for c in stored) and not any('edited' in c for c in stored))
    page.reload()
    page.wait_for_selector("#password-modal.active", timeout=5000)
    page.fill('#password-input', 'pw123'); page.keyboard.press('Enter')
    page.wait_for_selector('#notes-container .note-item')
    page.wait_for_timeout(500)
    check('decrypts after reload', dom(page) == ['secret one', 'secret two edited'], str(dom(page)))


def test_append_api_nested(ctx):
    name = f'F-append-{RUN}'
    api('append_to_page.php', {'page_name': name, 'notes': [{'content': 'from rss', 'children': [{'content': 'summary'}]}]})
    api('append_to_page.php', {'page_name': name, 'notes': 'second item'})
    page_id = api('pages.php?name=' + urllib.parse.quote(name))['id']
    check('append API keeps order and nesting', server_tree(page_id) == ['from rss', ' summary', 'second item'], str(server_tree(page_id)))


def test_template_insert_saves(ctx):
    pg, ids = seed(f'F-tpl-{RUN}', ['A'])
    page = ctx.open(pg['name'])
    focus(page, 'A')
    page.keyboard.type(' typed')
    page.keyboard.press('Escape')
    page.locator('body').click(position={'x': 5, 'y': 5})
    wait_synced(page)
    check('typing then leaving the note saves', server_tree(pg['id']) == ['A typed'], str(server_tree(pg['id'])))


def _upload(note_id, name, data, ctype):
    boundary = '----notd' + uuid.uuid4().hex
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="note_id"\r\n\r\n{note_id}\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="attachmentFile"; filename="{name}"\r\n'
            f'Content-Type: {ctype}\r\n\r\n').encode() + data + f'\r\n--{boundary}--\r\n'.encode()
    req = urllib.request.Request(BASE + 'api/v1/attachments.php', data=body,
                                 headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    import json
    with urllib.request.urlopen(req) as r:
        return json.load(r)['data']['attachments'][0]


PNG_1PX = bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000'
                        '1f15c4890000000d49444154789c6360f8cf00000301010018dd8db00000000049454e44ae426082')


def test_attachment_shown_in_text_is_not_repeated(ctx):
    pg, ids = seed(f'F-inline-{RUN}', ['picture placeholder', 'just attached'])
    placed = _upload(ids['picture placeholder'], 'placed.png', PNG_1PX, 'image/png')
    _upload(ids['just attached'], 'listed.png', PNG_1PX, 'image/png')
    path = '/'.join(urllib.parse.quote(part) for part in placed['path'].split('/'))
    api('ops.php', {'ops': [{'op_id': 'inline-' + RUN, 'type': 'edit', 'id': ids['picture placeholder'],
                              'content': f'picture ![the picture](uploads/{path})'}]})
    page = ctx.open(pg['name'])
    page.wait_for_selector(f'.note-item[data-note-id="{ids["just attached"]}"] .note-attachment-item', timeout=8000)
    page.wait_for_timeout(500)
    inline_list = page.locator(f'.note-item[data-note-id="{ids["picture placeholder"]}"] .note-attachment-item').count()
    check('an image placed in the text is not listed again below it', inline_list == 0, str(inline_list))
    listed = page.locator(f'.note-item[data-note-id="{ids["just attached"]}"] .note-attachment-item').count()
    check('an attachment the text does not show is listed', listed == 1, str(listed))
    focus(page, 'picture'); page.keyboard.press('Control+a'); page.keyboard.type('picture removed from the text')
    page.locator('body').click(position={'x': 5, 'y': 5})
    wait_synced(page)
    back = page.locator(f'.note-item[data-note-id="{ids["picture placeholder"]}"] .note-attachment-item')
    try:
        back.first.wait_for(timeout=8000)
    except Exception:
        pass
    check('once the text no longer shows it, the file is listed again', back.count() == 1, str(back.count()))


def test_kanban_cards_show_text_not_syntax(ctx):
    seed(f'F-kanban-{RUN}', [f'TODO Plan the **launch** for [[Project {RUN}]] {{type::work}} {{secret:::hidden}}'])
    page = ctx.context.new_page()
    page.goto(BASE + 'extensions/kanban_board/index.php')
    card = page.locator('.kanban-card', has_text=f'Project {RUN}')
    card.wait_for(timeout=8000)
    title = card.locator('.kanban-card-title').inner_text()
    check('card title is plain text', title == f'Plan the launch for Project {RUN}', title)
    props = card.locator('.kanban-card-prop').all_inner_texts()
    check('public properties are labels, internal ones are hidden', props == ['typework'], str(props))
    href = card.locator('.kanban-card-source').get_attribute('href') or ''
    check('the card links to its note', f'F-kanban-{RUN}' in urllib.parse.unquote(href) and '#note-' in href, href)
    page.close()


def main():
    only = sys.argv[1] if len(sys.argv) > 1 else ''
    tests = [(n, f) for n, f in globals().items() if n.startswith('test_') and only in n]
    set_latency(0)
    with sync_playwright() as p:
        for name, fn in tests:
            ctx = Ctx(p)
            before = len(results)
            try:
                fn(ctx)
            except Exception as e:
                check(name + ': raised', False, repr(e)[:300])
            finally:
                set_latency(0)
                errs = [e for e in ctx.errors if not e.startswith('dialog: Page encryption')]
                if errs:
                    check(name + ': no page errors/dialogs', False, '; '.join(errs[:3]))
                ctx.close()
            ok = all(r[1] for r in results[before:])
            print(('ok   ' if ok else 'FAIL ') + name)
            for r in results[before:]:
                if not r[1]:
                    print('     -', r[0], '\n      ', r[2])
    failed = [r for r in results if not r[1]]
    print(f'\n{len(results) - len(failed)} checks passed, {len(failed)} failed')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
