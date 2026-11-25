"""Plugins in the browser: loaded with their styles and imports, a broken one
reported without stopping the rest, switched off and on, and safe mode."""
import os
from support import ROOT, Server, browser, open_page, test, run

ENV = {'NOTD_PLUGIN_DIRS': os.path.join(ROOT, 'tests/fixtures/plugins')}


@test
def plugins_load_and_problems_are_reported():
    s = Server(ENV)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.wait_for_selector('.hello')
            assert page.inner_text('.hello') == 'Hello from the hello plugin'
            assert page.evaluate("getComputedStyle(document.querySelector('.hello')).color") == 'rgb(1, 2, 3)'
            assert page.locator('[data-panel="hello:greeting"]').count() == 1, 'panel ids are namespaced'
            page.click('[data-panel=plugins] summary')
            names = page.locator('.plugins label').all_inner_texts()
            assert [n.strip() for n in names] == ['badserver', 'broken', 'hello', 'oldapi'], names
            problems = page.locator('.plugin-problem').all_inner_texts()
            assert any('broken on purpose' in p for p in problems), problems
            assert any('plugin API 0' in p for p in problems), problems
            assert page.evaluate('document.body.dataset.oldapi') is None, 'a plugin for another API is not run'
            assert [e for e in errors if 'broken on purpose' not in e] == [], errors
    finally:
        s.stop()


@test
def switching_a_plugin_off_and_safe_mode():
    s = Server(ENV)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.wait_for_selector('.hello')
            page.click('[data-panel=plugins] summary')
            page.uncheck('.plugins label:has-text("hello") input')
            page.wait_for_selector('[data-panel=plugins] button:text("Reload")')
            page.click('[data-panel=plugins] button:text("Reload")')
            page.wait_for_selector('#app:not([hidden])')
            page.wait_for_timeout(300)
            assert page.locator('.hello').count() == 0
            s.api('POST', 'plugins', {'name': 'hello', 'enabled': True})
            page.goto(s.url + '?safe=1')
            page.wait_for_selector('[data-panel=safe-mode]')
            page.wait_for_timeout(300)
            assert page.locator('.hello').count() == 0
            assert 'Safe mode' in page.inner_text('[data-panel=safe-mode]')
            page.goto(s.url)
            page.wait_for_selector('.hello')
            assert [e for e in errors if 'broken on purpose' not in e] == [], errors
    finally:
        s.stop()


@test
def plugin_syntax_draws_and_its_buttons_run_commands():
    s = Server(ENV)
    try:
        pid = '01900000-0000-7000-8000-0000000000a1'
        s.ops({'op': 'create', 'kind': 'page', 'id': pid, 'title': 'Syntax'},
              {'op': 'create', 'parent': pid, 'text': '!! call the plumber'},
              {'op': 'create', 'parent': pid, 'text': 'as {{@knuth84}} says'})
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Syntax', errors)
            page.wait_for_selector('.cite')
            assert page.inner_text('.cite') == 'knuth84'
            assert page.locator('.content:has(.flag)').text_content().strip() == '!call the plumber'
            page.click('.flag')
            assert page.evaluate('document.body.dataset.greeted') == 'block', 'the button runs its command for its block'
            assert page.locator('.content[contenteditable]').count() == 0, 'clicking it does not start editing'
            page.goto(s.url + '?safe=1#/page/Syntax')
            page.wait_for_selector('.content:has-text("knuth84")')
            assert page.locator('.cite').count() == 0, 'safe mode shows the plain text'
            assert [e for e in errors if 'broken on purpose' not in e] == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
