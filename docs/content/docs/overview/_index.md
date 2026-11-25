---
title: "Overview"
weight: 10
description: "What notd 4 is, what it is not, and where the rewrite stands."
---

notd is a personal outliner and journal for one person, used for decades. It opens on today's journal page; notes nest as deep as you like; `[[links]]` connect pages and every page shows what links to it. Everything beyond that (tasks, git history, kanban boards, exports) is a plugin.

## Status

notd 4 is a clean rewrite on the [`next`](https://github.com/andjar/notd/tree/next) branch. All four phases below are built: notd 4 has reached its first milestone, **daily use**, and is ready to replace notd 3, which stays on `main` until the switch ([Switching from notd 3](../guide/switching/)). The work was done in about 40 small pull requests:

| Phase | What it gives |
| --- | --- |
| 0. Foundations | The repository layout, the checks every pull request must pass, the test runners and the size budget. |
| 1. Core server | Storage, operations, links and fields, search, queries, files, the change feed, login, Markdown export and import. |
| 2. Journal and outliner | The browser app: journal, outline editing, links and backlinks, search, phone layout. |
| 3. Plugins for daily use | The plugin host, commands, the syntax registry, actions and jobs, and the tasks and git plugins. Then notd 4 replaces notd 3. |

Everything else (the export framework, custom views, documents, images, offline use on the phone) waits until it is missed in daily use or a second real use asks for it.

## Principles

1. **Small core.** The core stores notes, links and fields, searches and queries them, keeps files, exports and imports. Everything else is a plugin using the public API, and CI keeps the core within a line budget.
2. **No build step.** PHP files and ES modules are served as written. Third-party libraries are committed as prebuilt files, each with a script that rebuilds it.
3. **Long-lived parts.** PHP, SQLite, HTML, CSS and JavaScript, with no runtime dependencies to fetch. A written protocol and its conformance tests let the server or the browser app be replaced on its own.
4. **The text is the truth.** Fields (`{key::value}`), links and tags are parsed from note text; every index can be rebuilt from it.
5. **Your notes stay yours.** One SQLite file plus a folder of files; a deterministic Markdown export that the git plugin commits.

## Not goals

Several users, live collaboration, native mobile apps, whiteboards, flashcards, PDF annotation and AI features. notd 4 is a tool its owner can read and change, not a competitor to Logseq, Obsidian, Trilium or SiYuan.
