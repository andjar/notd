---
title: "Writing plugins"
weight: 25
description: "How a plugin is made, what it can use (plugin API 1), and safe mode."
---

Everything beyond notes, links, search, queries, files and export is a plugin, including the ones that ship with notd. A plugin uses only the API on this page, the same one the core's own panels use, so it can be switched off without breaking anything: your notes stay as they are, as plain text.

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
| `go(title, block)` | Opens a page. |
| `on(event, fn)` | Listens for `route`, `page`, `saving` and `renamed`. |

Commands, syntax and actions join the API in the next steps.

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

## Safe mode

Open notd with `?safe=1` in the address (`http://…/?safe=1`) and no plugin runs, in the browser or on the server; the status line says so. Use it when a plugin breaks notd, then switch that plugin off. `safe_mode` set to `'1'` in `config.local.php` does the same for every request.
