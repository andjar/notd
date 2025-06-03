/**
 * UI Module for NotTD application
 * Handles all DOM manipulation and rendering
 * @module ui
 */

// DOM References
const domRefs = {
    notesContainer: document.getElementById('notes-container'),
    currentPageTitleEl: document.getElementById('current-page-title'),
    pagePropertiesContainer: document.getElementById('page-properties'),
    pageListContainer: document.getElementById('page-list'),
    addRootNoteBtn: document.getElementById('add-root-note-btn'),
    toggleLeftSidebarBtn: document.getElementById('toggle-left-sidebar-btn'),
    toggleRightSidebarBtn: document.getElementById('toggle-right-sidebar-btn'),
    leftSidebar: document.getElementById('left-sidebar'),
    rightSidebar: document.getElementById('right-sidebar'),
    globalSearchInput: document.getElementById('global-search-input'),
    searchResults: document.getElementById('search-results'),
    backlinksContainer: document.getElementById('backlinks-container'),
    breadcrumbsContainer: document.getElementById('breadcrumbs-container'),
    pagePropertiesGear: document.getElementById('page-properties-gear'),
    pagePropertiesModal: document.getElementById('page-properties-modal'),
    pagePropertiesModalClose: document.getElementById('page-properties-modal-close'),
    pagePropertiesList: document.getElementById('page-properties-list'),
    addPagePropertyBtn: document.getElementById('add-page-property-btn'),

    // New refs for Page Search Modal
    openPageSearchModalBtn: document.getElementById('open-page-search-modal-btn'),
    pageSearchModal: document.getElementById('page-search-modal'),
    pageSearchModalInput: document.getElementById('page-search-modal-input'),
    pageSearchModalResults: document.getElementById('page-search-modal-results'),
    pageSearchModalCancel: document.getElementById('page-search-modal-cancel'),

    // Image Viewer Modal Refs
    imageViewerModal: document.getElementById('image-viewer-modal'),
    imageViewerModalImg: document.getElementById('image-viewer-modal-img'),
    imageViewerModalClose: document.getElementById('image-viewer-modal-close')
};

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
    // No icon in bulletEl by default anymore

    if (note.children && note.children.length > 0) {
        noteItemEl.classList.add('has-children'); // Keep this class on note-item for styling

        // Create arrow for collapsable sections
        const arrowEl = document.createElement('span');
        arrowEl.className = 'note-collapse-arrow';
        arrowEl.innerHTML = '<i data-feather="chevron-right"></i>';
        arrowEl.dataset.noteId = note.id;
        arrowEl.dataset.collapsed = note.collapsed ? 'true' : 'false';

        arrowEl.addEventListener('click', async (e) => {
            e.stopPropagation(); // Prevent event from bubbling to note item click, etc.
            const currentNoteItem = arrowEl.closest('.note-item');
            if (!currentNoteItem) return;

            const childrenContainer = currentNoteItem.querySelector('.note-children');
            const isCurrentlyCollapsed = currentNoteItem.classList.toggle('collapsed');
            
            if (childrenContainer) {
                childrenContainer.classList.toggle('collapsed', isCurrentlyCollapsed);
            }
            
            // Update arrow data attribute to control rotation
            arrowEl.dataset.collapsed = isCurrentlyCollapsed ? 'true' : 'false';

            // Persist collapse state to database
            try {
                await window.notesAPI.updateNote(note.id, { collapsed: isCurrentlyCollapsed });
                console.log('Collapsed state saved:', { noteId: note.id, collapsed: isCurrentlyCollapsed });
                
                // Update local note object to keep in sync
                note.collapsed = isCurrentlyCollapsed;
                
                // Also update in global cache if it exists
                if (window.notesForCurrentPage) {
                    const noteToUpdate = window.notesForCurrentPage.find(n => String(n.id) === String(note.id));
                    if (noteToUpdate) {
                        noteToUpdate.collapsed = isCurrentlyCollapsed;
                    }
                }
            } catch (error) {
                console.error('Error saving collapsed state:', error);
                // Optionally show user feedback for the error
                const feedback = document.createElement('div');
                feedback.className = 'copy-feedback';
                feedback.style.background = 'var(--color-error, #dc2626)';
                feedback.textContent = 'Failed to save collapse state';
                document.body.appendChild(feedback);
                setTimeout(() => feedback.remove(), 2000);
            }
        });
        controlsEl.appendChild(arrowEl); // Add arrow first
    }
    
    controlsEl.appendChild(dragHandleEl);
    controlsEl.appendChild(bulletEl);

    // Add context menu to bullet
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

        // Position menu
        menu.style.position = 'fixed';
        menu.style.left = e.pageX + 'px';
        menu.style.top = e.pageY + 'px';

        // Handle menu actions
        menu.addEventListener('click', async (e) => {
            const action = e.target.closest('.menu-item')?.dataset.action;
            if (!action) return;

            switch (action) {
                case 'copy-transclusion':
                    // Use the actual note ID for transclusion
                    const transclusionLink = `!{{${note.id}}}`;
                    await navigator.clipboard.writeText(transclusionLink);
                    // Show feedback
                    const feedback = document.createElement('div');
                    feedback.className = 'copy-feedback';
                    feedback.textContent = 'Transclusion link copied!';
                    document.body.appendChild(feedback);
                    setTimeout(() => feedback.remove(), 2000);
                    break;
                case 'delete':
                    if (confirm('Are you sure you want to delete this note?')) {
                        try {
                            await notesAPI.deleteNote(note.id);
                            const noteEl = document.querySelector(`.note-item[data-note-id="${note.id}"]`);
                            if (noteEl) noteEl.remove();
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
                    input.onchange = async (e) => {
                        const files = Array.from(e.target.files);
                        for (const file of files) {
                            const formData = new FormData();
                            formData.append('attachmentFile', file);
                            formData.append('note_id', note.id);
                            try {
                                const result = await attachmentsAPI.uploadAttachment(formData);
                                console.log('File uploaded successfully:', result);
                                
                                // Show success feedback
                                const feedback = document.createElement('div');
                                feedback.className = 'copy-feedback';
                                feedback.textContent = `File "${file.name}" uploaded successfully!`;
                                document.body.appendChild(feedback);
                                setTimeout(() => feedback.remove(), 3000);
                                
                                // Refresh note to show new attachment
                                const notes = await notesAPI.getNotesForPage(note.page_id);
                                ui.displayNotes(notes, note.page_id);
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

        // Close menu when clicking outside
        const closeMenu = (e) => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            }
        };
        document.addEventListener('click', closeMenu);

        document.body.appendChild(menu);
        feather.replace();
    });

    // Add click handler for bullet focus functionality (not drag!)
    bulletEl.addEventListener('click', (e) => {
        e.stopPropagation();
        focusOnNote(note.id);
    });

    // Content wrapper (content + properties)
    const contentWrapperEl = document.createElement('div');
    contentWrapperEl.className = 'note-content-wrapper';

    // Content div - starts in rendered mode
    const contentEl = document.createElement('div');
    contentEl.className = 'note-content rendered-mode';
    contentEl.dataset.placeholder = 'Type to add content...';
    contentEl.dataset.noteId = note.id;
    contentEl.dataset.rawContent = note.content || '';

    // Render initial content
    if (note.content && note.content.trim()) {
        contentEl.innerHTML = parseAndRenderContent(note.content);
    }

    // Add mode switching functionality
    contentEl.addEventListener('click', (e) => {
        // Don't switch mode if clicking on interactive elements
        if (e.target.matches('.task-checkbox, .page-link, .property-inline, .task-status-badge')) {
            return;
        }
        
        switchToEditMode(contentEl);
    });

    // Properties div
    // const propertiesEl = document.createElement('div');
    // propertiesEl.className = 'note-properties';
    // renderProperties(propertiesEl, note.properties);

    contentWrapperEl.appendChild(contentEl);

    // Attachments section
    const attachmentsEl = document.createElement('div');
    attachmentsEl.className = 'note-attachments';
    contentWrapperEl.appendChild(attachmentsEl);
    // Asynchronously load and render attachments
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

    // Add Drag and Drop listeners for attachments to contentWrapperEl
    contentWrapperEl.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        contentWrapperEl.classList.add('dragover'); // For visual feedback
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
                    const result = await attachmentsAPI.uploadAttachment(formData);
                    console.log('File uploaded successfully via drag & drop:', result);
                    
                    // Show success feedback (reuse existing mechanism if available)
                    const feedback = document.createElement('div');
                    feedback.className = 'copy-feedback'; // Reuse class or create specific one
                    feedback.textContent = `File "${file.name}" uploaded!`;
                    document.body.appendChild(feedback);
                    setTimeout(() => feedback.remove(), 3000);
                    
                    // Refresh note to show new attachment
                    // This assumes notesForCurrentPage and currentPageId are accessible
                    // and ui.displayNotes can re-render the page correctly.
                    // Consider a more targeted update if possible.
                    if (window.currentPageId) {
                         const notes = await notesAPI.getNotesForPage(window.currentPageId);
                         window.notesForCurrentPage = notes; // Update global cache
                         ui.displayNotes(notes, window.currentPageId); // Re-render with fresh data
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

    // Assemble note item
    // Create a header row for controls and content wrapper
    const noteHeaderEl = document.createElement('div');
    noteHeaderEl.className = 'note-header-row';
    noteHeaderEl.appendChild(controlsEl);
    noteHeaderEl.appendChild(contentWrapperEl);

    noteItemEl.appendChild(noteHeaderEl);

    // Children container
    const childrenContainerEl = document.createElement('div');
    childrenContainerEl.className = 'note-children';
    if (note.collapsed) {
        childrenContainerEl.classList.add('collapsed');
    }

    // Render children recursively
    if (note.children && note.children.length > 0) {
        note.children.forEach(childNote => {
            childrenContainerEl.appendChild(renderNote(childNote, nestingLevel + 1));
        });
    }

    noteItemEl.appendChild(childrenContainerEl);

    // Update Feather icons with error handling
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
    contentEl.innerHTML = '';
    contentEl.textContent = rawContent;
    contentEl.focus();

    // Handle blur to switch back to rendered mode
    const handleBlur = () => {
        switchToRenderedMode(contentEl);
        contentEl.removeEventListener('blur', handleBlur);
        contentEl.removeEventListener('paste', handlePasteImage); // Remove paste listener on blur
    };
    contentEl.addEventListener('blur', handleBlur);

    // Handle pasting images
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
            event.preventDefault(); // Prevent default paste action for the image
            console.log('Pasted image file:', imageFile);

            const formData = new FormData();
            // Create a unique name for the pasted image, e.g., based on timestamp
            const fileName = `pasted_image_${Date.now()}.${imageFile.name.split('.').pop() || 'png'}`;
            formData.append('attachmentFile', imageFile, fileName);
            formData.append('note_id', noteId);
            // Potentially add a flag to indicate it's a pasted inline image
            // formData.append('is_inline', 'true'); 

            try {
                // Use a generic API function or create a new one if backend needs different handling
                const result = await attachmentsAPI.uploadAttachment(formData);
                console.log('Pasted image uploaded successfully:', result);

                if (result && result.url) {
                    // Insert image as a thumbnail at current cursor position or end of content
                    // This is a simplified insertion. For precise cursor insertion:
                    // document.execCommand('insertHTML', false, `<img src="${result.url}" alt="Pasted Image" style="max-width: 200px; cursor: pointer;" data-original-url="${result.url}" class="pasted-image-thumbnail">`);
                    
                    // Simpler: append to current content (might not be at cursor)
                    // Or, better, use parseAndRender to add it to rawContent and re-render
                    const currentRawContent = contentEl.textContent;
                    const imageMarkdown = `\n![Pasted Image](${result.url})\n`;
                    contentEl.textContent = currentRawContent + imageMarkdown;
                    
                    // Update raw content dataset and re-render if needed (or save directly)
                    contentEl.dataset.rawContent = contentEl.textContent;
                    // Potentially trigger save or re-render logic here
                    // For now, we just update the textContent, blur will handle saving.

                    // Show feedback
                    const feedback = document.createElement('div');
                    feedback.className = 'copy-feedback';
                    feedback.textContent = 'Image pasted and uploaded!';
                    document.body.appendChild(feedback);
                    setTimeout(() => feedback.remove(), 3000);

                    // Optionally, refresh attachments list for the note if it's separate
                    // For now, assuming the pasted image is primarily inline content.
                    // If it should also appear in the attachments list, an explicit refresh is needed:
                    if (window.currentPageId) {
                         const notes = await notesAPI.getNotesForPage(window.currentPageId);
                         window.notesForCurrentPage = notes; 
                         ui.displayNotes(notes, window.currentPageId); 
                    }

                } else {
                    throw new Error('Upload result did not include a URL.');
                }

            } catch (error) {
                console.error('Error uploading pasted image:', error);
                alert(`Failed to upload pasted image: ${error.message}`);
            } // End of try-catch block
        }
        // If not an image, let the default paste behavior occur
    }; // End of handlePasteImage function

    contentEl.addEventListener('paste', handlePasteImage);
}

/**
 * Switches a note content element to rendered mode
 * @param {HTMLElement} contentEl - The note content element
 */
function switchToRenderedMode(contentEl) {
    if (contentEl.classList.contains('rendered-mode')) return;

    const newContent = contentEl.textContent || '';
    contentEl.dataset.rawContent = newContent;
    
    contentEl.classList.remove('edit-mode');
    contentEl.classList.add('rendered-mode');
    contentEl.contentEditable = false;

    // Re-render content if there is any
    if (newContent.trim()) {
        contentEl.innerHTML = parseAndRenderContent(newContent);
    } else {
        contentEl.innerHTML = '';
    }
}

/**
 * Parses and renders note content with special formatting
 * @param {string} rawContent - Raw note content
 * @returns {string} HTML string for display
 */
function parseAndRenderContent(rawContent) {
    if (!rawContent) return '';
    
    let html = String(rawContent);

    // Replace property patterns directly inline with rendered pills
    html = html.replace(/\{([^:}]+)::([^}]+)\}/g, (match, key, value) => {
        const trimmedKey = key.trim();
        const trimmedValue = value.trim();
        
        if (!trimmedKey || !trimmedValue) return match; // Keep original if invalid
        
        // Handle favorite properties specially
        if (trimmedKey.toLowerCase() === 'favorite' && trimmedValue.toLowerCase() === 'true') {
            return `<span class="property-favorite">⭐</span>`;
        }
        
        // Handle alias properties specially - render as page links
        if (trimmedKey.toLowerCase() === 'alias') {
            return `<span class="property-inline alias-property">
                <span class="property-key">alias::</span>
                <span class="page-link-bracket">[[</span>
                <a href="#" class="page-link" data-page-name="${trimmedValue}">${trimmedValue}</a>
                <span class="page-link-bracket">]]</span>
            </span>`;
        }
        
        // Handle tag properties specially
        if (trimmedKey.toLowerCase() === 'tag' || trimmedKey.startsWith('tag-')) {
            return `<span class="property-tag">#${trimmedValue}</span>`;
        }
        
        return `<span class="property-inline">
            <span class="property-key">${trimmedKey}::</span>
            <span class="property-value">${trimmedValue}</span>
        </span>`;
    });

    // Handle task markers with checkboxes - don't show the TODO/DONE prefix in content
    if (html.startsWith('TODO ')) {
        const taskContent = html.substring(5); // Remove "TODO " prefix
        html = `
            <div class="task-container todo">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="TODO" />
                    <span class="task-status-badge todo">TODO</span>
                </div>
                <div class="task-content">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('DONE ')) {
        const taskContent = html.substring(5); // Remove "DONE " prefix
        html = `
            <div class="task-container done">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="DONE" checked />
                    <span class="task-status-badge done">DONE</span>
                </div>
                <div class="task-content done-text">${taskContent}</div>
            </div>
        `;
    } else if (html.startsWith('CANCELLED ')) {
        const taskContent = html.substring(10); // Remove "CANCELLED " prefix
        html = `
            <div class="task-container cancelled">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="CANCELLED" disabled />
                    <span class="task-status-badge cancelled">CANCELLED</span>
                </div>
                <div class="task-content cancelled-text">${taskContent}</div>
            </div>
        `;
    } else {
        // Handle page links
        html = html.replace(/\[\[(.*?)\]\]/g, (match, pageName) => {
            const trimmedName = pageName.trim();
            return `<span class="page-link-bracket">[[</span><a href="#" class="page-link" data-page-name="${trimmedName}">${trimmedName}</a><span class="page-link-bracket">]]</span>`;
        });

        // Handle block references (transclusions)
        html = html.replace(/!{{(.*?)}}/g, (match, blockRef) => {
            const trimmedRef = blockRef.trim();
            if (/^\d+$/.test(trimmedRef)) {
                return `<div class="transclusion-placeholder" data-block-ref="${trimmedRef}">Loading...</div>`;
            } else {
                return `<div class="transclusion-placeholder error" data-block-ref="${trimmedRef}">Invalid block reference</div>`;
            }
        });

        // Use marked.js for Markdown if available (only for non-task content)
        if (typeof marked !== 'undefined' && marked.parse) {
            try {
                // Configure marked for better inline parsing and custom image rendering
                const renderer = new marked.Renderer();
                const originalImageRenderer = renderer.image;
                renderer.image = (href, title, text) => {
                    // Create a standard image tag but add a class and data attribute for modal viewing
                    // Use a simple style for thumbnail, can be enhanced with CSS
                    let imageHTML = originalImageRenderer.call(renderer, href, title, text);
                    // Add class and data attribute to the <img> tag
                    // Regex to find <img> tag and add attributes carefully
                    imageHTML = imageHTML.replace(/^<img(.*?)>/, 
                        `<img$1 class="content-image" data-original-src="${href}" style="max-width: 200px; cursor: pointer;">`);
                    return imageHTML;
                };

                const originalHtml = html;
                html = marked.parse(html, {
                    renderer: renderer, // Use the custom renderer
                    breaks: false, 
                    gfm: true,
                    smartypants: true,
                    sanitize: false, // Be cautious with sanitize: false if content is not trusted
                    smartLists: true
                });
                console.log('Markdown processed with custom image renderer:', { original: originalHtml, processed: html });
            } catch (e) {
                console.warn('Marked.js parsing error:', e);
                // Don't add <br> tags - keep original content
            }
        } else {
            console.warn('marked.js not loaded properly or missing parse method');
            // Don't add <br> tags - keep original content as is
        }
    }

    return html;
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
        // Handle favorite properties specially
        if (name.toLowerCase() === 'favorite' && String(value).toLowerCase() === 'true') {
            return `<span class="property-item favorite">
                <span class="property-favorite">⭐</span>
            </span>`;
        }

        // Handle tag::tag format
        if (name.startsWith('tag::')) {
            const tagName = name.substring(5);
            return `<span class="property-item tag">
                <span class="property-key">#</span>
                <span class="property-value">${tagName}</span>
            </span>`;
        }

        // Handle list properties
        if (Array.isArray(value)) {
            return value.map(v => `
                <span class="property-item">
                    <span class="property-key">${name}</span>
                    <span class="property-value">${v}</span>
                </span>
            `).join('');
        }

        // Handle regular properties
        return `<span class="property-item">
            <span class="property-key">${name}</span>
            <span class="property-value">${value}</span>
        </span>`;
    }).join('');

    container.innerHTML = propertyItems;
    container.style.display = 'flex'; // Use flex for layout
    container.style.flexWrap = 'wrap'; // Allow pills to wrap
    container.style.gap = 'var(--ls-space-2, 8px)'; // Add gap between pills
    // Ensure it's placed appropriately if it was hidden
    container.classList.remove('hidden'); 
}

/**
 * Displays notes in the container
 * @param {Array} notesData - Array of note objects
 * @param {number} pageId - Current page ID
 */
function displayNotes(notesData, pageId) {
    domRefs.notesContainer.innerHTML = '';

    if (!notesData || notesData.length === 0) {
        // Create empty note for new page
        const tempId = `temp-${pageId || 'new'}-root-${Date.now()}`;
        const emptyNote = {
            id: tempId,
            content: '',
            properties: {},
            children: []
        };
        const emptyNoteEl = renderNote(emptyNote, 0);
        domRefs.notesContainer.appendChild(emptyNoteEl);
        
        // Switch to edit mode for the empty note
        const contentDiv = emptyNoteEl.querySelector('.note-content');
        if (contentDiv) {
            switchToEditMode(contentDiv);
        }
        return;
    }

    // Build and display note tree
    const noteTree = buildNoteTree(notesData);
    noteTree.forEach(note => {
        domRefs.notesContainer.appendChild(renderNote(note, 0));
    });
    
    // Initialize drag and drop functionality
    initializeDragAndDrop();
}

/**
 * Initializes drag and drop functionality for notes
 */
function initializeDragAndDrop() {
    if (typeof Sortable === 'undefined') {
        console.warn('Sortable.js not loaded, drag and drop disabled');
        return;
    }

    // Track drag state to prevent interference
    window.isDragInProgress = false;

    // Initialize sortable for the main notes container
    const notesContainer = domRefs.notesContainer;
    if (notesContainer) {
        Sortable.create(notesContainer, {
            group: 'notes',
            animation: 150,
            handle: '.note-bullet',
            ghostClass: 'note-ghost',
            chosenClass: 'note-chosen',
            dragClass: 'note-drag',
            onStart: function(evt) {
                window.isDragInProgress = true;
            },
            onEnd: async function(evt) {
                try {
                    await handleNoteDrop(evt);
                } finally {
                    setTimeout(() => {
                        window.isDragInProgress = false;
                    }, 500); // Keep the flag for a bit longer to prevent interference
                }
            }
        });
    }

    // Initialize sortable for all children containers
    const childrenContainers = document.querySelectorAll('.note-children');
    childrenContainers.forEach(container => {
        Sortable.create(container, {
            group: 'notes',
            animation: 150,
            handle: '.note-bullet',
            ghostClass: 'note-ghost',
            chosenClass: 'note-chosen',
            dragClass: 'note-drag',
            onStart: function(evt) {
                window.isDragInProgress = true;
            },
            onEnd: async function(evt) {
                try {
                    await handleNoteDrop(evt);
                } finally {
                    setTimeout(() => {
                        window.isDragInProgress = false;
                    }, 500); // Keep the flag for a bit longer to prevent interference
                }
            }
        });
    });
}

/**
 * Handles note drop events
 * @param {Object} evt - Sortable drop event
 */
async function handleNoteDrop(evt) {
    const noteId = evt.item.dataset.noteId;
    if (!noteId || noteId.startsWith('temp-')) {
        console.warn('Attempted to drop a temporary or invalid note. Aborting.');
        // Optionally revert drag UI immediately if needed
        if (evt.from && evt.item.parentNode === evt.from) { // Check if it's still in the original container
          evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
        } else if (evt.item.parentNode) { // If it was moved, try to remove it
          evt.item.parentNode.removeChild(evt.item);
        }
        return;
    }

    const newContainer = evt.to;
    const oldContainer = evt.from;
    const newIndex = evt.newIndex;
    const oldIndex = evt.oldIndex;

    // Get the current note data to preserve content
    const currentNoteData = window.notesForCurrentPage.find(n => String(n.id) === String(noteId));
    if (!currentNoteData) {
        console.error('Note data not found for ID:', noteId, 'in window.notesForCurrentPage. Aborting drop.');
        // Revert the DOM change on error
        if (oldContainer !== newContainer) {
            oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
        } else {
            if (oldIndex < newIndex) {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
            } else {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex + 1]);
            }
        }
        return;
    }

    let newParentId = null;
    if (newContainer.classList.contains('note-children')) {
        const parentNoteItem = newContainer.closest('.note-item');
        if (parentNoteItem) {
            newParentId = parentNoteItem.dataset.noteId;
            if (!newParentId || newParentId.startsWith('temp-')) {
                console.error('Cannot drop note onto a temporary or invalid parent note.');
                // Revert logic as above
                 if (oldContainer !== newContainer) { oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]); } else { if (oldIndex < newIndex) { oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]); } else { oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex + 1]); } }
                return;
            }
        }
    }

    // Calculate the correct order index based on its new siblings in the data model
    let targetOrderIndex = 0;
    const siblingsInNewParent = window.notesForCurrentPage.filter(note =>
        (note.parent_note_id ? String(note.parent_note_id) : null) === (newParentId ? String(newParentId) : null) &&
        String(note.id) !== String(noteId) // Exclude the note being moved
    ).sort((a, b) => a.order_index - b.order_index);

    if (newIndex >= siblingsInNewParent.length) {
        targetOrderIndex = siblingsInNewParent.length > 0 ? siblingsInNewParent[siblingsInNewParent.length - 1].order_index + 1 : 0;
    } else {
        // If dropped at the beginning or in the middle
        // We need to adjust order_index based on the visual position relative to actual data items
        const elementAtIndex = newContainer.children[newIndex];
        if(elementAtIndex && elementAtIndex !== evt.item) {
            const siblingNoteId = elementAtIndex.dataset.noteId;
            const siblingNoteData = window.notesForCurrentPage.find(n => String(n.id) === String(siblingNoteId));
            if (siblingNoteData) {
                 targetOrderIndex = siblingNoteData.order_index;
                 // Shift subsequent items if necessary (API should handle this, or we do it client-side before API call)
            } else {
                // Fallback if sibling data not found, less accurate
                targetOrderIndex = newIndex;
            }
        } else {
             targetOrderIndex = newIndex; // Fallback or if dropped at the end relative to its own position
        }
    }
    
    // Optimistically update UI (SortableJS already did this)
    // Prepare data for API call
    const updateData = {
        content: currentNoteData.content || '', // Ensure content is always present
        parent_note_id: newParentId,
        order_index: targetOrderIndex
    };

    console.log('Attempting to update note position:', { noteId, ...updateData });

    try {
        const updatedNote = await window.notesAPI.updateNote(noteId, updateData);
        console.log('Note position updated successfully on server:', updatedNote);

        // Update local data cache accurately
        currentNoteData.parent_note_id = updatedNote.parent_note_id;
        currentNoteData.order_index = updatedNote.order_index;
        currentNoteData.updated_at = updatedNote.updated_at; // Sync timestamp

        // Potentially re-fetch all notes for the page to ensure perfect order and hierarchy
        // This is a trade-off: ensures consistency but can be a bit slower.
        // For now, we'll rely on the optimistic update and correct data sync.
        // If inconsistencies appear, re-enable full refresh:
        if (window.currentPageId && !window.isDragInProgress) { // Check drag flag here as well
            const notes = await window.notesAPI.getNotesForPage(window.currentPageId);
            window.notesForCurrentPage = notes; // Update global cache
            ui.displayNotes(notes, window.currentPageId); // Re-render with fresh data
        }

    } catch (error) {
        console.error('Error updating note position on server:', error);
        // Show user-friendly error message
        const feedback = document.createElement('div');
        feedback.className = 'copy-feedback'; // Reuse existing class, maybe add an error variant
        feedback.style.background = 'var(--color-error, #dc2626)'; // Use CSS variable for error color
        feedback.textContent = `Failed to save position: ${error.message}`;
        document.body.appendChild(feedback);
        setTimeout(() => feedback.remove(), 3000);

        // Revert the DOM change by moving the item back
        // This is tricky because Sortable already moved it. We might need to re-render from original data.
        // For now, a simple revert based on old indices:
        if (oldContainer !== newContainer) {
            oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
        } else {
            if (oldIndex < newIndex) {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex]);
            } else {
                oldContainer.insertBefore(evt.item, oldContainer.children[oldIndex + 1]);
            }
        }
        // Ideally, after reverting, also re-fetch and re-render notes for consistency
        if (window.currentPageId) {
            const notes = await window.notesAPI.getNotesForPage(window.currentPageId);
            window.notesForCurrentPage = notes;
            ui.displayNotes(notes, window.currentPageId);
        }
    }
}

/**
 * Builds a tree structure from flat note array
 * @param {Array} notes - Flat array of note objects
 * @param {number|null} [parentId=null] - Parent note ID
 * @returns {Array} Tree structure of notes
 */
function buildNoteTree(notes, parentId = null) {
    if (!notes) return [];
    
    return notes
        .filter(note => note.parent_note_id === parentId)
        .sort((a, b) => a.order_index - b.order_index)
        .map(note => ({
            ...note,
            children: buildNoteTree(notes, note.id)
        }));
}

/**
 * Updates the page title
 * @param {string} name - Page name
 */
function updatePageTitle(name) {
    domRefs.currentPageTitleEl.textContent = name;
    document.title = `${name} - notd`;
}

/**
 * Updates the page list in the sidebar
 * @param {Array} pages - Array of page objects
 * @param {string} activePageName - Currently active page
 */
function updatePageList(pages, activePageName) {
    domRefs.pageListContainer.innerHTML = '';

    if (!pages || pages.length === 0) {
        const defaultPageName = typeof getTodaysJournalPageName === 'function' 
            ? getTodaysJournalPageName() 
            : 'Journal';
        const link = document.createElement('a');
        link.href = '#';
        link.dataset.pageName = defaultPageName;
        link.textContent = `${defaultPageName} (Create)`;
        domRefs.pageListContainer.appendChild(link);
        return;
    }

    // Sort by updated_at DESC, then name ASC
    pages.sort((a, b) => {
        if (a.updated_at > b.updated_at) return -1;
        if (a.updated_at < b.updated_at) return 1;
        return a.name.localeCompare(b.name);
    });

    // Limit to 7 most recent pages
    const limitedPages = pages.slice(0, 7);

    limitedPages.forEach(page => {
        const link = document.createElement('a');
        link.href = '#';
        link.dataset.pageName = page.name;
        link.textContent = page.name;
        if (page.name === activePageName) {
            link.classList.add('active');
        }
        domRefs.pageListContainer.appendChild(link);
    });
}

/**
 * Updates the active page link in the sidebar
 * @param {string} pageName - Active page name
 */
function updateActivePageLink(pageName) {
    document.querySelectorAll('#page-list a').forEach(link => {
        link.classList.toggle('active', link.dataset.pageName === pageName);
    });
}

/**
 * Shows or updates a property in a note
 * @param {string} noteId - Note ID
 * @param {string} propertyName - Property name
 * @param {string} propertyValue - Property value
 */
function showPropertyInNote(noteId, propertyName, propertyValue) {
    const noteEl = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteEl) return;

    let propertiesEl = noteEl.querySelector('.note-properties');
    if (!propertiesEl) {
        propertiesEl = document.createElement('div');
        propertiesEl.className = 'note-properties';
        noteEl.querySelector('.note-content-wrapper').appendChild(propertiesEl);
    }

    const existingProp = propertiesEl.querySelector(`.property-item[data-property="${propertyName}"]`);
    if (existingProp) {
        existingProp.querySelector('.property-value').innerHTML = parseAndRenderContent(String(propertyValue));
    } else {
        const propItem = document.createElement('span');
        propItem.className = 'property-item';
        propItem.dataset.property = propertyName;
        propItem.innerHTML = `
            <span class="property-key">${propertyName}::</span>
            <span class="property-value">${parseAndRenderContent(String(propertyValue))}</span>
        `;
        propertiesEl.appendChild(propItem);
    }
    propertiesEl.style.display = 'block';
}

/**
 * Removes a property from a note
 * @param {string} noteId - Note ID
 * @param {string} propertyName - Property name to remove
 */
function removePropertyFromNote(noteId, propertyName) {
    const noteEl = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    if (!noteEl) return;

    const propertiesEl = noteEl.querySelector('.note-properties');
    if (!propertiesEl) return;

    const propItem = propertiesEl.querySelector(`.property-item[data-property="${propertyName}"]`);
    if (propItem) {
        propItem.remove();
        if (propertiesEl.children.length === 0) {
            propertiesEl.style.display = 'none';
        }
    }
}

/**
 * Renders transcluded content
 * @param {HTMLElement} placeholderEl - Transclusion placeholder element
 * @param {string} noteContent - Content to render
 * @param {string} noteId - ID of the transcluded note
 */
function renderTransclusion(placeholderEl, noteContent, noteId) {
    if (!placeholderEl || !noteContent) return;

    const contentEl = document.createElement('div');
    contentEl.className = 'transcluded-content';
    contentEl.innerHTML = `
        <div class="transclusion-header">
            <span class="transclusion-icon">🔗</span>
            <a href="#" class="transclusion-link" data-note-id="${noteId}">View original note</a>
        </div>
        <div class="transclusion-body">
            ${parseAndRenderContent(noteContent)}
        </div>
    `;
    
    // Add click handler for the transclusion link
    const transclusionLink = contentEl.querySelector('.transclusion-link');
    if (transclusionLink) {
        transclusionLink.addEventListener('click', (e) => {
            e.preventDefault();
            // Find and focus the original note
            const originalNote = document.querySelector(`[data-note-id="${noteId}"]`);
            if (originalNote) {
                originalNote.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Add a temporary highlight
                originalNote.style.background = 'rgba(59, 130, 246, 0.1)';
                setTimeout(() => {
                    originalNote.style.background = '';
                }, 2000);
            }
        });
    }
    
    placeholderEl.replaceWith(contentEl);
}

/**
 * Calendar Widget Module
 */
const calendarWidget = {
    currentDate: new Date(),
    currentPageName: null,
    
    init() {
        this.calendarEl = document.querySelector('.calendar-widget');
        if (!this.calendarEl) return;
        
        this.monthEl = this.calendarEl.querySelector('.current-month');
        this.daysEl = this.calendarEl.querySelector('.calendar-days');
        this.prevBtn = this.calendarEl.querySelector('.calendar-nav.prev');
        this.nextBtn = this.calendarEl.querySelector('.calendar-nav.next');
        
        this.bindEvents();
        this.render();
    },
    
    bindEvents() {
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => {
                this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                this.render();
            });
        }
        
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => {
                this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                this.render();
            });
        }
    },
    
    setCurrentPage(pageName) {
        this.currentPageName = pageName;
        this.render();
    },
    
    render() {
        if (!this.monthEl || !this.daysEl) return;
        
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();
        
        // Update month display
        this.monthEl.textContent = new Date(year, month).toLocaleString('default', { 
            month: 'long', 
            year: 'numeric' 
        });
        
        // Clear previous days
        this.daysEl.innerHTML = '';
        
        // Get first day of month and total days
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const totalDays = lastDay.getDate();
        const startingDay = firstDay.getDay();
        
        // Add empty cells for days before start of month
        for (let i = 0; i < startingDay; i++) {
            const emptyDay = document.createElement('div');
            emptyDay.className = 'calendar-day empty';
            this.daysEl.appendChild(emptyDay);
        }
        
        // Add days of the month
        const today = new Date();
        for (let day = 1; day <= totalDays; day++) {
            const dayEl = document.createElement('div');
            dayEl.className = 'calendar-day';
            dayEl.textContent = day;
            
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            // Check if this is today
            if (day === today.getDate() && 
                month === today.getMonth() && 
                year === today.getFullYear()) {
                dayEl.classList.add('today');
            }
            
            // Check if this is the current page
            if (this.currentPageName === dateStr) {
                dayEl.classList.add('current-page');
            }
            
            // Add click handler for journal navigation
            dayEl.addEventListener('click', () => {
                if (typeof window.loadPage === 'function') {
                    window.loadPage(dateStr);
                }
            });
            
            this.daysEl.appendChild(dayEl);
        }
    }
};

// Initialize calendar when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    calendarWidget.init();
});

/**
 * Renders attachments for a note
 * @param {HTMLElement} container - The container element to render attachments into
 * @param {Array<Object>} attachments - Array of attachment objects
 * @param {string} noteId - The ID of the note these attachments belong to
 */
function renderAttachments(container, attachments, noteId) {
    container.innerHTML = ''; // Clear previous attachments
    if (!attachments || attachments.length === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex'; // Use flex for layout

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

        // Create the link element. It will either navigate or open modal.
        const linkEl = document.createElement('a');
        linkEl.href = attachment.url;
        linkEl.className = 'attachment-name';
        if (!isImage) {
            linkEl.target = '_blank'; // Open non-images in new tab
        }
        linkEl.textContent = attachment.name;

        attachmentEl.innerHTML = `
            <div class="attachment-preview">${previewEl}</div>
            <div class="attachment-info">
                ${linkEl.outerHTML} <!-- Inject the created link -->
                <span class="attachment-meta">${attachment.type} - ${new Date(attachment.created_at).toLocaleDateString()}</span>
            </div>
            <button class="attachment-delete-btn" data-attachment-id="${attachment.id}" data-note-id="${noteId}">
                <i data-feather="trash-2"></i>
            </button>
        `;

        container.appendChild(attachmentEl);

        // If it's an image, add click listener to open in modal
        if (isImage) {
            const imageLinkInDOM = attachmentEl.querySelector('.attachment-name');
            if (imageLinkInDOM) {
                imageLinkInDOM.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (domRefs.imageViewerModal && domRefs.imageViewerModalImg && domRefs.imageViewerModalClose) {
                        domRefs.imageViewerModalImg.src = attachment.url;
                        domRefs.imageViewerModal.classList.add('active');

                        // Setup close listeners only once or ensure they are managed properly
                        const closeImageModal = () => {
                            domRefs.imageViewerModal.classList.remove('active');
                            domRefs.imageViewerModalImg.src = ''; // Clear image src
                            domRefs.imageViewerModalClose.removeEventListener('click', closeImageModal);
                            domRefs.imageViewerModal.removeEventListener('click', outsideClickHandler);
                        };

                        const outsideClickHandler = (event) => {
                            if (event.target === domRefs.imageViewerModal) { // Clicked on backdrop
                                closeImageModal();
                            }
                        };

                        domRefs.imageViewerModalClose.addEventListener('click', closeImageModal);
                        domRefs.imageViewerModal.addEventListener('click', outsideClickHandler);
                    } else {
                        console.error('Image viewer modal elements not found.');
                        // Fallback to old behavior if modal elements are missing
                        window.open(attachment.url, '_blank');
                    }
                });
            }
        }

        // Add event listener for delete button
        const deleteBtn = attachmentEl.querySelector('.attachment-delete-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async () => {
                if (confirm(`Are you sure you want to delete "${attachment.name}"?`)) {
                    try {
                        await attachmentsAPI.deleteAttachment(attachment.id);
                        attachmentEl.remove(); // Remove from UI
                        // Check if container is empty after deletion
                        if (container.children.length === 0) {
                            container.style.display = 'none';
                        }
                        // Optionally, refresh the note or page if timestamps/etc. are affected server-side
                        // For now, just removing the element is fine.
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
 * Shows a generic input modal and returns a Promise with the entered value.
 * @param {string} title - The title for the modal.
 * @param {string} [defaultValue=''] - The default value for the input field.
 * @returns {Promise<string|null>} A Promise that resolves with the input string or null if canceled.
 */
function showGenericInputModal(title, defaultValue = '') {
    return new Promise((resolve) => {
        const modal = document.getElementById('generic-input-modal');
        const titleEl = document.getElementById('generic-input-modal-title');
        const inputEl = document.getElementById('generic-input-modal-input');
        const okBtn = document.getElementById('generic-input-modal-ok');
        const cancelBtn = document.getElementById('generic-input-modal-cancel');

        if (!modal || !titleEl || !inputEl || !okBtn || !cancelBtn) {
            console.error('Generic input modal elements not found!');
            resolve(prompt(title, defaultValue)); // Fallback to native prompt if modal elements are missing
            return;
        }

        titleEl.textContent = title;
        inputEl.value = defaultValue;
        modal.classList.add('active');
        inputEl.focus();
        inputEl.select();

        const closeHandler = (value) => {
            modal.classList.remove('active');
            // Remove event listeners to prevent multiple resolutions
            okBtn.removeEventListener('click', okHandler);
            inputEl.removeEventListener('keypress', enterKeyHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
            document.removeEventListener('keydown', escapeKeyHandler);
            resolve(value);
        };

        const okHandler = () => {
            closeHandler(inputEl.value);
        };

        const cancelHandler = () => {
            closeHandler(null);
        };

        const enterKeyHandler = (e) => {
            if (e.key === 'Enter') {
                okHandler();
            }
        };
        
        const escapeKeyHandler = (e) => {
            if (e.key === 'Escape') {
                cancelHandler();
            }
        };

        okBtn.addEventListener('click', okHandler);
        inputEl.addEventListener('keypress', enterKeyHandler);
        cancelBtn.addEventListener('click', cancelHandler);
        document.addEventListener('keydown', escapeKeyHandler); // Listen on document for Escape key
    });
}

/**
 * Shows a generic confirmation modal and returns a Promise with a boolean.
 * @param {string} title - The title for the modal.
 * @param {string} message - The confirmation message.
 * @returns {Promise<boolean>} A Promise that resolves with true if OK is clicked, false otherwise.
 */
function showGenericConfirmModal(title, message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('generic-confirm-modal');
        const titleEl = document.getElementById('generic-confirm-modal-title');
        const messageEl = document.getElementById('generic-confirm-modal-message');
        const okBtn = document.getElementById('generic-confirm-modal-ok');
        const cancelBtn = document.getElementById('generic-confirm-modal-cancel');

        if (!modal || !titleEl || !messageEl || !okBtn || !cancelBtn) {
            console.error('Generic confirm modal elements not found!');
            resolve(confirm(message)); // Fallback to native confirm
            return;
        }

        titleEl.textContent = title;
        messageEl.textContent = message;
        modal.classList.add('active');
        okBtn.focus();

        const closeHandler = (value) => {
            modal.classList.remove('active');
            okBtn.removeEventListener('click', okHandler);
            cancelBtn.removeEventListener('click', cancelHandler);
            document.removeEventListener('keydown', escapeKeyHandler);
            resolve(value);
        };

        const okHandler = () => {
            closeHandler(true);
        };

        const cancelHandler = () => {
            closeHandler(false);
        };
        
        const escapeKeyHandler = (e) => {
            if (e.key === 'Escape') {
                cancelHandler();
            }
        };

        okBtn.addEventListener('click', okHandler);
        cancelBtn.addEventListener('click', cancelHandler);
        document.addEventListener('keydown', escapeKeyHandler);
    });
}

/**
 * Extracts properties from note content using {key::value} syntax
 * @param {string} content - Note content 
 * @returns {Object} Object with content and extracted properties
 * @deprecated This function is kept for backward compatibility but properties are now handled inline
 */
function extractPropertiesFromContent(content) {
    // Simple stub - properties are now handled inline in parseAndRenderContent
    return { content: content || '', properties: {} };
}

/**
 * Renders properties as inline elements
 * @param {Object} properties - Properties object
 * @returns {string} HTML string for properties
 * @deprecated This function is kept for backward compatibility but properties are now handled inline
 */
function renderInlineProperties(properties) {
    // Simple stub - properties are now handled inline in parseAndRenderContent
    return '';
}

/**
 * Renders page properties as inline "pills" directly on the page.
 * @param {Object} properties - The page's properties object.
 * @param {HTMLElement} targetContainer - The HTML element to render properties into.
 */
function renderPageInlineProperties(properties, targetContainer) {
    if (!targetContainer) {
        console.error("Target container for inline page properties not provided.");
        return;
    }
    targetContainer.innerHTML = ''; // Clear previous properties

    if (!properties || Object.keys(properties).length === 0) {
        targetContainer.style.display = 'none';
        return;
    }

    let hasVisibleProperties = false;
    const fragment = document.createDocumentFragment();

    Object.entries(properties).forEach(([key, value]) => {
        // Skip specific properties like 'type: journal' from inline display
        // if ((key === 'type' && value === 'journal')) {
        //     return;
        // }

        hasVisibleProperties = true;

        const processValue = (val) => {
            const propItem = document.createElement('span');
            propItem.className = 'property-inline'; // Use the correct CSS class for inline pills

            // Special styling for favorite properties
            if (key.toLowerCase() === 'favorite' && String(val).toLowerCase() === 'true') {
                propItem.innerHTML = `<span class="property-favorite">⭐</span>`;
                fragment.appendChild(propItem);
                return;
            }

            // Special styling for tags (key starting with 'tag::')
            if (key.startsWith('tag::')) {
                const tagName = key.substring(5); // Remove 'tag::' prefix
                propItem.innerHTML = `<span class="property-key">#${tagName}</span>`;
                propItem.classList.add('property-tag'); // Add tag-specific styling
            } else {
                // For regular properties, show key: value
                // Don't use parseAndRenderContent for simple values to avoid <p> tags
                const displayValue = String(val).trim();
                propItem.innerHTML = `<span class="property-key">${key}:</span> <span class="property-value">${displayValue}</span>`;
            }
            fragment.appendChild(propItem);
        };

        if (Array.isArray(value)) {
            value.forEach(v => processValue(v));
        } else {
            processValue(value);
        }
    });

    if (hasVisibleProperties) {
        targetContainer.appendChild(fragment);
        targetContainer.style.display = 'flex'; // Use flex for layout
        targetContainer.style.flexWrap = 'wrap'; // Allow pills to wrap
        targetContainer.style.gap = 'var(--ls-space-2, 8px)'; // Add gap between pills
        // Ensure it's placed appropriately if it was hidden
        targetContainer.classList.remove('hidden'); 
    } else {
        targetContainer.style.display = 'none';
        targetContainer.classList.add('hidden');
    }
}

// Export functions and DOM references for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        domRefs,
        renderNote,
        parseAndRenderContent,
        extractPropertiesFromContent,
        renderInlineProperties,
        renderPageInlineProperties,
        renderProperties,
        displayNotes,
        buildNoteTree,
        updatePageTitle,
        updatePageList,
        updateActivePageLink,
        showPropertyInNote,
        removePropertyFromNote,
        renderTransclusion,
        switchToEditMode,
        switchToRenderedMode,
        initializeDragAndDrop,
        handleNoteDrop,
        calendarWidget,
        renderAttachments,
        showGenericInputModal,
        showGenericConfirmModal,
        focusOnNote,
        showAllNotes,
        getNoteAncestors,
        renderBreadcrumbs
    };
} else {
    // For browser environment, attach to window
    window.ui = {
        domRefs,
        renderNote,
        parseAndRenderContent,
        extractPropertiesFromContent,
        renderInlineProperties,
        renderPageInlineProperties,
        renderProperties,
        displayNotes,
        buildNoteTree,
        updatePageTitle,
        updatePageList,
        updateActivePageLink,
        showPropertyInNote,
        removePropertyFromNote,
        renderTransclusion,
        switchToEditMode,
        switchToRenderedMode,
        initializeDragAndDrop,
        handleNoteDrop,
        calendarWidget,
        renderAttachments,
        showGenericInputModal,
        showGenericConfirmModal,
        focusOnNote,
        showAllNotes,
        getNoteAncestors,
        renderBreadcrumbs,
        showAllNotesAndLoadPage
    };
}

/**
 * Traverses upwards from the noteId using parent_note_id to collect all ancestors.
 * @param {string} noteId - The ID of the note to start from.
 * @param {Array<Object>} allNotesOnPage - Flat list of all notes on the current page.
 * @returns {Array<Object>} Array of note objects, ordered from furthest ancestor to direct parent.
 */
function getNoteAncestors(noteId, allNotesOnPage) {
    const ancestors = [];
    if (!allNotesOnPage) {
        console.warn('getNoteAncestors called without allNotesOnPage');
        return ancestors;
    }
    let currentNote = allNotesOnPage.find(note => String(note.id) === String(noteId));

    while (currentNote && currentNote.parent_note_id) {
        const parentNote = allNotesOnPage.find(note => String(note.id) === String(currentNote.parent_note_id));
        if (parentNote) {
            ancestors.unshift(parentNote); // Add parent to the beginning of the array
            currentNote = parentNote;
        } else {
            // Parent not found, break the loop
            break;
        }
    }
    return ancestors;
}

/**
 * Renders breadcrumbs for the focused note.
 * @param {string|null} focusedNoteId - The ID of the currently focused note, or null.
 * @param {Array<Object>} allNotesOnPage - Flat list of all notes for the current page.
 * @param {string} currentPageName - The name of the current page.
 */
function renderBreadcrumbs(focusedNoteId, allNotesOnPage, currentPageName) {
    if (!domRefs.breadcrumbsContainer) {
        console.warn('Breadcrumbs container not found in DOM.');
        return;
    }

    if (!focusedNoteId || !allNotesOnPage || allNotesOnPage.length === 0) {
        domRefs.breadcrumbsContainer.innerHTML = ''; // Clear breadcrumbs
        return;
    }

    const focusedNote = allNotesOnPage.find(n => String(n.id) === String(focusedNoteId));
    if (!focusedNote) {
        domRefs.breadcrumbsContainer.innerHTML = ''; // Clear if focused note not found
        return;
    }

    const ancestors = getNoteAncestors(focusedNoteId, allNotesOnPage);
    let html = `<a href="#" onclick="ui.showAllNotesAndLoadPage('${currentPageName}'); return false;">${currentPageName}</a>`;

    ancestors.forEach(ancestor => {
        const noteName = (ancestor.content ? (ancestor.content.split('\n')[0].substring(0, 30) + (ancestor.content.length > 30 ? '...' : '')) : `Note ${ancestor.id}`).replace(/</g, '&lt;').replace(/>/g, '&gt;');
        html += ` &gt; <a href="#" onclick="ui.focusOnNote('${ancestor.id}'); return false;">${noteName}</a>`;
    });

    const focusedNoteName = (focusedNote.content ? (focusedNote.content.split('\n')[0].substring(0, 30) + (focusedNote.content.length > 30 ? '...' : '')) : `Note ${focusedNote.id}`).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    html += ` &gt; <span class="breadcrumb-current">${focusedNoteName}</span>`;

    domRefs.breadcrumbsContainer.innerHTML = html;
}

// Helper function to be called by breadcrumb page link
function showAllNotesAndLoadPage(pageName) {
    if (typeof ui !== 'undefined' && ui.showAllNotes) {
        ui.showAllNotes(); 
    }
    if (typeof window.loadPage === 'function') {
        window.loadPage(pageName); 
    } else {
        console.warn('window.loadPage function not found. Cannot reload page for breadcrumb.');
    }
}

function renderNoteProperties(note) {
    if (!note.properties || Object.keys(note.properties).length === 0) {
        return '';
    }

    const propertyItems = Object.entries(note.properties).map(([name, value]) => {
        // Handle favorite properties specially
        if (name.toLowerCase() === 'favorite' && String(value).toLowerCase() === 'true') {
            return `<span class="property-item favorite">
                <span class="property-favorite">⭐</span>
            </span>`;
        }

        // Handle tag::tag format
        if (name.startsWith('tag::')) {
            const tagName = name.substring(5);
            return `<span class="property-item tag">
                <span class="property-key">#</span>
                <span class="property-value">${tagName}</span>
            </span>`;
        }

        // Handle list properties
        if (Array.isArray(value)) {
            return value.map(v => `
                <span class="property-item">
                    <span class="property-key">${name}</span>
                    <span class="property-value">${v}</span>
                </span>
            `).join('');
        }

        // Handle regular properties
        return `<span class="property-item">
            <span class="property-key">${name}</span>
            <span class="property-value">${value}</span>
        </span>`;
    }).join('');

    return `<div class="note-properties">${propertyItems}</div>`;
}

// Update sidebar toggle buttons
function updateSidebarToggleButtons() {
    const leftToggle = domRefs.toggleLeftSidebarBtn;
    const rightToggle = domRefs.toggleRightSidebarBtn;

    // Check if sidebar is initially collapsed to set correct icon
    const isLeftCollapsed = domRefs.leftSidebar && domRefs.leftSidebar.classList.contains('collapsed');
    const isRightCollapsed = domRefs.rightSidebar && domRefs.rightSidebar.classList.contains('collapsed');

    if (leftToggle) {
        leftToggle.textContent = isLeftCollapsed ? '☰' : '✕'; // Unicode hamburger menu and X
        leftToggle.title = isLeftCollapsed ? 'Show left sidebar' : 'Hide left sidebar';
    }

    if (rightToggle) {
        rightToggle.textContent = isRightCollapsed ? '☰' : '✕'; // Unicode hamburger menu and X
        rightToggle.title = isRightCollapsed ? 'Show right sidebar' : 'Hide right sidebar';
    }
}

// Call this after DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    updateSidebarToggleButtons();
});

/**
 * Focuses on a specific note by hiding all other notes at the same level and above
 * @param {string} noteId - The ID of the note to focus on
 */
function focusOnNote(noteId) {
    window.currentFocusedNoteId = noteId; // Set current focused note ID

    const focusedNote = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
    const notesContainer = document.getElementById('notes-container'); // Keep this for showAllBtn logic

    if (!focusedNote) {
        console.warn(`Focus target note with ID ${noteId} not found.`);
        return;
    }

    const elementsToMakeVisible = new Set();
    const elementsToFocus = new Set();

    // Process Focused Note and Its Descendants
    elementsToMakeVisible.add(focusedNote);
    elementsToFocus.add(focusedNote);

    function collectAndMarkDescendants(currentElement) {
        const childrenContainer = currentElement.querySelector('.note-children');
        if (childrenContainer) {
            const childNotes = Array.from(childrenContainer.children).filter(el => el.matches('.note-item'));
            childNotes.forEach(child => {
                elementsToMakeVisible.add(child);
                elementsToFocus.add(child);
                collectAndMarkDescendants(child); // Recursive call
            });
        }
    }
    collectAndMarkDescendants(focusedNote);

    // Process Ancestors
    let currentAncestor = focusedNote.parentElement.closest('.note-item');
    while (currentAncestor) {
        elementsToMakeVisible.add(currentAncestor);
        // Ancestors are not added to elementsToFocus unless they are the focusedNote itself (already handled)
        currentAncestor = currentAncestor.parentElement.closest('.note-item');
    }

    // Apply Classes to All Notes
    const allNoteElements = document.querySelectorAll('#notes-container .note-item');
    allNoteElements.forEach(noteElement => {
        if (elementsToMakeVisible.has(noteElement)) {
            noteElement.classList.remove('note-hidden');
        } else {
            noteElement.classList.add('note-hidden');
        }

        if (elementsToFocus.has(noteElement)) {
            noteElement.classList.add('note-focused');
        } else {
            noteElement.classList.remove('note-focused');
        }
    });

    // Maintain Existing Logic for showAllBtn and breadcrumbs
    notesContainer.classList.add('has-focused-notes');
    
    const existingBtn = notesContainer.querySelector('.show-all-notes-btn');
    if (existingBtn) {
        existingBtn.remove();
    }
    
    const showAllBtn = document.createElement('button');
    showAllBtn.className = 'show-all-notes-btn';
    showAllBtn.textContent = '← Show All Notes';
    showAllBtn.addEventListener('click', showAllNotes);
    notesContainer.insertBefore(showAllBtn, notesContainer.firstChild);

    if (window.notesForCurrentPage && window.currentPageName) {
        renderBreadcrumbs(noteId, window.notesForCurrentPage, window.currentPageName);
    } else {
        console.warn("Cannot render breadcrumbs: notesForCurrentPage or currentPageName is missing.");
        if (domRefs.breadcrumbsContainer) domRefs.breadcrumbsContainer.innerHTML = '';
    }
}

/**
 * Shows all notes and clears focus state
 */
function showAllNotes() {
    window.currentFocusedNoteId = null; // Clear current focused note ID
    const allNotes = document.querySelectorAll('.note-item');
    const notesContainer = document.getElementById('notes-container');
    
    allNotes.forEach(note => {
        note.classList.remove('note-hidden');
        note.classList.remove('note-focused');
    });
    
    notesContainer.classList.remove('has-focused-notes');
    
    // Remove show all button
    const showAllBtn = notesContainer.querySelector('.show-all-notes-btn');
    if (showAllBtn) {
        showAllBtn.remove();
    }

    // Clear breadcrumbs when showing all notes
    if (domRefs.breadcrumbsContainer) {
        domRefs.breadcrumbsContainer.innerHTML = '';
    }
    // Alternatively, call renderBreadcrumbs with null to ensure consistent handling
    // renderBreadcrumbs(null, window.notesForCurrentPage, window.currentPageName);
}