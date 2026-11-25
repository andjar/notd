"""The outline's geometry: bullets on the middle of the first line for any text
(headings included), toggles level with bullets, guide lines under the parent's
bullet, one indent per level, at four screen widths; and a big page opens fast."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
B = lambda n: f'01900000-0000-7000-8000-{n:012d}'
LONG = 'A block long enough to wrap onto several lines on a phone, ' * 3

MEASURE = """() => [...document.querySelectorAll('.block')].map((b) => {
  const row = b.querySelector(':scope > .row');
  const content = row.querySelector('.content');
  const bullet = row.querySelector('.bullet').getBoundingClientRect();
  const dot = getComputedStyle(row.querySelector('.bullet'), '::before');
  const toggle = row.querySelector('.toggle').getBoundingClientRect();
  const range = document.createRange();
  range.selectNodeContents(content);
  const first = range.getClientRects()[0];
  const guide = b.querySelector(':scope > .children');
  return {
    text: content.textContent.slice(0, 20),
    bulletMid: bullet.top + bullet.height / 2, bulletX: bullet.left + bullet.width / 2,
    toggleMid: toggle.top + toggle.height / 2,
    lineMid: first.top + first.height / 2, lineTop: first.top,
    contentLeft: content.getBoundingClientRect().left, rowLeft: row.getBoundingClientRect().left,
    guideX: guide.children.length ? guide.getBoundingClientRect().left + parseFloat(getComputedStyle(guide, '::before').left) + 0.5 : null,
    depth: (() => { let d = 0, p = b.parentElement.closest('.block'); while (p) { d++; p = p.parentElement.closest('.block'); } return d; })(),
  };
})"""


def setup(s):
    ops = [{'op': 'create', 'kind': 'page', 'id': P, 'title': 'Geometry'}]
    texts = ['plain', '# Heading one', '## Heading two', '### Heading three', LONG, 'line one\nline two']
    for i, t in enumerate(texts):
        ops.append({'op': 'create', 'id': B(i + 1), 'parent': P, 'text': t})
    ops.append({'op': 'create', 'id': B(20), 'parent': B(1), 'text': 'child'})
    ops.append({'op': 'create', 'id': B(21), 'parent': B(20), 'text': '# grandchild heading'})
    ops.append({'op': 'create', 'id': B(22), 'parent': B(2), 'text': 'under a heading'})
    s.ops(*ops)


@test
def bullets_sit_on_the_middle_of_the_first_line_at_every_width():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            for width in (375, 768, 1100, 1440):
                errors = []
                page = open_page(b, s.url + '#/page/Geometry', errors, width=width)
                page.wait_for_selector('.block')
                rows = page.evaluate(MEASURE)
                assert len(rows) == 9, rows
                for r in rows:
                    assert abs(r['bulletMid'] - r['lineMid']) <= 1, (width, r)
                    assert abs(r['toggleMid'] - r['bulletMid']) <= 0.5, (width, r)
                indent = {r['depth']: r['rowLeft'] for r in rows}
                step = indent[1] - indent[0]
                assert step > 10 and abs((indent[2] - indent[1]) - step) < 0.5, (width, indent)
                by_text = {r['text']: r for r in rows}
                parent, child = by_text['plain'], by_text['child']
                assert abs(parent['guideX'] - parent['bulletX']) <= 1, (width, parent)
                assert child['bulletX'] > parent['contentLeft'] - 1, 'a child bullet sits under its parent text'
                assert errors == [], errors
                page.close()
    finally:
        s.stop()


@test
def a_page_of_3000_blocks_opens_in_under_a_second():
    s = Server()
    try:
        ops = [{'op': 'create', 'kind': 'page', 'id': P, 'title': 'Big'}]
        parents = [P]
        for i in range(3000):
            depth = i % 4
            parent = parents[depth] if depth < len(parents) else parents[-1]
            ops.append({'op': 'create', 'id': B(i + 1), 'parent': parent, 'text': f'block {i} with some words in it'})
            parents = parents[:depth + 1] + [B(i + 1)]
        for k in range(0, len(ops), 1000):
            s.ops(*ops[k:k + 1000])
        with browser() as b:
            errors = []
            page = open_page(b, s.url, errors)
            page.wait_for_selector('#app:not([hidden])')
            ms = page.evaluate("""async () => {
              const t = performance.now();
              location.hash = '#/page/Big';
              await new Promise((r) => { const check = () => document.querySelectorAll('.block').length >= 3000 ? r() : requestAnimationFrame(check); check(); });
              await new Promise((r) => requestAnimationFrame(() => r()));
              return performance.now() - t;
            }""")
            assert ms < 800, f'{ms:.0f} ms'
            print(f'     3,000 blocks drawn in {ms:.0f} ms')
            assert errors == [], errors
    finally:
        s.stop()


@test
def a_missing_page_can_be_created():
    s = Server()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Nowhere%20yet', errors)
            page.wait_for_selector('.missing button')
            page.click('.missing button')
            page.wait_for_selector('.outline', state='attached')
            assert s.api('GET', 'page?title=Nowhere%20yet')['page']['title'] == 'Nowhere yet'
            assert [e for e in errors if '404' not in e] == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
