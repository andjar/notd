"""The RSS plugin: a block with {feed:: ...} gets the feed's items under it,
newest first, read as soon as the block is saved; "Read this feed now" in the
block's menu reads it again and says what came in, or why it could not."""
import os
import socket
import subprocess
import tempfile
import time
import urllib.request
from support import Server, browser, open_page, test, run, ROOT

PAGE = '01900000-0000-7000-8000-0000000000c1'
FEED = '01900000-0000-7000-8000-0000000000c2'


class Feeds:
    """The test feeds, served over HTTP by a second PHP server."""
    def __init__(self):
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            self.port = s.getsockname()[1]
        self.url = f'http://127.0.0.1:{self.port}/'
        self.log = tempfile.TemporaryFile()
        self.proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{self.port}', '-t', os.path.join(ROOT, 'plugins/rss/tests/fixtures')],
                                     stdout=self.log, stderr=subprocess.STDOUT)
        for _ in range(100):
            try:
                urllib.request.urlopen(self.url + 'atom.xml', timeout=1)
                return
            except Exception:
                time.sleep(0.05)
        raise RuntimeError('the feed server did not start')

    def stop(self):
        self.proc.terminate()
        self.proc.wait()


CHILDREN = f"""() => [...document.querySelectorAll('.block[data-id="{FEED}"] > .children > .block > .row > .content')].map((c) => c.textContent)"""


@test
def a_feed_block_gets_its_items_and_can_be_read_again():
    s, f = Server(), Feeds()
    try:
        s.ops({'op': 'create', 'kind': 'page', 'id': PAGE, 'title': 'Feeds'},
              {'op': 'create', 'id': FEED, 'parent': PAGE, 'text': f'Garden {{feed:: {f.url}rss2.xml}}'})
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Feeds', errors)
            page.wait_for_function(f'({CHILDREN})().length === 3')     # read after the block was saved
            items = page.evaluate(CHILDREN)
            assert items[0].startswith('Sowing (peas) in November'), items
            assert page.locator(f'.block[data-id="{FEED}"] .children a:has-text("Sowing")').get_attribute('href') == 'https://example.org/peas'

            page.click(f'.block[data-id="{FEED}"] > .row > .bullet', button='right')
            page.click('.menu.block-menu li:has-text("Read this feed now")')
            page.wait_for_selector('[data-panel=status] :text("Garden & Kitchen: no new items")')

            s.ops({'op': 'edit', 'id': FEED, 'text': f'Garden {{feed:: {f.url}gone.xml}}'})
            page.evaluate(f"notd.commands.run('rss.read', {{ id: '{FEED}' }})")
            page.wait_for_selector('[data-panel=status] [role=alert]:has-text("The server answered 404.")')
            assert errors == [], errors
    finally:
        s.stop()
        f.stop()


if __name__ == '__main__':
    run()
