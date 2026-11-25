// What a task is, as text: a block that starts with a keyword. Every change a
// click or a key makes is a change to the block's text, so it is undoable,
// synced and exported like any other edit. No DOM; tested in Node.

export const KEYWORDS = ['TODO', 'DOING', 'WAITING', 'DONE', 'CANCELLED'];
export const OPEN = ['TODO', 'DOING', 'WAITING'];
export const LEAD = /^(TODO|DOING|WAITING|DONE|CANCELLED) /;

export function keyword(text) {
  return text.match(LEAD)?.[1] ?? null;
}

/** "2026-11-25 14:05" in local time. */
export function stamp(d = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

export function getField(text, key) {
  return text.match(new RegExp(`\\{${key}::\\s*([^}\\n]*)\\}`, 'i'))?.[1].trim() ?? null;
}

/** Sets a field: replaces it where it is, or adds it at the end of the first line. null removes it. */
export function setField(text, key, value) {
  const re = new RegExp(` ?\\{${key}::[^}\\n]*\\}`, 'i');
  if (value === null) return text.replace(re, '');
  if (re.test(text)) return text.replace(re, (m) => `${m.startsWith(' ') ? ' ' : ''}{${key}:: ${value}}`);
  const nl = text.indexOf('\n');
  const first = nl < 0 ? text : text.slice(0, nl);
  return `${first.replace(/\s+$/, '')} {${key}:: ${value}}` + (nl < 0 ? '' : text.slice(nl));
}

/** The text with a new keyword (null: no keyword), keeping the times right: DOING sets start, DONE sets done. */
export function setKeyword(text, next, now = new Date()) {
  let body = text.replace(LEAD, '');
  if (next === 'DOING' && !getField(body, 'start')) body = setField(body, 'start', stamp(now));
  if (next === 'DONE') body = setField(body, 'done', stamp(now));
  else body = setField(body, 'done', null);
  return next ? `${next} ${body}` : body;
}

/** The checkbox: open tasks become DONE; DONE and CANCELLED become TODO again. */
export function toggle(text, now) {
  const k = keyword(text);
  return setKeyword(text, OPEN.includes(k) ? 'DONE' : 'TODO', now);
}

/** Ctrl/Cmd+Enter: no keyword → TODO → DONE → no keyword. */
export function cycle(text, now) {
  const k = keyword(text);
  return setKeyword(text, k === null ? 'TODO' : OPEN.includes(k) ? 'DONE' : null, now);
}

/** Clicking the keyword: TODO → DOING → WAITING → TODO (DONE and CANCELLED stay). */
export function nextState(text, now) {
  const k = keyword(text);
  const order = { TODO: 'DOING', DOING: 'WAITING', WAITING: 'TODO' };
  return order[k] ? setKeyword(text, order[k], now) : text;
}
