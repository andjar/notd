---
title: "Writing an Extension"
weight: 100
description: "An extension is a folder with a config.json and a page that uses the HTTP API."
aliases: ["/docs/extensions/additional-extensions/"]
---

An extension is a folder in `extensions/` with two files:

- `config.json`, with at least `{"featherIcon": "<icon name>"}`: the sidebar icon, a name from [Feather](https://feathericons.com/). Put the extension's own settings next to it.
- `index.php`, the page that opens when the icon is clicked. It can be a complete page, or include one, as the default extensions do (`<?php require __DIR__ . '/kanban.php';`).

Add the folder name to `ACTIVE_EXTENSIONS` in `config.local.php` and reload notd: the icon appears in the right sidebar.

## Working with notes

Everything goes through the [HTTP API]({{< relref "../api-reference/_index.md" >}}), the same one the interface uses. The usual calls:

| Need | Call |
|---|---|
| Add notes to a page (created if needed) | `POST api/v1/append_to_page.php` |
| Read a note / a page's notes | `GET api/v1/notes.php?id=...` / `?page_id=...` |
| Change a note | `POST api/v1/ops.php` with an `edit` operation |
| Find notes | `GET api/v1/search.php?q=...`, `?tasks=TODO`, or `POST api/v1/query_notes.php` |
| Attach a file | `POST api/v1/attachments.php` |

Write what you store as notd text: properties as `{key::value}` (three colons for internal ones that are hidden while reading), links as `[[Page]]`. Then search, queries and the other extensions can use it.

In the browser, `assets/js/api_client.js` wraps these calls; it is an ES module, so import what you need: `import { searchAPI, notesAPI } from '../../assets/js/api_client.js';`. Each call returns the response's `data` or throws an error with the server's message.

## Looking like notd

Load the app's theme, stylesheet and libraries with relative paths, so the extension works offline and when notd is served from a sub-folder:

```php
<?php $assetBase = '../../'; require '../../assets/css/theme_loader.php'; ?>
<link rel="stylesheet" href="../../assets/css/main.css">
<script src="../../assets/libs/alpine.min.js" defer></script>
```

Link back to notd with `../../`, not `/`.

## Examples to copy from

- `extensions/kanban_board/kanban.php`: reads tasks, filters by properties, edits notes when a card is dragged.
- `extensions/pomodoro_timer/pomodoro_timer.php`: settings in `config.json`, writes notes with `append_to_page.php`.
- `extensions/rss_handler/rss_handler.php`: a command-line script that talks to the API over HTTP.

The note-menu entries (**Open in Zen Writer** and the others) are listed in `assets/js/ui/note-menu.js`; an editor for single notes is added there.
