---
title: "Mail Handler"
weight: 80
description: "A script that turns an e-mail into a note on today's journal page. Experimental."
---

![An e-mail as a note: subject as heading, sender, recipient and text, with mail properties](/images/mail.png)

The mail handler reads one e-mail message (in the raw `.eml` format mail programs and servers use) and adds it as a note to today's journal page. It is experimental: it handles plain-text messages. It does not decode MIME, so for HTML messages, messages with attachments, or encoded text and subjects, the note gets the message body as it was sent, encoding and all.

It does not fetch mail itself. Something else hands it the message: a mail server rule that pipes incoming mail to a program, a mail client's "pipe to command" action, or you, with a saved `.eml` file.

## Using it

Set notd's address in `extensions/mail_handler/config.json`:

```json
{
  "featherIcon": "mail",
  "api_url": "http://localhost:8000/api"
}
```

and give the script a message on its standard input:

```bash
php extensions/mail_handler/mail_handler.php < message.eml
```

The address can also be given as an argument, which wins over the configuration file:

```bash
php extensions/mail_handler/mail_handler.php http://localhost:8000/api < message.eml
```

With Postfix, for example, an alias that pipes a dedicated address to the script sends everything mailed there into notd:

```text
notes: "|php /path/to/notd/extensions/mail_handler/mail_handler.php"
```

## What the note looks like

The subject becomes a heading ("Email: ..."), followed by sender, recipient and the text of the message. The note gets `{tag::mail}` and properties for the sender, the recipient and the subject (`email_from_address`, `email_from_name`, `email_to_address`, `email_to_name`, `email_subject`), so all mail from one person is one query away.
