// Pure helpers for links: what is being typed after [[ or #, and rewriting
// links when a page is renamed. No DOM, tested in Node.

const fold = (s) => s.normalize('NFC').toLocaleLowerCase().replace(/\s+/g, ' ').trim();

/**
 * The link being typed just before the caret, if any:
 * { kind: '[[' | '#', start, query } where start is the offset of [[ or #.
 */
export function typedLink(text, caret) {
  const before = text.slice(0, caret);
  const open = before.lastIndexOf('[[');
  if (open >= 0 && !before.slice(open).includes(']]') && !before.slice(open).includes('\n')) {
    return { kind: '[[', start: open, query: before.slice(open + 2) };
  }
  const m = before.match(/(^|[^\p{L}\p{N}_#&/])#([\p{L}\p{N}_/-]*)$/u);
  if (m) return { kind: '#', start: caret - m[2].length - 1, query: m[2] };
  return null;
}

/** Titles matching a query: titles starting with it first, then containing it; aliases count. */
export function suggest(pages, query, limit = 8) {
  const q = fold(query);
  const scored = [];
  for (const p of pages) {
    const names = [p.title, ...(p.aliases ?? [])];
    let best = Infinity;
    for (const n of names) {
      const f = fold(n);
      const i = f.indexOf(q);
      if (i === 0) best = Math.min(best, f === q ? 0 : 1);
      else if (i > 0) best = Math.min(best, 2);
    }
    if (best < Infinity) scored.push([best, p.title]);
  }
  scored.sort((a, b) => a[0] - b[0] || a[1].localeCompare(b[1]));
  return scored.slice(0, limit).map((s) => s[1]);
}

/** The text that completes a typed link: what replaces text from start to the caret. */
export function completion(kind, title) {
  if (kind === '[[') return `[[${title}]]`;
  return /^[\p{L}\p{N}_][\p{L}\p{N}_/-]*$/u.test(title) ? `#${title}` : `#[[${title}]]`;
}

/** text with every [[old]], #old, #[[old]] and {{embed [[old]]}} pointing at the new title instead. */
export function rewriteLinks(text, oldTitle, newTitle) {
  const o = fold(oldTitle);
  const tag = completion('#', newTitle);
  return text
    .replace(/(#?)\[\[([^\]\n]+)\]\]/g, (all, hash, t) => (fold(t) === o ? (hash ? tag : `[[${newTitle}]]`) : all))
    .replace(/(^|[^\p{L}\p{N}_#&/])#([\p{L}\p{N}_][\p{L}\p{N}_/-]*)/gu, (all, pre, t) => (fold(t) === o ? pre + tag : all));
}
