"""Staging: a real-looking notebook (the Logseq test graph) imported with
tools/import.php opens page by page in the browser without a single error."""
import os
import subprocess
from support import ROOT, Server, browser, open_page, test, run


@test
def every_imported_page_opens_cleanly():
    s = Server()
    try:
        out = subprocess.check_output(['php', 'tools/import.php', 'tests/fixtures/logseq-graph'], cwd=ROOT,
                                      env={**os.environ, 'NOTD_DATA_DIR': s.data}).decode()
        assert 'pages' in out, out
        titles = [p['title'] for p in s.api('GET', 'pages')]
        assert len(titles) >= 10, titles
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.wait_for_selector('#app:not([hidden])')
            for title in titles:
                page.evaluate('t => { location.hash = "#/page/" + encodeURIComponent(t); }', title)
                page.wait_for_function('t => document.title === t + " · notd"', arg=title)
                page.wait_for_selector('.outline, .missing', state='attached')
                page.wait_for_timeout(100)
            # The graph has, on purpose, one missing image and one reference to a block that does not exist;
            # each shows up as a single 404 per page that shows it, and nothing else may go wrong.
            assert all('404' in e for e in errors) and len(errors) <= 4, errors
            page.evaluate('location.hash = "#/page/2023-11-14"')
            page.wait_for_selector('.content img')
            page.wait_for_selector('.block-ref, .page-ref')
    finally:
        s.stop()


if __name__ == '__main__':
    run()
