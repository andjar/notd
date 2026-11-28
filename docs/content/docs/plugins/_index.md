---
title: "Writing plugins"
weight: 25
description: "How a plugin is made, what it can use (plugin API 1), and safe mode."
---

Everything beyond notes, links, search, queries, files and export is a plugin, including the ones that ship with notd. A plugin uses only the API on this page, the same one the core's own panels use, so it can be switched off without breaking anything: your notes stay as they are, as plain text.

## Plugins that come with notd

| Plugin | Does |
| --- | --- |
| `excalidraw` | Drawings: **/Drawing** opens [Excalidraw](https://excalidraw.com) over the page; the drawing is saved as an SVG picture with the drawing inside it, so it shows in notes and the export like any image, and a click opens it again. Logseq drawings (`.excalidraw` files) open the same way. See [Excalidraw](excalidraw/). |
| `git` | Version history: a minute after you stop typing (`git_delay`), the Markdown export is committed to a git repository (`git_dir`, default `history/` in the data folder). A History section under each page lists its versions; look at one and restore it. Needs the `git` program on the server. |
| `kanban` | Boards: `{{kanban}}` in a block shows tasks as cards in columns by state, or any notes a query finds by a field (`group:stage`). Drag a card to another column to change its keyword or field. Needs `tasks` for boards of tasks. See [Kanban](kanban/). |
| `mail` | E-mail into notes: an `.eml` file dropped onto notd, picked with **Add an e-mail**, or piped in by a mail server becomes a folded note on today's journal, with its attachments stored. See [Mail](mail/). |
| `rss` | Feeds in notes: a block with `{feed:: https://…}` gets the feed's new items under it, each once, shown by day or by hour through the block's view. Feeds are read every hour (`rss_every`, in minutes) and straight away when the address is written. See [RSS](rss/). |
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

`main.js` is an ES module, served as written, with an `activate` function. It imports by relative path only: its own files, and lit-html as `import { html, render } from '../../client/lib/html.js'` (that module also has `nothing`, `svg`, `unsafeHTML`, `repeat`, `classMap` and `live`). The path is relative to the file doing the import, and a plugin's files are always served under `/plugins/<name>/`, wherever its folder is. There is no import map, so a bare name such as `'lit-html'` does not resolve. A prebuilt library that is not an ES module goes in the plugin's `vendor/` folder and is loaded with a `<script>` element when it is needed, as the excalidraw plugin does with Excalidraw and React; the module tests leave `vendor/` folders out.

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
| `commands.register({ id, title, run(ctx), key, slash, menu, when(ctx) })` | Adds a command (the id is prefixed with the plugin's name unless it already is). `key` like `"Mod+Enter"` (Ctrl, or Cmd on a Mac); `slash: true` puts it in the slash menu; `menu: 'block'` in a block's menu. `ctx` has `id` (the block), `outline`, `editor`, `page`, and in the slash menu `insert(text)`. While an element with the attribute `data-own-keys` is on the page (a plugin's full-screen editor), notd's keys are off, so that element's keys are its own. |
| `commands.run(id, extra)` | Runs a command. Any element with `data-command="id"` runs it when clicked, with `ctx.id` set to the block it is in. |
| `syntax.register({ name, pattern, mount(el, match, ctx) })` | Shows something live for text that matches `pattern`, as the core does for `{{query}}`: `mount` gets an empty element in the block and fills it, and runs again whenever the block is drawn. `ctx` has `block` (the node), `depth` (how deep in embeds and queries) and `renderText(el, text, node, depth)`. Listen for `notd:changes` on `document` to show the latest. Clicks inside the element do not start editing the block, except on an element with the class `widget-edit`; give elements that stand for another block `data-node="<id>"`, so a `data-command` inside them acts on that block. |
| `syntax.register({ name, pattern, at, render(match) })` | Draws text that matches `pattern`. With `at: 'start'` it must open the block (a task's `TODO `) and is drawn in front of the rest; otherwise it may appear anywhere, but never inside code. `render` returns HTML, which is sanitised like everything else; give interactive parts a `data-command` so a click runs a command for that block. The text itself is never changed by drawing. |
| `go(title, block)` | Opens a page. |
| `on(event, fn)` | Listens for `route`, `page`, `saving` and `renamed`. |
| `notice(text, { error, sticky })` | Shows a short message in the bar above the page for a few seconds (an error a little longer, a sticky one until the next message). |
| `files.register({ accept(file), handle(file, { block }) })` | Takes files of a kind of its own when they are pasted, dropped or picked, instead of having them stored: `handle` returns the text to put in the block (`block` is null when the file was dropped onto the page), or `''`. The mail plugin takes `.eml` files this way. |



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
| `endpoint('name', ['GET' => fn(Request $r): array, 'POST' => ...])` | Adds an endpoint at `/api/v1/<plugin>.<name>`; the browser calls it with `notd.api.get('<plugin>.<name>', query)` or `notd.api.post(...)`. |
| `indexer(fn(string $text): array)` | Reads fields out of a block's text: return `[[key, value], ...]`, indexed like `{key::value}`, so they can be queried. Rebuilt for every block by `php tools/reindex.php`. |
| `filter(string $key, fn(string $operator, string $value): array)` | Adds a query filter such as `task:open`: return `[sql, params]`, a condition on the nodes table `n`. |

A tasks plugin, for example, can draw `TODO ` as a checkbox (`syntax`), index it as `{status::todo}` (`indexer`) and answer `task:open` (`filter`), all without the core knowing what a task is. Turned off, its blocks simply read `TODO buy milk` again.

## Actions

Behaviour that should happen when notes change is written as a small PHP file in `actions/` (your own, never committed with notd) or in a plugin's `actions/` folder. There is no rule language to learn; an action is a few lines of PHP:

```php
<?php
// actions/movie-needs-rating.php: tag every movie that has no rating yet
return [
    'on' => 'saved',                          // saved (created or edited), created, edited or deleted
    'match' => 'type:movie -has:rating',      // a query the block must answer (optional)
    'do' => fn(array $node, Actions $notd) => $notd->addTag($node, 'needs-rating'),
];
```

`do` runs inside the same transaction as the change: what it changes is saved, synced and exported with it, and if the change fails, so does the action's part. The block (`$node`) is in the protocol's shape (`id`, `text`, `page`, ...). `Actions` offers:

| Method | Does |
| --- | --- |
| `addTag($node, 'tag')` | Adds `#tag` to the block's first line, unless it is already tagged. |
| `setField($node, 'key', 'value')` | Sets `{key:: value}`, replacing an old value. |
| `edit($id, $text)` | Replaces a block's text. |
| `create($parentId, $text)` | Adds a block; returns its id. |
| `query('...')`, `node($id)` | Reads, like the query endpoint. |
| `run(['program', 'arg', ...], key, timeout, cwd)` | Runs a program **after** the change is saved, as a job (see below). Arguments are passed as they are, never through a shell. |
| `queue('kind', $payload, key)` | Queues a job for a plugin's job handler. |

Changes an action makes can set off actions again, at most three deep; a loop is stopped there and reported, and the change itself still goes in. An action that throws is reported (in the server's log) and skipped. No action runs in safe mode. Point `actions_dir` in `config.local.php` elsewhere to keep your actions with your notes.

## Jobs: effects outside notd

Running a program, committing to git or posting to a website must not happen inside a change: the change could still fail, and a slow program would hold everything up. So `run()` and `queue()` only add a **job** to a queue, in the same transaction as the change; a job exists exactly when its change does. notd runs waiting jobs right after it has answered the request (or `php tools/jobs.php`, from cron, if your web server cannot work after answering).

- A job has a time limit (30 s unless you give another); a program that runs longer is stopped.
- What it printed is kept; `GET /api/v1/jobs` lists the last 200 jobs with their status (`waiting`, `running`, `done`, `failed`) and output.
- A failed job is tried again, up to three times in all.
- A **key** makes jobs coalesce: queuing a job while another with the same key is still waiting replaces it, so ten quick edits give one git commit, not ten.

```php
<?php
// actions/done-tasks-ping.php: when a task is ticked, run a script of your own
return ['on' => 'edited', 'match' => 'task:done',
        'do' => fn(array $node, Actions $notd) => $notd->run(['/home/me/bin/task-done', $node['text']])];
```

A job can wait for quiet: `Jobs::queue($pdo, 'git.commit', [], 'git.commit', 60)` runs only after 60 seconds without a newer job of that key, on whichever request comes then. A plugin handles its own kinds of job with `$notd->jobHandler('git.commit', fn(array $payload): string => ...)`; the returned string is logged.

## Safe mode

Open notd with `?safe=1` in the address (`http://…/?safe=1`) and no plugin runs, in the browser or on the server; the save status in the bar says so. Use it when a plugin breaks notd, then switch that plugin off. `safe_mode` set to `'1'` in `config.local.php` does the same for every request.
