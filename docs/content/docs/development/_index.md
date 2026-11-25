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
- a choice that someone would later ask "why?" about has a short note in the decisions page.

## Rules that keep the core small

1. **No build step.** Nothing is compiled, bundled or installed to run or change notd.
2. **No runtime dependencies to fetch.** No Composer or npm packages at runtime. Test tools may use them.
3. **GET and POST only**, so notd runs under minimal web servers such as phpdesktop.
4. **The core never imports a plugin.** Plugins use the public API only.
5. **One write path.** Every change to notes goes through the operations endpoint, whoever makes it.
