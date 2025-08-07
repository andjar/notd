/**
 * UI Module for Note Element specific functionalities
 * Handles DOM manipulation and rendering for note items
 * @module ui/note-elements
 */

import { domRefs } from './dom-refs.js';
import { renderNote } from './note-renderer.js';
import { calculateOrderIndex } from '../app/order-index-service.js';
import { setNotesForCurrentPage } from '../app/state.js';
import { pageCache } from '../app/page-cache.js';
import { provideBecomeParentFeedback } from '../app/note-actions.js';

window.renderNote = renderNote;

/**
 * Displays notes in the container
 * @param {Array} notesData - Array of note objects
 * @param {number} pageId - Current page ID
 */
export function displayNotes(notesData, pageId) {
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) {
        console.error('Notes container not found');
        return;
    }
    
    // Ensure notesData is an array (handle null/undefined)
    const safeNotesData = Array.isArray(notesData) ? notesData : [];
    
    // Clear the container first (removes "Loading page..." message)
    notesContainer.innerHTML = '';
    
    if (safeNotesData.length === 0) {
        // Update Alpine.js with empty array and update state
        setNotesForCurrentPage([]);
        if (notesContainer && notesContainer.__x) {
            notesContainer.__x.getUnobservedData().notes = [];
        }
        // Show empty state message
        notesContainer.innerHTML = '<p class="no-notes-message">No notes on this page yet. Click the + button to add your first note.</p>';
        return;
    }

    const sortedNotes = [...safeNotesData].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    setNotesForCurrentPage(sortedNotes);
    
    // Update Alpine.js data for future use
    if (notesContainer && notesContainer.__x) {
        notesContainer.__x.getUnobservedData().notes = noteTree;
    }
    
    // Render notes using traditional DOM approach since Alpine.js template is not implemented yet
    renderNotesInContainer(noteTree, notesContainer);
    
    // Initialize drag and drop after rendering
    setTimeout(() => {
        initializeDragAndDrop();
    }, 0);
}

/**
 * Renders notes in the container using traditional DOM manipulation
 * @param {Array} noteTree - Tree structure of notes
 * @param {HTMLElement} container - Container element to render notes in
 */
function renderNotesInContainer(noteTree, container) {
    noteTree.forEach(note => {
        const noteElement = renderNote(note, 0);
        if (noteElement) {
            container.appendChild(noteElement);
        }
    });
    
    // Replace feather icons after rendering
    if (typeof feather !== 'undefined') {
        setTimeout(() => {
            try {
                feather.replace();
            } catch (error) {
                console.warn('Feather icon replacement failed:', error.message);
            }
        }, 0);
    }
}

/**
 * Adds a new note element to the DOM in its correct sorted position.
 * @param {Object} noteData - The data for the new note.
 * @returns {HTMLElement|null} The newly created note element.
 */
export function addNoteElement(noteData) {
    if (!noteData) return null;
    
    // Update the data structures
    window.notesForCurrentPage.push(noteData);
    const sortedNotes = [...window.notesForCurrentPage].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) return null;
    
    // Update Alpine.js data if available
    if (notesContainer && notesContainer.__x) {
        notesContainer.__x.getUnobservedData().notes = noteTree;
    }
    
    // **FIX**: Create actual DOM element for immediate visual feedback
    const noteElement = renderNote(noteData, 0);
    if (!noteElement) return null;
    
    // Clear "no notes" message if it exists
    const noNotesMessage = notesContainer.querySelector('.no-notes-message');
    if (noNotesMessage) {
        noNotesMessage.remove();
    }
    
    // Insert the note in the correct position based on order_index
    if (!noteData.parent_note_id) {
        // Root note - find correct position among root notes
        const rootNotes = Array.from(notesContainer.children).filter(el => 
            el.classList.contains('note-item') && !el.closest('.note-children')
        );
        
        let insertPosition = 0;
        for (let i = 0; i < rootNotes.length; i++) {
            const existingNoteId = rootNotes[i].dataset.noteId;
            const existingNote = window.notesForCurrentPage.find(n => String(n.id) === String(existingNoteId));
            if (existingNote && (existingNote.order_index || 0) > (noteData.order_index || 0)) {
                insertPosition = i;
                break;
            }
            insertPosition = i + 1;
        }
        
        if (insertPosition >= rootNotes.length) {
            notesContainer.appendChild(noteElement);
        } else {
            notesContainer.insertBefore(noteElement, rootNotes[insertPosition]);
        }
    } else {
        // Child note - find parent and insert
        const parentElement = notesContainer.querySelector(`.note-item[data-note-id="${noteData.parent_note_id}"]`);
        if (parentElement) {
            let childrenContainer = parentElement.querySelector('.note-children');
            if (!childrenContainer) {
                childrenContainer = document.createElement('div');
                childrenContainer.className = 'note-children';
                parentElement.appendChild(childrenContainer);
                parentElement.classList.add('has-children');
                
                // **ENHANCEMENT**: Provide immediate visual feedback for new parent
                provideBecomeParentFeedback(parentElement);
            }
            childrenContainer.appendChild(noteElement);
        } else {
            // Fallback: add as root note
            notesContainer.appendChild(noteElement);
        }
    }
    
    // Initialize drag and drop for the new element
    setTimeout(() => {
        initializeDragAndDrop();
    }, 0);
    
    return noteElement;
}

/**
 * Removes a note element from the DOM.
 * @param {string} noteId - The ID of the note to remove.
 */
export function removeNoteElement(noteId) {
    // Update data structures
    window.notesForCurrentPage = window.notesForCurrentPage.filter(note => String(note.id) !== String(noteId));
    const sortedNotes = [...window.notesForCurrentPage].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) return;
    
    // Update Alpine.js data if available
    if (notesContainer.__x) {
        notesContainer.__x.getUnobservedData().notes = noteTree;
    }
    
    // **FIX**: Actually remove the DOM element for immediate visual feedback
    const noteElement = notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (noteElement) {
        // Handle parent cleanup if this was the last child
        const parent = noteElement.closest('.note-children');
        noteElement.remove();
        
        if (parent && parent.children.length === 0) {
            const parentNoteItem = parent.closest('.note-item');
            if (parentNoteItem) {
                parentNoteItem.classList.remove('has-children');
                parent.remove();
            }
        }
        
        // Show "no notes" message if no notes remain
        if (window.notesForCurrentPage.length === 0) {
            notesContainer.innerHTML = '<p class="no-notes-message">No notes on this page yet. Click the + button to add your first note.</p>';
        }
    }
}

/**
 * Builds a tree structure from flat note array
 * @param {Array} notes - Flat array of note objects
 * @param {number|null} [parentId=null] - Parent note ID
 * @returns {Array} Tree structure of notes
 */
export function buildNoteTree(notes, parentId = null) {
    if (!notes) return [];
    
    return notes
        .filter(note => (note.parent_note_id || null) == parentId)
        .sort((a, b) => (a.order_index || 0) - (b.order_index || 0))
        .map(note => ({
            ...note,
            children: buildNoteTree(notes, note.id)
        }));
}

/**
 * Initializes drag and drop functionality for notes using Sortable.js
 */
export function initializeDragAndDrop() {
    if (typeof Sortable === 'undefined') return;

    const containers = [domRefs.notesContainer, ...document.querySelectorAll('.note-children')];
    containers.forEach(container => {
        if (container && !container.classList.contains('ui-sortable')) {
            Sortable.create(container, {
                group: 'notes',
                animation: 150,
                handle: '.note-bullet',
                ghostClass: 'note-ghost',
                onEnd: handleNoteDrop
            });
        }
    });
}

window.initializeDragAndDrop = initializeDragAndDrop;

/**
 * Handles the logic after a note is dropped via drag-and-drop.
 * @param {Object} evt - The event object from Sortable.js.
 */
export async function handleNoteDrop(evt) {
    const noteId = evt.item.dataset.noteId;
    const newParentEl = evt.to.closest('.note-item');
    const newParentId = newParentEl ? newParentEl.dataset.noteId : null;

    if (newParentId === noteId || evt.item.contains(evt.to)) {
        evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
        return;
    }

    const previousEl = evt.item.previousElementSibling;
    const previousSiblingId = previousEl?.classList.contains('note-item') ? previousEl.dataset.noteId : null;

    // Find the next sibling after the dropped note
    const nextEl = evt.item.nextElementSibling;
    const nextSiblingId = nextEl?.classList.contains('note-item') ? nextEl.dataset.noteId : null;

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(
        window.notesForCurrentPage,
        newParentId,
        previousSiblingId,
        nextSiblingId
    );

    // CRITICAL FIX: Filter out the note being moved from sibling updates to prevent conflicts
    const filteredSiblingUpdates = siblingUpdates.filter(upd => String(upd.id) !== String(noteId));
    
    // Build full upsert payloads (server expects complete rows for unified upsert)
    const findNoteById = (id) => window.notesForCurrentPage.find(n => String(n.id) === String(id));
    const movedNoteFull = findNoteById(noteId);
    const movedPayload = movedNoteFull ? {
        id: movedNoteFull.id,
        page_id: movedNoteFull.page_id,
        content: movedNoteFull.content,
        parent_note_id: newParentId,
        order_index: targetOrderIndex,
        collapsed: movedNoteFull.collapsed || 0,
        internal: movedNoteFull.internal || 0
    } : { id: noteId, parent_note_id: newParentId, order_index: targetOrderIndex };

    const siblingUpserts = filteredSiblingUpdates.map(upd => {
        const sib = findNoteById(upd.id);
        if (sib) {
            return { type: 'upsert', payload: {
                id: sib.id,
                page_id: sib.page_id,
                content: sib.content,
                parent_note_id: sib.parent_note_id || null,
                order_index: upd.newOrderIndex,
                collapsed: sib.collapsed || 0,
                internal: sib.internal || 0
            }};
        }
        return { type: 'upsert', payload: { id: upd.id, order_index: upd.newOrderIndex } };
    });

    // Create a list of all operations needed for the batch update.
    const operations = [
        { type: 'upsert', payload: movedPayload },
        ...siblingUpserts
    ];
    
    // **FIX**: Store original state for potential rollback
    const originalNotesState = JSON.parse(JSON.stringify(window.notesForCurrentPage));
    const originalDOMState = {
        noteElement: evt.item,
        originalParent: evt.from,
        originalIndex: evt.oldIndex
    };
    
    // Optimistically update local state immediately
    const noteToMove = window.notesForCurrentPage.find(n => n.id == noteId);
    if(noteToMove) {
        noteToMove.parent_note_id = newParentId;
        noteToMove.order_index = targetOrderIndex;
    }
    
    filteredSiblingUpdates.forEach(upd => {
        const sib = window.notesForCurrentPage.find(n => n.id == upd.id);
        if(sib) {
            sib.order_index = upd.newOrderIndex;
        }
    });
    
    // **FIX**: Update visual hierarchy immediately without page reload
    updateNoteVisualHierarchy(evt.item, newParentId);

    try {
        // **FIX**: Use the new batch operations system with proper error handling
        const { executeBatchOperations } = await import('../app/note-actions.js');
        
        const success = await executeBatchOperations(
            originalNotesState,
            operations,
            () => {
                // Optimistic DOM updater - visual changes already done above
                console.log('[Drag Drop] Optimistic updates applied');
            },
            'Drag Drop'
        );
        
        if (!success) {
            throw new Error('One or more drag-drop operations failed on the server');
        }
        
        // **IMPROVEMENT**: Cache invalidation without full reload
        pageCache.removePage(window.currentPageName);
        
        console.log('[Drag Drop] Successfully updated note positions');
        
    } catch (error) {
        console.error("Failed to save note drop changes:", error);
        
        // **FIX**: Rollback optimistic changes on error
        window.notesForCurrentPage = originalNotesState;
        
        // Rollback DOM changes
        if (originalDOMState.originalParent && originalDOMState.noteElement) {
            const children = Array.from(originalDOMState.originalParent.children);
            if (originalDOMState.originalIndex >= children.length) {
                originalDOMState.originalParent.appendChild(originalDOMState.noteElement);
            } else {
                originalDOMState.originalParent.insertBefore(
                    originalDOMState.noteElement, 
                    children[originalDOMState.originalIndex]
                );
            }
            
            // Restore original visual hierarchy
            updateNoteVisualHierarchy(originalDOMState.noteElement, 
                originalNotesState.find(n => n.id == noteId)?.parent_note_id || null
            );
        }
        
        alert("Could not save new note positions. Changes have been reverted.");
    }
}

/**
 * **NEW**: Updates the visual hierarchy of a note without full page reload
 * @param {HTMLElement} noteElement - The note element to update
 * @param {string|null} newParentId - The new parent ID
 */
function updateNoteVisualHierarchy(noteElement, newParentId) {
    if (!noteElement) return;
    
    // Calculate new nesting level
    let nestingLevel = 0;
    if (newParentId) {
        // Count parent hierarchy
        const allNotes = window.notesForCurrentPage;
        let currentParentId = newParentId;
        while (currentParentId) {
            nestingLevel++;
            const parentNote = allNotes.find(n => String(n.id) === String(currentParentId));
            if (!parentNote) break;
            currentParentId = parentNote.parent_note_id;
        }
    }
    
    // Update CSS custom property for visual indentation
    noteElement.style.setProperty('--nesting-level', nestingLevel);
    
    // Update all descendant notes' nesting levels as well
    updateDescendantNestingLevels(noteElement, nestingLevel);
}

/**
 * **NEW**: Recursively updates nesting levels for descendant notes
 * @param {HTMLElement} noteElement - The parent note element
 * @param {number} parentNestingLevel - The parent's nesting level
 */
function updateDescendantNestingLevels(noteElement, parentNestingLevel) {
    const childrenContainer = noteElement.querySelector('.note-children');
    if (!childrenContainer) return;
    
    const childNotes = Array.from(childrenContainer.children).filter(el => 
        el.classList.contains('note-item')
    );
    
    for (const childNote of childNotes) {
        const childNestingLevel = parentNestingLevel + 1;
        childNote.style.setProperty('--nesting-level', childNestingLevel);
        updateDescendantNestingLevels(childNote, childNestingLevel);
    }
}


// These functions are not used by other modules and can be kept internal to this file or moved if needed.
function updateNoteElement() { /* placeholder if needed */ }
function moveNoteElement() { /* placeholder if needed */ }