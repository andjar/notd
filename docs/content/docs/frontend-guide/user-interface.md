---
title: "Interface & Shortcuts"
weight: 50
---

## Layout

![notd main layout](/images/frontpage.png)

| Area | Contents |
|---|---|
| Left sidebar | Calendar, search, recent pages, **Search or Create Page**, extensions |
| Centre | Page title (with the gear icon for page properties) and the outline |
| Right sidebar | Favorites, backlinks, child pages |
| Bottom right | Save status |

Both sidebars can be collapsed with the arrows at the top. On a phone the sidebars are hidden and a toolbar at the bottom opens them and adds notes.

## The note menu

Right-click a bullet for actions on that note:

- **Copy transclusion link**: the `!{{...}}` text that embeds this note elsewhere
- **Copy anchor link**: a URL to this note
- **Upload**: attach a file
- **Open in Zen / Excalidraw / Math notepad**: open the note in one of those extensions
- **Delete**: remove the note and its children

## Keyboard shortcuts

### Anywhere

| Shortcut | Action |
|---|---|
| `Ctrl + K` | Go to the search field |
| `Ctrl + Space` | Search or create a page |
| `Shift + Space` | Search notes (outside a note) |
| `Ctrl + B` | Show backlinks |
| `Ctrl + Alt + Left / Right` | Previous / next day (on a journal page) |
| `Escape` | Close a window |

### In a note

| Shortcut | Action |
|---|---|
| `Enter` | New note below |
| `Shift + Enter` | Line break |
| `Ctrl/Cmd + Enter` | New child note |
| `Tab` / `Shift + Tab` | Indent / outdent |
| `Up` / `Down` | Previous / next note |
| `Ctrl/Cmd + Up / Down` or `Alt + Shift + Up / Down` | Move the note up / down among its siblings |
| `Backspace` in an empty note | Delete the note |
| `/` | Insert a template |
| `[[` | Suggest pages to link |
| `:t` `:k` `:d` `:r` + space | Insert `{tag::}`, `{keyword::}`, today's date, the current time |

## Themes

Set `ACTIVE_THEME` in `config.local.php` to one of `default`, `darkly`, `flatly`, `purple` or `ubuntu`. A theme is a single CSS file of custom properties in `assets/css/themes/`; copy one to make your own.
