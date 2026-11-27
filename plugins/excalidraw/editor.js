// The drawing editor: Excalidraw, over the whole window. Excalidraw and React
// are prebuilt scripts in vendor/ (see tools/vendor.sh), loaded the first time a
// drawing is opened, never before. Saving exports the drawing as an SVG with the
// scene inside it, so the picture shows in notes as it is and opens here again.

import { langCode } from './lib/drawing.js';

const BASE = new URL('./vendor/', import.meta.url).href;
let loading = null;

const script = (src) => new Promise((resolve, reject) => {
  const s = Object.assign(document.createElement('script'), { src, async: false });
  s.onload = resolve;
  s.onerror = () => reject(new Error(`Could not load ${src.slice(BASE.length)}`));
  document.head.append(s);
});

/** Loads React and Excalidraw once. */
function load() {
  window.EXCALIDRAW_ASSET_PATH = BASE;          // fonts and translations come from here, not from a CDN
  loading ??= ['react.js', 'react-dom.js', 'excalidraw.js'].reduce((p, f) => p.then(() => script(BASE + f)), Promise.resolve())
    .catch((e) => { loading = null; throw e; });
  return loading;
}

const el = (tag, props = {}, ...children) => {
  const e = Object.assign(document.createElement(tag), props);
  e.append(...children);
  return e;
};

/**
 * Opens a drawing: `blob` holds the scene (an .excalidraw.svg or .excalidraw file), or is null for a
 * new one. `save(file)` gets the drawing as an .excalidraw.svg File named `name`. Resolves when closed.
 */
export async function openEditor({ blob = null, name, title, save }) {
  await load();
  const { React, ReactDOM, ExcalidrawLib: X } = window;
  const scene = blob ? await X.loadFromBlob(blob, null, null) : null;
  const before = document.activeElement;
  before?.blur?.();                            // a block being edited stops being edited, so keys go to the drawing
  const dark = document.documentElement.dataset.theme
    ? document.documentElement.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;

  const status = el('span', { className: 'drawing-status' });
  const cancel = el('button', { type: 'button', className: 'drawing-cancel', textContent: 'Cancel' });
  const done = el('button', { type: 'button', className: 'drawing-save', textContent: 'Save' });
  const canvas = el('div', { className: 'drawing-canvas' });
  const box = el('div', { className: 'drawing-editor' },
    el('div', { className: 'drawing-bar' }, el('span', { className: 'drawing-title', textContent: title }), status, cancel, done), canvas);
  box.dataset.ownKeys = '';                    // notd's shortcut keys are off while it is open
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-modal', 'true');
  box.setAttribute('aria-label', `Drawing: ${title}`);
  document.body.append(box);

  let api = null;
  let start = null;
  const version = () => (api ? X.getSceneVersion(api.getSceneElementsIncludingDeleted()) : 0);
  const root = ReactDOM.createRoot(canvas);
  root.render(React.createElement(X.Excalidraw, {
    initialData: scene ? { elements: scene.elements, appState: { ...scene.appState, collaborators: new Map() }, files: scene.files, scrollToContent: true } : null,
    excalidrawAPI: (a) => { api = a; requestAnimationFrame(() => { start = version(); }); },
    theme: dark ? 'dark' : 'light',
    autoFocus: true,
    langCode: langCode(navigator.language, (X.languages ?? []).map((l) => l.code)),
    UIOptions: { canvasActions: { loadScene: false, saveToActiveFile: false, export: false } },
  }));

  return new Promise((resolve) => {
    const close = () => {
      root.unmount();
      box.remove();
      before?.focus?.({ preventScroll: true });
      resolve();
    };
    cancel.addEventListener('click', () => {
      if (version() !== start && !cancel.dataset.sure) {   // a second click, to throw changes away
        cancel.dataset.sure = '1';
        cancel.textContent = 'Discard changes';
        return;
      }
      close();
    });
    done.addEventListener('click', async () => {
      if (!api) return;
      const elements = api.getSceneElements();
      if (!scene && !elements.length) { close(); return; }      // nothing drawn: nothing to keep
      if (scene && version() === start) { close(); return; }   // nothing changed
      done.disabled = true;
      status.textContent = 'Saving…';
      try {
        const state = api.getAppState();
        const plain = /^(#fff(fff)?|white|transparent)$/i.test(state.viewBackgroundColor ?? '');   // a plain background is left out, so the page shows through
        const svg = await X.exportToSvg({ elements, appState: { ...state, exportEmbedScene: true, exportBackground: !plain, exportWithDarkMode: false }, files: api.getFiles(), exportPadding: 16 });
        await save(new File([new XMLSerializer().serializeToString(svg)], name, { type: 'image/svg+xml' }));
        close();
      } catch (e) {
        status.textContent = e.message;
        done.disabled = false;
      }
    });
    box.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); e.stopPropagation(); done.click(); }
    }, true);
  });
}
