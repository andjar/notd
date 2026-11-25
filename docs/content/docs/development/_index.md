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

`harness.mjs` provides `startServer()` (with `get`, `post` and `stop`), `mismatch()` for comparing a response with what is expected, and the extractor that turns the examples on the [protocol pages](../protocol/) into tests (`docs_examples.test.mjs`). A change to the protocol therefore starts with its example in the docs.

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

Everything notd keeps is in the data folder: `$NOTD_DATA_DIR` if set, else `data_dir` in `config.local.php` (copy `config.local.example.php`), else `data/` next to `index.php`. It holds `notd.sqlite` and, later, the `files/` folder.

The schema is `server/schema.sql`:

| Table | Holds |
| --- | --- |
| `nodes` | Every page, block, document and file: kind, parent, sort key, title, `title_key`, text, clock, times. |
| `fields` | Index of `{key::value}` fields, with the value as a number when it is one. |
| `links` | Index of links, tags, block references and embeds, by source and target. |
| `changes` | The change feed: one row per change, numbered. |
| `search` | The FTS5 full-text index of titles and text (diacritics kept). |
| `applied_ops` | Operation ids already applied, so a retried batch is not applied twice. |
| `meta` | The schema version, the last clock, and other facts about the notebook. |

The database runs in WAL mode, so reading never waits for writing. All writes go through `Db::write()`, which takes the write lock up front (`BEGIN IMMEDIATE`), retries with a growing pause when another writer holds it, and rolls everything back on an exception. There are no migrations: a database with a different schema version is refused, and moves over through Markdown export and import. Until notd 4 reaches daily use the schema version stays 1 and the schema changes freely; throw-away databases are all there is.

All changes to notes go through `Ops::apply()` (`server/ops.php`), the code behind [`POST /api/v1/ops`](../protocol/operations/). It stamps each changed node with the next hybrid logical clock and appends to the change feed; other parts (the indexer, later plugins' actions) hook in through `Ops::$listeners`, wired in `server/core.php`.

The indexer (`server/indexer.php`) reads the [text syntax](../protocol/syntax/) into the `fields` and `links` tables after every create and edit. `php tools/reindex.php` rebuilds both tables, and the search index, from the text of every node.
