# notd - A Note-Taking Application

Welcome to **notd**, a simple and flexible outliner! **notd** is designed to help you effortlessly organize your thoughts, tasks, and information. Dive in and discover a more intuitive way to keep your digital life in order! Built with robust technology, focusing on longevity. The data are saved as clear text (unless specifically encrypted) in a sqlite database. Heavily inspired by [LogSeq](https://logseq.com/)

## 🌟 Frontend Features

**notd** provides a rich, interactive frontend experience with powerful features:

### 📝 **Rich Note-Taking**
- **Markdown Support** - Write with familiar Markdown syntax and live preview
- **Page Linking** - Connect ideas with `[[Page Name]]` syntax  
- **Transclusion** - Embed content with `{{transclude:Note Title}}`
- **SQL Queries** - Execute dynamic queries with `SQL{...}` syntax

### 🔐 **Security & Privacy** 
- **Client-Side Encryption** - AES-256 encryption with `ENC{...}` syntax
- **Password Protection** - Secure sensitive content with per-page passwords
- **Offline-First** - Works completely offline, your data stays with you

### ✅ **Task Management**
- **Multiple Task States** - TODO, DOING, DONE, WAITING, CANCELLED, and more
- **Visual Indicators** - Color-coded task states with checkboxes
- **Property-Based Organization** - Tag tasks with priority, assignee, due dates
- **Kanban Board Extension** - Visual task management with drag-and-drop

### 🔍 **Powerful Search & Navigation**
- **Global Search** - Find content across all notes and pages instantly
- **Property Filtering** - Search by any custom property
- **Backlink Discovery** - See which pages reference your current page
- **Favorites System** - Quick access to important pages

### 🧩 **Extension Ecosystem**
8 built-in extensions for specialized workflows:
- **🎨 Excalidraw Editor** - Drawing and diagramming
- **📋 Kanban Board** - Visual task management
- **📎 Attachment Dashboard** - File management
- **🧮 Math Notepad** - Mathematical notation
- **⏲️ Pomodoro Timer** - Time management
- **📰 RSS Handler** - Feed integration  
- **📧 Mail Handler** - Email integration
- **🧘 Zen Mode** - Distraction-free writing

### 📅 **Journal-Based Organization**
- **Daily Pages** - Automatic date-based pages for journaling
- **Calendar Navigation** - Visual date navigation with activity indicators
- **Hierarchical Organization** - Nest pages with forward-slash notation
- **Template System** - Reusable note and page templates

## Main Features

*   **Effortless Note-Taking & Organization:** Easily create, manage, and nest rich-text notes within a flexible page hierarchy. Organize notes into distinct pages (including special 'Journal' pages for daily logs) and navigate your collection with ease.
*   **Customizable Properties:** Personalize notes and pages with custom properties (like `{status::todo}` or `{priority::high}`) for powerful filtering, organization, and automation.
*   **Integrated File Attachments:** Keep all related information in one place by attaching files directly to your notes.
*   **Powerful Global Search:** Instantly find what you need across all your notes with a comprehensive search.
*   **Time-Saving Note Templates:** Create notes faster for recurring formats like meeting notes or bookmarks using predefined or custom templates.
*   **Visual Calendar Navigation:** Use the calendar widget to visually explore and access your notes by date.
*   **Discover Connections with Backlinks:** Uncover relationships between your notes and pages by seeing what links to them.
*   **Easy Markdown Formatting:** Write and format notes effortlessly using Markdown.
*   **Responsive & Fast:** Enjoy a smooth and speedy experience as you navigate and manage your notes.

## Plan Forward / Roadmap

**notd** is under active development.

## Getting Started / Installation

Setting it up is straightforward. You'll generally need a web server environment with PHP and SQLite support.

1.  **Server Environment:** Make sure your web server is ready to serve the application files and has PHP and SQLite capabilities.
2.  **Permissions:** Ensure the web server has write permissions for the `db/` directory (or the custom path you set in `config.php` for your database).
3.  **Configuration:** If `config.php` doesn't exist, copy `config.php.example` to `config.php`. You can then update `DB_PATH` in `config.php` if you wish to store the database in a custom location (default is `db/notd.sqlite`).
4.  **Launch:** Open **notd** in your web browser by navigating to its URL on your server. Enjoy!

The application is also designed with [phpdesktop](https://github.com/cztomczak/phpdesktop) in mind, allowing it to be packaged as a standalone desktop application. If using a phpdesktop build, refer to its specific instructions.

## How saving works

Every change you make in the outline (typing, Enter, Tab, Shift+Tab, drag and drop, collapsing, deleting) becomes a small operation that is applied in the browser immediately and queued for the server:

- **One model.** The page's notes live in one in-memory model (`assets/js/app/outline-model.js`); the DOM is rendered from it and notes are placed by identity, so what you see is what is saved.
- **Ordered, persisted queue.** Operations are sent strictly in order by `assets/js/app/outbox.js`. The queue is kept in `localStorage`, so edits survive a reload, a crash or a lost connection and are sent when the server is reachable again. A banner shows when you are working offline.
- **Stable ids.** Notes get their permanent id when they are created in the browser, so you can keep typing, indenting or attaching files without waiting for the server.
- **Fractional ordering.** Siblings are ordered by a `sort_key` string (`api/Ordering.php` / `assets/js/app/fractional-index.js`). Moving a note rewrites only that note.
- **One write endpoint.** `api/v1/ops.php` applies a batch of operations atomically, validates parents (same page, no cycles) and ignores operations it has already applied, so retries are safe. `api/v1/notes.php` is read-only.

### Upgrading an existing database

Schema v3 changes note ids to strings and replaces `order_index` with `sort_key`. Migrate once (a backup is written next to the database):

```
php db/migrate_v3.php            # uses DB_PATH from config.php
php db/migrate_v3.php path/to/database.sqlite
```

Existing note ids are kept (as strings), so `!{{123}}` transclusions and `#note-123` links keep working.

### Hosting notes

On Apache, `db/.htaccess` and `tests/.htaccess` block HTTP access to the database and test scripts. Other servers (nginx, `php -S`) need an equivalent rule if the app is reachable from other machines. notd has no login of its own; put it behind HTTP authentication when it is not running only on your computer.

## Tests

No build step is needed for the app or the tests.

```
node tests/js/model.test.mjs          # client model + outbox (no server)
node tests/js/ordering_parity.mjs     # JS and PHP order keys agree

# with a server: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8765 tests/browser/router.php
node tests/js/ops_api.test.mjs        # write endpoint: ordering, cycles, atomicity, idempotency
python3 tests/browser/outline_test.py # browser: indent/outdent, drag, latency, offline, two tabs
python3 tests/browser/features_test.py
```

The browser tests need `pip install playwright && playwright install chromium`.

## Contributing

We warmly welcome contributions to **notd**! Whether you have ideas for new features, bug fixes, or general improvements, your help is appreciated.

Ready to contribute? Feel free to open an issue to share your thoughts or submit a pull request with your changes. We're excited to see what you come up with!
