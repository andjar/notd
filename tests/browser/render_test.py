"""Drawing text: Markdown, nothing unsafe gets through, links, references,
embeds, fields, files, and Markdown lists that stay clear of the bullets."""
import base64
import json
import urllib.request
import uuid
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
Q = '01900000-0000-7000-8000-0000000000b1'
R = '01900000-0000-7000-8000-000000000001'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')


def upload(s, data, name):
    boundary = uuid.uuid4().hex
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\n'
            f'Content-Type: application/octet-stream\r\n\r\n').encode() + data + f'\r\n--{boundary}--\r\n'.encode()
    req = urllib.request.Request(s.url + 'api/v1/files', data=body, method='POST',
                                 headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
    return json.loads(urllib.request.urlopen(req).read())


def setup(s):
    f = upload(s, PNG, 'dot.png')
    s.ops({'op': 'create', 'kind': 'page', 'id': Q, 'title': 'Other'},
          {'op': 'create', 'id': R, 'parent': Q, 'text': 'The referenced block'},
          {'op': 'create', 'parent': R, 'text': 'its child'},
          {'op': 'create', 'kind': 'page', 'id': P, 'title': 'Render'},
          {'op': 'create', 'parent': P, 'text': '**bold** and *italic* and `code`'},
          {'op': 'create', 'parent': P, 'text': '<script>window.pwned=1</script><img src="data:," onerror="window.pwned=2">'},
          {'op': 'create', 'parent': P, 'text': 'a [js](javascript:window.pwned=3) link'},
          {'op': 'create', 'parent': P, 'text': 'Go to [[Other]] or #topic'},
          {'op': 'create', 'parent': P, 'text': f'See (({R}))'},
          {'op': 'create', 'parent': P, 'text': f'{{{{embed (({R}))}}}}'},
          {'op': 'create', 'parent': P, 'text': '{rating:: 5} stars'},
          {'op': 'create', 'parent': P, 'text': f'![dot](file:{f["id"]})'},
          {'op': 'create', 'parent': P, 'text': '- one\n- two\n- [ ] a task'})


@test
def markdown_syntax_and_safety():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Render', errors)
            page.wait_for_selector('.block-ref:not(:text("…"))')
            assert page.locator('.content strong').inner_text() == 'bold'
            assert page.locator('.content em').inner_text() == 'italic'
            assert page.evaluate('window.pwned') is None, 'no script ran'
            assert page.locator('.content script').count() == 0
            assert page.locator('.content [onerror]').count() == 0
            href = page.locator('.content a:text("js")').get_attribute('href')
            assert not href or not href.startswith('javascript'), href
            assert page.inner_text('.block-ref') == 'The referenced block'
            assert page.get_attribute('.block-ref', 'href') == f'#/page/Other/{R}'
            page.wait_for_selector('.embed li')
            assert 'its child' in page.inner_text('.embed')
            assert page.text_content('.field') == 'rating 5'
            page.locator('.content img[alt=dot]').scroll_into_view_if_needed()
            page.wait_for_function("document.querySelector('.content img[alt=dot]').complete && document.querySelector('.content img[alt=dot]').naturalWidth === 1")
            assert page.get_attribute('.content img[alt=dot]', 'src').startswith('api/v1/file?id=')
            page.click('.page-ref')
            page.wait_for_function("document.title === 'Other · notd'")
            assert errors == [], errors
    finally:
        s.stop()


@test
def markdown_lists_and_checkboxes_stay_inside_the_text():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            for width in (375, 1280):
                errors = []
                page = open_page(b, s.url + '#/page/Render', errors, width=width)
                page.wait_for_selector('.content ul')
                m = page.evaluate("""() => { const c = [...document.querySelectorAll('.content')].find((x) => x.querySelector(':scope > ul'));
                  const row = c.closest('.row'); const bullet = row.querySelector('.bullet').getBoundingClientRect();
                  const li = c.querySelector('li'); const marker = li.getBoundingClientRect().left - 12;
                  const box = c.querySelector('input[type=checkbox]').getBoundingClientRect();
                  const lh = parseFloat(getComputedStyle(c).lineHeight);
                  return { bulletRight: bullet.right, marker, boxH: box.height, lh, contentLeft: c.getBoundingClientRect().left }; }""")
                assert m['marker'] >= m['contentLeft'] - 1 and m['marker'] > m['bulletRight'], m
                assert m['boxH'] <= m['lh'], m
                page.close()
    finally:
        s.stop()


@test
def editing_shows_the_raw_text_and_drawing_returns_after():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Render', errors)
            page.wait_for_selector('.content strong')
            page.click('.content:has(strong)', position={'x': 200, 'y': 5})
            assert page.locator('.content[contenteditable]').text_content() == '**bold** and *italic* and `code`'
            page.keyboard.press('Escape')
            assert page.locator('.content strong').count() == 1
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
