"""The excalidraw plugin: /Drawing opens Excalidraw (loaded only then, under
notd's real Content-Security-Policy); saving puts the picture in the block;
clicking it opens it again, and saving replaces it (one undo takes that back).
A Logseq drawing (.excalidraw file) opens the same way and becomes a picture."""
import json
import time
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000d1'
B = '01900000-0000-7000-8000-0000000000d2'
L = '01900000-0000-7000-8000-0000000000d3'
EDITOR = '.drawing-editor .excalidraw'


def problems(errors):
    """Console errors, without the one newer Chrome logs because Excalidraw listens for "unload" (harmless)."""
    return [e for e in errors if 'unload is not allowed' not in e]


def text(s, id, unlike=None):
    """The block's text on the server, once it is no longer `unlike` (edits reach the server a moment later)."""
    for _ in range(100):
        t = next(b['text'] for b in s.api('GET', 'page?title=Sketches')['blocks'] if b['id'] == id)
        if t != unlike:
            return t
        time.sleep(0.1)
    return t


def draw(page, tool, x, y):
    page.keyboard.press(tool)
    page.mouse.move(x, y)
    page.mouse.down()
    page.mouse.move(x + 150, y + 100, steps=6)
    page.mouse.up()
    page.keyboard.press('Escape')


def saved(page):
    page.click('.drawing-save')
    page.wait_for_selector('.drawing-editor', state='detached', timeout=15000)


@test
def draw_save_open_again_and_undo():
    s = Server()
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Sketches'},
          {'op': 'create', 'id': B, 'parent': P, 'text': 'Kitchen plan', 'sort': 'a0'})
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Sketches', errors, csp=True)
            page.on('response', lambda r: r.status >= 400 and errors.append(f'{r.status} {r.url}'))
            page.wait_for_selector(f'.block[data-id="{B}"]')
            assert page.evaluate('typeof window.ExcalidrawLib') == 'undefined'        # not loaded with the page
            page.click(f'.block[data-id="{B}"] .content')
            page.keyboard.press('End')
            page.keyboard.type(' /drawing')
            page.wait_for_selector('.menu:not([hidden]) li.active:has-text("Drawing")')
            page.keyboard.press('Enter')
            page.wait_for_selector(EDITOR, timeout=20000)

            page.keyboard.press('Control+k')                                       # notd's keys are off in the editor
            assert page.locator('.menu.palette:not([hidden])').count() == 0
            draw(page, 'r', 400, 300)
            saved(page)
            first = text(s, B, unlike='Kitchen plan')
            assert first.startswith('Kitchen plan ![Drawing ') and first.endswith(')') and '.excalidraw.svg](file:' in first, first
            img = page.locator(f'.block[data-id="{B}"] img')
            img.wait_for()
            svg = page.evaluate("(src) => fetch(src).then((r) => r.text())", img.get_attribute('src'))
            assert '<svg' in svg and 'payload-type:application/vnd.excalidraw+json' in svg, svg[:300]

            img.click()                                                            # opens again, not the block's text
            page.wait_for_selector(EDITOR, timeout=20000)
            assert page.locator(f'.block[data-id="{B}"].editing').count() == 0
            page.click('.drawing-cancel')                                          # nothing changed: closes at once
            page.wait_for_selector('.drawing-editor', state='detached')

            img.click()
            page.wait_for_selector(EDITOR, timeout=20000)
            draw(page, 'o', 700, 300)
            page.click('.drawing-cancel')                                          # a change: asks for a second click
            assert page.inner_text('.drawing-cancel') == 'Discard changes'
            saved(page)
            second = text(s, B, unlike=first)
            assert second != first and second.count('](file:') == 1, second

            page.keyboard.press('Control+z')                                       # the old picture is back
            page.wait_for_function(f"""() => fetch('api/v1/page?title=Sketches').then((r) => r.json())
                .then((p) => p.blocks[0].text === {json.dumps(first)})""")
            assert problems(errors) == [], errors
    finally:
        s.stop()


@test
def a_logseq_drawing_opens_and_becomes_a_picture():
    s = Server()
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Sketches'})
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Sketches', errors)
            node = page.evaluate("""() => { const f = new FormData();
                f.append('file', new Blob(['{"type":"excalidraw","version":2,"elements":[]}'], { type: 'application/json' }), 'old.excalidraw');
                return fetch('api/v1/files', { method: 'POST', body: f }).then((r) => r.json()); }""")
            s.ops({'op': 'create', 'id': L, 'parent': P, 'text': f"Drawing [old.excalidraw](file:{node['id']})"})
            page.reload()
            page.click(f'.block[data-id="{L}"] a:has-text("old.excalidraw")')
            page.wait_for_selector(EDITOR, timeout=20000)
            draw(page, 'r', 400, 300)
            saved(page)
            after = text(s, L, unlike=f"Drawing [old.excalidraw](file:{node['id']})")
            assert after.startswith('Drawing ![old.excalidraw.svg](file:') and node['id'] not in after, after
            page.wait_for_selector(f'.block[data-id="{L}"] img[alt="old.excalidraw.svg"]')
            assert problems(errors) == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
