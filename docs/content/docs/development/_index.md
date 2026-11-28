---
title: "Development"
weight: 30
description: "How the notd 4 repository is laid out and how changes get in."
---

## Repository layout

```text
server/      the PHP server: storage, operations, indexing, search, queries, files, export, import
client/      the browser app, served as written (HTML, CSS, ES modules); vendored libraries in client/vendor/
plugins/     plugins that ship with notd; each folder is one plugin and uses only the public API
tests/
  php/          unit tests for the server
  js/           unit tests for browser modules, run in Node
  fixtures/     shared test data, such as sort key cases both languages must agree on
  conformance/  protocol tests: start a server on a throw-away database and talk HTTP to it
  browser/      end-to-end tests with Playwright
tools/       scripts for development and CI
actions/     your own actions (git-ignored, except its README)
docs/        this site (Hugo); docs/content/docs/protocol/ is the protocol the conformance tests check
.github/workflows/
  ci.yml       the checks every pull request must pass
  pages.yml    publishes this site from main
```

Folders appear as the steps that need them land, so some are still empty.

## How changes get in

All work happens on the `next` branch through pull requests, one step of the plan each. A pull request is done when:

- it does one step and nothing else;
- every new behaviour has a test, and all checks in `ci.yml` pass;
- the documentation that the change affects is updated in the same pull request;
- a choice that someone would later ask "why?" about has a short entry on the [decisions](../overview/decisions/) page.

## Rules that keep the core small

1. **No build step.** Nothing is compiled, bundled or installed to run or change notd.
2. **No runtime dependencies to fetch.** No Composer or npm packages at runtime. Test tools may use them.
3. **GET and POST only**, so notd runs under minimal web servers such as phpdesktop.
4. **The core never imports a plugin.** Plugins use the public API only.
5. **One write path.** Every change to notes goes through the operations endpoint, whoever makes it.

## Checks

`.github/workflows/ci.yml` runs on every pull request and on every push to `next` and `main`. All jobs are required; a suite that does not exist yet passes with a note.

| Job | What it checks |
| --- | --- |
| lint | Every PHP file parses (`php -l`) and every JavaScript module parses (`node --check`). |
| php-unit | The server's unit tests, on PHP 8.1 (the oldest supported) and the newest PHP. |
| conformance | Unit tests for browser modules (`tests/js/`), then the protocol tests and the request examples in the protocol docs against a real server on a throw-away database. |
| browser | End-to-end tests in Chromium with Playwright. |
| docs | This site builds with Hugo, and every internal link points at a page or file that exists (`tools/check_links.mjs`). |
| budget | The core stays within its line budget, and never imports a plugin (`tools/budget.mjs`, with a table in the job summary). |

`.github/workflows/nightly.yml` runs every night (and on demand from the Actions tab) the slow check: `php tools/benchmark.php 183000`, which builds a synthetic notebook of 183,000 nodes, imports it, and times the things that must stay fast. It fails when a target is missed, and writes the times to the job summary.

| Benchmark | Target |
| --- | ---: |
| Import 183,000 nodes | 2 min |
| Open a page of 3,000 nodes, open a journal page, backlinks | 50 ms each |
| Search for words that are in a third of all notes | 300 ms |
| A field query with sorting | 200 ms |
| All page names | 100 ms |
| Export everything / export again with nothing changed | 60 s / 30 s |

`NOTD_BENCH_DIR=/tmp/nb php tools/benchmark.php` keeps the generated notebook, so a second run skips the import, which helps when hunting a slow query.

## Unit tests

Server unit tests are PHP files named `*_test.php` under `tests/php/` (and, for a plugin, under `plugins/<name>/tests/`). They need nothing but PHP:

```sh
php tests/php/run.php              # everything
php tests/php/run.php ordering     # files or tests whose name contains "ordering"
```

A test file registers tests with `test()` and table-driven tests with `cases()`, and checks with `eq()` (strict `===`), `ok()` and `throws()`:

```php
<?php
test('titles ignore case', function () {
    eq(title_key('Østfold'), title_key('østfold'));
});

cases('sort keys', [
    'between two keys' => ['a0', 'a2', 'a1'],
    'at the start'     => [null, 'a0', 'Zz'],
], function (?string $before, ?string $after, string $expected) {
    eq($expected, key_between($before, $after));
});
```

A failure prints the test, the message and the line in the test file; the runner exits with 1 if anything failed.

Browser modules that do not need a page (such as `client/lib/keys.js`) are tested in Node with `node --test tests/js/*.test.mjs`. Code that exists in both languages is checked against one shared fixture: `tests/fixtures/sort_keys.json` holds 2,100 sort key cases made by `tools/make_sort_key_cases.mjs`, and both the PHP and the JavaScript tests must give exactly those keys.

## Protocol tests

The conformance tests in `tests/conformance/` start notd under PHP's built-in server on a free port, with a throw-away data folder, and talk HTTP to it. They need PHP and Node 22, nothing else:

```sh
node --test tests/conformance/*.test.mjs
```

`harness.mjs` provides `startServer()` (with `get`, `post` and `stop`; `phpArgs` such as `['-n']` start PHP without its `php.ini`), `mismatch()` for comparing a response with what is expected, and the extractor that turns the examples on the [protocol pages](../protocol/) into tests (`docs_examples.test.mjs`). A change to the protocol therefore starts with its example in the docs.

notd turns off PHP's `display_errors` for every request, so a warning can never end up inside a JSON answer; warnings go to the server log instead. `stop()` fails the test file if that log has a PHP warning, notice or error in it. `requirements.test.mjs` checks what a PHP without the needed extensions gets (the list is `NOTD_EXTENSIONS` in `server/http.php`), and that every tool, and the PHP test runner, refuses to run when a web server runs it: phpdesktop runs any `.php` file it is asked for.

To run notd yourself:

```sh
php -S 127.0.0.1:8080 index.php    # data goes to data/, or to $NOTD_DATA_DIR
```

## The size budget

`node tools/budget.mjs` counts non-blank lines in the core, leaving out vendored libraries:

| Core | Counted | Limit |
| --- | --- | ---: |
| PHP | `server/` and `index.php` | 2,500 |
| JavaScript | `client/` | 5,000 |
| CSS | `client/` | 2,000 |

It also fails when core code imports or requires anything from `plugins/` directly. Going over a limit is a signal to move something into a plugin or simplify it, not to raise the limit; raising one needs an entry on the decisions page.

## Storage

Settings live in `config.local.php` (copy `config.local.example.php`); each can be overridden from the environment as `NOTD_<KEY>`. Everything notd keeps is in the data folder: `data_dir` (`$NOTD_DATA_DIR`), else `data/` next to `index.php`. To use notd from other devices, set a password with `php tools/password.php`; until then only the computer notd runs on gets an answer (see [login](../protocol/login/)). It holds `notd.sqlite` and the `files/` folder, where uploaded files are kept once per identical content. `php tools/verify.php` checks every stored file against its SHA-256 and reports contents that no node describes (`--clean` deletes those).

The schema is `server/schema.sql`:

| Table | Holds |
| --- | --- |
| `nodes` | Every page, block, document and file: kind, parent, sort key, title, `title_key`, text, clock, times. |
| `fields` | Index of `{key::value}` fields, with the value as a number when it is one. |
| `links` | Index of links, tags, block references and embeds, by source and target. |
| `changes` | The change feed: one row per change, numbered. |
| `search` | The FTS5 full-text index of titles and text (diacritics kept). |
| `jobs` | The job queue and log: programs and plugin jobs to run after changes. |
| `files` | For each file node: SHA-256, size, content type and where the content is stored. |
| `applied_ops` | Operation ids already applied, so a retried batch is not applied twice. |
| `meta` | The schema version, the last clock, and other facts about the notebook. |

The database runs in WAL mode, so reading never waits for writing. All writes go through `Db::write()`, which takes the write lock up front (`BEGIN IMMEDIATE`), retries with a growing pause when another writer holds it, and rolls everything back on an exception. There are no migrations: a database with a different schema version is refused, and moves over through Markdown export and import. Until notd 4 reaches daily use the schema version stays 1 and the schema changes freely; throw-away databases are all there is.

All changes to notes go through `Ops::apply()` (`server/ops.php`), the code behind [`POST /api/v1/ops`](../protocol/operations/). It stamps each changed node with the next hybrid logical clock and appends to the change feed; other parts (the indexer, later plugins' actions) hook in through `Ops::$listeners`, wired in `server/core.php`.

The indexer (`server/indexer.php`) reads the [text syntax](../protocol/syntax/) into the `fields` and `links` tables after every create and edit. `php tools/reindex.php` rebuilds both tables, and the search index, from the text of every node. `php tools/export.php` and `php tools/import.php <folder>` (the importer is `tools/lib/import.php`, a tool rather than core) write and read [Markdown](../protocol/export/); the import is tested against a sample Logseq graph in `tests/fixtures/logseq-graph/`.

## The browser app

`client/index.html` is the whole page: the frame, the styles and `client/app.js`, with no inline script. The server sends it for `/`; everything under `client/` is served as written. Pages are addressed in the fragment (`#/page/Østfold`; empty means the journal), so notd works from any folder of a web server without rewrite rules.

```text
client/
  index.html      the frame, the styles and app.js (no inline script)
  app.js          start-up: ping, the login gate, the frame, the route
  lib/html.js     lit-html (and its directives) for the core and for plugins
  lib/api.js      one function per endpoint; errors as ApiError(status, code, message)
  lib/panels.js   the panel registry: panels.register({ id, slot, order, render(el, ctx), when(ctx) })
  lib/cache.js    cached(key, read): reads that panels repeat, kept until the change feed brings changes
  lib/keys.js     sort keys and ids
  lib/markdown.js note text to HTML: marked plus notd's syntax as marked extensions (no DOM)
  lib/links.js    what is being typed after [[ or #, suggestions, rewriting links on rename (no DOM)
  lib/feed.js     live updates: polls the change feed every 10 s and when the tab is shown again
  lib/commands.js the command registry, keys, and the one click listener for data-command
  core-commands.js  the commands the core comes with
  ui/menus.js     the command palette, the slash menu and a block's menu
  lib/plugins.js  loads plugins' browser code and calls activate(notd); safe mode
  lib/route.js    addresses: #/page/<title>[/<block id>], today's journal when empty
  lib/model.js    the blocks of the page on screen, in memory (no DOM)
  lib/outbox.js   operations waiting for the server: in order, kept in localStorage, retried
  lib/outline.js  the one place blocks change: model + undo/redo + outbox, and change events for the view
  panels/plugins.js  the plugins panel and the safe-mode notice
  panels/         the core panels: search and save status (the bar), page title with the favourite star,
                  favourites, recent pages and calendar (left)
  ui/gate.js      the login screen
  ui/page.js      one page on screen: loads it, draws its outline, one outbox for the app
  ui/journal.js   the journal: today's page, then earlier days with notes, loaded as you scroll
  ui/outline-view.js  draws the outline from the model and redraws only what a change touched; a block's {view:: …} draws its children through ui/query.js instead
  ui/text.js      draws a block's text: Markdown through DOMPurify, then references and embeds filled in
  ui/autocomplete.js  link suggestions at the caret
  ui/attach.js    files pasted, dropped or picked: uploaded and linked in the block's text
  ui/query.js     {{query ...}} and views: results in place (list, table, groups, groups by day and hour), run again on changes
  ui/templates.js templates (pages under notd/templates/) in the slash menu, and "Save as a template"
  lib/placeholders.js  {{date}}, {{page}}, {{cursor}} and the rest, filled in when a template goes in
  lib/remote.js   blocks shown but not on a page on screen (query results): changed through the server
  lib/notice.js   short messages in the bar (what an upload does, why a file was refused)
  panels/backlinks.js linked references under the page
  panels/namespace.js pages inside pages: the pages a title is under (above it) and the pages under it
  lib/namespace.js    which titles sit directly under a title
  ui/drag.js      moving blocks by dragging their bullet (pointer events, so touch works too)
  ui/toolbar.js   the editing toolbar on phones; its buttons send the editor the same keys a keyboard would
  ui/editor.js    editing: one block at a time as plain text (contenteditable="plaintext-only"), the keys
  style/tokens.css  colours, sizes and fonts, light and dark; the look is described at the top
  style/app.css     the frame, panels, menus
  style/outline.css the outline's geometry and the text inside blocks
  vendor/         prebuilt libraries, rebuilt by tools/vendor.sh
```

The frame's slots are `app.top`, `app.left`, `app.right`, `page.header`, `page.before`, `page.after` and `app.status`. The sidebars run the full height of the window; the bar above the page holds `app.top` (search) and `app.status` (the save status). The sidebars are grid columns, so they can never cover the page; under 900 px wide they become drawers.

The look ([decision 15](../overview/decisions/)) comes from `style/tokens.css`: the system's sans-serif for note text, Newsreader (vendored, OFL) for titles and headings, warm greys with an ink-blue accent, light and dark. Every title, heading and box under the page lines up with the note text, at `--inset` from the page's left edge, with bullets and fold toggles hanging to its left; a plugin that adds something under the page uses the same variable. Small labels are in sentence case, and space or a tinted background separates things before a line does. `dayLabel()` in `lib/route.js` shows a journal page's title as a date in the reader's language; the title itself stays `YYYY-MM-DD`. `tests/js/tokens.test.mjs` checks that every text colour reads at 4.5:1 on the page and the sidebars, in light and dark. The page is sent with a strict Content-Security-Policy: `script-src 'self'` (scripts only from notd itself, no inline script, no `eval`), no plugins, no framing.

There is no import map ([decision 14](../overview/decisions/)). Every module, core, vendored or plugin, imports by relative path to a `.js` file: lit-html through `client/lib/html.js`, marked and DOMPurify by their paths under `client/vendor/`. `tests/js/modules.test.mjs` fails on a bare name such as `'lit-html'`, on a `.mjs` file, on an import of a file that does not exist and on any inline script in `client/index.html`. Text files are checked out with LF line endings on every system (`.gitattributes`), so the files notd serves are the same bytes everywhere.

### The outline's geometry

Every block is a row of three boxes: the fold toggle, the bullet and the text. The toggle and bullet boxes are exactly as tall as the first line of the text (taller for a `#`, `##` or `###` heading), so the bullet sits on the middle of the first line whatever the text is; this fixes the notd 3 bullets that sat low beside headings. A child is indented one toggle width, so its bullet sits under its parent's text, and the guide line runs down the middle of the parent's bullet. `tests/browser/geometry_test.py` measures all of this at 375, 768, 1100 and 1440 px wide, and checks that a page of 3,000 blocks is drawn in under 0.8 s.

### Drawing text

A block's text is drawn in three steps. Text with no Markdown or syntax characters at all is set as plain text, which keeps big pages fast. Anything else goes through `client/lib/markdown.js`: marked, with notd's syntax (`[[links]]`, `#tags`, `((references))`, `{{embed}}`, `{key::value}` fields, `file:` links) as marked inline extensions, so nothing inside code counts. The HTML then goes through DOMPurify, which removes scripts, event handlers, forms and unsafe URLs, before it reaches the page. Last, references and embeds are filled in from the server, each block fetched once per page load.

marked, DOMPurify and the Newsreader font are vendored by `tools/vendor.sh` like lit-html, and so are the excalidraw plugin's Excalidraw 0.17 and React 18 (in `plugins/excalidraw/vendor/`, about 6 MB with fonts and translations, loaded only when a drawing is opened). The script saves DOMPurify as `purify.js` (some servers send `.mjs` with a MIME type the browser refuses for a module), drops the comments that point to source maps that are not vendored, and stops if a vendored file imports anything by a bare name. Because everything is imported by relative path, the Node tests load client modules such as `markdown.js` directly.

### How an edit travels

A key press calls the outline controller (`client/lib/outline.js`): for example `split(id, caret)`. The controller works out how to undo the action, applies it to the model, queues the operation in the outbox, and tells the view what changed, which redraws only that. The outbox sends operations in order, in batches, with an `opId` each; edits to one block that are still waiting are folded into one. If the server cannot be reached, the queue is kept in `localStorage` and retried with a growing pause, and nothing on screen is rolled back; after a reload the waiting operations are applied again on top of what the server sends. Changes from other devices arrive through the change feed and are merged into the model, except for parts of a block that still have unsent changes here.

Undo works on actions, not keystrokes: everything one key press does (a split, an indent, a merge with its moved children) is one step, and redo replays it with the same ids. Typing inside a block is left to the browser's own undo while the block is being edited. This code is ported from notd 3, where it had proved itself, and is tested in Node by `tests/js/outline.test.mjs`.

## Browser tests

`tests/browser/*_test.py` drive Chromium with Playwright against a server on a throw-away data folder. Each file runs on its own:

```sh
pip install playwright && python3 -m playwright install chromium
python3 tests/browser/frame_test.py
```

Test pages are normally opened with Playwright's `bypass_csp`, because its string predicates need `eval`. `frame_test.py` also runs the app under its real policy (`open_page(..., csp=True)`, waiting with selectors only), once from this checkout and once from a copy with Windows (CRLF) line endings, and requires no console error, so a refused script shows up there; a conformance test checks the policy itself. `support.py` provides `Server` (with `ops()` to set up notes through the API, `log()` for what the server printed, and `root` to serve another copy of notd), `browser()`, `open_page()` (which collects console errors so a test can require none) and the `@test` runner.

`Server` runs one PHP process, deliberately: PHP's multi-worker mode (`PHP_CLI_SERVER_WORKERS`) is experimental and drops requests when a page load asks for many modules at once. Its output goes to a log file, never to a pipe that nobody reads: once a pipe is full, PHP stops in the middle of a request and the test hangs.
