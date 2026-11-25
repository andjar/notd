---
title: "Markdown export"
weight: 50
description: "POST /api/v1/export: the whole notebook as Markdown files."
---

`POST /api/v1/export` writes the notebook as Markdown to the export folder (`export_dir` in `config.local.php`, default `export/` in the data folder) and returns `{"written", "unchanged", "deleted", "dir"}`. `php tools/export.php [folder]` does the same from the command line. The git plugin commits this folder.

The export is **deterministic**: the same notebook always gives the same bytes, and a file is only written when its content changed, so git sees exactly what changed. Pages that no longer exist are deleted from the folder.

```text
journals/2026-11-25.md     journal pages (empty days are left out)
pages/Østfold.md           every other page; / \ : * ? " < > | % become %2F and so on
files/2026/11/<sha256>.png copies of stored files
```

A page file:

```markdown
title:: Østfold
{alias:: Ostfold}

- Fredrikstad
  id:: 0192f6a0-7c3e-7b1a-9f3e-2b1c4d5e6f70
  - Old town
    collapsed:: true
    a second line of the same block
- See ((0192f6a0-7c3e-7b1a-9f3e-2b1c4d5e6f70))
```

- The first line is the exact title; the file name may differ (unsafe characters are escaped, very long titles cut and given a hash).
- The page's own text follows; a line of it that starts with `- ` is written as `\- `, so it is not read as a block.
- Blocks are a bulleted outline, two spaces per level. Further lines of a block are indented under its bullet.
- `id::` is written only for blocks that something references or embeds, so ids are kept where they matter without filling every line. `collapsed:: true` marks collapsed blocks.
- Links to stored files, `](file:<id>)`, become relative paths to the copy under `files/`, so the export reads well in any Markdown tool.

```request
POST /api/v1/export
```

```response
200
{"written": 0, "unchanged": 0, "deleted": 0, "dir": "…"}
```

## Import

`php tools/import.php <folder>` reads a folder of Markdown back in, through the same operations as any edit, so imported notes are indexed and searchable at once. Importing an export into an empty notebook and exporting again gives the same files. Pages that already exist get the imported blocks added at the end.

It also reads **Logseq graphs** (and so notd 3 exports, which use Logseq's layout):

- `journals/2026_11_25.md` becomes the journal page `2026-11-25`; `pages/A___B.md` becomes `A/B`; `%3A` and the like in file names are decoded; `title::` wins over the file name.
- `key:: value` lines become `{key:: value}` fields; a first block made only of properties becomes the page's text. `id::` and `collapsed::` are kept; `heading:: 2` becomes `## `; `created-at::` and `updated-at::` are dropped.
- Task words `LATER`, `NOW`, `WAIT`, `CANCELED` become `TODO`, `DOING`, `WAITING`, `CANCELLED`; `SCHEDULED: <date>` and `DEADLINE: <date>` become `{scheduled:: date}` and `{deadline:: date}`; `:LOGBOOK:` drawers are dropped.
- Links to journal days such as `[[Nov 13th, 2023]]` become `[[2023-11-13]]`. Titles with square brackets get round ones instead, and links to them are changed to match.
- Images and other files linked from a note (`../assets/...`) are stored as notd files and relinked; links to missing files stay as they were.
- `logseq/`, `whiteboards/` and `draws/` are skipped; `.org` files are reported and skipped.

**Plain Markdown** works too: text before the first bullet becomes the page's text, and `-`, `*` and `+` bullets become blocks.
