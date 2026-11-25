"""Zooming into a block, breadcrumbs, and moving blocks by dragging their bullet."""
from support import Server, browser, open_page, test, run

P = '01900000-0000-7000-8000-0000000000a1'
B = lambda n: f'01900000-0000-7000-8000-{n:012d}'


def setup(s):
    s.ops({'op': 'create', 'kind': 'page', 'id': P, 'title': 'Zoom'},
          {'op': 'create', 'id': B(1), 'parent': P, 'text': 'Projects', 'sort': 'a0'},
          {'op': 'create', 'id': B(2), 'parent': B(1), 'text': 'Kitchen', 'sort': 'a0'},
          {'op': 'create', 'id': B(3), 'parent': B(2), 'text': 'tiles', 'sort': 'a0'},
          {'op': 'create', 'id': B(4), 'parent': B(2), 'text': 'paint', 'sort': 'a1'},
          {'op': 'create', 'id': B(5), 'parent': P, 'text': 'Elsewhere', 'sort': 'a1'})


def outline(page):
    return page.evaluate("""() => { const out = []; const walk = (el, d) => { for (const b of el.querySelectorAll(':scope > .block')) {
      out.push('  '.repeat(d) + b.querySelector(':scope > .row > .content').textContent); walk(b.querySelector(':scope > .children'), d + 1); } };
      walk(document.querySelector('.outline'), 0); return out; }""")


def server(s):
    data = s.api('GET', 'page?title=Zoom')
    depth = {P: -1}
    out = []
    for b in data['blocks']:
        depth[b['id']] = depth[b['parent']] + 1
        out.append('  ' * depth[b['id']] + b['text'])
    return out


@test
def clicking_a_bullet_zooms_in_with_breadcrumbs():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Zoom', errors)
            page.wait_for_selector('.content:text-is("Kitchen")')
            page.click(f'.block[data-id="{B(2)}"] > .row > .bullet')
            page.wait_for_selector('.crumbs')
            assert outline(page) == ['Kitchen', '  tiles', '  paint']
            assert page.locator('.crumbs a').all_inner_texts() == ['Zoom', 'Projects']
            page.click('.content:text-is("Kitchen")')
            page.keyboard.press('End')
            page.keyboard.press('Enter')
            page.keyboard.type('wiring')
            assert outline(page) == ['Kitchen', '  wiring', '  tiles', '  paint'], 'Enter on the zoomed block adds a child in view'
            page.keyboard.press('Shift+Tab')
            assert outline(page) == ['Kitchen', '  wiring', '  tiles', '  paint'], 'cannot outdent out of the zoom'
            page.keyboard.press('Escape')
            page.click('.crumbs a:text("Projects")')
            page.wait_for_selector(f'.block.zoomed[data-id="{B(1)}"]')
            assert outline(page)[0] == 'Projects'
            page.click('.crumbs a:text("Zoom")')
            page.wait_for_selector('.content:text-is("Elsewhere")')
            assert page.locator('.crumbs').count() == 0
            assert errors == [], errors
    finally:
        s.stop()


def drag(page, src_id, dst_selector, dx=0, dy=0):
    bullet = page.locator(f'.block[data-id="{src_id}"] > .row > .bullet').bounding_box()
    dst = page.locator(dst_selector).bounding_box()
    page.mouse.move(bullet['x'] + bullet['width'] / 2, bullet['y'] + bullet['height'] / 2)
    page.mouse.down()
    page.mouse.move(bullet['x'] + 20, bullet['y'] + 20, steps=3)
    page.mouse.move(dst['x'] + dx, dst['y'] + dy, steps=5)
    page.mouse.up()


@test
def dragging_a_bullet_moves_the_block():
    s = Server()
    setup(s)
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Zoom', errors)
            page.wait_for_selector('.content:text-is("paint")')
            # paint above tiles
            drag(page, B(4), f'.block[data-id="{B(3)}"] > .row', dx=10, dy=2)
            assert outline(page) == ['Projects', '  Kitchen', '    paint', '    tiles', 'Elsewhere'], outline(page)
            # Elsewhere into Kitchen as a child: below Kitchen's row, to the right
            row = f'.block[data-id="{B(2)}"] > .row'
            box = page.locator(row + ' > .content').bounding_box()
            drag(page, B(5), row, dx=box['x'] - page.locator(row).bounding_box()['x'] + 80, dy=page.locator(row).bounding_box()['height'] - 3)
            assert outline(page) == ['Projects', '  Kitchen', '    Elsewhere', '    paint', '    tiles'], outline(page)
            assert page.evaluate('location.hash') == '#/page/Zoom', 'a drag is not a click on the bullet'
            page.wait_for_selector('[data-panel=status] span:text("Saved")')
            assert server(s) == outline(page)
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
