"""
Browser tests for the outline (indent/outdent, reorder, drag, sync, offline).

Runs the real UI in headless Chromium against a running server and checks,
after every action, that the DOM, the server and a fresh reload agree.

    PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8765 tests/browser/router.php
    python3 tests/browser/outline_test.py            # all tests
    python3 tests/browser/outline_test.py drag       # tests whose name contains "drag"

Requires: pip install playwright && playwright install chromium
"""
import json, os, sys, time, urllib.parse, urllib.request, uuid
from playwright.sync_api import sync_playwright

BASE = os.environ.get('NOTD_URL', 'http://127.0.0.1:8765/')
LATENCY_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'latency.txt')
RUN = str(int(time.time()))
results = []


def set_latency(ms):
    with open(LATENCY_FILE, 'w') as f:
        f.write(str(ms))


def api(path, body=None):
    req = urllib.request.Request(BASE + 'api/v1/' + path, data=json.dumps(body).encode() if body is not None else None,
                                 headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req) as r:
        j = json.load(r)
    assert j['status'] == 'success', j
    return j['data']


def seed(name, spec):
    """spec: list of strings, one leading space per nesting level."""
    page = api('pages.php?name=' + urllib.parse.quote(name))
    ids, stack, last = {}, [], {}
    ops = []
    for line in spec:
        depth = len(line) - len(line.lstrip(' '))
        text = line.strip()
        del stack[depth:]
        parent = stack[depth - 1] if depth else None
        nid = uuid.uuid4().hex[:16]
        ops.append({'op_id': 'seed-' + uuid.uuid4().hex, 'type': 'create', 'id': nid, 'page_id': page['id'],
                    'parent_id': parent, 'after': last.get(parent), 'content': text})
        last[parent] = nid
        ids[text] = nid
        stack.append(nid)
    api('ops.php', {'ops': ops})
    return page, ids


def server_tree(page_id):
    notes = api(f'notes.php?page_id={page_id}')
    out = []

    def walk(pid, d):
        kids = sorted([n for n in notes if (n['parent_note_id'] or None) == pid], key=lambda n: (n['sort_key'], n['id']))
        for n in kids:
            out.append(' ' * d + (n['content'] or '∅'))
            walk(n['id'], d + 1)
    walk(None, 0)
    return out


DOM_TREE = """() => {
  const out = [];
  const walk = (c, d) => { for (const el of c.children) {
      if (!el.classList.contains('note-item')) continue;
      const ct = el.querySelector(':scope > .note-header-row .note-content');
      const raw = (ct?.dataset.rawContent ?? ct?.textContent ?? '').trim();
      out.push(' '.repeat(d) + (raw || '∅'));
      const kids = el.querySelector(':scope > .note-children'); if (kids) walk(kids, d + 1); } };
  walk(document.getElementById('notes-container'), 0);
  return out;
}"""


class Ctx:
    def __init__(self, p):
        self.browser = p.chromium.launch()
        self.context = self.browser.new_context()
        self.errors = []

    def open(self, name):
        page = self.context.new_page()
        page.on('pageerror', lambda e: self.errors.append(str(e)))
        page.on('dialog', lambda d: (self.errors.append('dialog: ' + d.message), d.dismiss()))
        if os.environ.get('NOTD_TRACE'):
            page.on('console', lambda m: print('   console', m.type, m.text[:160]) if m.type in ('error', 'warning') else None)
            page.on('request', lambda r: print('   req', r.method, r.url.split('/')[-1][:50], (r.post_data or '')[:120]) if 'api/v1' in r.url else None)
            page.on('framenavigated', lambda f: print('   nav', f.url[-50:]) if f == page.main_frame else None)
        page.goto(BASE + 'page.php?page=' + urllib.parse.quote(name))
        page.wait_for_selector('#notes-container .note-item', timeout=15000)
        page.wait_for_timeout(300)
        return page

    def close(self):
        self.browser.close()


def focus(page, text, where='End'):
    page.locator('#notes-container .note-content', has_text=text).first.click()
    page.keyboard.press(where)


def wait_synced(page, timeout=15000):
    page.wait_for_function('() => window.notdOutline && window.notdOutline.outbox.size === 0', timeout=timeout)
    page.wait_for_timeout(150)


def dom(page):
    return page.evaluate(DOM_TREE)


def check(name, cond, detail=''):
    results.append((name, cond, detail))


def expect_tree(test, page, page_id, expected):
    wait_synced(page)
    d = dom(page)
    s = server_tree(page_id)
    check(test + ': DOM', d == expected, f'expected {expected}\n      got {d}')
    check(test + ': server', s == expected, f'expected {expected}\n      got {s}')


def expect_reload(test, page, expected):
    page.reload()
    page.wait_for_selector('#notes-container .note-item')
    page.wait_for_timeout(300)
    d = dom(page)
    check(test + ': after reload', d == expected, f'expected {expected}\n      got {d}')


def drag(page, src_id, dst_id, y=2):
    page.locator(f'.note-bullet[data-note-id="{src_id}"]').first.drag_to(
        page.locator(f'.note-item[data-note-id="{dst_id}"] > .note-header-row').first, target_position={'x': 30, 'y': y})
    page.wait_for_timeout(400)


# ---------------------------------------------------------------- tests

def test_indent_outdent(ctx):
    pg, ids = seed(f'T-indent-{RUN}', ['A', ' a1', ' a2', ' a3', 'B', 'C'])
    page = ctx.open(pg['name'])
    focus(page, 'a2'); page.keyboard.press('Shift+Tab')
    expect_tree('outdent middle child', page, pg['id'], ['A', ' a1', ' a3', 'a2', 'B', 'C'])
    focus(page, 'C'); page.keyboard.press('Tab')
    expect_tree('indent C under B', page, pg['id'], ['A', ' a1', ' a3', 'a2', 'B', ' C'])
    expect_reload('indent/outdent', page, ['A', ' a1', ' a3', 'a2', 'B', ' C'])


def test_rapid_tab_tab(ctx):
    pg, ids = seed(f'T-tabtab-{RUN}', ['A', 'B', ' b1', 'C'])
    set_latency(400)
    page = ctx.open(pg['name'])
    focus(page, 'C'); page.keyboard.press('Tab'); page.keyboard.press('Tab')
    check('Tab Tab: second Tab sees the first (DOM, before server answers)', dom(page) == ['A', 'B', ' b1', '  C'], str(dom(page)))
    expect_tree('Tab Tab', page, pg['id'], ['A', 'B', ' b1', '  C'])
    set_latency(0)


def test_enter_type_tab_with_latency(ctx):
    pg, ids = seed(f'T-entertab-{RUN}', ['A', 'B', 'C', 'D'])
    set_latency(300)
    page = ctx.open(pg['name'])
    focus(page, 'D'); page.keyboard.press('Enter'); page.keyboard.type('E'); page.keyboard.press('Tab')
    page.keyboard.type('!')
    expect_tree('Enter, type, Tab at 300 ms', page, pg['id'], ['A', 'B', 'C', 'D', ' E!'])
    set_latency(0)
    expect_reload('Enter, type, Tab', page, ['A', 'B', 'C', 'D', ' E!'])


def test_slow_network_text_kept(ctx):
    pg, ids = seed(f'T-slow-{RUN}', ['A', 'B'])
    set_latency(1500)
    page = ctx.open(pg['name'])
    focus(page, 'B'); page.keyboard.press('Enter'); page.keyboard.type('typed on a slow network')
    page.locator('#notes-container .note-content', has_text='A').first.click()
    expect_tree('slow network', page, pg['id'], ['A', 'B', 'typed on a slow network'])
    set_latency(0)


def test_plus_button_then_indent(ctx):
    pg, ids = seed(f'T-plus-{RUN}', ['A', 'B'])
    page = ctx.open(pg['name'])
    page.locator('#add-root-note-btn').click()
    page.keyboard.type('F'); page.keyboard.press('Enter'); page.keyboard.type('G'); page.keyboard.press('Tab')
    model_ids = page.evaluate("() => window.notdOutline.model.list().map(n => n.id)")
    check("'+' then indent: no duplicate notes in the model", len(model_ids) == len(set(model_ids)), str(model_ids))
    expect_tree("'+' then F Enter G Tab", page, pg['id'], ['A', 'B', 'F', ' G'])


def test_drag_twice(ctx):
    pg, ids = seed(f'T-drag-{RUN}', ['A', ' a1', ' a2', ' a3', 'B', 'C', 'D'])
    page = ctx.open(pg['name'])
    drag(page, ids['a3'], ids['a1'])
    drag(page, ids['C'], ids['B'])
    expected = ['A', ' a3', ' a1', ' a2', 'C', 'B', 'D']
    expect_tree('two drags', page, pg['id'], expected)
    expect_reload('two drags', page, expected)


def test_drag_into_other_parent(ctx):
    pg, ids = seed(f'T-drag2-{RUN}', ['A', ' a1', 'B', ' b1', ' b2'])
    page = ctx.open(pg['name'])
    drag(page, ids['b2'], ids['a1'])
    expected = ['A', ' b2', ' a1', 'B', ' b1']
    expect_tree('drag into another parent', page, pg['id'], expected)


def test_move_up_down(ctx):
    pg, ids = seed(f'T-move-{RUN}', ['A', 'B', ' b1', 'C'])
    page = ctx.open(pg['name'])
    focus(page, 'C'); page.keyboard.press('Alt+Shift+ArrowUp')
    expect_tree('Alt+Shift+Up moves C above B (with B\'s subtree intact)', page, pg['id'], ['A', 'C', 'B', ' b1'])
    focus(page, 'A'); page.keyboard.press('Alt+Shift+ArrowDown')
    expect_tree('Alt+Shift+Down', page, pg['id'], ['C', 'A', 'B', ' b1'])


def test_backspace_and_collapse(ctx):
    pg, ids = seed(f'T-bs-{RUN}', ['A', ' a1', 'B'])
    page = ctx.open(pg['name'])
    focus(page, 'B'); page.keyboard.press('Enter'); page.keyboard.press('Backspace')
    expect_tree('Backspace removes the empty note', page, pg['id'], ['A', ' a1', 'B'])
    page.locator(f'.note-item[data-note-id="{ids["A"]}"] > .note-header-row').hover()
    page.locator(f'.note-item[data-note-id="{ids["A"]}"] > .note-header-row .note-collapse-arrow').click()
    wait_synced(page)
    collapsed = api(f'notes.php?id={ids["A"]}')['collapsed']
    check('collapse is saved', int(collapsed) == 1, str(collapsed))
    page.reload(); page.wait_for_selector('.note-item')
    check('collapse survives reload', page.locator(f'.note-item[data-note-id="{ids["A"]}"].collapsed').count() == 1)


def test_new_note_becomes_parent_and_collapses(ctx):
    pg, ids = seed(f'T-arrow-{RUN}', ['A', 'B'])
    page = ctx.open(pg['name'])
    focus(page, 'B'); page.keyboard.press('Tab')
    has_arrow = page.locator(f'.note-item[data-note-id="{ids["A"]}"].has-children > .note-header-row .note-collapse-arrow').count()
    check('a note that gains children gets a collapse arrow', has_arrow == 1)


def test_requests_per_tab(ctx):
    pg, ids = seed(f'T-req-{RUN}', ['A', 'B', 'C'])
    page = ctx.open(pg['name'])
    focus(page, 'B'); wait_synced(page)
    posts = []
    page.on('request', lambda r: posts.append(r.url) if r.method == 'POST' and 'ops.php' in r.url else None)
    page.keyboard.press('Tab'); wait_synced(page)
    check('one request per Tab', len(posts) == 1, str(len(posts)))


def test_offline_edits_survive(ctx):
    pg, ids = seed(f'T-offline-{RUN}', ['A', 'B'])
    page = ctx.open(pg['name'])
    page.route('**/api/v1/ops.php', lambda route: route.abort())
    focus(page, 'B'); page.keyboard.type(' edited offline'); page.keyboard.press('Enter'); page.keyboard.type('new offline note')
    page.wait_for_timeout(1500)
    banner = page.locator('.sync-banner:not([hidden])').count()
    check('offline banner is shown', banner == 1)
    check('nothing reached the server yet', server_tree(pg['id']) == ['A', 'B'], str(server_tree(pg['id'])))
    page.close()  # close the tab while offline; ops stay in localStorage
    page2 = ctx.open(pg['name'])  # back online in a new tab: the queue is sent
    expect_tree('offline edits sent after reopening', page2, pg['id'], ['A', 'B edited offline', 'new offline note'])


def test_two_tabs(ctx):
    pg, ids = seed(f'T-tabs-{RUN}', ['A', 'B'])
    t1 = ctx.open(pg['name'])
    t2 = ctx.open(pg['name'])
    focus(t1, 'B'); t1.keyboard.press('Enter'); t1.keyboard.type('from tab 1')
    wait_synced(t1)
    t2.wait_for_timeout(1200)
    check('other tab picks up the change', dom(t2) == ['A', 'B', 'from tab 1'], str(dom(t2)))


def test_xss_sanitized(ctx):
    pg, ids = seed(f'T-xss-{RUN}', ['hello <img src=x onerror="document.title=\'PWNED\'">'])
    page = ctx.open(pg['name'])
    page.wait_for_timeout(500)
    check('note HTML is sanitized', page.title() != 'PWNED', page.title())


def test_transclusion_and_task(ctx):
    pg, ids = seed(f'T-misc-{RUN}', ['TODO write tests', 'target block'])
    api('ops.php', {'ops': [{'op_id': 'x-' + uuid.uuid4().hex, 'type': 'create', 'id': uuid.uuid4().hex[:16], 'page_id': pg['id'],
                             'parent_id': None, 'after': ids['target block'], 'content': '!{{' + ids['target block'] + '}}'}]})
    page = ctx.open(pg['name'])
    page.wait_for_timeout(800)
    check('transclusion renders', page.locator('.transcluded-content, .transclusion-placeholder:not(.error)').count() >= 1)
    page.locator('.task-checkbox').first.click()
    wait_synced(page)
    content = api(f'notes.php?id={ids["TODO write tests"]}')['content']
    check('task checkbox saves DONE', content.startswith('DONE'), content)


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
                check(name + ': raised', False, repr(e))
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
