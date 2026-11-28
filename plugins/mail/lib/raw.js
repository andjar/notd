// Whether pasted text is a raw e-mail message (the source Gmail shows under
// "Show original"), and not ordinary text that happens to start with "From:":
// it must open with a block of headers that has a sender and something only a
// sent message has (a Message-ID, Received or Date header), then a blank line.
// No DOM; tested in Node.

export function looksLikeMail(text) {
  const t = String(text ?? '').replace(/^﻿/, '');
  const end = t.search(/\r?\n\r?\n/);
  if (end < 0) return false;
  const lines = t.slice(0, end).split(/\r?\n/);
  if (!/^[A-Za-z0-9-]+:/.test(lines[0])) return false;
  const names = new Set();
  for (const line of lines) {
    if (/^[ \t]/.test(line)) continue;                       // a folded header's next line
    const m = /^([A-Za-z0-9-]+):/.exec(line);
    if (!m) return false;                                    // not a header block after all
    names.add(m[1].toLowerCase());
  }
  return names.has('from') && ['message-id', 'received', 'date'].some((n) => names.has(n)) && names.size >= 3;
}
