"""Commands: the palette, keys, the slash menu, a block's menu, and data-command."""
import datetime
import os
from support import ROOT, Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
ENV = {'NOTD_PLUGIN_DIRS': os.path.join(ROOT, 'tests/fixtures/plugins')}


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Cmd'},
          {'op': 'create', 'parent': P, 'text': 'first'},
          {'op': 'create', 'parent': P, 'text': 'second'})


def texts(s):
    return [b['text'] for b in s.api('GET', 'page?title=Cmd')['blocks']]


@test
def the_palette_runs_commands():
    s = Server(ENV)
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Cmd', errors)
            page.wait_for_selector('.hello')
            page.keyboard.press('Control+k')
            page.wait_for_selector('.menu.palette:not([hidden]) input')
            page.keyboard.type('dark')
            assert page.locator('.menu.palette li').all_inner_texts() == ['Switch between light and dark']
            page.keyboard.press('Enter')
            assert page.evaluate('document.documentElement.dataset.theme') in ('dark', 'light')
            page.keyboard.press('Control+k')
            page.keyboard.type('hello')
            items = page.locator('.menu.palette li').all_inner_texts()
            assert items[0].startswith('Say hello'), items
            page.keyboard.press('Escape')
            page.keyboard.press('Alt+Shift+H')
            assert page.evaluate('document.body.dataset.greeted') == 'yes', 'a plugin command by its key'
            page.evaluate('delete document.body.dataset.greeted')
            page.click('.greet')
            assert page.evaluate('document.body.dataset.greeted') == 'yes', 'data-command runs the command'
            assert [e for e in errors if 'broken on purpose' not in e] == [], errors
    finally:
        s.stop()


@test
def the_slash_menu_in_a_block():
    s = Server(ENV)
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Cmd', errors)
            page.wait_for_selector('.content:text-is("second")')
            page.click('.content:text-is("second")')
            page.keyboard.press('End')
            page.keyboard.type(' /tod')
            page.wait_for_selector('.menu.slash:not([hidden]) li')
            assert page.locator('.menu.slash li').all_inner_texts() == ["Today's date", 'Query: changed today']
            assert page.locator('.menu.slash li.active').inner_text() == "Today's date"
            page.keyboard.press('Enter')
            page.keyboard.type(' /head')
            page.wait_for_selector('.menu.slash li:has-text("Heading 2")')
            page.keyboard.press('ArrowDown')
            page.keyboard.press('Enter')
            page.keyboard.type(' /hel')
            page.wait_for_selector('.menu.slash li:has-text("Say hello")')
            page.keyboard.press('Tab')
            page.keyboard.press('Escape')
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            today = datetime.date.today().isoformat()
            assert texts(s)[1] == f'## second [[{today}]] hello!', texts(s)
            assert page.evaluate('document.body.dataset.greeted') == 'block'
            assert [e for e in errors if 'broken on purpose' not in e] == [], errors
    finally:
        s.stop()


@test
def a_blocks_menu_on_its_bullet():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Cmd', errors)
            page.wait_for_selector('.content:text-is("first")')
            page.click('.block:nth-child(1) > .row > .bullet', button='right')
            page.wait_for_selector('.menu.block-menu:not([hidden])')
            items = page.locator('.menu.block-menu li').all_inner_texts()
            assert 'Delete block' in items and 'Copy block reference' in items, items
            page.click('.menu.block-menu li:has-text("Delete block")')
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            assert texts(s) == ['second'], texts(s)
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
