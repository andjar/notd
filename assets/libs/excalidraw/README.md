# Excalidraw, bundled

[Excalidraw](https://github.com/excalidraw/excalidraw) 0.18.0 with React 18.3.1, as one ES module, so the Excalidraw extension (`extensions/excalidraw_editor`) works offline like the rest of notd.

| File | What it is |
|---|---|
| `excalidraw.js` | exports `React`, `createRoot`, `Excalidraw`, `exportToBlob`, `serializeAsJSON`, `restore` |
| `chunks/` | parts loaded only when needed (languages, the font subsetter, ...) |
| `excalidraw.css` | Excalidraw's stylesheet |
| `fonts/` | Excalidraw's fonts; set `window.EXCALIDRAW_ASSET_PATH` to this folder before importing |
| `LICENSES.txt` | the licences of everything bundled (MIT and similar) |
| `build/` | how it was made: `sh build/build.sh 0.18.0` |

Left out to keep the size down (about 5.6 MB instead of 20 MB):

- the "Mermaid to Excalidraw" converter (mermaid, elkjs, cytoscape, katex): it answers that it is not available,
- the Xiaolai font for Chinese and Japanese handwriting: such text uses a system font.
