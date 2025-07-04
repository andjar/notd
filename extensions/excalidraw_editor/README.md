# Excalidraw Editor Extension

This extension integrates the [Excalidraw](https://excalidraw.com/) visual whiteboard into the notd application, allowing you to create, edit, and save hand-drawn diagrams directly as note attachments.

## Features
- **Visual Drawing**: Use the Excalidraw editor to sketch diagrams, mind maps, and visual notes.
- **Attachment Integration**: Save your drawing as a PNG image attachment to any note.
- **Note Linking**: Each drawing is associated with a specific note via its `note_id`.
- **Modern UI**: Built with React for the Excalidraw component and Alpine.js for UI controls.
- **Offline Support**: Runs fully offline with phpdesktop.

## How It Works
- Access the Excalidraw editor from the context menu of a note ("Open in Excalidraw").
- The editor opens in a new tab, loading the Excalidraw interface.
- Draw your diagram. When ready, click **Save as Attachment** to upload the drawing as a PNG to the current note.
- The note ID is passed via the URL (e.g., `excalidraw.html?note_id=123`).

## File Overview
- `index.php`: Entry point, serves the Excalidraw HTML UI.
- `excalidraw.html`: Main UI, loads Excalidraw and handles save/upload logic.
- `main.js`: (Legacy/alternate) JS logic for initializing and saving drawings.
- `style.css`: Custom styles for the editor container.
- `config.json`: Extension icon configuration.

## Technical Notes
- Uses [Excalidraw v0.18.0](https://github.com/excalidraw/excalidraw) via CDN.
- React and ReactDOM are loaded via [esm.sh](https://esm.sh/) import maps.
- Drawings are exported as PNG using Excalidraw's `exportToBlob` and uploaded via the main app's `attachmentsAPI`.
- The extension is designed for hybrid use: React for the drawing canvas, Alpine.js for UI controls (see `EXTENSIONS_ALPINEJS_MIGRATION.md`).

## Requirements
- The main app must provide a working `attachmentsAPI` for uploads.
- The extension expects to be launched with a `note_id` parameter in the URL.

## Example Usage
1. Right-click a note and select **Open in Excalidraw**.
2. Draw your diagram.
3. Click **Save as Attachment**. The image will be attached to the note.

---

For questions or issues, see the main project documentation or contact the maintainer. 