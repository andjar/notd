import { ui } from '../ui.js';

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

// Assuming ui object is globally available.
// Destructure common UI elements/functions if needed, or use ui.method() directly.
const { notesContainer } = ui.domRefs; // notesContainer is frequently used.

// Assuming API objects are globally available.
// e.g. notesAPI, propertiesAPI

import { debounce } from '../utils.js'; // debounce imported from utils.js
import { handleNoteDrop } from '../ui/note-elements.js'; // Import directly from note-elements.js

import { notesAPI } from '../api_client.js';

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
    const rawTextValue = ui.getRawTextWithNewlines(contentDiv); // Assumes ui is global
    const rawContent = ui.normalizeNewlines(rawTextValue); // Assumes ui is global
    
    ui.updateSaveStatusIndicator('pending'); 
    console.log('[DEBUG IMMEDIATE SAVE] Attempting to save noteId:', noteId);
    console.log('[DEBUG IMMEDIATE SAVE] Raw content being sent for noteId ' + noteId + ':', JSON.stringify(rawContent));

    try {
        const updatedNote = await notesAPI.updateNote(noteId, { content: rawContent }); // Assumes notesAPI is global
        console.log('[DEBUG IMMEDIATE SAVE] Received updatedNote from server for noteId ' + noteId + '. Content:', JSON.stringify(updatedNote.content));
        updateNoteInCurrentPage(updatedNote);
        
        ui.updateNoteElement(updatedNote.id, updatedNote); // Assumes ui is global
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error('Error updating note (immediately):', error);
        ui.updateSaveStatusIndicator('error');
    }
    // finally block removed as updateSaveStatusIndicator is called in try/catch
}

export const debouncedSaveNote = debounce(async (noteEl) => { // Assumes debounce is imported/available
    const noteId = noteEl.dataset.noteId;
    if (noteId.startsWith('temp-')) return;

    const contentDiv = noteEl.querySelector('.note-content');
    const rawContent = contentDiv.dataset.rawContent; // Changed line
    
    const noteData = getNoteDataById(noteId);
    // If rawContent is undefined or null (e.g. note was just created and blurred without input), 
    // provide a default empty string to avoid errors with comparison or API calls.
    const contentToSave = rawContent !== undefined && rawContent !== null ? rawContent : '';

    if (noteData && noteData.content === contentToSave && !noteId.startsWith('new-')) return;

    ui.updateSaveStatusIndicator('pending');
    console.log('[DEBUG SAVE] Attempting to save noteId:', noteId);
    console.log('[DEBUG SAVE] Raw content being sent for noteId ' + noteId + ':', JSON.stringify(contentToSave));
    
    try {
        const updatedNote = await notesAPI.updateNote(noteId, { content: contentToSave }); // Assumes notesAPI is global
        console.log('[DEBUG SAVE] Received updatedNote from server for noteId ' + noteId + '. Content:', JSON.stringify(updatedNote.content));
        updateNoteInCurrentPage(updatedNote);
        
        ui.updateNoteElement(updatedNote.id, updatedNote); // Assumes ui is global
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error('Error updating note (debounced):', error);
        ui.updateSaveStatusIndicator('error');
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
        const savedNote = await notesAPI.createNote({ // Assumes notesAPI is global
            page_id: pageIdToUse,
            content: '', 
            parent_note_id: null
        });

        if (savedNote) {
            addNoteToCurrentPage(savedNote);
            const noteEl = ui.addNoteElement(savedNote, notesContainer, 0); // Assumes ui is global

            const contentDiv = noteEl ? noteEl.querySelector('.note-content') : null;
            if (contentDiv) {
                contentDiv.dataset.rawContent = '';
                ui.switchToEditMode(contentDiv); // Assumes ui is global
                
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
            const rawTextValue = ui.getRawTextWithNewlines(contentDiv); // Assumes ui is global
            contentDiv.dataset.rawContent = ui.normalizeNewlines(rawTextValue); // Assumes ui is global
            debouncedSaveNote(noteItemForShortcut);
        }
        return true;
    }
    return false;
}

function handleAutocloseBrackets(e) {
    let handled = false;
    if (e.key === '[') { e.preventDefault(); insertTextAtCursor('[]', 1); handled = true; }
    else if (e.key === '{') { e.preventDefault(); insertTextAtCursor('{}', 1); handled = true; }
    else if (e.key === '(') { e.preventDefault(); insertTextAtCursor('()', 1); handled = true; }
    return handled;
}

async function handleEnterKey(e, noteItem, noteData, contentDiv) {
    if (contentDiv.classList.contains('rendered-mode')) {
        e.preventDefault();
        ui.switchToEditMode(contentDiv); // Assumes ui is global
        return;
    }
    if (e.shiftKey) {
        const rawTextValue = ui.getRawTextWithNewlines(contentDiv); // Assumes ui is global
        contentDiv.dataset.rawContent = ui.normalizeNewlines(rawTextValue); // Assumes ui is global
        debouncedSaveNote(noteItem);
        return;
    }
    e.preventDefault();
    if (!noteData) { console.error("Cannot create new note with Enter: current noteData is missing."); return; }
    const pageIdToUse = currentPageId;
    if (!pageIdToUse) { console.error("Cannot create new note with Enter: currentPageId from state is missing."); return; }

    const newNoteData = { page_id: pageIdToUse, content: '', parent_note_id: noteData.parent_note_id, order_index: noteData.order_index + 1 };
    try {
        const savedNote = await notesAPI.createNote(newNoteData); // Assumes notesAPI is global
        const currentNoteIndex = notesForCurrentPage.findIndex(n => n.id === noteData.id);
        notesForCurrentPage.splice(currentNoteIndex + 1, 0, savedNote); 
        // This direct splice should be okay since notesForCurrentPage is a reference from state,
        // but ideally state.js would provide an insertNoteAt(note, index) function.
        // For now, this relies on the window.notesForCurrentPage also reflecting this.

        let newNoteNestingLevel = 0;
        let parentChildrenContainer = notesContainer;
        if (savedNote.parent_note_id) {
            const parentNoteEl = getNoteElementById(savedNote.parent_note_id);
            if (parentNoteEl) {
                parentChildrenContainer = parentNoteEl.querySelector('.note-children');
                if (!parentChildrenContainer) { 
                    parentChildrenContainer = document.createElement('div');
                    parentChildrenContainer.className = 'note-children';
                    parentNoteEl.appendChild(parentChildrenContainer);
                    if (typeof Sortable !== 'undefined') { // Assumes Sortable is global
                        Sortable.create(parentChildrenContainer, { group: 'notes', animation: 150, handle: '.note-bullet', ghostClass: 'note-ghost', chosenClass: 'note-chosen', dragClass: 'note-drag', onEnd: handleNoteDrop }); // handleNoteDrop would need to be imported or defined
                    }
                }
                newNoteNestingLevel = ui.getNestingLevel(parentNoteEl) + 1; // Assumes ui is global
            }
        } else {
             const rootNotes = Array.from(notesContainer.children).filter(child => child.classList.contains('note-item'));
             if(rootNotes.length > 0) newNoteNestingLevel = parseInt(rootNotes[0].style.getPropertyValue('--nesting-level') || '0');
        }
        
        let beforeElement = null;
        const siblingsInDom = Array.from(parentChildrenContainer.children)
            .filter(child => child.classList.contains('note-item'))
            .map(childEl => ({ element: childEl, order_index: getNoteDataById(childEl.dataset.noteId)?.order_index || Infinity }))
            .sort((a, b) => a.order_index - b.order_index);
        for (const sibling of siblingsInDom) {
            if (savedNote.order_index <= sibling.order_index) { beforeElement = sibling.element; break; }
        }
        
        const newNoteEl = ui.addNoteElement(savedNote, parentChildrenContainer, newNoteNestingLevel, beforeElement); // Assumes ui is global
        const newContentDiv = newNoteEl ? newNoteEl.querySelector('.note-content') : null;
        if (newContentDiv) ui.switchToEditMode(newContentDiv); // Assumes ui is global
    } catch (error) { console.error('Error creating sibling note:', error); }
}

async function handleTabKey(e, noteItem, noteData, contentDiv) {
    e.preventDefault();
    if (!noteData) return;
    const currentContentForTab = contentDiv.dataset.rawContent || contentDiv.textContent;
    if (currentContentForTab !== noteData.content) { await saveNoteImmediately(noteItem); noteData.content = currentContentForTab; }
    
    const originalNoteData = JSON.parse(JSON.stringify(noteData));
    const originalParentDomElement = noteItem.parentElement.closest('.note-item') || notesContainer;
    const originalNextSibling = noteItem.nextElementSibling;
    const originalNestingLevel = ui.getNestingLevel(noteItem); // Assumes ui is global

    let newParentNoteIdToSet = null; // For API call
    let newOrderIndex = noteData.order_index;

    if (e.shiftKey) { // Outdent
        if (!noteData.parent_note_id) return;
        const parentNoteData = getNoteDataById(noteData.parent_note_id);
        if (!parentNoteData) return;
        newParentNoteIdToSet = parentNoteData.parent_note_id; // This could be null for root
        const oldParentId = noteData.parent_note_id;
        
        // Update local data structure first
        noteData.parent_note_id = newParentNoteIdToSet;
        newOrderIndex = parentNoteData.order_index + 1; 
        noteData.order_index = newOrderIndex;
        // Adjust order_index of subsequent siblings of the original parent
        notesForCurrentPage.filter(n => n.parent_note_id === oldParentId && n.order_index > parentNoteData.order_index)
            .forEach(n => n.order_index--);
        updateNoteInCurrentPage(noteData); // Update the note in state

        // Update DOM
        const newParentDomElement = newParentNoteIdToSet ? getNoteElementById(newParentNoteIdToSet) : notesContainer;
        if (!newParentDomElement && newParentNoteIdToSet) { console.error("Could not find new parent in DOM for outdent"); return; }
        const newNestingLevel = newParentNoteIdToSet ? ui.getNestingLevel(newParentDomElement) + 1 : 0;
        const parentNoteElement = getNoteElementById(oldParentId);
        ui.moveNoteElement(noteItem, newParentDomElement || notesContainer, newNestingLevel, parentNoteElement ? parentNoteElement.nextElementSibling : null); // Assumes ui is global
    } else { // Indent
        const siblings = notesForCurrentPage.filter(n => n.parent_note_id === noteData.parent_note_id && n.order_index < noteData.order_index).sort((a, b) => b.order_index - a.order_index);
        if (siblings.length === 0) return;
        const newParentNoteData = siblings[0];
        newParentNoteIdToSet = newParentNoteData.id;

        // Update local data structure first
        noteData.parent_note_id = newParentNoteIdToSet;
        newOrderIndex = notesForCurrentPage.filter(n => String(n.parent_note_id) === String(newParentNoteIdToSet)).length -1; // -1 because current note is already in list
        noteData.order_index = newOrderIndex;
        updateNoteInCurrentPage(noteData); // Update the note in state

        // Update DOM
        const newParentDomElement = getNoteElementById(newParentNoteData.id);
        if (!newParentDomElement) { console.error("Could not find new parent in DOM for indent"); return; }
        const newNestingLevel = ui.getNestingLevel(newParentDomElement) + 1; // Assumes ui is global
        ui.moveNoteElement(noteItem, newParentDomElement, newNestingLevel); // Assumes ui is global
    }
    ui.switchToEditMode(contentDiv); // Assumes ui is global

    try {
        await notesAPI.updateNote(noteData.id, { content: noteData.content, parent_note_id: newParentNoteIdToSet, order_index: newOrderIndex }); // Assumes notesAPI is global
    } catch (error) {
        console.error('Error updating note parent/order (API):', error);
        alert('Error updating note structure. Reverting changes.');
        // Revert: This is complex, involves restoring notesForCurrentPage and DOM.
        // For simplicity in this step, full revert is omitted but would be needed in production.
        // notesForCurrentPage[notesForCurrentPage.findIndex(n => n.id === noteData.id)] = originalNoteData;
        // ui.moveNoteElement(noteItem, originalParentDomElement, originalNestingLevel, originalNextSibling);
        setNotesForCurrentPage(JSON.parse(sessionStorage.getItem('backupNotesBeforeTab')) || notesForCurrentPage); //簡易的なバックアップ・リストア
        ui.displayNotes(notesForCurrentPage, currentPageId); // Re-render all
        ui.switchToEditMode(contentDiv);
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
            ui.removeNoteElement(noteData.id); // Assumes ui is global
            if (noteToFocusAfterDelete) {
                const contentDivToFocus = noteToFocusAfterDelete.querySelector('.note-content');
                if (contentDivToFocus) ui.switchToEditMode(contentDivToFocus); // Assumes ui is global
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
        ui.switchToEditMode(nextNoteContent); // Assumes ui is global
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
    // This function is intended to be called IF e.target.matches('.task-checkbox') is true.
    // The main event listener in app.js will do this check.
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

    if (rawContent.startsWith('TODO ')) {
        if (isChecked) { newRawContent = 'DONE ' + rawContent.substring(5); newStatus = 'DONE'; doneAt = new Date().toISOString().slice(0, 19).replace('T', ' '); }
        else { newRawContent = rawContent; newStatus = 'TODO'; }
    } else if (rawContent.startsWith('DONE ')) {
        if (!isChecked) { newRawContent = 'TODO ' + rawContent.substring(5); newStatus = 'TODO'; }
        else { newRawContent = rawContent; newStatus = 'DONE'; doneAt = new Date().toISOString().slice(0, 19).replace('T', ' ');}
    } else if (rawContent.startsWith('CANCELLED ')) {
        checkbox.checked = true; return;
    } else {
        console.warn("Clicked task checkbox on a non-task or malformed note content:", rawContent);
        checkbox.checked = !checkbox.checked; return;
    }

    try {
        const updatedNoteServer = await notesAPI.updateNote(noteId, { content: newRawContent }); // Assumes notesAPI
        noteData.content = updatedNoteServer.content;
        noteData.updated_at = updatedNoteServer.updated_at;
        contentDiv.dataset.rawContent = updatedNoteServer.content;
        
        if (contentDiv.classList.contains('edit-mode')) contentDiv.textContent = updatedNoteServer.content;
        else contentDiv.innerHTML = ui.parseAndRenderContent(updatedNoteServer.content); // Assumes ui

        await propertiesAPI.setProperty({ entity_type: 'note', entity_id: parseInt(noteId), name: 'status', value: newStatus }); // Assumes propertiesAPI
        if (doneAt) await propertiesAPI.setProperty({ entity_type: 'note', entity_id: parseInt(noteId), name: 'done_at', value: doneAt });
        else { try { await propertiesAPI.deleteProperty('note', parseInt(noteId), 'done_at'); } catch (delError) { console.warn('Could not delete done_at:', delError); } }

        const updatedProperties = await propertiesAPI.getProperties('note', parseInt(noteId));
        noteData.properties = updatedProperties;
        updateNoteInCurrentPage(noteData);
        console.log('Task status updated: ' + newStatus, { noteId, newRawContent, doneAt });
    } catch (error) {
        console.error('Error updating task status:', error);
        alert('Failed to update task status: ' + error.message);
        checkbox.checked = !checkbox.checked;
        contentDiv.dataset.rawContent = noteData.content;
        if (contentDiv.classList.contains('edit-mode')) contentDiv.textContent = noteData.content;
        else contentDiv.innerHTML = ui.parseAndRenderContent(noteData.content); // Assumes ui
    }
}
