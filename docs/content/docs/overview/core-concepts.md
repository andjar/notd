---
title: "Core Concepts"
weight: 30
aliases: ["/docs/overview/advanced-features/", "/docs/overview/best-practices/"]
---

## Pages

A page is a named container of notes. Names are unique and case-insensitive.

- **Journal pages** are named after a date (`2026-11-10`) and are created the first time you open that date.
- **Namespaces.** A `/` in the name creates a hierarchy: `Projects/Alpha` is a child of `Projects`, and each page lists its children.
- A page has its own content for page-level properties, edited through the gear icon next to the title.

## Notes

A note is one bullet in the outline: a piece of Markdown that belongs to a page and optionally to a parent note. Notes have a stable id, which you can use to link to a note (`page.php?page=Name#note-<id>`) or to embed it elsewhere.

## Properties

Properties are metadata written inside the text of a note or page:

```text
Call the plumber {priority::high} {due::2026-11-14}
```

The number of colons sets the property's **weight**:

| Syntax | Weight | Use |
|---|---|---|
| `{key::value}` | 2 | Public: shown in the interface. |
| `{key:::value}` | 3 | Internal: hidden when reading, shown when editing. |
| `{key::::value}` | 4 | Log: each new value is kept as history instead of replacing the last. |

The text is the source of truth. The server parses it into an index that powers search, queries, backlinks, the kanban board and webhooks. Remove a property from the text and it disappears from the index.

A few properties have a meaning to notd itself:

| Property | Where | Effect |
|---|---|---|
| `{favorite::true}` | page | Lists the page under Favorites. |
| `{type::journal}` | page | Marks a journal page (set automatically for date pages). |
| `{alias::Other Page}` | page | Makes the page an alias of another page. |
| `{internal::true}` | note | Hides the note unless internal notes are requested. |
| `{encrypt::...}` | page | Set by the Encrypt action; do not edit by hand. |

The server also derives properties from content, such as `links_to_page` for every `[[link]]`, `status` for tasks and `done_at` when a task is completed.

## Tasks

A note that starts with a task keyword is a task:

```text
TODO Write the report
DOING Review pull request
DONE Book the flights
```

The keywords are `TODO`, `DOING`, `DONE`, `SOMEDAY`, `WAITING` and `CANCELLED`. Clicking the checkbox toggles between `TODO` and `DONE`. Each change of state is recorded as a `status` log property, so the history of a task can be queried.

## Links, transclusion and queries

| Syntax | Result |
|---|---|
| `[[Page Name]]` | A link to the page (created when first opened). The target page lists it as a backlink. |
| `!{{note-id}}` | Embeds that note and its children. Right-click a bullet and choose "Copy transclusion link" to get this text. |
| `SQL{SELECT id FROM Notes WHERE ...}` | Lists the notes selected by the query. See [Query Notes]({{< relref "../api-reference/query-notes.md" >}}) for the allowed forms. |

## How saving works

Every change in the outline (typing, Enter, Tab, drag and drop, collapsing, deleting) is applied in the browser at once and queued as a small operation for the server. The queue is kept in the browser's storage, so edits survive a reload or a lost connection and are sent, in order, when the server is reachable. Notes get their permanent id in the browser, so nothing ever waits for the server. Details are under [Development]({{< relref "../development/_index.md" >}}).
