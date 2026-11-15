---
title: "Changelog"
weight: 70
description: "What changed in each version."
---

### November 2026: version 3.1, schema v4

The database upgrades itself to v4 the first time it is opened.

Writing
- `Enter` splits a note at the caret and `Backspace` at the start of a note joins it with the one above, as in Logseq.
- Undo and redo for outline changes (`Ctrl + Z`, `Ctrl + Shift + Z`), including deleted notes.
- Zoom into a note (click its bullet) with breadcrumbs back out.
- `[[` page suggestions and `/` templates share one popup with keyboard navigation; typing a closing bracket steps over the automatic one.
- Rendered notes support `!{{id}}` transclusion and `SQL{...}` queries as read-only embedded outlines.

Pages
- ☆ star to add and remove favourites; rename (links and child pages follow) and delete from the page dialog.
- Print (or save as PDF) shows just the outline, collapsed notes included.
- Markdown export of a page, and `tools/export.php` / `tools/import.php` for whole notebooks in the Logseq layout.
- `tools/import_logseq.php` imports a Logseq graph: journals, pages, properties, tasks, block references and attachments. Safe to run again; `--dry-run` and `--replace`.
- An Espanso configuration (`tools/espanso/`) sends copied text to today's journal page.

Extensions
- The RSS handler imports items again: its duplicate check used columns that do not exist, so every item was skipped. Items are now stored with `{key::value}` properties, and `config.json` is read from the script's folder wherever it is run from.
- The mail handler writes `{key::value}` properties (it wrote Logseq's `key:: value`) and reads notd's address from `api_url` in its `config.json` or its first argument.
- The Pomodoro timer reads `pageTemplate`, `noteTemplate` and `cycle` from its `config.json` (they were ignored, so sessions went to the journal page and there were no long breaks), and its work session is 25 minutes again (the shipped setting was 2).
- The kanban board's **Back to Main App** works when notd is served from a sub-folder.

- Kanban cards show the note's text without `{property}` syntax, its properties as labels, and a link to the note.
- The attachment dashboard shows the note each file belongs to, lists files whose note was deleted (**Only files without a note**), and deletes files.

Attachments
- A file the note's text already shows (an image placed in the text, a link to a file) is no longer listed again below the note. Pasted images were shown twice.

Documentation
- A page for each extension, with screenshots; how to turn extensions on, and which ones appear in the note menu.
- Section pages (Development, Overview and others) show their text, which the theme used to hide.
- Page locks use a salted verifier; pages locked with the older SHA-256 hash still unlock.

Performance
- A 3000-note page opens in about 0.8 s (was 2.1 s): one render pass without per-note listeners, sanitizer configured once, a plain-text fast path, drag targets prepared in idle time, off-screen notes skipped by layout.
- 3 API requests per page view (was about 13); the calendar loads one month at a time.

Server
- One bootstrap and class autoloader for all endpoints; each record type has one write path (`PageStore`, `NoteOps`, `Attachments`).
- Writes from other web sites are refused (`ALLOW_CROSS_SITE_REQUESTS` to allow them).
- Templates are plain Markdown files and are never executed (PHP templates could be written through the API and run).
- Uploads are limited to a list of safe file types; `SQL{...}` can only read the notes and properties tables.
- Full-text search folds case for all languages and accepts prefixes (`plan*`); task status always reflects the current keyword.
- New endpoints: `export.php`, `pages.php?names=1`, `pages.php?month=YYYY-MM`.
- `router.php` for PHP's built-in server.

Code
- The browser code is a set of small ES modules (`app/` for state and actions, `ui/` for the screen) without globals; about 2,600 lines of the old view code are gone.
- GPL-3.0 license text included.

### November 2026: schema v3

**Upgrading needs one command:** `php db/migrate_v3.php` (see [Installation]({{< relref "quick-start.md" >}})).

Saving
- All note changes go through one endpoint, `ops.php`, which applies a batch of operations atomically and can be retried safely. `notes.php` is read-only and the old batch endpoint is gone.
- Notes get their permanent id (a UUIDv7) in the browser. Temporary ids are gone, so Enter followed by Tab, typing on a slow connection and attaching a file to a new note all work.
- Siblings are ordered by fractional keys: moving a note rewrites one row. This fixes reordering that corrupted the order of unrelated notes.
- Edits are queued in the browser, survive reloads and offline periods, and other open tabs follow along.
- `Ctrl`/`Alt+Shift` + `Up`/`Down` move a note among its siblings.

Security and robustness
- Rendered note content is sanitized (a stored XSS was possible before).
- The database file can no longer be downloaded on Apache, and its location cannot be changed by a web request.
- API errors no longer reveal file paths and stack traces unless `NOTD_DEBUG` is on.
- The database carries a schema version; an outdated database produces a clear message.
- Webhooks are sent after a change is saved instead of while the database is locked. The `webhooks.php` endpoint, which had stopped working, is back.

Offline
- Nothing is loaded from the internet any more: the font and Alpine.js are served locally, also in the kanban, pomodoro and attachment extensions. (The Excalidraw extension still needs a connection.)

Housekeeping
- Settings go in `config.local.php`; `config.php` only holds defaults.
- Removed: the `recent_pages.php` endpoint, legacy stylesheets, unused scripts, outdated documentation and the old PHPUnit suite (replaced by model, API and browser tests).

### Version 1.0.0
- Initial release: pages, notes, properties, full-text search, attachments, templates, webhooks.
