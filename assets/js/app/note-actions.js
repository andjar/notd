/**
 * @file Manages note-related actions such as creation, updates, deletion,
 * indentation, and interaction handling. It orchestrates optimistic UI updates
 * and communication with the backend API using batch operations.
 */

// Get Alpine store reference
function getAppStore() {
    return window.Alpine.store('app');
}

import { calculateOrderIndex } from './order-index-service.js';
import { notesAPI } from '../api_client.js';
import { debounce, handleAutocloseBrackets, insertTextAtCursor, encrypt } from '../utils.js';
import { generateUuidV7 } from '../utils/uuid-utils.js';
import { ui } from '../ui.js';
import { pageCache } from './page-cache.js';

const notesContainer = document.querySelector('#notes-container');

// --- Note Data Accessors ---
export function getNoteDataById(noteId) {
    if (!noteId) return null;
    const appStore = getAppStore();
    return appStore.notes.find(n => String(n.id) === String(noteId));
}

export function getNoteElementById(noteId) {
    if (!notesContainer || !noteId) return null;
    return notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
}

function _finalizeNewNote(clientTempId, noteFromServer) {
    if (!noteFromServer || !noteFromServer.id) {
        console.error("[_finalizeNewNote] Invalid note data from server for temp ID:", clientTempId, noteFromServer);
        return;
    }
    const permanentId = noteFromServer.id;
    const appStore = getAppStore();
    const noteIndex = appStore.notes.findIndex(n => String(n.id) === String(clientTempId));
    if (noteIndex > -1) {
        appStore.notes[noteIndex] = { ...appStore.notes[noteIndex], ...noteFromServer, id: permanentId };
    }
    const tempNoteEl = getNoteElementById(clientTempId);
    if (tempNoteEl) {
        tempNoteEl.dataset.noteId = permanentId;
        tempNoteEl.querySelector('.note-content')?.setAttribute('data-note-id', permanentId);
        tempNoteEl.querySelector('.note-bullet')?.setAttribute('data-note-id', permanentId);
    }
}

/**
 * Provides immediate visual feedback when a note becomes a parent
 * Shows the collapse arrow briefly to indicate the note now has children
 * @param {HTMLElement} noteElement - The note element that became a parent
 */
function provideBecomeParentFeedback(noteElement) {
    if (!noteElement) return;
    
    const collapseArrow = noteElement.querySelector('.note-collapse-arrow');
    if (collapseArrow) {
        // Temporarily make the collapse arrow visible for immediate feedback
        collapseArrow.style.opacity = '0.8';
        collapseArrow.style.transform = 'scale(1.1)';
        collapseArrow.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        
        // Reset to normal state after a brief moment
        setTimeout(() => {
            collapseArrow.style.opacity = '';
            collapseArrow.style.transform = '';
            collapseArrow.style.transition = '';
        }, 800);
    }
    
    // Also briefly highlight the thread line
    // Add a temporary class for thread line animation
    noteElement.classList.add('new-parent-feedback');
    setTimeout(() => {
        noteElement.classList.remove('new-parent-feedback');
    }, 1000);
}

let batchInProgress = false;
let batchQueue = [];

async function executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, userActionName) {
    if (!operations || operations.length === 0) return true;
    
    console.log(`[${userActionName}] Starting batch operation with ${operations.length} operations`);
    console.log(`[${userActionName}] Original notes count:`, originalNotesState?.length || 0);
    
    // **SIMPLIFIED RACE CONDITION FIX**: Just queue operations if one is in progress
    if (batchInProgress) {
        console.log(`[${userActionName}] Batch already in progress, queuing operation`);
        return new Promise((resolve, reject) => {
            batchQueue.push({ originalNotesState, operations, optimisticDOMUpdater, userActionName, resolve, reject });
        });
    }
    
    batchInProgress = true;
    ui.updateSaveStatusIndicator('pending');
    let success = false;
    
    try {
        if (typeof optimisticDOMUpdater === 'function') {
            console.log(`[${userActionName}] Running optimistic DOM updater`);
            optimisticDOMUpdater();
        }
        
        console.log("Batch operations to send:", operations);
        const batchResponse = await notesAPI.batchUpdateNotes(operations);
        let allSubOperationsSucceeded = true;
        
        if (batchResponse && Array.isArray(batchResponse)) {
            batchResponse.forEach(opResult => {
                if (opResult.status === 'error') {
                    allSubOperationsSucceeded = false;
                    console.error(`[${userActionName} BATCH] Server reported sub-operation error:`, opResult);
                    if (opResult.message) {
                        console.error(`[${userActionName} BATCH] Error message:`, opResult.message);
                    }
                    if (opResult.id) {
                        console.error(`[${userActionName} BATCH] Failed operation ID:`, opResult.id);
                    }
                } else if (opResult.type === 'update' && opResult.note) {
                    const appStore = getAppStore();
                    appStore.updateNote(opResult.note);
                }
            });
        } else {
            allSubOperationsSucceeded = false;
            console.error(`[${userActionName} BATCH] Invalid response structure from server:`, batchResponse);
        }
        
        if (!allSubOperationsSucceeded) {
            // **DATA LOSS FIX**: Since server operations are atomic (all succeed or all fail),
            // we should revert to the state before this batch operation if any operation fails
            console.warn(`[${userActionName}] Some operations failed, reverting to state before batch operation`);
            console.log(`[${userActionName}] Current notes count before revert:`, getAppStore().notes.length);
            
            // Revert to the state before this batch operation
            if (originalNotesState) {
                const appStore = getAppStore();
                appStore.setNotes(originalNotesState);
                ui.displayNotes(appStore.notes, appStore.currentPageId);
                console.log(`[${userActionName}] Reverted to state before batch operation`);
                console.log(`[${userActionName}] Notes count after revert:`, appStore.notes.length);
            }
            
            const errorDetails = (batchResponse || [])
                .filter(r => r.status === 'error')
                .map(r => `${r.type} operation: ${r.message || 'Unknown error'}`)
                .join(', ');
            throw new Error(`One or more sub-operations in '${userActionName}' failed: ${errorDetails}`);
        }
        
        success = true;
        ui.updateSaveStatusIndicator('saved');
        console.log(`[${userActionName}] Batch operation completed successfully`);
        
        // **CACHE INVALIDATION**: Remove the current page from cache so fresh data is loaded on next visit
        const appStore = getAppStore();
        if (appStore.currentPageName) {
            pageCache.removePage(appStore.currentPageName);
            console.log(`[CACHE] Invalidated cache for page: ${appStore.currentPageName}`);
        }
    } catch (error) {
        console.error(`[${userActionName}] Batch operation failed:`, error);
        const errorMessage = error.message || `Batch operation '${userActionName}' failed.`;
        alert(`${errorMessage} Reverting local changes.`);
        ui.updateSaveStatusIndicator('error');
        
        // **DATA LOSS FIX**: Since server operations are atomic, revert to state before batch operation
        if (originalNotesState) {
            console.log(`[${userActionName}] Reverting due to error. Current notes count:`, getAppStore().notes.length);
            const appStore = getAppStore();
            appStore.setNotes(originalNotesState);
            ui.displayNotes(appStore.notes, appStore.currentPageId);
            console.log(`[${userActionName}] Reverted to state before batch operation due to error`);
            console.log(`[${userActionName}] Notes count after error revert:`, appStore.notes.length);
        }
        
        success = false;
    } finally {
        batchInProgress = false;
        
        // Process the next batch in the queue, if any
        if (batchQueue.length > 0) {
            const nextBatch = batchQueue.shift();
            // Call recursively, and resolve/reject the promise for the queued batch
            executeBatchOperations(
                nextBatch.originalNotesState,
                nextBatch.operations,
                nextBatch.optimisticDOMUpdater,
                nextBatch.userActionName
            ).then(nextBatch.resolve).catch(nextBatch.reject);
        }
    }
    
    return success;
}

/**
 * A centralized handler for various note-related actions.
 * @param {string} action - The action to perform (e.g., 'create', 'focus').
 * @param {string|null} noteId - The ID of the note to act upon.
 */
export async function handleNoteAction(action, noteId) {
    switch (action) {
        case 'create':
            await handleAddRootNote();
            break;
        case 'focus':
            if (noteId) {
                const noteElement = getNoteElementById(noteId);
                const contentDiv = noteElement?.querySelector('.note-content');
                if (contentDiv) {
                    ui.switchToEditMode(contentDiv);
                }
            }
            break;
        default:
            console.warn(`Unknown note action: ${action}`);
    }
}

// --- Note Saving Logic ---
async function _saveNoteToServer(noteId, rawContent) {
    if (!getNoteDataById(noteId)) return null;
    
    const appStore = getAppStore();
    const password = appStore.pagePassword;
    let contentToSave = rawContent;
    let isEncrypted = false;
    if (password) {
        // **FIX**: Corrected argument order for encrypt function. It's (password, plaintext).
        contentToSave = encrypt(password, rawContent);
        isEncrypted = true;
    }
    
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));

    const operations = [{
        type: 'update',
        payload: { id: noteId, content: contentToSave }
    }];

    const success = await executeBatchOperations(originalNotesState, operations, null, "Save Note Content");
    return success ? getNoteDataById(noteId) : null;
}

export async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return null;
    
    const rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
    contentDiv.dataset.rawContent = rawContent;
    return await _saveNoteToServer(noteId, rawContent);
}

export const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    if (!getNoteDataById(noteId)) return;
    const contentDiv = noteEl.querySelector('.note-content');
    if (!contentDiv) return;
    
    const currentRawContent = contentDiv.dataset.rawContent || '';
    await _saveNoteToServer(noteId, currentRawContent);
}, 1000);

// --- Event Handlers for Structural Changes ---
export async function handleAddRootNote() {
    const appStore = getAppStore();
    if (!appStore.currentPageName) return;
    
    const noteId = generateUuidV7(); // Generate UUID directly instead of temporary ID
    const rootNotes = appStore.notes.filter(n => !n.parent_note_id);
    const lastRootNote = rootNotes.sort((a, b) => (a.order_index || 0) - (b.order_index || 0)).pop();
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, null, lastRootNote?.id || null, null);
    
    const validSiblingUpdates = siblingUpdates;
    
    const optimisticNewNote = { id: noteId, page_name: appStore.currentPageName, content: '', parent_note_id: null, order_index: targetOrderIndex, properties: {} };
    appStore.addNote(optimisticNewNote);
    
    // **DATA LOSS FIX**: Capture original state AFTER optimistic updates are applied
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    
    const password = appStore.pagePassword;
    let contentForServer = '';
    let isEncrypted = false;
    if (password) {
        // **FIX**: Corrected argument order for encrypt function.
        contentForServer = encrypt(password, '');
        isEncrypted = true;
    }

    const operations = [{ type: 'create', payload: { id: noteId, page_name: appStore.currentPageName, content: contentForServer, parent_note_id: null, order_index: targetOrderIndex } }];
    validSiblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    
    validSiblingUpdates.forEach(upd => {
        const note = getNoteDataById(upd.id);
        if(note) note.order_index = upd.newOrderIndex;
    });

    const optimisticDOMUpdater = () => {
        const noteEl = ui.addNoteElement(optimisticNewNote);
        const contentDiv = noteEl?.querySelector('.note-content');
        if (contentDiv) {
            contentDiv.dataset.rawContent = '';
            ui.switchToEditMode(contentDiv);
        }
    };
    
    // **ENHANCED RACE CONDITION FIX**: Track this note creation
    const creationPromise = executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Add Root Note");
    
    await creationPromise;
}

async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (e.shiftKey) {
        return;
    }
    e.preventDefault();

    // **FIX**: Save the current note's content first
    const currentContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
    contentDiv.dataset.rawContent = currentContent;
    
    // Update the note data in the store
    const note = getNoteDataById(noteData.id);
    if (note) {
        note.content = currentContent;
    }

    // No need to check for temporary IDs since we use real UUIDs now
    const appStore = getAppStore();
    const noteId = generateUuidV7(); // Generate UUID directly
    
    // **FIX**: Create a sibling note (same parent as current note), not a child
    const parentNoteId = noteData.parent_note_id; // Use the current note's parent, not the current note itself
    
    const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, parentNoteId, noteData.id, null);
    
    const optimisticNewNote = { id: noteId, page_name: appStore.currentPageName, content: '', parent_note_id: parentNoteId, order_index: targetOrderIndex, properties: {} };
    appStore.addNote(optimisticNewNote);
    
    // **DATA LOSS FIX**: Capture original state AFTER optimistic updates are applied
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    
    const password = appStore.pagePassword;
    let contentForServer = '';
    let isEncrypted = false;
    if (password) {
        // **FIX**: Corrected argument order for encrypt function.
        contentForServer = encrypt(password, '');
        isEncrypted = true;
    }

    // **FIX**: Include both the current note update and the new note creation
    const operations = [
        { type: 'update', payload: { id: noteData.id, content: currentContent } },
        { type: 'create', payload: { id: noteId, page_name: appStore.currentPageName, content: contentForServer, parent_note_id: parentNoteId, order_index: targetOrderIndex } }
    ];
    siblingUpdates.forEach(upd => operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } }));
    
    siblingUpdates.forEach(upd => {
        const note = getNoteDataById(upd.id);
        if(note) note.order_index = upd.newOrderIndex;
    });

    const optimisticDOMUpdater = () => {
        const noteEl = createOptimisticNoteElement(optimisticNewNote, parentNoteId);
        if (noteEl) {
            // Insert the new sibling note after the current note in the DOM
            noteItem.parentNode.insertBefore(noteEl, noteItem.nextSibling);
            
            const contentDiv = noteEl.querySelector('.note-content');
            if (contentDiv) {
                contentDiv.dataset.rawContent = '';
                ui.switchToEditMode(contentDiv);
                
                // Focus the new note
                contentDiv.focus();
                const range = document.createRange();
                const sel = window.getSelection();
                range.setStart(contentDiv, 0);
                range.collapse(true);
                sel.removeAllRanges();
                sel.addRange(range);
            }
        }
    };
    
    // **ENHANCED RACE CONDITION FIX**: Track this note creation
    const creationPromise = executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Create Sibling Note");
    
    await creationPromise;
}

// **NEW**: Helper function to create optimistic note element without full re-render
function createOptimisticNoteElement(noteData, parentId) {
    const noteItemEl = document.createElement('div');
    noteItemEl.className = 'note-item';
    noteItemEl.dataset.noteId = noteData.id;
    
    // Calculate nesting level
    const nestingLevel = calculateNestingLevel(parentId, getAppStore().notes);
    noteItemEl.style.setProperty('--nesting-level', nestingLevel);

    // Controls section
    const controlsEl = document.createElement('div');
    controlsEl.className = 'note-controls';

    // Bullet
    const bulletEl = document.createElement('span');
    bulletEl.className = 'note-bullet';
    bulletEl.dataset.noteId = noteData.id;
    controlsEl.appendChild(bulletEl);

    // Content wrapper
    const contentWrapperEl = document.createElement('div');
    contentWrapperEl.className = 'note-content-wrapper';

    const contentEl = document.createElement('div');
    contentEl.className = 'note-content edit-mode';
    contentEl.dataset.placeholder = 'Type to add content...';
    contentEl.dataset.noteId = noteData.id;
    contentEl.dataset.rawContent = '';
    contentEl.contentEditable = 'true';
    
    // **ENHANCEMENT**: Add event listeners for markdown rendering
    contentEl.addEventListener('blur', () => {
        // Switch to rendered mode when losing focus (if there's content)
        if (contentEl.textContent.trim()) {
            switchToRenderedMode(contentEl);
        }
    });
    
    // **ENHANCEMENT**: Add input handler for real-time markdown preview
    contentEl.addEventListener('input', (e) => {
        const rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentEl));
        contentEl.dataset.rawContent = rawContent;
        
        // Update the note data in the store
        const note = getNoteDataById(noteData.id);
        if (note) {
            note.content = rawContent;
        }
    });
    
    contentWrapperEl.appendChild(contentEl);

    // Header row
    const noteHeaderEl = document.createElement('div');
    noteHeaderEl.className = 'note-header-row';
    noteHeaderEl.appendChild(controlsEl);
    noteHeaderEl.appendChild(contentWrapperEl);
    noteItemEl.appendChild(noteHeaderEl);

    // Children container (empty)
    const childrenContainerEl = document.createElement('div');
    childrenContainerEl.className = 'note-children';
    noteItemEl.appendChild(childrenContainerEl);

    return noteItemEl;
}

// **NEW**: Helper function to switch to rendered mode (copied from note-renderer.js)
function switchToRenderedMode(contentEl) {
    if (contentEl.classList.contains('rendered-mode')) return;

    const rawTextValue = ui.getRawTextWithNewlines(contentEl);
    const newContent = ui.normalizeNewlines(rawTextValue);
    
    contentEl.dataset.rawContent = newContent;
    
    contentEl.classList.remove('edit-mode');
    contentEl.classList.add('rendered-mode');
    contentEl.contentEditable = false;
    contentEl.style.whiteSpace = '';

    if (newContent.trim()) {
        const tempDiv = document.createElement('div');
        tempDiv.textContent = newContent;
        const decodedContent = tempDiv.textContent;
        
        // **ENHANCEMENT**: Use the proper markdown rendering function
        if (typeof window.parseAndRenderContent === 'function') {
            contentEl.innerHTML = window.parseAndRenderContent(decodedContent);
        } else {
            // Fallback to basic HTML if parseAndRenderContent is not available
            contentEl.innerHTML = parseBasicMarkdown(decodedContent);
        }
    } else {
        contentEl.innerHTML = '';
    }
}

// **NEW**: Fallback markdown parser for optimistic notes
function parseBasicMarkdown(text) {
    if (!text) return '';
    
    let html = text;
    
    // Handle page links [[page name]]
    html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => {
        const trimmedName = pageName.trim();
        return `<span class="page-link-bracket">[[</span><a href="page.php?page=${encodeURIComponent(trimmedName)}" class="page-link">${trimmedName}</a><span class="page-link-bracket">]]</span>`;
    });
    
    // Handle basic markdown if marked.js is available
    if (typeof marked !== 'undefined' && marked.parse) {
        try {
            html = marked.parse(html, {
                breaks: true,
                gfm: true,
                smartypants: true,
                sanitize: false,
                smartLists: true
            });
        } catch (e) {
            console.warn('Marked.js parsing error:', e);
        }
    } else {
        // Basic fallback
        html = html.replace(/\n/g, '<br>');
    }
    
    return html;
}

async function handleTabKey(e, noteItem, noteData) {
    e.preventDefault();
    
    // No need to check for temporary IDs since we use real UUIDs now
    await saveNoteImmediately(noteItem); // **FIX**: Ensure content is saved before structural change

    const appStore = getAppStore();
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    let operations = [];
    let newParentId = null;
    let targetOrderIndex = null; // **FIX**: Declare targetOrderIndex in proper scope

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const oldParentNote = getNoteDataById(noteData.parent_note_id);
        if (!oldParentNote) return;
        
        // No need to check for temporary parent IDs since we use real UUIDs now
        newParentId = oldParentNote.parent_note_id;

        const { targetOrderIndex: calculatedOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, newParentId, String(oldParentNote.id), null);
        targetOrderIndex = calculatedOrderIndex; // **FIX**: Assign to outer scope variable
        
        operations.push({ type: 'update', payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => {
            operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } });
        });

    } else { // Indent
        const siblings = appStore.notes.filter(n => String(n.parent_note_id ?? '') === String(noteData.parent_note_id ?? '')).sort((a,b) => a.order_index - b.order_index);
        const currentNoteIndexInSiblings = siblings.findIndex(n => String(n.id) === String(noteData.id));
        if (currentNoteIndexInSiblings < 1) return;
        
        const newParentNote = siblings[currentNoteIndexInSiblings - 1];
        
        // No need to check for temporary parent IDs since we use real UUIDs now
        newParentId = String(newParentNote.id);
        
        // **FIX**: Calculate the correct position for the indented note
        // Find the existing children of the new parent
        const newParentChildren = appStore.notes.filter(n => String(n.parent_note_id) === String(newParentId)).sort((a,b) => a.order_index - b.order_index);
        
        // Find the position where the note should be inserted
        // It should be positioned after the new parent's last child, or at the beginning if no children exist
        let previousSiblingId = null;
        let nextSiblingId = null;
        
        if (newParentChildren.length > 0) {
            // Insert at the end of the new parent's children
            const lastChild = newParentChildren[newParentChildren.length - 1];
            previousSiblingId = String(lastChild.id);
            nextSiblingId = null;
        } else {
            // No existing children, insert at the beginning
            previousSiblingId = null;
            nextSiblingId = null;
        }
        
        const { targetOrderIndex: calculatedOrderIndex, siblingUpdates } = calculateOrderIndex(appStore.notes, newParentId, previousSiblingId, nextSiblingId);
        targetOrderIndex = calculatedOrderIndex; // **FIX**: Assign to outer scope variable

        operations.push({ type: 'update', payload: { id: noteData.id, parent_note_id: newParentId, order_index: targetOrderIndex } });
        siblingUpdates.forEach(upd => {
            operations.push({ type: 'update', payload: { id: upd.id, order_index: upd.newOrderIndex } });
        });
    }
    
    // **OPTIMIZATION**: Immediate visual feedback without delays
    const optimisticDOMUpdater = () => {
        // Update the note's visual indentation immediately
        const noteElement = getNoteElementById(noteData.id);
        if (noteElement && targetOrderIndex !== null) {
            // Calculate new nesting level
            const newNestingLevel = calculateNestingLevel(newParentId, appStore.notes);
            
            // Update CSS custom property for immediate visual feedback
            noteElement.style.setProperty('--nesting-level', newNestingLevel);
            
            // Move the note element to its new position in the DOM
            moveNoteElementInDOM(noteElement, newParentId, targetOrderIndex);
            
            // Update the note's data in the store
            const noteToMove = getNoteDataById(noteData.id);
            if (noteToMove) {
                noteToMove.parent_note_id = newParentId;
                noteToMove.order_index = targetOrderIndex;
            }
            
            // Update sibling order indices immediately
            operations.forEach(op => {
                if (op.type === 'update') {
                    const note = getNoteDataById(op.payload.id);
                    if (note) note.order_index = op.payload.order_index;
                }
            });
            
            // **IMPROVEMENT**: Refocus immediately without delay
            const newContentDiv = noteElement.querySelector('.note-content');
            if (newContentDiv) {
                ui.switchToEditMode(newContentDiv);
                // Focus with proper cursor positioning
                newContentDiv.focus();
                const range = document.createRange();
                const sel = window.getSelection();
                range.selectNodeContents(newContentDiv);
                range.collapse(false); // Collapse to end
                sel.removeAllRanges();
                sel.addRange(range);
            }
        }
    };
    
    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, e.shiftKey ? "Outdent Note" : "Indent Note");
}

// **NEW**: Helper function to calculate nesting level
function calculateNestingLevel(parentId, notes) {
    if (!parentId) return 0;
    
    let level = 0;
    let currentParentId = parentId;
    
    while (currentParentId) {
        const parentNote = notes.find(n => String(n.id) === String(currentParentId));
        if (!parentNote) break;
        
        level++;
        currentParentId = parentNote.parent_note_id;
    }
    
    return level;
}

// **NEW**: Helper function to move note element in DOM without full re-render
function moveNoteElementInDOM(noteElement, newParentId, targetOrderIndex) {
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) return;

    // Remove from current position (move the whole subtree)
    // Instead of just noteElement.remove(), move the note and its children as a block
    const subtreeFragment = document.createDocumentFragment();
    subtreeFragment.appendChild(noteElement);

    // Move all children (if any)
    const childrenContainer = noteElement.querySelector('.note-children');
    if (childrenContainer && childrenContainer.children.length > 0) {
        // Move all child notes as part of the fragment
        // (they are already inside noteElement, so this is just for clarity)
    }

    if (!newParentId) {
        // Moving to root level
        const rootNotes = Array.from(notesContainer.children).filter(el => 
            el.classList.contains('note-item') && 
            !el.closest('.note-children')
        );
        
        if (targetOrderIndex >= rootNotes.length) {
            notesContainer.appendChild(subtreeFragment);
        } else {
            const targetElement = rootNotes[targetOrderIndex];
            notesContainer.insertBefore(subtreeFragment, targetElement);
        }
    } else {
        // Moving to a specific parent
        const parentElement = getNoteElementById(newParentId);
        if (!parentElement) {
            // Fallback: append to root
            notesContainer.appendChild(subtreeFragment);
            return;
        }
        
        let childrenContainer = parentElement.querySelector('.note-children');
        if (!childrenContainer) {
            // Create children container if it doesn't exist
            childrenContainer = document.createElement('div');
            childrenContainer.className = 'note-children';
            parentElement.appendChild(childrenContainer);
            
            // Add has-children class to parent
            parentElement.classList.add('has-children');
            
            // **ENHANCEMENT**: Provide immediate visual feedback for new parent
            provideBecomeParentFeedback(parentElement);
        }
        
        const existingChildren = Array.from(childrenContainer.children).filter(el => 
            el.classList.contains('note-item')
        );
        
        if (targetOrderIndex >= existingChildren.length) {
            childrenContainer.appendChild(subtreeFragment);
        } else {
            const targetElement = existingChildren[targetOrderIndex];
            childrenContainer.insertBefore(subtreeFragment, targetElement);
        }
    }

    // After moving, update nesting level for the note and all descendants
    const newNestingLevel = calculateNestingLevel(newParentId, getAppStore().notes);
    updateSubtreeNestingLevels(noteElement, newNestingLevel);
}

// **NEW**: Recursively update --nesting-level for a note and all its descendants
function updateSubtreeNestingLevels(noteElement, nestingLevel) {
    noteElement.style.setProperty('--nesting-level', nestingLevel);
    const childrenContainer = noteElement.querySelector('.note-children');
    if (childrenContainer) {
        const childNotes = Array.from(childrenContainer.children).filter(el => el.classList.contains('note-item'));
        for (const child of childNotes) {
            updateSubtreeNestingLevels(child, nestingLevel + 1);
        }
    }
}

async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    const appStore = getAppStore();
    if ((contentDiv.dataset.rawContent || contentDiv.textContent).trim() !== '') return;
    const children = appStore.notes.filter(n => String(n.parent_note_id) === String(noteData.id));
    if (children.length > 0) return;

    e.preventDefault();
    let focusTargetEl = noteItem.previousElementSibling || getNoteElementById(noteData.parent_note_id);
    
    const noteIdToDelete = noteData.id;

    appStore.removeNoteById(noteIdToDelete);
    
    // **DATA LOSS FIX**: Capture original state AFTER optimistic updates are applied
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));
    
    const operations = [{ type: 'delete', payload: { id: noteIdToDelete } }];
    
    const optimisticDOMUpdater = () => {
        ui.removeNoteElement(noteIdToDelete);
        if (focusTargetEl) {
            const contentToFocus = focusTargetEl.querySelector('.note-content');
            if(contentToFocus) ui.switchToEditMode(contentToFocus);
        }
    };
    await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Delete Note (Backspace)");
}

function handleArrowKey(e, contentDiv) {
    // Only intercept ArrowUp and ArrowDown for custom navigation
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault();
    
    // **OPTIMIZATION**: More efficient arrow key navigation inspired by treehouse
    const allVisibleNotesContent = Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden) .note-content'));
    const currentIndex = allVisibleNotesContent.indexOf(contentDiv);
    let nextIndex = -1;

    if (e.key === 'ArrowUp' && currentIndex > 0) {
        nextIndex = currentIndex - 1;
    } else if (e.key === 'ArrowDown' && currentIndex < allVisibleNotesContent.length - 1) {
        nextIndex = currentIndex + 1;
    }

    if (nextIndex !== -1) {
        const nextContent = allVisibleNotesContent[nextIndex];
        ui.switchToEditMode(nextContent);
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(nextContent);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

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

    if (precedingText2Chars === ':t') { replacementText = '{tag::}'; cursorOffsetAfterReplace = 1; }
    else if (precedingText2Chars === ':d') { const today = new Date().toISOString().slice(0, 10); replacementText = `{date::${today}}`; cursorOffsetAfterReplace = 1; }
    else if (precedingText2Chars === ':r') { const now = new Date().toISOString(); replacementText = `{timestamp::${now}}`; cursorOffsetAfterReplace = 1; }
    else if (precedingText2Chars === ':k') { replacementText = '{keyword::}'; cursorOffsetAfterReplace = 1; }

    if (replacementText) {
        e.preventDefault();
        range.setStart(textNode, cursorPos - 2);
        range.deleteContents();
        insertTextAtCursor(replacementText, cursorOffsetAfterReplace);
        shortcutHandled = true;
    }

    if (shortcutHandled) {
        const noteItemForShortcut = contentDiv.closest('.note-item');
        if (noteItemForShortcut) {
            debouncedSaveNote(noteItemForShortcut);
        }
        return true;
    }
    return false;
}

export async function handleNoteKeyDown(e) {
    if (!e.target.matches('.note-content')) return;
    const noteItem = e.target.closest('.note-item');
    const contentDiv = e.target;
    if (!noteItem || !contentDiv) return;

    const noteData = getNoteDataById(noteItem.dataset.noteId);
    if (!noteData) return;

    if (await handleShortcutExpansion(e, contentDiv)) return;
    if (handleAutocloseBrackets(e)) return;
    
    // **NEW**: Treehouse-inspired shortcuts
    if (e.ctrlKey || e.metaKey) {
        switch (e.key) {
            case 'Enter':
                e.preventDefault();
                // **NEW**: Ctrl+Enter creates a child note (treehouse style)
                await handleCreateChildNote(e, noteItem, noteData, contentDiv);
                return;
            case 'ArrowUp':
                e.preventDefault();
                // **NEW**: Ctrl+Up moves note up in hierarchy
                await handleMoveNoteUp(e, noteItem, noteData);
                return;
            case 'ArrowDown':
                e.preventDefault();
                // **NEW**: Ctrl+Down moves note down in hierarchy
                await handleMoveNoteDown(e, noteItem, noteData);
                return;
        }
    }
    
    switch (e.key) {
        case 'Enter': return await handleEnterKey(e, noteItem, noteData, contentDiv);
        case 'Tab': return await handleTabKey(e, noteItem, noteData);
        case 'Backspace': return await handleBackspaceKey(e, noteItem, noteData, contentDiv);
        case 'ArrowUp':
        case 'ArrowDown': return handleArrowKey(e, contentDiv); // Only intercept up/down
        // ArrowLeft and ArrowRight now use default browser behavior
    }
}

// **NEW**: Treehouse-inspired function to create child note
async function handleCreateChildNote(e, noteItem, noteData, contentDiv) {
    // **FIX**: Save the current note's content first
    const currentContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
    contentDiv.dataset.rawContent = currentContent;
    
    // Update the note data in the store
    const note = getNoteDataById(noteData.id);
    if (note) {
        note.content = currentContent;
    }

    // No need to check for temporary IDs since we use real UUIDs now
    const appStore = getAppStore();
    const noteId = generateUuidV7(); // Generate UUID directly
    
    // Calculate order index for the new child
    const existingChildren = appStore.notes.filter(n => String(n.parent_note_id) === String(noteData.id)).sort((a, b) => a.order_index - b.order_index);
    const targetOrderIndex = existingChildren.length > 0 ? existingChildren[existingChildren.length - 1].order_index + 1 : 0;
    
    const optimisticNewNote = { 
        id: noteId, 
        page_name: appStore.currentPageName, 
        content: '', 
        parent_note_id: noteData.id, 
        order_index: targetOrderIndex, 
        properties: {} 
    };
    appStore.addNote(optimisticNewNote);

    // **DATA LOSS FIX**: Capture original state AFTER optimistic updates are applied
    const originalNotesState = JSON.parse(JSON.stringify(appStore.notes));

    const password = appStore.pagePassword;
    let contentForServer = '';
    let isEncrypted = false;
    if (password) {
        contentForServer = encrypt(password, '');
        isEncrypted = true;
    }
    
    // **FIX**: Include both the current note update and the new note creation
    const operations = [
        { type: 'update', payload: { id: noteData.id, content: currentContent } },
        { type: 'create', payload: { id: noteId, page_name: appStore.currentPageName, content: contentForServer, parent_note_id: noteData.id, order_index: targetOrderIndex } }
    ];

    const optimisticDOMUpdater = () => {
        // Create and insert child note element
        const newNoteEl = createOptimisticNoteElement(optimisticNewNote, noteData.id);
        if (newNoteEl) {
            // Find or create children container
            let childrenContainer = noteItem.querySelector('.note-children');
            if (!childrenContainer) {
                childrenContainer = document.createElement('div');
                childrenContainer.className = 'note-children';
                noteItem.appendChild(childrenContainer);
                noteItem.classList.add('has-children');
                
                // **ENHANCEMENT**: Provide immediate visual feedback for new parent
                provideBecomeParentFeedback(noteItem);
            }
            
            // Insert the new child
            childrenContainer.appendChild(newNoteEl);
            
            // **ENHANCEMENT**: Ensure immediate focus and cursor positioning
            const newContentDiv = newNoteEl.querySelector('.note-content');
            if (newContentDiv) {
                // Switch to edit mode immediately
                ui.switchToEditMode(newContentDiv);
                
                // Set cursor to beginning of the new note immediately
                const range = document.createRange();
                const sel = window.getSelection();
                
                // Clear any existing selection
                sel.removeAllRanges();
                
                // Position cursor at the start of the content
                range.setStart(newContentDiv, 0);
                range.collapse(true);
                
                // Apply the selection
                sel.addRange(range);
                
                // Ensure the new note is visible
                newContentDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                
                // Focus the element
                newContentDiv.focus();
            }
        }
    };
    
    // **ENHANCED RACE CONDITION FIX**: Track this note creation
    const creationPromise = executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, "Create Child Note");
    
    await creationPromise;
}

// **NEW**: Treehouse-inspired function to move note up in hierarchy
async function handleMoveNoteUp(e, noteItem, noteData) {
    // This would implement moving a note up in the hierarchy
    // Similar to outdenting but with more sophisticated logic
    console.log('Move note up - to be implemented');
}

// **NEW**: Treehouse-inspired function to move note down in hierarchy
async function handleMoveNoteDown(e, noteItem, noteData) {
    // This would implement moving a note down in the hierarchy
    // Similar to indenting but with more sophisticated logic
    console.log('Move note down - to be implemented');
}

export async function handleTaskCheckboxClick(e) {
    // This function is now delegated to `note-renderer.js` to keep it with the element creation logic.
    // The call remains in app.js, but the implementation is now in the UI layer.
    // This is a placeholder or can be removed if app.js calls the UI function directly.
}

// Export utility function for visual feedback
export { provideBecomeParentFeedback };