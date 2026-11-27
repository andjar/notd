---
title: "Excalidraw"
weight: 12
description: "Drawings in notes, drawn and edited in Excalidraw."
---

The excalidraw plugin lets you draw in your notes with [Excalidraw](https://excalidraw.com), the hand-drawn-looking whiteboard. A drawing is kept as a picture, so it shows in notes, in query results and in the Markdown export like any other image, and it opens again for editing with a click.

## Drawing

- **/Drawing** in a block opens a new drawing over the whole window. Draw, then press **Save** (or Ctrl+S, Cmd+S on a Mac). The picture goes at the end of the block.
- **Click a drawing** in a note to open it again. **Edit drawing** in the block's menu does the same.
- **Save** puts the new version in place of the old one. Ctrl+Z (Cmd+Z) on the page afterwards brings the old version back.
- **Cancel** closes the drawing. If you changed something, it asks for a second click first (“Discard changes”).

While a drawing is open, Excalidraw's own keys work and notd's are off. Excalidraw speaks your browser's language when it has a translation for it.

## How a drawing is stored

Saving uploads the drawing as one file, `<name>.excalidraw.svg`: an SVG picture with the Excalidraw drawing inside it, the format excalidraw.com calls “embed scene”. The block links it as an image, `![Kitchen.excalidraw.svg](file:…)`. Every save is a new file, so earlier versions stay in the notebook's files. An `.excalidraw.svg` you exported from excalidraw.com with “Embed scene” on, and dropped into notd, opens for editing the same way.

A drawing on a plain white background is saved without the background, so it sits on the page; in the dark theme, drawings are shown with their lines lightened, as Excalidraw does.

## Drawings from Logseq

Importing a Logseq graph stores its drawings (`draws/*.excalidraw`) as files and links them: `[2023-11-01-10-00-00.excalidraw](file:…)`. Click the link to open the drawing; saving it turns the link into a picture, `![2023-11-01-10-00-00.excalidraw.svg](file:…)`.

## Size

Excalidraw and React are about 6 MB with fonts and translations, kept in `plugins/excalidraw/vendor/` (`tools/vendor.sh` fetches them). They are loaded the first time you open a drawing, not with the page.
