/**
 * UI Module for Note Rendering functionalities
 * Handles how notes and their components are displayed in the DOM.
 * @module ui/note-renderer
 */

import { saveNoteImmediately } from '../app/note-actions.js';
import { domRefs } from './dom-refs.js';
import { handleTransclusions } from '../app/page-loader.js';
import { attachmentsAPI, notesAPI } from '../api_client.js';
import {
    showSuggestions,
    hideSuggestions,
    navigateSuggestions,
    getSelectedSuggestion,
} from './page-link-suggestions.js';

/**
 * Checks if the cursor is inside a [[link query]] and returns information.
 * @param {HTMLElement} contentEl The content editable element.
 * @returns {{ query: string, triggerPosition: { top: number, left: number}, replaceStartOffset: number, replaceEndOffset: number } | null}
 */
function getLinkQueryInfo(contentEl) {
    const selection = window.getSelection();
    if (!selection.rangeCount) return null;

    const range = selection.getRangeAt(0);
    const textUpToCursor = range.startContainer.textContent.substring(0, range.startOffset);
    const openBracketIndex = textUpToCursor.lastIndexOf('[[');

    if (openBracketIndex === -1 || textUpToCursor.substring(openBracketIndex).includes(']]')) {
        return null;
    }

    const query = textUpToCursor.substring(openBracketIndex + 2);

    const rect = range.getClientRects()[0];
    if (!rect) return null;

    const position = {
        top: rect.bottom + window.scrollY,
        left: rect.left + window.scrollX
    };

    return {
        query,
        triggerPosition: position,
        replaceStartOffset: openBracketIndex,
        replaceEndOffset: range.startOffset,
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

    if (note.children && note.children.length > 0) noteItemEl.classList.add('has-children');
    if (note.collapsed) noteItemEl.classList.add('collapsed');

    const controlsEl = document.createElement('div');
    controlsEl.className = 'note-controls';

    const dragHandleEl = document.createElement('span');
    dragHandleEl.className = 'note-drag-handle';
    dragHandleEl.innerHTML = '<i data-feather="menu"></i>';
    dragHandleEl.style.display = 'none';

    const bulletEl = document.createElement('span');
    bulletEl.className = 'note-bullet';
    bulletEl.dataset.noteId = note.id;

    if (note.children && note.children.length > 0) {
        const arrowEl = document.createElement('span');
        arrowEl.className = 'note-collapse-arrow';
        arrowEl.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-right"><polyline points="9 18 15 12 9 6"></polyline></svg>`;
        arrowEl.dataset.noteId = note.id;
        arrowEl.dataset.collapsed = note.collapsed ? 'true' : 'false';
        controlsEl.appendChild(arrowEl);
    }
    
    controlsEl.appendChild(dragHandleEl);
    controlsEl.appendChild(bulletEl);

    const contentWrapperEl = document.createElement('div');
    contentWrapperEl.className = 'note-content-wrapper';

    const contentEl = document.createElement('div');
    contentEl.className = 'note-content rendered-mode';
    contentEl.dataset.placeholder = 'Type to add content...';
    contentEl.dataset.noteId = note.id;

    let effectiveContentForDataset = note.content || '';
    if (window.decryptionPassword && window.currentPageEncryptionKey && (note.content || '').trim()) {
        try {
            if (note.content.startsWith('{') && note.content.endsWith('}')) {
                const decrypted = sjcl.decrypt(window.decryptionPassword, note.content);
                effectiveContentForDataset = decrypted;
            }
        } catch (e) { /* Not encrypted or wrong key, use original content */ }
    }
    contentEl.dataset.rawContent = effectiveContentForDataset;

    if (note.content && note.content.trim()) {
        contentEl.innerHTML = parseAndRenderContent(note.content);
    }

    contentWrapperEl.appendChild(contentEl);

    const attachmentsEl = document.createElement('div');
    attachmentsEl.className = 'note-attachments';
    contentWrapperEl.appendChild(attachmentsEl);

    if (note.id && !String(note.id).startsWith('temp-')) {
        renderAttachments(attachmentsEl, note.id, note.has_attachments);
    }
    
    // Drag & Drop event listeners for file uploads
    contentWrapperEl.addEventListener('dragover', (e) => { e.preventDefault(); e.stopPropagation(); contentWrapperEl.classList.add('dragover'); });
    contentWrapperEl.addEventListener('dragleave', (e) => { e.preventDefault(); e.stopPropagation(); contentWrapperEl.classList.remove('dragover'); });
    contentWrapperEl.addEventListener('drop', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        contentWrapperEl.classList.remove('dragover');
        if (String(note.id).startsWith('temp-')) {
            alert('Please save the note before adding attachments.');
            return;
        }
        for (const file of e.dataTransfer.files) {
            const formData = new FormData();
            formData.append('attachmentFile', file);
            formData.append('note_id', note.id);
            try {
                await attachmentsAPI.uploadAttachment(formData);
                if (window.ui && typeof window.ui.displayNotes === 'function') {
                    const pageData = await notesAPI.getPageData(window.currentPageId);
                    window.ui.displayNotes(pageData.notes, window.currentPageId);
                }
            } catch (error) {
                alert(`Failed to upload file "${file.name}": ${error.message}`);
            }
        }
    });

    const noteHeaderEl = document.createElement('div');
    noteHeaderEl.className = 'note-header-row';
    noteHeaderEl.appendChild(controlsEl);
    noteHeaderEl.appendChild(contentWrapperEl);

    noteItemEl.appendChild(noteHeaderEl);

    const childrenContainerEl = document.createElement('div');
    childrenContainerEl.className = 'note-children';
    if (note.collapsed) childrenContainerEl.classList.add('collapsed');

    if (note.children && note.children.length > 0) {
        note.children.forEach(childNote => {
            childrenContainerEl.appendChild(renderNote(childNote, nestingLevel + 1));
        });
    }

    noteItemEl.appendChild(childrenContainerEl);

    if (typeof feather !== 'undefined' && feather.replace) feather.replace();
    return noteItemEl;
}

/**
 * Switches a note content element to edit mode
 * @param {HTMLElement} contentEl - The note content element
 */
function switchToEditMode(contentEl) {
    if (contentEl.classList.contains('edit-mode')) return;

    let textToEdit = contentEl.dataset.rawContent || '';
    let suggestionBoxVisible = false;

    contentEl.classList.remove('rendered-mode');
    contentEl.classList.add('edit-mode');
    contentEl.contentEditable = true;
    contentEl.style.whiteSpace = 'pre-wrap';
    contentEl.textContent = textToEdit;
    contentEl.focus();

    const insertSelectedPageLink = (selectedPageName) => {
        const queryInfo = getLinkQueryInfo(contentEl);
        if (!queryInfo) {
            hideSuggestions();
            suggestionBoxVisible = false;
            return;
        }

        const selection = window.getSelection();
        const range = selection.getRangeAt(0);
        range.setStart(range.startContainer, queryInfo.replaceStartOffset);
        range.setEnd(range.startContainer, queryInfo.replaceEndOffset);
        range.deleteContents();
        
        const newText = `[[${selectedPageName}]]`;
        const textNode = document.createTextNode(newText);
        range.insertNode(textNode);
        
        range.setStartAfter(textNode);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        
        hideSuggestions();
        suggestionBoxVisible = false;
        contentEl.dispatchEvent(new Event('input', { bubbles: true }));
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

    const handleKeydownForSuggestions = (event) => {
        if (!suggestionBoxVisible) return;
        if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            navigateSuggestions(event.key);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const selectedPageName = getSelectedSuggestion();
            if (selectedPageName) insertSelectedPageLink(selectedPageName);
            else hideSuggestions();
        } else if (['Escape', 'Tab'].includes(event.key)) {
            event.preventDefault();
            hideSuggestions();
        }
    };
    
    const suggestionBoxElement = document.getElementById('page-link-suggestion-box');
    const suggestionClickListener = (event) => {
        if (suggestionBoxVisible && event.detail?.pageName) {
            insertSelectedPageLink(event.detail.pageName);
        }
    };

    const handleBlur = () => {
        setTimeout(() => { if (suggestionBoxVisible) hideSuggestions(); }, 150);
        switchToRenderedMode(contentEl);
        contentEl.removeEventListener('blur', handleBlur);
        contentEl.removeEventListener('input', handleInputForSuggestions);
        contentEl.removeEventListener('keydown', handleKeydownForSuggestions);
        suggestionBoxElement?.removeEventListener('suggestion-select', suggestionClickListener);
    };

    contentEl.addEventListener('input', handleInputForSuggestions);
    contentEl.addEventListener('keydown', handleKeydownForSuggestions);
    suggestionBoxElement?.addEventListener('suggestion-select', suggestionClickListener);
    contentEl.addEventListener('blur', handleBlur);
}

/**
 * Extracts raw text content from an HTML element, converting block elements to newlines.
 * @param {HTMLElement} element - The HTML element to extract text from.
 * @returns {string} The processed text content.
 */
function getRawTextWithNewlines(element) {
    // A simplified approach using innerText which often preserves line breaks from block elements
    // This is generally more reliable than manual node walking for complex contenteditable structures.
    return element.innerText;
}

/**
 * Normalizes newline characters in a string.
 * @param {string} str - The input string.
 * @returns {string} The normalized string.
 */
function normalizeNewlines(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * Switches a note content element to rendered mode
 * @param {HTMLElement} contentEl - The note content element
 */
function switchToRenderedMode(contentEl) {
    if (contentEl.classList.contains('rendered-mode')) return;

    const noteEl = contentEl.closest('.note-item');
    if (noteEl && noteEl.dataset.noteId && !noteEl.dataset.noteId.startsWith('temp-')) {
        saveNoteImmediately(noteEl);
    }
    
    const rawTextValue = getRawTextWithNewlines(contentEl);
    const newContent = normalizeNewlines(rawTextValue);
    
    contentEl.dataset.rawContent = newContent;
    contentEl.classList.remove('edit-mode');
    contentEl.classList.add('rendered-mode');
    contentEl.contentEditable = false;
    contentEl.style.whiteSpace = '';
    contentEl.innerHTML = newContent.trim() ? parseAndRenderContent(newContent) : '';

    hideSuggestions();
    handleTransclusions();
}

/**
 * Parses and renders note content with special formatting
 * @param {string} rawContent - Raw note content
 * @returns {string} HTML string for display
 */
function parseAndRenderContent(rawContent) {
    let content = rawContent || '';
    if (window.decryptionPassword && window.currentPageEncryptionKey && content.startsWith('{')) {
        try {
            content = sjcl.decrypt(window.decryptionPassword, content);
        } catch(e) { /* Failed decryption, show raw */ }
    }
    
    // Regex for properties: {key:::value} or {key::value}
    // Group 1: key, Group 2: colons, Group 3: value
    const propertyRegex = /\{([^:]+):(:{2,})([^}]+)\}/g;
    content = content.replace(propertyRegex, (match, key, colons, value) => {
        const isInternal = colons.length > 2;
        // Check config to see if internal properties should be rendered
        const RENDER_INTERNAL = window.APP_CONFIG && window.APP_CONFIG.RENDER_INTERNAL_PROPERTIES === true;
        if (isInternal && !RENDER_INTERNAL) {
            return ''; // Omit internal property from rendering
        }
        // For now, we don't render properties inline in the note body.
        // They are handled by the properties modal or a separate properties section.
        // This regex replacement effectively strips them from the main content view.
        return '';
    });

    // Handle task markers
    const taskMarkerRegex = /^(TODO|DOING|DONE|SOMEDAY|WAITING|CANCELLED|NLR)\s+/i;
    const taskMatch = content.match(taskMarkerRegex);
    if (taskMatch) {
        const marker = taskMatch[1].toUpperCase();
        const taskContent = content.substring(taskMatch[0].length);
        const isChecked = ['DONE', 'CANCELLED', 'NLR'].includes(marker);
        const isDisabled = ['CANCELLED', 'NLR'].includes(marker);
        return `
            <div class="task-container ${marker.toLowerCase()}">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="${marker}" ${isChecked ? 'checked' : ''} ${isDisabled ? 'disabled' : ''} />
                    <span class="task-status-badge ${marker.toLowerCase()}">${marker}</span>
                </div>
                <div class="task-content ${isChecked ? 'done-text' : ''}">${taskContent}</div>
            </div>
        `;
    }

    // Process standard markdown after handling custom syntax
    let html = content.trim();

    // Page links: [[Page Name]]
    html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => `<span class="page-link-bracket">[[</span><a href="#" class="page-link" data-page-name="${pageName.trim()}">${pageName.trim()}</a><span class="page-link-bracket">]]</span>`);
    
    // Transclusions: !{{block-ref}}
    html = html.replace(/!{{(.*?)}}/g, (match, blockRef) => `<div class="transclusion-placeholder" data-block-ref="${blockRef.trim()}">Loading...</div>`);

    // SQL Queries: SQL{...}
    html = html.replace(/SQL\{([^}]+)\}/g, (match, sqlQuery) => `<div class="sql-query-placeholder" data-sql-query="${sqlQuery.replace(/"/g, '&quot;')}">Loading SQL Query...</div>`);

    // Use marked.js for general markdown
    if (typeof marked !== 'undefined' && marked.parse) {
        html = marked.parse(html, { breaks: true, gfm: true, smartypants: true });
    }

    return html;
}

/**
 * Renders attachments for a note
 * @param {HTMLElement} container - The container element to render attachments into
 * @param {string} noteId - The ID of the note
 * @param {boolean|number} has_attachments_flag - Flag indicating if attachments exist
 */
async function renderAttachments(container, noteId, has_attachments_flag) {
    if (!has_attachments_flag) {
        container.innerHTML = '';
        return;
    }

    try {
        const attachments = await attachmentsAPI.getNoteAttachments(noteId);
        container.innerHTML = '';
        if (!attachments || attachments.length === 0) return;

        attachments.forEach(attachment => {
            const isImage = attachment.type?.startsWith('image/');
            const previewEl = isImage ? `<img src="${attachment.url}" alt="${attachment.name}" class="attachment-preview-image">` : `<i data-feather="file" class="attachment-preview-icon"></i>`;
            
            const attachmentEl = document.createElement('div');
            attachmentEl.className = 'note-attachment-item';
            attachmentEl.dataset.attachmentId = attachment.id;
            attachmentEl.innerHTML = `
                <div class="attachment-preview">${previewEl}</div>
                <div class="attachment-info">
                    <a href="${attachment.url}" class="attachment-name" ${!isImage ? 'target="_blank"' : ''} data-attachment-url="${attachment.url}">${attachment.name}</a>
                    <span class="attachment-meta">${attachment.type} - ${new Date(attachment.created_at).toLocaleDateString()}</span>
                </div>
                <button class="attachment-delete-btn" data-attachment-id="${attachment.id}" data-note-id="${noteId}"><i data-feather="trash-2"></i></button>
            `;
            container.appendChild(attachmentEl);
        });
        if (typeof feather !== 'undefined') feather.replace();
    } catch (error) {
        console.error('Error rendering attachments:', error);
        container.innerHTML = '<small>Could not load attachments.</small>';
    }
}


/**
 * Renders properties for a note (not used for inline properties in content)
 * @param {HTMLElement} container - Container element for properties
 * @param {Object} properties - Properties object
 */
function renderProperties(container, properties) {
    // This function is less critical now that properties are mainly in content
    // and the property modal. It can be used for specific non-inline displays if needed.
    container.innerHTML = '';
    if (!properties || Object.keys(properties).length === 0) {
        container.style.display = 'none';
        return;
    }
    // Implementation would be similar to renderPageInlineProperties if needed.
}


// --- Delegated Event Handlers ---

async function handleDelegatedCollapseArrowClick(target) {
    const noteItem = target.closest('.note-item');
    if (!noteItem) return;
    const isCurrentlyCollapsed = noteItem.classList.toggle('collapsed');
    noteItem.querySelector('.note-children')?.classList.toggle('collapsed', isCurrentlyCollapsed);
    target.dataset.collapsed = isCurrentlyCollapsed.toString();
    try {
        await notesAPI.updateNote(noteItem.dataset.noteId, { collapsed: isCurrentlyCollapsed });
    } catch (error) {
        console.error(`Error saving collapse state:`, error);
        noteItem.classList.toggle('collapsed'); // Revert on error
    }
}

async function handleDelegatedAttachmentDelete(target) {
    const attachmentId = target.dataset.attachmentId;
    const noteId = target.dataset.noteId;
    if (!attachmentId || !noteId) return;

    if (confirm(`Are you sure you want to delete this attachment?`)) {
        try {
            await attachmentsAPI.deleteAttachment(attachmentId);
            target.closest('.note-attachment-item')?.remove();
        } catch (error) {
            alert(`Failed to delete attachment: ${error.message}`);
        }
    }
}

/**
 * Initializes delegated event listeners for notes on the provided container.
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
            handleDelegatedCollapseArrowClick(collapseArrow);
            return;
        }

        // Content click to edit
        const contentArea = target.closest('.note-content.rendered-mode');
        if (contentArea && !target.closest('a, .task-checkbox')) {
            switchToEditMode(contentArea);
            return;
        }
        
        // Attachment delete
        const deleteBtn = target.closest('.attachment-delete-btn');
        if (deleteBtn) {
            handleDelegatedAttachmentDelete(deleteBtn);
            return;
        }

        // Attachment image view
        if (target.matches('.attachment-preview-image')) {
            const attachmentUrl = target.closest('.note-attachment-item')?.querySelector('a.attachment-name')?.dataset.attachmentUrl;
            if (attachmentUrl && domRefs.imageViewerModal) {
                 domRefs.imageViewerModalImg.src = attachmentUrl;
                 domRefs.imageViewerModal.classList.add('active');
            }
        }
    });

    // Close image viewer
    if (domRefs.imageViewerModal) {
        domRefs.imageViewerModal.addEventListener('click', (e) => {
            if (e.target === domRefs.imageViewerModal || e.target === domRefs.imageViewerModalClose || e.target.closest('#image-viewer-modal-close')) {
                domRefs.imageViewerModal.classList.remove('active');
            }
        });
    }

    console.log("Delegated note event listeners initialized.");
}

export {
    renderNote,
    switchToEditMode,
    switchToRenderedMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties,
    parseAndRenderContent,
    initializeDelegatedNoteEventListeners
};