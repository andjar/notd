// notd bundles Excalidraw without the Mermaid converter (about 3 MB), so that it works offline at a sensible size.
export async function parseMermaidToExcalidraw() {
    throw new Error('Mermaid diagrams cannot be converted in notd\'s offline copy of Excalidraw.');
}
export default { parseMermaidToExcalidraw };
