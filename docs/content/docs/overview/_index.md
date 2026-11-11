---
title: "Overview"
weight: 10
---

**notd** is a small outliner for notes, journals and tasks, inspired by [Logseq](https://logseq.com/). It runs on anything that runs PHP and keeps everything in one SQLite file that you own.

## What it does

- **Outline editing.** Every note is a bullet that can be nested, collapsed, dragged, reordered and zoomed into. Enter splits a note, Backspace joins it to the one above, and `Ctrl + Z` undoes. Notes are written in Markdown.
- **Journal.** Each day has its own page; notd opens today's page by default.
- **Links and backlinks.** `[[Page Name]]` links pages; every page shows what links to it.
- **Tasks.** Start a note with `TODO`, `DOING`, `DONE`, ... and it becomes a task with a checkbox.
- **Properties.** `{key::value}` anywhere in a note or page becomes searchable metadata.
- **Queries and transclusion.** List notes with `SQL{...}`; embed a note elsewhere with `!{{note-id}}`.
- **Attachments, templates, namespaces, favorites, password-locked pages.**
- **Print and export.** Print a page or save it as PDF; export a page or the whole notebook as Markdown files, and import Markdown, including folders written by Logseq.
- **Extensions.** Kanban board, pomodoro timer, attachment dashboard, distraction-free editor and more.
- **An HTTP API** that can do everything the interface can.

## Built to last

notd is deliberately made of few, well-understood parts:

- **PHP + SQLite on the server, plain JavaScript modules in the browser.** There is no build step, no package manager and no framework to keep up with. The few libraries it uses are stored in the repository.
- **Your data is plain text** in a documented SQLite schema (unless you choose to lock a page). Any SQLite tool can read it, and `tools/export.php` turns it into Markdown files.
- **Works offline.** Nothing is loaded from the internet. Edits made while the server is unreachable are kept in the browser and sent when it is back.
- **Runs anywhere PHP runs**: a laptop (`php -S localhost:8000 router.php`), a NAS, a shared web host, or packaged as a desktop app with [phpdesktop](https://github.com/cztomczak/phpdesktop).

## Where to go next

- [Installation]({{< relref "quick-start.md" >}}): get it running, configure it, upgrade it.
- [Core concepts]({{< relref "core-concepts.md" >}}): pages, notes, properties, tasks.
- [User guide]({{< relref "../frontend-guide/_index.md" >}}): how to use the interface.
- [API reference]({{< relref "../api-reference/_index.md" >}}) and [Development]({{< relref "../development/_index.md" >}}): how to script and change it.
