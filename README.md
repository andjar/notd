# notd

notd is a personal outliner and journal: one page per day, notes nested as deep as you like, links and backlinks, and small plugins for everything else. It is made of plain PHP, plain JavaScript and one SQLite file, with no build step, so it can be read, run and changed for decades.

> **This is the `next` branch: notd 4, a clean rewrite, ready for daily use.** The working version, notd 3, is on [`main`](https://github.com/andjar/notd/tree/main). The [overview](docs/content/docs/overview/_index.md), [architecture](docs/content/docs/overview/architecture.md) and [decisions](docs/content/docs/overview/decisions.md) say what notd 4 is and why.

## What is where

| Folder | What it holds |
| --- | --- |
| `server/` | The PHP server: storage, operations, indexing, search, queries, files, export and import. |
| `client/` | The browser app, served as written: HTML, CSS and ES modules, with third-party libraries vendored as prebuilt files. |
| `plugins/` | Plugins that ship with notd. Each folder is one plugin and uses only the public API. |
| `tests/` | `php/` unit tests, `conformance/` protocol tests against a running server, `browser/` end-to-end tests. |
| `tools/` | Scripts for development and CI, such as the core size budget. |
| `docs/` | The documentation site (Hugo), including the protocol that the conformance tests check. |

## Getting started

See [Switching from notd 3](docs/content/docs/guide/switching.md) for importing your notes and serving notd 4 (Apache `.htaccess` included).

## Checks

```sh
php tests/php/run.php                      # server unit tests
node --test tests/conformance/*.test.mjs   # protocol tests (start their own server)
node tools/budget.mjs                      # core size budget
php -S 127.0.0.1:8080 index.php            # run notd, then open http://127.0.0.1:8080/
python3 tests/browser/frame_test.py        # a browser test (needs Playwright)
```

Every pull request runs the checks in GitHub Actions (`.github/workflows/ci.yml`).

## Licence

GPL 3. See [LICENSE](LICENSE).
