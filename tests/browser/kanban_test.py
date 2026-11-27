"""The kanban plugin: {{kanban}} shows tasks as cards in columns by state, and
any notes by a field; dragging a card (or Shift+arrow on it) changes its text,
whether it lives on the page on screen or on another one."""
from support import Server, browser, open_page, test, run

BOARD = '01900000-0000-7000-8000-0000000000b1'
WORK = '01900000-0000-7000-8000-0000000000b2'
K = '01900000-0000-7000-8000-0000000000b3'
T1 = '01900000-0000-7000-8000-000000000b01'
T2 = '01900000-0000-7000-8000-000000000b02'
T3 = '01900000-0000-7000-8000-000000000b03'
P1 = '01900000-0000-7000-8000-000000000b11'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': WORK, 'title': 'Work'},
          {'op': 'create', 'id': T1, 'parent': WORK, 'text': 'TODO Paint the door', 'sort': 'a0'},
          {'op': 'create', 'id': T2, 'parent': WORK, 'text': 'DOING Order tiles {start:: 2026-11-20 10:00}', 'sort': 'a1'},
          {'op': 'create', 'id': P1, 'parent': WORK, 'text': 'Launch the shop {stage:: Idea} #project', 'sort': 'a2'},
          {'op': 'create', 'parent': WORK, 'text': 'Write the manual {stage:: Shipped} #project', 'sort': 'a3'},
          {'op': 'create', 'kind': 'page', 'id': BOARD, 'title': 'Board'},
          {'op': 'create', 'id': K, 'parent': BOARD, 'text': '{{kanban}}', 'sort': 'a0'},
          {'op': 'create', 'id': T3, 'parent': BOARD, 'text': 'TODO Clear the hall', 'sort': 'a1'},
          {'op': 'create', 'parent': BOARD, 'text': '{{kanban tag:project group:stage columns:Idea,Building,Shipped}}', 'sort': 'a2'})


def text(s, title, id):
    return next(b['text'] for b in s.api('GET', 'page?title=' + title)['blocks'] if b['id'] == id)


def drag(page, card, column):
    box = page.locator(card).bounding_box()
    to = page.locator(column).bounding_box()
    page.mouse.move(box['x'] + 20, box['y'] + 10)
    page.mouse.down()
    page.mouse.move(box['x'] + 40, box['y'] + 20, steps=3)
    page.mouse.move(to['x'] + to['width'] / 2, to['y'] + to['height'] / 2, steps=8)
    page.mouse.up()


COLS = f'.block[data-id="{K}"] .kanban-column'


@test
def tasks_move_between_columns():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Board', errors)
            page.wait_for_selector(f'{COLS} .kanban-card')
            assert page.locator(f'{COLS} h4').all_inner_texts() == ['To do\n2', 'Doing\n1', 'Waiting\n0', 'Done\n0'], \
                page.locator(f'{COLS} h4').all_inner_texts()
            assert page.locator(f'{COLS} >> nth=1').locator('.kanban-text').inner_text() == 'Order tiles'

            drag(page, f'{COLS} [data-node="{T1}"]', f'{COLS} >> nth=1')          # a task on another page
            page.wait_for_function(f"() => document.querySelectorAll('{COLS}')[1].querySelector('[data-node=\"{T1}\"]')")
            page.wait_for_function(f"""() => fetch('api/v1/page?title=Work').then(r => r.json())
                .then(p => (window.__t = p.blocks.find(b => b.id === '{T1}').text).startsWith('DOING'))""")
            assert text(s, 'Work', T1).startswith('DOING Paint the door {start:: ')

            page.focus(f'{COLS} [data-node="{T3}"]')                               # a task on this page, by keyboard
            page.keyboard.press('Shift+ArrowRight')
            page.keyboard.press('Shift+ArrowRight')
            page.keyboard.press('Shift+ArrowRight')
            page.wait_for_function(f"document.querySelector('.block[data-id=\"{T3}\"] .content').textContent.startsWith('DONE')")
            assert page.locator(f'{COLS} >> nth=3').locator(f'[data-node="{T3}"]').count() == 1
            page.keyboard.press('Control+z')                                       # one move, one undo
            page.wait_for_function(f"document.querySelector('.block[data-id=\"{T3}\"] .content').textContent.startsWith('WAITING')")

            page.click(f'.block[data-id="{K}"] .query-head')                      # its heading edits the board's block
            page.wait_for_selector(f'.block[data-id="{K}"].editing')
            page.keyboard.press('Escape')
            assert errors == [], errors
    finally:
        s.stop()


@test
def notes_move_between_the_values_of_a_field():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Board', errors)
            board = '.block:has-text("Launch") .kanban'
            page.wait_for_selector(f'{board} .kanban-card')
            cols = f'{board} .kanban-column'
            assert [h.split('\n')[0] for h in page.locator(f'{cols} h4').all_inner_texts()] == ['Idea', 'Building', 'Shipped']
            assert page.locator(f'{cols} >> nth=0').locator('.kanban-text').inner_text() == 'Launch the shop #project'
            drag(page, f'{cols} [data-node="{P1}"]', f'{cols} >> nth=1')
            page.wait_for_function(f"""() => fetch('api/v1/page?title=Work').then(r => r.json())
                .then(p => p.blocks.find(b => b.id === '{P1}').text.includes('{{stage:: Building}}'))""")
            assert text(s, 'Work', P1) == 'Launch the shop {stage:: Building} #project'
            assert page.url.endswith('#/page/Board')                              # the drop opened nothing
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
