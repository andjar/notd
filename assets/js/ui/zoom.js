/**
 * Zooming into a note: only that note and what is under it stay visible,
 * with a breadcrumb trail back out. Purely a matter of CSS classes on the
 * existing elements, so editing works the same while zoomed.
 *
 * @module ui/zoom
 */

import * as outline from '../app/outline.js';
import { page } from '../app/page-state.js';
import { getNoteElement, hooks } from './note-elements.js';

let zoomedId = null;

const container = () => document.getElementById('notes-container');
const crumbs = () => document.getElementById('note-focus-breadcrumbs-container');

export function zoomedNoteId() {
    return zoomedId;
}

function clear() {
    const c = container();
    if (!c) return;
    c.classList.remove('zoomed');
    c.style.removeProperty('--zoom-level');
    c.querySelectorAll('.zoom-path, .zoom-root').forEach((el) => el.classList.remove('zoom-path', 'zoom-root'));
}

function crumb(label, onClick) {
    const a = document.createElement('a');
    a.href = '#';
    a.textContent = label;
    a.addEventListener('click', (e) => {
        e.preventDefault();
        onClick();
    });
    return a;
}

const title = (note) => (note.content || '').split('\n')[0].slice(0, 40) || 'Untitled note';

function drawCrumbs() {
    const el = crumbs();
    if (!el) return;
    el.replaceChildren();
    if (zoomedId === null) {
        el.hidden = true;
        return;
    }
    const trail = [];
    for (let n = outline.model.get(zoomedId); n; n = n.parent_note_id === null ? null : outline.model.get(n.parent_note_id)) {
        trail.unshift(n);
    }
    el.appendChild(crumb(page.name, zoomOut));
    trail.forEach((note, index) => {
        el.appendChild(document.createTextNode(' › '));
        if (index === trail.length - 1) {
            const here = document.createElement('span');
            here.textContent = title(note);
            el.appendChild(here);
        } else {
            el.appendChild(crumb(title(note), () => zoomInto(note.id)));
        }
    });
    el.hidden = false;
}

/** Shows only the note `id` and its descendants. */
export function zoomInto(id, { scroll = true } = {}) {
    const el = getNoteElement(id);
    if (!el || !outline.model.has(id)) return;
    clear();
    zoomedId = String(id);
    el.classList.add('zoom-root');
    for (let p = el.parentElement?.closest('.note-item'); p; p = p.parentElement?.closest('.note-item')) {
        p.classList.add('zoom-path');
    }
    container().classList.add('zoomed');
    container().style.setProperty('--zoom-level', outline.model.depth(id));
    if (outline.model.get(id).collapsed) outline.setCollapsed(id, false);
    drawCrumbs();
    if (scroll) window.scrollTo({ top: 0 });
}

export function zoomOut() {
    const was = zoomedId;
    zoomedId = null;
    clear();
    drawCrumbs();
    if (was) getNoteElement(was)?.scrollIntoView({ block: 'center' });
}

/** After the outline was re-rendered or a note moved: keep the zoom if its note still exists. */
function refreshZoom() {
    if (zoomedId === null) return;
    if (outline.model.has(zoomedId) && getNoteElement(zoomedId)) zoomInto(zoomedId, { scroll: false });
    else zoomOut();
}

let refreshQueued = false;
hooks.structureChanged = () => {
    if (zoomedId === null || refreshQueued) return;
    refreshQueued = true;
    queueMicrotask(() => {
        refreshQueued = false;
        refreshZoom();
    });
};
