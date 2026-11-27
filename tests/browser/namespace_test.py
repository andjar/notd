"""Pages inside pages: "Projects/Alpha/Notes" shows the pages it is under above
its title, and "Projects" lists the pages directly under it."""
from support import Server, browser, open_page, test, run


def setup(s):
    for title in ('Projects', 'Projects/Alpha', 'Projects/Alpha/Notes', 'Projects/Gamma/Plan', 'Other'):
        s.ops({'op': 'create', 'kind': 'page', 'title': title})


@test
def a_page_shows_the_pages_it_is_under_and_the_pages_under_it():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Projects%2FAlpha%2FNotes', errors)
            page.wait_for_selector('nav.parents')
            assert page.locator('nav.parents').inner_text().split() == ['Projects', '/', 'Alpha', '/'], \
                page.locator('nav.parents').inner_text()
            page.click('nav.parents a:has-text("Projects")')
            page.wait_for_selector('.child-pages li')
            assert page.locator('.child-pages a').all_inner_texts() == ['Alpha', 'Gamma']
            assert '1 under it' in page.locator('.child-pages li').nth(1).inner_text()
            assert page.locator('nav.parents').count() == 0
            page.click('.child-pages a:has-text("Alpha")')
            page.wait_for_selector('.child-pages a:has-text("Notes")')
            assert errors == [], errors
    finally:
        s.stop()


@test
def a_page_with_nothing_under_it_lists_nothing():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Other', errors)
            page.wait_for_selector('h1')
            page.wait_for_timeout(300)
            assert page.locator('.child-pages').count() == 0
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
