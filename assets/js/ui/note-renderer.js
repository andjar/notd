/**
 * UI Module for Note Rendering functionalities
 * Handles how notes and their components are displayed in the DOM.
 * @module ui/note-renderer
 */

import { saveNoteImmediately } from '../app/note-actions.js';
import { domRefs } from './dom-refs.js';
import { handleTransclusions } from '../app/page-loader.js';
import { attachmentsAPI, notesAPI } from '../api_client.js';
import { decrypt } from '../utils.js';
import { getCurrentPagePassword } from '../app/state.js';
import {
    showSuggestions,
    hideSuggestions,
    navigateSuggestions,
    getSelectedSuggestion,
    // We might need to set allPagesForSuggestions if it's not handled globally
    // For now, assuming it's populated elsewhere or will be handled in a future step.
    // setAllPagesForSuggestions
} from './page-link-suggestions.js';


// Anticipatory imports - these files/exports might not exist yet.
// import { handleNoteDrop } from './note-interactions.js'; // Likely not needed here, but was in instructions. handleNoteDrop is in note-elements.js
// import { focusOnNote } from './note-interactions.js'; // focusOnNote is used by renderNote's bullet click
// import { showGenericConfirmModal } from './modals.js'; // Potentially for error handling if needed directly by render functions.

// Globals assumed to be available: feather, Sortable, marked, window.notesForCurrentPage, window.currentPageId, displayNotes (potentially from main ui object for callbacks)

// Helper function to get link query info
/**
 * Checks if the cursor is inside a [[link query]] and returns information.
 * @param {HTMLElement} contentEl The content editable element.
 * @returns {{ query: string, triggerPosition: { top: number, left: number}, replaceStartOffset: number, replaceEndOffset: number, hasAutoClose: boolean } | null}
 */
function getLinkQueryInfo(contentEl) {
    const selection = window.getSelection();
    if (!selection.rangeCount) return null;

    const range = selection.getRangeAt(0);
    const fullText = contentEl.textContent;

    // Calculate globalCursorPos robustly using document.createRange()
    const tempRangeForOffset = document.createRange();
    tempRangeForOffset.selectNodeContents(contentEl);
    tempRangeForOffset.setEnd(range.startContainer, range.startOffset);
    const globalCursorPos = tempRangeForOffset.toString().length;

    const textBeforeGlobalCursor = fullText.substring(0, globalCursorPos);
    let openBracketIndex = textBeforeGlobalCursor.lastIndexOf('[[');

    if (openBracketIndex === -1) return null;

    const textBetweenOpenBracketAndCursor = fullText.substring(openBracketIndex + 2, globalCursorPos);
    if (textBetweenOpenBracketAndCursor.includes(']]')) {
        return null; // Cursor is after a fully formed link, not inside a query part
    }

    const query = textBetweenOpenBracketAndCursor;

    // Get cursor position for suggestion box placement
    let rect;
    const clientRects = range.getClientRects();
    if (clientRects.length > 0) {
        rect = clientRects[0];
    } else {
        // Fallback for empty content or specific situations
        const tempSpan = document.createElement('span');
        tempSpan.appendChild(document.createTextNode('\u200B')); // Zero-width space
        const tempRangeForRect = range.cloneRange();
        tempRangeForRect.insertNode(tempSpan);
        rect = tempSpan.getBoundingClientRect();
        tempSpan.remove();
    }
    if (!rect) return null;

    const position = {
        top: rect.bottom + window.scrollY,
        left: rect.left + window.scrollX
    };

    // Check for auto-closing brackets
    let hasAutoClose = false;
    let effectiveReplaceEndOffset = globalCursorPos;

    // Check if ']]' exists immediately after cursor
    if (fullText.startsWith(']]', globalCursorPos)) {
        hasAutoClose = true;
        effectiveReplaceEndOffset = globalCursorPos + 2;
    }

    return {
        query,
        triggerPosition: position,
        replaceStartOffset: openBracketIndex,
        replaceEndOffset: effectiveReplaceEndOffset,
        hasAutoClose
    };
}


/**
 * Renders a single note element with proper structure and event handling
 * @param {Object} note - Note object
 * @param {number} [nestingLevel=0] - Nesting level for indentation
 * @returns {HTMLElement} Rendered note element
 */
function renderNote(note, nestingLevel = 0) {
    const noteItemEl = document.createElement('div');
    noteItemEl.className = 'note-item';
    noteItemEl.dataset.noteId = note.id;
    noteItemEl.style.setProperty('--nesting-level', nestingLevel);

    // Add classes based on note state
    if (note.children && note.children.length > 0) {
        noteItemEl.classList.add('has-children');
    }
    if (note.collapsed) {
        noteItemEl.classList.add('collapsed');
    }
    if (note.is_encrypted) {
        noteItemEl.classList.add('encrypted-note');
    }

    // Controls section (drag handle and bullet)
    const controlsEl = document.createElement('div');
    controlsEl.className = 'note-controls';

    // Drag handle
    const dragHandleEl = document.createElement('span');
    dragHandleEl.className = 'note-drag-handle';
    dragHandleEl.innerHTML = '<i data-feather="menu"></i>';
    dragHandleEl.style.display = 'none'; // Hide the grip handle
    controlsEl.appendChild(dragHandleEl);

    // Bullet (common to all notes)
    const bulletEl = document.createElement('span');
    bulletEl.className = 'note-bullet';
    bulletEl.dataset.noteId = note.id;

    if (note.children && note.children.length > 0) {
        noteItemEl.classList.add('has-children'); 

        const arrowEl = document.createElement('span');
        arrowEl.className = 'note-collapse-arrow';
        arrowEl.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-right"><polyline points="9 18 15 12 9 6"></polyline></svg>`;
        arrowEl.dataset.noteId = note.id;
        arrowEl.dataset.collapsed = note.collapsed ? 'true' : 'false';

        const bulletElFromControls = controlsEl.querySelector('.note-bullet');
        const dragHandle = controlsEl.querySelector('.note-drag-handle');
        if (dragHandle) {
            controlsEl.insertBefore(arrowEl, dragHandle);
        } else if (bulletElFromControls) {
            controlsEl.insertBefore(arrowEl, bulletElFromControls);
        } else {
            controlsEl.appendChild(arrowEl);
        }
    }
    
    controlsEl.appendChild(dragHandleEl);
    controlsEl.appendChild(bulletEl);

    const contentWrapperEl = document.createElement('div');
    contentWrapperEl.className = 'note-content-wrapper';

    const contentEl = document.createElement('div');
    contentEl.className = 'note-content rendered-mode';
    contentEl.dataset.placeholder = 'Type to add content...';
    contentEl.dataset.noteId = note.id;

    // Handle encrypted content
    let displayContent = note.content || '';
    
    // First check if content is already decrypted (not a JSON string)
    const isAlreadyDecrypted = typeof note.content === 'string' && !note.content.startsWith('{');
    
    if (note.is_encrypted && note.content && !isAlreadyDecrypted) {
        // Only attempt decryption if the note is marked as encrypted
        // and the content is still in encrypted format (JSON string)
        const password = getCurrentPagePassword();
        if (password) {
            try {
                const decryptedContent = decrypt(password, note.content);
                if (decryptedContent !== null) {
                    displayContent = decryptedContent;
                    noteItemEl.classList.add('decrypted-note');
                } else {
                    displayContent = '[DECRYPTION FAILED]';
                    noteItemEl.classList.add('decryption-failed');
                }
            } catch (e) {
                console.error(`Decryption failed for note ${note.id}:`, e);
                displayContent = '[DECRYPTION FAILED]';
                noteItemEl.classList.add('decryption-failed');
            }
        } else {
            displayContent = '[ENCRYPTED CONTENT - Enter password to view]';
            noteItemEl.classList.add('needs-password');
        }
    } else if (isAlreadyDecrypted) {
        // Content is already decrypted, just use it
        displayContent = note.content;
        if (note.is_encrypted) {
            noteItemEl.classList.add('decrypted-note');
        }
    }

    contentEl.dataset.rawContent = displayContent;

    if (displayContent && displayContent.trim()) {
        contentEl.innerHTML = parseAndRenderContent(displayContent);
        if (typeof feather !== 'undefined') {
            feather.replace({ width: '18px', height: '18px' });
        }
    }

    contentWrapperEl.appendChild(contentEl);

    const attachmentsEl = document.createElement('div');
    attachmentsEl.className = 'note-attachments';
    contentWrapperEl.appendChild(attachmentsEl);

    if (note.id && (typeof note.id === 'number' || (typeof note.id === 'string' && !note.id.startsWith('temp-')))) {
        // Pass note.id and note.has_attachments flag
        renderAttachments(attachmentsEl, note.id, note.has_attachments);
    }

    contentWrapperEl.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        contentWrapperEl.classList.add('dragover');
    });

    contentWrapperEl.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        contentWrapperEl.classList.remove('dragover');
    });

    contentWrapperEl.addEventListener('drop', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        contentWrapperEl.classList.remove('dragover');

        const files = Array.from(e.dataTransfer.files);
        if (files.length > 0 && note.id && !String(note.id).startsWith('temp-')) {
            for (const file of files) {
                const formData = new FormData();
                formData.append('attachmentFile', file);
                formData.append('note_id', note.id);
                try {
                    await attachmentsAPI.uploadAttachment(formData);
                    const feedback = document.createElement('div');
                    feedback.className = 'copy-feedback'; 
                    feedback.textContent = `File "${file.name}" uploaded!`;
                    document.body.appendChild(feedback);
                    setTimeout(() => feedback.remove(), 3000);
                    
                    if (window.currentPageId && window.ui && typeof window.ui.displayNotes === 'function') {
                         const pageData = await notesAPI.getPageData(window.currentPageId);
                         window.notesForCurrentPage = pageData.notes; 
                         window.ui.displayNotes(pageData.notes, window.currentPageId); 
                    } else {
                        console.warn('displayNotes function not available for page refresh after D&D upload.')
                    }
                } catch (error) {
                    console.error('Error uploading file via drag & drop:', error);
                    alert(`Failed to upload file "${file.name}": ${error.message}`);
                }
            }
        } else if (String(note.id).startsWith('temp-')) {
            alert('Please save the note (by adding some content) before adding attachments.');
        }
    });

    const noteHeaderEl = document.createElement('div');
    noteHeaderEl.className = 'note-header-row';
    noteHeaderEl.appendChild(controlsEl);
    noteHeaderEl.appendChild(contentWrapperEl);

    noteItemEl.appendChild(noteHeaderEl);

    const childrenContainerEl = document.createElement('div');
    childrenContainerEl.className = 'note-children';
    if (note.collapsed) {
        childrenContainerEl.classList.add('collapsed');
    }

    if (note.children && note.children.length > 0) {
        note.children.forEach(childNote => {
            childrenContainerEl.appendChild(renderNote(childNote, nestingLevel + 1)); // Recursive call
        });
    }

    noteItemEl.appendChild(childrenContainerEl);

    if (typeof feather !== 'undefined' && feather.replace) {
        try {
            feather.replace();
        } catch (error) {
            console.warn('Feather icons replacement failed:', error);
        }
    }

    return noteItemEl;
}

/**
 * Switches a note content element to edit mode
 * @param {HTMLElement} contentEl - The note content element
 */
function switchToEditMode(contentEl) {
    if (contentEl.classList.contains('edit-mode')) return;

    // Use dataset.rawContent, which should now be plaintext
    let textToEdit = contentEl.dataset.rawContent || '';
    const noteId = contentEl.dataset.noteId;
    let suggestionBoxVisible = false;

    contentEl.classList.remove('rendered-mode');
    contentEl.classList.add('edit-mode');
    contentEl.contentEditable = true;
    contentEl.style.whiteSpace = 'pre-wrap';
    contentEl.innerHTML = '';
    contentEl.textContent = textToEdit;
    contentEl.focus();

    // Helper function for inserting link and updating state
    const insertSelectedPageLink = (selectedPageName) => {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            hideSuggestions();
            suggestionBoxVisible = false;
            return;
        }

        const queryInfo = getLinkQueryInfo(contentEl);
        if (!queryInfo) {
            hideSuggestions();
            suggestionBoxVisible = false;
            return;
        }

        // Helper function to map a character offset within contentEl.textContent to a DOM Node and offset pair
        function findDomPosition(parentElement, charOffset) {
            let accumulatedOffset = 0;
            let found = false;
            let result = null;
            // Walk all text nodes, including whitespace-only
            const walker = document.createTreeWalker(
                parentElement,
                NodeFilter.SHOW_TEXT,
                null, // Accept all text nodes
                false
            );
            let currentNode;
            while ((currentNode = walker.nextNode())) {
                const nodeLength = currentNode.textContent.length;
                if (accumulatedOffset + nodeLength >= charOffset) {
                    result = { node: currentNode, offset: charOffset - accumulatedOffset };
                    found = true;
                    break;
                }
                accumulatedOffset += nodeLength;
            }
            if (found) return result;
            // Fallback: if charOffset is beyond content length
            // Find the last text node
            let lastTextNode = null;
            const fallbackWalker = document.createTreeWalker(
                parentElement,
                NodeFilter.SHOW_TEXT,
                null,
                false
            );
            let n;
            while ((n = fallbackWalker.nextNode())) {
                lastTextNode = n;
            }
            if (lastTextNode) {
                return { node: lastTextNode, offset: lastTextNode.textContent.length };
            } else {
                // No text nodes at all: return parent with offset 0 (start) or offset = childNodes.length (end)
                if (charOffset <= 0) {
                    return { node: parentElement, offset: 0 };
                } else {
                    return { node: parentElement, offset: parentElement.childNodes.length };
                }
            }
        }

        try {
            // Create and set up the replacement range
            const replacementRange = document.createRange();
            const startDomPos = findDomPosition(contentEl, queryInfo.replaceStartOffset);
            const endDomPos = findDomPosition(contentEl, queryInfo.replaceEndOffset);

            replacementRange.setStart(startDomPos.node, startDomPos.offset);
            replacementRange.setEnd(endDomPos.node, endDomPos.offset);

            // Delete the old query text and insert the new link
            replacementRange.deleteContents();
            const newLinkTextNode = document.createTextNode(`[[${selectedPageName}]]`);
            replacementRange.insertNode(newLinkTextNode);

            // Position cursor after the inserted link
            selection.removeAllRanges();
            const newCursorRange = document.createRange();
            newCursorRange.setStartAfter(newLinkTextNode);
            newCursorRange.collapse(true);
            selection.addRange(newCursorRange);

            // Update the raw content dataset
            contentEl.dataset.rawContent = getRawTextWithNewlines(contentEl);

            // Clean up UI state
            hideSuggestions();
            suggestionBoxVisible = false;

            // Notify other listeners of the change
            const inputEvent = new Event('input', { 
                bubbles: true, 
                cancelable: true,
                composed: true // Allow the event to cross shadow DOM boundaries if needed
            });
            contentEl.dispatchEvent(inputEvent);

        } catch (error) {
            console.error('Error inserting page link:', error, {
                queryInfo,
                selectedPageName,
                contentLength: contentEl.textContent.length
            });
            
            // Attempt graceful fallback: append the link at the end
            try {
                const fallbackText = contentEl.textContent + ` [[${selectedPageName}]]`;
                contentEl.textContent = fallbackText;
                contentEl.dataset.rawContent = fallbackText;
                
                // Position cursor at end
                const range = document.createRange();
                range.selectNodeContents(contentEl);
                range.collapse(false); // collapse to end
                selection.removeAllRanges();
                selection.addRange(range);
            } catch (fallbackError) {
                console.error('Fallback link insertion also failed:', fallbackError);
            }

            hideSuggestions();
            suggestionBoxVisible = false;
        }
    };

    const handleInputForSuggestions = () => {
        const queryInfo = getLinkQueryInfo(contentEl);
        if (queryInfo) {
            showSuggestions(queryInfo.query, queryInfo.triggerPosition);
            suggestionBoxVisible = true;
        } else {
            hideSuggestions();
            suggestionBoxVisible = false;
        }
    };
    contentEl.addEventListener('input', handleInputForSuggestions);

    const handleKeydownForSuggestions = (event) => {
        if (!suggestionBoxVisible) return;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            navigateSuggestions(event.key);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const selectedPageName = getSelectedSuggestion();
            if (selectedPageName) {
                insertSelectedPageLink(selectedPageName);
            } else {
                hideSuggestions();
                suggestionBoxVisible = false;
            }
        } else if (event.key === 'Escape' || event.key === 'Tab') {
            event.preventDefault();
            hideSuggestions();
            suggestionBoxVisible = false;
        }
    };
    contentEl.addEventListener('keydown', handleKeydownForSuggestions);

    // Added listener for custom event from suggestion click
    const suggestionClickListener = (event) => {
        if (suggestionBoxVisible && event.detail && event.detail.pageName) {
             insertSelectedPageLink(event.detail.pageName);
        }
    };
    // Get a reference to the suggestionBox (it's global in page-link-suggestions.js, not ideal but working with it)
    const suggestionBoxElement = document.getElementById('page-link-suggestion-box');
    if (suggestionBoxElement) {
        suggestionBoxElement.addEventListener('suggestion-select', suggestionClickListener);
    } else {
        console.warn('Suggestion box element not found to attach click listener.');
    }

    const handleBlur = () => {
        setTimeout(() => {
            if (suggestionBoxVisible) {
                hideSuggestions();
                suggestionBoxVisible = false;
            }
        }, 150);

        switchToRenderedMode(contentEl);
        contentEl.removeEventListener('blur', handleBlur);
        contentEl.removeEventListener('paste', handlePasteImage);
        contentEl.removeEventListener('input', handleInputForSuggestions);
        contentEl.removeEventListener('keydown', handleKeydownForSuggestions);
        if (suggestionBoxElement) {
            suggestionBoxElement.removeEventListener('suggestion-select', suggestionClickListener); // Clean up
        }
    };
    contentEl.addEventListener('blur', handleBlur);

    const handlePasteImage = async (event) => {
        if (String(noteId).startsWith('temp-')) {
            alert('Please save the note (by adding some content) before pasting images.');
            return;
        }

        const items = (event.clipboardData || event.originalEvent.clipboardData).items;
        let imageFile = null;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                imageFile = items[i].getAsFile();
                break;
            }
        }

        if (imageFile) {
            event.preventDefault(); 
            console.log('Pasted image file:', imageFile);

            const formData = new FormData();
            const fileName = `pasted_image_${Date.now()}.${imageFile.name.split('.').pop() || 'png'}`;
            formData.append('attachmentFile', imageFile, fileName);
            formData.append('note_id', noteId);

            try {
                const result = await attachmentsAPI.uploadAttachment(formData);
                console.log('Pasted image uploaded successfully:', result);

                if (result && result.url) {
                    const currentRawContent = contentEl.textContent;
                    const imageMarkdown = `\n![Pasted Image](${result.url})\n`;
                    contentEl.textContent = currentRawContent + imageMarkdown;
                    contentEl.dataset.rawContent = contentEl.textContent;

                    const feedback = document.createElement('div');
                    feedback.className = 'copy-feedback';
                    feedback.textContent = 'Image pasted and uploaded!';
                    document.body.appendChild(feedback);
                    setTimeout(() => feedback.remove(), 3000);

                    if (window.currentPageId && window.ui && typeof window.ui.displayNotes === 'function') {
                         const pageData = await notesAPI.getPageData(window.currentPageId);
                         window.notesForCurrentPage = pageData.notes; 
                         window.ui.displayNotes(pageData.notes, window.currentPageId); 
                    } else {
                        console.warn('displayNotes function not available for page refresh after paste upload.')
                    }
                } else {
                    throw new Error('Upload result did not include a URL.');
                }
            } catch (error) {
                console.error('Error uploading pasted image:', error);
                alert(`Failed to upload pasted image: ${error.message}`);
            } 
        }
    }; 
    contentEl.addEventListener('paste', handlePasteImage);
}

/**
 * Extracts raw text content from an HTML element, converting BR tags to newlines,
 * and attempting to respect paragraph breaks from DIV/P tags.
 * @param {HTMLElement} element - The HTML element to extract text from.
 * @returns {string} The processed text content with newlines.
 */
function getRawTextWithNewlines(element) {
    let text = "";
    if (!element || !element.childNodes) return text;

    for (const node of element.childNodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            text += node.textContent;
        } else if (node.nodeName === "BR") {
            text += "\n";
        } else if (node.nodeName === "DIV" || node.nodeName === "P") {
            if (text.length > 0 && !text.endsWith("\n")) {
                text += "\n";
            }
            text += getRawTextWithNewlines(node); 
            if (!text.endsWith("\n")) {
                text += "\n";
            }
        } else {
            text += getRawTextWithNewlines(node);
        }
    }
    return text;
}

/**
 * Normalizes newline characters in a string.
 * Converts 3+ newlines to 2 (paragraph), and trims leading/trailing whitespace.
 * @param {string} str - The input string.
 * @returns {string} The normalized string.
 */
function normalizeNewlines(str) {
    if (typeof str !== 'string') return '';
    let normalized = str.replace(/\n\s*\n\s*\n+/g, '\n\n');
    normalized = normalized.trim();
    return normalized;
}

/**
 * Switches a note content element to rendered mode
 * @param {HTMLElement} contentEl - The note content element
 */
function switchToRenderedMode(contentEl) {
    const noteEl = contentEl.closest('.note-item');
    if (noteEl && noteEl.dataset.noteId && !noteEl.dataset.noteId.startsWith('temp-')) {
        // It's important that saveNoteImmediately is available in this scope.
        // Assuming it's imported or globally available.
        // console.log('[DEBUG switchToRenderedMode] Calling saveNoteImmediately for noteId:', noteEl.dataset.noteId);
        saveNoteImmediately(noteEl);
    }
    if (contentEl.classList.contains('rendered-mode')) return;

    const rawTextValue = getRawTextWithNewlines(contentEl);
    const newContent = normalizeNewlines(rawTextValue);
    
    contentEl.dataset.rawContent = newContent;
    
    contentEl.classList.remove('edit-mode');
    contentEl.classList.add('rendered-mode');
    contentEl.contentEditable = false;
    contentEl.style.whiteSpace = '';

    // Ensure suggestions are hidden when switching to rendered mode
    hideSuggestions();
    // suggestionBoxVisible = false; // This variable is local to switchToEditMode

    if (newContent.trim()) {
        const tempDiv = document.createElement('div');
        tempDiv.textContent = newContent;
        const decodedContent = tempDiv.textContent;
        contentEl.innerHTML = parseAndRenderContent(decodedContent);
    } else {
        contentEl.innerHTML = '';
    }

    // Call handleTransclusions here
    handleTransclusions();
    // Ensure suggestion box is hidden if it was somehow left open
    // This is a fallback, should be handled by blur or selection.
    hideSuggestions();
}

/**
 * Parses and renders note content with special formatting
 * @param {string} rawContent - Raw note content
 * @returns {string} HTML string for display
 */
function parseAndRenderContent(rawContent) {
    // rawContent is now assumed to be plaintext if it was meant to be decrypted.
    // Decryption is handled by page-loader.js before this function is called.
    let html = rawContent || '';

    // Handle task markers with checkboxes - don't show the TODO/DONE prefix in content
    if (html.startsWith('TODO ')) {
        const taskContent = html.substring(5);
        html = `
            <div class="task-container todo">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="TODO" />
                    <span class="task-status-badge todo">TODO</span>
                </div>
                <div class="task-content">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('DOING ')) {
        const taskContent = html.substring(6);
        html = `
            <div class="task-container doing">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="DOING" />
                    <span class="task-status-badge doing">DOING</span>
                </div>
                <div class="task-content">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('SOMEDAY ')) {
        const taskContent = html.substring(8);
        html = `
            <div class="task-container someday">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="SOMEDAY" />
                    <span class="task-status-badge someday">SOMEDAY</span>
                </div>
                <div class="task-content">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('DONE ')) {
        const taskContent = html.substring(5);
        html = `
            <div class="task-container done">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="DONE" checked />
                    <span class="task-status-badge done">DONE</span>
                </div>
                <div class="task-content done-text">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('WAITING ')) {
        const taskContent = html.substring(8);
        html = `
            <div class="task-container waiting">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="WAITING" />
                    <span class="task-status-badge waiting">WAITING</span>
                </div>
                <div class="task-content">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('CANCELLED ')) {
        const taskContent = html.substring(10);
        html = `
            <div class="task-container cancelled">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="CANCELLED" checked disabled />
                    <span class="task-status-badge cancelled">CANCELLED</span>
                </div>
                <div class="task-content cancelled-text">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('NLR ')) {
        const taskContent = html.substring(4);
        html = `
            <div class="task-container nlr">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="NLR" checked disabled />
                    <span class="task-status-badge nlr">NLR</span>
                </div>
                <div class="task-content nlr-text">${taskContent}</div>
            </div>
        `;
    } else {
        // Handle inline properties first
        // Regex: /\{([^:]+):(:{1,2})([^}]+)\}/g
        // matches[1]: key
        // matches[2]: additional colons (":" for "::", "::" for ":::")
        // matches[3]: value
        html = html.replace(/\{([^:]+):(:{1,2})([^}]+)\}/g, (match, key, additionalColons, value) => {
            const trimmedKey = key.trim();
            const trimmedValue = value.trim();
            const isInternal = additionalColons === '::'; // True if original was {key:::value}

            if (isInternal && (!window.APP_CONFIG || window.APP_CONFIG.RENDER_INTERNAL_PROPERTIES === false)) {
                return ''; // Omit internal property if RENDER_INTERNAL_PROPERTIES is false
            }

            let propertyClass = 'property-inline';
            let separator = isInternal ? ':::' : '::';
            
            if (isInternal) {
                propertyClass += ' property-internal';
            } else {
                propertyClass += ' property-normal'; // Explicitly class normal properties
            }

            if (trimmedKey.toLowerCase() === 'tag') {
                // Tags are typically not marked internal this way, but if they were:
                return `<span class="${propertyClass} property-tag"><span class="property-key">#</span><span class="property-value">${trimmedValue}</span></span>`;
            } else if (trimmedKey.toLowerCase() === 'alias') {
                 return `<span class="${propertyClass} alias-property"><span class="property-key">Alias</span><span class="property-separator">${separator}</span><span class="property-value">${trimmedValue}</span></span>`;
            } else {
                return `<span class="${propertyClass}"><span class="property-key">${trimmedKey}</span><span class="property-separator">${separator}</span><span class="property-value">${trimmedValue}</span></span>`;
            }
        });

        // Handle page links
        html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => {
            const trimmedName = pageName.trim();
            return `<span class="page-link-bracket">[[</span><a href="#" class="page-link" data-page-name="${trimmedName}">${trimmedName}</a><span class="page-link-bracket">]]</span>`;
        });

        // Handle SQL Queries SQL{...} - This should happen before Markdown parsing of the query content itself.
        const sqlQueryRegex = /SQL\{([^}]+)\}/g;
        html = html.replace(sqlQueryRegex, (match, sqlQuery) => {
            // Ensure quotes within the SQL query are properly escaped for the HTML attribute
            const escapedSqlQuery = sqlQuery.replace(/"/g, '"');
            return `<div class="sql-query-placeholder" data-sql-query="${escapedSqlQuery}">Loading SQL Query...</div>`;
        });

        html = html.replace(/!\{\{(.*?)\}\}/g, (match, blockRef) => {
            const trimmedRef = blockRef ? blockRef.trim() : '';
            if (/^\d+$/.test(trimmedRef)) {
                return `<div class="transclusion-placeholder" data-block-ref="${trimmedRef}">Loading...</div>`;
            } else {
                return `<div class="transclusion-placeholder error" data-block-ref="${trimmedRef}">Invalid block reference</div>`;
            }
        });

        if (typeof marked !== 'undefined' && marked.parse) {
            try {
                const renderer = new marked.Renderer();
                const originalImageRenderer = renderer.image;
                renderer.image = (href, title, text) => {
                    let imageHTML = originalImageRenderer.call(renderer, href, title, text);
                    imageHTML = imageHTML.replace(/^<img(.*?)>/, 
                        `<img$1 class="content-image" data-original-src="${href}" style="max-width: 200px; cursor: pointer;">`);
                    return imageHTML;
                };

                const originalHtml = html;
                html = marked.parse(html, {
                    renderer: renderer, 
                    breaks: true,
                    gfm: true,
                    smartypants: true,
                    sanitize: false, 
                    smartLists: true
                });
                // console.log('Markdown processed with custom image renderer:', { original: originalHtml, processed: html });
            } catch (e) {
                console.warn('Marked.js parsing error:', e);
            }
        } else {
            console.warn('marked.js not loaded properly or missing parse method');
        }
    }
    return html;
}

window.parseAndRenderContent = parseAndRenderContent;

/**
 * Renders attachments for a note
 * @param {HTMLElement} container - The container element to render attachments into
 * @param {string} noteId - The ID of the note these attachments belong to
 * @param {boolean|number|undefined} has_attachments_flag - Flag indicating if attachments exist
 */
async function renderAttachments(container, noteId, has_attachments_flag) {
    // If has_attachments_flag is explicitly false, clear container, hide, and return.
    if (has_attachments_flag === false) {
        container.innerHTML = ''; // Clear any existing content
        container.style.display = 'none'; // Hide the container
        return;
    }

    // If has_attachments_flag is true, proceed to fetch and render.
    // If undefined (e.g. legacy calls or error states), also proceed to fetch as a fallback.
    // This means an API call will be made if has_attachments_flag is true or undefined.
    
    // Ensure container is visible before attempting to fetch, in case it was previously hidden.
    // We will hide it again if no attachments are found after fetching.
    container.style.display = ''; // Reset display style

    try {
        const noteAttachments = await attachmentsAPI.getNoteAttachments(noteId);
        
        container.innerHTML = ''; // Clear previous content

        if (!noteAttachments || noteAttachments.length === 0) {
            container.style.display = 'none'; // Hide if no attachments are found
            return;
        }
        
        // If attachments are found, ensure the main container is visible (it was reset above)
        // and the inner attachmentsContainer will be 'flex'

        const attachmentsContainer = document.createElement('div');
        // The class 'note-attachments' might already be on the 'container' element itself.
        // If 'container' is just a generic div, then adding this class to a child is appropriate.
        // Based on existing code, `container` IS the element with class `note-attachments`.
        // So, we should append items directly to `container` or manage `attachmentsContainer` carefully.
        // For now, let's assume `container` is the one to be styled and filled.
        // Re-evaluating: The original code appends `attachmentsContainer` to `container`. This is fine.

        container.appendChild(attachmentsContainer); // This was the original structure.

        noteAttachments.forEach(attachment => {
            const attachmentEl = document.createElement('div');
            attachmentEl.className = 'note-attachment-item';
            attachmentEl.dataset.attachmentId = attachment.id;

            let previewEl = '';
            const isImage = attachment.type && attachment.type.startsWith('image/');

            if (isImage) {
                previewEl = `<img src="${attachment.url}" alt="${attachment.name}" class="attachment-preview-image">`;
            } else {
                previewEl = `<i data-feather="file" class="attachment-preview-icon"></i>`;
            }

            const linkEl = document.createElement('a');
            linkEl.href = attachment.url;
            linkEl.className = 'attachment-name';
            if (!isImage) {
                linkEl.target = '_blank'; 
            }
            linkEl.textContent = attachment.name;

            attachmentEl.innerHTML = `
                <div class="attachment-preview">${previewEl}</div>
                <div class="attachment-info">
                    ${linkEl.outerHTML} 
                    <span class="attachment-meta">${attachment.type} - ${new Date(attachment.created_at).toLocaleDateString()}</span>
                </div>
                <button class="attachment-delete-btn" data-attachment-id="${attachment.id}" data-note-id="${noteId}">
                    <i data-feather="trash-2"></i>
                </button>
            `;

            attachmentsContainer.appendChild(attachmentEl);

            if (isImage) {
                const imageLink = attachmentEl.querySelector('.attachment-name');
                if (imageLink) {
                    imageLink.dataset.attachmentUrl = attachment.url;
                    imageLink.classList.add('delegated-attachment-image');
                }
            }
        });

        if (attachmentsContainer.children.length > 0) {
            // The main `container` should be visible (already set by `container.style.display = '';`)
            // The `attachmentsContainer` (inner list) can be flex if it has items.
            attachmentsContainer.style.display = 'flex'; // This was on attachmentsContainer before
            container.style.display = 'flex'; // Ensure the main container is also flex if it wasn't.
                                               // Or, if `container` is already styled by CSS, this might be redundant
                                               // or conflict. Let's assume `container`'s display is managed by its parent or CSS.
                                               // The original code set `attachmentsContainer.style.display = 'flex'`.
        } else {
            // This case should be caught by `if (!noteAttachments || noteAttachments.length === 0)` above.
            // If somehow it's reached, hide the main container.
            container.style.display = 'none';
        }

        if (typeof feather !== 'undefined' && feather.replace) {
            feather.replace();
        }
    } catch (error) {
        console.error('Error rendering attachments:', error);
        container.innerHTML = '<small>Could not load attachments.</small>';
        container.style.display = 'block'; // Ensure error message is visible
    }
}

/**
 * Renders properties for a note
 * @param {HTMLElement} container - Container element for properties
 * @param {Object} properties - Properties object
 */
function renderProperties(container, properties) {
    container.innerHTML = '';
    if (!properties || Object.keys(properties).length === 0) {
        container.style.display = 'none';
        return;
    }

    let htmlToSet = '';
    Object.entries(properties).forEach(([name, propValueEntries]) => {
        // Ensure propValueEntries is always an array for consistent processing
        const entries = Array.isArray(propValueEntries) ? propValueEntries : [propValueEntries];

        entries.forEach(item => {
            // Each item is expected to be an object like {value: "...", internal: true/false}
            // or a simple value for older/non-internal properties if structure varies.
            let valueToRender;
            let isItemInternal = false;

            if (item && typeof item === 'object' && item.hasOwnProperty('value') && item.hasOwnProperty('internal')) {
                valueToRender = item.value;
                isItemInternal = item.internal;
            } else if (typeof item !== 'object') { // Legacy: simple value, assume not internal
                valueToRender = item;
                isItemInternal = false; // Default for simple values
            } else {
                return; // Skip malformed items
            }

            if (isItemInternal && (!window.APP_CONFIG || window.APP_CONFIG.RENDER_INTERNAL_PROPERTIES === false)) {
                return; // Skip this internal item based on config
            }

            let itemClass = "property-item";
            if (isItemInternal) {
                itemClass += " property-item-internal";
            } else {
                itemClass += " property-item-normal";
            }

            if (name.toLowerCase() === 'favorite' && String(valueToRender).toLowerCase() === 'true') {
                htmlToSet += `<span class="${itemClass} favorite"><span class="property-favorite">⭐</span></span>`;
            } else if (name.startsWith('tag::') || name.toLowerCase() === 'tags' || name.toLowerCase() === 'tag') {
                htmlToSet += `<span class="${itemClass} tag">
                    <span class="property-key">#</span>
                    <span class="property-value">${valueToRender}</span>
                </span>`;
            } else {
                htmlToSet += `<span class="${itemClass}">
                    <span class="property-key">${name}</span>
                    <span class="property-value">${valueToRender}</span>
                </span>`;
            }
        });
    });

    if (htmlToSet) {
        container.innerHTML = htmlToSet;
        container.style.display = 'flex'; 
        container.style.flexWrap = 'wrap'; 
        container.style.gap = 'var(--ls-space-2, 8px)'; 
        container.classList.remove('hidden'); 
    } else {
        container.style.display = 'none';
    }
}


export {
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    switchToRenderedMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties,
    initializeDelegatedNoteEventListeners // Exporting the initializer
};

// --- Delegated Event Handler Functions ---

/**
 * Opens a modal to display an image.
 * @param {string} imageUrl The URL of the image to display.
 */

function openImageViewerModal(imageUrl) {
    if (!imageUrl) return;

    if (domRefs.imageViewerModal && domRefs.imageViewerModalImg && domRefs.imageViewerModalClose) {
        domRefs.imageViewerModalImg.src = imageUrl;
        domRefs.imageViewerModal.classList.add('active');

        const closeImageModal = () => {
            domRefs.imageViewerModal.classList.remove('active');
            domRefs.imageViewerModalImg.src = '';
        };

        const outsideClickHandlerForModal = (event) => {
            if (event.target === domRefs.imageViewerModal) {
                closeImageModal();
            }
        };

        domRefs.imageViewerModalClose.addEventListener('click', closeImageModal, { once: true });
        domRefs.imageViewerModal.addEventListener('click', outsideClickHandlerForModal, { once: true });
    } else {
        console.error('Image viewer modal elements not found.');
        window.open(imageUrl, '_blank');
    }
}


// custom confirmation dialog for delete actions

async function showConfirmationDialog(message) {
    return new Promise((resolve) => {
        // Create modal wrapper
        const modal = document.createElement('div');
        modal.className = 'custom-confirm-modal';
        Object.assign(modal.style, {
            position: 'fixed',
            top: '0',
            left: '0',
            width: '100%',
            height: '100%',
            backgroundColor: 'rgba(0,0,0,0.4)',
            display: 'flex',
            justifyContent: 'center',
            alignItems: 'center',
            zIndex: '9999',
        });

        // Create dialog box
        const dialog = document.createElement('div');
        Object.assign(dialog.style, {
            backgroundColor: '#fff',
            padding: '20px',
            borderRadius: '8px',
            boxShadow: '0 8px 20px rgba(0,0,0,0.2)',
            maxWidth: '400px',
            width: '90%',
        });

        const msg = document.createElement('p');
        msg.textContent = message;
        msg.style.marginBottom = '20px';

        const buttonGroup = document.createElement('div');
        Object.assign(buttonGroup.style, {
            display: 'flex',
            justifyContent: 'flex-end',
            gap: '10px',
        });

        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.padding = '8px 16px';
        cancelBtn.style.background = '#e0e0e0';
        cancelBtn.style.border = 'none';
        cancelBtn.style.borderRadius = '4px';
        cancelBtn.style.cursor = 'pointer';

        const deleteBtn = document.createElement('button');
        deleteBtn.textContent = 'Delete';
        deleteBtn.style.padding = '8px 16px';
        deleteBtn.style.background = '#e53935';
        deleteBtn.style.color = '#fff';
        deleteBtn.style.border = 'none';
        deleteBtn.style.borderRadius = '4px';
        deleteBtn.style.cursor = 'pointer';

        cancelBtn.addEventListener('click', () => {
            modal.remove();
            resolve(false);
        });

        deleteBtn.addEventListener('click', () => {
            modal.remove();
            resolve(true);
        });

        buttonGroup.append(cancelBtn, deleteBtn);
        dialog.append(msg, buttonGroup);
        modal.appendChild(dialog);
        document.body.appendChild(modal);
    });
}





async function handleDelegatedCollapseArrowClick(targetElement) {
    const noteItem = targetElement.closest('.note-item');
    const noteId = noteItem?.dataset.noteId;
    if (!noteId) return;

    const childrenContainer = noteItem.querySelector('.note-children');
    const isCurrentlyCollapsed = noteItem.classList.toggle('collapsed');
    
    if (childrenContainer) {
        childrenContainer.classList.toggle('collapsed', isCurrentlyCollapsed);
    }
    targetElement.dataset.collapsed = isCurrentlyCollapsed.toString();

    try {
        // Use the notesAPI.batchUpdateNotes function
        const result = await notesAPI.batchUpdateNotes([{
            type: 'update',
            payload: {
                id: noteId,
                page_id: window.currentPageId,
                collapsed: isCurrentlyCollapsed ? 1 : 0
            }
        }]);

        // Update local cache
        if (window.notesForCurrentPage) {
            const noteToUpdate = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
            if (noteToUpdate) {
                noteToUpdate.collapsed = isCurrentlyCollapsed;
            }
        }
    } catch (error) {
        const errorMessage = error.message || 'Please try again.';
        console.error(`handleDelegatedCollapseArrowClick: Error saving collapse state for note ${noteId}. Error:`, error);
        console.error('Full error details:', {
            noteId,
            isCurrentlyCollapsed,
            currentPageId: window.currentPageId,
            error
        });
        
        // Revert UI changes on error
        noteItem.classList.toggle('collapsed'); // Toggle back
        if (childrenContainer) childrenContainer.classList.toggle('collapsed'); // Toggle back
        targetElement.dataset.collapsed = (!isCurrentlyCollapsed).toString();
        
        // Show feedback to user - using a temporary feedback div for this non-critical error
        const feedback = document.createElement('div');
        feedback.className = 'copy-feedback error-feedback'; // Added 'error-feedback' for specific styling
        feedback.textContent = `Failed to save collapse state: ${errorMessage}`;
        document.body.appendChild(feedback);
        setTimeout(() => feedback.remove(), 3000); // Longer timeout for errors
    }
}

function handleDelegatedBulletClick(targetElement) {
    const noteId = targetElement.dataset.noteId;
    if (!noteId) return;

    if (typeof focusOnNote === 'function') {
         focusOnNote(noteId);
    } else if (window.ui && typeof window.ui.focusOnNote === 'function') {
        window.ui.focusOnNote(noteId);
    } else {
        console.warn('focusOnNote function not available.');
    }
}

async function handleDelegatedBulletContextMenu(event, targetElement) {
    event.preventDefault();
    const noteId = targetElement.dataset.noteId;
    const notePageId = targetElement.closest('.note-item')?.dataset.pageId; // Assuming pageId might be on note-item

    if (!noteId) return;
    
    // Remove any existing context menus
    document.querySelectorAll('.bullet-context-menu').forEach(menu => menu.remove());

    const menu = document.createElement('div');
    menu.className = 'bullet-context-menu';
    // Note: Using data-note-id on menu items to pass noteId to the action handler
    menu.innerHTML = `
        <div class="menu-item" data-action="copy-transclusion" data-note-id="${noteId}">
            <i data-feather="link"></i> Copy transclusion link
        </div>
        <div class="menu-item" data-action="delete" data-note-id="${noteId}">
            <i data-feather="trash-2"></i> Delete
        </div>
        <div class="menu-item" data-action="upload" data-note-id="${noteId}" data-page-id="${notePageId || window.currentPageId}">
            <i data-feather="upload"></i> Upload attachment
        </div>
        <div class="menu-item" data-action="open-zen" data-note-id="${noteId}">
            <i data-feather="edit-3"></i> Open in Zen Writer
        </div>
    `;
    menu.style.position = 'fixed';
    menu.style.left = `${event.pageX}px`;
    menu.style.top = `${event.pageY}px`;

    const handleMenuAction = async (actionEvent) => {
        const selectedMenuItem = actionEvent.target.closest('.menu-item');
        if (!selectedMenuItem) return;
        
        const action = selectedMenuItem.dataset.action;
        const actionNoteId = selectedMenuItem.dataset.noteId; // Get noteId from the item
        const actionPageId = selectedMenuItem.dataset.pageId;


        switch (action) {
            case 'copy-transclusion':
                const transclusionLink = `!{{${actionNoteId}}}`;
                await navigator.clipboard.writeText(transclusionLink);
                // Show feedback (consider a global feedback function)
                const feedbackCopy = document.createElement('div');
                feedbackCopy.className = 'copy-feedback';
                feedbackCopy.textContent = 'Transclusion link copied!';
                document.body.appendChild(feedbackCopy);
                setTimeout(() => feedbackCopy.remove(), 2000);
                break;
            case 'delete':
                if (confirm(`Are you sure you want to delete note ${actionNoteId}?`)) {
                    try {
                        await notesAPI.deleteNote(actionNoteId);
                        document.querySelector(`.note-item[data-note-id="${actionNoteId}"]`)?.remove();
                        // Also remove from window.notesForCurrentPage
                        if (window.notesForCurrentPage) {
                            window.notesForCurrentPage = window.notesForCurrentPage.filter(n => String(n.id) !== String(actionNoteId));
                        }
                    } catch (error) {
                        const deleteErrorMessage = error.message || 'Please try again.';
                        console.error(`handleDelegatedBulletContextMenu (delete action): Error deleting note ${actionNoteId}. Error:`, error);
                        alert(`Failed to delete note. ${deleteErrorMessage}`);
                    }
                }
                break;
            case 'upload':
                const input = document.createElement('input');
                input.type = 'file';
                input.multiple = true;
                input.onchange = async (uploadEvent) => {
                    const files = Array.from(uploadEvent.target.files);
                    for (const file of files) {
                        const formData = new FormData();
                        formData.append('attachmentFile', file);
                        formData.append('note_id', actionNoteId);
                        try {
                            await attachmentsAPI.uploadAttachment(formData);
                            const feedbackUpload = document.createElement('div');
                            feedbackUpload.className = 'copy-feedback'; // Success feedback
                            feedbackUpload.textContent = `File "${file.name}" uploaded successfully!`;
                            document.body.appendChild(feedbackUpload);
                            setTimeout(() => feedbackUpload.remove(), 3000);
                            if (window.ui && typeof window.ui.displayNotes === 'function' && actionPageId) {
                                const pageData = await notesAPI.getPageData(actionPageId); // Refresh page
                                window.ui.displayNotes(pageData.notes, actionPageId);
                            } else {
                                console.warn('handleDelegatedBulletContextMenu (upload action): displayNotes function or pageId not available to refresh after upload for noteId:', actionNoteId);
                            }
                        } catch (error) {
                            const uploadErrorMessage = error.message || 'Please try again.';
                            console.error(`handleDelegatedBulletContextMenu (upload action): Error uploading file "${file.name}" for note ${actionNoteId}. Error:`, error);
                            alert(`Failed to upload file "${file.name}". ${uploadErrorMessage}`);
                        }
                    }
                };
                input.click();
                break;
        }
        menu.remove(); 
        document.removeEventListener('click', closeMenuOnClickOutside); 
    };

    menu.addEventListener('click', handleMenuAction);

    const closeMenuOnClickOutside = (closeEvent) => {
        if (!menu.contains(closeEvent.target)) {
            menu.remove();
            document.removeEventListener('click', closeMenuOnClickOutside);
        }
    };
    // Add timeout to allow current event loop to finish before attaching,
    // preventing immediate close if contextmenu was via click.
    setTimeout(() => {
        document.addEventListener('click', closeMenuOnClickOutside);
    }, 0);


    document.body.appendChild(menu);
    if (typeof feather !== 'undefined') feather.replace();
}





function handleDelegatedNoteContentClick(targetElement) {
    // Check if the click was on an interactive element within the content that should not trigger edit mode
    if (targetElement.matches('.task-checkbox, .page-link, .property-inline, .task-status-badge, .sql-query-placeholder, .transclusion-placeholder, .content-image') || 
        targetElement.closest('.task-checkbox, .page-link, .property-inline, .task-status-badge, .sql-query-placeholder, .transclusion-placeholder, .content-image')) {
        return;
    }
    switchToEditMode(targetElement);
}





async function handleDelegatedAttachmentDelete(targetElement) {
    const attachmentId = targetElement.dataset.attachmentId;
    const noteId = targetElement.dataset.noteId;
    const attachmentItem = targetElement.closest('.note-attachment-item');
    const attachmentName = attachmentItem?.querySelector('.attachment-name')?.textContent || 'this attachment';

    if (!attachmentId || !noteId) return;

    const confirmed = await showConfirmationDialog(`Are you sure you want to delete "${attachmentName}"?`);
    if (!confirmed) return;

    try {
        console.log("Deleting:", { attachmentId, noteId });

        await attachmentsAPI.deleteAttachment(attachmentId, noteId);
        attachmentItem?.remove();

        const attachmentsContainer = targetElement.closest('.note-attachments');
        if (attachmentsContainer && attachmentsContainer.children.length === 0) {
            attachmentsContainer.style.display = 'none';
        }

        if (window.notesForCurrentPage) {
            const noteToUpdate = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
            if (noteToUpdate) {
                const remaining = attachmentsContainer ? attachmentsContainer.children.length : 0;
                noteToUpdate.has_attachments = remaining > 0;
            }
        }
    } catch (error) {
        const msg = error.message || 'Please try again.';
        console.error(`Failed to delete attachment ${attachmentId} for note ${noteId}:`, error);
        alert(`Failed to delete attachment "${attachmentName}". ${msg}`);
    }
}




function handleDelegatedAttachmentImageView(targetElement) {
    let attachmentUrl = targetElement.dataset.attachmentUrl;
    if (!attachmentUrl) {
        // This logic handles clicking the <img> preview instead of the <a> link.
        const imgPreview = targetElement.closest('.attachment-preview-image');
        if (imgPreview) {
           const attachmentItem = imgPreview.closest('.note-attachment-item');
           // Find the sibling link that holds the data
           const linkElement = attachmentItem?.querySelector('.attachment-name[data-attachment-url]');
           if (linkElement) {
               attachmentUrl = linkElement.dataset.attachmentUrl;
           }
        }
    }

    if (attachmentUrl) {
        openImageViewerModal(attachmentUrl);
    }
}

async function handleDelegatedTaskCheckboxClick(checkbox) {
    const noteItem = checkbox.closest('.note-item');
    const contentEl = noteItem?.querySelector('.note-content');
    if (!noteItem || !contentEl) return;

    const noteId = noteItem.dataset.noteId;
    if (!noteId || noteId.startsWith('temp-')) return;
    
    let rawContent = contentEl.dataset.rawContent;
    const currentMarker = checkbox.dataset.markerType.toUpperCase();
    let newContent;
    let newMarker;

    // Logic based on current state and the new checked status of the box
    const isChecked = checkbox.checked;

    if (isChecked) { // The user is checking the box (moving to a "done" state)
        if (['TODO', 'DOING', 'WAITING', 'SOMEDAY'].includes(currentMarker)) {
            newMarker = 'DONE';
        }
    } else { // The user is un-checking the box (moving to an "active" state)
        if (currentMarker === 'DONE') {
            newMarker = 'TODO';
        }
    }
    
    // If no state change is defined, or for un-clickable types, revert the checkbox and exit
    if (!newMarker) {
        checkbox.checked = !isChecked; // Revert visual change
        return;
    }

    // Find the original content part by stripping the old marker
    let contentPart = '';
    const prefixes = ['TODO ', 'DONE ', 'DOING ', 'SOMEDAY ', 'WAITING ', 'CANCELLED ', 'NLR '];
    for (const prefix of prefixes) {
        if (rawContent.startsWith(prefix)) {
            contentPart = rawContent.substring(prefix.length);
            break;
        }
    }
    // Fallback if no prefix found
    if (contentPart === '' && rawContent.includes(' ')) {
        contentPart = rawContent.substring(rawContent.indexOf(' ') + 1);
    } else if (contentPart === '') {
        contentPart = rawContent;
    }

    newContent = `${newMarker} ${contentPart}`;
    
    // Update the raw content dataset for the next save
    contentEl.dataset.rawContent = newContent;
    
    // The visual state of the checkbox is already correct from the user click.
    // Now, update the rest of the UI optimistically.
    const taskContainer = checkbox.closest('.task-container');
    if (taskContainer) {
        taskContainer.classList.remove(currentMarker.toLowerCase());
        taskContainer.classList.add(newMarker.toLowerCase());
        
        const badge = taskContainer.querySelector('.task-status-badge');
        badge.className = `task-status-badge ${newMarker.toLowerCase()}`;
        badge.textContent = newMarker;

        const taskContentDiv = taskContainer.querySelector('.task-content');
        taskContentDiv.classList.toggle('done-text', newMarker === 'DONE');
        
        checkbox.dataset.markerType = newMarker;
    }

    // Save the change
    try {
        await saveNoteImmediately(noteItem);
    } catch (error) {
        console.error(`Error saving task state for note ${noteId}:`, error);
        alert('Failed to save the task state change. Please refresh the page.');
    }
}

/**
 * Renders the content of a fetched transclusion into its placeholder.
 * @param {HTMLElement} placeholderEl - The placeholder element to be replaced.
 * @param {string} noteContent - The raw content of the note to be rendered inside the placeholder.
 * @param {string} noteId - The ID of the transcluded note, for creating a link.
 */
export function renderTransclusion(placeholderEl, noteContent, noteId) {
    if (!placeholderEl) return;

    // Create a container for the transcluded content
    const contentEl = document.createElement('div');
    contentEl.className = 'transcluded-content';
    
    // Parse the fetched note content into HTML
    const renderedHTML = parseAndRenderContent(noteContent);

    // Create a header with a link to the original note
    const headerEl = document.createElement('div');
    headerEl.className = 'transclusion-header';
    headerEl.innerHTML = `<a href="#" class="transclusion-link" data-note-id="${noteId}" title="Go to original note"><i data-feather="corner-up-left"></i></a>`;

    const bodyEl = document.createElement('div');
    bodyEl.className = 'transclusion-body';
    bodyEl.innerHTML = renderedHTML;

    contentEl.appendChild(headerEl);
    contentEl.appendChild(bodyEl);
    
    // Replace the placeholder with the new content element
    placeholderEl.replaceWith(contentEl);

    // Re-run Feather icons for the new link icon
    if (typeof feather !== 'undefined') {
        feather.replace({ width: '1em', height: '1em' });
    }
}


/**
 * Initializes delegated event listeners for notes on the provided container.
 * This function should be called once after the main notes container is in the DOM.
 * @param {HTMLElement} notesContainerEl - The main container where notes are rendered.
 */
function initializeDelegatedNoteEventListeners(notesContainerEl) {
    if (!notesContainerEl) {
        console.error("Notes container not provided for event delegation.");
        return;
    }

    notesContainerEl.addEventListener('click', (event) => {
        const target = event.target;
        
        // Collapse arrow
        const collapseArrow = target.closest('.note-collapse-arrow');
        if (collapseArrow) {
            event.stopPropagation(); // Keep stopPropagation if it was there for a reason
            handleDelegatedCollapseArrowClick(collapseArrow);
            return;
        }

        // Bullet click
        const bullet = target.closest('.note-bullet');
        if (bullet) {
            event.stopPropagation(); // Keep stopPropagation
            handleDelegatedBulletClick(bullet);
            return;
        }

        // Task checkbox click
        const checkbox = target.closest('.task-checkbox');
        if (checkbox) {
            event.stopPropagation();
            handleDelegatedTaskCheckboxClick(checkbox);
            return;
        }

        // Content click to edit
        const contentArea = target.closest('.note-content.rendered-mode');
        if (contentArea) {
            // No stopPropagation here, allow normal bubbling unless specific condition
            handleDelegatedNoteContentClick(contentArea);
            return;
        }
        
        // Attachment delete
        const deleteBtn = target.closest('.attachment-delete-btn');
        if (deleteBtn) {
            handleDelegatedAttachmentDelete(deleteBtn);
            return;
        }

        // Markdown-embedded content image click
        const contentImage = target.closest('.content-image');
        if (contentImage) {
            event.preventDefault();
            const imageUrl = contentImage.dataset.originalSrc;
            openImageViewerModal(imageUrl);
            return;
        }

        // Attachment image view (delegated from .attachment-name or .attachment-preview-image)
        const imageLink = target.closest('.attachment-name.delegated-attachment-image, .attachment-preview-image');
        if (imageLink) {
            event.preventDefault();
            handleDelegatedAttachmentImageView(imageLink);
            return;
        }


    });

    notesContainerEl.addEventListener('contextmenu', (event) => {
        const target = event.target;
        const bullet = target.closest('.note-bullet');
        if (bullet) {
            // event.preventDefault() is handled by handleDelegatedBulletContextMenu
            handleDelegatedBulletContextMenu(event, bullet);
        }
    });

    console.log("Delegated note event listeners initialized.");
}
