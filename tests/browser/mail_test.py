"""The mail plugin in the browser: an .eml file dropped onto a block becomes an
e-mail note on today's journal, with a reference to it in the block; dropped a
second time, nothing is added twice. An Outlook .msg (what dragging a message
out of Outlook for Windows gives) and a raw message pasted into a block (Gmail's
"Show original") are added the same way."""
import base64
import datetime
import os
from support import ROOT, Server, browser, open_page, test, run

PAGE = '01900000-0000-7000-8000-0000000000d1'
B1 = '01900000-0000-7000-8000-000000000d01'
FIXTURES = os.path.join(ROOT, 'plugins/mail/tests/fixtures')
EML = base64.b64encode(open(os.path.join(FIXTURES, 'mixed.eml'), 'rb').read()).decode()
MSG = base64.b64encode(open(os.path.join(FIXTURES, 'attachments.msg'), 'rb').read()).decode()
RAW = open(os.path.join(FIXTURES, 'plain.eml'), encoding='utf-8').read()

DROP = """([selector, b64, name = 'Biobank list.eml', type = 'message/rfc822']) => {
  const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  const dt = new DataTransfer();
  dt.items.add(new File([bytes], name, { type }));
  const el = document.querySelector(selector);
  el.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
  el.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
}"""


@test
def a_dropped_message_becomes_a_note_with_a_reference_where_it_was_dropped():
    s = Server()
    s.ops({'op': 'create', 'kind': 'page', 'id': PAGE, 'title': 'Biobank'},
          {'op': 'create', 'id': B1, 'parent': PAGE, 'text': 'The list from Signe:'})
    today = datetime.date.today().isoformat()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Biobank', errors)
            page.wait_for_selector(f'.block[data-id="{B1}"]')
            page.evaluate(DROP, [f'.block[data-id="{B1}"] .content', EML])
            page.wait_for_selector('[data-panel=status] [role=alert]:has-text("Added the e-mail “Biobank list” to")')
            assert 'not stored: tool.exe' in page.text_content('[data-panel=status]')
            page.wait_for_selector(f'.block[data-id="{B1}"] a.block-ref:text("Email: Biobank list #mail")')
            day = s.api('GET', 'page?title=' + today)['blocks']
            assert day[0]['text'].startswith('Email: Biobank list #mail') and day[0]['collapsed'], day[0]
            assert any('[biobank list.pdf](file:' in b['text'] for b in day), day

            page.evaluate(DROP, ['.page', EML])                       # onto the page, a second time
            page.wait_for_selector('[data-panel=status] [role=status]:has-text("is in notd already")')
            assert len(s.api('GET', 'page?title=' + today)['blocks']) == len(day)
            assert len(s.api('GET', 'page?title=Biobank')['blocks']) == 1, 'nothing added to the page itself'
            page.keyboard.press('Control+k')
            page.fill('.menu.palette input', 'e-mail')
            page.wait_for_selector('.menu.palette li:has-text("Add an e-mail (.eml or .msg file)")')
            assert errors == [], errors
    finally:
        s.stop()


PASTE = """([selector, text]) => {
  const dt = new DataTransfer();
  dt.setData('text/plain', text);
  document.querySelector(selector).dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
}"""


@test
def an_outlook_message_and_a_pasted_raw_message_become_notes():
    s = Server()
    s.ops({'op': 'create', 'kind': 'page', 'id': PAGE, 'title': 'Biobank'},
          {'op': 'create', 'id': B1, 'parent': PAGE, 'text': 'From Outlook:'})
    today = datetime.date.today().isoformat()
    try:
        with browser() as b:
            errors = []
            page = open_page(b, s.url + '#/page/Biobank', errors)
            page.wait_for_selector(f'.block[data-id="{B1}"]')
            page.evaluate(DROP, [f'.block[data-id="{B1}"] .content', MSG, 'attachmentFiles.msg', ''])   # Outlook gives no type
            page.wait_for_selector(f'.block[data-id="{B1}"] a.block-ref:text("Email: attachmentFiles #mail")')
            day = s.api('GET', 'page?title=' + today)['blocks']
            assert any('![png.png](file:' in b['text'] for b in day), day

            page.click(f'.block[data-id="{B1}"] .content')                        # Gmail: Show original, Copy to clipboard
            page.keyboard.press('End')
            page.keyboard.type(' and Gmail: ')
            page.evaluate(PASTE, [f'.block[data-id="{B1}"] .content', RAW])
            page.wait_for_selector('[data-panel=status] [role=status]:has-text("Added the e-mail “Blabærsyltetøy for møte”")')
            page.wait_for_function(f"document.querySelector('.block[data-id=\"{B1}\"] .content').textContent.includes('((')")
            page.keyboard.press('Escape')
            page.wait_for_selector(f'.block[data-id="{B1}"] a.block-ref:text("Email: Blabærsyltetøy for møte #mail")')
            assert 'Delivered' not in page.text_content(f'.block[data-id="{B1}"] .content') and 'Message-ID' not in page.text_content(f'.block[data-id="{B1}"] .content')

            page.click(f'.block[data-id="{B1}"] .content')                        # ordinary text pastes as text
            page.keyboard.press('End')
            page.evaluate(PASTE, [f'.block[data-id="{B1}"] .content', 'From: me, about the list'])
            page.keyboard.press('Escape')
            assert len([b for b in s.api('GET', 'page?title=' + today)['blocks'] if b['text'].startswith('Email:')]) == 2
            assert errors == [], errors
    finally:
        s.stop()


if __name__ == '__main__':
    run()
