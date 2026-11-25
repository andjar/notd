---
title: "Using notd"
weight: 15
description: "How to find your way around notd 4. Grows with every step that adds something you can use."
---

notd opens on the **journal**: today's page at the top, ready to write in, and below it the earlier days on which you wrote something, newest first. More days load as you scroll down, and every day can be edited right there. Click a day's date to open it on its own. Days you opened but never wrote in are left out.

Any other page has an address of its own, `#/page/<title>`, shown in the address bar.

## Pages and blocks

A page is an outline of **blocks**: each bullet is one block, and blocks nest under each other. The small triangle left of a bullet that has children folds them away; a bullet with a ring around it has hidden children. Clicking a bullet opens just that block (zooming arrives in a later step).

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

notd follows your system's light or dark setting.
