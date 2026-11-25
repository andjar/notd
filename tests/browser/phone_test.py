"""On a phone: the editing toolbar does what Tab and friends do, nothing is
wider than the screen, and the sidebars are drawers."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
PHONE = dict(width=375, height=740, has_touch=True, is_mobile=True, device_scale_factor=2)


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Phone'},
          {'op': 'create', 'parent': P, 'text': 'first'},
          {'op': 'create', 'parent': P, 'text': 'second'},
          {'op': 'create', 'parent': P, 'text': 'averyveryverylongwordwithoutanyspacesthatmustwrapsomehowonasmallscreen ' * 2},
          {'op': 'create', 'parent': P, 'text': '```\nconst a_long_line_of_code = "that should scroll inside its own box and not make the page wider";\n```'},
          {'op': 'create', 'parent': P, 'text': '| a | b | c | d | e |\n|---|---|---|---|---|\n| one | two | three | four | five six seven eight |'})


def outline(page):
    return page.evaluate("""() => { const out = []; const walk = (el, d) => { for (const b of el.querySelectorAll(':scope > .block')) {
      out.push('  '.repeat(d) + b.querySelector(':scope > .row > .content').textContent.slice(0, 12)); walk(b.querySelector(':scope > .children'), d + 1); } };
      walk(document.querySelector('.outline'), 0); return out; }""")


@test
def the_toolbar_indents_moves_and_undoes():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Phone', errors, **PHONE)
            page.wait_for_selector('.content:text-is("second")')
            assert page.locator('.toolbar').is_hidden()
            page.tap('.content:text-is("second")')
            page.wait_for_selector('.toolbar:not([hidden])')
            page.tap('.toolbar [data-action=indent]')
            assert outline(page)[:2] == ['first', '  second'], outline(page)
            assert page.evaluate("document.activeElement.matches('.content[contenteditable]')"), 'still editing'
            page.tap('.toolbar [data-action=outdent]')
            page.tap('.toolbar [data-action=up]')
            assert outline(page)[:2] == ['second', 'first'], outline(page)
            page.tap('.toolbar [data-action=undo]')
            assert outline(page)[:2] == ['first', 'second'], outline(page)
            page.tap('.toolbar [data-action=link]')
            page.wait_for_selector('.suggest:not([hidden])')
            page.keyboard.press('Escape')
            page.tap('.toolbar [data-action=done]')
            page.wait_for_selector('.toolbar[hidden]', state='attached')
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            assert errors == [], errors
    finally:
        s.stop()


@test
def nothing_is_wider_than_the_screen():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Phone', errors, **PHONE)
            page.wait_for_selector('.content pre')
            widths = page.evaluate("""() => ({ doc: document.documentElement.scrollWidth, main: document.querySelector('.main').scrollWidth,
              view: innerWidth, mainClient: document.querySelector('.main').clientWidth })""")
            assert widths['doc'] <= widths['view'], widths
            assert widths['main'] <= widths['mainClient'] + 1, widths
            assert page.locator('.has-children').count() == 0 or page.locator('.has-children > .row > .toggle').evaluate('e => getComputedStyle(e).opacity') == '1'
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
