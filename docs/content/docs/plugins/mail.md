---
title: "Mail"
weight: 20
description: "E-mail messages as notes, with their attachments: from Outlook, Gmail and any mail program."
---

The mail plugin turns an e-mail message into a note. It reads the two formats messages travel in: `.eml`, which most mail programs save and mail servers pass on, and Outlook's own `.msg`.

## From your mail program

- **Outlook for Windows (the classic program):** drag a message, or several, from Outlook onto notd in Chrome or Edge. Outlook hands each one over as a `.msg` file. Dropped onto a block, the note goes to today's journal and a reference to it goes into the block; dropped onto the page, only the note is added. In phpdesktop, if the drop does not arrive, drag the messages to a folder first (Outlook saves them as `.msg` files there), then drop those.
- **The new Outlook and Outlook on the web:** they do not let messages be dragged into web pages. Use **Save as** › **Save as EML** (on the web: **Download** › **Download as EML**), then drop the file. Chrome's download list can be dragged from directly.
- **Gmail:** open the message, then ⋮ › **Download message** and drop the `.eml` file, from Chrome's download list or from your Downloads folder. Or ⋮ › **Show original** › **Copy to clipboard**, then paste into a block in notd: the pasted message becomes a note, and a reference to it goes where you pasted.
- **Thunderbird, Apple Mail and others:** drag the message out to save it as an `.eml` file, then drop that.

**Add an e-mail (.eml or .msg file)** in the command palette opens the file picker, and **/file** or **Attach a file** take e-mail files the same way.

## What the note looks like

The message goes to today's journal page as one folded block, `Email: <subject> #mail`, with the sender and the time as fields: `{from:: kari@example.no} {date:: 2026-11-27 09:30}`. Unfold it for the sender, the recipients and the time as written, then the text, then the attachments. Attachments are stored as notd files: images show, other files are links. One notd does not accept, such as a program, is named under "Not stored". An e-mail attached to the message becomes a note of the same kind under it, `Attached e-mail: <subject>`. An Outlook calendar item, contact or task dragged in starts with `Meeting:`, `Contact:` or `Outlook task:` instead.

Times are on the notebook's clock (the `timezone` setting), so a view such as `{view:: group:date:day sort:-date}` on a block of messages sorts them into days.

So all mail is one query away, `tag:mail`, and all mail from one person too: `tag:mail from:kari@example.no`.

A message is added only once. Its note's id is worked out from its `Message-ID` (or, without one, from what it says), so a mail server that delivers twice, or a second drag and drop, changes nothing.

The text is read the way mail programs write it: any character set, `quoted-printable` and `base64`, encoded subjects and names, messages with both a text and an HTML version (the text one is used), and HTML-only messages, turned into Markdown with their links, lists and emphasis. From `.msg` files: Unicode and older 8-bit messages in their own code page (Japanese, Cyrillic, Central European and so on), and the headers the message arrived with, when Outlook kept them.

## From the command line or a mail server

On the computer notd runs on:

```sh
php plugins/mail/mail.php message.eml          # or several, .eml or .msg, or a folder of them
php plugins/mail/mail.php < message.eml        # a message on standard input
php plugins/mail/mail.php --page Inbox message.msg
```

In phpdesktop: `php\php.exe www\plugins\mail\mail.php message.msg`.

A mail server can pipe a dedicated address to the tool. With Postfix, for example, an alias:

```text
notes: "|php /path/to/notd/plugins/mail/mail.php"
```

The tool exits as mail servers expect: `0` when the message was added or was there already, `65` when the input is not an e-mail message, `75` when notd is busy (the server keeps the message and tries again later), and `1` for anything else. The mail server's user must be able to write to notd's data folder.
