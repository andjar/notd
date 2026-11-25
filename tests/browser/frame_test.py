"""The frame: it loads without errors, sidebars never cover the page, narrow
screens get drawers, dark mode follows the system, and the login gate works."""
import subprocess
from support import Server, browser, open_page, test, run


def box(page, sel):
    return page.locator(sel).bounding_box()


@test
def loads_without_errors_and_shows_todays_journal_route():
    s = Server()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.wait_for_selector('#app:not([hidden])')
            assert page.locator('[data-slot="app.status"]').inner_text().strip() == 'Saved'
            for slot in ['app.left', 'app.right', 'page.header', 'page.before', 'page.after', 'app.status']:
                assert page.locator(f'[data-slot="{slot}"]').count() == 1, slot
            assert page.title().endswith('· notd')
            page.evaluate("location.hash = '#/page/' + encodeURIComponent('Østfold')")
            page.wait_for_function("document.title === 'Østfold · notd'")
            page.wait_for_selector('.missing')
            assert [e for e in errors if '404' not in e] == [], errors  # a page that does not exist yet answers 404
    finally:
        s.stop()


@test
def sidebars_never_cover_the_page():
    s = Server()
    try:
        with browser() as b:
            for width in (1440, 1100, 900):
                errors = []
                page = open_page(b, s.url, errors, width=width)
                page.wait_for_selector('#app:not([hidden])')
                left, main = box(page, '.left'), box(page, '.main')
                assert left['x'] + left['width'] <= main['x'] + 0.5, (width, left, main)
                assert main['width'] >= 400, (width, main)
                page.click('[data-toggle="left"]')
                page.wait_for_timeout(50)
                assert box(page, '.main')['x'] < main['x'], 'hiding the sidebar widens the page'
                page.close()
    finally:
        s.stop()


@test
def narrow_screens_get_a_drawer():
    s = Server()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors, width=375, height=740)
            page.wait_for_selector('#app:not([hidden])')
            assert box(page, '.main')['width'] >= 370
            assert not page.locator('.left').is_visible()
            page.click('[data-toggle="left"]')
            page.locator('.left').wait_for(state='visible')
            page.click('.main', position={'x': 340, 'y': 300})
            page.wait_for_function("!document.querySelector('#app').classList.contains('show-left')")
    finally:
        s.stop()


@test
def dark_mode_follows_the_system():
    s = Server()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors, color_scheme='dark')
            page.wait_for_selector('#app:not([hidden])')
            bg = page.evaluate("getComputedStyle(document.body).backgroundColor")
            assert bg == 'rgb(24, 25, 27)', bg
    finally:
        s.stop()


@test
def the_gate_asks_for_the_password():
    hash_ = subprocess.check_output(['php', '-r', 'echo password_hash("correct horse", PASSWORD_DEFAULT);']).decode()
    s = Server({'NOTD_PASSWORD_HASH': hash_})
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.fill('input[name=password]', 'wrong')
            page.press('input[name=password]', 'Enter')
            page.wait_for_selector('.gate .error')
            assert page.inner_text('.gate .error') == 'Wrong password.'
            page.fill('input[name=password]', 'correct horse')
            page.press('input[name=password]', 'Enter')
            page.wait_for_selector('#app:not([hidden])')
            assert page.locator('#gate').is_hidden()
            assert [e for e in errors if '401' not in e] == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
