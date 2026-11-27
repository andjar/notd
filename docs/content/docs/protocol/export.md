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

**Importing again is safe.** Every block keeps its `id::`, or gets an id worked out from its file, its parent and its text. A block whose id is already in the notebook is left as it is, with any edits made in notd since. So a second import of the same folder adds nothing, and an import of a graph you kept writing in adds only the new blocks. A block whose text changed in the meantime counts as new.

In phpdesktop on Windows, run the tool with phpdesktop's own PHP, from its folder: `php\php.exe www\tools\import.php "C:\path\to\graph"`.

It also reads **Logseq graphs** (and so notd 3 exports, which use Logseq's layout):

- `journals/2026_11_25.md` becomes the journal page `2026-11-25` (any `:journal/file-name-format` in `logseq/config.edn` is understood); `pages/A___B.md` becomes `A/B`, and so does `A.B.md` in an older graph whose `config.edn` uses the legacy file names; `%3A` and the like are decoded; `title::` wins over the file name.
- `key:: value` lines become `{key:: value}` fields; a first block made only of properties becomes the page's text. `id::` and `collapsed::` are kept; `heading:: 2` becomes `## `; `created-at::`, `updated-at::`, `public::`, `filters::`, `background-color::` and `logseq.…::` are dropped.
- Task words `LATER`, `NOW`, `WAIT`, `CANCELED` become `TODO`, `DOING`, `WAITING`, `CANCELLED`; a priority `[#A]` becomes `{priority:: A}`; `SCHEDULED: <date>` and `DEADLINE: <date>` become `{scheduled:: date}` and `{deadline:: date}`; `:LOGBOOK:` drawers are dropped.
- `#+BEGIN_QUOTE` blocks become quotes, `#+BEGIN_SRC lang` and `#+BEGIN_EXAMPLE` fenced code, and `#+BEGIN_NOTE`, `_TIP`, `_WARNING` and the like quotes that start with **Note:**, **Tip:** and so on.
- Links to journal days in the graph's own title format (`:journal/page-title-format`, by default `[[Nov 13th, 2023]]`) become `[[2023-11-13]]`. Titles with square brackets get round ones instead, and links to them are changed to match.
- The pages in `:favorites` in `logseq/config.edn` are added to notd's favourites, once.
- Images and other files linked from a note (`../assets/...`) are stored as notd files and relinked; links to missing files, and to anything outside the graph's folder, stay as they were. Drawings (`[[draws/….excalidraw]]`) are stored the same way and linked as files, `[name.excalidraw](file:…)`; with the excalidraw plugin, clicking one opens it, and saving turns it into a picture.
- Logseq queries (`{{query …}}`) use a language of their own, so they are kept as code and reported; write them again as [notd queries](../queries/).
- `logseq/`, `whiteboards/` and `draws/` are not read as pages; `.org` files are reported and skipped.

**Plain Markdown** works too: text before the first bullet becomes the page's text, and `-`, `*` and `+` bullets become blocks.
