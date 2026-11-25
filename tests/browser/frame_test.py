"""The frame: it loads without errors (under its real CSP too, and from a checkout
with Windows line endings), sidebars never cover the page, narrow screens get
drawers, dark mode follows the system, and the login gate works."""
import os
import shutil
import subprocess
import tempfile
from support import ROOT, Server, browser, open_page, test, run

PAGE = '01900000-0000-7000-8000-0000000000f1'
BLOCK = lambda n: f'01900000-0000-7000-8000-{n:012d}'


def box(page, sel):
    return page.locator(sel).bounding_box()


def works_under_the_real_csp(s):
    """The app, a plugin, Markdown with sanitising and the palette, all under notd's
    own Content-Security-Policy, waiting with selectors only (no eval), and not one
    console error: a refused script, module or style is reported there."""
    s.ops({'op': 'create', 'kind': 'page', 'id': PAGE, 'title': 'Policy'},
          {'op': 'create', 'id': BLOCK(1), 'parent': PAGE, 'text': 'TODO **bold** <span onclick="alert(1)">safe</span>', 'sort': 'a0'})
    with browser() as b:
        errors = []
        page = open_page(b, s.url + '#/page/Policy', errors, csp=True)
        try:
            page.wait_for_selector(f'.block[data-id="{BLOCK(1)}"] .task-box', timeout=10000)   # the tasks plugin ran
        except Exception:
            raise AssertionError(f'the page did not come up; console: {errors}') from None
        assert page.locator(f'.block[data-id="{BLOCK(1)}"] strong').inner_text() == 'bold'   # marked
        assert page.locator(f'.block[data-id="{BLOCK(1)}"] span:text("safe")').count() == 1
        assert page.locator(f'.block[data-id="{BLOCK(1)}"] [onclick]').count() == 0          # DOMPurify
        page.keyboard.press('Control+k')
        page.wait_for_selector('.menu.palette:not([hidden]) input')
        assert errors == [], errors


@test
def works_under_its_real_csp():
    s = Server()
    try:
        works_under_the_real_csp(s)
    finally:
        s.stop()


@test
def works_from_a_checkout_with_windows_line_endings():
    """Git on Windows may check text files out with CRLF line endings. The app must
    not care: nothing in it depends on the exact bytes of a file (an inline script's
    CSP hash once did, and stopped every module)."""
    root = tempfile.mkdtemp(prefix='notd-crlf-')
    try:
        shutil.copy(os.path.join(ROOT, 'index.php'), root)
        for d in ('client', 'server', 'plugins'):
            shutil.copytree(os.path.join(ROOT, d), os.path.join(root, d), ignore=shutil.ignore_patterns('tests'))
        converted = 0
        for folder, _, names in os.walk(root):
            for name in names:
                if name.endswith(('.php', '.js', '.css', '.html', '.json', '.sql')):
                    path = os.path.join(folder, name)
                    with open(path, 'rb') as f:
                        data = f.read()
                    with open(path, 'wb') as f:
                        f.write(data.replace(b'\r\n', b'\n').replace(b'\n', b'\r\n'))
                    converted += 1
        assert converted > 50, converted
        s = Server(root=root)
        try:
            works_under_the_real_csp(s)
        finally:
            s.stop()
    finally:
        shutil.rmtree(root, ignore_errors=True)


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


@test
def start_up_problems_are_explained():
    """What a misconfigured server answers reaches the screen as a sentence that says
    what to change: a 404 from the web server itself (phpdesktop without its 404
    handler), or notd's own answer about missing PHP extensions."""
    s = Server()
    try:
        with browser() as b:
            answers = {
                'web server 404': (404, 'text/html', '<h1>Not Found</h1>', '"404_handler": "/index.php"'),
                'missing extensions': (500, 'application/json',
                                       '{"error": {"code": "php_extensions", "message": "PHP lacks extensions notd needs: intl. Add extension=intl to php.ini and start notd again."}}',
                                       'notd could not start: PHP lacks extensions notd needs: intl. Add extension=intl'),
            }
            for name, (status, kind, body, expected) in answers.items():
                errors = []
                page = b.new_page(bypass_csp=True)
                page.on('pageerror', lambda e: errors.append(str(e)))
                answer = dict(status=status, content_type=kind, body=body)
                page.route('**/api/v1/ping', lambda route, request, answer=answer: route.fulfill(**answer))
                page.goto(s.url)
                page.wait_for_function('document.body.textContent.startsWith("notd could not start")')
                text = page.text_content('body')
                assert expected in text, (name, text)
                assert '..' not in text, (name, text)
                assert errors == [], (name, errors)
                page.close()
    finally:
        s.stop()


if __name__ == '__main__':
    run()
