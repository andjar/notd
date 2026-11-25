-- notd 4 schema. The text of nodes is the truth; fields and links are
-- indexes built from it and can be rebuilt at any time.

CREATE TABLE IF NOT EXISTS meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS nodes (
  id         TEXT PRIMARY KEY,                 -- UUIDv7, sortable by creation time
  kind       TEXT NOT NULL CHECK (kind IN ('block', 'page', 'doc', 'file')),
  parent_id  TEXT REFERENCES nodes(id),        -- NULL for pages; the parent block or page for blocks
  page_id    TEXT,                             -- the page a block belongs to; a page's own id
  sort_key   TEXT NOT NULL DEFAULT '',         -- fractional key, orders siblings
  title      TEXT,                             -- pages, docs and files
  title_key  TEXT,                             -- title in NFC and lower case, for lookups
  text       TEXT NOT NULL DEFAULT '',
  collapsed  INTEGER NOT NULL DEFAULT 0,
  clock      TEXT NOT NULL DEFAULT '',         -- hybrid logical clock of the last change
  created    INTEGER NOT NULL,                 -- milliseconds since 1970
  updated    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS nodes_children ON nodes (parent_id, sort_key);
CREATE INDEX IF NOT EXISTS nodes_page ON nodes (page_id);
CREATE UNIQUE INDEX IF NOT EXISTS nodes_title ON nodes (title_key) WHERE title_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS fields (
  node_id TEXT NOT NULL REFERENCES nodes(id) ON DELETE CASCADE,
  key     TEXT NOT NULL,                       -- lower case
  value   TEXT NOT NULL,
  value_key TEXT NOT NULL,                     -- the value folded like a title, for comparisons
  num     REAL                                 -- the value as a number, when it is one
);
CREATE INDEX IF NOT EXISTS fields_node ON fields (node_id);
CREATE INDEX IF NOT EXISTS fields_key_value ON fields (key, value_key);
CREATE INDEX IF NOT EXISTS fields_key_num ON fields (key, num);

CREATE TABLE IF NOT EXISTS links (
  from_id TEXT NOT NULL REFERENCES nodes(id) ON DELETE CASCADE,
  kind    TEXT NOT NULL,                       -- link, tag, ref, embed
  target  TEXT NOT NULL                        -- a title_key, or a node id for refs and embeds
);
CREATE INDEX IF NOT EXISTS links_from ON links (from_id);
CREATE INDEX IF NOT EXISTS links_target ON links (target, kind);

CREATE TABLE IF NOT EXISTS changes (
  seq     INTEGER PRIMARY KEY AUTOINCREMENT,   -- the change feed cursor
  node_id TEXT NOT NULL,
  op      TEXT NOT NULL,                       -- create, edit, move, delete, collapse
  clock   TEXT NOT NULL,
  at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS applied_ops (
  op_id TEXT PRIMARY KEY,                      -- a client's operation id, so a retried batch is not applied twice
  at    INTEGER NOT NULL
);

CREATE VIRTUAL TABLE IF NOT EXISTS search USING fts5(
  node_id UNINDEXED,
  title,
  text,
  tokenize = "unicode61 remove_diacritics 0"     -- case-insensitive, but å and a stay different
);

CREATE TABLE IF NOT EXISTS files (
  node_id TEXT PRIMARY KEY REFERENCES nodes(id) ON DELETE CASCADE,
  sha256  TEXT NOT NULL UNIQUE,                -- one stored copy per identical file
  size    INTEGER NOT NULL,
  mime    TEXT NOT NULL,
  path    TEXT NOT NULL                        -- relative to the data folder: files/YYYY/MM/<sha256>.<ext>
);
