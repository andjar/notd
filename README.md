# notd

notd is a personal outliner and journal: one page per day, notes nested as deep as you like, links and backlinks, and small plugins for everything else. It is made of plain PHP, plain JavaScript and one SQLite file, with no build step, so it can be read, run and changed for decades.

> **This is the `next` branch: notd 4, a clean rewrite in progress.** The working version, notd 3, is on [`main`](https://github.com/andjar/notd/tree/main). The plan, the steps and the reasons are in the [documentation](docs/content/docs/overview/_index.md).

## What is where

| Folder | What it holds |
| --- | --- |
| `server/` | The PHP server: storage, operations, indexing, search, queries, files, export and import. |
| `client/` | The browser app, served as written: HTML, CSS and ES modules, with third-party libraries vendored as prebuilt files. |
| `plugins/` | Plugins that ship with notd. Each folder is one plugin and uses only the public API. |
| `tests/` | `php/` unit tests, `conformance/` protocol tests against a running server, `browser/` end-to-end tests. |
| `tools/` | Scripts for development and CI, such as the core size budget. |
| `docs/` | The documentation site (Hugo), including the protocol that the conformance tests check. |

## Checks

```sh
php tests/php/run.php   # server unit tests
```

Every pull request runs the checks in GitHub Actions (`.github/workflows/ci.yml`).

## Licence

GPL 3. See [LICENSE](LICENSE).
