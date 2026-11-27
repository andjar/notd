"""{{query ...}} in a block: results drawn in place (list, table, groups), the
block itself left out, a task ticked in the results, kept up to date, and the
ready-made queries in the slash menu."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000e1'
Q = '01900000-0000-7000-8000-0000000000e2'
BOOKS = '01900000-0000-7000-8000-0000000000e3'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': BOOKS, 'title': 'Books'},
          {'op': 'create', 'parent': BOOKS, 'text': 'Dune {type:: book} {rating:: 5}', 'sort': 'a0'},
          {'op': 'create', 'parent': BOOKS, 'text': 'Solaris {type:: book} {rating:: 4}', 'sort': 'a1'},
          {'op': 'create', 'parent': BOOKS, 'text': 'TODO Return Solaris to the library #book-club', 'sort': 'a2'},
          {'op': 'create', 'kind': 'page', 'id': P, 'title': 'Overview'},
          {'op': 'create', 'id': Q, 'parent': P, 'text': '{{query type:book sort:-rating}}', 'sort': 'a0'},
          {'op': 'create', 'parent': P, 'text': '{{query type:book view:table columns:rating}}', 'sort': 'a1'},
          {'op': 'create', 'parent': P, 'text': 'Club: {{query tag:book-club}}', 'sort': 'a2'},
          {'op': 'create', 'parent': P, 'text': '{{query task:sometimes}}', 'sort': 'a3'})


@test
def queries_show_their_results_and_keep_up():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Overview', errors)
            page.wait_for_selector(f'.block[data-id="{Q}"] .query-item')
            assert [t.split()[0] for t in page.locator(f'.block[data-id="{Q}"] .query-text').all_inner_texts()] == ['Dune', 'Solaris']
            assert '2 notes' in page.inner_text(f'.block[data-id="{Q}"] .query-head')
            assert page.locator('.query-table tbody tr').count() == 2
            assert page.locator('.query-table th').all_inner_texts() == ['Note', 'rating', 'Page']
            assert page.inner_text('.query-note.error').startswith('task:sometimes: task: takes open')

            club = page.locator('.block:has-text("Club:") .query-item')
            club.locator('.task-box').click()                          # tick a task that lives on another page
            page.wait_for_function("[...document.querySelectorAll('.query-item')].some((li) => li.textContent.includes('DONE') || li.querySelector('.task-box:checked'))")
            assert any(b['text'].startswith('DONE Return Solaris') for b in s.api('GET', 'page?title=Books')['blocks'])

            s.ops({'op': 'create', 'parent': BOOKS, 'text': 'Neuromancer {type:: book} {rating:: 3}'})  # another device adds a book
            page.evaluate('notd.feed.poll()')
            page.wait_for_function(f"document.querySelector('.block[data-id=\"{Q}\"] .query-head').textContent.includes('3 notes')")

            page.click(f'.block[data-id="{Q}"] .query-item .query-text')    # clicking a result does not edit the query
            assert page.locator(f'.block[data-id="{Q}"].editing').count() == 0
            page.click(f'.block[data-id="{Q}"] .query-head')                 # its heading does
            page.wait_for_selector(f'.block[data-id="{Q}"].editing')
            assert page.text_content(f'.block[data-id="{Q}"] .content') == '{{query type:book sort:-rating}}'
            page.keyboard.press('Escape')
            assert [e for e in errors if '400 (Bad Request)' not in e] == [], errors   # the bad query's answer
    finally:
        s.stop()


@test
def ready_made_queries_in_the_slash_menu():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Books', errors)
            page.wait_for_selector('.block')
            page.click('.add-block')
            page.keyboard.type('/query open tasks on')
            page.wait_for_selector('.menu:not([hidden]) li.active:has-text("Query: open tasks on this page")')
            page.keyboard.press('Enter')
            page.keyboard.press('Escape')
            page.wait_for_selector('.block .query-item:has-text("Return Solaris")')
            assert page.locator('.block .query-head code').inner_text() == 'task:open page:Books'
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
