---
title: "Plugins"
weight: 55
description: "GET and POST /api/v1/plugins, plugin files, and the safe-mode header."
---

`GET /api/v1/plugins` lists every plugin found: `{"safe", "api", "plugins": [...]}`, each with `name`, `version`, `description`, `enabled`, `problem` (why it cannot run, or `null`), `browser` (the module to import, or `null`) and `styles`. `POST /api/v1/plugins` with `{"name": "...", "enabled": false}` switches one off (or on), and returns the new list.

A plugin's own files (scripts, styles, pictures) are served at `plugins/<name>/<path>`, never its PHP or its `plugin.json`.

A request with the header `X-Notd-Safe: 1` runs no plugin code on the server. The browser sends it in safe mode.

```request
GET /api/v1/plugins
```

```response
200
{"safe": false, "api": 1, "plugins": [
  {"name": "git", "enabled": true, "problem": null, "browser": "plugins/git/main.js"},
  {"name": "mail", "enabled": true, "problem": null, "browser": "plugins/mail/main.js"},
  {"name": "tasks", "enabled": true, "problem": null, "browser": "plugins/tasks/main.js"}
]}
```

```request
POST /api/v1/plugins
{"name": "nothing-here", "enabled": false}
```

```response
404
{"error": {"code": "not_found", "message": "…"}}
```

How to write a plugin: [Writing plugins](../../plugins/).
