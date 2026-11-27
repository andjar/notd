// The placeholders a template can hold, filled in when it is put into a block
// (see ui/templates.js). Anything else in double braces is left as it is.

export const CURSOR = '\u0000cursor\u0000';      // where the caret goes; taken out after
const pad = (n) => String(n).padStart(2, '0');

/** A template block's text with {{date}}, {{time}}, {{datetime}}, {{year}}, {{month}}, {{day}}, {{weekday}}, {{page}} and {{cursor}} filled in. */
export function fill(text, { page = '', now = new Date() } = {}) {
  const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  const time = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  const values = {
    date, time, datetime: `${date} ${time}`, year: String(now.getFullYear()), month: pad(now.getMonth() + 1), day: pad(now.getDate()),
    weekday: now.toLocaleDateString(undefined, { weekday: 'long' }), page, cursor: CURSOR,
  };
  return text.replace(/\{\{(date|time|datetime|year|month|day|weekday|page|cursor)\}\}/g, (_, k) => values[k]);
}
