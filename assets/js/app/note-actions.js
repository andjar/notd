// assets_js_app_note-actions.js

/**
 * @file Manages note-related actions such as creation, updates, deletion,
 * indentation, and interaction handling (keyboard, clicks).
 * It orchestrates optimistic UI updates, local state management,
 * and communication with the backend API, primarily using batch operations
 * for structural changes.
 */

import {
    notesForCurrentPage,
    currentPageId,
    addNoteToCurrentPage,
    updateNoteInCurrentPage,
    removeNoteFromCurrentPageById,
    setNotesForCurrentPage,
    // saveStatus, // UI state, managed by ui.js via updateSaveStatusIndicator
} from './state.js';

import { calculateOrderIndex } from './order-index-service.js';
import { notesAPI, propertiesAPI } from '../api_client.js';
import { debounce } from '../utils.js';
// import { handleNoteDrop } from '../ui/note-elements.js'; // For Sortable.js, if used

const notesContainer = document.querySelector('#notes-container');

// --- Property Parsing Utility ---
/**
 * Parses explicit properties (key::value) from text content.
 * @param {string} textContent - The text content of the note.
 * @returns {object} - An object where keys are property names and values are arrays of strings.
 */
export function parsePropertiesFromText(textContent) {
    const properties = {};
    const regex = /\{([^:}]+)::([^}]+)\}/g; // Matches {key::value}
    let match;
    while ((match = regex.exec(textContent)) !== null) {
        const key = match[1].trim();
        const value = match[2].trim();
        if (!properties[key]) {
            properties[key] = [];
        }
        properties[key].push(value);
    }
    return properties;
}

// --- Helper functions for text manipulation ---
/**
 * Inserts text at the current cursor position and positions cursor.
 * @param {string} text - Text to insert.
 * @param {number} [cursorOffset=0] - Position cursor relative to start of inserted text.
 */
function insertTextAtCursor(text, cursorOffset = 0) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    const textNode = document.createTextNode(text);
    range.insertNode(textNode);
    range.setStart(textNode, cursorOffset);
    range.setEnd(textNode, cursorOffset);
    selection.removeAllRanges();
    selection.addRange(range);
}

/**
 * Replaces text by deleting characters before cursor and inserting new text.
 * @param {number} deleteCount - Number of characters to delete before cursor.
 * @param {string} newText - Text to insert.
 * @param {number} [cursorOffset=0] - Position cursor relative to start of inserted text.
 */
function replaceTextAtCursor(deleteCount, newText, cursorOffset = 0) {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    if (range.startOffset < deleteCount) { // Ensure we don't delete beyond the start
        deleteCount = range.startOffset;
    }
    range.setStart(range.startContainer, range.startOffset - deleteCount);
    range.deleteContents();
    const textNode = document.createTextNode(newText);
    range.insertNode(textNode);
    // Ensure cursorOffset is within the bounds of the new textNode
    const finalCursorOffset = Math.min(cursorOffset, textNode.length);
    range.setStart(textNode, finalCursorOffset);
    range.setEnd(textNode, finalCursorOffset);
    selection.removeAllRanges();
    selection.addRange(range);
}

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
 * Helper function to update a newly created note (optimistically added with a temp ID)
 * with its permanent ID and server data, in both local state and DOM.
 * @param {string} clientTempId - The temporary ID used by the client.
 * @param {Object} noteFromServer - The note object received from the server, containing the permanent ID.
 */
function _finalizeNewNote(clientTempId, noteFromServer) {
    if (!noteFromServer || !noteFromServer.id) {
        console.error("[_finalizeNewNote] Invalid note data from server for temp ID:", clientTempId, noteFromServer);
        // Optionally, trigger a revert or error state if finalization fails critically
        return;
    }
    const permanentId = noteFromServer.id;
    console.log(`[_finalizeNewNote] Finalizing note. Temp ID: ${clientTempId}, Permanent ID: ${permanentId}`);

    // Update local state: Find the note by temp ID and update it with server data, ensuring permanent ID.
    const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(clientTempId));
    if (noteIndex > -1) {
        // Merge server data into the existing optimistic note, making sure 'id' is the permanent one.
        // Also, ensure client_temp_id is removed or handled if it was part of notesForCurrentPage[noteIndex].
        const existingOptimisticNote = notesForCurrentPage[noteIndex];
        notesForCurrentPage[noteIndex] = { 
            ...existingOptimisticNote, // Keep any client-side optimistic fields not yet on server
            ...noteFromServer,         // Override with server data
            id: permanentId            // Explicitly set permanent ID
        };
        // If 'client_temp_id' was a property on the state object, remove it after finalization:
        // delete notesForCurrentPage[noteIndex].client_temp_id;
    } else {
        console.warn(`[_finalizeNewNote] Could not find note with temp ID ${clientTempId} in local state to finalize.`);
        // This might indicate an issue, e.g., the note was removed before finalization.
        // Depending on strategy, you might re-add it here if it's guaranteed to exist.
        // addNoteToCurrentPage({ ...noteFromServer, id: permanentId }); // If it should be added if missing
    }

    // Update DOM element's dataset.noteId
    const tempNoteEl = getNoteElementById(clientTempId);
    if (tempNoteEl) {
        tempNoteEl.dataset.noteId = permanentId;
        const contentDiv = tempNoteEl.querySelector('.note-content');
        if (contentDiv) contentDiv.dataset.noteId = permanentId;
        const bulletEl = tempNoteEl.querySelector('.note-bullet');
        if (bulletEl) bulletEl.dataset.noteId = permanentId;
    } else {
        console.warn(`[_finalizeNewNote] Could not find DOM element for temp ID ${clientTempId} to update dataset.`);
    }
}


/**
 * Executes a batch of note operations with optimistic updates and revert logic.
 * Assumes `notesAPI.batchUpdateNotes` returns the array of individual operation results directly,
 * or throws an error if the API call fails or the response structure is invalid.
 *
 * @param {Array<Object>} operations - Array of operations for the batch API.
 *                                   Each operation: { type: 'create'|'update'|'delete', payload: Object }
 * @param {Function} optimisticDOMUpdater - A function to call to perform optimistic DOM updates.
 *                                          It is called after local state updates.
 * @param {string} userActionName - Name of the user action for logging/error messages (e.g., "Add Root Note").
 * @returns {Promise<boolean>} True if all operations were successful (or no operations), false otherwise.
 */
async function executeBatchOperations(operations, optimisticDOMUpdater, userActionName) {
    if (!operations || operations.length === 0) {
        console.warn(`[${userActionName} BATCH] No operations to execute.`);
        // Consider returning true as no operations means no failures.
        return true;
    }

    window.ui.updateSaveStatusIndicator('pending');
    // Clone the state *before* any optimistic changes by the CALLER of this function.
    // Callers (handleAddRootNote, etc.) are responsible for optimistic STATE updates.
    // This function handles DOM updates and API communication/revert.
    const originalNotesStateBeforeOptimisticChanges = JSON.parse(JSON.stringify(notesForCurrentPage));
    let success = false;

    try {
        // 1. Perform Optimistic DOM Updates (call the provided function)
        //    This is called AFTER the caller has made optimistic state changes.
        if (typeof optimisticDOMUpdater === 'function') {
            optimisticDOMUpdater();
        } else {
            console.warn(`[${userActionName} BATCH] No optimisticDOMUpdater function provided.`);
        }

        // 2. API Call
        console.log(`[${userActionName} BATCH] Sending operations:`, JSON.stringify(operations, null, 2));
        // `batchResultsArray` is now expected to be the direct array of operation results,
        // or an error would have been thrown by `notesAPI.batchUpdateNotes` (in api_client.js).
        const batchResultsArray = await notesAPI.batchUpdateNotes(operations);
        console.log(`[${userActionName} BATCH] API results array received:`, batchResultsArray);

        // 3. Process Individual Results
        //    (Though `notesAPI.batchUpdateNotes` should throw if the overall structure is bad,
        //     we still check individual operation statuses for robustness)
        if (!Array.isArray(batchResultsArray)) {
            // This case should ideally be caught by the modified notesAPI.batchUpdateNotes in api_client.js
            throw new Error(`[${userActionName} BATCH] notesAPI.batchUpdateNotes did not return an array as expected.`);
        }

        let allSubOperationsSucceeded = true;
        batchResultsArray.forEach(opResult => {
            // Validate each operation result object
            if (!opResult || typeof opResult !== 'object') {
                console.error(`[${userActionName} BATCH] Invalid operation result item:`, opResult);
                allSubOperationsSucceeded = false;
                return; // Skip to next opResult
            }

            if (opResult.status === 'error') {
                allSubOperationsSucceeded = false;
                const idForError = opResult.payload_identifier?.id || opResult.id || opResult.client_temp_id || 'N/A';
                console.error(`[${userActionName} BATCH] Server reported sub-operation error: Type: ${opResult.type || 'Unknown'}, Identifier: ${idForError}, Message: ${opResult.error_message || opResult.message || 'Unknown sub-operation error'}`);
            }

            if (!allSubOperationsSucceeded) return; // Stop processing if one failed

            const noteFromServer = opResult.note;       // Expected for 'create' and 'update'
            const clientTempId = opResult.client_temp_id; // Expected for 'create' if sent by client
            const deletedNoteId = opResult.deleted_note_id; // Expected for 'delete'

            if (opResult.type === 'create' && clientTempId && noteFromServer) {
                _finalizeNewNote(clientTempId, noteFromServer);
            } else if (opResult.type === 'update' && noteFromServer) {
                updateNoteInCurrentPage(noteFromServer);
                // Optionally, trigger a targeted DOM update for this specific note if its properties changed
                // e.g., if (window.ui.updateNoteElement) window.ui.updateNoteElement(noteFromServer.id, noteFromServer);
            } else if (opResult.type === 'delete' && deletedNoteId) {
                // Local state removal was optimistic and done by the caller. This is a server confirmation.
                console.log(`[${userActionName} BATCH] Confirmed deletion of note ID: ${deletedNoteId}`);
            } else if (opResult.status === 'success') {
                // A successful operation type not covered above (e.g. a 'read' if batch ever supports it)
                console.log(`[${userActionName} BATCH] Successfully processed unhandled operation type: ${opResult.type}`);
            }
        });

        if (!allSubOperationsSucceeded) {
            // If any sub-operation failed (as reported by server within a 2xx response),
            // treat it as an overall failure needing a revert.
            throw new Error(`One or more sub-operations in '${userActionName}' failed on the server side.`);
        }

        success = true;
        window.ui.updateSaveStatusIndicator('saved');

    } catch (error) { // Catches errors from API call, or errors thrown from processing results
        const errorMessage = error.message || `Batch operation '${userActionName}' failed.`;
        console.error(`[${userActionName} BATCH] Overall error: ${errorMessage}`, error.stack ? `\nStack: ${error.stack}` : '');
        
        // User feedback
        alert(`${errorMessage} Reverting local changes.`);
        window.ui.updateSaveStatusIndicator('error');

        // Revert local state to the state *before* the caller made its optimistic changes
        setNotesForCurrentPage(originalNotesStateBeforeOptimisticChanges);

        // Re-render the entire notes list from the reverted state
        if (window.ui && typeof window.ui.displayNotes === 'function' && currentPageId) {
            window.ui.displayNotes(notesForCurrentPage, currentPageId);
            // TODO: Consider re-focusing the element that triggered the action.
            // This is complex because the element might no longer exist or might have a different ID/state.
            // Example: if a new note creation failed, the temporary note element is gone.
            // If an indent failed, the original note element might need to be focused.
        } else {
            console.warn(`[${userActionName} BATCH] Could not re-render notes after batch failure. UI might be inconsistent.`);
        }
        success = false;
    } finally {
        // Always ensure the current local state (either successfully updated or reverted) is sorted.
        notesForCurrentPage.sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    }
    return success;
}


// --- Note Saving Logic (Single Note - for content edits, tasks) ---
/**
 * Saves a single note's content and properties to the server.
 * Used for debounced saves or immediate saves of content changes.
 * @param {string} noteId - The ID of the note. Must be a permanent ID.
 * @param {string} rawContent - The raw string content of the note.
 * @param {boolean} [isImmediateSave=false] - Flag for logging/differentiating save type.
 * @returns {Promise<Object|null>} The updated note object from the server, or null if save failed.
 */
async function _saveNoteToServer(noteId, rawContent, isImmediateSave = false) {
    if (String(noteId).startsWith('temp-')) {
        console.warn(`[_saveNoteToServer] Attempted to save note with temporary ID: ${noteId}. This should be part of a batch create or update after ID assignment.`);
        return null;
    }
    const saveType = isImmediateSave ? "IMMEDIATE" : "DEBOUNCED";
    window.ui.updateSaveStatusIndicator('pending');
    try {
        const explicitProperties = parsePropertiesFromText(rawContent);
        let contentToSave = rawContent;

        if (window.currentPageEncryptionKey && window.decryptionPassword) {
            contentToSave = sjcl.encrypt(window.decryptionPassword, rawContent);
            if (!explicitProperties.encrypted) explicitProperties.encrypted = [];
            if (!explicitProperties.encrypted.includes('true')) explicitProperties.encrypted.push('true');
        }
        
        const updatePayload = { 
            page_id: currentPageId,
            content: contentToSave,
            properties_explicit: explicitProperties
        };
        
        const updatedNote = await notesAPI.updateNote(noteId, updatePayload); // Uses single update API
        
        // It's unlikely a backend would change an ID on update, but this handles it.
        if (String(updatedNote.id) !== String(noteId)) {
             console.warn(`[_saveNoteToServer] Note ID changed by server from ${noteId} to ${updatedNote.id}. This is unusual for an update.`);
            // If this happens, need to update the ID in notesForCurrentPage and DOM.
            const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(noteId));
            if (noteIndex > -1) notesForCurrentPage[noteIndex].id = updatedNote.id;
            const noteEl = getNoteElementById(noteId); 
            if (noteEl) {
                noteEl.dataset.noteId = updatedNote.id;
                noteEl.querySelector('.note-content')?.setAttribute('data-note-id', updatedNote.id);
                noteEl.querySelector('.note-bullet')?.setAttribute('data-note-id', updatedNote.id);
            }
        }
        
        updateNoteInCurrentPage(updatedNote); 
        if (window.ui && typeof window.ui.updateNoteElement === 'function') {
            window.ui.updateNoteElement(updatedNote.id, updatedNote);
        }
        window.ui.updateSaveStatusIndicator('saved');
        return updatedNote;
    } catch (error) {
        console.error(`_saveNoteToServer (${saveType}): Error updating note ${noteId}. Error:`, error);
        window.ui.updateSaveStatusIndicator('error');
        // Avoid alert for non-critical background saves; status indicator is primary feedback.
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
    if (String(noteId).startsWith('temp-')) {
        console.warn("[saveNoteImmediately] Attempted to save note with temporary ID:", noteId);
        return null;
    }
    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return null;

    const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv);
    const rawContent = window.ui.normalizeNewlines(rawTextValue);
    contentDiv.dataset.rawContent = rawContent; // Ensure dataset is up-to-date
    return await _saveNoteToServer(noteId, rawContent, true);
}

/**
 * Debounced function to save a note's content.
 * @param {HTMLElement} noteEl - The note's DOM element.
 */
export const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    if (String(noteId).startsWith('temp-')) return;

    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return;
    
    // For debounced save, rely on dataset.rawContent which should be updated on input/blur
    const currentRawContent = contentDiv.dataset.rawContent || '';
    const noteData = getNoteDataById(noteId);

    // If note content hasn't changed, skip.
    if (noteData && noteData.content === currentRawContent) {
        if (window.ui && typeof window.ui.getSaveStatus === 'function' && window.ui.getSaveStatus() !== 'saved') {
            window.ui.updateSaveStatusIndicator('saved');
        }
        return;
    }
    await _saveNoteToServer(noteId, currentRawContent, false);
}, 1000); // 1-second debounce


// --- Event Handlers for Structural Changes (Using Batch API) ---

/**
 * Handles adding a new root-level note to the current page.
 */
export async function handleAddRootNote() {
    const pageIdToUse = currentPageId;
    if (!pageIdToUse) {
        alert('Please select or create a page first.');
        return;
    }

    const clientTempId = `temp-R-${Date.now()}`;
    const operations = [];

    const rootNotes = notesForCurrentPage
        .filter(n => !n.parent_note_id)
        .sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const previousSiblingId = rootNotes.length > 0 ? String(rootNotes[rootNotes.length - 1].id) : null;
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, null, previousSiblingId, null);

    const optimisticNewNote = {
        id: clientTempId, page_id: pageIdToUse, content: '', parent_note_id: null,
        order_index: targetOrderIndex, created_at: new Date().toISOString(), updated_at: new Date().toISOString(),
        properties: {}, client_temp_id: clientTempId // Important for UI mapping
    };
    addNoteToCurrentPage(optimisticNewNote); // Optimistic state update

    operations.push({
        type: 'create',
        payload: {
            page_id: pageIdToUse, content: '', parent_note_id: null,
            order_index: targetOrderIndex, client_temp_id: clientTempId
        }
    });

    siblingUpdates.forEach(update => {
        const noteToUpdate = getNoteDataById(update.id);
        if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: update.newOrderIndex });
        operations.push({
            type: 'update',
            payload: { id: update.id, page_id: pageIdToUse, order_index: update.newOrderIndex }
        });
    });
    // Local state sorting will be handled by executeBatchOperations.finally or after optimisticDOMUpdater

    const optimisticDOMUpdater = () => {
        notesForCurrentPage.sort((a,b) => (a.order_index || 0) - (b.order_index || 0)); // Sort before DOM update
        // Sibling DOM elements might need reordering here if not handled by addNoteElement implicitly
        // For now, relying on addNoteElement to place correctly or a refresh after batch.
        const noteEl = window.ui.addNoteElement(optimisticNewNote, notesContainer, 0); // 0 for root level
        const contentDiv = noteEl?.querySelector('.note-content');
        if (contentDiv) {
            contentDiv.dataset.rawContent = '';
            window.ui.switchToEditMode(contentDiv);
        }
    };

    await executeBatchOperations(operations, optimisticDOMUpdater, "Add Root Note");
}

/**
 * Handles the Enter key press in a note, creating a new sibling note.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} noteItem - The DOM element of the current note.
 * @param {Object} noteData - The data object for the current note.
 * @param {HTMLElement} contentDiv - The content-editable div of the current note.
 */
async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (contentDiv.classList.contains('rendered-mode')) {
        e.preventDefault(); window.ui.switchToEditMode(contentDiv); return;
    }
    if (e.shiftKey) { // Handle Shift+Enter for newline
        // Allow default browser behavior for Shift+Enter (inserts <br> or new div)
        // Then, ensure rawContent is updated for save
        const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv);
        contentDiv.dataset.rawContent = window.ui.normalizeNewlines(rawTextValue);
        debouncedSaveNote(noteItem); // Save the current note's change with the newline
        return; // Do not prevent default for Shift+Enter
    }
    e.preventDefault(); // Prevent default Enter behavior (usually newline) for non-Shift+Enter

    if (!noteData) { console.error("EnterKey: current noteData missing."); return; }
    const pageIdToUse = currentPageId;
    if (!pageIdToUse) { console.error("EnterKey: currentPageId missing."); return; }

    const clientTempId = `temp-E-${Date.now()}`;
    const operations = [];
    const parentIdForNewNote = noteData.parent_note_id;
    const previousSiblingIdForNewNote = String(noteData.id);

    const siblingsOfCurrentNote = notesForCurrentPage.filter(n =>
        String(n.parent_note_id ?? null) === String(parentIdForNewNote ?? null)
    ).sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const currentNoteIdx = siblingsOfCurrentNote.findIndex(n => String(n.id) === String(noteData.id));
    const nextSiblingIdForNewNote = (currentNoteIdx !== -1 && currentNoteIdx < siblingsOfCurrentNote.length - 1)
        ? String(siblingsOfCurrentNote[currentNoteIdx + 1].id) : null;

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(
        notesForCurrentPage, parentIdForNewNote, previousSiblingIdForNewNote, nextSiblingIdForNewNote
    );

    const optimisticNewNote = {
        id: clientTempId, page_id: pageIdToUse, content: '', parent_note_id: parentIdForNewNote,
        order_index: targetOrderIndex, created_at: new Date().toISOString(), updated_at: new Date().toISOString(),
        properties: {}, client_temp_id: clientTempId
    };
    addNoteToCurrentPage(optimisticNewNote);

    operations.push({
        type: 'create',
        payload: {
            page_id: pageIdToUse, content: '', parent_note_id: parentIdForNewNote,
            order_index: targetOrderIndex, client_temp_id: clientTempId
        }
    });

    siblingUpdates.forEach(update => {
        const noteToUpdate = getNoteDataById(update.id);
        if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: update.newOrderIndex });
        operations.push({
            type: 'update',
            payload: { id: update.id, page_id: pageIdToUse, order_index: update.newOrderIndex }
        });
    });

    const optimisticDOMUpdater = () => {
        notesForCurrentPage.sort((a,b) => (a.order_index || 0) - (b.order_index || 0));
        const currentNestingLevel = window.ui.getNestingLevel(noteItem);
        // renderNote is for a full note structure, addNoteElement might be more appropriate if it handles insertion
        const newNoteEl = window.ui.renderNote(optimisticNewNote, currentNestingLevel);

        const parentDomContainer = noteItem.parentElement; // New note is a sibling
        if (parentDomContainer) {
            parentDomContainer.insertBefore(newNoteEl, noteItem.nextSibling);
        } else {
            console.error("[EnterKey BATCH] Current note item has no parent. Appending to notesContainer.");
            notesContainer.appendChild(newNoteEl); // Fallback
        }
        
        const newContentDiv = newNoteEl?.querySelector('.note-content');
        if (newContentDiv) {
            newContentDiv.dataset.rawContent = '';
            window.ui.switchToEditMode(newContentDiv);
        }
    };

    await executeBatchOperations(operations, optimisticDOMUpdater, "Create Note (Enter)");
}

/**
 * Handles Tab (indent) and Shift+Tab (outdent) key presses in a note.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} noteItem - The DOM element of the current note.
 * @param {Object} noteData - The data object for the current note.
 * @param {HTMLElement} contentDiv - The content-editable div of the current note.
 */
async function handleTabKey(e, noteItem, noteData, contentDiv) {
    e.preventDefault();
    if (!noteData) return;

    const currentRawContent = contentDiv.dataset.rawContent || window.ui.getRawTextWithNewlines(contentDiv);
    const normalizedCurrentContent = window.ui.normalizeNewlines(currentRawContent);

    if (normalizedCurrentContent !== noteData.content) {
        window.ui.updateSaveStatusIndicator('pending');
        const savedNote = await _saveNoteToServer(noteData.id, normalizedCurrentContent, true);
        if (savedNote) {
            // Update noteData with confirmed values from server
            noteData.content = savedNote.content;
            noteData.updated_at = savedNote.updated_at;
            noteData.properties = savedNote.properties || parsePropertiesFromText(savedNote.content); // Ensure properties are synced
            contentDiv.dataset.rawContent = savedNote.content; // Update DOM dataset
            window.ui.updateSaveStatusIndicator('saved');
        } else {
            alert("Failed to save current note's changes before indent/outdent. Aborting operation.");
            window.ui.updateSaveStatusIndicator('error');
            return;
        }
    }

    const operations = [];
    let newParentIdForMovedNote = noteData.parent_note_id;
    let newOrderIndexForMovedNote = noteData.order_index;
    let destinationSiblingUpdates = []; // Updates for siblings in the new parent list
    let sourceSiblingOperationsPayloads = []; // Payloads for updating siblings in the old parent list

    const originalParentId = noteData.parent_note_id;
    const originalOrderIndex = noteData.order_index;
    const originalNoteDataForRevert = { ...getNoteDataById(noteData.id) }; // Backup before changing

    if (e.shiftKey) { // Outdent
        if (!originalParentId) return; // Already root
        const oldParentNoteData = getNoteDataById(originalParentId);
        if (!oldParentNoteData) { console.error("Outdent: Old parent data missing for ID:", originalParentId); return; }

        newParentIdForMovedNote = oldParentNoteData.parent_note_id; // New parent is grandparent
        const prevSiblingForMoved = String(oldParentNoteData.id); // Moved note comes after its old parent

        const siblingsOfGrandparent = notesForCurrentPage.filter(n =>
            String(n.parent_note_id ?? null) === String(newParentIdForMovedNote ?? null)
        ).sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
        const oldParentIdxInGrandparentList = siblingsOfGrandparent.findIndex(n => String(n.id) === String(oldParentNoteData.id));
        const nextSiblingForMoved = (oldParentIdxInGrandparentList !== -1 && oldParentIdxInGrandparentList < siblingsOfGrandparent.length - 1)
            ? String(siblingsOfGrandparent[oldParentIdxInGrandparentList + 1].id) : null;

        const calcResult = calculateOrderIndex(notesForCurrentPage, newParentIdForMovedNote, prevSiblingForMoved, nextSiblingForMoved);
        newOrderIndexForMovedNote = calcResult.targetOrderIndex;
        destinationSiblingUpdates = calcResult.siblingUpdates;
    } else { // Indent
        const currentLevelSiblings = notesForCurrentPage.filter(n =>
            String(n.parent_note_id ?? null) === String(originalParentId ?? null)
        ).sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
        const currentNoteIdx = currentLevelSiblings.findIndex(n => String(n.id) === String(noteData.id));

        if (currentNoteIdx <= 0) return; // Cannot indent first child or if no previous sibling
        const potentialNewParent = currentLevelSiblings[currentNoteIdx - 1];
        if (!potentialNewParent) return;

        newParentIdForMovedNote = String(potentialNewParent.id);
        const childrenOfNewParent = notesForCurrentPage.filter(n =>
            String(n.parent_note_id ?? null) === newParentIdForMovedNote
        ).sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
        const prevSiblingForMoved = childrenOfNewParent.length > 0
            ? String(childrenOfNewParent[childrenOfNewParent.length - 1].id) : null;

        const calcResult = calculateOrderIndex(notesForCurrentPage, newParentIdForMovedNote, prevSiblingForMoved, null);
        newOrderIndexForMovedNote = calcResult.targetOrderIndex;
        destinationSiblingUpdates = calcResult.siblingUpdates;
    }

    // --- Optimistic Local State Update (Moved Note) ---
    updateNoteInCurrentPage({ ...noteData, parent_note_id: newParentIdForMovedNote, order_index: newOrderIndexForMovedNote });
    operations.push({
        type: 'update',
        payload: {
            id: noteData.id, page_id: currentPageId,
            parent_note_id: newParentIdForMovedNote, order_index: newOrderIndexForMovedNote,
            content: noteData.content // Content is up-to-date
        }
    });

    destinationSiblingUpdates.forEach(update => {
        const noteToUpdate = getNoteDataById(update.id);
        if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: update.newOrderIndex });
        operations.push({
            type: 'update',
            payload: { id: update.id, page_id: currentPageId, order_index: update.newOrderIndex }
        });
    });

    // --- Re-index Source Siblings if parent changed ---
    if (String(originalParentId ?? null) !== String(newParentIdForMovedNote ?? null)) {
        const sourceSiblingsToReindex = notesForCurrentPage
            .filter(n => String(n.parent_note_id ?? null) === String(originalParentId ?? null) && String(n.id) !== String(noteData.id))
            .sort((a, b) => (a.order_index || 0) - (b.order_index || 0)); // Sort by current order_index

        sourceSiblingsToReindex.forEach((sibling, index) => {
            if ((sibling.order_index || 0) !== index) { // Check if order_index needs update
                const noteToUpdate = getNoteDataById(sibling.id);
                if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: index });
                sourceSiblingOperationsPayloads.push({ id: sibling.id, page_id: currentPageId, order_index: index });
            }
        });
        sourceSiblingOperationsPayloads.forEach(payload => operations.push({ type: 'update', payload }));
    }
    
    const optimisticDOMUpdater = () => {
        notesForCurrentPage.sort((a,b) => (a.order_index || 0) - (b.order_index || 0));
        const movedNoteElement = getNoteElementById(noteData.id); // Get the element again after state updates
        if (!movedNoteElement) { console.error("Tab: Moved note element not found in DOM for update."); return; }

        let targetParentDomEl;
        let newNestingLevel;
        let insertBeforeEl = null;

        if (e.shiftKey) { // Outdent
            const oldParentNoteEl = getNoteElementById(originalParentId); // This is the note that WAS the parent
            targetParentDomEl = oldParentNoteEl ? oldParentNoteEl.parentElement : notesContainer; // New parent container is grandparent's children container
            newNestingLevel = window.ui.getNestingLevel(targetParentDomEl); // Nesting level of target container
             // The moved note should come *after* its old parent.
            insertBeforeEl = oldParentNoteEl ? oldParentNoteEl.nextElementSibling : null;

        } else { // Indent
            const newParentNoteDomEl = getNoteElementById(newParentIdForMovedNote);
            if (!newParentNoteDomEl) { console.error("Indent: New parent DOM element not found."); return; }
            targetParentDomEl = newParentNoteDomEl.querySelector('.note-children');
            if (!targetParentDomEl) {
                targetParentDomEl = document.createElement('div');
                targetParentDomEl.className = 'note-children';
                newParentNoteDomEl.appendChild(targetParentDomEl);
                // if (typeof Sortable !== 'undefined' && Sortable.create) { /* Make sortable */ }
            }
            newNestingLevel = window.ui.getNestingLevel(newParentNoteDomEl) + 1;
            // No insertBeforeEl, append to end of new children list
        }
        
        window.ui.moveNoteElement(movedNoteElement, targetParentDomEl, newNestingLevel, insertBeforeEl);
        const movedContentDiv = movedNoteElement.querySelector('.note-content');
        if (movedContentDiv) window.ui.switchToEditMode(movedContentDiv);
    };

    const success = await executeBatchOperations(operations, optimisticDOMUpdater, e.shiftKey ? "Outdent Note" : "Indent Note");
    if (!success) {
        // If batch fails, executeBatchOperations handles global revert.
        // We might need to restore the specific moved note's original state if it was altered before the backup.
        // However, originalNotesState in executeBatchOperations should capture the state before any changes in this function.
        // The prerequisite save of content is the only change before originalNotesState is cloned.
    }
}

/**
 * Handles Backspace key press in an empty note, deleting it.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} noteItem - The DOM element of the current note.
 * @param {Object} noteData - The data object for the current note.
 * @param {HTMLElement} contentDiv - The content-editable div of the current note.
 */
async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if (!noteData || String(noteData.id).startsWith('temp-')) return;

    if (contentDiv.classList.contains('edit-mode') && (contentDiv.dataset.rawContent || contentDiv.textContent).trim() === '') {
        const children = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.id));
        if (children.length > 0) {
            console.log('Backspace: Note has children, not deleting:', noteData.id); return;
        }
        if (notesForCurrentPage.length === 1 && !noteData.parent_note_id) {
            console.log('Backspace: Cannot delete the only root note:', noteData.id); return;
        }
        e.preventDefault();

        let noteToFocusAfterDeleteEl = null;
        const allVisibleNoteItems = Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden)'));
        const currentDOMIndex = allVisibleNoteItems.findIndex(el => el === noteItem);

        if (currentDOMIndex > 0) { // Try to focus previous sibling
            noteToFocusAfterDeleteEl = allVisibleNoteItems[currentDOMIndex - 1];
        } else if (allVisibleNoteItems.length > 1 && currentDOMIndex + 1 < allVisibleNoteItems.length) { // Try next sibling
            noteToFocusAfterDeleteEl = allVisibleNoteItems[currentDOMIndex + 1];
        } else if (noteData.parent_note_id) { // Try parent
            noteToFocusAfterDeleteEl = getNoteElementById(noteData.parent_note_id);
        } // If still null, no specific focus target after delete (e.g., last note on page deleted)


        const noteIdToDelete = noteData.id;
        const parentIdOfDeleted = noteData.parent_note_id;

        // Optimistic Local State Update: Remove first
        removeNoteFromCurrentPageById(noteIdToDelete);

        // Prepare operations: delete + re-index siblings
        const operations = [{ type: 'delete', payload: { id: noteIdToDelete } }];
        const siblingsOfDeleted = notesForCurrentPage // Get siblings from already-updated list
            .filter(n => String(n.parent_note_id ?? null) === String(parentIdOfDeleted ?? null))
            .sort((a, b) => (a.order_index || 0) - (b.order_index || 0));

        siblingsOfDeleted.forEach((sibling, index) => {
            if ((sibling.order_index || 0) !== index) {
                const noteToUpdate = getNoteDataById(sibling.id); // Get fresh copy for update
                if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: index });
                operations.push({
                    type: 'update',
                    payload: { id: sibling.id, page_id: currentPageId, order_index: index }
                });
            }
        });

        const optimisticDOMUpdater = () => {
            // notesForCurrentPage is already sorted if sibling re-indexing happened.
            // DOM update for siblings might be needed if visual order changes.
            window.ui.removeNoteElement(noteIdToDelete);
        };

        const success = await executeBatchOperations(operations, optimisticDOMUpdater, "Delete Note (Backspace)");
        if (success && noteToFocusAfterDeleteEl) {
            const contentDivToFocus = noteToFocusAfterDeleteEl.querySelector('.note-content');
            if (contentDivToFocus) window.ui.switchToEditMode(contentDivToFocus);
        } else if (success && notesForCurrentPage.length === 0 && currentPageId) {
            console.log("All notes deleted. Page is empty.");
            // Optionally, trigger creation of a new first note:
            // if (typeof window.handleCreateAndFocusFirstNote === 'function') { // Check if function exists
            //     await window.handleCreateAndFocusFirstNote(currentPageId);
            // }
        }
    }
}

// --- Standard Keyboard Handlers (Non-structural changes) ---
/**
 * Handles Arrow Up/Down key presses for navigating between notes.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} contentDiv - The content-editable div of the current note.
 */
function handleArrowKey(e, contentDiv) {
    e.preventDefault();
    const allVisibleNotesContent = Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden) .note-content'));
    const currentVisibleIndex = allVisibleNotesContent.indexOf(contentDiv);
    let nextVisibleIndex = -1;

    if (e.key === 'ArrowUp' && currentVisibleIndex > 0) {
        nextVisibleIndex = currentVisibleIndex - 1;
    } else if (e.key === 'ArrowDown' && currentVisibleIndex < allVisibleNotesContent.length - 1) {
        nextVisibleIndex = currentVisibleIndex + 1;
    }

    if (nextVisibleIndex !== -1) {
        const nextNoteContent = allVisibleNotesContent[nextVisibleIndex];
        window.ui.switchToEditMode(nextNoteContent);
        // Place cursor at end of content in next note
        const range = document.createRange();
        const sel = window.getSelection();
        if (nextNoteContent.firstChild) { // Check if there's content to select
            range.selectNodeContents(nextNoteContent);
            range.collapse(false); // false to collapse to end
        } else { // If empty, just set cursor at the start
            range.setStart(nextNoteContent, 0);
            range.collapse(true);
        }
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

/**
 * Handles shortcut expansions (e.g., :tag: -> {tag::}).
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} contentDiv - The content-editable div.
 * @returns {Promise<boolean>} True if a shortcut was handled, false otherwise.
 */
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

    if (precedingText2Chars === ':t') { replacementText = '{tag::}'; cursorOffsetAfterReplace = 6; }
    else if (precedingText2Chars === ':d') { const today = new Date().toISOString().slice(0, 10); replacementText = `{date::${today}}`; cursorOffsetAfterReplace = replacementText.length -1; }
    else if (precedingText2Chars === ':r') { const now = new Date().toISOString(); replacementText = `{timestamp::${now}}`; cursorOffsetAfterReplace = replacementText.length -1;}
    else if (precedingText2Chars === ':k') { replacementText = '{keyword::}'; cursorOffsetAfterReplace = 10; }

    if (replacementText) {
        e.preventDefault();
        replaceTextAtCursor(2, replacementText, cursorOffsetAfterReplace);
        shortcutHandled = true;
    }

    if (shortcutHandled) {
        const noteItemForShortcut = contentDiv.closest('.note-item');
        if (noteItemForShortcut) {
            const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv);
            contentDiv.dataset.rawContent = window.ui.normalizeNewlines(rawTextValue);
            debouncedSaveNote(noteItemForShortcut); // Save after shortcut expansion
        }
        return true;
    }
    return false;
}

/**
 * Handles auto-closing of brackets/parentheses/braces.
 * @param {Event} e - The keyboard event.
 * @returns {boolean} True if a bracket was auto-closed, false otherwise.
 */
function handleAutocloseBrackets(e) {
    let handled = false;
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) return false;
    const range = selection.getRangeAt(0);
    const editor = e.target; // contentEditable div

    const keyActionMap = { '[': '[]', '{': '{}', '(': '()' };

    if (keyActionMap[e.key]) {
        const textToInsert = keyActionMap[e.key];
        let cursorOffset = 1;

        // Special handling for '[' to avoid conflict with manual '[[page]]' typing
        // if (e.key === '[') {
        //     const textNode = range.startContainer;
        //     let textBeforeCursor = "";
        //     if (textNode.nodeType === Node.TEXT_NODE && range.startOffset > 0) {
        //         textBeforeCursor = textNode.textContent.substring(range.startOffset - 1, range.startOffset);
        //     }
        //     if (textBeforeCursor === '[') { // User typed '[[', let page link suggestions handle it
        //         return false; // Don't auto-close to '[[[]]]'
        //     }
        // }
        // Simpler: always auto-close. If user types [[, they can backspace one ] if needed.
        // Or, page-link-suggestions.js might handle [[ typing more gracefully.

        e.preventDefault();
        insertTextAtCursor(textToInsert, cursorOffset);
        handled = true;
    }

    if (handled) {
        // Dispatch an input event so note-renderer's listeners (like for page link suggestions) are triggered
        editor.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
    }
    return handled;
}

/**
 * Master keydown handler for notes. Delegates to specific handlers based on key.
 * @param {Event} e - The keyboard event.
 */
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


// --- Event Handler: Task Checkbox Click ---
/**
 * Handles clicks on task checkboxes, updating note content and properties.
 * @param {Event} e - The click event.
 */
export async function handleTaskCheckboxClick(e) {
    const checkbox = e.target;
    const noteItem = checkbox.closest('.note-item');
    if (!noteItem) return;
    
    const noteId = noteItem.dataset.noteId;
    if (String(noteId).startsWith('temp-')) {
        console.warn("Task checkbox clicked on temp note. Action deferred until note is saved.");
        checkbox.checked = !checkbox.checked; // Revert optimistic UI change
        return;
    }

    const contentDiv = noteItem.querySelector('.note-content');
    const noteData = getNoteDataById(noteId);

    if (!noteData || !contentDiv) {
        console.error('Task Click: Note data or contentDiv not found for ID:', noteId);
        checkbox.checked = !checkbox.checked; return;
    }
    
    // Use dataset.rawContent if available (likely from edit mode), otherwise use noteData.content (from rendered mode)
    let currentRawContent = contentDiv.dataset.rawContent !== undefined ? contentDiv.dataset.rawContent : noteData.content;
    
    let newRawContent = currentRawContent;
    let doneAt = null;
    const isChecked = checkbox.checked;
    const markerType = checkbox.dataset.markerType?.toUpperCase();

    const taskPrefixesWithSpace = ["TODO ", "DOING ", "SOMEDAY ", "DONE ", "WAITING ", "CANCELLED ", "NLR "];
    const currentPrefix = taskPrefixesWithSpace.find(p => currentRawContent.toUpperCase().startsWith(p));
    const contentWithoutPrefix = currentPrefix ? currentRawContent.substring(currentPrefix.length) : currentRawContent;

    switch (markerType) {
        case 'TODO':    newRawContent = (isChecked ? 'DONE ' : 'TODO ')    + contentWithoutPrefix; break;
        case 'DOING':   newRawContent = (isChecked ? 'DONE ' : 'TODO ')    + contentWithoutPrefix; break;
        case 'SOMEDAY': newRawContent = (isChecked ? 'DONE ' : 'TODO ')    + contentWithoutPrefix; break;
        case 'DONE':    newRawContent = (isChecked ? 'DONE ' : 'TODO ')    + contentWithoutPrefix; break;
        case 'WAITING': newRawContent = (isChecked ? 'DONE ' : 'TODO ')    + contentWithoutPrefix; break;
        case 'CANCELLED': case 'NLR': checkbox.checked = true; /* Non-interactive, ensure it stays checked */ return;
        default: console.warn("Unknown task marker type:", markerType); checkbox.checked = !checkbox.checked; return;
    }

    if (newRawContent.toUpperCase().startsWith('DONE ')) {
        doneAt = new Date().toISOString().slice(0, 19).replace('T', ' '); // YYYY-MM-DD HH:MM:SS format
    }

    // Optimistic update of local state and DOM
    const originalNoteContentForRevert = noteData.content;
    const originalNotePropertiesForRevert = JSON.parse(JSON.stringify(noteData.properties || {}));

    noteData.content = newRawContent; // Update local state content
    contentDiv.dataset.rawContent = newRawContent; // Update DOM dataset
    if (contentDiv.classList.contains('edit-mode')) {
        contentDiv.textContent = newRawContent;
    } else {
        // Re-render only if content actually changed to avoid unnecessary parsing
        if (newRawContent !== currentRawContent) {
            contentDiv.innerHTML = window.ui.parseAndRenderContent(newRawContent);
        }
    }
    // Optimistically update properties (done_at)
    if (!noteData.properties) noteData.properties = {};
    if (doneAt) {
        noteData.properties.done_at = [{ value: doneAt, internal: 0 }];
    } else {
        delete noteData.properties.done_at;
    }
    updateNoteInCurrentPage(noteData); // Propagate optimistic changes to global state

    try {
        window.ui.updateSaveStatusIndicator('pending');
        // 1. Update note content (server's pattern processor should handle status property)
        const updatedNoteServer = await notesAPI.updateNote(noteId, { 
            page_id: currentPageId, content: newRawContent 
        });
        
        // Sync local noteData with server response for content, timestamps, and server-processed properties
        noteData.content = updatedNoteServer.content;
        noteData.updated_at = updatedNoteServer.updated_at;
        noteData.properties = updatedNoteServer.properties || parsePropertiesFromText(updatedNoteServer.content); // Trust server's properties

        // 2. Explicitly manage 'done_at' property if server doesn't auto-handle it based on "DONE "
        //    (This depends on backend implementation. If backend handles it, this block might be redundant)
        const serverDoneAt = noteData.properties?.done_at?.[0]?.value;
        if (doneAt && serverDoneAt !== doneAt) { // If we calculated doneAt and server doesn't have it (or different)
            await propertiesAPI.setProperty({ entity_type: 'note', entity_id: parseInt(noteId), name: 'done_at', value: doneAt });
            if(!noteData.properties.done_at) noteData.properties.done_at = [];
            noteData.properties.done_at = [{value: doneAt, internal: 0}];
        } else if (!doneAt && serverDoneAt) { // If we calculated no doneAt but server has one
            await propertiesAPI.deleteProperty('note', parseInt(noteId), 'name', 'done_at');
             delete noteData.properties.done_at;
        }
        
        updateNoteInCurrentPage(noteData); // Final update to global state with all server-confirmed data
        window.ui.updateSaveStatusIndicator('saved');

    } catch (error) {
        console.error(`Task Click: Error updating task for note ${noteId}. Error:`, error);
        alert(`Failed to update task status. ${error.message}`);
        
        // Revert optimistic changes
        noteData.content = originalNoteContentForRevert;
        noteData.properties = originalNotePropertiesForRevert;
        checkbox.checked = !checkbox.checked; 
        contentDiv.dataset.rawContent = originalNoteContentForRevert; 
        if (contentDiv.classList.contains('edit-mode')) {
            contentDiv.textContent = originalNoteContentForRevert;
        } else {
            contentDiv.innerHTML = window.ui.parseAndRenderContent(originalNoteContentForRevert);
        }
        updateNoteInCurrentPage(noteData); // Revert in global state
        window.ui.updateSaveStatusIndicator('error');
    }
}