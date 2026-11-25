---
title: "Using notd"
weight: 15
description: "How to find your way around notd 4. Grows with every step that adds something you can use."
---

notd opens on the **journal**: today's page at the top, ready to write in, and below it the earlier days on which you wrote something, newest first. More days load as you scroll down, and every day can be edited right there. Click a day's date to open it on its own. Days you opened but never wrote in are left out.

Any other page has an address of its own, `#/page/<title>`, shown in the address bar.

## Pages and blocks

A page is an outline of **blocks**: each bullet is one block, and blocks nest under each other. The small triangle left of a bullet that has children folds them away; a bullet with a ring around it has hidden children. **Zooming:** click a bullet to show just that block and what is under it. Breadcrumbs above it lead back to the page and to the blocks around it. Enter in the zoomed block adds a child, so new blocks stay in view.

**Moving with the mouse or a finger:** drag a block by its bullet. A line shows where it will go: above or below the block under the pointer, or, further to the right, as its first child. Its children move with it.

A link to a page that does not exist yet shows “There is no page called … yet” with a button to create it. Journal pages (titles like `2026-11-25`) are created the moment you open them.

## Writing

Click a block's text to edit it; you then see and edit its raw text (a heading shows its `#`). Click below the last block, or on “Click here to write” on an empty page, to add a block at the end. Changes are saved as you type.

| Key | Does |
| --- | --- |
| Enter | Splits the block at the caret: the text after it becomes a new block below. |
| Shift+Enter | A new line inside the block. |
| Tab / Shift+Tab | Indents the block under the one above / moves it back out. |
| Backspace at the start | Joins the block onto the one above (an empty block is removed). |
| Delete at the end | Joins the block below onto this one. |
| ↑ / ↓ on the first / last line | Moves to the block above / below. |
| Alt+Shift+↑ / ↓ | Moves the block, with its children, up or down. |
| Ctrl+↑ / ↓ (Cmd on a Mac) | Folds / unfolds the block's children. The triangle left of the bullet does the same. |
| Escape | Stops editing. |
| Ctrl+Z / Ctrl+Shift+Z (Cmd on a Mac) | Undoes / redoes the last action: a split, an indent, a move, a join. Text you typed in the block you are editing is undone first. |

## What you can write

Text is Markdown: `**bold**`, `*italic*`, `` `code` ``, `[a link](https://…)`, lists, quotes, tables and fenced code. A block starting with `# `, `## ` or `### ` is a heading. On top of that:

| Write | Shows |
| --- | --- |
| `[[Page]]` | A link to a page (it need not exist yet). |
| `#tag`, `#[[two words]]` | A tag: a link that says what the block is about. |
| `((block id))` | The text of another block, linked to it. |
| `{{embed ((block id))}}`, `{{embed [[Page]]}}` | A block with its children, or a whole page, shown in place. |
| `{rating:: 5}` | A field, shown as a small label; fields can be queried. |
| `![name](file:id)` | A stored image (or `[name](file:id)` for a link to a file). |

## Commands

- **Ctrl+K** (Cmd+K on a Mac) opens the command palette: type a few letters of what you want, Enter runs it. Every command, including those plugins add, is there, with its key if it has one.
- **/** in a block opens the slash menu: headings, today's date, a link, a code block, and whatever plugins add. Keep typing to narrow it, Enter or Tab picks.
- **Right-click a bullet** (or long-press it on a phone) for the block's menu: copy a reference to it, zoom in, fold or unfold everything under it, delete it.
- **Ctrl+/** goes to search.

## Tasks

A block that starts with `TODO`, `DOING`, `WAITING`, `DONE` or `CANCELLED` is a task (the tasks plugin, on by default).

- The **checkbox** ticks it: an open task becomes `DONE` and gets `{done:: …}` with the date and time; ticking a done task makes it `TODO` again.
- The **word** after the checkbox steps through `TODO` → `DOING` → `WAITING`. The first time a task becomes `DOING` it gets `{start:: …}`.
- **Ctrl+Enter** (Cmd+Enter) in a block makes it a task, ticks it, and makes it a plain block again.
- In the slash menu: `/todo`, `/doing`, `/waiting`, `/done`, `/cancelled`, and `/scheduled` or `/deadline`, which add `{scheduled:: today}` or `{deadline:: today}`; change the date as you like.
- On a **journal page**, the agenda above the page lists open tasks scheduled for that day or before, deadlines on or before it (overdue in red), and what is in progress.
- In queries: `task:open`, `task:todo`, `task:doing`, `task:waiting`, `task:done`, `task:cancelled`, `task:any`, together with any other filter, for example `{{query task:open deadline<=2026-12-01}}`.

Everything is in the text, so with the plugin switched off a task simply reads `TODO buy milk`. Ticking is undone with Ctrl+Z like any other change.

## Version history

notd keeps every version of your notes (the git plugin, on by default). A minute after you stop typing, the whole notebook is saved as Markdown files and committed to a git repository in the data folder (`history/`). Under every page, **History** lists the saved versions: click one to read it, and **Restore this version** to bring it back. Restoring is itself a change, so it can be undone the same way. The repository is ordinary git: you can push it anywhere as a backup, or read your notes with any tool.

## Links

Type `[[` or `#` and notd suggests pages as you type (their aliases count too). Arrow keys choose, Enter or Tab picks, Escape closes the list. The last entry, “New page: …”, links to a page that does not exist yet; it is created when you open it.

Under every page, **linked references** list the blocks on other pages that link to it or tag it, also through its aliases, grouped by page. Click a block's bullet to go to it.

**Renaming:** click a page's title, type the new name and press Enter (Escape cancels). Every `[[link]]` and `#tag` that pointed at the old name is changed to the new one, in one go. Links that use one of the page's aliases are left as they are; they still work.

## Around the page

- **Search** sits in the top bar. Type a few letters of any words, in any order; titles come first, then notes, with the matching words marked. Arrow keys move, Enter opens. If no page has that exact title, the last entry creates it and opens it. Quotes search for a phrase and `-word` leaves out notes with that word.
- **The star** next to a page's title adds it to your **favourites** in the left sidebar. Favourites are stored in the notebook itself, as links on the page `notd/favourites`, so every device shows the same list and you can reorder them by editing that page.
- **Recent** lists the pages you opened last on this device.
- **The calendar** shows the month of the journal page you are on; a dot marks days you wrote something. Click a day to open it, or the arrows to change month.
- **The status line** at the bottom says whether your changes are saved.
- The **☰** and **◧** buttons hide and show the sidebars. On a phone they open the sidebars as drawers; tap the page to close them.

## On a phone

notd works in the phone's browser. While you edit a block, a toolbar sits just above the keyboard: ⇤ outdent, ⇥ indent, ↑ and ↓ move the block, `[[` starts a link, ↶ and ↷ undo and redo, ✓ stops editing. Drag a bullet with your finger to move a block. The sidebars open as drawers from the ☰ and ◧ buttons. Long words, code and tables never make the page wider than the screen; code and tables scroll inside their own box.

## Several devices

Open notd on another computer or your phone and it shows the same notebook. Changes made on one device appear on the others within about ten seconds, and at once when you switch back to a tab. A block you are typing in is never overwritten: what you type wins until it is saved. A block you have open but have not changed takes the new text.

notd follows your system's light or dark setting.
