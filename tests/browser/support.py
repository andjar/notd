"""Helpers for the browser tests: a notd server on a throw-away data folder,
a Chromium page that fails the test on console errors, and a tiny runner.

Each *_test.py file runs on its own: python3 tests/browser/frame_test.py
"""
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import traceback
import urllib.request
from contextlib import contextmanager

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


class Server:
    def __init__(self, env=None):
        self.data = tempfile.mkdtemp(prefix='notd-browser-')
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            self.port = s.getsockname()[1]
        self.url = f'http://127.0.0.1:{self.port}/'
        self.proc = subprocess.Popen(
            ['php', '-S', f'127.0.0.1:{self.port}', 'index.php'], cwd=ROOT,
            env={**os.environ, 'NOTD_DATA_DIR': self.data, 'PHP_CLI_SERVER_WORKERS': '4', **(env or {})},
            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        for _ in range(100):
            try:
                urllib.request.urlopen(self.url + 'api/v1/ping', timeout=1)
                return
            except Exception:
                time.sleep(0.05)
        raise RuntimeError('notd did not start')

    def api(self, method, name, body=None):
        req = urllib.request.Request(self.url + 'api/v1/' + name, method=method,
                                     data=json.dumps(body).encode() if body is not None else None,
                                     headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(req) as res:
            return json.loads(res.read())

    def ops(self, *ops):
        return self.api('POST', 'ops', {'ops': list(ops)})

    def stop(self):
        self.proc.terminate()
        self.proc.wait()
        shutil.rmtree(self.data, ignore_errors=True)


@contextmanager
def browser(**context):
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        b = p.chromium.launch()
        try:
            yield b
        finally:
            b.close()


def open_page(b, url, errors, width=1280, height=900, **kw):
    page = b.new_page(viewport={'width': width, 'height': height}, **kw)
    page.on('console', lambda m: m.type == 'error' and errors.append(m.text))
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(url)
    return page


TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


def run():
    failed = 0
    for fn in TESTS:
        try:
            fn()
            print(f'ok   {fn.__name__}')
        except Exception:
            failed += 1
            print(f'FAIL {fn.__name__}')
            traceback.print_exc()
    print(f'{len(TESTS) - failed} passed, {failed} failed')
    sys.exit(1 if failed else 0)
