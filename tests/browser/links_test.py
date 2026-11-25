"""Links: suggestions after [[ and #, linked references under the page, and
renaming a page, which rewrites every link to it."""
from support import Server, browser, open_page, test, run

A = '01900000-0000-7000-8000-0000000000a1'
B = '01900000-0000-7000-8000-0000000000b1'
X = '01900000-0000-7000-8000-000000000001'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': A, 'title': 'Østfold', 'text': '{alias:: Ostfold county}'},
          {'op': 'create', 'parent': A, 'text': 'Fredrikstad'},
          {'op': 'create', 'kind': 'page', 'id': B, 'title': 'Trips'},
          {'op': 'create', 'id': X, 'parent': B, 'text': 'went to [[Østfold]] and #Østfold'},
          {'op': 'create', 'parent': B, 'text': 'also [[ostfold county]]'},
          {'op': 'create', 'parent': B, 'text': 'nothing here'})


@test
def suggestions_after_double_brackets_and_hash():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Trips', errors)
            page.wait_for_selector('.content:text-is("nothing here")')
            page.click('.content:text-is("nothing here")')
            page.keyboard.press('End')
            page.keyboard.type(' [[øst')
            page.wait_for_selector('.suggest li')
            assert page.locator('.suggest li').all_inner_texts() == ['Østfold', 'New page: øst']
            page.keyboard.press('Enter')
            page.keyboard.type(' #tr')
            page.wait_for_selector('.suggest li:text("Trips")')
            page.keyboard.press('Tab')
            page.keyboard.type(' [[brand new')
            page.wait_for_selector('.suggest li:text("New page: brand new")')
            page.keyboard.press('ArrowDown')
            page.keyboard.press('Escape')
            assert page.locator('.suggest').is_hidden()
            assert page.locator('.content[contenteditable]').count() == 1, 'Escape closed the list, not the editor'
            page.keyboard.press('Escape')
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            texts = [x['text'] for x in s.api('GET', 'page?title=Trips')['blocks']]
            assert texts[2] == 'nothing here [[Østfold]] #Trips [[brand new', texts
            assert errors == [], errors
    finally:
        s.stop()


@test
def linked_references_list_blocks_that_link_here():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/%C3%98stfold', errors)
            page.wait_for_selector('[data-panel=backlinks] h2:text("2 linked references")')
            assert page.inner_text('[data-panel=backlinks] .ref-page') == 'Trips'
            blocks = page.locator('[data-panel=backlinks] .ref-block .content').all_inner_texts()
            assert [x.replace('\n', '') for x in blocks] == ['went to Østfold and #Østfold', 'also ostfold county'], blocks
            assert page.get_attribute('[data-panel=backlinks] .ref-block .bullet', 'href') == f'#/page/Trips/{X}'
            page.evaluate("location.hash = '#/page/Trips'")
            page.wait_for_selector('[data-panel=backlinks] h2:text("No linked references")')
            assert errors == [], errors
    finally:
        s.stop()


@test
def renaming_a_page_rewrites_the_links_to_it():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/%C3%98stfold', errors)
            page.wait_for_selector('[data-panel=backlinks] h2:text("2 linked references")')
            page.click('.page-title h1')
            page.keyboard.type('Viken county')
            page.keyboard.press('Enter')
            page.wait_for_function("document.title === 'Viken county · notd'")
            trips = [x['text'] for x in s.api('GET', 'page?title=Trips')['blocks']]
            assert trips[0] == 'went to [[Viken county]] and #[[Viken county]]', trips
            assert trips[1] == 'also [[ostfold county]]', 'links through an alias still work and stay'
            page.click('.page-title h1')
            page.keyboard.type('Trips')
            page.keyboard.press('Enter')
            page.wait_for_selector('.page-title + .error:has-text("already a page")')
            assert page.inner_text('.page-title h1') == 'Viken county'
            assert errors == [] or all('409' in e for e in errors), errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
