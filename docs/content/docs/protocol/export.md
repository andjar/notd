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
