"""Views: a block with {view:: ...} shows its children through that view (here
by day and hour) instead of as the tree. The keys skip them, Enter at its end
makes a sibling, zooming into it shows the tree, and taking the view out of its
text brings the tree back."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000e1'
V = '01900000-0000-7000-8000-0000000000e2'
A = '01900000-0000-7000-8000-0000000000e3'
AFTER = '01900000-0000-7000-8000-0000000000e4'
VIEW = '{view:: group:published:hour sort:-published}'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Reading'},
          {'op': 'create', 'id': V, 'parent': P, 'text': f'Feed {VIEW}', 'sort': 'a0'},
          {'op': 'create', 'id': A, 'parent': V, 'text': 'Alpha {published:: 2026-11-27 09:15}', 'sort': 'a0'},
          {'op': 'create', 'parent': V, 'text': 'Beta {published:: 2026-11-28 14:05}', 'sort': 'a1'},
          {'op': 'create', 'parent': V, 'text': 'Gamma {published:: 2026-11-28 14:40}', 'sort': 'a2'},
          {'op': 'create', 'parent': V, 'text': 'Delta {published:: 2026-11-28 16:00}', 'sort': 'a3'},
          {'op': 'create', 'id': AFTER, 'parent': P, 'text': 'After the feed', 'sort': 'a1'})


SHOWN = f"""() => [...document.querySelectorAll('.block[data-id="{V}"] > .children > .query > *')]
    .map((e) => e.matches('h4') ? 'day ' + e.textContent : e.matches('section') ? e.querySelector('h5').textContent + ' '
      + [...e.querySelectorAll('.query-text')].map((t) => t.textContent.split(' ')[0]).join(',') : e.className)"""


@test
def children_show_through_the_view():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Reading', errors)
            page.wait_for_selector(f'.block[data-id="{V}"] .query section')
            shown = page.evaluate(SHOWN)
            days = page.evaluate("['2026-11-28', '2026-11-27'].map((d) => new Date(d + 'T00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))")
            assert [x.split(' ', 1)[0] for x in shown] == ['day', '16:00', '14:00', 'day', '09:00'], shown
            assert shown[1:3] == ['16:00 Delta', '14:00 Gamma,Beta'] and shown[4] == '09:00 Alpha', shown
            assert page.locator(f'.block[data-id="{A}"]').count() == 0             # not drawn as blocks
            assert page.locator(f'.block[data-id="{V}"] .query-head').count() == 0
            assert len(days) == 2

            page.click(f'.block[data-id="{V}"] > .row .content')                   # the keys go past the children
            page.keyboard.press('End')
            page.keyboard.press('ArrowDown')
            page.wait_for_selector(f'.block[data-id="{AFTER}"].editing')
            page.keyboard.press('ArrowUp')
            page.wait_for_selector(f'.block[data-id="{V}"].editing')
            page.keyboard.press('End')
            page.keyboard.press('Enter')                                           # a sibling, not a hidden child
            page.keyboard.type('Between')
            page.keyboard.press('Escape')
            page.wait_for_function(f"""() => {{ const b = [...document.querySelectorAll('.outline > .block')].map((e) => e.textContent);
                return b.length === 3 && b[1].startsWith('Between'); }}""")
            assert page.locator(f'.block[data-id="{V}"] .query-item').count() == 4

            s.ops({'op': 'create', 'parent': V, 'text': 'Epsilon {published:: 2026-11-28 17:30}'})   # from elsewhere
            page.evaluate('window.notd.feed.poll()')
            page.wait_for_function(f"document.querySelector('.block[data-id=\"{V}\"] .query section h5').textContent === '17:00'")
            assert errors == [], errors
    finally:
        s.stop()


@test
def zooming_in_shows_the_tree_and_the_view_can_be_taken_out():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + f'#/page/Reading/{V}', errors)
            page.wait_for_selector(f'.block[data-id="{A}"]')                       # zoomed in: the children as blocks
            assert page.locator(f'.block[data-id="{V}"] .query').count() == 0
            page.click(f'.block[data-id="{A}"] .content')
            page.keyboard.press('End')
            page.keyboard.type(' edited')
            page.keyboard.press('Escape')

            page.goto(s.url + '#/page/Reading')
            page.wait_for_selector(f'.block[data-id="{V}"] .query section')
            page.click(f'.block[data-id="{V}"] > .row .content')
            page.keyboard.press('End')
            for _ in range(len(VIEW) + 1):
                page.keyboard.press('Backspace')
            page.keyboard.press('Escape')
            page.wait_for_selector(f'.block[data-id="{A}"]')                       # the tree is back
            assert 'edited' in page.inner_text(f'.block[data-id="{A}"] .content')
            assert page.locator(f'.block[data-id="{V}"] .query').count() == 0
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
