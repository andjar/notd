// What a drawing is, as text: an image of an .excalidraw.svg file (an SVG with
// the Excalidraw scene inside it, so it shows anywhere and opens again for
// editing), or a link to an .excalidraw file (from a Logseq graph). No DOM;
// tested in Node.

const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const LINK = new RegExp(`!?\\[([^\\]\\n]*\\.excalidraw(?:\\.svg)?)\\]\\(file:(${UUID})\\)`, 'gi');

/** The drawings in a block's text: [{ name, file }], in order. */
export function drawingsIn(text) {
  return [...text.matchAll(LINK)].map((m) => ({ name: m[1], file: m[2].toLowerCase() }));
}

/** The text with the drawing of file `file` replaced by `link`. */
export function replaced(text, file, link) {
  return text.replace(LINK, (all, name, id) => (id.toLowerCase() === file ? link : all));
}

/** The text with `link` added at the end. */
export function appended(text, link) {
  const t = text.replace(/\s+$/, '');
  return t ? `${t} ${link}` : link;
}

/** The image of an uploaded drawing. */
export const linkFor = (node, name) => `![${name.replace(/[[\]\n]/g, '')}](file:${node.id})`;

/** The file name a drawing is saved under: "Kitchen.excalidraw" and "Kitchen.excalidraw.svg" give "Kitchen.excalidraw.svg". */
export const svgName = (name) => `${name.replace(/\.excalidraw(\.svg)?$/i, '') || 'Drawing'}.excalidraw.svg`;

/** A new drawing's name: "Drawing 2026-11-27 14.05.excalidraw.svg". */
export function newName(d = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `Drawing ${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}.${p(d.getMinutes())}.excalidraw.svg`;
}

/** Excalidraw's language for the browser's: the same code, else one for the same language, else English. */
export function langCode(wanted, codes) {
  const w = String(wanted || '').toLowerCase();
  const lang = { nb: 'nb-no', no: 'nb-no', nn: 'nn-no' }[w.split('-')[0]] ?? w;
  return codes.find((c) => c.toLowerCase() === lang)
    ?? codes.find((c) => c.toLowerCase().split('-')[0] === lang.split('-')[0]) ?? 'en';
}
