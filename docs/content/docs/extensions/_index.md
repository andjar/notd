---
title: "Extensions"
weight: 25
aliases: ["/docs/extensions/excalidraw-editor/", "/docs/extensions/additional-extensions/"]
---

Extensions are small apps in the `extensions/` folder that work on your notes through the [API]({{< relref "../api-reference/_index.md" >}}).

## Turning extensions on

Extensions listed in `ACTIVE_EXTENSIONS` get an icon in the left sidebar. Set the list in `config.local.php` using the folder names:

```php
define('ACTIVE_EXTENSIONS', ['kanban_board', 'pomodoro_timer', 'attachment_dashboard']);
```

Zen, Excalidraw and the math notepad work on a single note and are opened from the note menu (right-click a bullet), whether or not they are in the list.

## Included extensions

| Extension | Folder | What it does | Opened from |
|---|---|---|---|
| [Kanban board]({{< relref "kanban-board.md" >}}) | `kanban_board` | All tasks as cards in columns by state; drag a card to change its state. | Sidebar |
| Pomodoro timer | `pomodoro_timer` | Work/break timer that logs each finished session as a note. | Sidebar |
| Attachment dashboard | `attachment_dashboard` | Searchable, filterable list of every uploaded file. | Sidebar |
| Zen | `zen` | Distraction-free full-screen Markdown editor for one note. | Note menu |
| Math notepad | `math_notepad` | Calculation pad: write expressions and variables line by line and see results; saved into the note. | Note menu |
| Excalidraw | `excalidraw_editor` | Whiteboard drawing saved to the note as an image attachment. **Needs an internet connection**: Excalidraw itself is loaded from a CDN. | Note menu |
| RSS handler | `rss_handler` | Command-line script that appends new feed items as notes. | `php extensions/rss_handler/rss_handler.php` |
| Mail handler | `mail_handler` | Command-line script that turns an e-mail piped to it into a note. Experimental. | `php extensions/mail_handler/mail_handler.php < mail.eml` |

### Pomodoro timer

Settings are in `extensions/pomodoro_timer/config.json`:

| Key | Meaning |
|---|---|
| `workDurationMinutes`, `shortBreakDurationMinutes`, `longBreakDurationMinutes` | Lengths of the three session types. |
| `pomodorosBeforeLongBreak` | Work sessions before a long break. |
| `pageTemplate` | Page that receives the session log. `<today>` is replaced with the date, so `<today>/Pomodoro` logs to a child page of the day's journal. |
| `noteTemplate` | Text of the logged note. Placeholders: `{noteTitle}`, `{date}`, `{duration}`, `{pomodoroType}`, `{customProperties}`. |

Text typed in the field below the timer as `key:value, key2:value2` is added to the logged note as properties.

### RSS handler

Configure feeds in `extensions/rss_handler/config.json`, then run the script from a scheduler (cron, Task Scheduler):

```json
{
  "api_url": "http://localhost:8000/api",
  "default_page_name_for_rss": "Inbox/rss",
  "feeds": [
    "https://example.com/feed.xml",
    { "url": "https://example.org/feed", "page_name": "<today>/rss" }
  ]
}
```

Each new item is appended to its page as a note. Items already imported are recognised and skipped.

### Zen and math notepad themes

Both use the bundled CodeMirror editor. `defaultLightTheme` and `defaultDarkTheme` in each extension's `config.json` name a theme from `extensions/zen/codemirror/themes/`.

## Writing an extension

An extension is a folder in `extensions/` with:

- `config.json` containing at least `{"featherIcon": "<icon name>"}` (an icon from [Feather](https://feathericons.com/)); add your own settings next to it,
- `index.php`, the page that opens when the sidebar icon is clicked.

Inside, use the HTTP API. The usual calls:

| Need | Call |
|---|---|
| Add notes to a page | `POST api/v1/append_to_page.php` |
| Read a note / a page's notes | `GET api/v1/notes.php?id=...` / `?page_id=...` |
| Change a note | `POST api/v1/ops.php` with an `edit` operation |
| Find notes | `GET api/v1/search.php?q=...`, `?tasks=TODO`, or `POST api/v1/query_notes.php` |

To match the look of the app, load the theme and stylesheet and the bundled libraries with relative paths, so the extension also works offline and when notd is served from a sub-folder:

```php
<?php $assetBase = '../../'; require '../../assets/css/theme_loader.php'; ?>
<link rel="stylesheet" href="../../assets/css/main.css">
<script src="../../assets/libs/alpine.min.js" defer></script>
```

The kanban board (`extensions/kanban_board/kanban.php`) is a good example to copy from.
