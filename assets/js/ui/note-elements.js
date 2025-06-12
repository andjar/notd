// FILE: assets/js/ui/note-elements.js

/**
 * UI Module for managing the note list structure in the DOM.
 */
import { domRefs } from './dom-refs.js';
import { renderNote } from './note-renderer.js';
import { notesAPI } from '../api_client.js';
import { calculateOrderIndex } from '../app/order-index-service.js';

// --- Display & Tree Building ---

export function displayNotes(notesData, pageId) {
    if (!domRefs.notesContainer) return;
    domRefs.notesContainer.innerHTML = '';

    if (!notesData || notesData.length === 0) return;

    const sortedNotes = [...notesData].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);

    noteTree.forEach(note => {
        domRefs.notesContainer.appendChild(renderNote(note, 0));
    });

    if (typeof feather !== 'undefined') feather.replace();
    initializeDragAndDrop();
}

export function buildNoteTree(notes, parentId = null) {
    if (!notes) return [];
    return notes
        .filter(note => (note.parent_note_id || null) === parentId)
        .sort((a, b) => (a.order_index || 0) - (b.order_index || 0))
        .map(note => ({
            ...note,
            children: buildNoteTree(notes, note.id)
        }));
}

// --- DOM Manipulation ---

export function addNoteElement(noteData) {
    const parentEl = noteData.parent_note_id
        ? document.querySelector(`.note-item[data-note-id="${noteData.parent_note_id}"] .note-children`)
        : domRefs.notesContainer;

    if (!parentEl) {
        console.error("Could not find parent container for new note.", noteData);
        return null;
    }

    const nestingLevel = noteData.parent_note_id ? (getNestingLevel(parentEl.closest('.note-item')) + 1) : 0;
    const newNoteEl = renderNote(noteData, nestingLevel);

    const siblings = Array.from(parentEl.children).filter(child => child.matches('.note-item'));
    const insertBeforeEl = siblings.find(sibling => {
        const siblingNote = window.notesForCurrentPage.find(n => n.id == sibling.dataset.noteId);
        return siblingNote && siblingNote.order_index > noteData.order_index;
    });

    if (insertBeforeEl) {
        parentEl.insertBefore(newNoteEl, insertBeforeEl);
    } else {
        parentEl.appendChild(newNoteEl);
    }
    
    if (noteData.parent_note_id) {
        updateParentVisuals(parentEl.closest('.note-item'));
    }

    if (typeof feather !== 'undefined') feather.replace();
    return newNoteEl;
}

export function removeNoteElement(noteId) {
    const noteEl = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (noteEl) {
        const parentContainer = noteEl.parentElement;
        noteEl.remove();
        if (parentContainer && parentContainer.classList.contains('note-children')) {
            updateParentVisuals(parentContainer.closest('.note-item'));
        }
    }
}

export function updateNoteElement(noteId, updatedData) {
    const noteEl = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteEl) return;
    const nestingLevel = getNestingLevel(noteEl);
    const newNoteEl = renderNote(updatedData, nestingLevel);
    noteEl.replaceWith(newNoteEl);
    if (typeof feather !== 'undefined') feather.replace();
}

export function updateParentVisuals(parentNoteElement) {
    if (!parentNoteElement) return;
    const childrenContainer = parentNoteElement.querySelector('.note-children');
    const hasChildren = childrenContainer && childrenContainer.children.length > 0;
    parentNoteElement.classList.toggle('has-children', hasChildren);
    let arrowEl = parentNoteElement.querySelector('.note-collapse-arrow');
    if (hasChildren && !arrowEl) {
        arrowEl = document.createElement('span');
        arrowEl.className = 'note-collapse-arrow';
        arrowEl.innerHTML = `<i data-feather="chevron-right"></i>`;
        const controlsEl = parentNoteElement.querySelector('.note-controls');
        controlsEl?.insertBefore(arrowEl, controlsEl.firstChild);
        if (typeof feather !== 'undefined') feather.replace();
    } else if (!hasChildren && arrowEl) {
        arrowEl.remove();
    }
}

function getNestingLevel(noteElement) {
    if (!noteElement) return 0;
    return parseInt(noteElement.style.getPropertyValue('--nesting-level') || '0');
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

export {
    moveNoteElement,
    getNestingLevel,
    initializeDragAndDrop
};