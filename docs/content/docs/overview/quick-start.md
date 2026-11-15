---
title: "Installation"
weight: 20
aliases: ["/docs/overview/troubleshooting/"]
---

## Requirements

- PHP 7.4 or newer with the `pdo_sqlite` and `mbstring` extensions (and `curl` if you use webhooks or the RSS extension).
- A modern browser.

There is nothing to compile or install.

## Run it

Download or clone the repository, then start PHP's built-in server in that folder:

```bash
git clone https://github.com/andjar/notd.git
cd notd
php -S localhost:8000 router.php
```

Open <http://localhost:8000>. (`router.php` keeps the built-in server from handing out the database, the tests and the configuration files.) The database is created on first use and today's journal page opens with a few welcome notes.

### On a web server

Copy the folder to a place served by Apache or nginx with PHP. The web server needs write access to `db/` (the database) and `uploads/` (attachments).

notd has **no login**. If the server can be reached by other machines, protect it with HTTP authentication or a VPN. Writes coming from other web sites are refused (the browser's `Sec-Fetch-Site` and `Origin` headers are checked), so a page you visit cannot change your notes; if you deliberately call the API from another origin, set `ALLOW_CROSS_SITE_REQUESTS` to `true`. On Apache, the included `.htaccess` files stop the database and tests from being downloaded; on nginx, add an equivalent rule:

```nginx
location ~ ^/(db|tests|tools)/ { deny all; }
location ~ ^/config(\.local)?\.php$ { deny all; }
```

### As a desktop app

notd works with [phpdesktop](https://github.com/cztomczak/phpdesktop): put the notd folder in phpdesktop's `www` directory.

## Configure it

Defaults live in `config.php`. To change one, create `config.local.php` next to it and define only what you want to change (copy `config.local.example.php` as a start). `config.local.php` is not tracked by git, so updates never overwrite your settings.

```php
<?php
define('ACTIVE_THEME', 'darkly');
define('ACTIVE_EXTENSIONS', ['kanban_board', 'pomodoro_timer', 'zen']);
```

| Setting | Default | Meaning |
|---|---|---|
| `DB_PATH` | `db/database.sqlite` | The SQLite database file. |
| `UPLOADS_DIR` | `uploads/` | Where attachments are stored. |
| `ACTIVE_THEME` | `default` | `default`, `darkly`, `flatly`, `purple` or `ubuntu`. |
| `ACTIVE_EXTENSIONS` | attachments, pomodoro, kanban | Extensions shown in the sidebar. |
| `TASK_STATES` | `TODO`, `DOING`, `DONE`, `SOMEDAY`, `WAITING`, `CANCELLED` | Task keywords the server indexes. |
| `ADVANCED_QUERY_MODE` | `false` | Allow any read-only SQL in `SQL{...}` blocks. |
| `WEBHOOKS_ENABLED` | `true` | Master switch for webhook deliveries. |
| `NOTD_DEBUG` | `false` | Show PHP errors and include stack traces in API errors. |
| `ALLOW_CROSS_SITE_REQUESTS` | `false` | Accept writes from pages on other origins. |

The environment variable `NOTD_DB_PATH`, when set, overrides `DB_PATH` (useful for tests and for running several notebooks from one copy).

## Back up

Everything is in two places: the database file (`db/database.sqlite`, plus `-wal` and `-shm` files next to it while notd is running) and the `uploads/` folder. Copy both.

## Upgrade

Replace the code (or `git pull`) and keep `db/`, `uploads/` and `config.local.php`.

The database carries a schema version. Small upgrades (such as v3 to v4) are applied automatically the first time the new code opens the database. A larger one that needs your attention stops notd with a message that names the script to run; such scripts keep a backup of the original file next to it.

**From a version before November 2026 (schema v2 to v3):**

```bash
php db/migrate_v3.php                         # the database from the configuration
php db/migrate_v3.php path/to/database.sqlite # or a specific file
```

Existing note ids are kept, so `!{{123}}` transclusions and `#note-123` links continue to work.

## Moving data in and out

Your notes are never locked in. Besides the SQLite file, which any SQLite tool can open, there are Markdown tools in the Logseq layout:

```bash
php tools/export.php ~/notd-export        # journals/, pages/ and assets/
php tools/import.php ~/logseq/pages       # a folder of .md files, or single files
```

Export writes one file per page: journal pages go to `journals/2026_11_10.md`, other pages to `pages/Name.md` (a `/` in a name becomes `___`), attachments are copied to `assets/`. Import reads the same layout and appends to pages that already exist. A single page can also be downloaded from the gear menu (**Export**) or from `api/v1/export.php?page=Name`.

### Importing a Logseq graph

For a Logseq graph (Logseq's Markdown files, not its newer database version) use the Logseq importer and point it at the graph folder, the one with `journals/`, `pages/`, `assets/` and `logseq/`:

```bash
php tools/import_logseq.php --dry-run ~/logseq-graph   # see what would happen; changes nothing
php tools/import_logseq.php ~/logseq-graph
```

It reads `logseq/config.edn` for the journal and file name formats and converts what notd has an equivalent for:

| Logseq | notd |
|---|---|
| `journals/2023_11_14.md`, `[[Nov 14th, 2023]]` | the journal page `2023-11-14`, `[[2023-11-14]]` |
| `pages/A___B.md`, `title::` | the page `A/B`; a `title::` property names the page |
| `key:: value` (page or block) | `{key::value}` |
| `id::` on a block, `((id))`, `{{embed ((id))}}` | the note keeps the id; references become `!{{id}}` |
| `LATER` `NOW` `WAIT` `CANCELED`, `[#A]` | `TODO` `DOING` `WAITING` `CANCELLED`, `{priority::A}` |
| `SCHEDULED:` / `DEADLINE: <2023-11-14 Tue>` | `{scheduled::2023-11-14}` / `{deadline::...}` |
| `#tag`, `#[[a tag]]` | `[[tag]]`, `[[a tag]]` (in Logseq a tag is a link) |
| `[[alias]]` | `[[Page]]`, when the alias has no page of its own |
| `collapsed:: true`, `heading:: 2`, `#+BEGIN_QUOTE` | collapsed note, `## `, a Markdown quote |
| `![x](../assets/a.png)`, `[x](../assets/a.pdf)`, `[[draws/x.excalidraw]]` | the file is copied to `uploads/`, attached to the note, and the link points at the copy |
| `:favorites` in `config.edn` | `{favorite::true}` on those pages |

Logbooks (`:LOGBOOK:`) are dropped. Queries (`{{query ...}}`), page embeds and other Logseq-only syntax are kept as text. Org-mode files and whiteboards are not imported, and attachments only of the types notd accepts (no `.svg`, for instance) and up to 10 MB (`--max-file-size=MB` to change).

Running the import again is safe: notes that are already there are skipped and only new ones are added, and files already stored are not copied again. With `--replace` each imported page is made an exact copy of its file instead (notes added to it in notd are removed). Each page is imported completely or not at all; a page that fails is reported and the others go in. `--strict` rolls the whole import back if any page fails, and `--help` lists the other options. To try it out first, point `NOTD_DB_PATH` at a scratch database:

```bash
NOTD_DB_PATH=/tmp/try.sqlite php tools/import_logseq.php ~/logseq-graph
```

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "The database could not be opened" | The web server cannot write to `db/`. Fix the folder permissions. Details are in the PHP error log. |
| "This database uses an older notd schema" | Run the migration shown in the message (see Upgrade). |
| A banner says you are working offline | The server cannot be reached. Keep working: edits are stored in the browser and sent when the connection returns. |
| An extension is missing from the sidebar | Add its folder name to `ACTIVE_EXTENSIONS`. |
| Something else is wrong | Set `NOTD_DEBUG` to `true` in `config.local.php`, reload, and look at the browser console and the PHP error log. |
