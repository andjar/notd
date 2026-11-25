---
title: "Architecture"
weight: 20
description: "How notd 4 is built: one table of nodes, a thin server, a browser app, and two ways to extend it."
---

This page describes the target. Parts that are not built yet are marked *(later)*; the [overview](../) says what is in each phase.

## The parts

```text
browser app (client/)          plugins (plugins/)
  outliner, journal, panels      tasks, git, ... (public API only)
        │  protocol: HTTP + JSON, documented, tested
server (server/)
  operations · indexer · search · queries · files · change feed · export/import · plugin host
        │
storage (data/)
  notd.sqlite (text only) + files/YYYY/MM/
```

The server is thin: it stores, indexes, searches and exports. The browser builds the interface. Between them is a [protocol](../../protocol/) whose examples are run as tests, so either side can be rewritten without the other.

## Data model

Everything is a **node** in one `nodes` table. A node's `kind` is `block`, `page`, `doc` *(later)* or `file`. A journal is a page whose title is a date. Blocks nest through `parent_id` and are ordered by fractional sort keys: base-62 strings compared byte by byte, where a new key can always be made between two others (`a0` < `a0V` < `a1`). Moving a block therefore changes one row, and the browser can pick the key itself, with the same algorithm as the server.

- **Text is the truth.** Fields are written inline as `{key::value}`, links as `[[Page]]`, tags as `#tag`. The `fields` and `links` tables are indexes built from the text and can always be rebuilt.
- **User types are fields.** A movie review is a page with `{type::movie}`; no schema change is needed for new kinds of notes.
- **Titles compare without case in any language.** A `title_key` (Unicode NFC, case-folded, spaces collapsed, done in PHP because SQLite's `LOWER()` only knows ASCII) makes `Østfold` and `østfold` the same page, while `Ålesund` and `Alesund` stay different. A title is at most 255 characters, cannot contain control characters or square brackets, and is unique among pages.
- **Ids are UUIDv7**, made by whoever creates the node (the server or a client), so they sort by creation time and a client can refer to a node before the server has seen it.
- **System nodes** under `notd/` hold settings, templates and the like, so they are searchable, exportable and versioned like any other note.
- **Files** live in `data/files/YYYY/MM/`, named by their SHA-256, with a `file` node describing each.

## Changes

Every change is an **operation** (create, edit, move, delete, collapse) sent to one endpoint, in atomic batches with idempotent ids. The server applies them in a transaction, updates the indexes, records an undo group and appends to the **change feed**, which other devices poll to update live. This one write path is used by the interface, plugins and scripts alike.

## Search and queries

Full-text search uses SQLite FTS5 (diacritics kept, so `å` and `a` stay different). Queries are written in a short syntax that compiles to parameterised SQL:

```text
{{query type:movie rating>=4 sort:-watched}}
```

Raw SQL is a plugin *(later)* over stable read-only views (`v_nodes`, `v_fields`, `v_links`, `v_files`).

## The browser app

Plain ES modules, imported by relative path (no import map, no build). The outliner is notd's own code, with a fixed row geometry checked by an automated test at four screen widths. lit-html (3 KB compressed) renders panels, dialogs and menus. The frame has named **slots** (`app.left`, `app.right`, `page.before`, `page.after`, `app.status`, menus, the command palette); search, calendar, backlinks and the rest are **panels** registered into slots, by the core and by plugins alike.

## Extending notd

The core is small on purpose: anything else is a plugin in `plugins/<name>/` with a manifest, an optional browser module and optional PHP. Plugins use only the public API, and the core never imports a plugin. Two mechanisms carry almost every extension:

1. **Syntax that triggers code.** A plugin registers a pattern (`TODO ` at the start of a block, `{{name args}}` macros, `SQL{...}`, `{{@citekey}}`) and how to render it. Interactive parts carry `data-command`; a click runs the command, which edits the note's text, so the result is undoable and synced. The tasks plugin turns a leading `TODO` into a checkbox this way.
2. **Actions that react to changes.** Small PHP files with `on`, `match` (query syntax), `do` (operations inside the same transaction) and `after` (outside effects such as running a script or committing to git, through a logged job queue after the commit).

Commands, panels, query filters and indexers are registered the same way. Safe mode (`?safe=1`) starts notd with all plugins off.

## Export and history

A deterministic Markdown export writes one file per page, and only files that changed. The git plugin commits that export after changes, which gives full version history in a format any tool can read. A general export framework (query, template, output folder, commands afterwards) for things like a CV or a publication list comes *later*, when a second exporter is wanted.

## Devices

One server that every device can reach (a home machine over Tailscale, or a small VPS with HTTPS), a single-password login, and the change feed. An installable web app with an offline cache and conflict copies comes *later*.
