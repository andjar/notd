---
title: "Quick Start"
weight: 5
---

## The screen

![notd main layout](/images/frontpage.png)

- **Left sidebar:** calendar, search, recent pages, a button to find or create a page, and your extensions.
- **Centre:** the current page: its title and its outline of notes.
- **Right sidebar:** favorites, backlinks to this page and its child pages.

notd opens on today's journal page.

## Write

![a first note](/images/my_first_note.png)

Click the text of a note (or the **+** below the last note) and type. Each bullet is a note, written in Markdown; it is shown formatted as soon as you leave it.

| Key | Action |
|---|---|
| `Enter` | New note below (in the middle of the text: split the note there) |
| `Shift + Enter` | Line break inside the note |
| `Tab` / `Shift + Tab` | Indent / outdent |
| `Ctrl/Cmd + Enter` | New child note |
| `Backspace` at the start | Join with the note above |
| `Ctrl/Cmd + Z` | Undo |
| `Escape` | Stop editing |

Drag a bullet to move a note together with its children. Click the arrow next to a note with children to collapse it, and click the bullet itself to zoom into the note.

## Link

![Typing [[ suggests pages](/images/suggest.png)

Type `[[` and a few letters: notd suggests existing pages, or offers to create a new one. Choose with the arrow keys and `Enter`. The page you link to lists your note in its backlinks.

## The page menu

![The page dialog](/images/page_dialog.png)

Hover over the page title for the ☆ (add to favourites) and the ⚙. The ⚙ opens the page dialog: rename the page, edit its own text and properties, and export, print, lock or delete it.

Everything is saved as you type. The indicator in the bottom-right corner shows the state; if the server cannot be reached, notd keeps your edits and sends them later.

## Try the essentials

**Link to a page.** Type `[[` and pick a page, or type a new name. Clicking the link opens the page, creating it if needed.

```markdown
Kickoff notes are in [[Projects/Alpha]]
```

**Make a task.** Start a note with a task keyword. Each task is its own note.

```markdown
TODO Send the agenda
```

**Add properties.** Put `{key::value}` anywhere in a note.

```markdown
TODO Send the agenda {priority::high} {due::2026-11-14}
```

**Find things.** `Ctrl + K` jumps to the search field. `Ctrl + Space` opens a page by name. `Shift + Space` (outside a note) searches note contents.

**Mark a favorite.** Click the gear next to the page title and add `{favorite::true}` to the page properties.

## Next

- [Writing notes]({{< relref "core-features.md" >}}) covers queries, transclusion, attachments, templates and encryption.
- [Extensions]({{< relref "../extensions/_index.md" >}}) add a kanban board, a pomodoro timer and more.
