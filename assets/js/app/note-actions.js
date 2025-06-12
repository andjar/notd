// FILE: assets/js/app/note-actions.js

import { notesForCurrentPage, currentPageId, addNoteToCurrentPage, updateNoteInCurrentPage, removeNoteFromCurrentPageById, setNotesForCurrentPage } from './state.js';
import { calculateOrderIndex } from './order-index-service.js';
import { notesAPI } from '../api_client.js';
import { debounce } from '../utils.js';
import { ui } from '../ui.js';

// --- Helper Functions ---

export function getNoteDataById(noteId) {
    return notesForCurrentPage.find(n => n.id == noteId);
}

export function getNoteElementById(noteId) {
    return ui.domRefs.notesContainer?.querySelector(`.note-item[data-note-id="${noteId}"]`);
}

async function executeBatchOperations(operations, optimisticDOMUpdater, userActionName) {
    if (!operations || operations.length === 0) return true;
    ui.updateSaveStatusIndicator('pending');
    const originalState = JSON.parse(JSON.stringify(notesForCurrentPage));

    try {
        if (optimisticDOMUpdater) optimisticDOMUpdater();

        const results = await notesAPI.batchUpdateNotes(operations);
        let allSucceeded = true;
        results.forEach(res => {
            if (res.status === 'error') allSucceeded = false;
            if (res.type === 'create' && res.note) {
                const tempEl = getNoteElementById(res.client_temp_id);
                if (tempEl) tempEl.dataset.noteId = res.note.id;
                updateNoteInCurrentPage({ ...res.note });
            } else if (res.type === 'update' && res.note) {
                updateNoteInCurrentPage(res.note);
            }
        });
        if (!allSucceeded) throw new Error('One or more batch operations failed.');
        ui.updateSaveStatusIndicator('saved');
        return true;
    } catch (error) {
        console.error(`Error in batch '${userActionName}':`, error);
        alert(`Action failed: ${error.message}. Reverting.`);
        setNotesForCurrentPage(originalState);
        ui.displayNotes(notesForCurrentPage, currentPageId);
        ui.updateSaveStatusIndicator('error');
        return false;
    }
}

// --- Core Actions ---

export async function saveNoteImmediately(noteItemEl) {
    const noteId = noteItemEl?.dataset.noteId;
    if (!noteId || String(noteId).startsWith('temp-')) return;
    const contentDiv = noteItemEl.querySelector('.note-content');
    if (!contentDiv) return;
    const newContent = ui.getRawTextWithNewlines(contentDiv);
    const noteData = getNoteDataById(noteId);
    if (noteData?.content === newContent) return;
    
    ui.updateSaveStatusIndicator('pending');
    try {
        const updatedNote = await notesAPI.updateNote(noteId, { content: newContent });
        updateNoteInCurrentPage(updatedNote);
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error(`Save failed for note ${noteId}:`, error);
        ui.updateSaveStatusIndicator('error');
    }
}

export const debouncedSaveNote = debounce(saveNoteImmediately, 1200);

export async function handleAddRootNote() {
    const { targetOrderIndex } = calculateOrderIndex(notesForCurrentPage, null, null, null);
    const clientTempId = `temp-root-${Date.now()}`;
    const newNoteData = { id: clientTempId, client_temp_id: clientTempId, page_id: currentPageId, content: '', parent_note_id: null, order_index: targetOrderIndex };
    addNoteToCurrentPage(newNoteData);

    const domUpdater = () => {
        const newNoteEl = ui.addNoteElement(newNoteData);
        ui.switchToEditMode(newNoteEl.querySelector('.note-content'));
    };
    await executeBatchOperations([{ type: 'create', payload: { ...newNoteData } }], domUpdater, 'Add Root Note');
}

export async function handleNoteDropAction(noteId, newParentId, previousSiblingId) {
    const originalState = JSON.parse(JSON.stringify(notesForCurrentPage));
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, newParentId, previousSiblingId, null);

    // Optimistic State Update
    const droppedNote = getNoteDataById(noteId);
    if(droppedNote) {
        droppedNote.parent_note_id = newParentId;
        droppedNote.order_index = targetOrderIndex;
    }
    siblingUpdates.forEach(update => {
        const sibling = getNoteDataById(update.id);
        if(sibling) sibling.order_index = update.newOrderIndex;
    });

    // Prepare operations
    const operations = [{ type: 'update', payload: { id: noteId, parent_note_id: newParentId, order_index: targetOrderIndex } }];
    siblingUpdates.forEach(update => operations.push({ type: 'update', payload: { id: update.id, order_index: update.newOrderIndex } }));

    // No DOM updater needed, as SortableJS already moved the element.
    const success = await executeBatchOperations(operations, null, 'Drop Note');
    if(!success) {
        // executeBatchOperations handles reverting state and re-rendering on failure.
    }
}
window.handleNoteDropAction = handleNoteDropAction; // Make global for SortableJS

/**
 * Handles the Enter key press in a note, creating a new sibling note.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} noteItem - The DOM element of the current note.
 * @param {Object} noteData - The data object for the current note.
 * @param {HTMLElement} contentDiv - The content-editable div of the current note.
 */
async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (e.shiftKey) {
        // Allow default browser behavior for Shift+Enter (inserts <br> or new div)
        // Then, ensure rawContent is updated for save
        setTimeout(() => {
            debouncedSaveNote(noteItem);
        }, 0);
        return;
    }
    e.preventDefault();

    const clientTempId = `temp-enter-${Date.now()}`;
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, noteData.parent_note_id, noteData.id, null);

    const newNoteData = {
        id: clientTempId,
        client_temp_id: clientTempId,
        page_id: currentPageId,
        content: '',
        parent_note_id: noteData.parent_note_id,
        order_index: targetOrderIndex,
        children: []
    };
    addNoteToCurrentPage(newNoteData);

    const operations = [{
        type: 'create',
        payload: { ...newNoteData, id: undefined, client_temp_id: clientTempId }
    }];
    siblingUpdates.forEach(update => {
        const noteToUpdate = getNoteDataById(update.id);
        if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: update.newOrderIndex });
        operations.push({ type: 'update', payload: { id: update.id, order_index: update.newOrderIndex } });
    });

    const domUpdater = () => {
        const newNoteEl = ui.addNoteElement(newNoteData, noteItem);
        const contentDivNew = newNoteEl.querySelector('.note-content');
        if (contentDivNew) ui.switchToEditMode(contentDivNew);
    };

    await executeBatchOperations(operations, domUpdater, 'Create Sibling Note');
}

/**
 * Handles Tab (indent) and Shift+Tab (outdent) key presses in a note.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} noteItem - The DOM element of the current note.
 * @param {Object} noteData - The data object for the current note.
 */
async function handleTabKey(e, noteItem, noteData) {
    e.preventDefault();

    await saveNoteImmediately(noteItem);

    const isOutdent = e.shiftKey;
    const currentSiblings = notesForCurrentPage.filter(n => String(n.parent_note_id ?? null) === String(noteData.parent_note_id ?? null))
        .sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const currentIndex = currentSiblings.findIndex(n => String(n.id) === String(noteData.id));

    let newParentId = null;
    if (isOutdent) {
        if (!noteData.parent_note_id) return; // Already at root
        const parentNote = getNoteDataById(noteData.parent_note_id);
        newParentId = parentNote ? parentNote.parent_note_id : null;
    } else { // Indent
        if (currentIndex === 0) return; // Cannot indent the first item in a list
        newParentId = currentSiblings[currentIndex - 1].id;
    }

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(notesForCurrentPage, newParentId, null, null);

    updateNoteInCurrentPage({ ...noteData, parent_note_id: newParentId, order_index: targetOrderIndex });

    const operations = [{
        type: 'update',
        payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex }
    }];
    siblingUpdates.forEach(update => {
        const noteToUpdate = getNoteDataById(update.id);
        if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: update.newOrderIndex });
        operations.push({ type: 'update', payload: { id: update.id, order_index: update.newOrderIndex } });
    });

    const domUpdater = () => {
        ui.moveNoteElement(noteData.id, newParentId);
        const contentDiv = noteItem.querySelector('.note-content');
        if (contentDiv) ui.switchToEditMode(contentDiv);
    };

    await executeBatchOperations(operations, domUpdater, isOutdent ? 'Outdent Note' : 'Indent Note');
}

/**
 * Handles Backspace key press in an empty note, deleting it.
 * @param {Event} e - The keyboard event.
 * @param {HTMLElement} noteItem - The DOM element of the current note.
 * @param {Object} noteData - The data object for the current note.
 * @param {HTMLElement} contentDiv - The content-editable div of the current note.
 */
async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if (noteData.content.trim() !== '' || (noteData.children && noteData.children.length > 0)) {
        return;
    }
    e.preventDefault();

    const noteToFocusId = ui.findPreviousVisibleNoteId(noteData.id);
    removeNoteFromCurrentPageById(noteData.id);
    const { siblingUpdates } = calculateOrderIndex(notesForCurrentPage, noteData.parent_note_id, null, null);

    const operations = [{ type: 'delete', payload: { id: noteData.id } }];
    siblingUpdates.forEach(update => {
        const noteToUpdate = getNoteDataById(update.id);
        if (noteToUpdate) updateNoteInCurrentPage({ ...noteToUpdate, order_index: update.newOrderIndex });
        operations.push({ type: 'update', payload: { id: update.id, order_index: update.newOrderIndex } });
    });

    const domUpdater = () => {
        ui.removeNoteElement(noteData.id);
        if (noteToFocusId) {
            const noteToFocusEl = getNoteElementById(noteToFocusId);
            const contentDivToFocus = noteToFocusEl.querySelector('.note-content');
            if (contentDivToFocus) ui.switchToEditMode(contentDivToFocus);
        }
    };

    await executeBatchOperations(operations, domUpdater, 'Delete Note');
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

    if (String(noteId).startsWith('temp-')) {
        e.preventDefault();
        return;
    }
    if (!noteData) return;

    switch (e.key) {
        case 'Enter':
            await handleEnterKey(e, noteItem, noteData, contentDiv);
            break;
        case 'Tab':
            await handleTabKey(e, noteItem, noteData);
            break;
        case 'Backspace':
            await handleBackspaceKey(e, noteItem, noteData, contentDiv);
            break;
        case 'ArrowUp':
        case 'ArrowDown':
            e.preventDefault();
            ui.navigateNotes(noteId, e.key === 'ArrowUp');
            break;
    }
}

/**
 * Handles clicks on task checkboxes, updating note content and properties.
 * @param {Event} e - The click event.
 */
export async function handleTaskCheckboxClick(e) {
    const checkbox = e.target;
    const noteItem = checkbox.closest('.note-item');
    if (!noteItem) return;

    const noteId = noteItem.dataset.noteId;
    if (!noteId || String(noteId).startsWith('temp-')) {
        e.preventDefault();
        return;
    }

    const noteData = getNoteDataById(noteId);
    if (!noteData) return;

    const isChecked = checkbox.checked;
    const currentContent = noteData.content;
    const taskRegex = /^(TODO|DOING|WAITING|SOMEDAY|DONE|CANCELLED|NLR)\s/;
    const match = currentContent.match(taskRegex);
    const contentWithoutPrefix = match ? currentContent.replace(taskRegex, '') : currentContent;

    const newContent = (isChecked ? 'DONE ' : 'TODO ') + contentWithoutPrefix;

    if (newContent === currentContent) return;

    updateNoteInCurrentPage({ ...noteData, content: newContent });
    ui.updateNoteElement(noteId, { ...noteData, content: newContent });
    ui.updateSaveStatusIndicator('pending');

    try {
        const updatedNote = await notesAPI.updateNote(noteId, { content: newContent });
        updateNoteInCurrentPage(updatedNote);
        ui.updateNoteElement(updatedNote.id, updatedNote);
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error(`Failed to update task for note ${noteId}:`, error);
        updateNoteInCurrentPage(noteData); // Revert optimistic update
        ui.updateNoteElement(noteId, noteData);
        ui.updateSaveStatusIndicator('error');
    }
}

/**
 * Creates the very first note on an empty page and focuses it.
 * This is typically called when a new page is loaded and found to have no notes.
 * @param {number} pageId - The ID of the page to add the note to.
 */
export async function handleCreateAndFocusFirstNote(pageId) {
    if (!pageId) {
        console.warn("Cannot create first note without a pageId.");
        return;
    }

    try {
        ui.updateSaveStatusIndicator('pending');
        console.log(`[note-actions] Creating first note for page ${pageId}`);

        // Use the single-note creation endpoint for this special case.
        const newNote = await notesAPI.createNote({
            page_id: pageId,
            content: '',
            order_index: 0 // First note is always at index 0
        });

        if (newNote) {
            // Update the application state with the new note.
            setNotesForCurrentPage([newNote]);
            
            // Tell the UI to render this single note.
            ui.displayNotes([newNote], pageId);
            
            // Find the newly rendered element and switch it to edit mode.
            const noteEl = getNoteElementById(newNote.id);
            if (noteEl) {
                const contentDiv = noteEl.querySelector('.note-content');
                if (contentDiv) {
                    ui.switchToEditMode(contentDiv);
                }
            }
            ui.updateSaveStatusIndicator('saved');
        }
    } catch (error) {
        console.error('Error creating the first note for the page:', error);
        if (ui.domRefs.notesContainer) {
            ui.domRefs.notesContainer.innerHTML = '<p class="error-message">Error creating the first note. Please try reloading.</p>';
        }
        ui.updateSaveStatusIndicator('error');
    }
}