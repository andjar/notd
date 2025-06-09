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
            oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
        } else {
            if (oldIndex < newIndex) {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
            } else {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex + 1]);
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
                 if (oldContainer !== newContainer) { oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]); } else { if (oldIndex < newIndex) { oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]); } else { oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex + 1]); } }
                return;
            }
        }
    }

    // Determine logical previous and next siblings based on the drop position.
    // newSortableIndex is the DOM index in the new list provided by SortableJS.
    // We need to map this to the data-model (`order_index` sorted) list of siblings.

    const siblingsInNewParentContext = window.notesForCurrentPage
        .filter(note => 
            (note.parent_note_id ? String(note.parent_note_id) : null) === (newParentId ? String(newParentId) : null) && // Same parent
            String(note.id) !== String(noteId) // Exclude the note being moved itself
        )
        .sort((a, b) => a.order_index - b.order_index);

    let previousSiblingId = null;
    let nextSiblingId = null;

    if (siblingsInNewParentContext.length === 0) {
        // No other siblings in the new parent context.
        previousSiblingId = null;
        nextSiblingId = null;
    } else if (newSortableIndex === 0) {
        // Dropped at the very beginning of the list (of siblings).
        previousSiblingId = null;
        nextSiblingId = String(siblingsInNewParentContext[0].id);
    } else if (newSortableIndex >= siblingsInNewParentContext.length) {
        // Dropped at the very end of the list (of siblings).
        previousSiblingId = String(siblingsInNewParentContext[siblingsInNewParentContext.length - 1].id);
        nextSiblingId = null;
    } else {
        // Dropped between two existing siblings.
        previousSiblingId = String(siblingsInNewParentContext[newSortableIndex - 1].id);
        nextSiblingId = String(siblingsInNewParentContext[newSortableIndex].id);
    }
    
    const targetOrderIndex = calculateOrderIndex(
        window.notesForCurrentPage, // Full notes array for lookups by the service
        newParentId,
        previousSiblingId,
        nextSiblingId
    );
    
    console.log(`[HANDLE_NOTE_DROP] For Note ID: ${noteId}, New Parent ID: ${newParentId}, Prev Sib ID: ${previousSiblingId}, Next Sib ID: ${nextSiblingId}, DOM newIndex: ${newSortableIndex}, Calculated Target OrderIndex: ${targetOrderIndex}`);
    
    const updateData = {
        page_id: window.currentPageId,
        content: currentNoteData.content || '', 
        parent_note_id: newParentId,
        order_index: targetOrderIndex
    };

    console.log('Attempting to update note position (using calculateOrderIndex):', { noteId, ...updateData });

    try {
        const updatedNote = await window.notesAPI.updateNote(noteId, updateData);
        console.log('Note position updated successfully on server:', updatedNote);

        // Update local data cache accurately WITH THE SERVER'S RESPONSE
        const noteIndexInCache = window.notesForCurrentPage.findIndex(n => String(n.id) === String(updatedNote.id));
        if (noteIndexInCache > -1) {
            // Preserve local children if any, update fields from server response
            // Note: updatedNote from server typically won't have 'children' array.
            // We merge server data into the existing local cache item.
            window.notesForCurrentPage[noteIndexInCache] = { 
                ...window.notesForCurrentPage[noteIndexInCache], // Keep existing local fields like 'children'
                ...updatedNote // Overwrite with server data (id, content, parent_note_id, order_index, etc.)
            };
        } else {
            // This case should ideally not happen if currentNoteData was found earlier.
            window.notesForCurrentPage.push(updatedNote); // Add if somehow missing
            console.warn(`[HANDLE_NOTE_DROP] Note with ID ${updatedNote.id} was not in notesForCurrentPage cache but was updated on server. Added to cache.`);
        }
        
        // Ensure the entire notesForCurrentPage is sorted by the now definitive server-side order_index
        window.notesForCurrentPage.sort((a, b) => a.order_index - b.order_index);

        // DOM is already updated by SortableJS.
        // Update nesting level visual property for the moved item.
        const movedNoteElement = evt.item;
        const newParentNoteElement = newContainer.closest('.note-item');
        const newNestingLevel = newParentId ? ui.getNestingLevel(newParentNoteElement) + 1 : 0;
        movedNoteElement.style.setProperty('--nesting-level', newNestingLevel);
        
        // Update parent visuals for old and new parents.
        const oldParentEl = oldContainer.closest('.note-item'); // evt.from is oldContainer
        const newParentEl = newContainer.closest('.note-item'); // evt.to is newContainer

        if (oldParentEl) {
            ui.updateParentVisuals(oldParentEl);
            // If old parent's children container is now empty, remove it.
            if (oldContainer.classList.contains('note-children') && oldContainer.children.length === 0) {
                oldContainer.remove();
                oldParentEl.classList.remove('has-children'); // Update visual indicator
            }
        }
        // Update new parent, only if it's different from old parent, or if it's a root container
        if (newParentEl && newParentEl !== oldParentEl) {
            ui.updateParentVisuals(newParentEl);
        } else if (!newParentEl && newContainer === domRefs.notesContainer) {
            // Dropped into root, no specific parent item to update, but ensure old parent (if any) is updated.
            // This is covered if oldParentEl exists.
        }
        
        // If the note was moved to the root, and the original container was a children container that is now empty
        if (!newParentId && oldContainer.classList.contains('note-children') && oldContainer.children.length === 0) {
            // This check is redundant if oldParentEl logic above is comprehensive.
            // However, ensuring the direct parent of oldContainer is updated if it exists.
            const parentOfOldContainer = oldContainer.parentElement?.closest('.note-item');
            if (parentOfOldContainer) {
                 ui.updateParentVisuals(parentOfOldContainer);
                 if (oldContainer.children.length === 0) { // Double check
                    oldContainer.remove();
                    parentOfOldContainer.classList.remove('has-children');
                 }
            }
        }
        
        console.log(`[HANDLE_NOTE_DROP] Successfully updated note ${updatedNote.id}. New parent: ${updatedNote.parent_note_id}, New order: ${updatedNote.order_index}`);


    } catch (error) {
        console.error('Error updating note position on server:', error);
        // Show user-friendly error message
        const feedback = document.createElement('div');
        feedback.className = 'copy-feedback'; // Reuse existing class, maybe add an error variant
        feedback.style.background = 'var(--color-error, #dc2626)'; // Use CSS variable for error color
        feedback.textContent = `Failed to save position: ${error.message}`;
        document.body.appendChild(feedback);
        setTimeout(() => feedback.remove(), 3000);

        // Revert the DOM change by moving the item back
        // This is tricky because Sortable already moved it. We might need to re-render from original data.
        // For now, a simple revert based on old indices:
        // Revert based on oldSortableIndex
        if (oldContainer !== newContainer) {
            oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]);
        } else {
            // If same container, newSortableIndex might be off by 1 after item removal for revert.
            // This logic attempts to place it back correctly.
            if (oldSortableIndex < newSortableIndex) { // Item was moved down
                oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex]);
            } else { // Item was moved up
                oldContainer.insertBefore(evt.item, oldContainer.children[oldSortableIndex + 1]);
            }
        }
        // Ideally, after reverting, also re-fetch and re-render notes for consistency
        if (window.currentPageId && typeof window.ui !== 'undefined' && typeof window.ui.displayNotes === 'function') {
            const pageData = await window.notesAPI.getPageData(window.currentPageId);
            window.notesForCurrentPage = pageData.notes;
            window.ui.displayNotes(pageData.notes, window.currentPageId);
        } else if (window.currentPageId) {
            console.warn("ui.displayNotes not available for error recovery. State may be inconsistent until next refresh.");
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
