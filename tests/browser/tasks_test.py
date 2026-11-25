"""The tasks plugin: checkbox, keyword states, Ctrl+Enter, slash commands, undo,
the agenda on journal pages, and plain text when the plugin is off."""
import datetime
import re
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
B = lambda n: f'01900000-0000-7000-8000-{n:012d}'


def setup(s, today):
    yesterday = (today - datetime.timedelta(days=1)).isoformat()
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Work'},
          {'op': 'create', 'id': B(1), 'parent': P, 'text': 'TODO buy milk', 'sort': 'a0'},
          {'op': 'create', 'id': B(2), 'parent': P, 'text': f'TODO file taxes {{deadline:: {yesterday}}}', 'sort': 'a1'},
          {'op': 'create', 'id': B(3), 'parent': P, 'text': 'plain block', 'sort': 'a2'},
          {'op': 'create', 'id': B(4), 'parent': P, 'text': f'WAITING reply {{scheduled:: {today.isoformat()}}}', 'sort': 'a3'})


def text(s, n):
    return next(b['text'] for b in s.api('GET', 'page?title=Work')['blocks'] if b['id'] == B(n))


def saved(page):
    page.wait_for_selector('[data-panel=status] span:text("Saved")')


@test
def checkbox_keyword_and_keys():
    s = Server()
    setup(s, datetime.date.today())
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Work', errors)
            box = f'.block[data-id="{B(1)}"] .task-box'
            page.wait_for_selector(box)
            h = page.evaluate(f"[document.querySelector('{box}').getBoundingClientRect().height, parseFloat(getComputedStyle(document.querySelector('{box}').closest('.content')).lineHeight)]")
            assert h[0] <= h[1], h
            page.click(box)
            saved(page)
            assert re.fullmatch(r'DONE buy milk \{done:: \d{4}-\d\d-\d\d \d\d:\d\d\}', text(s, 1)), text(s, 1)
            assert page.locator(f'.block[data-id="{B(1)}"] .task-done').count() == 1
            assert page.locator('.content[contenteditable]').count() == 0, 'ticking does not start editing'
            page.keyboard.press('Control+z')
            saved(page)
            assert text(s, 1) == 'TODO buy milk', 'ticking is undoable'
            page.click(f'.block[data-id="{B(1)}"] .task-keyword')
            saved(page)
            assert text(s, 1).startswith('DOING buy milk {start:: '), text(s, 1)
            page.click(f'.block[data-id="{B(3)}"] .content')
            page.keyboard.press('Control+Enter')
            assert page.locator('.content[contenteditable]').text_content() == 'TODO plain block'
            page.keyboard.press('Control+Enter')
            assert page.locator('.content[contenteditable]').text_content().startswith('DONE plain block {done::')
            page.keyboard.press('Control+Enter')
            assert page.locator('.content[contenteditable]').text_content() == 'plain block'
            page.keyboard.type(' /wait')
            page.wait_for_selector('.menu.slash li:has-text("WAITING")')
            page.keyboard.press('Enter')
            page.keyboard.press('Escape')
            saved(page)
            assert text(s, 3) == 'WAITING plain block', text(s, 3)
            assert errors == [], errors
    finally:
        s.stop()


@test
def the_agenda_on_journal_pages():
    s = Server()
    today = datetime.date.today()
    setup(s, today)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/' + today.isoformat(), errors)
            page.wait_for_selector('.agenda')
            agenda = page.inner_text('.agenda')
            assert 'SCHEDULED' in agenda.upper() and 'reply' in agenda, agenda
            assert 'file taxes' in agenda and page.locator('.agenda .overdue').count() == 1, agenda
            assert 'buy milk' not in agenda
            page.evaluate("location.hash = '#/page/Work'")
            page.wait_for_selector('.task-box')
            assert page.locator('.agenda').count() == 0, 'only on journal pages'
            assert errors == [], errors
    finally:
        s.stop()


@test
def with_the_plugin_off_a_task_is_plain_text():
    s = Server()
    setup(s, datetime.date.today())
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '?safe=1#/page/Work', errors)
            page.wait_for_selector('.content:text-is("TODO buy milk")')
            assert page.locator('.task-box').count() == 0
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
