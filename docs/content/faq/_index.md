+++
draft= false
title = "FAQ"
description = "Frequently Asked Questions"
+++

## Where is my data?
In one SQLite file, `db/database.sqlite`, plus the `uploads/` folder for attachments. Notes are stored as the plain text you typed. Back up those two and you have everything.

## Does it need an internet connection?
No. Nothing is loaded from the internet, and edits made while the server is unreachable are kept in the browser and sent later. The only exception is the optional Excalidraw extension.

## Can I undo a mistake?
Yes. `Ctrl + Z` undoes typing first and then outline changes (new, moved, indented and deleted notes), up to 200 steps while the page is open. `Ctrl + Shift + Z` redoes.

## Is there a login?
No. notd is made for one person on their own machine. If you host it where others can reach it, put it behind HTTP authentication or a VPN.

## Can several people or devices use it?
Several devices can use the same server; each loads the current state when a page is opened. It is not a real-time collaboration tool: two people editing the same note at the same time will overwrite each other, last save wins.

## How do I move to another machine?
Copy the folder, or copy `db/database.sqlite`, `uploads/` and `config.local.php` into a fresh download.

## Can I get my notes out?
Yes, in three ways: `php tools/export.php <folder>` writes every page as a Markdown file (Logseq's layout, attachments included); the ⚙ next to a page title has **Export** for a single page; and the database is ordinary SQLite with a documented schema (`db/schema.sql`). The [API]({{< relref "../docs/api-reference/_index.md" >}}) returns everything as JSON too.

## Can I bring my notes from Logseq?
Yes: `php tools/import.php ~/logseq/pages ~/logseq/journals`. Pages, journals, nesting and `key:: value` properties come across. Logseq-only features (block references by UUID, `#tags`, queries in Logseq's syntax) are kept as text.

## How is notd different from Logseq?
notd keeps the outliner, the daily journal, links, backlinks, tasks and properties, and leaves out the rest. In exchange it has no build step, no desktop runtime and no sync service: it is PHP and SQLite, runs on any web host or a laptop, opens large pages quickly, and its whole code base can be read in an afternoon.

## How are properties stored?
You write them in the text, for example `{priority::high}`. The server keeps a searchable index of them, but the text stays the source of truth.

## Which task states are there?
`TODO`, `DOING`, `DONE`, `SOMEDAY`, `WAITING` and `CANCELLED`. The list is the `TASK_STATES` setting.

## How do I change notes from a script?
Use [`append_to_page.php`]({{< relref "../docs/api-reference/append-to-page.md" >}}) to add notes, and [`ops.php`]({{< relref "../docs/api-reference/ops.md" >}}) to create, edit, move or delete them in one atomic request.

## What are the upload limits?
10 MB per file. Images, PDF, text, office documents, zip, audio and video are accepted; HTML, SVG and scripts are refused because a browser could run code in them.

## I forgot the password of a locked page.
The content cannot be recovered. Encryption happens in the browser and the server never sees the password.
