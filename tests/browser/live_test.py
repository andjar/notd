"""Live updates: changes made elsewhere (another device, through the API) show
up after the next poll, without losing the block being edited."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
B = lambda n: f'01900000-0000-7000-8000-{n:012d}'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Live'},
          {'op': 'create', 'id': B(1), 'parent': P, 'text': 'one', 'sort': 'a0'},
          {'op': 'create', 'id': B(2), 'parent': P, 'text': 'two', 'sort': 'a1'},
          {'op': 'create', 'id': B(3), 'parent': P, 'text': 'three', 'sort': 'a2'})


def texts(page):
    return page.locator('.outline .content').all_text_contents()


def poll(page):
    page.evaluate('notd.feed.poll()')
    page.wait_for_timeout(300)


@test
def changes_from_elsewhere_appear_after_a_poll():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Live', errors)
            page.wait_for_selector('.content:text-is("three")')
            page.wait_for_function('notd.feed && notd.feed.since !== null')
            s.ops({'op': 'create', 'id': B(4), 'parent': P, 'text': 'four'},
                  {'op': 'edit', 'id': B(1), 'text': 'one (edited elsewhere)'},
                  {'op': 'delete', 'id': B(3)},
                  {'op': 'move', 'id': B(2), 'parent': B(1)})
            poll(page)
            assert texts(page) == ['one (edited elsewhere)', 'two', 'four'], texts(page)
            assert page.locator(f'.block[data-id="{B(1)}"] .block[data-id="{B(2)}"]').count() == 1
            assert errors == [], errors
    finally:
        s.stop()


@test
def the_block_being_edited_keeps_focus_and_its_typing():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Live', errors)
            page.wait_for_selector('.content:text-is("two")')
            page.wait_for_function('notd.feed && notd.feed.since !== null')
            page.click('.content:text-is("two")')
            page.keyboard.press('End')
            page.keyboard.type(' and more')
            s.ops({'op': 'edit', 'id': B(1), 'text': 'ONE'}, {'op': 'edit', 'id': B(2), 'text': 'two (theirs)'})
            poll(page)
            assert page.locator('.content[contenteditable]').text_content() == 'two and more', 'typing here wins while unsaved'
            assert page.evaluate("document.activeElement.closest('.block').dataset.id") == B(2)
            page.keyboard.type('!')
            assert texts(page)[0] == 'ONE'
            page.keyboard.press('Escape')
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            assert [x['text'] for x in s.api('GET', 'page?title=Live')['blocks']][1] == 'two and more!'
            # A block that is open but untouched takes the new text, caret kept.
            page.click('.content:text-is("three")')
            s.ops({'op': 'edit', 'id': B(3), 'text': 'three, from the phone'})
            poll(page)
            assert page.locator('.content[contenteditable]').text_content() == 'three, from the phone'
            assert errors == [], errors
    finally:
        s.stop()


@test
def a_page_renamed_elsewhere_follows_the_new_title():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Live', errors)
            page.wait_for_selector('.content:text-is("one")')
            page.wait_for_function('notd.feed && notd.feed.since !== null')
            s.ops({'op': 'edit', 'id': P, 'title': 'Live wire'})
            poll(page)
            page.wait_for_function("document.title === 'Live wire · notd'")
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
