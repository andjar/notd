/**
 * @file Manages note-related actions such as creation, updates, deletion,
 * indentation, and interaction handling. It orchestrates optimistic UI updates
 * and communication with the backend API using unified upsert operations.
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

// **PERFORMANCE OPTIMIZATION**: Cache for DOM queries and calculations
const domCache = new Map();
const nestingLevelCache = new Map();

// **PERFORMANCE OPTIMIZATION**: Debounced DOM updates
let pendingDOMUpdates = new Set();
let domUpdateScheduled = false;

function scheduleDOMUpdate(updateFn) {
    pendingDOMUpdates.add(updateFn);
    if (!domUpdateScheduled) {
        domUpdateScheduled = true;
        requestAnimationFrame(() => {
            const updates = Array.from(pendingDOMUpdates);
            pendingDOMUpdates.clear();
            domUpdateScheduled = false;
            
            // Batch all DOM updates
            updates.forEach(update => update());
        });
    }
}

// **PERFORMANCE OPTIMIZATION**: Optimized nesting level calculation with caching
function calculateNestingLevel(parentId, notes) {
    if (!parentId) return 0;
    
    // Check cache first
    const cacheKey = `nesting_${parentId}`;
    if (nestingLevelCache.has(cacheKey)) {
        return nestingLevelCache.get(cacheKey);
    }
    
    let level = 0;
    let currentParentId = parentId;
    
    while (currentParentId) {
        const parentNote = notes.find(n => String(n.id) === String(currentParentId));
        if (!parentNote) break;
        
        level++;
        currentParentId = parentNote.parent_note_id;
    }
    
    // Cache the result
    nestingLevelCache.set(cacheKey, level);
    return level;
}

// **PERFORMANCE OPTIMIZATION**: Clear cache when notes change
function clearNestingCache() {
    nestingLevelCache.clear();
}

// **PERFORMANCE OPTIMIZATION**: Throttling mechanism for rapid key sequences
let lastKeyPressTime = 0;
const KEY_THROTTLE_MS = 150; // 150ms throttle for rapid key presses

// **PERFORMANCE OPTIMIZATION**: Track recent operations to prevent conflicts
const recentOperations = new Map(); // noteId -> { timestamp, operationType }

function isThrottled() {
    const now = Date.now();
    if (now - lastKeyPressTime < KEY_THROTTLE_MS) {
        return true;
    }
    lastKeyPressTime = now;
    return false;
}

function isNoteRecentlyOperated(noteId, operationType, thresholdMs = 500) {
    const key = `${noteId}_${operationType}`;
    const lastOp = recentOperations.get(key);
    if (!lastOp) return false;
    
    const now = Date.now();
    if (now - lastOp.timestamp < thresholdMs) {
        return true;
    }
    
    // Clean up old entries
    recentOperations.delete(key);
    return false;
}

function markNoteOperation(noteId, operationType) {
    const key = `${noteId}_${operationType}`;
    recentOperations.set(key, { timestamp: Date.now(), operationType });
}

// **PERFORMANCE OPTIMIZATION**: Enhanced state cloning with better performance
function cloneNotesState(notes) {
    // **FIX**: Convert Alpine.js reactive objects to plain objects before cloning
    // This prevents DataCloneError with Alpine.js reactive properties
    const plainNotes = notes.map(note => ({
        id: note.id,
        page_name: note.page_name,
        content: note.content,
        parent_note_id: note.parent_note_id,
        order_index: note.order_index,
        properties: note.properties ? { ...note.properties } : {},
        created_at: note.created_at,
        updated_at: note.updated_at
    }));
    
    // **PERFORMANCE**: Use structuredClone for better performance than JSON.parse/stringify
    if (typeof structuredClone !== 'undefined') {
        try {
            return structuredClone(plainNotes);
        } catch (error) {
            // Silently fall back; structuredClone can fail on some engines when proxies are present
            return JSON.parse(JSON.stringify(plainNotes));
        }
    }
    // Fallback to JSON method for older browsers
    return JSON.parse(JSON.stringify(plainNotes));
}

// **UNIFIED OPERATION**: Simplified batch operation execution
async function executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, userActionName) {
    try {
        // Apply optimistic DOM updates
        if (optimisticDOMUpdater) {
            optimisticDOMUpdater();
        }

        // Send operations to server
        const response = await notesAPI.batchUpdateNotes(operations);
        
        // Update notes state with server response
        if (response && Array.isArray(response)) {
            const appStore = getAppStore();
            response.forEach(result => {
                if (result.status === 'success' && result.note) {
                    const existingIndex = appStore.notes.findIndex(n => String(n.id) === String(result.note.id));
                    if (existingIndex !== -1) {
                        appStore.notes[existingIndex] = result.note;
                    } else {
                        appStore.notes.push(result.note);
                    }
                }
            });
        }

        // Clear caches
        clearNestingCache();
        pageCache.clearAll();

        return response;
    } catch (error) {
        console.error(`Error in batch operations for ${userActionName}:`, error);
        
        // Revert to original state on error
        const appStore = getAppStore();
        appStore.notes = cloneNotesState(originalNotesState);
        
        // No re-apply of optimistic updater here to avoid duplicating DOM state
        
        throw error;
    }
}

// **PERFORMANCE OPTIMIZATION**: Optimized DOM element retrieval with caching
export function getNoteElementById(noteId) {
    if (!notesContainer || !noteId) return null;
    
    // Check cache first
    const cacheKey = `element_${noteId}`;
    if (domCache.has(cacheKey)) {
        const cached = domCache.get(cacheKey);
        if (cached && document.contains(cached)) {
            return cached;
        }
        // Remove stale cache entry
        domCache.delete(cacheKey);
    }
    
    const element = notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (element) {
        domCache.set(cacheKey, element);
    }
    return element;
}

// **PERFORMANCE OPTIMIZATION**: Batch DOM style updates
function batchUpdateStyles(elements, styleUpdates) {
    scheduleDOMUpdate(() => {
        elements.forEach(({ element, updates }) => {
            Object.entries(updates).forEach(([property, value]) => {
                element.style.setProperty(property, value);
            });
        });
    });
}

// **PERFORMANCE OPTIMIZATION**: Optimized subtree nesting update
function updateSubtreeNestingLevels(noteElement, nestingLevel) {
    const updates = [];
    
    function collectUpdates(element, level) {
        updates.push({
            element,
            updates: { '--nesting-level': level }
        });
        
        const childrenContainer = element.querySelector('.note-children');
        if (childrenContainer) {
            const childNotes = Array.from(childrenContainer.children).filter(el => el.classList.contains('note-item'));
            childNotes.forEach(child => collectUpdates(child, level + 1));
        }
    }
    
    collectUpdates(noteElement, nestingLevel);
    batchUpdateStyles(updates);
}

// **PERFORMANCE OPTIMIZATION**: Optimized DOM movement with minimal reflows
function moveNoteElementInDOM(noteElement, newParentId, targetOrderIndex) {
    const notesContainer = document.getElementById('notes-container');
    if (!notesContainer) return;

    // **PERFORMANCE**: Use DocumentFragment to minimize reflows
    const subtreeFragment = document.createDocumentFragment();
    subtreeFragment.appendChild(noteElement);

    // **PERFORMANCE**: Batch DOM operations
    scheduleDOMUpdate(() => {
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

        // **PERFORMANCE**: Update nesting levels in next frame
        const newNestingLevel = calculateNestingLevel(newParentId, getAppStore().notes);
        updateSubtreeNestingLevels(noteElement, newNestingLevel);
    });
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

// **PERFORMANCE**: Optimized function to update only affected notes instead of full re-render
function updateAffectedNotesOnly(originalNotesState, operations) {
    const affectedNoteIds = new Set();
    
    // Collect all affected note IDs
    operations.forEach(op => {
        if (op.payload && op.payload.id) {
            affectedNoteIds.add(op.payload.id);
        }
    });
    
    // Update only the affected notes in the DOM
    affectedNoteIds.forEach(noteId => {
        const noteElement = getNoteElementById(noteId);
        if (noteElement) {
            const originalNote = originalNotesState.find(n => String(n.id) === String(noteId));
            if (originalNote) {
                // Update the note element's data attributes and visual state
                noteElement.dataset.noteId = originalNote.id;
                noteElement.style.setProperty('--nesting-level', calculateNestingLevel(originalNote.parent_note_id, originalNotesState));
                
                // Update content if it changed
                const contentEl = noteElement.querySelector('.note-content');
                if (contentEl && contentEl.dataset.rawContent !== originalNote.content) {
                    contentEl.dataset.rawContent = originalNote.content;
                    contentEl.textContent = originalNote.content;
                }
            }
        }
    });
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
    if (!noteEl) return null;
    
    const noteId = noteEl.dataset.noteId;
    if (!noteId) return null;
    
    const note = getNoteDataById(noteId);
    if (!note) return null;
    
    const rawContent = noteEl.textContent;
    if (!rawContent && note.content === rawContent) {
        return note; // No change, skip save
    }
    
    const updatedNoteData = {
        id: noteId,
        page_id: note.page_id,
        content: rawContent,
        parent_note_id: note.parent_note_id,
        order_index: note.order_index,
        collapsed: note.collapsed,
        internal: note.internal
    };
    
    const originalNotesState = cloneNotesState(getAppStore().notes);
    
    const operations = [
        { type: 'upsert', payload: updatedNoteData }
    ];
    
    try {
        await executeBatchOperations(originalNotesState, operations, null, 'Save Note');
        return getNoteDataById(noteId);
    } catch (error) {
        console.error('Error saving note:', error);
        return null;
    }
}

export const debouncedSaveNote = debounce(async (noteEl) => {
    // Intentionally disabled: we only save on blur/exit edit mode now
    return;
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
    
    // **RACE CONDITION FIX**: Mark note as pending creation with auto-cleanup
    // markNotePendingCreationWithTimeout(noteId); // Removed as per edit hint
    
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
    e.preventDefault();

    const rawContent = contentDiv.textContent;
    const { text, pos } = getContentAndCursor(contentDiv);
    const cursorPosition = pos;
    
    // Split content at cursor position
    const beforeCursor = rawContent.substring(0, cursorPosition);
    const afterCursor = rawContent.substring(cursorPosition);
    
    // Create new note data
    const newNoteId = generateUuidV7();
    const newNoteData = {
        id: newNoteId,
        page_id: noteData.page_id,
        content: afterCursor,
        parent_note_id: noteData.parent_note_id,
        order_index: noteData.order_index + 1,
        collapsed: 0,
        internal: 0
    };
    
    // Update current note content
    const updatedNoteData = {
        id: noteData.id,
        page_id: noteData.page_id,
        content: beforeCursor,
        parent_note_id: noteData.parent_note_id,
        order_index: noteData.order_index,
        collapsed: noteData.collapsed,
        internal: noteData.internal
    };
    
    const originalNotesState = cloneNotesState(getAppStore().notes);
    
    const operations = [
        { type: 'upsert', payload: updatedNoteData },
        { type: 'upsert', payload: newNoteData }
    ];

    const optimisticDOMUpdater = () => {
        // Update current note content
        contentDiv.textContent = beforeCursor;
        setContentAndCursor(contentDiv, beforeCursor, cursorPosition);
        
        // Create new note element
        const newNoteElement = createOptimisticNoteElement(newNoteData, noteData.parent_note_id);
        noteItem.after(newNoteElement);
        
        // Update order indices for subsequent notes
        const subsequentNotes = Array.from(notesContainer.querySelectorAll('.note-item'))
            .filter(el => {
                const elNoteId = el.dataset.noteId;
                return elNoteId !== noteData.id && elNoteId !== newNoteId;
            })
            .filter(el => {
                const elNoteData = getNoteDataById(el.dataset.noteId);
                return elNoteData && elNoteData.order_index > noteData.order_index;
            });
        
        subsequentNotes.forEach((el, index) => {
            const elNoteData = getNoteDataById(el.dataset.noteId);
            if (elNoteData) {
                elNoteData.order_index = noteData.order_index + 2 + index;
            }
        });
    };
    
    try {
        await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, 'Enter Key');
        
        // Focus the new note after a short delay
        setTimeout(() => {
            const newNoteElement = document.querySelector(`[data-note-id="${newNoteId}"]`);
            if (newNoteElement) {
                const newContentDiv = newNoteElement.querySelector('.note-content');
                if (newContentDiv) {
                    setContentAndCursor(newContentDiv, newContentDiv.textContent, 0);
                }
            }
        }, 50);
        
        markNoteOperation(noteData.id, 'enter');
    } catch (error) {
        console.error('Error handling Enter key:', error);
    }
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

// **PERFORMANCE OPTIMIZATION**: Selective state updates
function updateNotesState(notes, updates) {
    const updatedNotes = [...notes];
    
    updates.forEach(update => {
        const index = updatedNotes.findIndex(n => String(n.id) === String(update.id));
        if (index !== -1) {
            updatedNotes[index] = { ...updatedNotes[index], ...update };
        }
    });
    
    return updatedNotes;
}

// **PERFORMANCE OPTIMIZATION**: Optimized tab key handling
async function handleTabKey(e, noteItem, noteData) {
    e.preventDefault();
    
    const currentParentId = noteData.parent_note_id;
    const currentOrderIndex = noteData.order_index;
    
    // Find the previous note at the same level
    const previousNote = getAppStore().notes.find(n => 
        n.parent_note_id === currentParentId && 
        n.order_index === currentOrderIndex - 1
    );
    
    if (!previousNote) return; // Can't indent if no previous note
    
    const newNoteData = {
        id: noteData.id,
        page_id: noteData.page_id,
        content: noteData.content,
        parent_note_id: previousNote.id, // Make it a child of the previous note
        order_index: 0, // Will be calculated by the server
        collapsed: noteData.collapsed,
        internal: noteData.internal
    };
    
    const originalNotesState = cloneNotesState(getAppStore().notes);
    
    const operations = [
        { type: 'upsert', payload: newNoteData }
    ];
    
    const optimisticDOMUpdater = () => {
        // Move the note element in DOM
        moveNoteElementInDOM(noteItem, previousNote.id, 0);
        
        // Update nesting levels
        const nestingLevel = calculateNestingLevel(previousNote.id, getAppStore().notes);
        updateSubtreeNestingLevels(noteItem, nestingLevel + 1);
    };
    
    try {
        await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, 'Tab Key');
        
        // Focus the note after a short delay
        setTimeout(() => {
            const newContentDiv = noteItem.querySelector('.note-content');
            if (newContentDiv) {
                newContentDiv.focus();
            }
        }, 50);
        
        markNoteOperation(noteData.id, 'tab');
    } catch (error) {
        console.error('Error handling Tab key:', error);
    }
}

// Duplicate functions removed - using optimized versions above

async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    const { text: rawContent, pos: cursorPosition } = getContentAndCursor(contentDiv);
    
    // Only handle backspace at the beginning of the line
    if (cursorPosition > 0) return;
    
    // Only handle if this note has a parent (can be outdented)
    if (!noteData.parent_note_id) return;
    
    e.preventDefault();
    
    const parentNote = getNoteDataById(noteData.parent_note_id);
    if (!parentNote) return;
    
    const newNoteData = {
        id: noteData.id,
        page_id: noteData.page_id,
        content: noteData.content,
        parent_note_id: parentNote.parent_note_id, // Move to parent's level
        order_index: parentNote.order_index + 1, // Place after parent
        collapsed: noteData.collapsed,
        internal: noteData.internal
    };
    
    const originalNotesState = cloneNotesState(getAppStore().notes);
    
    const operations = [
        { type: 'upsert', payload: newNoteData }
    ];
    
    const optimisticDOMUpdater = () => {
        // Move the note element in DOM
        moveNoteElementInDOM(noteItem, parentNote.parent_note_id, parentNote.order_index + 1);
        
        // Update nesting levels
        const nestingLevel = calculateNestingLevel(parentNote.parent_note_id, getAppStore().notes);
        updateSubtreeNestingLevels(noteItem, nestingLevel);
    };
    
    try {
        await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, 'Backspace Key');
    } catch (error) {
        console.error('Error handling Backspace key:', error);
    }
}

// Shift+Tab handler: outdent current note (move to parent's level if possible)
async function handleShiftTabKey(e, noteItem, noteData, contentDiv) {
    // If already at root, nothing to do
    if (!noteData.parent_note_id) return;
    const parentNote = getNoteDataById(noteData.parent_note_id);
    if (!parentNote) return;

    e.preventDefault();

    const newNoteData = {
        id: noteData.id,
        page_id: noteData.page_id,
        content: noteData.content,
        parent_note_id: parentNote.parent_note_id || null,
        order_index: (parentNote.order_index ?? 0) + 1,
        collapsed: noteData.collapsed,
        internal: noteData.internal
    };

    const originalNotesState = cloneNotesState(getAppStore().notes);
    const operations = [{ type: 'upsert', payload: newNoteData }];
    
    const optimisticDOMUpdater = () => {
        // Move in DOM to parent's level after the parent
        moveNoteElementInDOM(noteItem, parentNote.parent_note_id || null, (parentNote.order_index ?? 0) + 1);
        const nestingLevel = calculateNestingLevel(parentNote.parent_note_id || null, getAppStore().notes);
        updateSubtreeNestingLevels(noteItem, nestingLevel);
    };

    try {
        await executeBatchOperations(originalNotesState, operations, optimisticDOMUpdater, 'Shift+Tab Key');
        // Refocus
        setTimeout(() => {
            const newContentDiv = noteItem.querySelector('.note-content');
            if (newContentDiv) newContentDiv.focus();
        }, 50);
        markNoteOperation(noteData.id, 'tab');
    } catch (error) {
        console.error('Error handling Shift+Tab key:', error);
    }
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
    // Require the ':' to be at start of line or preceded by whitespace to avoid accidental expansions
    const charBeforeColon = cursorPos >= 3 ? textContent.charAt(cursorPos - 3) : ' ';
    if (!/\s/.test(charBeforeColon)) {
        return false;
    }
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
    const noteItem = e.target.closest('.note-item');
    if (!noteItem) return;

    const noteData = getNoteDataById(noteItem.dataset.noteId);
    if (!noteData) return;

    const contentDiv = noteItem.querySelector('.note-content');
    if (!contentDiv) return;

    // **PERFORMANCE**: Throttle rapid key presses
    if (isThrottled()) {
        e.preventDefault();
        return;
    }

        switch (e.key) {
            case 'Enter':
            // Shift+Enter should insert a newline in the same note
            if (e.shiftKey) {
                return; // allow default behavior
            }
            if (isNoteRecentlyOperated(noteData.id, 'enter')) {
                e.preventDefault();
                return;
            }
            return await handleEnterKey(e, noteItem, noteData, contentDiv);
        case 'Tab':
            // Shift+Tab should outdent
            if (e.shiftKey) {
                e.preventDefault();
                return await handleShiftTabKey(e, noteItem, noteData, contentDiv);
            }
            if (isNoteRecentlyOperated(noteData.id, 'tab')) {
                e.preventDefault();
                return;
            }
            return await handleTabKey(e, noteItem, noteData);
        case 'Backspace':
            return await handleBackspaceKey(e, noteItem, noteData, contentDiv);
        case 'ArrowUp':
        case 'ArrowDown':
            return handleArrowKey(e, contentDiv);
        default:
            // Handle shortcut expansions
            if (e.key.length === 1) {
                return await handleShortcutExpansion(e, contentDiv);
            }
    }
}

// Utilities for getting/setting content and caret position in contenteditable
function getContentAndCursor(contentEl) {
    const selection = window.getSelection();
    let pos = contentEl.textContent.length;
    if (selection && selection.rangeCount > 0) {
        const range = selection.getRangeAt(0);
        if (contentEl.contains(range.startContainer)) {
            // Compute position by creating a range to the start
            const preRange = range.cloneRange();
            preRange.selectNodeContents(contentEl);
            preRange.setEnd(range.startContainer, range.startOffset);
            pos = preRange.toString().length;
        }
    }
    return { text: contentEl.textContent, pos };
}

function setContentAndCursor(contentEl, text, pos) {
    contentEl.textContent = text;
    const range = document.createRange();
    const sel = window.getSelection();
    // Walk the text nodes to place caret at character offset 'pos'
    let remaining = Math.max(0, Math.min(pos, text.length));
    let targetNode = null;
    let targetOffset = 0;
    function walk(node) {
        if (remaining <= 0 || targetNode) return;
        if (node.nodeType === Node.TEXT_NODE) {
            const len = node.textContent.length;
            if (remaining <= len) {
                targetNode = node;
                targetOffset = remaining;
            }
            remaining -= len;
        } else {
            for (const child of node.childNodes) walk(child);
        }
    }
    if (contentEl.childNodes.length === 0) {
        contentEl.appendChild(document.createTextNode(text));
    }
    walk(contentEl);
    if (!targetNode) {
        // Fallback to end
        targetNode = contentEl.lastChild;
        targetOffset = targetNode && targetNode.nodeType === Node.TEXT_NODE ? targetNode.textContent.length : 0;
    }
    if (targetNode) {
        range.setStart(targetNode, targetOffset);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
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
    
    // **RACE CONDITION FIX**: Mark note as pending creation with auto-cleanup
    // markNotePendingCreationWithTimeout(noteId); // Removed as per edit hint

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

// Export batch operations for drag-and-drop
export { executeBatchOperations };