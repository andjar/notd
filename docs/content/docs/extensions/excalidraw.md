---
title: "Excalidraw"
weight: 60
description: "Hand-drawn diagrams and sketches, saved to a note. Needs an internet connection. Turn it on with ACTIVE_EXTENSIONS."
aliases: ["/docs/extensions/excalidraw-editor/"]
---

[Excalidraw](https://excalidraw.com/) is a whiteboard for quick, hand-drawn-looking diagrams. This extension opens it for a note and saves the drawing to that note.

**It needs an internet connection.** Unlike the rest of notd, Excalidraw is not bundled: it is loaded from a CDN (esm.sh) when the editor opens.

## Turning it on

Add `excalidraw_editor` to `ACTIVE_EXTENSIONS` in `config.local.php` (see [Turning extensions on and off]({{< relref "_index.md#turning-extensions-on-and-off" >}})). It then appears in the note menu. It also gets an icon in the right sidebar, but a drawing can only be saved when Excalidraw was opened from a note.

## Using it

1. Right-click a note's bullet and choose **Open in Excalidraw**. The editor opens in a new tab.
2. Draw.
3. Click **Save as Attachment**.

The drawing is attached to the note twice: as a PNG image, which shows below the note, and as an `.excalidraw` file, which holds the drawing itself. The `.excalidraw` attachment has an **Edit in Excalidraw** button that opens the drawing again; saving it adds the new version to the note.

Drawings imported from Logseq (`[[draws/...excalidraw]]`, see [Importing a Logseq graph]({{< relref "../overview/quick-start.md#importing-a-logseq-graph" >}})) are `.excalidraw` attachments too and open the same way.
