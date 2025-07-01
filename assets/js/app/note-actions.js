/**
 * @file Manages note-related actions such as creation, updates, deletion,
 * indentation, and interaction handling. It orchestrates optimistic UI updates
 * and communication with the backend API using batch operations.
 */

// Get Alpine store reference
function getAppStore() {
    return window.Alpine.store('app');
}

import { calculateOrderIndex } from './order-index-service.js';
import { notesAPI } from '../api_client.js';
import { debounce, handleAutocloseBrackets, insertTextAtCursor, encrypt } from '../utils.js';
import { ui } from '../ui.js';

const notesContainer = document.querySelector('#notes-container');

// --- Note Data Accessors ---
export function getNoteDataById(noteId) {
    if (!noteId) return null;
    const appStore = getAppStore();
    return appStore.notes.find(n => String(n.id) === String(noteId));
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
    const appStore = getAppStore();
    const noteIndex = appStore.notes.findIndex(n => String(n.id) === String(clientTempId));
    if (noteIndex > -1) {
        appStore.notes[noteIndex] = { ...appStore.notes[noteIndex], ...noteFromServer, id: permanentId };
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

         console.log("Batch operations to send:", operations);

        const batchResponse = await notesAPI.batchUpdateNotes(operations);
        let allSubOperationsSucceeded = true;
        
        if (batchResponse && Array.isArray(batchResponse.results)) {
            batchResponse.results.forEach(opResult => {
                if (opResult.status === 'error') {
                    allSubOperationsSucceeded = false;
                    console.error(`[${userActionName} BATCH] Server reported sub-operation error:`, opResult);
                    // Log more details about the error
                    if (opResult.message) {
                        console.error(`[${userActionName} BATCH] Error message:`, opResult.message);
                    }
                    if (opResult.id) {
                        console.error(`[${userActionName} BATCH] Failed operation ID:`, opResult.id);
                    }
                } else if (opResult.type === 'create' && opResult.client_temp_id) {
                    _finalizeNewNote(opResult.client_temp_id, opResult.note);
                } else if (opResult.type === 'update' && opResult.note) {
                    const appStore = getAppStore();
                    appStore.updateNote(opResult.note);
                }
            });
        } else {
            allSubOperationsSucceeded = false;
            console.error(`[${userActionName} BATCH] Invalid response structure from server:`, batchResponse);
        }

        if (!allSubOperationsSucceeded) {
            const errorDetails = batchResponse.results
                .filter(r => r.status === 'error')
                .map(r => `${r.type} operation: ${r.message || 'Unknown error'}`)
                .join(', ');
            throw new Error(`One or more sub-operations in '${userActionName}' failed: ${errorDetails}`);
        }
        success = true;
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error(`[${userActionName}] Batch operation failed:`, error);
        const errorMessage = error.message || `Batch operation '${userActionName}' failed.`;
        alert(`${errorMessage} Reverting local changes.`);
        ui.updateSaveStatusIndicator('error');
        const appStore = getAppStore();
        appStore.setNotes(originalNotesState);
        ui.displayNotes(appStore.notes, appStore.currentPageId);
        success = false;
    }
    return success;
}

/**
 * A centralized handler for various note-related actions.
 * @param {string} action - The action to perform (e.g., 'create', 'focus').
 * @param {string|null} noteId - The ID of the note to act upon.
 */
export async function handleNoteAction(action, noteId) {
    switch (action) {
        case 'create':
            await handleAddRootNote();
            break;
        case 'focus':
            if (noteId) {
                const noteElement = getNoteElementById(noteId);
                const contentDiv = noteElement?.querySelector('.note-content');
                if (contentDiv) {
                    ui.switchToEditMode(contentDiv);
                }
            }
            break;
        default:
            console.warn(`Unknown note action: ${action}`);
    }
}

// --- Note Saving Logic ---
async function _saveNoteToServer(noteId, rawContent) {
    if (String(noteId).startsWith('temp-')) return null;
    if (!getNoteDataById(noteId)) return null;
    
    const appStore = getAppStore();
    const password = appStore.pagePassword;
    let contentToSave = rawContent;
    let isEncrypted = false;
    if (password) {
        // **FIX**: Corrected argument order for encrypt function. It's (password, plaintext).
        contentToSave = encrypt(password, rawContent);
        isEncrypted = true;
    }
    
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));

    const operations = [{
        type: 'update',
        payload: { id: noteId, content: contentToSave, is_encrypted: isEncrypted }
    }];

    const success = await executeBatchOperations(originalNotesState, operations, null, "Save Note Content");
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
    if (!getNoteDataById(noteId)) return;
    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return;
    
    const currentRawContent = contentDiv.dataset.rawContent || '';
    await _saveNoteToServer(noteId, currentRawContent);
}, 1000);

// --- Event Handlers for Structural Changes ---
export async function handleAddRootNote() {
    const appStore = getAppStore();
    if (!appStore.currentPageId) return;
    const clientTempId = `temp-R-${Date.now()}`;
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    const rootNotes = appStore.notes.filter(n => !n.parent_note_id);
    const lastRootNote = rootNotes.sort((a, b) => (a.order_index || 0) - (b.order_index || 0)).pop();
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, null, lastRootNote?.id || null, null);
    
    const optimisticNewNote = { id: clientTempId, page_id: appStore.currentPageId, content: '', parent_note_id: null, order_index: targetOrderIndex, properties: {} };
    appStore.addNote(optimisticNewNote);
    
    const password = appStore.pagePassword;
    let contentForServer = '';
    let isEncrypted = false;
    if (password) {
        // **FIX**: Corrected argument order for encrypt function.
        contentForServer = encrypt(password, '');
        isEncrypted = true;
    }

    const operations = [{ type: 'create', payload: { page_id: appStore.currentPageId, content: contentForServer, is_encrypted: isEncrypted, parent_note_id: null, order_index: targetOrderIndex, client_temp_id: clientTempId } }];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    
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

    const appStore = getAppStore();
    const clientTempId = `temp-E-${Date.now()}`;
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    const siblings = appStore.notes.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? '')).sort((a, b) => a.order_index - b.order_index);
    const currentNoteIndexInSiblings = siblings.findIndex(n => String(n.id) === String(noteData.id));
    const nextSibling = siblings[currentNoteIndexInSiblings + 1];

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, noteData.parent_note_id, String(noteData.id), nextSibling?.id || null);
    
    const optimisticNewNote = { id: clientTempId, page_id: appStore.currentPageId, content: '', parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex, properties: {} };
    appStore.addNote(optimisticNewNote);

    const password = appStore.pagePassword;
    let contentForServer = '';
    let isEncrypted = false;
    if (password) {
        // **FIX**: Corrected argument order for encrypt function.
        contentForServer = encrypt(password, '');
        isEncrypted = true;
    }
    
    const operations = [{ type: 'create', payload: { page_id: appStore.currentPageId, content: contentForServer, is_encrypted: isEncrypted, parent_note_id: noteData.parent_note_id, order_index: targetOrderIndex, client_temp_id: clientTempId } }];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    
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

async function handleTabKey(e, noteItem, noteData) {
    e.preventDefault();
    await saveNoteImmediately(noteItem); // **FIX**: Ensure content is saved before structural change

    const appStore = getAppStore();
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    let operations = [];
    let newParentId = null;

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const oldParentNote = getNoteDataById(noteData.parent_note_id);
        if (!oldParentNote) return;
        newParentId = oldParentNote.parent_note_id;

        const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, newParentId, String(oldParentNote.id), null);
        
        operations.push({ type: 'update', payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));

    } else { // Indent
        const siblings = appStore.notes.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? '')).sort((a,b) => a.order_index - b.order_index);
        const currentNoteIndexInSiblings = siblings.findIndex(n => String(n.id) === String(noteData.id));
        if (currentNoteIndexInSiblings < 1) return;
        
        const newParentNote = siblings[currentNoteIndexInSiblings - 1];
        newParentId = String(newParentNote.id);
        const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, newParentId, null, null);

        operations.push({ type: 'update', payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    }
    
    // Perform optimistic state updates before calling the batch operation
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
        const appStore = getAppStore();
        ui.displayNotes(appStore.notes, appStore.currentPageId);
        const newNoteItem = getNoteElementById(noteData.id);
        if (newNoteItem) {
            const newContentDiv = newNoteItem.querySelector('.note-content');
            if (newContentDiv) ui.switchToEditMode(newContentDiv);
        }
    };
    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, e.shiftKey ? "Outdent Note" : "Indent Note");
}

async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    const appStore = getAppStore();
    if ((contentDiv.dataset.rawContent || contentDiv.textContent).trim() !== '') return;
    const children = appStore.notes.filter(n => String(n.parent_note_id) === String(noteData.id));
    if (children.length > 0) return;

    e.preventDefault();
    let focusTargetEl = noteItem.previousElementSibling || getNoteElementById(noteData.parent_note_id);
    
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    const noteIdToDelete = noteData.id;

    appStore.removeNoteById(noteIdToDelete);
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

async function handleShortcutExpansion(e, contentDiv) {
    if (e.key !== ' ') return false;
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return false;
    const range = selection.getRangeAt(0);
    const cursorPos = range.startOffset;
    const textNode = range.startContainer;
    if (!textNode || textNode.nodeType !== Node.TEXT_NODE || cursorPos < 2) return false;

    const textContent = textNode.textContent;
    const precedingText2Chars = textContent.substring(cursorPos - 2, cursorPos);
    let shortcutHandled = false;
    let replacementText = '';
    let cursorOffsetAfterReplace = 0;

    if (precedingText2Chars === ':t') { replacementText = '{tag::}'; cursorOffsetAfterReplace = 1; }
    else if (precedingText2Chars === ':d') { const today = new Date().toISOString().slice(0, 10); replacementText = `{date::${today}}`; cursorOffsetAfterReplace = 1; }
    else if (precedingText2Chars === ':r') { const now = new Date().toISOString(); replacementText = `{timestamp::${now}}`; cursorOffsetAfterReplace = 1; }
    else if (precedingText2Chars === ':k') { replacementText = '{keyword::}'; cursorOffsetAfterReplace = 1; }

    if (replacementText) {
        e.preventDefault();
        range.setStart(textNode, cursorPos - 2);
        range.deleteContents();
        insertTextAtCursor(replacementText, cursorOffsetAfterReplace);
        shortcutHandled = true;
    }

    if (shortcutHandled) {
        const noteItemForShortcut = contentDiv.closest('.note-item');
        if (noteItemForShortcut) {
            debouncedSaveNote(noteItemForShortcut);
        }
        return true;
    }
    return false;
}

export async function handleNoteKeyDown(e) {
    if (!e.target.matches('.note-content')) return;
    const noteItem = e.target.closest('.note-item');
    const contentDiv = e.target;
    if (!noteItem || !contentDiv) return;

    const noteData = getNoteDataById(noteItem.dataset.noteId);
    if (!noteData) return;

    if (await handleShortcutExpansion(e, contentDiv)) return;
    if (handleAutocloseBrackets(e)) return;
    
    switch (e.key) {
        case 'Enter': return await handleEnterKey(e, noteItem, noteData, contentDiv);
        case 'Tab': return await handleTabKey(e, noteItem, noteData);
        case 'Backspace': return await handleBackspaceKey(e, noteItem, noteData, contentDiv);
        case 'ArrowUp':
        case 'ArrowDown': return handleArrowKey(e, contentDiv);
    }
}

export async function handleTaskCheckboxClick(e) {
    // This function is now delegated to `note-renderer.js` to keep it with the element creation logic.
    // The call remains in app.js, but the implementation is now in the UI layer.
    // This is a placeholder or can be removed if app.js calls the UI function directly.
}