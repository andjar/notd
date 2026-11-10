---
title: "Changelog"
weight: 70
---

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
