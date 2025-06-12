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
import { debounce } from '../utils.js';
import { ui } from '../ui.js';

const notesContainer = document.querySelector('#notes-container');

// --- Note Data Accessors ---
/**
 * Retrieves note data from the current page's state by its ID.
 * @param {string|number} noteId - The ID of the note to retrieve.
 * @returns {Object|null} The note object if found, otherwise null.
 */
export function getNoteDataById(noteId) {
    if (!noteId) return null;
    return notesForCurrentPage.find(n => String(n.id) === String(noteId));
}

/**
 * Retrieves the DOM element for a note by its ID.
 * @param {string|number} noteId - The ID of the note element to retrieve.
 * @returns {HTMLElement|null} The note's DOM element if found, otherwise null.
 */
export function getNoteElementById(noteId) {
    if (!notesContainer || !noteId) return null;
    return notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
}

/**
 * Updates a newly created note (optimistically added with a temp ID)
 * with its permanent ID and server data, in both local state and DOM.
 * @param {string} clientTempId - The temporary ID used by the client.
 * @param {Object} noteFromServer - The note object received from the server.
 */
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

/**
 * Executes a batch of note operations with optimistic updates and revert logic.
 * @param {Array<Object>} originalNotesState - A deep clone of the state *before* optimistic changes.
 * @param {Array<Object>} operations - Array of operations for the batch API.
 * @param {Function} optimisticDOMUpdater - A function to perform optimistic DOM updates.
 * @param {string} userActionName - Name of the action for logging.
 * @returns {Promise<boolean>} True if all operations were successful, false otherwise.
 */
async function executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, userActionName) {
    if (!operations || operations.length === 0) {
        return true;
    }

    ui.updateSaveStatusIndicator('pending');
    let success = false;

    try {
        if (typeof optimisticDOMUpdater === 'function') {
            optimisticDOMUpdater();
        }

        const batchResponse = await notesAPI.batchUpdateNotes(operations);
        const batchResultsArray = batchResponse?.results;

        if (!Array.isArray(batchResultsArray)) {
            throw new Error(`Batch update notes did not return a valid results array.`);
        }

        let allSubOperationsSucceeded = true;
        batchResultsArray.forEach(opResult => {
            if (opResult.status === 'error') {
                allSubOperationsSucceeded = false;
                console.error(`[${userActionName} BATCH] Server reported sub-operation error:`, opResult);
            } else if (opResult.type === 'create' && opResult.client_temp_id) {
                _finalizeNewNote(opResult.client_temp_id, opResult.note);
            } else if (opResult.type === 'update' && opResult.note) {
                updateNoteInCurrentPage(opResult.note);
            }
        });

        if (!allSubOperationsSucceeded) {
            throw new Error(`One or more sub-operations in '${userActionName}' failed on the server.`);
        }

        success = true;
        ui.updateSaveStatusIndicator('saved');

    } catch (error) {
        const errorMessage = error.message || `Batch operation '${userActionName}' failed.`;
        console.error(`[${userActionName} BATCH] Error: ${errorMessage}`, error.stack);
        alert(`${errorMessage} Reverting local changes.`);
        ui.updateSaveStatusIndicator('error');

        setNotesForCurrentPage(originalNotesState);
        ui.displayNotes(notesForCurrentPage, currentPageId);
        success = false;
    }
    return success;
}


// --- Note Saving Logic (Single Note - for content edits, tasks) ---
/**
 * Saves a single note's content to the server.
 * @param {string} noteId - The ID of the note. Must be a permanent ID.
 * @param {string} rawContent - The raw string content of the note.
 * @returns {Promise<Object|null>} The updated note object from the server, or null if save failed.
 */
async function _saveNoteToServer(noteId, rawContent) {
    if (String(noteId).startsWith('temp-')) {
        return null;
    }
    ui.updateSaveStatusIndicator('pending');
    try {
        let contentToSave = rawContent;
        if (window.currentPageEncryptionKey && window.decryptionPassword) {
            contentToSave = sjcl.encrypt(window.decryptionPassword, rawContent);
        }
        
        const updatedNote = await notesAPI.updateNote(noteId, {
            page_id: currentPageId,
            content: contentToSave,
        });
        
        updateNoteInCurrentPage(updatedNote); 
        ui.updateSaveStatusIndicator('saved');
        return updatedNote;
    } catch (error) {
        console.error(`_saveNoteToServer: Error updating note ${noteId}. Error:`, error);
        ui.updateSaveStatusIndicator('error');
        return null;
    }
}

/**
 * Immediately saves the content of a note element.
 * @param {HTMLElement} noteEl - The note's DOM element.
 * @returns {Promise<Object|null>} The updated note object from server or null.
 */
export async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    const contentDiv = noteEl.querySelector('.note-content');
    if (String(noteId).startsWith('temp-') || !contentDiv) return null;
    
    const rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
    contentDiv.dataset.rawContent = rawContent;
    return await _saveNoteToServer(noteId, rawContent);
}

/**
 * Debounced function to save a note's content.
 * @param {HTMLElement} noteEl - The note's DOM element.
 */
export const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    const contentDiv = noteEl.querySelector('.note-content');
    if (String(noteId).startsWith('temp-') || !contentDiv) return;
    
    const currentRawContent = contentDiv.dataset.rawContent || '';
    const noteData = getNoteDataById(noteId);

    if (noteData && noteData.content === currentRawContent) {
        if (saveStatus !== 'saved') ui.updateSaveStatusIndicator('saved');
        return;
    }
    await _saveNoteToServer(noteId, currentRawContent);
}, 1000);


// --- Event Handlers for Structural Changes (Using Batch API) ---

/**
 * Handles adding a new root-level note to the current page.
 */
export async function handleAddRootNote() {
    if (!currentPageId) {
        alert('Please select or create a page first.');
        return;
    }

    const clientTempId = `temp-R-${Date.now()}`;
    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));

    const rootNotes = notesForCurrentPage.filter(n => !n.parent_note_id);
    const lastRootNote = rootNotes.sort((a,b) => (a.order_index || 0) - (b.order_index || 0)).pop();
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, null, lastRootNote?.id || null, null);

    const optimisticNewNote = { id: clientTempId, page_id: currentPageId, content: '', parent_note_id: null, order_index: targetOrderIndex, properties: {} };
    addNoteToCurrentPage(optimisticNewNote);

    const operations = [{
        type: 'create',
        payload: { page_id: currentPageId, content: '', parent_note_id: null, order_index: targetOrderIndex, client_temp_id: clientTempId }
    }];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, page_id: currentPageId, order_index: upd.newOrderIndex }}));
    
    operations.forEach(op => {
        if (op.type === 'update') {
            const note = getNoteDataById(op.payload.id);
            if(note) note.order_index = op.payload.order_index;
        }
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

/**
 * Handles the Enter key press in a note, creating a new sibling note.
 */
async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (e.shiftKey) return;
    e.preventDefault();

    const clientTempId = `temp-E-${Date.now()}`;
    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));

    const siblings = notesForCurrentPage.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? ''));
    const currentNoteIndexInSiblings = siblings.sort((a,b) => a.order_index - b.order_index).findIndex(n => String(n.id) === String(noteData.id));
    const nextSibling = siblings[currentNoteIndexInSiblings + 1];

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, noteData.parent_note_id, String(noteData.id), nextSibling?.id || null);
    
    const optimisticNewNote = { id: clientTempId, page_id: currentPageId, content: '', parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex, properties: {} };
    addNoteToCurrentPage(optimisticNewNote);

    const operations = [{
        type: 'create',
        payload: { page_id: currentPageId, content: '', parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex, client_temp_id: clientTempId }
    }];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, page_id: currentPageId, order_index: upd.newOrderIndex }}));
    
    operations.forEach(op => {
        if (op.type === 'update') {
            const note = getNoteDataById(op.payload.id);
            if (note) note.order_index = op.payload.order_index;
        }
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

/**
 * Handles Tab (indent) and Shift+Tab (outdent) key presses in a note.
 */
async function handleTabKey(e, noteItem, noteData) {
    e.preventDefault();
    await saveNoteImmediately(noteItem);

    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));
    let operations = [];

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const oldParentNote = getNoteDataById(noteData.parent_note_id);
        if (!oldParentNote) return;

        const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, oldParentNote.parent_note_id, String(oldParentNote.id), null);
        
        noteData.parent_note_id = oldParentNote.parent_note_id;
        noteData.order_index = targetOrderIndex;
        operations.push({ type: 'update', payload: { id: noteData.id, page_id: currentPageId, parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, page_id: currentPageId, order_index: upd.newOrderIndex }}));

    } else { // Indent
        const siblings = notesForCurrentPage.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? ''));
        const currentNoteIndexInSiblings = siblings.sort((a,b) => a.order_index - b.order_index).findIndex(n => String(n.id) === String(noteData.id));
        if (currentNoteIndexInSiblings < 1) return;
        
        const newParentNote = siblings[currentNoteIndexInSiblings - 1];
        const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, String(newParentNote.id), null, null);

        noteData.parent_note_id = String(newParentNote.id);
        noteData.order_index = targetOrderIndex;
        operations.push({ type: 'update', payload: { id: noteData.id, page_id: currentPageId, parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, page_id: currentPageId, order_index: upd.newOrderIndex }}));
    }

    const optimisticDOMUpdater = () => {
        ui.displayNotes(notesForCurrentPage, currentPageId);
        const newNoteItem = getNoteElementById(noteData.id);
        if (newNoteItem) {
            const contentDiv = newNoteItem.querySelector('.note-content');
            if (contentDiv) ui.switchToEditMode(contentDiv);
        }
    };

    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, e.shiftKey ? "Outdent Note" : "Indent Note");
}

/**
 * Handles Backspace key press in an empty note, deleting it.
 */
async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if ((contentDiv.dataset.rawContent || contentDiv.textContent).trim() !== '') return;
    const children = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.id));
    if (children.length > 0) return;

    e.preventDefault();

    let focusTargetEl = noteItem.previousElementSibling || noteItem.nextElementSibling || getNoteElementById(noteData.parent_note_id);

    const originalNotesState = JSON.parse(JSON.stringify(notesForCurrentPage));
    const noteIdToDelete = noteData.id;

    removeNoteFromCurrentPageById(noteIdToDelete);
    const operations = [{ type: 'delete', payload: { id: noteIdToDelete } }];

    // Re-index siblings
    const siblings = notesForCurrentPage.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? ''));
    siblings.sort((a,b) => a.order_index - b.order_index).forEach((sib, index) => {
        if (sib.order_index !== index) {
            sib.order_index = index;
            operations.push({ type: 'update', payload: { id: sib.id, page_id: currentPageId, order_index: index }});
        }
    });

    const optimisticDOMUpdater = () => {
        ui.removeNoteElement(noteIdToDelete);
        if (focusTargetEl) {
            const contentToFocus = focusTargetEl.querySelector('.note-content');
            if(contentToFocus) ui.switchToEditMode(contentToFocus);
        }
    };

    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Delete Note (Backspace)");
}

/**
 * Handles Arrow Up/Down key presses for navigating between notes.
 */
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
        range.collapse(false); // to end
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

/**
 * Master keydown handler for notes. Delegates to specific handlers based on key.
 */
export async function handleNoteKeyDown(e) {
    if (!e.target.matches('.note-content')) return;
    const noteItem = e.target.closest('.note-item');
    if (!noteItem) return;

    const noteId = noteItem.dataset.noteId;
    const contentDiv = e.target;
    const noteData = getNoteDataById(noteId);
    if (!noteData || String(noteId).startsWith('temp-')) {
        // Allow typing in temp notes, but block structural changes
        if (['Enter', 'Tab', 'Backspace'].includes(e.key)) e.preventDefault();
        return;
    }

    switch (e.key) {
        case 'Enter':     await handleEnterKey(e, noteItem, noteData, contentDiv); break;
        case 'Tab':       await handleTabKey(e, noteItem, noteData, contentDiv); break;
        case 'Backspace': await handleBackspaceKey(e, noteItem, noteData, contentDiv); break;
        case 'ArrowUp':
        case 'ArrowDown':
            if (contentDiv.classList.contains('edit-mode')) handleArrowKey(e, contentDiv);
            break;
    }
}

/**
 * Handles clicks on task checkboxes, updating note content.
 */
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
    } else { // Not a task, make it one
        newRawContent = (isChecked ? 'DONE ' : 'TODO ') + currentRawContent;
    }

    // Optimistic UI update
    const contentDiv = noteItem.querySelector('.note-content');
    if (contentDiv) {
        contentDiv.dataset.rawContent = newRawContent;
        contentDiv.innerHTML = ui.parseAndRenderContent(newRawContent);
    }
    noteData.content = newRawContent;
    
    try {
        await _saveNoteToServer(noteId, newRawContent);
    } catch (error) {
        console.error(`Task Click: Error updating task for note ${noteId}. Reverting.`, error);
        checkbox.checked = !checkbox.checked;
        noteData.content = currentRawContent;
        if (contentDiv) {
            contentDiv.dataset.rawContent = currentRawContent;
            contentDiv.innerHTML = ui.parseAndRenderContent(currentRawContent);
        }
    }
}