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
php -S localhost:8000
```

Open <http://localhost:8000>. The database is created on first use and today's journal page opens with a few welcome notes.

### On a web server

Copy the folder to a place served by Apache or nginx with PHP. The web server needs write access to `db/` (the database) and `uploads/` (attachments).

notd has **no login**. If the server can be reached by other machines, protect it with HTTP authentication or a VPN. On Apache, the included `.htaccess` files stop the database and tests from being downloaded; on nginx, add an equivalent rule:

```nginx
location ~ ^/(db|tests)/ { deny all; }
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

## Back up

Everything is in two places: the database file (`db/database.sqlite`, plus `-wal` and `-shm` files next to it while notd is running) and the `uploads/` folder. Copy both.

## Upgrade

Replace the code (or `git pull`) and keep `db/`, `uploads/` and `config.local.php`.

The database carries a schema version. If it is older than the code expects, notd stops with a message that names the migration to run. Migrations keep a backup of the original file next to it.

**From a version before November 2026 (schema v2 to v3):**

```bash
php db/migrate_v3.php                         # the database from the configuration
php db/migrate_v3.php path/to/database.sqlite # or a specific file
```

Existing note ids are kept, so `!{{123}}` transclusions and `#note-123` links continue to work.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "The database could not be opened" | The web server cannot write to `db/`. Fix the folder permissions. Details are in the PHP error log. |
| "This database uses an older notd schema" | Run the migration shown in the message (see Upgrade). |
| A banner says you are working offline | The server cannot be reached. Keep working: edits are stored in the browser and sent when the connection returns. |
| An extension is missing from the sidebar | Add its folder name to `ACTIVE_EXTENSIONS`. |
| Something else is wrong | Set `NOTD_DEBUG` to `true` in `config.local.php`, reload, and look at the browser console and the PHP error log. |
