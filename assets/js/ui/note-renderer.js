/**
 * UI Module for Note Rendering functionalities
 * Handles how notes and their components are displayed in the DOM.
 * @module ui/note-renderer
 */

import { domRefs } from './dom-refs.js';
import { handleTransclusions } from '../app/page-loader.js';
// The path to api_client.js is ../../api_client.js if note-renderer.js is in assets/js/ui/
// Adjust if api_client.js is elsewhere, e.g. assets/js/api_client.js
import { attachmentsAPI, notesAPI } from '../api_client.js';


// Anticipatory imports - these files/exports might not exist yet.
// import { handleNoteDrop } from './note-interactions.js'; // Likely not needed here, but was in instructions. handleNoteDrop is in note-elements.js
// import { focusOnNote } from './note-interactions.js'; // focusOnNote is used by renderNote's bullet click
// import { showGenericConfirmModal } from './modals.js'; // Potentially for error handling if needed directly by render functions.

// Globals assumed to be available: feather, Sortable, marked, window.notesForCurrentPage, window.currentPageId, displayNotes (potentially from main ui object for callbacks)

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

        arrowEl.addEventListener('click', async (e) => {
            e.stopPropagation(); 
            const currentNoteItem = arrowEl.closest('.note-item');
            if (!currentNoteItem) return;

            const childrenContainer = currentNoteItem.querySelector('.note-children');
            const isCurrentlyCollapsed = currentNoteItem.classList.toggle('collapsed');
            
            if (childrenContainer) {
                childrenContainer.classList.toggle('collapsed', isCurrentlyCollapsed);
            }
            
            arrowEl.dataset.collapsed = isCurrentlyCollapsed ? 'true' : 'false';

            try {
                await notesAPI.updateNote(note.id, { collapsed: isCurrentlyCollapsed });
                console.log('Collapsed state saved:', { noteId: note.id, collapsed: isCurrentlyCollapsed });
                
                note.collapsed = isCurrentlyCollapsed;
                
                if (window.notesForCurrentPage) {
                    const noteToUpdate = window.notesForCurrentPage.find(n => String(n.id) === String(note.id));
                    if (noteToUpdate) {
                        noteToUpdate.collapsed = isCurrentlyCollapsed;
                    }
                }
            } catch (error) {
                console.error('Error saving collapsed state:', error);
                const feedback = document.createElement('div');
                feedback.className = 'copy-feedback';
                feedback.style.background = 'var(--color-error, #dc2626)';
                feedback.textContent = 'Failed to save collapse state';
                document.body.appendChild(feedback);
                setTimeout(() => feedback.remove(), 2000);
            }
        });

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

    bulletEl.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        const menu = document.createElement('div');
        menu.className = 'bullet-context-menu';
        menu.innerHTML = `
            <div class="menu-item" data-action="copy-transclusion">
                <i data-feather="link"></i> Copy transclusion link
            </div>
            <div class="menu-item" data-action="delete">
                <i data-feather="trash-2"></i> Delete
            </div>
            <div class="menu-item" data-action="upload">
                <i data-feather="upload"></i> Upload attachment
            </div>
        `;
        menu.style.position = 'fixed';
        menu.style.left = `${e.pageX}px`;
        menu.style.top = `${e.pageY}px`;

        menu.addEventListener('click', async (ev) => {
            const action = ev.target.closest('.menu-item')?.dataset.action;
            if (!action) return;

            switch (action) {
                case 'copy-transclusion':
                    const transclusionLink = `!{{${note.id}}}`;
                    await navigator.clipboard.writeText(transclusionLink);
                    const feedbackCopy = document.createElement('div');
                    feedbackCopy.className = 'copy-feedback';
                    feedbackCopy.textContent = 'Transclusion link copied!';
                    document.body.appendChild(feedbackCopy);
                    setTimeout(() => feedbackCopy.remove(), 2000);
                    break;
                case 'delete':
                    // Confirming deletion would ideally use a shared modal function
                    // For now, using confirm() as it was in the original code.
                    // if (await showGenericConfirmModal('Delete Note', 'Are you sure you want to delete this note?')) {
                    if (confirm('Are you sure you want to delete this note?')) {
                        try {
                            await notesAPI.deleteNote(note.id);
                            const noteElToDelete = document.querySelector(`.note-item[data-note-id="${note.id}"]`);
                            if (noteElToDelete) noteElToDelete.remove();
                        } catch (error) {
                            console.error('Error deleting note:', error);
                            alert('Failed to delete note');
                        }
                    }
                    break;
                case 'upload':
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.multiple = true;
                    input.onchange = async (eventUpload) => {
                        const files = Array.from(eventUpload.target.files);
                        for (const file of files) {
                            const formData = new FormData();
                            formData.append('attachmentFile', file);
                            formData.append('note_id', note.id);
                            try {
                                await attachmentsAPI.uploadAttachment(formData);
                                const feedbackUpload = document.createElement('div');
                                feedbackUpload.className = 'copy-feedback';
                                feedbackUpload.textContent = `File "${file.name}" uploaded successfully!`;
                                document.body.appendChild(feedbackUpload);
                                setTimeout(() => feedbackUpload.remove(), 3000);
                                // Refreshing notes requires displayNotes, which might be in a different module now.
                                // This could be `window.ui.displayNotes` or passed as a callback.
                                if (window.ui && typeof window.ui.displayNotes === 'function') {
                                    const notes = await notesAPI.getNotesForPage(note.page_id);
                                    window.ui.displayNotes(notes, note.page_id);
                                } else {
                                    console.warn('displayNotes function not available to refresh after upload.')
                                }
                            } catch (error) {
                                console.error('Error uploading file:', error);
                                alert(`Failed to upload file "${file.name}": ${error.message}`);
                            }
                        }
                    };
                    input.click();
                    break;
            }
            menu.remove();
        });

        const closeMenu = (ev) => {
            if (!menu.contains(ev.target)) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            }
        };
        document.addEventListener('click', closeMenu);

        document.body.appendChild(menu);
        if (typeof feather !== 'undefined') feather.replace();
    });

    bulletEl.addEventListener('click', (e) => {
        e.stopPropagation();
        // focusOnNote is expected to be imported or available globally/via ui object
        if (typeof focusOnNote === 'function') { // Check if focusOnNote is defined
             focusOnNote(note.id);
        } else if (window.ui && typeof window.ui.focusOnNote === 'function') {
            window.ui.focusOnNote(note.id);
        } else {
            console.warn('focusOnNote function not available.');
        }
    });

    const contentWrapperEl = document.createElement('div');
    contentWrapperEl.className = 'note-content-wrapper';

    const contentEl = document.createElement('div');
    contentEl.className = 'note-content rendered-mode';
    contentEl.dataset.placeholder = 'Type to add content...';
    contentEl.dataset.noteId = note.id;
    contentEl.dataset.rawContent = note.content || '';

    if (note.content && note.content.trim()) {
        contentEl.innerHTML = parseAndRenderContent(note.content);
    }

    contentEl.addEventListener('click', (e) => {
        if (e.target.matches('.task-checkbox, .page-link, .property-inline, .task-status-badge')) {
            return;
        }
        switchToEditMode(contentEl);
    });

    contentWrapperEl.appendChild(contentEl);

    const attachmentsEl = document.createElement('div');
    attachmentsEl.className = 'note-attachments';
    contentWrapperEl.appendChild(attachmentsEl);

    if (note.id && (typeof note.id === 'number' || (typeof note.id === 'string' && !note.id.startsWith('temp-')))) {
        attachmentsAPI.getAttachmentsForNote(note.id)
            .then(attachments => {
                renderAttachments(attachmentsEl, attachments, note.id);
            })
            .catch(error => {
                console.error('Failed to load attachments for note:', note.id, error);
                attachmentsEl.innerHTML = '<small>Could not load attachments.</small>';
            });
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
                         const notes = await notesAPI.getNotesForPage(window.currentPageId);
                         window.notesForCurrentPage = notes; 
                         window.ui.displayNotes(notes, window.currentPageId); 
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

    const rawContent = contentEl.dataset.rawContent || '';
    const noteId = contentEl.dataset.noteId;
    
    contentEl.classList.remove('rendered-mode');
    contentEl.classList.add('edit-mode');
    contentEl.contentEditable = true;
    contentEl.style.whiteSpace = 'pre-wrap'; 
    contentEl.innerHTML = '';
    contentEl.textContent = rawContent;
    contentEl.focus();

    const handleBlur = () => {
        switchToRenderedMode(contentEl);
        contentEl.removeEventListener('blur', handleBlur);
        contentEl.removeEventListener('paste', handlePasteImage); 
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
                         const notes = await notesAPI.getNotesForPage(window.currentPageId);
                         window.notesForCurrentPage = notes; 
                         window.ui.displayNotes(notes, window.currentPageId); 
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
    if (contentEl.classList.contains('rendered-mode')) return;

    const rawTextValue = getRawTextWithNewlines(contentEl);
    const newContent = normalizeNewlines(rawTextValue);
    
    contentEl.dataset.rawContent = newContent;
    
    contentEl.classList.remove('edit-mode');
    contentEl.classList.add('rendered-mode');
    contentEl.contentEditable = false;
    contentEl.style.whiteSpace = ''; 

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
}

/**
 * Parses and renders note content with special formatting
 * @param {string} rawContent - Raw note content
 * @returns {string} HTML string for display
 */
function parseAndRenderContent(rawContent) {
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
        html = html.replace(/\{([^:]+)::([^}]+)\}/g, (match, key, value) => {
            const trimmedKey = key.trim();
            const trimmedValue = value.trim();
            if (trimmedKey.toLowerCase() === 'tag') {
                return `<span class="property-inline property-tag"><span class="property-key">#</span><span class="property-value">${trimmedValue}</span></span>`;
            } else if (trimmedKey.toLowerCase() === 'alias') {
                return `<span class="property-inline alias-property"><span class="property-key">Alias</span><span class="property-value">${trimmedValue}</span></span>`;
            } else {
                return `<span class="property-inline"><span class="property-key">${trimmedKey}</span><span class="property-value">${trimmedValue}</span></span>`;
            }
        });

        // Handle page links
        html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => {
            const trimmedName = pageName.trim();
            return `<span class="page-link-bracket">[[</span><a href="#" class="page-link" data-page-name="${trimmedName}">${trimmedName}</a><span class="page-link-bracket">]]</span>`;
        });

        html = html.replace(/!{{(.*?)}}/g, (match, blockRef) => {
            const trimmedRef = blockRef.trim();
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

/**
 * Renders attachments for a note
 * @param {HTMLElement} container - The container element to render attachments into
 * @param {Array<Object>} attachments - Array of attachment objects
 * @param {string} noteId - The ID of the note these attachments belong to
 */
function renderAttachments(container, attachments, noteId) {
    container.innerHTML = ''; 
    if (!attachments || attachments.length === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex'; 

    attachments.forEach(attachment => {
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

        container.appendChild(attachmentEl);

        if (isImage) {
            const imageLinkInDOM = attachmentEl.querySelector('.attachment-name');
            if (imageLinkInDOM) {
                imageLinkInDOM.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (domRefs.imageViewerModal && domRefs.imageViewerModalImg && domRefs.imageViewerModalClose) {
                        domRefs.imageViewerModalImg.src = attachment.url;
                        domRefs.imageViewerModal.classList.add('active');

                        const closeImageModal = () => {
                            domRefs.imageViewerModal.classList.remove('active');
                            domRefs.imageViewerModalImg.src = ''; 
                            domRefs.imageViewerModalClose.removeEventListener('click', closeImageModal);
                            domRefs.imageViewerModal.removeEventListener('click', outsideClickHandler);
                        };

                        const outsideClickHandler = (event) => {
                            if (event.target === domRefs.imageViewerModal) { 
                                closeImageModal();
                            }
                        };

                        domRefs.imageViewerModalClose.addEventListener('click', closeImageModal);
                        domRefs.imageViewerModal.addEventListener('click', outsideClickHandler);
                    } else {
                        console.error('Image viewer modal elements not found.');
                        window.open(attachment.url, '_blank');
                    }
                });
            }
        }

        const deleteBtn = attachmentEl.querySelector('.attachment-delete-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async () => {
                // if (await showGenericConfirmModal('Delete Attachment', `Are you sure you want to delete "${attachment.name}"?`)) {
                if (confirm(`Are you sure you want to delete "${attachment.name}"?`)) { // Using confirm as showGenericConfirmModal might not be available yet
                    try {
                        await attachmentsAPI.deleteAttachment(attachment.id);
                        attachmentEl.remove(); 
                        if (container.children.length === 0) {
                            container.style.display = 'none';
                        }
                    } catch (error) {
                        console.error('Error deleting attachment:', error);
                        alert('Failed to delete attachment: ' + error.message);
                    }
                }
            });
        }
    });

    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace();
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

    const propertyItems = Object.entries(properties).map(([name, value]) => {
        if (name.toLowerCase() === 'favorite' && String(value).toLowerCase() === 'true') {
            return `<span class="property-item favorite">
                <span class="property-favorite">⭐</span>
            </span>`;
        }

        if (name.startsWith('tag::')) {
            const tagName = name.substring(5);
            return `<span class="property-item tag">
                <span class="property-key">#</span>
                <span class="property-value">${tagName}</span>
            </span>`;
        }

        if (Array.isArray(value)) {
            return value.map(v => `
                <span class="property-item">
                    <span class="property-key">${name}</span>
                    <span class="property-value">${v}</span>
                </span>
            `).join('');
        }

        return `<span class="property-item">
            <span class="property-key">${name}</span>
            <span class="property-value">${value}</span>
        </span>`;
    }).join('');

    container.innerHTML = propertyItems;
    container.style.display = 'flex'; 
    container.style.flexWrap = 'wrap'; 
    container.style.gap = 'var(--ls-space-2, 8px)'; 
    container.classList.remove('hidden'); 
}


export {
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    switchToRenderedMode,
    getRawTextWithNewlines,
    normalizeNewlines,
    renderAttachments,
    renderProperties
};
