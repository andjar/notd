/**
 * UI Module for Note Element specific functionalities
 * Handles DOM manipulation and rendering for note items
 * @module ui/note-elements
 */

import { domRefs } from './dom-refs.js';
import { renderNote } from './note-renderer.js';
import { calculateOrderIndex } from '../app/order-index-service.js';

/**
 * Displays notes in the container
 * @param {Array} notesData - Array of note objects
 * @param {number} pageId - Current page ID
 */
export function displayNotes(notesData, pageId) {
    domRefs.notesContainer.innerHTML = '';
    if (!notesData || notesData.length === 0) return;

    const sortedNotes = [...notesData].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    
    noteTree.forEach(note => {
        domRefs.notesContainer.appendChild(renderNote(note, 0));
    });
    
    initializeDragAndDrop();
    if (typeof feather !== 'undefined') feather.replace();
}

/**
 * Adds a new note element to the DOM in its correct sorted position.
 * @param {Object} noteData - The data for the new note.
 * @returns {HTMLElement|null} The newly created note element.
 */
export function addNoteElement(noteData) {
    if (!noteData) return null;

    const parentId = noteData.parent_note_id || null;
    const parentEl = parentId ? document.querySelector(`.note-item[data-note-id="${parentId}"]`) : domRefs.notesContainer;

    if (!parentEl) {
        console.error("Could not find parent element for new note.", { parentId });
        return null;
    }
    
    let parentContainer;
    let nestingLevel;

    if (parentId) {
        parentContainer = parentEl.querySelector('.note-children');
        if (!parentContainer) {
            parentContainer = document.createElement('div');
            parentContainer.className = 'note-children';
            parentEl.appendChild(parentContainer);
            parentEl.classList.add('has-children');
            // Re-render arrow if needed
            const controls = parentEl.querySelector('.note-controls');
            if(controls && !controls.querySelector('.note-collapse-arrow')) {
                const arrow = document.createElement('span');
                arrow.className = 'note-collapse-arrow';
                arrow.innerHTML = `<i data-feather="chevron-right"></i>`;
                controls.insertBefore(arrow, controls.firstChild);
                feather.replace();
            }
        }
        nestingLevel = window.ui.getNestingLevel(parentEl) + 1;
    } else {
        parentContainer = domRefs.notesContainer;
        nestingLevel = 0;
    }

    const newNoteEl = renderNote(noteData, nestingLevel);

    const siblings = Array.from(parentContainer.children)
        .filter(child => child.classList.contains('note-item'))
        .map(el => ({ element: el, orderIndex: parseInt(window.notesForCurrentPage.find(n => n.id == el.dataset.noteId)?.order_index, 10) || 0 }));

    const nextSibling = siblings.find(sib => sib.orderIndex > noteData.order_index);

    if (nextSibling) {
        parentContainer.insertBefore(newNoteEl, nextSibling.element);
    } else {
        parentContainer.appendChild(newNoteEl);
    }

    return newNoteEl;
}


/**
 * Removes a note element from the DOM.
 * @param {string} noteId - The ID of the note to remove.
 */
export function removeNoteElement(noteId) {
    const noteElement = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (noteElement) {
        noteElement.remove();
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

    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(
        window.notesForCurrentPage,
        newParentId,
        previousSiblingId,
        null
    );
    
    // Create a list of all operations needed for the batch update.
    const operations = [
        { type: 'update', payload: { id: noteId, parent_note_id: newParentId, order_index: targetOrderIndex } },
        ...siblingUpdates.map(upd => ({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }))
    ];
    
    // Optimistically update local state before calling API
    const noteToMove = window.notesForCurrentPage.find(n => n.id == noteId);
    if(noteToMove) {
        noteToMove.parent_note_id = newParentId;
        noteToMove.order_index = targetOrderIndex;
    }
    siblingUpdates.forEach(upd => {
        const sib = window.notesForCurrentPage.find(n => n.id == upd.id);
        if(sib) sib.order_index = upd.newOrderIndex;
    });

    try {
        await window.notesAPI.batchUpdateNotes(operations);
        // On success, we can just do a light DOM update if needed, but a reload is safest.
        await window.loadPage(window.currentPageName, false, false); // Reload without adding to history
    } catch (error) {
        console.error("Failed to save note drop changes:", error);
        alert("Could not save new note positions. Reverting.");
        await window.loadPage(window.currentPageName, false, false);
    }
}


// These functions are not used by other modules and can be kept internal to this file or moved if needed.
function updateNoteElement() { /* placeholder if needed */ }
function moveNoteElement() { /* placeholder if needed */ }