---
title: "Text syntax"
weight: 20
description: "What notd reads out of the text of a note: fields, links, tags, references and embeds."
---

A note's text is Markdown. The core reads a few more things out of it and keeps them in an index, so they can be searched and queried; the text itself is never changed by this. Plugins can add syntax of their own (later); what follows is the core.

| Write | Meaning | Indexed as |
| --- | --- | --- |
| `{key::value}` | A field. The key is letters, digits, `_`, `.` and `-`, and is compared in lower case. The value runs to the closing brace, on one line. | a field; a value that is a number is also stored as one, so `rating>=4` works |
| `{tags:: a, [[b c]], #d}` | Tags, as a list. | one `tags` field and one tag per item |
| `{alias:: NYC, New York}` | Other names for this page, as a list. | one `alias` field per item |
| `[[Page]]` | A link to the page with that title (case and Unicode form do not matter). | a `link` to the page's title key |
| `#tag`, `#[[two words]]` | A tag: a link that also says what the note is about. Letters, digits, `_`, `-` and `/`; not after a letter, `&` or `/`, so `C#`, `&#39;` and URL anchors are not tags. | a `tag` to the page's title key |
| `((id))` | A reference to a block, by its id. | a `ref` to the block id |
| `{{embed ((id))}}`, `{{embed [[Page]]}}` | Shows a block or a page in place. | an `embed` to the block id or page title key |

Nothing inside inline code (`` `...` ``) or fenced code blocks counts.

Links point at titles, not ids, so a link to a page that does not exist yet is fine: the page appears when it is created, and its backlinks are already there.
