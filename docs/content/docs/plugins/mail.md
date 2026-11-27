---
title: "Mail"
weight: 20
description: "E-mail messages as notes, with their attachments."
---

The mail plugin turns an e-mail message, in the `.eml` format that mail programs save and mail servers pass on, into a note.

## What the note looks like

The message goes to today's journal page as one folded block, `Email: <subject> #mail`, with the sender and the date as fields: `{from:: kari@example.no} {date:: 2026-11-27}`. Unfold it for the sender, the recipients and the date as written, then the text, then the attachments. Attachments are stored as notd files: images show, other files are links. One notd does not accept, such as a program, is named under "Not stored".

So all mail is one query away, `tag:mail`, and all mail from one person too: `tag:mail from:kari@example.no`.

A message is added only once. Its note's id is worked out from its `Message-ID`, so a mail server that delivers twice, or a second drag and drop, changes nothing.

The text is read the way mail programs write it: any character set, `quoted-printable` and `base64`, encoded subjects and names, messages with both a text and an HTML version (the text one is used), and HTML-only messages, turned into Markdown with their links, lists and emphasis.

## Adding messages

- **Drop** an `.eml` file onto notd. Dropped onto a block, the note goes to today's journal and a reference to it goes into the block; dropped onto the page, only the note is added. Pasting or picking an `.eml` file with **/file** or **Attach a file** works the same way.
- **Add an e-mail (.eml file)** in the command palette opens the file picker.
- **From the command line**, on the computer notd runs on:

  ```sh
  php plugins/mail/mail.php message.eml          # or several, or a folder of .eml files
  php plugins/mail/mail.php < message.eml        # a message on standard input
  php plugins/mail/mail.php --page Inbox message.eml
  ```

  In phpdesktop: `php\php.exe www\plugins\mail\mail.php message.eml`.

- **From a mail server.** Have it pipe a dedicated address to the tool. With Postfix, for example, an alias:

  ```text
  notes: "|php /path/to/notd/plugins/mail/mail.php"
  ```

  The tool exits as mail servers expect: `0` when the message was added or was there already, `65` when the input is not an e-mail message, `75` when notd is busy (the server keeps the message and tries again later), and `1` for anything else. The mail server's user must be able to write to notd's data folder.

Outlook (the classic Windows program) saves messages as `.msg` files, a format of its own, which is not read yet; Thunderbird, Apple Mail, the new Outlook and webmail save `.eml`.
