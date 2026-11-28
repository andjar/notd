import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { looksLikeMail } from '../lib/raw.js';

test('a raw message pasted from "Show original" is mail; ordinary text is not', () => {
  for (const f of ['plain.eml', 'mixed.eml', 'html.eml']) {
    assert.equal(looksLikeMail(readFileSync(new URL(`./fixtures/${f}`, import.meta.url), 'utf8')), true, f);
  }
  const gmail = 'Delivered-To: me@example.com\r\nReceived: by 2002:a05 with SMTP id x;\r\n        Fri, 27 Nov 2026 01:02:03 -0800 (PST)\r\n'
    + 'From: Kari <kari@example.no>\r\nSubject: Hei\r\nDate: Fri, 27 Nov 2026 10:02:03 +0100\r\n\r\nHello';
  assert.equal(looksLikeMail(gmail), true);
  assert.equal(looksLikeMail('From: me\nTo: you\n\nJust a note I wrote.'), false, 'no Message-ID, Received or Date');
  assert.equal(looksLikeMail('Meeting notes\nFrom: Kari\nDate: today\n\nStuff'), false, 'does not open with headers');
  assert.equal(looksLikeMail('From: Kari\nDate: Friday\nWe met and talked\n\nmore'), false, 'a line that is no header');
  assert.equal(looksLikeMail('From: a@b.c\nDate: Fri, 27 Nov 2026'), false, 'no blank line, no body');
  assert.equal(looksLikeMail(''), false);
});
