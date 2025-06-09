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
    const newIndex = evt.newIndex;
    const oldIndex = evt.oldIndex;

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

    // Calculate the correct order index based on its new siblings in the data model
    let targetOrderIndex = 0;
    const siblingsInNewParent = window.notesForCurrentPage.filter(note =>
        (note.parent_note_id ? String(note.parent_note_id) : null) === (newParentId ? String(newParentId) : null) &&
        String(note.id) !== String(noteId) // Exclude the note being moved
    ).sort((a, b) => a.order_index - b.order_index);

    if (siblingsInNewParent.length === 0) {
        targetOrderIndex = 0;
    } else if (newIndex >= siblingsInNewParent.length) {
        // If dropped at the end, use the highest order_index + 1
        targetOrderIndex = Math.max(...siblingsInNewParent.map(n => n.order_index)) + 1;
    } else {
        // If dropped between items, calculate the average of surrounding order indices
        const prevSibling = siblingsInNewParent[newIndex - 1];
        const nextSibling = siblingsInNewParent[newIndex];
        
        if (!prevSibling) {
            // Dropped at the beginning
            targetOrderIndex = nextSibling.order_index - 1;
        } else if (!nextSibling) {
            // Dropped at the end
            targetOrderIndex = prevSibling.order_index + 1;
        } else {
            // Dropped between two items
            targetOrderIndex = Math.floor((prevSibling.order_index + nextSibling.order_index) / 2);
        }
    }
    
    // Optimistically update UI (SortableJS already did this)
    // Prepare data for API call
    const updateData = {
        page_id: window.currentPageId, // Add page_id to the update payload
        content: currentNoteData.content || '', // Ensure content is always present
        parent_note_id: newParentId,
        order_index: targetOrderIndex
    };

    console.log('Attempting to update note position:', { noteId, ...updateData });

    try {
        const updatedNote = await window.notesAPI.updateNote(noteId, updateData);
        console.log('Note position updated successfully on server:', updatedNote);

        // Update local data cache accurately
        currentNoteData.parent_note_id = updatedNote.parent_note_id;
        currentNoteData.order_index = updatedNote.order_index;
        currentNoteData.updated_at = updatedNote.updated_at; // Sync timestamp

        // Potentially re-fetch all notes for the page to ensure perfect order and hierarchy
        // This is a trade-off: ensures consistency but can be a bit slower.
        // For now, we'll rely on the optimistic update and correct data sync.
        // If inconsistencies appear, re-enable full refresh:
        if (window.currentPageId && !window.isDragInProgress) {
            // Optimistic UI update is done by SortableJS.
            // Local data (notesForCurrentPage) should be updated with canonical data from server.
            const noteIndex = window.notesForCurrentPage.findIndex(n => String(n.id) === String(updatedNote.id));
            if (noteIndex > -1) {
                // Preserve local children, update other fields from server response
                const localChildren = window.notesForCurrentPage[noteIndex].children;
                window.notesForCurrentPage[noteIndex] = {...updatedNote, children: localChildren};
            } else {
                // Note was not found, this case should ideally not happen if currentNoteData was found earlier
                window.notesForCurrentPage.push(updatedNote);
            }
            window.notesForCurrentPage.sort((a,b) => a.order_index - b.order_index); // Ensure order

            // Update the specific element if its content/properties might have changed server-side
            // (unlikely for a pure move, but good for robustness)
            updateNoteElement(updatedNote.id, updatedNote);

            // Update visuals of old and new parent (if not already handled by moveNoteElement logic)
            // This needs to be done carefully. Sortable has moved the item.
            // We need to find the old parent from originalNoteData and new parent from updatedNote.
            // For now, this is simplified; ui.moveNoteElement handles this if used directly.
            // If not using ui.moveNoteElement, explicit calls to updateParentVisuals for old/new parents are needed.
            const oldParentEl = evt.from.closest('.note-item');
            const newParentEl = evt.to.closest('.note-item');
            if(oldParentEl) ui.updateParentVisuals(oldParentEl);
            if(newParentEl && newParentEl !== oldParentEl) ui.updateParentVisuals(newParentEl);


        } else if (window.isDragInProgress) {
             // If another drag started, a full refresh might be safer once all operations settle.
             // For now, we rely on the server providing consistent data for the next full load.
            console.log("Drag in progress, skipping targeted UI update for note drop, full refresh might occur later.");
        }


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
        if (oldContainer !== newContainer) {
            oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
        } else {
            if (oldIndex < newIndex) {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
            } else {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex + 1]);
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
