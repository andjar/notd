"""The journal: today first, then earlier days with notes, newest first,
loaded as you scroll; every day can be edited in place."""
import datetime
from support import Server, browser, open_page, test, run


def day(n):
    return (datetime.date.today() - datetime.timedelta(days=n)).isoformat()


def write(s, title, text):
    pid = s.api('GET', 'page?title=' + title)['page']['id']
    s.ops({'op': 'create', 'parent': pid, 'text': text})


@test
def today_first_then_earlier_days_with_notes():
    s = Server()
    try:
        for n in (1, 3, 40):
            write(s, day(n), f'note from {n} days ago')
        s.api('GET', 'page?title=' + day(2))  # opened, never written: not shown
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.wait_for_selector('.journal-day:nth-of-type(4)')
            days = page.eval_on_selector_all('.journal-day', 'els => els.map((e) => e.dataset.day)')
            assert days == [day(0), day(1), day(3), day(40)], days
            assert page.inner_text('.page-title h1') == day(0)
            assert page.locator('[data-panel=backlinks]').count() == 0
            assert page.locator('.journal-day.today .add-block').inner_text() == 'Click here to write'
            page.click('.journal-day.today .add-block')
            page.keyboard.type('written today')
            page.click(f'.journal-day[data-day="{day(3)}"] .content')
            page.keyboard.press('End')
            page.keyboard.type(' (edited)')
            page.keyboard.press('Escape')
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            assert [x['text'] for x in s.api('GET', 'page?title=' + day(0))['blocks']] == ['written today']
            assert [x['text'] for x in s.api('GET', 'page?title=' + day(3))['blocks']] == ['note from 3 days ago (edited)']
            page.click(f'.journal-day[data-day="{day(1)}"] h2 a')
            page.wait_for_function(f"document.title === '{day(1)} · notd'")
            assert page.locator('.journal-day').count() == 0, 'a single day opens as a page'
            assert errors == [], errors
    finally:
        s.stop()


@test
def earlier_days_load_as_you_scroll():
    s = Server()
    try:
        for n in range(1, 13):
            write(s, day(n * 2), f'day {n}\n' + 'filler\n' * 12)
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors, height=700)
            page.wait_for_selector('.journal-day:nth-of-type(6)')
            first = page.locator('.journal-day').count()
            assert first <= 11, first
            for _ in range(10):
                page.locator('.journal-more').scroll_into_view_if_needed()
                page.wait_for_timeout(200)
            page.wait_for_function("document.querySelectorAll('.journal-day').length === 13")
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
