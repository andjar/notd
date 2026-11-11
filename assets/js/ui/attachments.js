/**
 * Attachments of a note: upload, list, view, delete.
 *
 * @module ui/attachments
 */

import { attachmentsAPI } from '../api_client.js';
import * as outline from '../app/outline.js';
import { escapeHtml, renderIcons } from '../utils.js';
import { toast, confirmDialog, showImage } from './dialogs.js';
import { getNoteElement } from './note-elements.js';

function listElement(noteId, create) {
    const wrapper = getNoteElement(noteId)?.querySelector(':scope > .note-header-row .note-content-wrapper');
    if (!wrapper) return null;
    let list = wrapper.querySelector(':scope > .note-attachments');
    if (!list && create) {
        list = document.createElement('div');
        list.className = 'note-attachments';
        wrapper.appendChild(list);
    }
    return list;
}

/**
 * Uploads files to a note. The note may have been created a moment ago, so
 * queued operations are sent first.
 *
 * @returns {Promise<Array>} the attachments that were stored
 */
export async function uploadFiles(noteId, files) {
    files = Array.from(files || []);
    if (!files.length) return [];
    try {
        await outline.ensureSynced();
        const form = new FormData();
        form.append('note_id', noteId);
        for (const file of files) form.append('attachmentFile[]', file, file.name);
        const result = await attachmentsAPI.upload(form);
        const stored = result.attachments || [];
        for (const failure of result.failed || []) {
            toast(`${failure.name}: ${failure.message}`, { error: true });
        }
        if (stored.length) {
            toast(`Uploaded ${stored.length} file${stored.length > 1 ? 's' : ''}`);
            const note = outline.model.get(noteId);
            if (note) note.has_attachments = true;
            await renderAttachments(noteId);
        }
        return stored;
    } catch (error) {
        toast(`Upload failed: ${error.message}`, { error: true });
        return [];
    }
}

/** Fetches and shows the attachments of a note below its text. */
export async function renderAttachments(noteId) {
    const list = listElement(noteId, true);
    if (!list) return;
    let attachments;
    try {
        attachments = await attachmentsAPI.forNote(noteId);
    } catch {
        list.innerHTML = '<small>Could not load attachments.</small>';
        return;
    }
    if (!attachments.length) {
        list.remove();
        return;
    }
    list.innerHTML = attachments.map((a) => {
        const name = escapeHtml(a.name);
        const url = escapeHtml(a.url);
        const isImage = (a.type || '').startsWith('image/');
        const isDrawing = /\.excalidraw$/i.test(a.name);
        const date = new Date(String(a.created_at).replace(' ', 'T') + 'Z');
        const preview = isImage
            ? `<img src="${url}" alt="" class="attachment-preview-image" loading="lazy" data-attachment-url="${url}">`
            : `<i data-feather="${isDrawing ? 'pen-tool' : 'file'}" class="attachment-preview-icon"></i>`;
        const link = isImage
            ? `<a href="${url}" class="attachment-name" data-attachment-url="${url}">${name}</a>`
            : `<a href="${url}" class="attachment-name" target="_blank" rel="noopener">${name}</a>`;
        const edit = isDrawing
            ? `<button type="button" class="attachment-edit-excalidraw-btn" data-attachment-id="${a.id}"><i data-feather="edit-3"></i> Edit in Excalidraw</button>`
            : '';
        return `<div class="note-attachment-item" data-attachment-id="${a.id}">
            <div class="attachment-preview">${preview}</div>
            <div class="attachment-info">${edit}${link}
                <span class="attachment-meta">${escapeHtml(a.type)} - ${isNaN(date) ? '' : escapeHtml(date.toLocaleDateString())}</span>
            </div>
            <button type="button" class="attachment-delete-btn" data-attachment-id="${a.id}" title="Delete attachment" aria-label="Delete ${name}"><i data-feather="trash-2"></i></button>
        </div>`;
    }).join('');
    renderIcons(list);
}

/**
 * Handles a click inside a note's attachment list.
 * @returns {boolean} true if the click was for an attachment control
 */
export function handleAttachmentClick(event) {
    const list = event.target.closest('.note-attachments');
    if (!list) return false;
    const noteId = list.closest('.note-item')?.dataset.noteId;

    const del = event.target.closest('.attachment-delete-btn');
    if (del) {
        const item = del.closest('.note-attachment-item');
        const name = item?.querySelector('.attachment-name')?.textContent || 'this attachment';
        confirmDialog(`Delete "${name}"?`, { confirmLabel: 'Delete', danger: true }).then(async (yes) => {
            if (!yes) return;
            try {
                await attachmentsAPI.remove(del.dataset.attachmentId);
                item.remove();
                if (!list.querySelector('.note-attachment-item')) {
                    list.remove();
                    const note = outline.model.get(noteId);
                    if (note) note.has_attachments = false;
                }
            } catch (error) {
                toast(`Could not delete "${name}": ${error.message}`, { error: true });
            }
        });
        return true;
    }
    const edit = event.target.closest('.attachment-edit-excalidraw-btn');
    if (edit) {
        window.open(`extensions/excalidraw_editor/excalidraw.html?note_id=${encodeURIComponent(noteId)}&attachment_id=${encodeURIComponent(edit.dataset.attachmentId)}`, '_blank', 'noopener');
        return true;
    }
    const image = event.target.closest('[data-attachment-url]');
    if (image) {
        event.preventDefault();
        showImage(image.dataset.attachmentUrl);
        return true;
    }
    return false;
}
