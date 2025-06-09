/**
 * UI Module for Note Element specific functionalities
 * Handles DOM manipulation and rendering for note items
 * @module ui/note-elements
 */

import { domRefs } from './dom-refs.js';
// Assuming note-renderer.js will export renderNote, parseAndRenderContent, and renderAttachments
import { renderNote, parseAndRenderContent, renderAttachments } from './note-renderer.js';
// Assuming note-interactions.js will export updateParentVisuals and handleNoteDrop
// import { updateParentVisuals } from './note-interactions.js';  // Remove this import as the function is in ui.js
import { calculateOrderIndex } from '../app/order-index-service.js'; // Added import

// Globals assumed to be available: Sortable, feather, window.notesAPI, window.notesForCurrentPage, window.currentPageId, ui (for ui.displayNotes in handleNoteDrop error case)

/**
 * Displays notes in the container
 * @param {Array} notesData - Array of note objects
 * @param {number} pageId - Current page ID
 */
function displayNotes(notesData, pageId) {
    domRefs.notesContainer.innerHTML = '';

    if (!notesData || notesData.length === 0) {
        // ui.displayNotes will now simply leave the container empty if there are no notes.
        // The creation of the first note on an empty page is handled by app.js (handleCreateAndFocusFirstNote).
        // No temporary client-side note needed here anymore.
        return;
    }

    // Sort notes by order_index before building the tree
    const sortedNotes = [...notesData].sort((a, b) => a.order_index - b.order_index);
    
    // Build and display note tree
    const noteTree = buildNoteTree(sortedNotes);
    noteTree.forEach(note => {
        domRefs.notesContainer.appendChild(renderNote(note, 0));
    });
    
    // Initialize drag and drop functionality
    initializeDragAndDrop();

    // Initialize Feather icons after all notes are rendered
    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace();
    }
}

/**
 * Updates an existing note element in the DOM with new data.
 * @param {string} noteId - The ID of the note to update.
 * @param {Object} updatedNoteData - The new data for the note.
 */
function updateNoteElement(noteId, updatedNoteData) {
    const noteElement = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteElement) {
        console.warn(`updateNoteElement: Note element with ID ${noteId} not found.`);
        return;
    }

    // Update content
    const contentDiv = noteElement.querySelector('.note-content');
    if (contentDiv) {
        contentDiv.dataset.rawContent = updatedNoteData.content || '';
        // If in rendered mode, re-render. If in edit mode, user is typing, so don't overwrite.
        // However, if a background save updated content, edit mode should reflect it.
        // For simplicity now, only re-render if in rendered-mode.
        // More complex sync for edit mode might be needed if server changes content significantly.
        if (contentDiv.classList.contains('rendered-mode')) {
            contentDiv.innerHTML = parseAndRenderContent(updatedNoteData.content || '');
        } else {
            // If in edit mode, and the updated content is different from current text,
            // it implies a background change. For now, we log this.
            // A more sophisticated merge or notification could be implemented.
            if (contentDiv.textContent !== (updatedNoteData.content || '')) {
                console.log(`Note ${noteId} content updated in background while in edit mode. UI not refreshed to preserve user edits.`, { current: contentDiv.textContent, new: updatedNoteData.content });
                 // Optionally, you could signal this to the user or update rawContent and let blur handle it.
            }
        }
    }

    // Update properties (assuming parseAndRenderContent handles inline properties)
    // If properties were displayed in a separate div, that would be updated here.

    // Update collapse state
    const isCollapsed = updatedNoteData.collapsed === true || String(updatedNoteData.collapsed) === 'true';
    noteElement.classList.toggle('collapsed', isCollapsed);
    const arrowEl = noteElement.querySelector('.note-collapse-arrow');
    if (arrowEl) {
        arrowEl.dataset.collapsed = isCollapsed.toString();
    }
    const childrenContainer = noteElement.querySelector('.note-children');
    if (childrenContainer) {
        childrenContainer.classList.toggle('collapsed', isCollapsed);
         childrenContainer.style.display = isCollapsed ? 'none' : ''; // Direct style for immediate effect
    }
    
    // Update "has-children" indicator and parent visuals
    // This relies on updatedNoteData.children being part of the data if it's available
    // or checking the DOM if not. For now, let updateParentVisuals handle it based on DOM.
    ui.updateParentVisuals(noteElement); // Call on itself to update its own arrow if children status changed

    // Update attachments section if has_attachments info is available
    const attachmentsContainer = noteElement.querySelector('.note-attachments');
    if (attachmentsContainer && typeof updatedNoteData.has_attachments !== 'undefined') {
        // renderAttachments is now idempotent and handles showing/hiding/fetching based on the flag
        renderAttachments(attachmentsContainer, noteId, updatedNoteData.has_attachments);
    }

    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace();
    }
}

/**
 * Adds a new note element to the DOM.
 * @param {Object} noteData - The data for the new note.
 * @param {HTMLElement} targetDomContainer - The parent DOM container (notesContainer or a .note-children div).
 * @param {number} nestingLevel - The nesting level for the new note.
 * @param {HTMLElement|null} [beforeElement=null] - Optional: if provided, insert noteElement before this sibling.
 */
function addNoteElement(noteData, targetDomContainer, nestingLevel, beforeElement = null) {
    if (!noteData || !targetDomContainer) {
        console.error('addNoteElement: noteData or targetDomContainer is null');
        return null;
    }

    const newNoteEl = renderNote(noteData, nestingLevel);

    // Find the correct position to insert based on order_index
    if (!beforeElement) {
        const siblings = Array.from(targetDomContainer.children)
            .filter(child => child.classList.contains('note-item'))
            .map(childEl => ({
                element: childEl,
                order_index: window.notesForCurrentPage.find(n => String(n.id) === String(childEl.dataset.noteId))?.order_index || Infinity
            }))
            .sort((a, b) => a.order_index - b.order_index);

        // Find the first sibling with a higher order_index
        const nextSibling = siblings.find(s => s.order_index > noteData.order_index);
        if (nextSibling) {
            beforeElement = nextSibling.element;
        } else if (siblings.length > 0 && noteData.order_index < siblings[0].order_index) {
            // If this note should be first, insert before the first sibling
            beforeElement = siblings[0].element;
        }
    }

    // Insert the new note at the correct position
    if (beforeElement && beforeElement.parentElement === targetDomContainer) {
        targetDomContainer.insertBefore(newNoteEl, beforeElement);
    } else {
        // If no beforeElement or it's not in the target container, append to the end
        targetDomContainer.appendChild(newNoteEl);
    }

    // Update visuals of the parent if this note is added as a child
    if (targetDomContainer.classList.contains('note-children')) {
        const parentNoteItem = targetDomContainer.closest('.note-item');
        if (parentNoteItem) {
            ui.updateParentVisuals(parentNoteItem);
        }
    }
    
    // Initialize Sortable on its children container if it has one and it's newly created by renderNote
    const newChildrenContainer = newNoteEl.querySelector('.note-children');
    if (newChildrenContainer && !newChildrenContainer.classList.contains('ui-sortable')) {
        if (typeof Sortable !== 'undefined' && Sortable.create) {
            Sortable.create(newChildrenContainer, { 
                group: 'notes', 
                animation: 150, 
                handle: '.note-bullet', 
                ghostClass: 'note-ghost', 
                chosenClass: 'note-chosen', 
                dragClass: 'note-drag', 
                onEnd: handleNoteDrop 
            });
        }
    }
    
    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace();
    }
    return newNoteEl;
}

/**
 * Removes a note element from the DOM.
 * @param {string} noteId - The ID of the note to remove.
 */
function removeNoteElement(noteId) {
    const noteElement = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteElement) {
        console.warn(`removeNoteElement: Note element with ID ${noteId} not found.`);
        return;
    }

    const parentDomContainer = noteElement.parentElement;
    noteElement.remove();

    if (parentDomContainer && parentDomContainer.classList.contains('note-children')) {
        const parentNoteItem = parentDomContainer.closest('.note-item');
        if (parentNoteItem) {
            ui.updateParentVisuals(parentNoteItem);
            if (parentDomContainer.children.length === 0) {
                // If children container is empty, remove it to clean up DOM
                parentDomContainer.remove();
                parentNoteItem.classList.remove('has-children'); // Ensure visual state is correct
            }
        }
    }
    // No specific Feather call needed here unless parent visuals change icons.
}

/**
 * Moves a note element in the DOM to a new parent and nesting level.
 * Handles creation of children containers and updates nesting styles.
 * @param {HTMLElement} noteElement - The note element to move.
 * @param {HTMLElement} newParentDomElement - The new parent DOM element (can be notesContainer).
 * @param {number} newNestingLevel - The new nesting level for the moved note.
 * @param {HTMLElement|null} [beforeElement=null] - Optional: if provided, insert noteElement before this sibling.
 */
function moveNoteElement(noteElement, newParentDomElement, newNestingLevel, beforeElement = null) {
    if (!noteElement || !newParentDomElement) {
        console.error('moveNoteElement: noteElement or newParentDomElement is null');
        return;
    }

    const oldParentChildrenContainer = noteElement.parentElement;

    let targetChildrenContainer;
    if (newParentDomElement.id === 'notes-container') {
        targetChildrenContainer = newParentDomElement;
    } else {
        targetChildrenContainer = newParentDomElement.querySelector('.note-children');
        if (!targetChildrenContainer) {
            targetChildrenContainer = document.createElement('div');
            targetChildrenContainer.className = 'note-children';
            newParentDomElement.appendChild(targetChildrenContainer);
            // If Sortable is used, it might need to be initialized here for the new container
            if (typeof Sortable !== 'undefined' && Sortable.create) {
                 Sortable.create(targetChildrenContainer, { group: 'notes', animation: 150, handle: '.note-bullet', ghostClass: 'note-ghost', chosenClass: 'note-chosen', dragClass: 'note-drag', onEnd: handleNoteDrop });
            }
        }
    }

    // Move the element
    if (beforeElement && beforeElement.parentElement === targetChildrenContainer) {
        targetChildrenContainer.insertBefore(noteElement, beforeElement);
    } else {
        targetChildrenContainer.appendChild(noteElement);
    }

    // Update nesting level for the moved note and its children
    function updateNestingRecursive(element, level) {
        element.style.setProperty('--nesting-level', level);
        const childrenContainer = element.querySelector('.note-children');
        if (childrenContainer) {
            Array.from(childrenContainer.children)
                .filter(child => child.classList.contains('note-item'))
                .forEach(childNote => updateNestingRecursive(childNote, level + 1));
        }
    }
    updateNestingRecursive(noteElement, newNestingLevel);

    // Update visuals for old and new parents
    if (oldParentChildrenContainer && oldParentChildrenContainer !== targetChildrenContainer) {
        const oldParentEl = oldParentChildrenContainer.closest('.note-item');
        if (oldParentEl) {
            ui.updateParentVisuals(oldParentEl);
             // Check if old children container is empty
            if (oldParentChildrenContainer.classList.contains('note-children') && oldParentChildrenContainer.children.length === 0) {
                oldParentChildrenContainer.remove(); // Or hide, depending on desired behavior
                oldParentEl.classList.remove('has-children'); // Ensure parent no longer shows as expandable
            }
        }
    }
    if (newParentDomElement.id !== 'notes-container') {
        ui.updateParentVisuals(newParentDomElement);
    } else {
        // If new parent is notesContainer, there's no specific parent element to update visuals for in this way,
        // but SortableJS root list might need refresh if not handled by its own mechanisms.
    }
     // Ensure Feather icons are re-applied if any were moved or changed
    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace();
    }
}

/**
 * Builds a tree structure from flat note array
 * @param {Array} notes - Flat array of note objects
 * @param {number|null} [parentId=null] - Parent note ID
 * @returns {Array} Tree structure of notes
 */
function buildNoteTree(notes, parentId = null) {
    if (!notes) return [];
    
    return notes
        .filter(note => note.parent_note_id === parentId)
        .sort((a, b) => a.order_index - b.order_index)
        .map(note => ({
            ...note,
            children: buildNoteTree(notes, note.id)
        }));
}

/**
 * Handles note drop events
 * @param {Object} evt - Sortable drop event
 */
async function handleNoteDrop(evt) {
    const noteId = evt.item.dataset.noteId;
    if (!noteId || noteId.startsWith('temp-')) {
        console.warn('Attempted to drop a temporary or invalid note. Aborting.');
        // Optionally revert drag UI immediately if needed
        if (evt.from && evt.item.parentNode === evt.from) { // Check if it's still in the original container
          evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
        } else if (evt.item.parentNode) { // If it was moved, try to remove it
          evt.item.parentNode.removeChild(evt.item);
        }
        return;
    }

    const newContainer = evt.to;
    const oldContainer = evt.from;
    const newSortableIndex = evt.newDraggableIndex !== undefined ? evt.newDraggableIndex : evt.newIndex;
    const oldSortableIndex = evt.oldDraggableIndex !== undefined ? evt.oldDraggableIndex : evt.oldIndex;

    // Get the current note data to preserve content
    const currentNoteData = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
    if (!currentNoteData) {
        console.error('Note data not found for ID:', noteId, 'in window.notesForCurrentPage. Aborting drop.');
        // Revert the DOM change on error
        if (oldContainer !== newContainer) {
            oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]);
        } else {
            if (oldSortableIndex < newSortableIndex) {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]);
            } else {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex + 1]);
            }
        }
        return;
    }

    let newParentId = null;
    if (newContainer.classList.contains('note-children')) {
        const parentNoteItem = newContainer.closest('.note-item');
        if (parentNoteItem) {
            newParentId = parentNoteItem.dataset.noteId;
            if (!newParentId || newParentId.startsWith('temp-')) {
                console.error('Cannot drop note onto a temporary or invalid parent note.');
                // Revert logic as above
                 if (oldContainer !== newContainer) { oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]); } else { if (oldSortableIndex < newSortableIndex) { oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]); } else { oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex + 1]); } }
                return;
            }
        }
    }

    // Determine logical previous and next siblings based on the item's final DOM position.
    // This correctly uses the visual order from the DOM instead of relying on the
    // data model's order (order_index), which can be out of sync during a drag-and-drop operation.
    const previousEl = evt.item.previousElementSibling;
    const nextEl = evt.item.nextElementSibling;

    let previousSiblingId = null;
    if (previousEl && previousEl.classList.contains('note-item') && previousEl.dataset.noteId) {
        previousSiblingId = previousEl.dataset.noteId;
    }

    let nextSiblingId = null;
    if (nextEl && nextEl.classList.contains('note-item') && nextEl.dataset.noteId) {
        nextSiblingId = nextEl.dataset.noteId;
    }

    const originalNotesState = JSON.parse(JSON.stringify(window.notesForCurrentPage)); // For revert

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(
        window.notesForCurrentPage,
        newParentId,
        previousSiblingId,
        nextSiblingId
    );
    
    console.log(`[HANDLE_NOTE_DROP] For Note ID: ${noteId}, New Parent ID: ${newParentId}, Prev Sib ID: ${previousSiblingId}, Next Sib ID: ${nextSiblingId}. Calculated: targetOrderIndex=${targetOrderIndex}, siblingUpdates:`, siblingUpdates);

    // Optimistic local state updates
    const noteToUpdate = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
    if (noteToUpdate) {
        noteToUpdate.parent_note_id = newParentId;
        noteToUpdate.order_index = targetOrderIndex;
    } else {
        console.error(`[HANDLE_NOTE_DROP] Dropped note with ID ${noteId} not found in local cache for optimistic update.`);
        // Revert and bail, something is wrong.
        window.notesForCurrentPage = originalNotesState;
        window.ui.displayNotes(window.notesForCurrentPage, window.currentPageId); // Re-render
        return;
    }

    siblingUpdates.forEach(update => {
        const siblingNote = window.notesForCurrentPage.find(n => String(n.id) === String(update.id));
        if (siblingNote) {
            siblingNote.order_index = update.newOrderIndex;
        } else {
            console.warn(`[HANDLE_NOTE_DROP] Sibling note with ID ${update.id} for order_index update not found in local cache.`);
        }
    });
    window.notesForCurrentPage.sort((a, b) => a.order_index - b.order_index);

    // Prepare API calls
    const apiPromises = [];
    const droppedNotePayload = {
        page_id: window.currentPageId,
        // content: currentNoteData.content || '', // Content is not changed on drop
        parent_note_id: newParentId,
        order_index: targetOrderIndex
    };
    apiPromises.push(window.notesAPI.updateNote(noteId, droppedNotePayload));

    siblingUpdates.forEach(update => {
        apiPromises.push(window.notesAPI.updateNote(update.id, {
            order_index: update.newOrderIndex,
            page_id: window.currentPageId
            // parent_note_id is not changed for siblings
        }));
    });

    try {
        const results = await Promise.allSettled(apiPromises);
        console.log('[HANDLE_NOTE_DROP] API call results:', results);

        let isError = false;
        results.forEach(result => {
            if (result.status === 'rejected') {
                isError = true;
                console.error(`[HANDLE_NOTE_DROP] Failed API operation:`, result.reason);
            } else if (result.status === 'fulfilled') {
                // Sync successful updates back to local state (e.g., updated_at, server-confirmed order_index)
                const updatedNoteFromServer = result.value;
                const localNoteIndex = window.notesForCurrentPage.findIndex(n => String(n.id) === String(updatedNoteFromServer.id));
                if (localNoteIndex > -1) {
                    window.notesForCurrentPage[localNoteIndex] = {
                        ...window.notesForCurrentPage[localNoteIndex], // Preserve local properties like 'children'
                        ...updatedNoteFromServer // Apply server updates
                    };
                }
            }
        });

        if (isError) {
            throw new Error('One or more note updates failed during drag and drop.');
        }
        
        // Final sort after successful server updates
        window.notesForCurrentPage.sort((a, b) => a.order_index - b.order_index);

        // DOM is already updated by SortableJS.
        // Update visual properties for the moved item and parents.
        const movedNoteElement = evt.item;
        const newParentNoteElement = newContainer.closest('.note-item');
        const newNestingLevel = newParentId ? ui.getNestingLevel(newParentNoteElement) + 1 : 0;
        movedNoteElement.style.setProperty('--nesting-level', newNestingLevel);
        
        const oldParentEl = oldContainer.closest('.note-item');
        const newParentEl = newContainer.closest('.note-item');

        if (oldParentEl) {
            ui.updateParentVisuals(oldParentEl);
            if (oldContainer.classList.contains('note-children') && oldContainer.children.length === 0) {
                oldContainer.remove();
                oldParentEl.classList.remove('has-children');
            }
        }
        if (newParentEl && newParentEl !== oldParentEl) {
            ui.updateParentVisuals(newParentEl);
        } else if (!newParentEl && newContainer === domRefs.notesContainer) {
            // Handled by oldParentEl logic if it exists
        }
        
        console.log(`[HANDLE_NOTE_DROP] Successfully processed drop for note ${noteId}.`);

    } catch (error) { // Catches errors from Promise.allSettled block or if isError was true
        console.error('[HANDLE_NOTE_DROP] Error processing note drop, attempting to revert:', error);
        
        // Revert local state
        window.notesForCurrentPage = originalNotesState;
        
        // Show user-friendly error message
        const feedback = document.createElement('div');
        feedback.className = 'copy-feedback error-feedback'; 
        feedback.style.background = 'var(--color-error, #dc2626)';
        feedback.textContent = `Failed to save position: ${error.message || 'Unknown error'}`;
        document.body.appendChild(feedback);
        setTimeout(() => feedback.remove(), 3000);

        // Re-render the entire notes list from the reverted state
        // This effectively reverts DOM changes made by SortableJS as well.
        if (window.ui && typeof window.ui.displayNotes === 'function') {
            window.ui.displayNotes(window.notesForCurrentPage, window.currentPageId);
        } else {
            console.warn("[HANDLE_NOTE_DROP] ui.displayNotes not available for error recovery. UI might be inconsistent.");
            // Fallback DOM revert (might not be perfect if SortableJS made complex changes)
            if (oldContainer !== newContainer) {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]);
            } else {
                if (oldSortableIndex < newSortableIndex) {
                    oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]);
                } else {
                    oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex + 1]);
                }
            }
        }
    }
}

/**
 * Initializes drag and drop functionality for notes
 */
function initializeDragAndDrop() {
    if (typeof Sortable === 'undefined') {
        console.warn('Sortable.js not loaded, drag and drop disabled');
        return;
    }

    // Track drag state to prevent interference
    window.isDragInProgress = false;

    // Initialize sortable for the main notes container
    const notesContainer = domRefs.notesContainer;
    if (notesContainer) {
        Sortable.create(notesContainer, {
            group: 'notes',
            animation: 150,
            handle: '.note-bullet',
            ghostClass: 'note-ghost',
            chosenClass: 'note-chosen',
            dragClass: 'note-drag',
            onStart: function(evt) {
                window.isDragInProgress = true;
            },
            onEnd: async function(evt) {
                try {
                    await handleNoteDrop(evt);
                } finally {
                    setTimeout(() => {
                        window.isDragInProgress = false;
                    }, 500); // Keep the flag for a bit longer to prevent interference
                }
            }
        });
    }

    // Initialize sortable for all children containers
    const childrenContainers = document.querySelectorAll('.note-children');
    childrenContainers.forEach(container => {
        // Check if sortable already initialized
        if (container.classList.contains('ui-sortable')) { // Sortable.js adds 'ui-sortable' class
            return;
        }
        Sortable.create(container, {
            group: 'notes',
            animation: 150,
            handle: '.note-bullet',
            ghostClass: 'note-ghost',
            chosenClass: 'note-chosen',
            dragClass: 'note-drag',
            onStart: function(evt) {
                window.isDragInProgress = true;
            },
            onEnd: async function(evt) {
                try {
                    await handleNoteDrop(evt);
                } finally {
                    setTimeout(() => {
                        window.isDragInProgress = false;
                    }, 500); // Keep the flag for a bit longer to prevent interference
                }
            }
        });
    });
}


export {
    displayNotes,
    updateNoteElement,
    addNoteElement,
    removeNoteElement,
    moveNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    handleNoteDrop // Exporting if it's needed by other modules, though current plan is internal use.
};
