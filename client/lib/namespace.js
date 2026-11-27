// Pages inside pages: which titles sit directly under a title. Kept free of
// the page so it can be tested on its own.

/** The pages directly under `parent`, with how many there are under each in turn. */
export function childrenOf(parent, titles) {
  const prefix = parent.toLowerCase() + '/';
  const found = new Map();                       // lower-case name -> { title, below }
  for (const t of titles) {
    if (!t.toLowerCase().startsWith(prefix) || t.length === prefix.length) continue;
    const rest = t.slice(prefix.length);
    const name = rest.split('/')[0];
    const key = name.toLowerCase();
    const entry = found.get(key) ?? { title: parent + '/' + name, below: 0 };
    if (rest.includes('/')) entry.below++;
    else entry.title = t;                        // the page's own spelling
    found.set(key, entry);
  }
  return [...found.values()].sort((a, b) => a.title.localeCompare(b.title, undefined, { sensitivity: 'base', numeric: true }));
}
