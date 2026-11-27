---
title: "Kanban"
weight: 15
description: "Tasks, or any notes a query finds, as cards in columns."
---

The kanban plugin draws a board in a block: the notes a query finds, as cards in columns. Moving a card to another column changes its text, so a board never holds anything of its own. Turn the plugin off and the block reads `{{kanban}}` again; the tasks and fields are where they always were.

## A board of tasks

Write `{{kanban}}` in a block, or pick **Kanban board of tasks** in the slash menu, for every task in your notes in four columns: To do, Doing, Waiting and Done (and Cancelled, when a task is). Anything after `kanban` is a [query](../../protocol/queries/) that narrows the cards down:

```text
{{kanban page:Kitchen}}               the tasks on one page (also in the slash menu)
{{kanban tag:renovation}}             the tasks tagged #renovation
{{kanban task:open sort:deadline}}    open tasks only, the soonest deadline first
{{kanban columns:TODO,DOING,DONE}}    only these columns, in this order
```

Moving a card to Doing makes the task `DOING` and gives it a start time; moving it to Done makes it `DONE` with the time it was done, just as ticking its checkbox would. Boards of tasks need the tasks plugin, which answers the `task:` part of the query.

## A board by any field

`group:` puts the cards in a column for each value of a field instead:

```text
{{kanban tag:project group:stage columns:Idea,Building,Shipped}}
```

Each note tagged `#project` is a card in the column of its `{stage:: …}` field. `columns:` gives the columns and their order, so a column can be there before any card is in it; values not listed get a column after them, and notes without the field one at the end. Moving a card sets the field to that column's value, or takes the field away in the last column.

## Moving cards

- **Drag** a card with the mouse. On a touch screen, hold the card still for a moment, then drag.
- **With the keyboard,** Tab to a card and press Shift+Left or Shift+Right.

A card that is a block on the page on screen changes like any edit there, and Ctrl+Z (Cmd+Z) takes the move back. A card from another page changes on the server straight away. Cards keep the query's order (`sort:`, the most recently changed first by default); dragging up or down within a column does not reorder them.

Clicking a card's page name opens the note. Clicking the line above the board edits the block, query and all.
