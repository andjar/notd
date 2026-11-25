"""Core panels: search, favourites, recent pages, the calendar, the save status."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Kitchen renovation'},
          {'op': 'create', 'parent': P, 'text': 'Choose tiles for the kitchen floor'},
          {'op': 'create', 'kind': 'page', 'title': '2026-11-03'})
    j = s.api('GET', 'page?title=2026-11-03')['page']['id']
    s.ops({'op': 'create', 'parent': j, 'text': 'wrote something'})


def ready(page):
    page.wait_for_selector('#app:not([hidden])')


@test
def search_finds_pages_and_blocks_and_offers_to_create():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            ready(page)
            page.fill('.search input', 'kitch')
            page.wait_for_selector('.search-results li')
            items = page.locator('.search-results li').all_inner_texts()
            assert items[0].startswith('Kitchen renovation'), items
            assert any('tiles' in i for i in items), items
            assert items[-1] == 'Create page kitch', items
            assert page.locator('.search-results mark').count() >= 1
            page.press('.search input', 'Enter')
            page.wait_for_function("document.title === 'Kitchen renovation · notd'")
            page.fill('.search input', 'Brand new page')
            page.wait_for_selector('.search-results .create')
            page.press('.search input', 'ArrowDown')
            page.press('.search input', 'Enter')
            page.wait_for_function("document.title === 'Brand new page · notd'")
            assert errors == [], errors
    finally:
        s.stop()


@test
def favourites_are_kept_in_the_notebook():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Kitchen%20renovation', errors)
            ready(page)
            page.wait_for_selector('.page-title h1:text("Kitchen renovation")')
            assert page.locator('[data-panel=favourites] .hint').count() == 1
            page.click('.star')
            page.wait_for_selector('[data-panel=favourites] a:text("Kitchen renovation")')
            assert page.get_attribute('.star', 'aria-pressed') == 'true'
            fav = s.api('GET', 'page?title=notd/favourites')
            assert [b['text'] for b in fav['blocks']] == ['[[Kitchen renovation]]'], fav
            page.reload()
            ready(page)
            page.wait_for_selector('.star.on')
            page.click('.star')
            page.wait_for_selector('[data-panel=favourites] .hint')
            assert s.api('GET', 'page?title=notd/favourites')['blocks'] == []
            assert errors == [], errors
    finally:
        s.stop()


@test
def recent_pages_and_the_calendar():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/2026-11-03', errors)
            ready(page)
            page.wait_for_selector('.calendar a.dot[data-day="2026-11-03"]')
            assert page.locator('.calendar a.selected').get_attribute('data-day') == '2026-11-03'
            assert 'November 2026' in page.text_content('.calendar-head h2')
            page.click('.calendar a[data-day="2026-11-10"]')
            page.wait_for_function("document.title === '2026-11-10 · notd'")
            page.click('[aria-label="Previous month"]')
            page.wait_for_selector('.calendar-head h2:has-text("October 2026")')
            page.evaluate("location.hash = '#/page/Kitchen%20renovation'")
            page.wait_for_selector('[data-panel=recent] li:first-child a:text("Kitchen renovation")')
            recent = page.locator('[data-panel=recent] a').all_inner_texts()
            assert recent[:3] == ['Kitchen renovation', '2026-11-10', '2026-11-03'], recent
            assert page.inner_text('[data-panel=status]') == 'Saved'
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
