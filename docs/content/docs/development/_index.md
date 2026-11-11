---
title: "Development"
weight: 35
---

How notd is put together, and the rules that keep it small and long-lived.

## Principles

1. **No build step.** The repository is the application. PHP files are served as they are; the browser loads plain ES modules. Nothing is compiled, bundled or installed.
2. **No runtime dependencies to fetch.** The handful of libraries (Alpine.js, marked, DOMPurify, Sortable, SJCL, Feather) are stored in `assets/libs/`. Nothing is loaded from the internet.
3. **Boring technology.** PHP, SQLite, HTML, CSS and JavaScript as browsers and PHP ship them.
4. **Content is the source of truth.** Properties, links and task states are parsed from note text. Derived tables can always be rebuilt from it.
5. **GET and POST only**, so the app runs under minimal web servers such as phpdesktop.
6. **One write path per kind of record**, shared by the interface, the extensions and scripts.
7. **Your data stays yours.** SQLite plus plain Markdown export and import in the Logseq layout.

## Layout

```text
index.php, page.php      entry point and the single application page
router.php               request filter for PHP's built-in server
config.php               default settings (overrides: config.local.php)
api/
  bootstrap.php          loaded by every endpoint: config, autoloader, request helpers, cross-site check
  v1/*.php               HTTP endpoints, one file each
  NoteOps.php            the note write path: applies operations in a transaction
  PageStore.php          the page write path: create, rename (rewrites links), delete
  Attachments.php        the attachment write path: type whitelist, storage, URLs
  PropertyIndex.php      parses properties, links and tasks from content into the index
  Ordering.php           fractional sort keys and id generation
  DataManager.php        read queries
  Markdown.php           Markdown export and import (Logseq layout)
  Templates.php          template files and placeholders
  PropertyTriggerService.php, WebhooksManager.php   reactions to property changes
  db_connect.php         connection, first-run setup, migrations, notd_write() transactions
db/
  schema.sql             the whole schema
  migrations.php         automatic upgrades (v3 to v4, ...)
  migrate_v3.php         manual migration from schema v2
tools/                   command-line export and import
assets/
  js/api_client.js       the HTTP API, for the app and for extensions
  js/app/                state and actions: outline, outbox, page, search, keys
  js/ui/                 the screen: note rendering, events, menus, dialogs, embeds
  css/                   one file per component, themes as custom properties
  libs/                  bundled third-party libraries
  template/              note and page templates
extensions/              optional apps built on the API
tests/                   model, API and browser tests
docs/                    this site (Hugo)
```

## How saving works

The outline is the part of notd where the browser and the server must never disagree, so it has one model and one write path.

```text
 key press / drag
        │
        ▼
 outline.js ── applies the change to ──▶ outline-model.js   (the notes of the page, in memory)
        │                                      │
        │                                      ▼
        │                               note-elements.js    (DOM rendered from the model)
        ▼
 outbox.js ── POST /api/v1/ops.php ──▶ NoteOps.php ──▶ SQLite
   (ordered queue in localStorage)       (one transaction per batch)
```

- **One model.** `assets/js/app/outline-model.js` holds the notes of the open page. It is pure (no DOM, no network) and unit-tested. The DOM is rendered from it and elements are placed by note id.
- **Operations.** Every user action becomes one intent operation: `create`, `edit`, `move`, `collapse` or `delete`. `outline.js` applies it to the model and the DOM immediately and hands it to the outbox.
- **Outbox.** `outbox.js` sends operations strictly in order, merges consecutive edits of the same note, retries with back-off and keeps the queue in `localStorage`, so edits survive reloads and offline periods. There are no rollbacks: the server's answer is merged into the model without overwriting edits that have not been sent yet.
- **Ids.** Notes get a UUIDv7 in the browser (`ids.js`), so a new note can be indented, edited or given an attachment before the server has seen it.
- **Ordering.** Siblings are ordered by a fractional `sort_key` (`fractional-index.js`, mirrored in `api/Ordering.php`). A move rewrites only the moved note. A parity test checks that the JavaScript and PHP implementations produce identical keys.
- **Server.** `api/v1/ops.php` runs the batch in one `BEGIN IMMEDIATE` transaction through `NoteOps`. It validates parents and cycles, skips operations whose target has vanished (with a warning) and ignores `op_id`s it has already applied, so a retry is harmless.
- **Other tabs** are told to resynchronise over a `BroadcastChannel`.
- **Undo** (`outline.js`) records the inverse of each undoable operation, grouped per user action, and replays it as new operations; the server never needs to know.

## The browser code

`assets/js/app.js` wires the modules together. Each module has one job and no globals (`window.notdOutline` exists only as a test hook):

| Module | Job |
|---|---|
| `app/outline.js` | the only writer of notes: model + outbox + undo |
| `app/page-state.js` | the open page and the configuration from `window.NOTD_CONFIG` |
| `app/page-loader.js` | loads the page, unlocks it, renders the outline |
| `app/page-actions.js` | favourite, rename, delete, lock, print, export |
| `app/note-actions.js` | keys in a note: split, join, indent, move, undo, shortcuts |
| `app/search.js`, `app/backlinks.js`, `app/shortcuts.js` | the tools around the outline |
| `ui/note-elements.js` | turns model changes into DOM changes; drag and drop |
| `ui/note-view.js` | one note's element: rendering, edit mode, caret |
| `ui/note-events.js` | one set of delegated listeners for every note |
| `ui/markdown.js` | Markdown rendering, notd syntax, sanitizing |
| `ui/embeds.js` | read-only outlines for `!{{id}}` and `SQL{...}` |
| `ui/autocomplete.js`, `ui/zoom.js`, `ui/note-menu.js`, `ui/dialogs.js`, `ui/attachments.js` | what their names say |

Alpine.js is used only for the small reactive widgets (sidebars, calendar, splash screen); the outline container carries `x-ignore` so Alpine never watches it.

The request and response format is documented under [Operations]({{< relref "../api-reference/ops.md" >}}).

## Database

The schema is `db/schema.sql`. Its version is stored in SQLite's `PRAGMA user_version` and must equal `NOTD_SCHEMA_VERSION` in `api/db_connect.php`.

To change the schema:

1. Edit `db/schema.sql` and raise `PRAGMA user_version` there and `NOTD_SCHEMA_VERSION` in `api/db_connect.php`.
2. Add a step to `db/migrations.php` that brings a database from the previous version to the new one. Steps run automatically, in a transaction, when a database is opened.
3. Only for a change that cannot be done safely in place, write a separate script like `migrate_v3.php` and make `notd_migrate()` stop with a message naming it.
4. Mention the change in the changelog.

All writes go through `notd_write($pdo, fn)`, which opens a `BEGIN IMMEDIATE` transaction, retries when the database is busy, and sends queued webhooks only after the commit.

`Properties` and the full-text index (`Notes_fts`, maintained by triggers) are derived from note content.

## Tests

No test needs a build step or a PHP package.

```bash
node tests/js/model.test.mjs          # outline model and outbox (no server)
node tests/js/ordering_parity.mjs     # JavaScript and PHP sort keys agree (needs php on PATH)

# the remaining suites talk to a running server, on a throw-away database:
NOTD_DB_PATH=/tmp/notd-test.sqlite PHP_CLI_SERVER_WORKERS=4 \
  php -S 127.0.0.1:8765 tests/browser/router.php

node tests/js/ops_api.test.mjs        # write endpoint: ordering, cycles, atomicity, idempotency, webhooks
node tests/js/api.test.mjs            # the other endpoints: pages, search, templates, uploads, export, security
python3 tests/browser/outline_test.py # real browser: indent, drag, latency, offline, two tabs, XSS
python3 tests/browser/features_test.py # search, properties, attachments, encryption, templates
python3 tests/browser/editing_test.py  # split, join, undo, suggestions, zoom, tasks, page actions
```

The browser suites need `pip install playwright && playwright install chromium`. All suites run in GitHub Actions on every push (`.github/workflows/tests.yml`).

## Conventions

- **Endpoints** live in `api/v1/`, answer with `ApiResponse::success()` / `ApiResponse::error()` and accept `_method` in the POST body for updates and deletes.
- **Writes** go through `NoteOps`, `PageStore` or `Attachments`, inside `notd_write()`, also from server-side code (see `append_to_page.php`). Do not `UPDATE Notes` or `Pages` elsewhere.
- **PHP in `namespace App`** must reference global classes with a leading backslash (`\Exception`, `\PDO`).
- **No external URLs** in pages, styles or scripts. Add a library by placing the file in `assets/libs/`.
- **Rendered note HTML** passes through `sanitizeHtml()` (DOMPurify) before it is inserted into the page.
- **Settings** get a default in `config.php` via `notd_default()` and a line in `config.local.example.php`.
- **Paths are relative**, so notd works from a sub-folder.

## Documentation site

This site is built with [Hugo](https://gohugo.io/) from `docs/` and published to GitHub Pages by `.github/workflows/pages.yml` when `docs/` changes on `main`. To preview it:

```bash
git submodule update --init   # the theme
cd docs && hugo server
```
