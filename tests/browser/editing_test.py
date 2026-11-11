"""
Browser tests for editing behaviour: split/join, undo/redo, suggestions,
zoom, tasks, page actions (favourite, rename, delete, export).

    python3 tests/browser/editing_test.py   (server as in outline_test.py)
"""
import os, sys, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from outline_test import (Ctx, seed, api, server_tree, dom, focus, wait_synced, check, results, set_latency, RUN, BASE,
                          sync_playwright, urllib)


def expect(test, page, page_id, expected):
    wait_synced(page)
    check(test + ': DOM', dom(page) == expected, f'expected {expected}\n      got {dom(page)}')
    check(test + ': server', server_tree(page_id) == expected, f'expected {expected}\n      got {server_tree(page_id)}')


def caret_at(page, text, offset):
    focus(page, text, 'Home')
    for _ in range(offset):
        page.keyboard.press('ArrowRight')


def test_enter_splits_at_caret(ctx):
    pg, ids = seed(f'E-split-{RUN}', ['HelloWorld', 'B'])
    page = ctx.open(pg['name'])
    caret_at(page, 'HelloWorld', 5)
    page.keyboard.press('Enter')
    page.keyboard.type('!')
    expect('Enter splits the note at the caret', page, pg['id'], ['Hello', '!World', 'B'])


def test_enter_at_end_and_on_parent(ctx):
    pg, ids = seed(f'E-enter-{RUN}', ['A', ' a1', 'B'])
    page = ctx.open(pg['name'])
    focus(page, 'B'); page.keyboard.press('Enter'); page.keyboard.type('C')
    focus(page, 'A'); page.keyboard.press('Enter'); page.keyboard.type('a0')
    expect('Enter at the end: new sibling; on an open parent: first child', page, pg['id'], ['A', ' a0', ' a1', 'B', 'C'])


def test_enter_on_empty_nested_note_outdents(ctx):
    pg, ids = seed(f'E-outdent-{RUN}', ['A', ' a1'])
    page = ctx.open(pg['name'])
    focus(page, 'a1'); page.keyboard.press('Enter'); page.keyboard.press('Enter'); page.keyboard.type('B')
    expect('Enter on an empty last child moves it out', page, pg['id'], ['A', ' a1', 'B'])


def test_backspace_joins(ctx):
    pg, ids = seed(f'E-join-{RUN}', ['Hello', 'World', 'C'])
    page = ctx.open(pg['name'])
    focus(page, 'World', 'Home')
    page.keyboard.press('Backspace')
    page.keyboard.type('-')
    expect('Backspace at the start joins with the note above, caret at the seam', page, pg['id'], ['Hello-World', 'C'])


def test_undo_redo_structure(ctx):
    pg, ids = seed(f'E-undo-{RUN}', ['A', 'B', 'C'])
    page = ctx.open(pg['name'])
    focus(page, 'C'); page.keyboard.press('Tab')
    expect('indent', page, pg['id'], ['A', 'B', ' C'])
    page.keyboard.press('Control+z')
    expect('undo the indent', page, pg['id'], ['A', 'B', 'C'])
    page.keyboard.press('Control+Shift+z')
    expect('redo the indent', page, pg['id'], ['A', 'B', ' C'])


def test_undo_delete(ctx):
    pg, ids = seed(f'E-undodel-{RUN}', ['A', 'B', ' b1', 'C'])
    page = ctx.open(pg['name'])
    page.locator(f'.note-bullet[data-note-id="{ids["B"]}"]').click(button='right')
    page.locator('.menu-item[data-action="delete"]').click()
    page.locator('.notd-dialog .button-danger').click()
    expect('delete a note with a child', page, pg['id'], ['A', 'C'])
    page.locator('body').click(position={'x': 5, 'y': 5})
    page.keyboard.press('Control+z')
    expect('undo brings both back', page, pg['id'], ['A', 'B', ' b1', 'C'])


def test_typing_undo_is_native(ctx):
    pg, ids = seed(f'E-typeundo-{RUN}', ['A'])
    page = ctx.open(pg['name'])
    focus(page, 'A'); page.keyboard.type('bc')
    # The browser decides how typing is grouped for undo; enough presses take it all back,
    # and once the typing is gone, further presses must not undo outline changes.
    for _ in range(3):
        page.keyboard.press('Control+z')
    page.locator('body').click(position={'x': 5, 'y': 5})
    wait_synced(page)
    check('Ctrl+Z right after typing undoes the typing', server_tree(pg['id']) == ['A'], str(server_tree(pg['id'])))


def test_page_link_suggestions(ctx):
    target = f'E-target-{RUN}'
    api('pages.php', {'name': target})
    pg, ids = seed(f'E-link-{RUN}', ['A'])
    page = ctx.open(pg['name'])
    focus(page, 'A'); page.keyboard.type(' [[E-targ')
    page.wait_for_selector('.autocomplete-popup:not([hidden]) li', timeout=5000)
    page.keyboard.press('Enter')
    page.keyboard.type(' end')
    page.locator('body').click(position={'x': 5, 'y': 5})
    wait_synced(page)
    check('[[ suggests pages and completes the link', server_tree(pg['id']) == [f'A [[{target}]] end'], str(server_tree(pg['id'])))


def test_zoom(ctx):
    pg, ids = seed(f'E-zoom-{RUN}', ['A', ' a1', '  a2', 'B'])
    page = ctx.open(pg['name'])
    page.locator(f'.note-bullet[data-note-id="{ids["a1"]}"]').click()
    visible = [t.strip() for t in page.locator('#notes-container .note-content:visible').all_inner_texts()]
    check('zoom shows the note and its children only', visible == ['a1', 'a2'], str(visible))
    crumbs = page.locator('#note-focus-breadcrumbs-container').inner_text()
    check('breadcrumbs show the path', 'A' in crumbs and pg['name'] in crumbs, crumbs)
    page.locator('#note-focus-breadcrumbs-container a').first.click()
    visible = [t.strip() for t in page.locator('#notes-container .note-content:visible').all_inner_texts()]
    check('zoom out shows everything', visible == ['A', 'a1', 'a2', 'B'], str(visible))


def test_task_checkbox(ctx):
    pg, ids = seed(f'E-task-{RUN}', ['TODO buy milk'])
    page = ctx.open(pg['name'])
    page.locator('.task-checkbox').first.click()
    wait_synced(page)
    check('ticking a task marks it DONE', server_tree(pg['id']) == ['DONE buy milk'], str(server_tree(pg['id'])))


def test_favorite_rename_export_delete(ctx):
    pg, ids = seed(f'E-page-{RUN}', ['first', ' second'])
    page = ctx.open(pg['name'])
    page.locator('#favorite-toggle').click()
    page.wait_for_function("() => document.getElementById('favorite-toggle').textContent === '★'", timeout=5000)
    check('star marks the page as favourite', api('pages.php?id=%d' % pg['id'])['properties'].get('favorite') is not None)

    md = urllib.request.urlopen(BASE + 'api/v1/export.php?page=' + urllib.parse.quote(pg['name'])).read().decode()
    check('export gives the outline as Markdown', '- first\n\t- second' in md, md[:200])

    new_name = f'E-renamed-{RUN}'
    page.locator('#page-properties-gear').click()
    page.fill('#page-name-input', new_name)
    page.locator('#save-page-content-btn').click()
    page.wait_for_url('**page=' + new_name, timeout=5000)
    page.wait_for_selector('#notes-container .note-item')
    check('rename keeps the notes', dom(page) == ['first', ' second'], str(dom(page)))

    page.locator('#page-properties-gear').click()
    page.locator('#page-delete-button').click()
    page.locator('.notd-dialog .button-danger').click()
    page.wait_for_url(lambda url: 'page=' + new_name not in url, timeout=5000)
    try:
        api('pages.php?id=%d' % pg['id'])
        gone = False
    except Exception:
        gone = True
    check('delete removes the page', gone)


def test_embeds(ctx):
    src, sids = seed(f'E-src-{RUN}', ['embedded text', ' embedded child'])
    pg, ids = seed(f'E-embed-{RUN}', ['see !{{%s}}' % sids['embedded text']])
    page = ctx.open(pg['name'])
    page.wait_for_selector('.embedded-note-tree', timeout=5000)
    text = page.locator('.embedded-note-tree').inner_text()
    check('transclusion shows the note and its children', 'embedded text' in text and 'embedded child' in text, text)


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
                if ctx.errors:
                    check(name + ': no page errors/dialogs', False, '; '.join(ctx.errors[:3]))
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
