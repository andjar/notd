+++
draft= false
title = "FAQ"
description = "Frequently Asked Questions"
+++

## Where is my data?
In one SQLite file, `db/database.sqlite`, plus the `uploads/` folder for attachments. Notes are stored as the plain text you typed. Back up those two and you have everything.

## Does it need an internet connection?
No. Nothing is loaded from the internet, and edits made while the server is unreachable are kept in the browser and sent later. The only exception is the optional Excalidraw extension.

## Is there a login?
No. notd is made for one person on their own machine. If you host it where others can reach it, put it behind HTTP authentication or a VPN.

## Can several people or devices use it?
Several devices can use the same server; each loads the current state when a page is opened. It is not a real-time collaboration tool: two people editing the same note at the same time will overwrite each other, last save wins.

## How do I move to another machine?
Copy the folder, or copy `db/database.sqlite`, `uploads/` and `config.local.php` into a fresh download.

## Can I get my notes out?
Yes. The database is ordinary SQLite with a documented schema (`db/schema.sql`), and the [API]({{< relref "../docs/api-reference/_index.md" >}}) returns everything as JSON.

## How are properties stored?
You write them in the text, for example `{priority::high}`. The server keeps a searchable index of them, but the text stays the source of truth.

## Which task states are there?
`TODO`, `DOING`, `DONE`, `SOMEDAY`, `WAITING` and `CANCELLED`. The list is the `TASK_STATES` setting.

## How do I change notes from a script?
Use [`append_to_page.php`]({{< relref "../docs/api-reference/append-to-page.md" >}}) to add notes, and [`ops.php`]({{< relref "../docs/api-reference/ops.md" >}}) to create, edit, move or delete them in one atomic request.

## What are the upload limits?
10 MB per file; common image, document and text formats are accepted.

## I forgot the password of an encrypted page.
The content cannot be recovered. Encryption happens in the browser and the server never sees the password.
