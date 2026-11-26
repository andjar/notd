// Addresses: #/page/<title> shows a page; an empty fragment shows today's journal.
// #/page/<title>/<block id> also scrolls to (and later zooms into) a block.

/** Today as YYYY-MM-DD in local time: the title of today's journal page. */
export function today(d = new Date()) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** The route in the address: { title, block, journal } (journal: the journal of all days, not one page). */
export function route(hash = location.hash) {
  const m = hash.match(/^#\/page\/([^/]+)(?:\/([0-9a-f-]{36}))?$/);
  return { title: m ? decodeURIComponent(m[1]) : today(), block: m?.[2] ?? null, journal: !m };
}

export function pageLink(title, block = null) {
  return '#/page/' + encodeURIComponent(title) + (block ? '/' + block : '');
}

export function isJournal(title) {
  return /^\d{4}-\d{2}-\d{2}$/.test(title);
}

/** How a page's title is shown: a day as a date in the reader's language ("Friday 27 November",
    with the year when it is not this year; short: "Fri 27 Nov"), any other title as it is. */
export function dayLabel(title, { short = false } = {}) {
  if (!isJournal(title)) return title;
  const d = new Date(title + 'T00:00');
  if (Number.isNaN(d.getTime())) return title;
  const year = d.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' };
  return d.toLocaleDateString(undefined, short ? { weekday: 'short', day: 'numeric', month: 'short', ...year }
    : { weekday: 'long', day: 'numeric', month: 'long', ...year });
}
