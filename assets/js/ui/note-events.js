/**
 * Pointer, focus, paste and file-drop handling for the whole outline. One set
 * of listeners on the container serves every note, however many there are.
 * (Keys are handled in app/note-actions.js.)
 *
 * @module ui/note-events
 */

import * as outline from '../app/outline.js';
import * as view from './note-elements.js';
import * as autocomplete from './autocomplete.js';
import { enterEditMode, leaveEditMode, readText, caretOffset, setCaret, replaceRange } from './note-view.js';
import { parseTask } from './markdown.js';
import { handleAttachmentClick, uploadFiles } from './attachments.js';
import { showImage } from './dialogs.js';
import { openNoteMenu } from './note-menu.js';
import { zoomInto } from './zoom.js';
import { handleNoteKeyDown, noteInput, noteFocused } from '../app/note-actions.js';

// A click on any of these inside a rendered note does its own thing instead of starting an edit.
const INTERACTIVE = 'a, button, input, summary, img, video, audio, .embedded-note-tree, .sql-query-results, .transclusion-placeholder, .sql-query-placeholder';

const noteIdOf = (el) => el.closest('.note-item')?.dataset.noteId ?? null;

/** Where in the note's text a click landed, when the rendered text is the raw text. */
function clickOffset(content, event) {
    const raw = content.dataset.rawContent || '';
    if (!raw || content.textContent !== raw) return null;
    let node;
    let offset;
    if (document.caretPositionFromPoint) {
        const position = document.caretPositionFromPoint(event.clientX, event.clientY);
        if (!position) return null;
        node = position.offsetNode;
        offset = position.offset;
    } else if (document.caretRangeFromPoint) {
        const range = document.caretRangeFromPoint(event.clientX, event.clientY);
        if (!range) return null;
        node = range.startContainer;
        offset = range.startOffset;
    } else {
        return null;
    }
    if (!content.contains(node)) return null;
    const range = document.createRange();
    range.selectNodeContents(content);
    try {
        range.setEnd(node, offset);
    } catch {
        return null;
    }
    return range.toString().length;
}

function startEditing(content, event) {
    const offset = clickOffset(content, event);
    enterEditMode(content);
    content.focus({ preventScroll: true });
    setCaret(content, offset ?? (content.textContent || '').length);
}

function toggleTask(checkbox) {
    const id = noteIdOf(checkbox);
    const note = id && outline.model.get(id);
    const task = note && parseTask(note.content);
    if (!task) return;
    const next = task.keyword === 'DONE' ? 'TODO' : 'DONE';
    outline.editNote(id, `${next} ${task.text}`, { undoable: true });
    outline.outbox.flushSoon();
}

function onClick(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const arrow = target.closest('.note-collapse-arrow');
    if (arrow) {
        const id = noteIdOf(arrow);
        const note = id && outline.model.get(id);
        if (note) outline.setCollapsed(id, !note.collapsed);
        return;
    }
    const bullet = target.closest('.note-bullet');
    if (bullet) {
        const id = noteIdOf(bullet);
        if (id) zoomInto(id);
        return;
    }
    // Embedded notes (transclusions, query results) are read-only copies.
    if (target.closest('.embedded-note-tree, .sql-query-results')) {
        const image = target.closest('img.content-image');
        if (image) {
            event.preventDefault();
            showImage(image.currentSrc || image.src);
        }
        return;
    }
    const checkbox = target.closest('.task-checkbox');
    if (checkbox) {
        toggleTask(checkbox);
        return;
    }
    if (handleAttachmentClick(event)) return;

    const image = target.closest('img.content-image');
    if (image) {
        event.preventDefault();
        showImage(image.currentSrc || image.src);
        return;
    }
    const content = target.closest('.note-content.rendered-mode');
    if (!content || target.closest(INTERACTIVE)) return;
    // Dragging to select text for copying is not a request to edit.
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed && content.contains(selection.anchorNode)) return;
    startEditing(content, event);
}

function onContextMenu(event) {
    const bullet = event.target.closest?.('.note-bullet');
    if (!bullet) return;
    event.preventDefault();
    const id = noteIdOf(bullet);
    if (id) openNoteMenu(id, event.clientX, event.clientY);
}

function onFocusIn(event) {
    const content = event.target.closest?.('.note-content.edit-mode');
    if (content) noteFocused(content);
}

function onFocusOut(event) {
    const content = event.target.closest?.('.note-content.edit-mode');
    if (!content || view.isBlurSuppressed()) return;
    autocomplete.hide();
    const id = content.dataset.noteId;
    const text = leaveEditMode(content);
    if (outline.model.has(id)) {
        outline.editNote(id, text);
        outline.outbox.flushSoon();
    }
}

async function onPaste(event) {
    const content = event.target.closest?.('.note-content.edit-mode');
    if (!content) return;
    const files = Array.from(event.clipboardData?.files || []);
    if (files.length) {
        event.preventDefault();
        const id = content.dataset.noteId;
        // Screenshots all arrive as "image.png": give them distinct names.
        const named = files.map((f) => (/^image\.\w+$/i.test(f.name)
            ? new File([f], `pasted-${Date.now()}.${f.name.split('.').pop()}`, { type: f.type })
            : f));
        const stored = await uploadFiles(id, named);
        const markdown = stored.filter((a) => (a.type || '').startsWith('image/')).map((a) => `![${a.name}](${a.url})`).join('\n');
        if (!markdown || !outline.model.has(id)) return;
        if (content.isConnected && content.classList.contains('edit-mode') && document.activeElement === content) {
            const at = caretOffset(content) ?? readText(content).length;
            replaceRange(content, at, at, markdown);
        } else {
            const current = outline.model.get(id).content || '';
            outline.editNote(id, current ? `${current}\n${markdown}` : markdown, { undoable: true });
        }
        return;
    }
    if (content.contentEditable !== 'plaintext-only') {
        // Older browsers would paste formatted HTML into the note.
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
    }
}

const hasFiles = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');

function onDragOver(event) {
    if (!hasFiles(event)) return;
    const wrapper = event.target.closest?.('.note-content-wrapper');
    if (!wrapper) return;
    event.preventDefault();
    wrapper.classList.add('dragover');
}

function onDragLeave(event) {
    event.target.closest?.('.note-content-wrapper')?.classList.remove('dragover');
}

function onDrop(event) {
    if (!hasFiles(event)) return;
    const wrapper = event.target.closest?.('.note-content-wrapper');
    if (!wrapper) return;
    event.preventDefault();
    wrapper.classList.remove('dragover');
    const id = noteIdOf(wrapper);
    if (id) uploadFiles(id, event.dataTransfer.files);
}

/** Wires the outline container. Call once. */
export function initNoteEvents(container = document.getElementById('notes-container')) {
    if (!container) return;
    container.addEventListener('click', onClick);
    container.addEventListener('contextmenu', onContextMenu);
    container.addEventListener('focusin', onFocusIn);
    container.addEventListener('focusout', onFocusOut);
    container.addEventListener('keydown', handleNoteKeyDown);
    container.addEventListener('input', (event) => {
        const content = event.target.closest?.('.note-content.edit-mode');
        if (!content) return;
        noteInput(content);
        autocomplete.update(content);
    });
    container.addEventListener('paste', onPaste);
    container.addEventListener('dragover', onDragOver);
    container.addEventListener('dragleave', onDragLeave);
    container.addEventListener('drop', onDrop);
    // Drop targets are prepared lazily on big pages; a drag needs them all.
    container.addEventListener('pointerdown', (event) => {
        if (event.target.closest?.('.note-bullet')) view.ensureDragReady();
    });
}
