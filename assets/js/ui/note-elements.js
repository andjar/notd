/**
 * UI Module for Note Element specific functionalities
 * Handles DOM manipulation and rendering for note items
 * @module ui/note-elements
 */

import { domRefs } from './dom-refs.js';
import { renderNote } from './note-renderer.js';
import { calculateOrderIndex } from '../app/order-index-service.js';
import { setNotesForCurrentPage, syncNotesState, setSaveStatus } from '../app/state.js';
import { pageCache } from '../app/page-cache.js';
import { provideBecomeParentFeedback, acquireStructuralLock, releaseStructuralLock } from '../app/note-actions.js';

window.renderNote = renderNote;

let pendingDragOperation = null;

/**
 * Helper to update save status indicator
 * @param {string} status - 'saved', 'pending', or 'error'
 */
function updateSaveStatus(status) {
    try {
        setSaveStatus(status);
    } catch (e) {
        // Fallback if Alpine store not ready
        const indicator = document.getElementById('save-status-indicator');
        if (indicator) {
            indicator.className = `save-status-indicator status-${status}`;
        }
    }
}

// **PERFORMANCE**: Pagination constants
const INITIAL_BLOCKS_TO_SHOW = 100;
const LOAD_MORE_INCREMENT = 100;

/**
 * Displays notes in the container with pagination support for large pages
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
        setNotesForCurrentPage([]);
        notesContainer.innerHTML = '<p class="no-notes-message">No notes on this page yet. Click the + button to add your first note.</p>';
        return;
    }

    const sortedNotes = [...safeNotesData].sort((a, b) => (a.order_index || 0) - (b.order_index || 0));
    const noteTree = buildNoteTree(sortedNotes);
    setNotesForCurrentPage(sortedNotes);
    
    // **PERFORMANCE FIX**: Batch rendering and initialization with pagination
    requestAnimationFrame(() => {
        // Count total blocks (including nested notes)
        const totalBlockCount = countTotalBlocks(noteTree);
        
        if (totalBlockCount > INITIAL_BLOCKS_TO_SHOW) {
            // Render with pagination
            renderNotesWithPagination(noteTree, notesContainer, totalBlockCount);
        } else {
            // Render all notes normally
            renderNotesInContainer(noteTree, notesContainer, 0, noteTree.length);
        }
        
        // Initialize drag and drop immediately after rendering
        initializeDragAndDrop();
    });
}

/**
 * Counts total blocks in a note tree (including all nested notes)
 * @param {Array} noteTree - Tree structure of notes
 * @returns {number} Total count of blocks
 */
function countTotalBlocks(noteTree) {
    let count = 0;
    for (const note of noteTree) {
        count++; // Count this note
        if (note.children && note.children.length > 0) {
            count += countTotalBlocks(note.children); // Recursively count children
        }
    }
    return count;
}

/**
 * Renders notes with pagination support
 * @param {Array} noteTree - Tree structure of notes
 * @param {HTMLElement} container - Container element to render notes in
 * @param {number} totalBlockCount - Total number of blocks
 */
function renderNotesWithPagination(noteTree, container, totalBlockCount) {
    // Store the full tree for later loading
    container._fullNoteTree = noteTree;
    container._currentlyRenderedCount = 0;
    
    // Render initial blocks
    const { renderedCount, fragment } = renderNotesUpToLimit(noteTree, INITIAL_BLOCKS_TO_SHOW, 0);
    container._currentlyRenderedCount = renderedCount;
    container.appendChild(fragment);
    
    // Add "Load more..." button if there are more blocks
    if (renderedCount < totalBlockCount) {
        addLoadMoreButton(container, totalBlockCount);
    }
    
    // Replace feather icons
    if (typeof feather !== 'undefined') {
        try {
            feather.replace({ 'class': 'feather-icon' });
        } catch (error) {
            console.warn('Feather icon replacement failed:', error.message);
        }
    }
}

/**
 * Renders notes up to a certain limit
 * @param {Array} noteTree - Tree structure of notes
 * @param {number} limit - Maximum number of blocks to render
 * @param {number} nestingLevel - Current nesting level
 * @returns {Object} Object with renderedCount and fragment
 */
function renderNotesUpToLimit(noteTree, limit, nestingLevel = 0) {
    const fragment = document.createDocumentFragment();
    let renderedCount = 0;
    
    for (const note of noteTree) {
        if (renderedCount >= limit) break;
        
        const noteElement = renderNote(note, nestingLevel);
        if (noteElement) {
            fragment.appendChild(noteElement);
            renderedCount++;
            
            // Count children (they're already rendered inside the note)
            if (note.children && note.children.length > 0) {
                renderedCount += countTotalBlocks(note.children);
            }
        }
    }
    
    return { renderedCount, fragment };
}

/**
 * Adds a "Load more..." button to the container
 * @param {HTMLElement} container - Container element
 * @param {number} totalBlockCount - Total number of blocks
 */
function addLoadMoreButton(container, totalBlockCount) {
    const loadMoreBtn = document.createElement('button');
    loadMoreBtn.className = 'load-more-notes-btn';
    loadMoreBtn.id = 'load-more-notes-btn';
    
    const remaining = totalBlockCount - container._currentlyRenderedCount;
    const toLoad = Math.min(remaining, LOAD_MORE_INCREMENT);
    
    loadMoreBtn.innerHTML = `
        <i data-feather="chevron-down"></i>
        <span>Load ${toLoad} more blocks... (${remaining} remaining)</span>
    `;
    
    loadMoreBtn.addEventListener('click', () => {
        handleLoadMore(container, totalBlockCount);
    });
    
    container.appendChild(loadMoreBtn);
    
    // Replace feather icon for the button
    if (typeof feather !== 'undefined') {
        try {
            feather.replace({ 'class': 'feather-icon' });
        } catch (error) {
            console.warn('Feather icon replacement failed:', error.message);
        }
    }
}

/**
 * Handles loading more notes
 * @param {HTMLElement} container - Container element
 * @param {number} totalBlockCount - Total number of blocks
 */
function handleLoadMore(container, totalBlockCount) {
    const loadMoreBtn = container.querySelector('#load-more-notes-btn');
    if (loadMoreBtn) loadMoreBtn.remove();
    
    // Calculate how many more to render
    const alreadyRendered = container._currentlyRenderedCount;
    const toRender = Math.min(LOAD_MORE_INCREMENT, totalBlockCount - alreadyRendered);
    
    // Find where we left off in the tree and render more
    const fullTree = container._fullNoteTree;
    const { renderedCount, fragment } = renderMoreNotesFromTree(fullTree, alreadyRendered, toRender);
    
    container._currentlyRenderedCount += renderedCount;
    container.appendChild(fragment);
    
    // Add button again if there are still more blocks
    if (container._currentlyRenderedCount < totalBlockCount) {
        addLoadMoreButton(container, totalBlockCount);
    }
    
    // Re-initialize drag and drop for new notes
    initializeDragAndDrop();
    
    // Replace feather icons
    if (typeof feather !== 'undefined') {
        try {
            feather.replace({ 'class': 'feather-icon' });
        } catch (error) {
            console.warn('Feather icon replacement failed:', error.message);
        }
    }
}

/**
 * Renders more notes from the tree, skipping already rendered ones
 * @param {Array} noteTree - Full note tree
 * @param {number} skipCount - Number of blocks to skip
 * @param {number} renderCount - Number of blocks to render
 * @returns {Object} Object with renderedCount and fragment
 */
function renderMoreNotesFromTree(noteTree, skipCount, renderCount) {
    const fragment = document.createDocumentFragment();
    let skipped = 0;
    let rendered = 0;
    
    function traverseAndRender(notes, nestingLevel) {
        for (const note of notes) {
            // If we've skipped enough and haven't rendered enough yet
            if (skipped < skipCount) {
                skipped++;
                // Still need to skip children
                if (note.children && note.children.length > 0) {
                    traverseAndRender(note.children, nestingLevel + 1);
                }
                continue;
            }
            
            if (rendered >= renderCount) {
                return; // Stop rendering
            }
            
            const noteElement = renderNote(note, nestingLevel);
            if (noteElement) {
                fragment.appendChild(noteElement);
                rendered++;
                
                // Children are already inside the note, so count them
                if (note.children && note.children.length > 0) {
                    const childCount = countTotalBlocks(note.children);
                    rendered += childCount;
                }
            }
        }
    }
    
    traverseAndRender(noteTree, 0);
    
    return { renderedCount: rendered, fragment };
}

/**
 * Renders notes in the container using traditional DOM manipulation
 * @param {Array} noteTree - Tree structure of notes
 * @param {HTMLElement} container - Container element to render notes in
 * @param {number} startIndex - Start index (default 0)
 * @param {number} endIndex - End index (default all)
 */
function renderNotesInContainer(noteTree, container, startIndex = 0, endIndex = null) {
    // **PERFORMANCE FIX**: Use DocumentFragment for batched DOM insertion
    const fragment = document.createDocumentFragment();
    
    const notesToRender = endIndex ? noteTree.slice(startIndex, endIndex) : noteTree.slice(startIndex);
    
    notesToRender.forEach(note => {
        const noteElement = renderNote(note, 0);
        if (noteElement) {
            fragment.appendChild(noteElement);
        }
    });
    
    // Single DOM insertion (much faster than individual appends)
    container.appendChild(fragment);
    
    // **PERFORMANCE FIX**: Replace feather icons immediately without setTimeout
    if (typeof feather !== 'undefined') {
        try {
            feather.replace({ 'class': 'feather-icon' });
        } catch (error) {
            console.warn('Feather icon replacement failed:', error.message);
        }
    }
}

/**
 * Adds a new note element to the DOM in its correct sorted position.
 * @param {Object} noteData - The data for the new note.
 * @returns {HTMLElement|null} The newly created note element.
 */
export function addNoteElement(noteData) {
    if (!noteData) return null;
    
    // Guard against duplicate insertion (note may already be in the array from appStore.addNote)
    const currentNotes = window.notesForCurrentPage || [];
    if (!currentNotes.find(n => String(n.id) === String(noteData.id))) {
        currentNotes.push(noteData);
        syncNotesState(currentNotes);
    }
    
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) return null;
    
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
    
    // **PERFORMANCE FIX**: Initialize drag and drop immediately
    initializeDragAndDrop();
    
    return noteElement;
}

/**
 * Removes a note element from the DOM.
 * @param {string} noteId - The ID of the note to remove.
 */
export function removeNoteElement(noteId) {
    // Update data structures using syncNotesState to keep both stores in sync
    const filteredNotes = window.notesForCurrentPage.filter(note => String(note.id) !== String(noteId));
    syncNotesState(filteredNotes);
    
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) return;
    
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
 * Uses Sortable.get() to check if already initialized (Sortable.js stores instance on element)
 */
export function initializeDragAndDrop() {
    if (typeof Sortable === 'undefined') return;

    const containers = [domRefs.notesContainer, ...document.querySelectorAll('.note-children')];
    containers.forEach(container => {
        // **FIX**: Use Sortable.get() to properly check if already initialized
        // Sortable.js stores the instance on the element, not via a CSS class
        if (container && !Sortable.get(container)) {
            Sortable.create(container, {
                group: 'notes',
                animation: 150,
                handle: '.note-bullet',
                ghostClass: 'note-ghost',
                chosenClass: 'note-chosen',
                dragClass: 'note-drag',
                fallbackOnBody: true,
                swapThreshold: 0.65,
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

    // **DATA LOSS PREVENTION**: Prevent dropping a note into itself or its children
    if (newParentId === noteId || evt.item.contains(evt.to)) {
        evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
        return;
    }

    // Acquire structural lock to prevent concurrent operations
    if (!(await acquireStructuralLock('Drag Drop'))) {
        console.warn('[Drag Drop] Operation already in progress, reverting');
        evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
        return;
    }
    
    pendingDragOperation = noteId;

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
        console.warn(`[Drag Drop] Sibling note ${upd.id} not found in state, skipping update`);
        return null;
    }).filter(Boolean);

    // Create a list of all operations needed for the batch update.
    const operations = [
        { type: 'upsert', payload: movedPayload },
        ...siblingUpserts
    ];
    
    // **FIX**: Store original state for potential rollback
    // **PERFORMANCE**: Use shallow clone instead of deep clone (10-100x faster)
    const originalNotesState = window.notesForCurrentPage.map(note => ({ ...note }));
    const originalDOMState = {
        noteElement: evt.item,
        originalParent: evt.from,
        originalIndex: evt.oldIndex
    };
    
    // **FIX**: Update visual hierarchy immediately without page reload
    updateNoteVisualHierarchy(evt.item, newParentId);
    
    // **DATA LOSS PREVENTION**: Update save status to indicate pending operation
    updateSaveStatus('pending');

    try {
        // **FIX**: Use the new batch operations system with proper error handling
        const { executeBatchOperations } = await import('../app/note-actions.js');
        
        const optimisticDOMUpdater = () => {
            // Optimistically update BOTH window.notesForCurrentPage and Alpine store
            const appStore = window.Alpine?.store('app');
            
            // Update window.notesForCurrentPage
            const noteToMove = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
            if (noteToMove) {
                noteToMove.parent_note_id = newParentId;
                noteToMove.order_index = targetOrderIndex;
            }
            
            filteredSiblingUpdates.forEach(upd => {
                const sib = window.notesForCurrentPage.find(n => String(n.id) === String(upd.id));
                if (sib) {
                    sib.order_index = upd.newOrderIndex;
                }
            });
            
            // **FIX**: Also update Alpine store for consistency
            if (appStore) {
                const storeNote = appStore.notes.find(n => String(n.id) === String(noteId));
                if (storeNote) {
                    storeNote.parent_note_id = newParentId;
                    storeNote.order_index = targetOrderIndex;
                }
                
                filteredSiblingUpdates.forEach(upd => {
                    const storeSib = appStore.notes.find(n => String(n.id) === String(upd.id));
                    if (storeSib) {
                        storeSib.order_index = upd.newOrderIndex;
                    }
                });
            }
            
            console.log('[Drag Drop] Optimistic updates applied to both state stores');
        };
        
        const response = await executeBatchOperations(
            originalNotesState,
            operations,
            optimisticDOMUpdater,
            'Drag Drop'
        );
        
        // Check if all operations succeeded
        const hasFailures = response && Array.isArray(response) && 
            response.some(r => r.status !== 'success');
        
        if (hasFailures) {
            throw new Error('One or more drag-drop operations failed on the server');
        }
        
        console.log('[Drag Drop] Successfully updated note positions and synced state');
        
        pendingDragOperation = null;
        updateSaveStatus('saved');
        
    } catch (error) {
        console.error("Failed to save note drop changes:", error);
        
        pendingDragOperation = null;
        
        // Update save status to indicate error
        updateSaveStatus('error');
        
        // **FIX**: Rollback BOTH state stores on error using syncNotesState for atomic update
        // **PERFORMANCE**: Use shallow clone instead of deep clone
        const restoredState = originalNotesState.map(note => ({ ...note }));
        syncNotesState(restoredState);
        
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
                originalNotesState.find(n => String(n.id) === String(noteId))?.parent_note_id || null
            );
        }
        
        alert("Could not save new note positions. Changes have been reverted.");
    } finally {
        releaseStructuralLock();
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