/**
 * @file Manages note-related actions such as creation, updates, deletion,
 * indentation, and interaction handling (keyboard, clicks). It orchestrates
 * optimistic UI updates and communication with the backend API using batch operations.
 */

import {
    notesForCurrentPage,
    currentPageId,
    addNoteToCurrentPage,
    updateNoteInCurrentPage,
    removeNoteFromCurrentPageById,
    setNotesForCurrentPage,
} from './state.js';

import { calculateOrderIndex } from './order-index-service.js';
import { notesAPI } from '../api_client.js';
import { debounce, handleAutocloseBrackets, handleShortcutExpansion } from '../utils.js';
import { ui } from '../ui.js';

const notesContainer = document.querySelector('#notes-container');

// --- Note Data Accessors ---
export function getNoteDataById(noteId) {
    if (!noteId) return null;
    return notesForCurrentPage.find(n => String(n.id) === String(noteId));
}

export function getNoteElementById(noteId) {
    if (!notesContainer || !noteId) return null;
    return notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
}

function _finalizeNewNote(clientTempId, noteFromServer) {
    if (!noteFromServer || !noteFromServer.id) {
        console.error("[_finalizeNewNote] Invalid note data from server for temp ID:", clientTempId, noteFromServer);
        return;
    }
    const permanentId = noteFromServer.id;

    const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(clientTempId));
    if (noteIndex > -1) {
        notesForCurrentPage[noteIndex] = { ...notesForCurrentPage[noteIndex], ...noteFromServer, id: permanentId };
    } else {
        console.warn(`[_finalizeNewNote] Could not find note with temp ID ${clientTempId} in local state to finalize.`);
    }

    const tempNoteEl = getNoteElementById(clientTempId);
    if (tempNoteEl) {
        tempNoteEl.dataset.noteId = permanentId;
        tempNoteEl.querySelector('.note-content')?.setAttribute('data-note-id', permanentId);
        tempNoteEl.querySelector('.note-bullet')?.setAttribute('data-note-id', permanentId);
    }
}

async function executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, userActionName) {
    if (!operations || operations.length === 0) return true;

    ui.updateSaveStatusIndicator('pending');
    let success = false;

    try {
        if (typeof optimisticDOMUpdater === 'function') optimisticDOMUpdater();
        const batchResponse = await notesAPI.batchUpdateNotes(operations);
        let allSubOperationsSucceeded = true;
        
        // **FIXED**: The API client now returns the object containing the results array.
        // We must iterate over `batchResponse.results`.
        if (batchResponse && Array.isArray(batchResponse.results)) {
            batchResponse.results.forEach(opResult => {
                if (opResult.status === 'error') {
                    allSubOperationsSucceeded = false;
                    console.error(`[${userActionName} BATCH] Server reported sub-operation error:`, opResult);
                } else if (opResult.type === 'create' && opResult.client_temp_id) {
                    _finalizeNewNote(opResult.client_temp_id, opResult.note);
                } else if (opResult.type === 'update' && opResult.note) {
                    updateNoteInCurrentPage(opResult.note);
                }
            });
        } else {
            allSubOperationsSucceeded = false;
            console.error(`[${userActionName} BATCH] Invalid response structure from server:`, batchResponse);
        }

        if (!allSubOperationsSucceeded) throw new Error(`One or more sub-operations in '${userActionName}' failed.`);
        success = true;
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        alert(`${error.message || `Batch operation '${userActionName}' failed.`} Reverting local changes.`);
        ui.updateSaveStatusIndicator('error');
        setNotesForCurrentPage(originalNotesState);
        ui.displayNotes(notesForCurrentPage, currentPageId);
        success = false;
    }
    return success;
}

// --- Note Saving Logic ---

async function _saveNoteToServer(noteId, rawContent) {
    if (String(noteId).startsWith('temp-')) return null;
    
    // Prepare the content for saving, including encryption if applicable
    let contentToSave = rawContent;
    if (window.currentPageEncryptionKey && window.decryptionPassword) {
        contentToSave = sjcl.encrypt(window.decryptionPassword, rawContent);
    }

    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));

    const operations = [{
        type: 'update',
        payload: {
            id: noteId,
            page_id: currentPageId, // Ensure page_id is sent for context if needed by backend
            content: contentToSave
        }
    }];

    // Using executeBatchOperations for consistency and error handling
    const success = await executeBatchOperations(originalNotesState, operations, () => {
        // Optimistic DOM update for content is handled elsewhere (e.g., when typing stops)
        // This save operation only ensures the data is sent to the server.
        // updateNoteInCurrentPage is called by executeBatchOperations on success via opResult.note
    }, "Save Note Content");

    return success ? getNoteDataById(noteId) : null;
}

export async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    if (String(noteId).startsWith('temp-')) return null;
    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return null;
    
    const rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
    contentDiv.dataset.rawContent = rawContent;
    return await _saveNoteToServer(noteId, rawContent);
}

export const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    if (String(noteId).startsWith('temp-')) return;
    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return;
    
    const currentRawContent = contentDiv.dataset.rawContent || '';
    await _saveNoteToServer(noteId, currentRawContent);
}, 1000);

// --- Event Handlers for Structural Changes ---

export async function handleAddRootNote() {
    if (!currentPageId) return;
    const clientTempId = `temp-R-${Date.now()}`;
    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));
    const rootNotes = notesForCurrentPage.filter(n => !n.parent_note_id);
    const lastRootNote = rootNotes.sort((a, b) => (a.order_index || 0) - (b.order_index || 0)).pop();
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, null, lastRootNote?.id || null, null);
    
    const optimisticNewNote = { id: clientTempId, page_id: currentPageId, content: '', parent_note_id: null, order_index: targetOrderIndex, properties: {} };
    addNoteToCurrentPage(optimisticNewNote);
    
    const operations = [{ type: 'create', payload: { page_id: currentPageId, content: '', parent_note_id: null, order_index: targetOrderIndex, client_temp_id: clientTempId } }];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    
    // Optimistically update local state for siblings
    siblingUpdates.forEach(upd => {
        const note = getNoteDataById(upd.id);
        if(note) note.order_index = upd.newOrderIndex;
    });

    const optimisticDOMUpdater = () => {
        const noteEl = ui.addNoteElement(optimisticNewNote);
        const contentDiv = noteEl?.querySelector('.note-content');
        if (contentDiv) {
            contentDiv.dataset.rawContent = '';
            ui.switchToEditMode(contentDiv);
        }
    };
    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Add Root Note");
}

async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (e.shiftKey) return;
    e.preventDefault();

    const clientTempId = `temp-E-${Date.now()}`;
    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));

    const siblings = notesForCurrentPage.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? '')).sort((a, b) => a.order_index - b.order_index);
    const currentNoteIndexInSiblings = siblings.findIndex(n => String(n.id) === String(noteData.id));
    const nextSibling = siblings[currentNoteIndexInSiblings + 1];

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, noteData.parent_note_id, String(noteData.id), nextSibling?.id || null);
    
    const optimisticNewNote = { id: clientTempId, page_id: currentPageId, content: '', parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex, properties: {} };
    addNoteToCurrentPage(optimisticNewNote);

    const operations = [{ type: 'create', payload: { page_id: currentPageId, content: '', parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex, client_temp_id: clientTempId } }];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    
    // Optimistically update local state for siblings
    siblingUpdates.forEach(op => {
        const note = getNoteDataById(op.id);
        if (note) note.order_index = op.newOrderIndex;
    });

    const optimisticDOMUpdater = () => {
        const newNoteEl = ui.renderNote(optimisticNewNote, ui.getNestingLevel(noteItem));
        noteItem.after(newNoteEl);
        const newContentDiv = newNoteEl?.querySelector('.note-content');
        if (newContentDiv) {
            newContentDiv.dataset.rawContent = '';
            ui.switchToEditMode(newContentDiv);
        }
    };
    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Create Sibling Note");
}

async function handleTabKey(e, noteItem, noteData, contentDiv) {
    e.preventDefault();
    await saveNoteImmediately(noteItem); // Ensure content is saved before structural change

    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));
    let operations = [];
    let newParentId = null;

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const oldParentNote = getNoteDataById(noteData.parent_note_id);
        if (!oldParentNote) return;
        newParentId = oldParentNote.parent_note_id;

        const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, newParentId, String(oldParentNote.id), null);
        
        operations.push({ type: 'update', payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));

    } else { // Indent
        const siblings = notesForCurrentPage.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? '')).sort((a,b) => a.order_index - b.order_index);
        const currentNoteIndexInSiblings = siblings.findIndex(n => String(n.id) === String(noteData.id));
        if (currentNoteIndexInSiblings < 1) return;
        
        const newParentNote = siblings[currentNoteIndexInSiblings - 1];
        newParentId = String(newParentNote.id);
        const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, newParentId, null, null);

        operations.push({ type: 'update', payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    }
    
    // **FIXED**: Perform optimistic state updates before calling the batch operation
    const noteToMove = getNoteDataById(noteData.id);
    if(noteToMove) noteToMove.parent_note_id = newParentId;
    operations.forEach(op => {
        if (op.type === 'update') {
            const note = getNoteDataById(op.payload.id);
            if(note) note.order_index = op.payload.order_index;
        }
    });

    const optimisticDOMUpdater = () => {
        // Since this is a structural change, a full re-render is the safest approach.
        ui.displayNotes(notesForCurrentPage, currentPageId);
        const newNoteItem = getNoteElementById(noteData.id);
        if (newNoteItem) {
            const newContentDiv = newNoteItem.querySelector('.note-content');
            if (newContentDiv) ui.switchToEditMode(newContentDiv);
        }
    };

    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, e.shiftKey ? "Outdent Note" : "Indent Note");
}

async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if ((contentDiv.dataset.rawContent || contentDiv.textContent).trim() !== '') return;
    const children = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.id));
    if (children.length > 0) return;

    e.preventDefault();
    let focusTargetEl = noteItem.previousElementSibling || getNoteElementById(noteData.parent_note_id);
    
    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));
    const noteIdToDelete = noteData.id;

    removeNoteFromCurrentPageById(noteIdToDelete);
    const operations = [{ type: 'delete', payload: { id: noteIdToDelete } }];
    
    const optimisticDOMUpdater = () => {
        ui.removeNoteElement(noteIdToDelete);
        if (focusTargetEl) {
            const contentToFocus = focusTargetEl.querySelector('.note-content');
            if(contentToFocus) ui.switchToEditMode(contentToFocus);
        }
    };
    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Delete Note (Backspace)");
}

function handleArrowKey(e, contentDiv) {
    e.preventDefault();
    const allVisibleNotesContent = Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden) .note-content'));
    const currentIndex = allVisibleNotesContent.indexOf(contentDiv);
    let nextIndex = -1;

    if (e.key === 'ArrowUp' && currentIndex > 0) nextIndex = currentIndex - 1;
    else if (e.key === 'ArrowDown' && currentIndex < allVisibleNotesContent.length - 1) nextIndex = currentIndex + 1;

    if (nextIndex !== -1) {
        const nextContent = allVisibleNotesContent[nextIndex];
        ui.switchToEditMode(nextContent);
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(nextContent);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

export async function handleNoteKeyDown(e) {
    if (!e.target.matches('.note-content')) return;
    const noteItem = e.target.closest('.note-item');
    if (!noteItem) return;

    const noteId = noteItem.dataset.noteId;
    const contentDiv = e.target;
    const noteData = getNoteDataById(noteId);

    // Handle shortcuts and auto-close brackets first if in edit mode
    if (contentDiv.classList.contains('edit-mode')) {
        if (await handleShortcutExpansion(e, contentDiv)) return;
        if (handleAutocloseBrackets(e)) return;
    }

    // --- Pre-action checks for structural changes ---
    const structuralKeys = ['Enter', 'Tab', 'Backspace'];
    if (structuralKeys.includes(e.key) && !e.shiftKey) { // Shift+Key usually has different meaning
        if (String(noteId).startsWith('temp-')) {
            console.warn(`Structural action (${e.key}) blocked on temp note ID: ${noteId}`);
            e.preventDefault(); return;
        }
        if (!noteData) {
            console.warn(`Note data not found for ID: ${noteId}. Key: ${e.key}. Blocking structural change.`);
            e.preventDefault(); return;
        }
    }
    // Allow Enter on rendered mode to switch to edit mode (handled in handleEnterKey)
    // Allow arrow keys even if noteData is somehow missing (for navigation)

    switch (e.key) {
        case 'Enter':     await handleEnterKey(e, noteItem, noteData, contentDiv); break;
        case 'Tab':       await handleTabKey(e, noteItem, noteData, contentDiv); break;
        case 'Backspace': await handleBackspaceKey(e, noteItem, noteData, contentDiv); break;
        case 'ArrowUp':
        case 'ArrowDown':
            if (contentDiv.classList.contains('edit-mode')) { // Only navigate if in edit mode
                 handleArrowKey(e, contentDiv);
            }
            break;
        // Default: allow native behavior for other keys (typing, etc.)
    }
}

export async function handleTaskCheckboxClick(e) {
    const checkbox = e.target;
    const noteItem = checkbox.closest('.note-item');
    if (!noteItem) return;
    
    const noteId = noteItem.dataset.noteId;
    const noteData = getNoteDataById(noteId);
    if (!noteData || String(noteId).startsWith('temp-')) {
        checkbox.checked = !checkbox.checked;
        return;
    }
    
    let currentRawContent = noteData.content;
    let newRawContent = currentRawContent;
    const isChecked = checkbox.checked;
    
    const taskMarkers = ["TODO ", "DOING ", "SOMEDAY ", "DONE ", "WAITING ", "CANCELLED ", "NLR "];
    const currentPrefix = taskMarkers.find(p => currentRawContent.toUpperCase().startsWith(p));
    
    if (currentPrefix) {
        const contentWithoutPrefix = currentRawContent.substring(currentPrefix.length);
        newRawContent = (isChecked ? 'DONE ' : 'TODO ') + contentWithoutPrefix;
    } else {
        newRawContent = (isChecked ? 'DONE ' : 'TODO ') + currentRawContent;
    }

    const contentDiv = noteItem.querySelector('.note-content');
    if (contentDiv) {
        contentDiv.dataset.rawContent = newRawContent;
        contentDiv.innerHTML = ui.parseAndRenderContent(newRawContent);
    }
    noteData.content = newRawContent;
    
    try {
        await _saveNoteToServer(noteId, newRawContent);
    } catch (error) {
        checkbox.checked = !checkbox.checked;
        noteData.content = currentRawContent;
        if (contentDiv) {
            contentDiv.dataset.rawContent = currentRawContent;
            contentDiv.innerHTML = ui.parseAndRenderContent(currentRawContent);
        }
    }
}
