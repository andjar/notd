"""Attachments: a pasted screenshot, files dropped onto a block or onto the page,
the block menu's and the slash menu's file picker, and a refused file."""
import base64
import os
import tempfile
from support import Server, browser, open_page, test, run

PAGE = '01900000-0000-7000-8000-0000000000c1'
B1 = '01900000-0000-7000-8000-000000000c01'
B2 = '01900000-0000-7000-8000-000000000c02'
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
PDF = base64.b64encode(b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n').decode()

# A File in a DataTransfer, sent to an element as a paste or a drop, as the browser would.
SEND = """([selector, kind, name, type, b64]) => {
  const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  const dt = new DataTransfer();
  dt.items.add(new File([bytes], name, { type }));
  const el = document.querySelector(selector);
  if (kind === 'paste') { el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); return; }
  el.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
  el.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
}"""


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': PAGE, 'title': 'Files'},
          {'op': 'create', 'id': B1, 'parent': PAGE, 'text': 'Receipt', 'sort': 'a0'},
          {'op': 'create', 'id': B2, 'parent': PAGE, 'text': 'Plan', 'sort': 'a1'})


def texts(s):
    return [b['text'] for b in s.api('GET', 'page?title=Files')['blocks']]


def wait_saved(page):
    page.wait_for_function("!document.querySelector('[data-panel=status]').textContent.includes('Saving')")
    page.wait_for_timeout(300)


@test
def a_pasted_screenshot_and_dropped_files_are_attached():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Files', errors)
            page.wait_for_selector(f'.block[data-id="{B1}"]')
            page.click(f'.block[data-id="{B1}"] .content')
            page.keyboard.press('End')
            page.evaluate(SEND, [f'.block[data-id="{B1}"] .content', 'paste', 'image.png', 'image/png', PNG])
            page.wait_for_function(f"document.querySelector('.block[data-id=\"{B1}\"] .content').textContent.includes('](file:')")
            text = page.text_content(f'.block[data-id="{B1}"] .content')
            assert text.startswith('Receipt ![Pasted image ') and text.endswith(')'), text   # named, after a space, at the caret
            page.keyboard.press('Escape')
            page.wait_for_selector(f'.block[data-id="{B1}"] .content img[src^="api/v1/file?id="]')
            assert page.evaluate(f"document.querySelector('.block[data-id=\"{B1}\"] img').naturalWidth") == 1

            page.evaluate(SEND, [f'.block[data-id="{B2}"] .content', 'drop', 'budget.pdf', 'application/pdf', PDF])
            page.wait_for_selector(f'.block[data-id="{B2}"] .content a[href^="api/v1/file?id="]:text("budget.pdf")')
            page.evaluate(SEND, ['.page', 'drop', 'again.pdf', 'application/pdf', PDF])
            page.wait_for_function("document.querySelectorAll('.outline > .block').length === 3")
            wait_saved(page)
            t = texts(s)
            assert t[1].startswith('Plan [budget.pdf](file:') and t[2].startswith('[again.pdf](file:'), t
            assert t[1][-37:] == t[2][-37:], 'the same content is one stored file'
            assert errors == [], errors
    finally:
        s.stop()


@test
def the_file_pickers_and_a_refused_file():
    s = Server()
    setup(s)
    folder = tempfile.mkdtemp()
    png, exe = os.path.join(folder, 'chart.png'), os.path.join(folder, 'tool.exe')
    with open(png, 'wb') as f:
        f.write(base64.b64decode(PNG))
    with open(exe, 'wb') as f:
        f.write(b'MZ not really a program')
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Files', errors)
            page.on('filechooser', lambda _: None)   # keeps Playwright catching file pickers between the two below
            page.wait_for_selector(f'.block[data-id="{B2}"]')
            page.click(f'.block[data-id="{B2}"] .bullet', button='right')
            with page.expect_file_chooser() as fc:
                page.click('.menu li:has-text("Attach a file")')
            fc.value.set_files(png)
            page.wait_for_selector(f'.block[data-id="{B2}"] .content img')

            page.click(f'.block[data-id="{B1}"] .content')
            page.keyboard.press('End')
            page.keyboard.type(' /file')
            page.wait_for_selector('.menu:not([hidden]) li.active:has-text("File or image")')
            with page.expect_file_chooser() as fc:
                page.keyboard.press('Enter')
            fc.value.set_files(exe)
            page.wait_for_selector('[data-panel=status] [role=alert]:has-text("tool.exe was not attached")')
            assert page.text_content(f'.block[data-id="{B1}"] .content') == 'Receipt', 'the /file is gone, nothing added'
            page.keyboard.press('Escape')
            wait_saved(page)
            t = texts(s)
            assert t[0] == 'Receipt' and t[1].startswith('Plan ![chart.png](file:'), t
            assert [e for e in errors if '415' not in e] == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
