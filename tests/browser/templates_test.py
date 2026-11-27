"""Templates: pages under notd/templates/ are in the slash menu; putting one into
an empty block fills it in (placeholders, children, the caret at {{cursor}}),
one undo takes it back, and "Save as a template" makes one from a block."""
import datetime
from urllib.parse import quote
from support import Server, browser, open_page, test, run

T = '01900000-0000-7000-8000-0000000000f1'
P = '01900000-0000-7000-8000-0000000000f2'
B = '01900000-0000-7000-8000-000000000f01'

OUTLINE = """() => { const out = []; const walk = (el, d) => { for (const b of el.querySelectorAll(':scope > .block')) {
  out.push('  '.repeat(d) + b.querySelector(':scope > .row > .content').textContent);
  walk(b.querySelector(':scope > .children'), d + 1); } }; walk(document.querySelector('.outline'), 0); return out; }"""


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': T, 'title': 'notd/templates/Meeting'},
          {'op': 'create', 'id': '01900000-0000-7000-8000-000000000f11', 'parent': T, 'text': 'Meeting on [[{{page}}]], {{date}}', 'sort': 'a0'},
          {'op': 'create', 'parent': '01900000-0000-7000-8000-000000000f11', 'text': 'Who:', 'sort': 'a0'},
          {'op': 'create', 'parent': '01900000-0000-7000-8000-000000000f11', 'text': 'Notes: {{cursor}}', 'sort': 'a1'},
          {'op': 'create', 'parent': T, 'text': 'Open tasks {{query task:open page:"{{page}}"}}', 'sort': 'a1'},
          {'op': 'create', 'kind': 'page', 'id': P, 'title': 'Kitchen'},
          {'op': 'create', 'id': B, 'parent': P, 'text': 'Plan the work', 'sort': 'a0'})


@test
def a_template_goes_into_an_empty_block_and_one_undo_takes_it_back():
    s = Server()
    setup(s)
    today = datetime.date.today().isoformat()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Kitchen', errors)
            page.wait_for_selector(f'.block[data-id="{B}"]')
            page.click('.add-block')
            page.keyboard.type('/template meet')
            page.wait_for_selector('.menu:not([hidden]) li.active:has-text("Template: Meeting")')
            page.keyboard.press('Enter')
            page.wait_for_function("document.querySelectorAll('.block').length === 5")
            page.keyboard.type('the budget')                       # the caret is at {{cursor}}
            page.keyboard.press('Escape')
            outline = page.evaluate(OUTLINE)
            assert outline[0] == 'Plan the work', outline
            assert outline[1] == f'Meeting on Kitchen, {today}', outline
            assert outline[2:4] == ['  Who:', '  Notes: the budget'], outline
            assert outline[4].startswith('Open tasks'), outline
            assert page.locator('.block .query-head code').inner_text() == 'task:open page:"Kitchen"'
            page.keyboard.press('Control+z')                       # the typing, then the template
            page.keyboard.press('Control+z')
            page.wait_for_function("document.querySelectorAll('.block').length <= 2")
            assert page.evaluate(OUTLINE)[0] == 'Plan the work'
            assert errors == [], errors
    finally:
        s.stop()


@test
def save_a_block_as_a_template():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Kitchen', errors)
            page.wait_for_selector(f'.block[data-id="{B}"]')
            page.click(f'.block[data-id="{B}"] .bullet', button='right')
            page.click('.menu li:has-text("Save as a template")')
            page.wait_for_selector('[data-panel=status] :text("Saved as the template “Plan the work”")')
            assert [x['text'] for x in s.api('GET', 'page?title=' + quote('notd/templates/Plan the work'))['blocks']] == ['Plan the work']
            page.click('.add-block')
            page.keyboard.type('/template plan')
            page.wait_for_selector('.menu:not([hidden]) li:has-text("Template: Plan the work")')
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
