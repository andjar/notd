/**
 * UI Module for Note Element specific functionalities
 * Handles DOM manipulation and rendering for note items
 * @module ui/note-elements
 */

import { domRefs } from './dom-refs.js';
import { renderNote } from './note-renderer.js';
import { calculateOrderIndex } from '../app/order-index-service.js';
import { setNotesForCurrentPage } from '../app/state.js';

window.renderNote = renderNote;

/**
 * Displays notes in the container
 * @param {Array} notesData - Array of note objects
 * @param {number} pageId - Current page ID
 */
export function displayNotes(notesData, pageId) {
    const notesContainer = document.getElementById('notes-container');
    
    // Ensure notesData is an array (handle null/undefined)
    const safeNotesData = Array.isArray(notesData) ? notesData : [];
    
    if (safeNotesData.length === 0) {
        // Clear the container and update Alpine.js with empty array
        setNotesForCurrentPage([]);
        if (notesContainer && notesContainer.__x) {
            notesContainer.__x.getUnobservedData().notes = [];
        }
        return;
    }

    const sortedNotes = [...safeNotesData].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    setNotesForCurrentPage(sortedNotes);
    
    if (notesContainer && notesContainer.__x) {
        // Update Alpine's notes array reactively
        notesContainer.__x.getUnobservedData().notes = noteTree;
    }
    // Drag-and-drop and feather icons are handled by Alpine lifecycle hooks
}

/**
 * Adds a new note element to the DOM in its correct sorted position.
 * @param {Object} noteData - The data for the new note.
 * @returns {HTMLElement|null} The newly created note element.
 */
export function addNoteElement(noteData) {
    if (!noteData) return null;
    window.notesForCurrentPage.push(noteData);
    const sortedNotes = [...window.notesForCurrentPage].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    const notesContainer = document.getElementById('notes-container');
    if (notesContainer && notesContainer.__x) {
        notesContainer.__x.getUnobservedData().notes = noteTree;
    }
    // Drag-and-drop and feather icons are handled by Alpine lifecycle hooks
    return null;
}

/**
 * Removes a note element from the DOM.
 * @param {string} noteId - The ID of the note to remove.
 */
export function removeNoteElement(noteId) {
    window.notesForCurrentPage = window.notesForCurrentPage.filter(note => String(note.id) !== String(noteId));
    const sortedNotes = [...window.notesForCurrentPage].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    const notesContainer = document.getElementById('notes-container');
    if (notesContainer && notesContainer.__x) {
        notesContainer.__x.getUnobservedData().notes = noteTree;
    }
    // Drag-and-drop and feather icons are handled by Alpine lifecycle hooks
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