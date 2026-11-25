---
title: "Files"
weight: 35
description: "Uploading and serving images and other files."
---

Files are uploaded with `POST /api/v1/files` as multipart form data, in a field named `file`. The server keeps one copy per identical content, named by its SHA-256 under `files/YYYY/MM/` in the data folder, and describes it with a node of kind `file`. Uploading the same content again, under any name, returns the node it already has.

The response is the file node plus `sha256`, `size` (bytes), `mime` and `url`. `title` is the file name as uploaded (without any folder part). File names need not be unique.

`GET /api/v1/file?id=...` returns the content, with its content type, an `ETag` (the SHA-256, so `If-None-Match` gives `304`), and a long cache lifetime, since the content of a file node never changes. Images, PDFs, plain text, audio and video are shown inline; everything else, including SVG, downloads. Every file is served with `X-Content-Type-Options: nosniff` and a sandboxing `Content-Security-Policy`, so a file cannot run script in notd's origin.

A note refers to a file with a Markdown link or image to `file:<id>`: `![holiday](file:0192f6a0-...)`. The indexer records these, so a file knows where it is used.

## Accepted files

At most 50 MB. The extension must be one of: `png jpg jpeg gif webp avif svg pdf txt md csv json mp3 m4a wav ogg mp4 webm zip docx xlsx pptx odt`, and the content must match it (checked with PHP's `finfo`; plain text must be UTF-8). Anything else is refused with `415 bad_type`; a file that is too large with `413 too_large`.

## Deleting

A file node is deleted with the usual `delete` operation. Its stored content stays until `php tools/verify.php --clean` removes contents that no node describes. `tools/verify.php` without `--clean` only reports missing, corrupt (SHA-256 differs) and orphaned contents.

```request
GET /api/v1/file?id=01900000-0000-7000-8000-000000000000
```

```response
404
{"error": {"code": "not_found", "message": "…"}}
```
