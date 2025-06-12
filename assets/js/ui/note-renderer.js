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


function renderNote(note, nestingLevel = 0) {
    const noteItemEl = document.createElement('div');
    noteItemEl.className = 'note-item';
    noteItemEl.dataset.noteId = note.id;
    noteItemEl.style.setProperty('--nesting-level', nestingLevel);

    if (note.children && note.children.length > 0) noteItemEl.classList.add('has-children');
    if (note.collapsed) noteItemEl.classList.add('collapsed');

    const controlsEl = document.createElement('div');
    controlsEl.className = 'note-controls';

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
            if (note.content.includes('"iv"') && note.content.includes('"ct"')) {
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
        renderAttachments(attachmentsEl, note.id, true);
    }
    
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

function getRawTextWithNewlines(element) {
    return element.innerText;
}

function normalizeNewlines(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/\n{3,}/g, '\n\n').trim();
}

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

function parseAndRenderContent(rawContent) {
    let content = rawContent || '';
    if (window.decryptionPassword && window.currentPageEncryptionKey && content.includes('"iv"') && content.includes('"ct"')) {
        try {
            content = sjcl.decrypt(window.decryptionPassword, content);
        } catch(e) { console.warn("Decryption failed for content that looked like SJCL data.", e); }
    }
    
    const propertyRegex = /\{([^:]+):(:{2,})([^}]+)\}/g;
    content = content.replace(propertyRegex, ''); // Strip properties from rendering in note body

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

    let html = content.trim();
    html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => `<span class="page-link-bracket">[[</span><a href="#" class="page-link" data-page-name="${pageName.trim()}">${pageName.trim()}</a><span class="page-link-bracket">]]</span>`);
    html = html.replace(/!{{(.*?)}}/g, (match, blockRef) => `<div class="transclusion-placeholder" data-block-ref="${blockRef.trim()}">Loading...</div>`);
    html = html.replace(/SQL\{([^}]+)\}/g, (match, sqlQuery) => `<div class="sql-query-placeholder" data-sql-query="${sqlQuery.replace(/"/g, '"')}">Loading SQL Query...</div>`);

    if (typeof marked !== 'undefined' && marked.parse) {
        html = marked.parse(html, { breaks: true, gfm: true, smartypants: true });
    }

    return html;
}

async function renderAttachments(container, noteId) {
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

function renderProperties(container, properties) {
    container.innerHTML = '';
    if (!properties || Object.keys(properties).length === 0) {
        container.style.display = 'none';
        return;
    }
}

function initializeDelegatedNoteEventListeners(notesContainerEl) {
    if (!notesContainerEl) return;
    notesContainerEl.addEventListener('click', (event) => {
        const target = event.target;
        const collapseArrow = target.closest('.note-collapse-arrow');
        if (collapseArrow) { handleDelegatedCollapseArrowClick(collapseArrow); return; }
        const contentArea = target.closest('.note-content.rendered-mode');
        if (contentArea && !target.closest('a, .task-checkbox')) { switchToEditMode(contentArea); return; }
        const deleteBtn = target.closest('.attachment-delete-btn');
        if (deleteBtn) { handleDelegatedAttachmentDelete(deleteBtn); return; }
        if (target.matches('.attachment-preview-image')) {
            const attachmentUrl = target.closest('.note-attachment-item')?.querySelector('a.attachment-name')?.dataset.attachmentUrl;
            if (attachmentUrl && domRefs.imageViewerModal) {
                 domRefs.imageViewerModalImg.src = attachmentUrl;
                 domRefs.imageViewerModal.classList.add('active');
            }
        }
    });

    if (domRefs.imageViewerModal) {
        domRefs.imageViewerModal.addEventListener('click', (e) => {
            if (e.target === domRefs.imageViewerModal || e.target === domRefs.imageViewerModalClose || e.target.closest('#image-viewer-modal-close')) {
                domRefs.imageViewerModal.classList.remove('active');
            }
        });
    }
}

// --- Delegated Event Handler Functions ---

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
        await notesAPI.updateNote(noteId, { 
            page_id: window.currentPageId, // Assuming window.currentPageId is accessible
            collapsed: isCurrentlyCollapsed 
        });
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
    const noteId = targetElement.dataset.noteId; // Ensure this is on the button
    const attachmentItem = targetElement.closest('.note-attachment-item');
    const attachmentName = attachmentItem?.querySelector('.attachment-name')?.textContent || 'this attachment';

    if (!attachmentId || !noteId) return;

    if (confirm(`Are you sure you want to delete "${attachmentName}"?`)) {
        try {
            await attachmentsAPI.deleteAttachment(attachmentId);
            attachmentItem?.remove();
            const attachmentsContainer = targetElement.closest('.note-attachments');
            if (attachmentsContainer && attachmentsContainer.children.length === 0) {
                attachmentsContainer.style.display = 'none';
            }
             // Update has_attachments on the note in local cache
            if (window.notesForCurrentPage) {
                const noteToUpdate = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
                if (noteToUpdate) {
                    const remainingAttachments = attachmentsContainer ? attachmentsContainer.children.length : 0;
                    noteToUpdate.has_attachments = remainingAttachments > 0;
                }
            }

        } catch (error) {
            const deleteAttachMessage = error.message || 'Please try again.';
            console.error(`handleDelegatedAttachmentDelete: Error deleting attachment ${attachmentId} for note ${noteId}. Error:`, error);
            alert(`Failed to delete attachment "${attachmentName}". ${deleteAttachMessage}`);
        }
    }
}

function handleDelegatedAttachmentImageView(targetElement) {
    const attachmentUrl = targetElement.dataset.attachmentUrl;
    if (!attachmentUrl) { // Might be the img itself, not the link
        const imgPreview = targetElement.closest('.attachment-preview-image');
        if (imgPreview) {
           const attachmentItem = imgPreview.closest('.note-attachment-item');
           const linkElement = attachmentItem?.querySelector('.attachment-name[data-attachment-url]');
           if(linkElement) handleDelegatedAttachmentImageView(linkElement); // Recurse with the link
        }
        return;
    }


    if (domRefs.imageViewerModal && domRefs.imageViewerModalImg && domRefs.imageViewerModalClose) {
        domRefs.imageViewerModalImg.src = attachmentUrl;
        domRefs.imageViewerModal.classList.add('active');

        const closeImageModal = () => {
            domRefs.imageViewerModal.classList.remove('active');
            domRefs.imageViewerModalImg.src = ''; 
            domRefs.imageViewerModalClose.removeEventListener('click', closeImageModal);
            domRefs.imageViewerModal.removeEventListener('click', outsideClickHandlerForModal);
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
        window.open(attachmentUrl, '_blank');
    }
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