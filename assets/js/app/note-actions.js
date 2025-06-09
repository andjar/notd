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


// --- Note Saving Logic ---
export async function saveNoteImmediately(noteEl) {
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) {
        console.warn('Attempted to save temporary note immediately.');
        return; 
    }

    const contentDiv = noteEl.querySelector('.note-content');
    const rawTextValue = window.ui.getRawTextWithNewlines(contentDiv);
    const rawContent = window.ui.normalizeNewlines(rawTextValue);
    
    window.ui.updateSaveStatusIndicator('pending'); 
    console.log('[DEBUG IMMEDIATE SAVE] Attempting to save noteId:', noteId);
    console.log('[DEBUG IMMEDIATE SAVE] Raw content for noteId ' + noteId + ':', JSON.stringify(rawContent));

    try {
        let contentToSave = rawContent;
        const explicitProperties = parsePropertiesFromText(rawContent);

        if (window.currentPageEncryptionKey && window.decryptionPassword) {
            try {
                contentToSave = sjcl.encrypt(window.decryptionPassword, rawContent);
                // console.log('Encrypting (immediate): Original rawContent:', rawContent);
                // console.log('Encrypting (immediate): Encrypted contentToSave:', contentToSave);
                if (!explicitProperties['encrypted']) {
                    explicitProperties['encrypted'] = [];
                }
                explicitProperties['encrypted'].push('true'); 
            } catch (e) {
                console.error('Encryption failed (immediate):', e);
                window.ui.updateSaveStatusIndicator('error');
                alert('Critical error: Encryption failed. Note not saved. Please try again or contact support.');
                return; // Do not proceed with saving if encryption fails
            }
            // Removed old propertiesAPI.setProperty call for 'encrypted'
        }
        
        const updatePayload = { 
            content: contentToSave,
            properties_explicit: explicitProperties
        };
        
        const updatedNote = await notesAPI.updateNote(noteId, updatePayload);
        console.log('[DEBUG IMMEDIATE SAVE] Received updatedNote from server for noteId ' + noteId + '. Content:', JSON.stringify(updatedNote.content), 'Properties:', updatedNote.properties);
        updateNoteInCurrentPage(updatedNote);
        
        window.ui.updateNoteElement(updatedNote.id, updatedNote);
        window.ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error('Error updating note (immediately):', error);
        window.ui.updateSaveStatusIndicator('error');
    }
}

export const debouncedSaveNote = debounce(async (noteEl) => { // Assumes debounce is imported/available
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) return;

    const contentDiv = noteEl.querySelector('.note-content');
    const rawContentFromDataset = contentDiv.dataset.rawContent; 
    
    const noteData = getNoteDataById(noteId);
    // If rawContentFromDataset is undefined or null, provide a default empty string.
    const currentRawContent = rawContentFromDataset !== undefined && rawContentFromDataset !== null ? rawContentFromDataset : '';

    // If note content hasn't changed and it's not a newly created note (which might just be getting its initial save), skip.
    if (noteData && noteData.content === currentRawContent && !noteId.startsWith('new-')) {
        // console.log('[DEBUG SAVE] Content unchanged for noteId:', noteId, '. Skipping save.');
        return;
    }

    window.ui.updateSaveStatusIndicator('pending');
    console.log('[DEBUG SAVE] Attempting to save noteId:', noteId);
    console.log('[DEBUG SAVE] Raw content for noteId ' + noteId + ':', JSON.stringify(currentRawContent));
    
    try {
        let finalContentToSave = currentRawContent;
        const explicitProperties = parsePropertiesFromText(currentRawContent);

        if (window.currentPageEncryptionKey && window.decryptionPassword) {
            try {
                finalContentToSave = sjcl.encrypt(window.decryptionPassword, currentRawContent); // Encrypt the original currentRawContent
                // console.log('Encrypting (debounced): Original currentRawContent:', currentRawContent);
                // console.log('Encrypting (debounced): Encrypted finalContentToSave:', finalContentToSave);
                if (!explicitProperties['encrypted']) {
                    explicitProperties['encrypted'] = [];
                }
                explicitProperties['encrypted'].push('true');
            } catch (e) {
                console.error('Encryption failed (debounced):', e);
                window.ui.updateSaveStatusIndicator('error');
                alert('Critical error: Encryption failed. Note not saved. Please try again or contact support.');
                return; 
            }
            // Removed old propertiesAPI.setProperty call for 'encrypted'
        }
        
        const updatePayload = {
            content: finalContentToSave,
            properties_explicit: explicitProperties
        };
        
        const updatedNote = await notesAPI.updateNote(noteId, updatePayload);
        console.log('[DEBUG SAVE] Received updatedNote from server for noteId ' + noteId + '. Content:', JSON.stringify(updatedNote.content), 'Properties:', updatedNote.properties);
        updateNoteInCurrentPage(updatedNote);
        
        window.ui.updateNoteElement(updatedNote.id, updatedNote); // Assumes ui is global
        window.ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error('Error updating note (debounced):', error);
        window.ui.updateSaveStatusIndicator('error');
    }
    // finally block removed as updateSaveStatusIndicator is called in try/catch
}, 1000);


// --- Event Handler: Add Root Note ---
export async function handleAddRootNote() {
    const pageIdToUse = currentPageId; 
    if (!pageIdToUse) {
        alert('Please select or create a page first.');
        return;
    }

    try {
        const rootNotes = notesForCurrentPage.filter(n => n.parent_note_id === null || typeof n.parent_note_id === 'undefined');
        let newRootOrderIndex = 0;
        if (rootNotes.length > 0) {
            const maxRootOrderIndex = Math.max(...rootNotes.map(s => Number(s.order_index) || 0));
            newRootOrderIndex = maxRootOrderIndex + 1;
        }

        const newNotePayload = {
            page_id: pageIdToUse,
            content: '',
            parent_note_id: null,
            order_index: newRootOrderIndex // Send calculated order_index
        };
        
        console.log(`[NOTE CREATION] Sending to server for new note: page_id=${newNotePayload.page_id}, parent_id=${newNotePayload.parent_note_id}, client_calculated_order_index=${newNotePayload.order_index}`);

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
        console.error('Error creating root note:', error);
        alert('Failed to save new note. Please try again.');
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

    // Calculate order_index for the new note
    const parentIdForNewNote = noteData.parent_note_id;
    const siblings = notesForCurrentPage.filter(n => {
        const nParentId = n.parent_note_id;
        if (parentIdForNewNote === null || typeof parentIdForNewNote === 'undefined') {
            return nParentId === null || typeof nParentId === 'undefined';
        }
        return String(nParentId) === String(parentIdForNewNote);
    }).sort((a, b) => a.order_index - b.order_index);

    // Find the current note's index in the siblings array
    const currentNoteIndex = siblings.findIndex(n => String(n.id) === String(noteData.id));
    if (currentNoteIndex === -1) {
        console.error("Current note not found in siblings array");
        return;
    }

    // Calculate new order_index based on the current note's position
    let newOrderIndex;
    if (currentNoteIndex === siblings.length - 1) {
        // If this is the last note, add after it
        newOrderIndex = noteData.order_index + 1;
    } else {
        // If there are notes after this one, insert between current and next
        const nextNote = siblings[currentNoteIndex + 1];
        newOrderIndex = (noteData.order_index + nextNote.order_index) / 2;
    }

    console.log(`[NOTE CREATION] Sending to server for new note: page_id=${pageIdToUse}, parent_id=${parentIdForNewNote}, client_calculated_order_index=${newOrderIndex}`);

    const newNotePayload = {
        page_id: pageIdToUse,
        content: '',
        parent_note_id: parentIdForNewNote,
        order_index: newOrderIndex
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
        console.error('Error creating note on Enter:', error);
        alert('Failed to save new note. Please try again.');
    }
}

async function handleTabKey(e, noteItem, noteData, contentDiv) {
    e.preventDefault();
    if (!noteData) return;
    const currentContentForTab = contentDiv.dataset.rawContent || contentDiv.textContent;
    if (currentContentForTab !== noteData.content) { await saveNoteImmediately(noteItem); noteData.content = currentContentForTab; }
    
    const originalNoteData = JSON.parse(JSON.stringify(noteData));
    const originalParentDomElement = noteItem.parentElement.closest('.note-item') || notesContainer;
    const originalNextSibling = noteItem.nextElementSibling;
    const originalNestingLevel = window.ui.getNestingLevel(noteItem);

    let newParentNoteIdToSet = null;
    let newOrderIndex = noteData.order_index;

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const parentNoteData = getNoteDataById(noteData.parent_note_id);
        if (!parentNoteData) return;
        newParentNoteIdToSet = parentNoteData.parent_note_id;
        const oldParentId = noteData.parent_note_id;
        
        // Update local data structure first
        noteData.parent_note_id = newParentNoteIdToSet;
        newOrderIndex = parentNoteData.order_index + 1;
        noteData.order_index = newOrderIndex;
        updateNoteInCurrentPage(noteData);

        // Update DOM
        const newParentDomElement = newParentNoteIdToSet ? getNoteElementById(newParentNoteIdToSet) : notesContainer;
        if (!newParentDomElement && newParentNoteIdToSet) { console.error("Could not find new parent in DOM for outdent"); return; }
        const newNestingLevel = newParentNoteIdToSet ? window.ui.getNestingLevel(newParentDomElement) + 1 : 0;
        window.ui.moveNoteElement(noteItem, newParentDomElement || notesContainer, newNestingLevel, parentNoteData ? getNoteElementById(parentNoteData.id)?.nextElementSibling : null);
    } else { // Indent
        let newParentNoteData;
        if (noteData.parent_note_id === null || typeof noteData.parent_note_id === 'undefined') {
            // Handle indenting a root note
            const rootNotes = notesForCurrentPage.filter(n => n.parent_note_id === null || typeof n.parent_note_id === 'undefined').sort((a, b) => a.order_index - b.order_index);
            const currentIndex = rootNotes.findIndex(n => String(n.id) === String(noteData.id));
            if (currentIndex === 0) return; // Cannot indent the first root note
            if (currentIndex === -1) { console.error("Current root note not found for indent"); return; }
            newParentNoteData = rootNotes[currentIndex - 1];
        } else {
            // Handle indenting a non-root note (existing logic)
            const siblings = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.parent_note_id) && n.order_index < noteData.order_index).sort((a, b) => b.order_index - a.order_index);
            if (siblings.length === 0) return;
            newParentNoteData = siblings[0];
        }

        if (!newParentNoteData) { console.error("Could not determine new parent for indent"); return; }
        newParentNoteIdToSet = newParentNoteData.id;

        // Update local data structure first
        noteData.parent_note_id = newParentNoteIdToSet;
        
        // Calculate new order index for the indented note
        // Ensure notesForCurrentPage is sorted or filter children correctly
        const childrenOfNewParent = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(newParentNoteIdToSet)).sort((a,b) => a.order_index - b.order_index);
        if (childrenOfNewParent.length === 0) {
            newOrderIndex = 0;
        } else {
            // If the note being moved was already a child of the new parent (e.g. due to a quick succession of tab/shift-tab)
            // and is now the last child, its order index might not need to change relative to others,
            // but it should be greater than the current max.
            // However, standard indent behavior is to become the last child.
            newOrderIndex = Math.max(...childrenOfNewParent.map(n => n.order_index)) + 1;
        }
        noteData.order_index = newOrderIndex;
        updateNoteInCurrentPage(noteData);

        // Update DOM
        const newParentDomElement = getNoteElementById(newParentNoteData.id);
        if (!newParentDomElement) { console.error("Could not find new parent in DOM for indent"); return; }
        const newNestingLevel = window.ui.getNestingLevel(newParentDomElement) + 1;
        
        // Ensure the parent has a children container
        let childrenContainer = newParentDomElement.querySelector('.note-children');
        if (!childrenContainer) {
            childrenContainer = document.createElement('div');
            childrenContainer.className = 'note-children';
            newParentDomElement.appendChild(childrenContainer);
            if (typeof Sortable !== 'undefined' && Sortable.create) { // Check Sortable.create
                Sortable.create(childrenContainer, { 
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
        
        window.ui.moveNoteElement(noteItem, childrenContainer, newNestingLevel);
    }
    window.ui.switchToEditMode(contentDiv);

    try {
        await notesAPI.updateNote(noteData.id, {
            page_id: currentPageId, // Added this line
            content: noteData.content, 
            parent_note_id: newParentNoteIdToSet, 
            order_index: newOrderIndex 
        });
    } catch (error) {
        console.error('Error updating note parent/order (API):', error);
        alert('Error updating note structure. Reverting changes.');
        // Ensure the imported setNotesForCurrentPage is used below, not window.ui.setNotesForCurrentPage
        setNotesForCurrentPage(JSON.parse(sessionStorage.getItem('backupNotesBeforeTab')) || notesForCurrentPage); 
        window.ui.displayNotes(notesForCurrentPage, currentPageId); // This uses the global ui object method
        window.ui.switchToEditMode(contentDiv); // This uses the global ui object method
    }
}

async function handleBackspaceKey(e, noteItem, noteData, contentDiv) {
    if (!noteData) return;
    if (contentDiv.classList.contains('edit-mode') && (contentDiv.dataset.rawContent || contentDiv.textContent).trim() === '') {
        const children = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(noteData.id));
        if (children.length > 0) { console.log('Note has children, not deleting on backspace.'); return; }
        const isRootNote = !noteData.parent_note_id;
        const rootNotesCount = notesForCurrentPage.filter(n => !n.parent_note_id).length;
        if (isRootNote && rootNotesCount === 1 && notesForCurrentPage.length === 1) { console.log('Cannot delete the only note on the page via Backspace.'); return; }
        
        let noteToFocusAfterDelete = null;
        const allNoteElements = Array.from(notesContainer.querySelectorAll('.note-item'));
        const currentNoteIndexInDOM = allNoteElements.findIndex(el => el.dataset.noteId === noteData.id);
        if (currentNoteIndexInDOM > 0) noteToFocusAfterDelete = allNoteElements[currentNoteIndexInDOM - 1];
        else if (allNoteElements.length > 1) noteToFocusAfterDelete = allNoteElements[currentNoteIndexInDOM + 1];
        else if (noteData.parent_note_id) noteToFocusAfterDelete = getNoteElementById(noteData.parent_note_id);

        e.preventDefault();
        try {
            await notesAPI.deleteNote(noteData.id); // Assumes notesAPI is global
            removeNoteFromCurrentPageById(noteData.id);
            window.ui.removeNoteElement(noteData.id); // Assumes ui is global
            if (noteToFocusAfterDelete) {
                const contentDivToFocus = noteToFocusAfterDelete.querySelector('.note-content');
                if (contentDivToFocus) window.ui.switchToEditMode(contentDivToFocus); // Assumes ui is global
            } else if (notesForCurrentPage.length === 0 && currentPageId) { console.log("All notes deleted. Page is empty."); }
        } catch (error) { console.error('Error deleting note:', error); }
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
        const updatedNoteServer = await notesAPI.updateNote(noteId, { content: newRawContent });
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
        console.error('Error updating task status:', error);
        alert('Failed to update task status: ' + error.message);
        // Revert UI on error
        checkbox.checked = !checkbox.checked;
        contentDiv.dataset.rawContent = noteData.content;
        if (contentDiv.classList.contains('edit-mode')) {
            contentDiv.textContent = noteData.content;
        } else {
            contentDiv.innerHTML = window.ui.parseAndRenderContent(noteData.content);
        }
    }
}
