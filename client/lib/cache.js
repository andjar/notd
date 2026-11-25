// Reads that panels repeat on every refresh (the favourites, the calendar's days)
// reach the server once until the notebook changes. app.js forgets everything when
// the change feed brings changes, before it refreshes the panels; code that changes
// what a cached read returns, and needs the change on screen at once, calls forgetCached()
// itself after saving.
//
//   const days = await cached(`journal-days:${month}`, () => api.journalDays(month));

const answers = new Map();

/** The answer for key: read() is called once, and its promise shared, until forgetCached(). A failed read is not kept. */
export function cached(key, read) {
  if (!answers.has(key)) {
    const answer = read();
    answers.set(key, answer);
    answer.catch(() => { if (answers.get(key) === answer) answers.delete(key); });
  }
  return answers.get(key);
}

/** Forgets every cached answer. */
export function forgetCached() {
  answers.clear();
}
