// import { ui } from '../ui.js';

import {
    notesForCurrentPage,
    currentPageId,
    addNoteToCurrentPage,
    updateNoteInCurrentPage,
    removeNoteFromCurrentPageById,
    setNotesForCurrentPage
    // setSaveStatus, // No longer needed here as updateSaveStatusIndicator from ui.js will handle it
    // getNoteDataById, // This will be a local helper using imported notesForCurrentPage
    // setCurrentFocusedNoteId, // Not directly used by functions moved here yet, but might be needed by future note actions
} from './state.js';

// Use window.ui instead of direct import
// const { notesContainer } = ui.domRefs;
const notesContainer = document.querySelector('#notes-container');

// Assuming API objects are globally available.
// e.g. notesAPI, propertiesAPI

import { debounce } from '../utils.js'; // debounce imported from utils.js

// --- Property Parsing Utility ---
/**
 * Parses explicit properties (key::value) from text content.
 * @param {string} textContent - The text content of the note.
 * @returns {object} - An object where keys are property names and values are arrays of strings.
 */
export function parsePropertiesFromText(textContent) {
    const properties = {};
    const regex = /\{([^:}]+)::([^}]+)\}/g; // Matches {key::value}
    let match;
    while ((match = regex.exec(textContent)) !== null) {
        const key = match[1].trim();
        const value = match[2].trim();
        if (!properties[key]) {
            properties[key] = [];
        }
        properties[key].push(value);
    }
    return properties;
}

import { handleNoteDrop } from '../ui/note-elements.js'; // Import directly from note-elements.js

import { notesAPI, propertiesAPI } from '../api_client.js';

// --- Helper functions moved from app.js ---
/**
 * Inserts text at the current cursor position and positions cursor
 * @param {string} text - Text to insert
 * @param {number} cursorOffset - Position cursor relative to start of inserted text
 */
function insertTextAtCursor(text, cursorOffset = 0) {
    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;
    
    const range = selection.getRangeAt(0);
    const textNode = document.createTextNode(text);
    range.insertNode(textNode);
    
    range.setStart(textNode, cursorOffset);
    range.setEnd(textNode, cursorOffset);
    selection.removeAllRanges();
    selection.addRange(range);
}

/**
 * Replaces text by deleting characters before cursor and inserting new text
 * @param {number} deleteCount - Number of characters to delete before cursor
 * @param {string} newText - Text to insert
 * @param {number} cursorOffset - Position cursor relative to start of inserted text
 */
function replaceTextAtCursor(deleteCount, newText, cursorOffset = 0) {
    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;
    
    const range = selection.getRangeAt(0);
    
    range.setStart(range.startContainer, range.startOffset - deleteCount);
    range.deleteContents();
    
    const textNode = document.createTextNode(newText);
    range.insertNode(textNode);
    
    range.setStart(textNode, cursorOffset);
    range.setEnd(textNode, cursorOffset);
    selection.removeAllRanges();
    selection.addRange(range);
}


// --- Note Data Accessors ---
export function getNoteDataById(noteId) {
    return notesForCurrentPage.find(n => String(n.id) === String(noteId));
}

export function getNoteElementById(noteId) {
    if (!notesContainer) {
        console.error("notesContainer not found in note-actions:getNoteElementById");
        return null;
    }
    return notesContainer.querySelector(`.note-item[data-note-id="${noteId}"]`);
}


/**
 * Calculates a new order_index for a note being inserted between two siblings.
 * This uses a fractional indexing strategy to avoid re-ordering all subsequent notes.
 * @param {Array<Object>} allNotes - All notes for the current page.
 * @param {string|null} _parentId - The parent ID of the note. (Not directly used, but good for context).
 * @param {string|null} previousSiblingId - The ID of the note before the new position.
 * @param {string|null} nextSiblingId - The ID of the note after the new position.
 * @returns {number} The calculated order_index.
 */
function calculateOrderIndex(allNotes, _parentId, previousSiblingId, nextSiblingId) {
    const getNoteOrderById = (id) => {
        const note = allNotes.find(n => String(n.id) === String(id));
        return note ? Number(note.order_index) : null;
    };

    const prevOrder = previousSiblingId ? getNoteOrderById(previousSiblingId) : null;
    const nextOrder = nextSiblingId ? getNoteOrderById(nextSiblingId) : null;

    if (prevOrder !== null && nextOrder !== null) {
        // Case 1: Insert between two notes.
        return (prevOrder + nextOrder) / 2.0;
    } else if (prevOrder !== null) {
        // Case 2: Insert after a note (at the end of a list).
        return prevOrder + 1.0;
    } else if (nextOrder !== null) {
        // Case 3: Insert before a note (at the beginning of a list).
        return nextOrder > 0 ? nextOrder / 2.0 : nextOrder - 1.0;
    } else {
        // Case 4: Only note in the list.
        return 1.0;
    }
}


// --- Note Saving Logic ---

/**
 * Core function to handle saving a note's content and properties to the server.
 * @param {string} noteId - The ID of the note to save.
 * @param {string} rawContent - The raw string content of the note.
 * @param {boolean} [isImmediateSave=false] - Flag to indicate if this is an immediate save.
 * @returns {Promise<Object|null>} The updated note object from the server, or null if save failed.
 */
async function _saveNoteToServer(noteId, rawContent, isImmediateSave = false) {
    const saveType = isImmediateSave ? "IMMEDIATE" : "DEBOUNCED";
    
    // It's possible noteId might still be temporary (e.g. 'new-xxx') if this is the very first save
    // for a note created by handleEnterKey or handleAddRootNote, if the initial input handler
    // that calls saveNoteImmediately hasn't yet received a permanent ID from the server.
    // However, notesAPI.updateNote should ideally handle creating the note if ID is 'new-xxx'
    // or the server should return an error if it can't find/create.
    // For now, we assume the noteId will be resolvable by the server or is a permanent ID.
    // if (noteId.startsWith('temp-') || noteId.startsWith('new-')) {
    //     console.warn(`[_saveNoteToServer ${saveType}] Attempted to save temporary/new note: ${noteId}. This should ideally be handled by createNote first.`);
    //     // Depending on backend, this might need to be a createNote call or special handling.
    //     // For now, proceeding with updateNote, assuming backend might handle 'new-xxx' as create.
    // }

    window.ui.updateSaveStatusIndicator('pending');
    console.log(`[_saveNoteToServer ${saveType}] Attempting to save noteId: ${noteId}`);
    console.log(`[_saveNoteToServer ${saveType}] Raw content for noteId ${noteId}:`, JSON.stringify(rawContent));

    try {
        const explicitProperties = parsePropertiesFromText(rawContent);
        let contentToSave = rawContent;

        if (window.currentPageEncryptionKey && window.decryptionPassword) {
            try {
                contentToSave = sjcl.encrypt(window.decryptionPassword, rawContent);
                if (!explicitProperties['encrypted']) {
                    explicitProperties['encrypted'] = [];
                }
                explicitProperties['encrypted'].push('true');
                console.log(`[_saveNoteToServer ${saveType}] Encrypted content for noteId: ${noteId}`);
            } catch (e) {
                console.error(`_saveNoteToServer (${saveType}): Encryption failed for noteId ${noteId}. Error:`, e);
                window.ui.updateSaveStatusIndicator('error');
                alert('Critical Error: Encryption failed. Note was not saved. Please verify your password or contact support if the issue persists.');
                return null;
            }
        }
        
        const updatePayload = { 
            page_id: currentPageId,
            content: contentToSave,
            properties_explicit: explicitProperties
        };
        
        const updatedNote = await notesAPI.updateNote(noteId, updatePayload);
        console.log(`_saveNoteToServer (${saveType}): Received updatedNote from server for noteId ${updatedNote.id}.`);
        
        if (noteId !== updatedNote.id) {
            console.log(`_saveNoteToServer (${saveType}): Note ID changed from ${noteId} to ${updatedNote.id}. Updating state and DOM.`);
            const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(noteId));
            if (noteIndex > -1) {
                notesForCurrentPage[noteIndex].id = updatedNote.id;
            }
            const noteEl = getNoteElementById(noteId); 
            if (noteEl) {
                noteEl.dataset.noteId = updatedNote.id;
                const contentDiv = noteEl.querySelector('.note-content');
                if (contentDiv) contentDiv.dataset.noteId = updatedNote.id;
                const bulletEl = noteEl.querySelector('.note-bullet');
                if (bulletEl) bulletEl.dataset.noteId = updatedNote.id;
            }
        }
        
        updateNoteInCurrentPage(updatedNote); 
        window.ui.updateNoteElement(updatedNote.id, updatedNote); 
        window.ui.updateSaveStatusIndicator('saved');
        return updatedNote;

    } catch (error) {
        const errorMessage = error.message || 'An unknown error occurred.';
        console.error(`_saveNoteToServer (${saveType}): Error updating note ${noteId}. Error:`, error);
        window.ui.updateSaveStatusIndicator('error');
        if (error.response && error.response.data) {
            console.error(`_saveNoteToServer (${saveType}): Server error details for note ${noteId}:`, error.response.data);
        }
        // No alert here as save status indicator is primary UI feedback for general save errors.
        // Critical encryption error is alerted above.
        return null;
    }
}


export async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    // It's possible that noteId here is still a temporary 'new-XYZ' if this is the very first save.
    // _saveNoteToServer and the backend should handle this scenario (e.g. by creating if ID is 'new-XYZ').

    const contentDiv = noteEl.querySelector('.note-content');
    // For immediate save, always get the freshest content from the DOM
    const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv);
    const rawContent = window.ui.normalizeNewlines(rawTextValue);
    
    // Update dataset.rawContent before saving, so it's consistent if debouncedSave follows.
    contentDiv.dataset.rawContent = rawContent;

    return await _saveNoteToServer(noteId, rawContent, true);
}

export const debouncedSaveNote = debounce(async (noteEl) => {
    const noteId = noteEl.dataset.noteId;
    const contentDiv = noteEl.querySelector('.note-content');
    
    // For debounced save, rely on dataset.rawContent which should be updated on input/blur
    const currentRawContent = contentDiv.dataset.rawContent !== undefined && contentDiv.dataset.rawContent !== null 
        ? contentDiv.dataset.rawContent 
        : '';

    const noteData = getNoteDataById(noteId);

    // If note content hasn't changed and it's not a newly created note (which might just be getting its initial save), skip.
    // 'new-' prefix is used for notes that haven't been saved to the server yet.
    // `noteData.content` here is the last known *saved* content from the server.
    if (noteData && noteData.content === currentRawContent && !String(noteId).startsWith('new-')) {
        // console.log('[DEBOUNCED SAVE] Content unchanged for noteId:', noteId, '. Skipping save.');
        // Ensure status is 'saved' if no changes
        const currentSaveStatus = window.ui.saveStatus; // Assuming saveStatus is exposed or use a getter
        if (currentSaveStatus !== 'saved') {
            window.ui.updateSaveStatusIndicator('saved');
        }
        return;
    }
    
    // If noteId still starts with 'new-', it implies this is its first proper save attempt after creation.
    // The content of a 'new-' note in noteData might be empty initially.
    // The check above handles subsequent saves after the first successful one.

    await _saveNoteToServer(noteId, currentRawContent, false);
}, 1000);


// --- Event Handler: Add Root Note ---
export async function handleAddRootNote() {
    const pageIdToUse = currentPageId; 
    if (!pageIdToUse) {
        alert('Please select or create a page first.');
        return;
    }

    try {
        const rootNotes = notesForCurrentPage
            .filter(n => n.parent_note_id === null || typeof n.parent_note_id === 'undefined')
            .sort((a, b) => a.order_index - b.order_index);
        
        const previousSiblingId = rootNotes.length > 0 ? String(rootNotes[rootNotes.length - 1].id) : null;
        const nextSiblingId = null; // New root notes are added at the end.
        const parentId = null;

        const newRootOrderIndex = calculateOrderIndex(
            notesForCurrentPage,
            parentId,
            previousSiblingId,
            nextSiblingId
        );

        const newNotePayload = {
            page_id: pageIdToUse,
            content: '',
            parent_note_id: parentId,
            order_index: newRootOrderIndex
        };
        
        console.log(`[NOTE CREATION] Adding root note. PrevSiblingId: ${previousSiblingId}, NextSiblingId: ${nextSiblingId}. Calculated newOrderIndex: ${newRootOrderIndex}`);

        const savedNote = await notesAPI.createNote(newNotePayload);

        if (savedNote) {
            console.log(`[NOTE CREATION] Received from server: id=${savedNote.id}, server_assigned_order_index=${savedNote.order_index}, content="${savedNote.content}"`);
            addNoteToCurrentPage(savedNote);
            const noteEl = window.ui.addNoteElement(savedNote, notesContainer, 0); // Assumes ui is global

            const contentDiv = noteEl ? noteEl.querySelector('.note-content') : null;
            if (contentDiv) {
                contentDiv.dataset.rawContent = '';
                window.ui.switchToEditMode(contentDiv); // Assumes ui is global
                
                const initialInputHandler = async (e) => {
                    const currentContent = contentDiv.textContent;
                    if (currentContent !== '') {
                        contentDiv.dataset.rawContent = currentContent;
                        await saveNoteImmediately(noteEl);
                        contentDiv.removeEventListener('input', initialInputHandler);
                    }
                };
                contentDiv.addEventListener('input', initialInputHandler);
            }
        }
    } catch (error) {
        const errorMessage = error.message || 'Please check connection and try again.';
        console.error('handleAddRootNote: Error creating root note. Error:', error);
        alert(`Failed to create new root note. ${errorMessage}`);
    }
}

// --- Keyboard Interaction Helpers & Main Handler ---
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

    if (precedingText2Chars === ':t') { e.preventDefault(); replaceTextAtCursor(2, '{tag::}', 6); shortcutHandled = true; }
    else if (precedingText2Chars === ':d') { e.preventDefault(); const today = new Date().toISOString().slice(0, 10); replaceTextAtCursor(2, `{date::${today}}`, 18); shortcutHandled = true; }
    else if (precedingText2Chars === ':r') { e.preventDefault(); const now = new Date().toISOString(); replaceTextAtCursor(2, `{timestamp::${now}}`, 12 + now.length + 1); shortcutHandled = true; }
    else if (precedingText2Chars === ':k') { e.preventDefault(); replaceTextAtCursor(2, '{keyword::}', 10); shortcutHandled = true; }

    if (shortcutHandled) {
        const noteItemForShortcut = contentDiv.closest('.note-item');
        if (noteItemForShortcut) {
            const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv); // Assumes ui is global
            contentDiv.dataset.rawContent = window.ui.normalizeNewlines(rawTextValue); // Assumes ui is global
            debouncedSaveNote(noteItemForShortcut);
        }
        return true;
    }
    return false;
}

function handleAutocloseBrackets(e) { // e.target is the contentEditable div
    let handled = false;
    const selection = window.getSelection();
    if (!selection.rangeCount) return false;
    const range = selection.getRangeAt(0);
    const editor = e.target; // This is the contentEditable div

    if (e.key === '[') {
        e.preventDefault();
        // Get text immediately before the current cursor position within the editor
        const textNode = range.startContainer;
        let textBeforeCursor = "";
        if (textNode.nodeType === Node.TEXT_NODE) {
            textBeforeCursor = textNode.textContent.substring(0, range.startOffset);
        } else { 
            // Fallback or more complex logic might be needed if cursor is not in a simple text node
            // For now, using editor.textContent and range.startOffset as a general approach
            // This might be less accurate if the DOM inside contentEditable is complex.
            // However, for typical text entry, range.startContainer is often the text node.
            textBeforeCursor = editor.textContent.substring(0, range.startOffset);
        }
        
        if (textBeforeCursor.endsWith('[')) {
            // User typed '[' when the char before was already '['.
            // Initial state (e.g.): X[|Y (cursor is |)
            // After this `insertTextAtCursor` call, we want X[[|]]Y
            // `insertTextAtCursor('[]', 1)` will insert '[]' at the cursor position,
            // and place the cursor at offset 1 within the newly inserted '[]'.
            // So, X[|Y becomes X[[]|]]Y. This is the correct behavior.
            insertTextAtCursor('[]', 1);
        } else {
            // Standard case: insert '[]', cursor in middle.
            // e.g. X|Y becomes X[|]Y
            insertTextAtCursor('[]', 1);
        }
        handled = true;
    } else if (e.key === '{') {
        e.preventDefault();
        insertTextAtCursor('{}', 1);
        handled = true;
    } else if (e.key === '(') {
        e.preventDefault();
        insertTextAtCursor('()', 1);
        handled = true;
    }

    // After modification, dispatch an input event so note-renderer's listeners are triggered
    if (handled) {
        // Ensure the event is dispatched on the correct element that has the 'input' listeners (contentDiv)
        editor.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
    }
    return handled;
}

async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (contentDiv.classList.contains('rendered-mode')) {
        e.preventDefault();
        window.ui.switchToEditMode(contentDiv);
        return;
    }
    if (e.shiftKey) {
        const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv);
        contentDiv.dataset.rawContent = window.ui.normalizeNewlines(rawTextValue);
        debouncedSaveNote(noteItem);
        return;
    }
    e.preventDefault();
    if (!noteData) { console.error("Cannot create new note with Enter: current noteData is missing."); return; }
    const pageIdToUse = currentPageId;
    if (!pageIdToUse) { console.error("Cannot create new note with Enter: currentPageId from state is missing."); return; }

    const parentIdForNewNote = noteData.parent_note_id;

    // Determine previous and next sibling IDs for the new note
    const previousSiblingId = String(noteData.id); // The new note is created after the current note
    
    // Find the next sibling of the current note in the data model
    // (not just in the DOM, to ensure logical ordering)
    const siblingsOfCurrentNote = notesForCurrentPage.filter(n => {
        const nParentId = n.parent_note_id;
        if (parentIdForNewNote === null || typeof parentIdForNewNote === 'undefined') {
            return nParentId === null || typeof nParentId === 'undefined';
        }
        return String(nParentId) === String(parentIdForNewNote);
    }).sort((a, b) => a.order_index - b.order_index);

    const currentNoteDataIndexInSiblings = siblingsOfCurrentNote.findIndex(n => String(n.id) === String(noteData.id));
    let nextSiblingId = null;
    if (currentNoteDataIndexInSiblings !== -1 && currentNoteDataIndexInSiblings < siblingsOfCurrentNote.length - 1) {
        nextSiblingId = String(siblingsOfCurrentNote[currentNoteDataIndexInSiblings + 1].id);
    }

    // Use the new service to calculate order_index
    const newOrderIndex = calculateOrderIndex(
        notesForCurrentPage,
        parentIdForNewNote,
        previousSiblingId,
        nextSiblingId
    );

    console.log(`[NOTE CREATION] Using calculateOrderIndex. ParentId: ${parentIdForNewNote}, PrevSiblingId: ${previousSiblingId}, NextSiblingId: ${nextSiblingId}. Calculated newOrderIndex: ${newOrderIndex}`);

    const newNotePayload = {
        page_id: pageIdToUse,
        content: '',
        parent_note_id: parentIdForNewNote,
        order_index: newOrderIndex // Use the order_index from the service
    };

    try {
        const savedNewNote = await notesAPI.createNote(newNotePayload);

        if (savedNewNote) {
            console.log(`[NOTE CREATION] Received from server: id=${savedNewNote.id}, server_assigned_order_index=${savedNewNote.order_index}, content="${savedNewNote.content}"`);
            
            // Add the new note to the local state
            addNoteToCurrentPage(savedNewNote);
            
            // Sort notesForCurrentPage by order_index
            notesForCurrentPage.sort((a, b) => a.order_index - b.order_index);

            // Determine target container and nesting level correctly
            let targetDomContainer;
            let nestingLevel;
            const currentNestingLevel = window.ui.getNestingLevel(noteItem);

            if (parentIdForNewNote) {
                const parentNoteElement = getNoteElementById(parentIdForNewNote);
                if (parentNoteElement) {
                    targetDomContainer = parentNoteElement.querySelector('.note-children');
                    if (!targetDomContainer) {
                        targetDomContainer = document.createElement('div');
                        targetDomContainer.className = 'note-children';
                        parentNoteElement.appendChild(targetDomContainer);
                        if (typeof Sortable !== 'undefined' && Sortable.create) {
                            Sortable.create(targetDomContainer, { 
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
                    nestingLevel = window.ui.getNestingLevel(parentNoteElement) + 1;
                } else {
                    console.warn(`Parent element for ID ${parentIdForNewNote} not found. Adding new note to root.`);
                    targetDomContainer = notesContainer;
                    nestingLevel = 0;
                }
            } else {
                targetDomContainer = notesContainer;
                nestingLevel = 0;
            }
            
            // Create the new note element
            const newNoteEl = window.ui.renderNote(savedNewNote, nestingLevel);
            
            // Insert the new note after the current note in the DOM
            if (noteItem.nextSibling) {
                targetDomContainer.insertBefore(newNoteEl, noteItem.nextSibling);
            } else {
                targetDomContainer.appendChild(newNoteEl);
            }

            const newContentDiv = newNoteEl ? newNoteEl.querySelector('.note-content') : null;
            if (newContentDiv) {
                newContentDiv.dataset.rawContent = '';
                window.ui.switchToEditMode(newContentDiv);
                
                const initialInputHandler = async (evt) => {
                    const currentContent = newContentDiv.textContent;
                    if (currentContent !== '') {
                        newContentDiv.dataset.rawContent = currentContent;
                        await saveNoteImmediately(newNoteEl);
                        newContentDiv.removeEventListener('input', initialInputHandler);
                    }
                };
                newContentDiv.addEventListener('input', initialInputHandler);
            }
        }
    } catch (error) {
        const errorMessage = error.message || 'Please check connection and try again.';
        console.error('handleEnterKey: Error creating note. Error:', error);
        alert(`Failed to create new note. ${errorMessage}`);
    }
}

async function handleTabKey(e, noteItem, noteData, contentDiv) {
    e.preventDefault();
    if (!noteData) return;
    const currentContentForTab = contentDiv.dataset.rawContent || contentDiv.textContent;
    if (currentContentForTab !== noteData.content) {
        await saveNoteImmediately(noteItem);
        noteData.content = currentContentForTab;
    }
    
    const originalNoteData = JSON.parse(JSON.stringify(noteData));
    const originalParentDomElement = noteItem.parentElement.closest('.note-item') || notesContainer;
    const originalNextSibling = noteItem.nextElementSibling;
    const originalNestingLevel = window.ui.getNestingLevel(noteItem);

    let newParentIdForAPI = null;
    let newOrderIndexForAPI;

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return; // Already a root note
        const oldParentNoteData = getNoteDataById(noteData.parent_note_id);
        if (!oldParentNoteData) { console.error("Outdent: Old parent note data not found for ID:", noteData.parent_note_id); return; }

        newParentIdForAPI = oldParentNoteData.parent_note_id; // This could be null if outdenting to root

        // The note being moved will be placed AFTER its original parent.
        const previousSiblingId = String(oldParentNoteData.id);

        // Find the next sibling of the oldParentNoteData
        const siblingsOfOldParent = notesForCurrentPage.filter(n => {
            const nParentId = n.parent_note_id;
            if (newParentIdForAPI === null || typeof newParentIdForAPI === 'undefined') {
                return nParentId === null || typeof nParentId === 'undefined';
            }
            return String(nParentId) === String(newParentIdForAPI);
        }).sort((a, b) => a.order_index - b.order_index);
        
        const oldParentIndexInSiblings = siblingsOfOldParent.findIndex(n => String(n.id) === String(oldParentNoteData.id));
        let nextSiblingId = null;
        if (oldParentIndexInSiblings !== -1 && oldParentIndexInSiblings < siblingsOfOldParent.length - 1) {
            nextSiblingId = String(siblingsOfOldParent[oldParentIndexInSiblings + 1].id);
        }
        
        newOrderIndexForAPI = calculateOrderIndex(notesForCurrentPage, newParentIdForAPI, previousSiblingId, nextSiblingId);
        console.log(`[TAB-KEY OUTDENT] PrevSib: ${previousSiblingId}, NextSib: ${nextSiblingId}, NewParent: ${newParentIdForAPI}, NewOrder: ${newOrderIndexForAPI}`);

        // DOM Update (Optimistic)
        noteData.parent_note_id = newParentIdForAPI;
        noteData.order_index = newOrderIndexForAPI;
        updateNoteInCurrentPage(noteData); // Update local state before DOM manipulation

        const newParentDomElement = newParentIdForAPI ? getNoteElementById(newParentIdForAPI) : notesContainer;
        if (!newParentDomElement && newParentIdForAPI) { console.error("Could not find new parent in DOM for outdent"); return; }
        const newNestingLevel = newParentIdForAPI ? window.ui.getNestingLevel(newParentDomElement) + 1 : 0;
        // Determine the DOM element to insert before (the original parent's next sibling)
        const domInsertBefore = oldParentNoteData ? getNoteElementById(oldParentNoteData.id)?.nextElementSibling : null;
        window.ui.moveNoteElement(noteItem, newParentDomElement || notesContainer, newNestingLevel, domInsertBefore);

    } else { // Indent
        let potentialNewParentNoteData;
        // Determine the note to indent under (its previous sibling at the same level)
        const currentLevelSiblings = notesForCurrentPage.filter(n => {
            const currentNoteParentId = noteData.parent_note_id;
            const nParentId = n.parent_note_id;
            if (currentNoteParentId === null || typeof currentNoteParentId === 'undefined') {
                return nParentId === null || typeof nParentId === 'undefined';
            }
            return String(nParentId) === String(currentNoteParentId);
        }).sort((a, b) => a.order_index - b.order_index);

        const currentNoteIndexInLevel = currentLevelSiblings.findIndex(n => String(n.id) === String(noteData.id));
        if (currentNoteIndexInLevel <= 0) return; // Cannot indent first item or if not found
        
        potentialNewParentNoteData = currentLevelSiblings[currentNoteIndexInLevel - 1];
        if (!potentialNewParentNoteData) { console.error("Indent: Could not determine new parent note."); return; }
        
        newParentIdForAPI = String(potentialNewParentNoteData.id);

        // The indented note becomes the last child of this new parent.
        const childrenOfNewParent = notesForCurrentPage
            .filter(n => String(n.parent_note_id) === newParentIdForAPI)
            .sort((a, b) => a.order_index - b.order_index);
        
        const previousSiblingId = childrenOfNewParent.length > 0 ? String(childrenOfNewParent[childrenOfNewParent.length - 1].id) : null;
        const nextSiblingId = null; // Becomes the last child

        newOrderIndexForAPI = calculateOrderIndex(notesForCurrentPage, newParentIdForAPI, previousSiblingId, nextSiblingId);
        console.log(`[TAB-KEY INDENT] PrevSib: ${previousSiblingId}, NextSib: ${nextSiblingId}, NewParent: ${newParentIdForAPI}, NewOrder: ${newOrderIndexForAPI}`);

        // DOM Update (Optimistic)
        noteData.parent_note_id = newParentIdForAPI;
        noteData.order_index = newOrderIndexForAPI;
        updateNoteInCurrentPage(noteData); // Update local state before DOM manipulation
        
        const newParentDomElement = getNoteElementById(newParentIdForAPI); // newParentIdForAPI is ID of potentialNewParentNoteData
        if (!newParentDomElement) { console.error("Could not find new parent in DOM for indent"); return; }
        const newNestingLevel = window.ui.getNestingLevel(newParentDomElement) + 1;
        
        let childrenContainer = newParentDomElement.querySelector('.note-children');
        if (!childrenContainer) {
            childrenContainer = document.createElement('div');
            childrenContainer.className = 'note-children';
            newParentDomElement.appendChild(childrenContainer);
            if (typeof Sortable !== 'undefined' && Sortable.create) {
                Sortable.create(childrenContainer, { group: 'notes', animation: 150, handle: '.note-bullet', ghostClass: 'note-ghost', chosenClass: 'note-chosen', dragClass: 'note-drag', onEnd: handleNoteDrop });
            }
        }
        // Indented note usually goes to the end of children list
        window.ui.moveNoteElement(noteItem, childrenContainer, newNestingLevel, null); 
    }
    window.ui.switchToEditMode(contentDiv);

    try {
        const payload = {
            page_id: currentPageId,
            content: noteData.content, // Ensure content is part of the payload
            parent_note_id: newParentIdForAPI,
            order_index: newOrderIndexForAPI
        };
        console.log('[TAB-KEY API Call] Updating note:', noteData.id, payload);
        const updatedNoteFromServer = await notesAPI.updateNote(noteData.id, payload);
        
        // Update local cache with server response
        updateNoteInCurrentPage(updatedNoteFromServer);
        // The DOM was already updated optimistically. If server changed something, it might need reconciliation,
        // but for parent_id and order_index, the optimistic update should match if API call is successful.
        // If server changes order_index (e.g. due to conflict), that needs to be reflected.
        // For now, we assume server respects the client's calculated order_index if valid.
        noteData.order_index = updatedNoteFromServer.order_index; // Ensure local data has server's version
        noteData.parent_note_id = updatedNoteFromServer.parent_note_id; 
        noteData.updated_at = updatedNoteFromServer.updated_at; // Sync timestamp

        notesForCurrentPage.sort((a, b) => a.order_index - b.order_index);
        
        // updateNoteElement might be called by _saveNoteToServer if content changed,
        // but if only parent/order changed, ensure DOM is reflecting the optimistic update,
        // or re-render if server data is substantially different.
        // For now, optimistic DOM move is primary.

    } catch (error) {
        const errorMessage = error.message || 'Please try again.';
        console.error(`handleTabKey: Error updating note ${noteData.id} parent/order. Error:`, error);
        alert(`Failed to update note structure. ${errorMessage} Reverting changes.`);
        
        const backupNotes = JSON.parse(sessionStorage.getItem('backupNotesBeforeTab'));
        if (backupNotes) {
            setNotesForCurrentPage(backupNotes);
        }
        if (window.ui && typeof window.ui.displayNotes === 'function' && currentPageId) {
             window.ui.displayNotes(notesForCurrentPage, currentPageId);
        } else {
            console.warn('handleTabKey: Could not re-render notes after error, UI might be inconsistent.');
        }
        const originalContentDiv = getNoteElementById(noteData.id)?.querySelector('.note-content');
        if (originalContentDiv) {
            window.ui.switchToEditMode(originalContentDiv);
        }
    }
}

async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if (!noteData) return;
    if (contentDiv.classList.contains('edit-mode') && (contentDiv.dataset.rawContent || contentDiv.textContent).trim() === '') {
        const children = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.id));
        if (children.length > 0) { 
            console.log('handleBackspaceKey: Note has children, not deleting on backspace for noteId:', noteData.id); 
            return; 
        }
        
        const isRootNote = !noteData.parent_note_id;
        const rootNotesCount = notesForCurrentPage.filter(n => !n.parent_note_id).length;
        if (isRootNote && rootNotesCount === 1 && notesForCurrentPage.length === 1) { 
            console.log('handleBackspaceKey: Cannot delete the only note on the page via Backspace for noteId:', noteData.id); 
            return; 
        }
        
        let noteToFocusAfterDelete = null;
        const allNoteElements = Array.from(notesContainer.querySelectorAll('.note-item'));
        const currentNoteIndexInDOM = allNoteElements.findIndex(el => el.dataset.noteId === noteData.id);
        if (currentNoteIndexInDOM > 0) {
            noteToFocusAfterDelete = allNoteElements[currentNoteIndexInDOM - 1];
        } else if (allNoteElements.length > 1 && currentNoteIndexInDOM + 1 < allNoteElements.length) {
            noteToFocusAfterDelete = allNoteElements[currentNoteIndexInDOM + 1];
        } else if (noteData.parent_note_id) {
            noteToFocusAfterDelete = getNoteElementById(noteData.parent_note_id);
        }

        e.preventDefault();
        try {
            await notesAPI.deleteNote(noteData.id);
            removeNoteFromCurrentPageById(noteData.id);
            window.ui.removeNoteElement(noteData.id);
            if (noteToFocusAfterDelete) {
                const contentDivToFocus = noteToFocusAfterDelete.querySelector('.note-content');
                if (contentDivToFocus) window.ui.switchToEditMode(contentDivToFocus);
            } else if (notesForCurrentPage.length === 0 && currentPageId) { 
                console.log("handleBackspaceKey: All notes deleted from pageId:", currentPageId); 
            }
        } catch (error) { 
            const errorMessage = error.message || 'Please try again.';
            console.error(`handleBackspaceKey: Error deleting note ${noteData.id}. Error:`, error); 
            alert(`Failed to delete note. ${errorMessage}`);
        }
    }
}

function handleArrowKey(e, contentDiv) {
    e.preventDefault();
    const allVisibleNotesContent = Array.from(notesContainer.querySelectorAll('.note-item:not(.note-hidden) .note-content'));
    const currentVisibleIndex = allVisibleNotesContent.indexOf(contentDiv);
    let nextVisibleIndex = -1;
    if (e.key === 'ArrowUp' && currentVisibleIndex > 0) nextVisibleIndex = currentVisibleIndex - 1;
    else if (e.key === 'ArrowDown' && currentVisibleIndex < allVisibleNotesContent.length - 1) nextVisibleIndex = currentVisibleIndex + 1;

    if (nextVisibleIndex !== -1) {
        const nextNoteContent = allVisibleNotesContent[nextVisibleIndex];
        window.ui.switchToEditMode(nextNoteContent); // Assumes ui is global
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(nextNoteContent);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

export async function handleNoteKeyDown(e) {
    if (!e.target.matches('.note-content')) return;
    const noteItem = e.target.closest('.note-item');
    const noteId = noteItem.dataset.noteId;
    const contentDiv = e.target;
    const noteData = getNoteDataById(noteId);

    if (contentDiv.classList.contains('edit-mode')) {
        if (await handleShortcutExpansion(e, contentDiv)) return;
        if (handleAutocloseBrackets(e, contentDiv)) return;
    }
    if (!noteData || noteId.startsWith('temp-')) {
        if (e.key === 'Enter' && contentDiv.classList.contains('rendered-mode')) { /* Allow Enter for edit mode */ }
        else if (noteId.startsWith('temp-') && ['Enter', 'Tab', 'Backspace'].includes(e.key)) { console.warn('Action (' + e.key + ') blocked on temp note ID: ' + noteId); return; }
        else if (!noteData && !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) { console.warn('Note data not found for ID: ' + noteId + '. Key: ' + e.key + '. Blocking.'); return; }
    }
    
    // Backup notes before Tab operation for potential revert
    if (e.key === 'Tab') {
        sessionStorage.setItem('backupNotesBeforeTab', JSON.stringify(notesForCurrentPage));
    }

    switch (e.key) {
        case 'Enter': await handleEnterKey(e, noteItem, noteData, contentDiv); break;
        case 'Tab': await handleTabKey(e, noteItem, noteData, contentDiv); break;
        case 'Backspace': await handleBackspaceKey(e, noteItem, noteData, contentDiv); break;
        case 'ArrowUp': case 'ArrowDown': handleArrowKey(e, contentDiv); break;
    }
}


// --- Event Handler: Task Checkbox Click ---
export async function handleTaskCheckboxClick(e) {
    const checkbox = e.target;
    const noteItem = checkbox.closest('.note-item');
    if (!noteItem) return;
    
    const noteId = noteItem.dataset.noteId;
    const contentDiv = noteItem.querySelector('.note-content');
    const noteData = getNoteDataById(noteId);

    if (!noteData || !contentDiv || noteId.startsWith('temp-')) {
        console.error('Note data, contentDiv not found, or temp note for task checkbox click', { noteId, noteData, contentDiv });
        checkbox.checked = !checkbox.checked;
        return;
    }
    
    let rawContent = contentDiv.dataset.rawContent || contentDiv.textContent;
    let newRawContent, newStatus, doneAt = null;
    const isChecked = checkbox.checked;
    const markerType = checkbox.dataset.markerType;

    // Handle different task statuses
    switch (markerType) {
        case 'TODO':
            if (isChecked) {
                newRawContent = 'DONE ' + rawContent.substring(5);
                newStatus = 'DONE';
                doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
            } else {
                newRawContent = rawContent;
                newStatus = 'TODO';
            }
            break;

        case 'DOING':
            if (isChecked) {
                newRawContent = 'DONE ' + rawContent.substring(6);
                newStatus = 'DONE';
                doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
            } else {
                newRawContent = 'TODO ' + rawContent.substring(6);
                newStatus = 'TODO';
            }
            break;

        case 'SOMEDAY':
            if (isChecked) {
                newRawContent = 'DONE ' + rawContent.substring(8);
                newStatus = 'DONE';
                doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
            } else {
                newRawContent = 'TODO ' + rawContent.substring(8);
                newStatus = 'TODO';
            }
            break;

        case 'DONE':
            if (!isChecked) {
                newRawContent = 'TODO ' + rawContent.substring(5);
                newStatus = 'TODO';
            } else {
                newRawContent = rawContent;
                newStatus = 'DONE';
                doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
            }
            break;

        case 'WAITING':
            if (isChecked) {
                newRawContent = 'DONE ' + rawContent.substring(8);
                newStatus = 'DONE';
                doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
            } else {
                newRawContent = 'TODO ' + rawContent.substring(8);
                newStatus = 'TODO';
            }
            break;

        case 'CANCELLED':
        case 'NLR':
            // These statuses are not interactive
            checkbox.checked = true;
            return;

        default:
            console.warn("Unknown task marker type:", markerType);
            checkbox.checked = !checkbox.checked;
            return;
    }

    try {
        // 1. Update note content - this will trigger pattern processor to set status
        console.log('[TASK_DEBUG] Updating note content:', { noteId, newRawContent });
        const updatedNoteServer = await notesAPI.updateNote(noteId, { 
            page_id: currentPageId,
            content: newRawContent 
        });
        console.log('[TASK_DEBUG] Note content updated:', updatedNoteServer);
        noteData.content = updatedNoteServer.content;
        noteData.updated_at = updatedNoteServer.updated_at;
        contentDiv.dataset.rawContent = updatedNoteServer.content;

        // Re-render the content div with the new raw content
        if (contentDiv.classList.contains('edit-mode')) {
            contentDiv.textContent = updatedNoteServer.content;
        } else {
            contentDiv.innerHTML = window.ui.parseAndRenderContent(updatedNoteServer.content);
        }

        // 2. Only handle done_at property directly (status is handled by pattern processor)
        if (doneAt) {
            console.log('[TASK_DEBUG] Setting done_at property:', { noteId, doneAt });
            await propertiesAPI.setProperty({ 
                entity_type: 'note', 
                entity_id: parseInt(noteId), 
                name: 'done_at', 
                value: doneAt 
            });
            console.log('[TASK_DEBUG] done_at property set');
        } else {
            try {
                console.log('[TASK_DEBUG] Deleting done_at property for note:', noteId);
                await propertiesAPI.deleteProperty('note', parseInt(noteId), 'done_at');
                console.log('[TASK_DEBUG] done_at property deleted');
            } catch (delError) {
                console.warn('Could not delete done_at:', delError);
            }
        }

        // 3. Fetch all properties for the note to update local cache
        const updatedProperties = await propertiesAPI.getProperties('note', parseInt(noteId));
        noteData.properties = updatedProperties;

        // 4. Update global notes data for consistency
        const noteIndexInGlobal = notesForCurrentPage.findIndex(n => String(n.id) === String(noteId));
        if (noteIndexInGlobal > -1) {
            notesForCurrentPage[noteIndexInGlobal] = { ...notesForCurrentPage[noteIndexInGlobal], ...noteData };
            window.notesForCurrentPage = notesForCurrentPage;
        }

        updateNoteInCurrentPage(noteData);
        console.log('Task status updated:', { noteId, newStatus, newRawContent, doneAt });
    } catch (error) {
        const errorMessage = error.message || 'Please try again.';
        console.error(`handleTaskCheckboxClick: Error updating task status for note ${noteId}. Error:`, error);
        alert(`Failed to update task status. ${errorMessage}`);
        
        // Revert UI on error
        checkbox.checked = !checkbox.checked; 
        contentDiv.dataset.rawContent = noteData.content; 
        if (contentDiv.classList.contains('edit-mode')) {
            contentDiv.textContent = noteData.content;
        } else {
            contentDiv.innerHTML = window.ui.parseAndRenderContent(noteData.content);
        }
        // Revert noteData properties if they were optimistically changed before API calls.
        // (Current logic seems to update noteData.properties only after successful API calls, which is good).
    }
}
