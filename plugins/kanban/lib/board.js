// What a board is, without the page: the query sent for it, its columns, and
// the text of a card moved to another column. A move is a change to the card's
// text (its task keyword, or a field), so it is undoable, synced and exported
// like any other edit. No DOM; tested in Node.

import { setKeyword, setField } from '../../tasks/lib/tasks.js';

export const STATES = ['TODO', 'DOING', 'WAITING', 'DONE', 'CANCELLED'];
const SHOWN = ['TODO', 'DOING', 'WAITING', 'DONE'];

const has = (q, key) => new RegExp(`(?:^|\\s)-?${key}[:<>=!]`, 'i').test(q);

/** The query sent to the server for {{kanban q}}, and the field its columns come from. */
export function boardQuery(q = '') {
  q = q.trim();
  const field = q.match(/(?:^|\s)group:(\S+)/i)?.[1].toLowerCase() ?? 'status';
  const parts = [q];
  if (field === 'status' && !has(q, 'task')) parts.unshift('task:any');
  if (!has(q, 'group')) parts.push('group:status');
  if (!has(q, 'sort')) parts.push('sort:-updated');
  return { field, sent: parts.filter(Boolean).join(' ') };
}

const label = (v) => v[0].toUpperCase() + v.slice(1).toLowerCase();

/**
 * The columns, in order, each { value, label, nodes }. By task state: To do,
 * Doing, Waiting, Done (Cancelled too when a card is cancelled), or the states
 * in columns:. By a field: the values in columns: first, then any others the
 * cards have, then a column for cards without the field when there are any.
 */
export function columns(field, groups, wanted) {
  const by = new Map(groups.map((g) => [String(g.value ?? '').toLowerCase(), g.nodes]));
  const order = wanted ? String(wanted).split(',').map((s) => s.trim()).filter(Boolean) : [];
  if (field === 'status') {
    const states = order.length ? order.map((s) => s.toUpperCase()).filter((s) => STATES.includes(s))
      : [...SHOWN, ...(by.has('cancelled') ? ['CANCELLED'] : [])];
    return states.map((s) => ({ value: s, label: s === 'TODO' ? 'To do' : label(s), nodes: by.get(s.toLowerCase()) ?? [] }));
  }
  const seen = new Set(order.map((s) => s.toLowerCase()));
  const others = groups.map((g) => g.value).filter((v) => v !== null && v !== undefined && !seen.has(String(v).toLowerCase()));
  const cols = [...order, ...others].map((v) => ({ value: v, label: v, nodes: by.get(String(v).toLowerCase()) ?? [] }));
  if (by.get('')?.length) cols.push({ value: null, label: `No ${field}`, nodes: by.get('') });
  return cols;
}

/** A card's text after moving it to the column `value` (null: the column for cards without the field). */
export function moved(text, field, value, now) {
  return field === 'status' ? setKeyword(text, value, now) : setField(text, field, value);
}

/** What a card shows: the first line, without the keyword (by task state) or fields. */
export function cardText(text, field) {
  let first = text.split('\n')[0];
  if (field === 'status') first = first.replace(/^(TODO|DOING|WAITING|DONE|CANCELLED) /, '');
  return first.replace(/ ?\{[A-Za-z0-9_.-]+::[^}\n]*\}/g, '').trim();
}
