"""Editing keys, one test each: typing, Enter, Shift+Enter, Tab, Shift+Tab,
Backspace, Delete, arrows, Alt+Shift+arrows, Ctrl+arrows, Escape, undo and redo.
Every test also checks that the server ends up with the same outline."""
from support import Server, browser, open_page, test, run

PAGE = 'Editing'
OUTLINE = """() => { const out = []; const walk = (el, d) => { for (const b of el.querySelectorAll(':scope > .block')) {
  out.push('  '.repeat(d) + (b.classList.contains('editing') ? b.querySelector(':scope > .row > .content').textContent : b.querySelector(':scope > .row > .content').textContent));
  walk(b.querySelector(':scope > .children'), d + 1); } }; walk(document.querySelector('.outline'), 0); return out; }"""


def server_outline(s):
    data = s.api('GET', 'page?title=' + PAGE)
    depth = {data['page']['id']: -1}
    out = []
    for b in data['blocks']:
        depth[b['id']] = depth[b['parent']] + 1
        out.append('  ' * depth[b['id']] + b['text'])
    return out


def caret(page):
    return page.evaluate("""() => { const s = getSelection(); const el = document.activeElement;
      const r = document.createRange(); r.selectNodeContents(el); r.setEnd(s.focusNode, s.focusOffset); return [el.closest('.block').dataset.id, r.toString().length]; }""")


class Session:
    def __init__(self, b, s, lines=()):
        self.s = s
        if lines:
            pid = '01900000-0000-7000-8000-0000000000a1'
            ops = [{'op': 'create', 'kind': 'page', 'id': pid, 'title': PAGE}]
            parents = [pid]
            for i, line in enumerate(lines):
                d = (len(line) - len(line.lstrip(' '))) // 2
                bid = f'01900000-0000-7000-8000-{i + 1:012d}'
                ops.append({'op': 'create', 'id': bid, 'parent': parents[d], 'text': line.strip(' ')})
                parents = parents[:d + 1] + [bid]
            s.ops(*ops)
        else:
            s.ops({'op': 'create', 'kind': 'page', 'title': PAGE})
        self.errors = []
        self.page = open_page(b, s.url + '#/page/' + PAGE, self.errors)
        self.page.wait_for_selector('.outline', state='attached')

    def click(self, text):
        self.page.click(f'.content:text-is("{text}")')

    def outline(self):
        return self.page.evaluate(OUTLINE)

    def saved(self):
        self.page.locator('.content[contenteditable]').evaluate('el => el.blur()') if self.page.locator('.content[contenteditable]').count() else None
        self.page.wait_for_selector('[data-panel=status] span:text("Saved")')
        self.page.wait_for_timeout(50)
        return server_outline(self.s)

    def check(self, expected):
        assert self.outline() == expected, self.outline()
        got = self.saved()
        assert got == expected, ('server', got)
        assert self.errors == [], self.errors


def case(lines=()):
    def wrap(fn):
        def runner():
            s = Server()
            try:
                with browser() as b:
                    fn(Session(b, s, lines))
            finally:
                s.stop()
        runner.__name__ = fn.__name__
        return test(runner)
    return wrap


@case()
def typing_into_an_empty_page(t):
    t.page.click('.add-block')
    t.page.keyboard.type('Hello, Østfold')
    t.check(['Hello, Østfold'])


@case(['hello world'])
def enter_splits_the_block_at_the_caret(t):
    t.click('hello world')
    t.page.keyboard.press('End')
    for _ in range(6):
        t.page.keyboard.press('ArrowLeft')
    t.page.keyboard.press('Enter')
    t.page.keyboard.type('new ')
    t.check(['hello', 'new  world'])


@case(['one'])
def shift_enter_adds_a_line_inside_the_block(t):
    t.click('one')
    t.page.keyboard.press('End')
    t.page.keyboard.press('Shift+Enter')
    t.page.keyboard.type('two')
    t.check(['one\ntwo'])


@case(['a', 'b', 'c'])
def tab_indents_and_shift_tab_outdents_keeping_the_caret(t):
    t.click('b')
    t.page.keyboard.press('End')
    t.page.keyboard.press('Tab')
    assert t.outline() == ['a', '  b', 'c']
    t.page.keyboard.type('!')
    t.page.keyboard.press('ArrowDown')
    t.page.keyboard.press('Tab')
    t.page.keyboard.press('Tab')
    t.page.keyboard.press('Shift+Tab')
    t.check(['a', '  b!', '  c'])


@case(['first', 'second', '  child'])
def backspace_at_the_start_joins_with_the_block_above(t):
    t.click('second')
    t.page.keyboard.press('Home')
    t.page.keyboard.press('Backspace')
    assert caret(t.page)[1] == 5
    t.page.keyboard.type('|')
    t.check(['first|second', '  child'])


@case(['keep', ''])
def backspace_in_an_empty_block_removes_it(t):
    t.page.click('.block:nth-child(2) .content')
    t.page.keyboard.press('Backspace')
    t.page.keyboard.type('!')
    t.check(['keep!'])


@case(['left', 'right'])
def delete_at_the_end_joins_the_block_below(t):
    t.click('left')
    t.page.keyboard.press('End')
    t.page.keyboard.press('Delete')
    t.page.keyboard.type('-')
    t.check(['left-right'])


@case(['top', 'middle line', 'bottom'])
def arrows_move_between_blocks_on_the_first_and_last_line(t):
    t.click('middle line')
    t.page.keyboard.press('ArrowUp')
    t.page.keyboard.type('1')
    t.page.keyboard.press('ArrowDown')
    t.page.keyboard.press('ArrowDown')
    t.page.keyboard.type('3')
    t.check(['top1', 'middle line', '3bottom'])


@case(['a', 'b', '  b1', 'c'])
def alt_shift_arrows_move_the_block_with_its_children(t):
    t.click('b')
    t.page.keyboard.press('Alt+Shift+ArrowUp')
    assert t.outline() == ['b', '  b1', 'a', 'c']
    t.page.keyboard.press('Alt+Shift+ArrowDown')
    t.page.keyboard.press('Alt+Shift+ArrowDown')
    t.page.keyboard.type('!')
    t.check(['a', 'c', 'b!', '  b1'])


@case(['parent', '  child'])
def ctrl_arrows_fold_and_unfold(t):
    t.click('parent')
    t.page.keyboard.press('Control+ArrowUp')
    assert t.page.locator('.block.collapsed').count() == 1
    assert not t.page.locator('.content:text-is("child")').is_visible()
    t.page.keyboard.press('Control+ArrowDown')
    assert t.page.locator('.block.collapsed').count() == 0
    t.page.click('.toggle')
    assert t.page.locator('.block.collapsed').count() == 1
    t.saved()
    assert t.s.api('GET', 'page?title=' + PAGE)['blocks'][0]['collapsed'] is True


@case(['# Title', 'text'])
def escape_stops_editing_and_shows_the_text_again(t):
    t.page.click('.row.h1 .content')
    assert t.page.locator('.content[contenteditable]').text_content() == '# Title'
    t.page.keyboard.press('Escape')
    assert t.page.locator('.content[contenteditable]').count() == 0
    assert t.page.locator('.row.h1 .content').text_content() == 'Title'
    assert t.saved() == ['# Title', 'text'], 'the text is unchanged'
    assert t.errors == [], t.errors


@case(['one two'])
def undo_and_redo_work_on_whole_actions(t):
    t.click('one two')
    t.page.keyboard.press('End')
    for _ in range(4):
        t.page.keyboard.press('ArrowLeft')
    t.page.keyboard.press('Enter')
    t.page.keyboard.press('Tab')
    assert t.outline() == ['one', '   two']
    t.page.keyboard.press('Control+z')
    assert t.outline() == ['one', ' two']
    t.page.keyboard.press('Control+z')
    assert t.outline() == ['one two']
    t.page.keyboard.press('Control+Shift+z')
    t.check(['one', ' two'])


if __name__ == '__main__':
    run()
