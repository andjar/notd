/**
 * The menu opened by right-clicking a note's bullet.
 *
 * @module ui/note-menu
 */

import * as outline from '../app/outline.js';
import { page, config } from '../app/page-state.js';
import { pageUrl, renderIcons } from '../utils.js';
import { toast, confirmDialog } from './dialogs.js';
import { uploadFiles } from './attachments.js';
import { zoomInto } from './zoom.js';

/** Editors that open one note in a new tab; shown when the extension folder is active. */
const NOTE_EDITORS = [
    { extension: 'zen', label: 'Open in Zen Writer', icon: 'edit-3', path: 'extensions/zen/index.html' },
    { extension: 'excalidraw_editor', label: 'Open in Excalidraw', icon: 'pen-tool', path: 'extensions/excalidraw_editor/excalidraw.html' },
    { extension: 'math_notepad', label: 'Open in Math Notepad', icon: 'hash', path: 'extensions/math_notepad/index.html' },
];

let menu = null;

function close() {
    if (!menu) return;
    menu.remove();
    menu = null;
    document.removeEventListener('mousedown', onOutside, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('scroll', close, true);
    window.removeEventListener('blur', close);
}

function onOutside(e) {
    if (menu && !menu.contains(e.target)) close();
}

function onKey(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        close();
    }
}

async function copy(text, message) {
    try {
        await navigator.clipboard.writeText(text);
        toast(message);
    } catch {
        toast('Could not copy to the clipboard', { error: true });
    }
}

function actionsFor(id) {
    const actions = [
        { action: 'zoom', label: 'Zoom in', icon: 'maximize-2', run: () => zoomInto(id) },
        { action: 'add-child', label: 'Add child note', icon: 'corner-down-right', run: () => outline.createChild(id, { focus: 'start' }) },
        { action: 'copy-link', label: 'Copy link to note', icon: 'anchor', run: () => copy(new URL(pageUrl(page.name, id), document.baseURI).href, 'Link copied') },
        { action: 'copy-embed', label: 'Copy embed code', icon: 'link', run: () => copy(`!{{${id}}}`, 'Embed code copied') },
        { action: 'upload', label: 'Upload attachment', icon: 'upload', run: () => pickFiles(id) },
    ];
    for (const editor of NOTE_EDITORS) {
        if (!config.activeExtensions.includes(editor.extension)) continue;
        actions.push({
            action: editor.extension,
            label: editor.label,
            icon: editor.icon,
            run: async () => {
                const tab = window.open('about:blank', '_blank');
                await outline.settle(); // the editor reads the note from the server
                const url = `${editor.path}?note_id=${encodeURIComponent(id)}`;
                if (tab) tab.location.href = new URL(url, document.baseURI).href;
                else window.location.href = url;
            },
        });
    }
    actions.push({ action: 'delete', label: 'Delete', icon: 'trash-2', danger: true, run: () => deleteWithConfirmation(id) });
    return actions;
}

function pickFiles(id) {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.addEventListener('change', () => uploadFiles(id, input.files));
    input.click();
}

/** Deletes a note; asks first when it would take nested notes with it. */
export async function deleteWithConfirmation(id) {
    const nested = outline.model.subtreeIds(id).length - 1;
    if (nested > 0) {
        const ok = await confirmDialog(`Delete this note and its ${nested} nested note${nested > 1 ? 's' : ''}?`, { confirmLabel: 'Delete', danger: true });
        if (!ok) return;
    }
    if (outline.deleteNote(id)) toast('Note deleted. Ctrl+Z brings it back.');
}

/** Opens the menu for note `id` at the pointer position. */
export function openNoteMenu(id, x, y) {
    close();
    if (!outline.model.has(id)) return;
    menu = document.createElement('div');
    menu.className = 'bullet-context-menu';
    menu.setAttribute('role', 'menu');
    for (const action of actionsFor(String(id))) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'menu-item' + (action.danger ? ' menu-item-danger' : '');
        item.setAttribute('role', 'menuitem');
        item.dataset.action = action.action;
        const icon = document.createElement('i');
        icon.dataset.feather = action.icon;
        item.append(icon, document.createTextNode(' ' + action.label));
        item.addEventListener('click', () => {
            close();
            action.run();
        });
        menu.appendChild(item);
    }
    menu.style.position = 'fixed';
    document.body.appendChild(menu);
    renderIcons(menu);
    menu.style.left = `${Math.max(4, Math.min(x, window.innerWidth - menu.offsetWidth - 4))}px`;
    menu.style.top = `${Math.max(4, Math.min(y, window.innerHeight - menu.offsetHeight - 4))}px`;
    document.addEventListener('mousedown', onOutside, true);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('scroll', close, true);
    window.addEventListener('blur', close);
    menu.querySelector('button')?.focus({ preventScroll: true });
}
