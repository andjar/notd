"""Loads a page and prints console errors (quick smoke check)."""
import os, sys, time
from playwright.sync_api import sync_playwright
BASE = os.environ.get('NOTD_URL', 'http://127.0.0.1:8765/')
with sync_playwright() as p:
    b = p.chromium.launch(); page = b.new_page()
    logs = []
    page.on('console', lambda m: logs.append(f'{m.type}: {m.text}') if m.type in ('error', 'warning') else None)
    page.on('pageerror', lambda e: logs.append(f'PAGEERROR: {e}'))
    page.on('requestfailed', lambda r: logs.append(f'REQUESTFAILED: {r.url}'))
    page.goto(BASE + 'page.php?page=Smoke-' + str(int(time.time())))
    page.wait_for_timeout(2500)
    print('notes rendered:', page.locator('.note-item').count())
    print('\n'.join(logs[:30]) or 'no console errors')
    b.close()
