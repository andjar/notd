---
title: "Writing plugins"
weight: 25
description: "How a plugin is made, what it can use (plugin API 1), and safe mode."
---

Everything beyond notes, links, search, queries, files and export is a plugin, including the ones that ship with notd. A plugin uses only the API on this page, the same one the core's own panels use, so it can be switched off without breaking anything: your notes stay as they are, as plain text.

## Plugins that come with notd

| Plugin | Does |
| --- | --- |
| `tasks` | TODO/DOING/WAITING/DONE/CANCELLED with a checkbox, start, scheduled, deadline and done times, the `task:` query filter and an agenda on journal pages. Read `plugins/tasks/` as an example: it uses syntax, commands, a panel, an indexer and a filter. |

## A plugin is a folder

```text
plugins/hello/
  plugin.json     what it is
  main.js         browser code (optional)
  server.php      server code (optional)
  hello.css       styles (optional)
```

`plugin.json`:

```json
{
  "name": "hello",
  "version": "1.0.0",
  "api": 1,
  "description": "Says hello in the right sidebar.",
  "browser": "main.js",
  "server": "server.php",
  "styles": ["hello.css"]
}
```

`name` must be the folder's name, and `api` the plugin API it was written for; notd runs only plugins for the API it offers (1). Plugins are found in `plugins/` and in any other folder listed in `plugin_dirs` (separated by `:`, or `;` on Windows) in `config.local.php`. Every plugin found is on until it is switched off in the **Plugins** panel at the bottom of the left sidebar; that choice is kept in the notebook. A plugin whose code fails is reported there, and the others carry on.

## Browser code

`main.js` is an ES module, served as written, with an `activate` function. It may import its own files with relative paths, and `lit-html` through the import map.

```js
export function activate(notd) {
  notd.panels.register({
    id: 'greeting', slot: 'app.right',
    render(el, ctx) { el.textContent = `Hello! You are on ${ctx.route.title}.`; },
  });
}
```

| `notd.` | What it is |
| --- | --- |
| `version` | `1`, the plugin API. |
| `plugin` | This plugin's name. |
| `api` | One function per endpoint of the [protocol](../protocol/), for example `api.query('tag:idea')` or `api.ops([...])`. |
| `panels.register({ id, slot, order, render(el, ctx), when(ctx) })` | Puts a panel in a slot: `app.top`, `app.left`, `app.right`, `page.header`, `page.before`, `page.after`, `app.status`. Ids are prefixed with the plugin's name. `ctx` has `route` (`title`, `block`, `journal`) and `page` (the page on screen, once loaded). |
| `panels.refresh(slot)` | Draws the panels again. |
| `commands.register({ id, title, run(ctx), key, slash, menu, when(ctx) })` | Adds a command (the id is prefixed with the plugin's name unless it already is). `key` like `"Mod+Enter"` (Ctrl, or Cmd on a Mac); `slash: true` puts it in the slash menu; `menu: 'block'` in a block's menu. `ctx` has `id` (the block), `outline`, `editor`, `page`, and in the slash menu `insert(text)`. |
| `commands.run(id, extra)` | Runs a command. Any element with `data-command="id"` runs it when clicked, with `ctx.id` set to the block it is in. |
| `syntax.register({ name, pattern, at, render(match) })` | Draws text that matches `pattern`. With `at: 'start'` it must open the block (a task's `TODO `) and is drawn in front of the rest; otherwise it may appear anywhere, but never inside code. `render` returns HTML, which is sanitised like everything else; give interactive parts a `data-command` so a click runs a command for that block. The text itself is never changed by drawing. |
| `go(title, block)` | Opens a page. |
| `on(event, fn)` | Listens for `route`, `page`, `saving` and `renamed`. |

Actions join the API in a later step.

## Server code

`server.php` returns a function that gets a `PluginApi`:

```php
<?php
return function (PluginApi $notd): void {
    $notd->onChange(function (PDO $pdo, string $op, string $nodeId): void {
        // runs inside the transaction of every change
    });
};
```

`PluginApi` also has:

| Method | Does |
| --- | --- |
| `onChange(fn(PDO $pdo, string $op, string $nodeId))` | Runs inside the transaction of every change. |
| `indexer(fn(string $text): array)` | Reads fields out of a block's text: return `[[key, value], ...]`, indexed like `{key::value}`, so they can be queried. Rebuilt for every block by `php tools/reindex.php`. |
| `filter(string $key, fn(string $operator, string $value): array)` | Adds a query filter such as `task:open`: return `[sql, params]`, a condition on the nodes table `n`. |

A tasks plugin, for example, can draw `TODO ` as a checkbox (`syntax`), index it as `{status::todo}` (`indexer`) and answer `task:open` (`filter`), all without the core knowing what a task is. Turned off, its blocks simply read `TODO buy milk` again.

## Safe mode

Open notd with `?safe=1` in the address (`http://…/?safe=1`) and no plugin runs, in the browser or on the server; the status line says so. Use it when a plugin breaks notd, then switch that plugin off. `safe_mode` set to `'1'` in `config.local.php` does the same for every request.
