"""The git plugin: versions are committed after changes, listed under the
page, can be looked at, and an old one restored."""
import time
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
B1 = '01900000-0000-7000-8000-000000000001'


@test
def history_lists_versions_and_restores_one():
    s = Server({'NOTD_GIT_DELAY': '0'})
    try:
        s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Diary'},
              {'op': 'create', 'id': B1, 'parent': P, 'text': 'the first version'})
        time.sleep(1.1)
        s.ops({'op': 'edit', 'id': B1, 'text': 'the second version'})
        time.sleep(0.5)
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Diary', errors)
            page.wait_for_selector('[data-panel="git:history"] summary')
            page.click('[data-panel="git:history"] summary')
            page.wait_for_selector('.git-versions li:nth-child(2) button')
            assert page.locator('.git-versions li').count() == 2
            page.click('.git-versions li:nth-child(2) button')
            page.wait_for_selector('.git-version pre')
            assert 'the first version' in page.inner_text('.git-version pre')
            page.click('.git-restore')
            page.wait_for_selector('.content:text-is("the first version")')
            assert [x['text'] for x in s.api('GET', 'page?title=Diary')['blocks']] == ['the first version']
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
