---
title: "Troubleshooting"
weight: 60
---

| Problem | What to check |
|---|---|
| **A banner says "offline"** or the save indicator shows an error | The server cannot be reached. Your edits are kept in this browser and are sent when it is reachable again; do not clear the browser's site data in the meantime. |
| **A change did not appear in another tab or device** | Reload the page. Tabs in the same browser follow each other; other devices load the current state when the page is opened. |
| **`SQL{...}` shows an error** | The query must have one of the three [accepted shapes]({{< relref "core-features.md#queries" >}}), return note ids and contain no semicolon. |
| **`!{{...}}` shows nothing** | The id must be that of an existing note. Use **Copy transclusion link** from the note menu instead of typing it. Transclusions deeper than three levels are not expanded. |
| **A property is not found by search** | Check the syntax: `{key::value}` with braces and at least two colons. Properties on encrypted pages are not indexed. |
| **A task is missing from the kanban board** | The keyword must be the first word of the note, in capitals, followed by a space, and be listed in `TASK_STATES`. |
| **Wrong password on an encrypted page** | There is no way to recover the content without the password. |
| **An upload is refused** | Files are limited to 10 MB and to common image, document and text types. |
| **An extension is not in the sidebar** | Add it to `ACTIVE_EXTENSIONS` in `config.local.php`. |
| **The page says the database uses an older schema** | Run the migration named in the message; see [Installation]({{< relref "../overview/quick-start.md" >}}). |

For anything else, set `NOTD_DEBUG` to `true` in `config.local.php`, reload, and look at the browser console and the PHP error log.
