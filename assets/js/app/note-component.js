import { saveNoteImmediately, handleNoteKeyDown as globalHandleNoteKeyDown, debouncedSaveNote } from '../app/note-actions.js';
import { getRawTextWithNewlines, normalizeNewlines } from '../ui/note-renderer.js'; // Keep for text processing
// switchToEditMode and switchToRenderedMode are being replaced by Alpine logic

/**
 * Alpine.js component for rendering and managing a single note.
 * @param {Object} initialNote - The note data object.
 * @param {number} nestingLevel - The nesting level of the note.
 */
export default function noteComponent(initialNote, nestingLevel = 0) {
    return {
        note: initialNote, // Contains id, content, children, collapsed, is_encrypted, attachments etc.
        nestingLevel: nestingLevel,
        isEditing: false,
        _lastRenderedContent: null, // Cache for raw content
        _cachedHtmlContent: '', // Cache for parsed HTML

        // contentEl and renderedContentEl will be assigned via x-ref

        // Reactive getter for parsed HTML content with caching
        get renderedHtmlContent() {
            if (this.note.is_encrypted && typeof this.note.content === 'string' && this.note.content.startsWith('{')) {
                // Placeholder for encrypted content if not yet decrypted by page load logic
                return "[Encrypted]";
            }
            // Check cache
            if (this.note.content === this._lastRenderedContent) {
                return this._cachedHtmlContent;
            }

            // Not cached or content changed, re-parse
            this._cachedHtmlContent = window.parseAndRenderContent(this.note.content || '');
            this._lastRenderedContent = this.note.content;
            return this._cachedHtmlContent;
        },

        initializeEditor() {
            this.$watch('isEditing', (isNowEditing) => {
                if (isNowEditing) {
                    this.$nextTick(() => {
                        if (this.$refs.contentDiv) {
                            this.$refs.contentDiv.textContent = this.note.content; // Set raw text for editing
                            this.$refs.contentDiv.focus();
                            // Move cursor to end
                            const range = document.createRange();
                            const sel = window.getSelection();
                            range.selectNodeContents(this.$refs.contentDiv);
                            range.collapse(false);
                            sel.removeAllRanges();
                            sel.addRange(range);
                        }
                    });
                } else {
                    // When isEditing becomes false, content is already saved by handleBlur
                    // and renderedHtmlContent will update the view.
            this.cleanupSuggestionListeners(); // Remove suggestion listeners
                    this.$nextTick(() => {
                        if (typeof feather !== 'undefined' && feather.replace) {
                            feather.replace();
                        }
                    });
                }
            });
            this.$nextTick(() => { // Initial feather render
                 if (typeof feather !== 'undefined' && feather.replace) {
                    feather.replace();
                }
            });
        },

// --- Suggestion Box Specific Data & Methods ---
suggestionBoxVisible: false,
suggestionQueryInfo: null,

setupSuggestionListeners() {
    if (!this.$refs.contentDiv) return;
    // Assuming getLinkQueryInfo, showSuggestions, hideSuggestions, etc. are globally available
    // or imported and attached to `window` or `this` if they were class methods.
    // For simplicity, assuming global `window.getLinkQueryInfo` etc.

    // The 'input' and 'keydown' events are already handled by handleInput and handleNoteKeyDown.
    // We need to call the suggestion logic from within those handlers.

    // Listener for clicks on suggestions (if suggestion box emits such an event)
    // This was originally on suggestionBoxElement. If that's a single global element,
    // this listener might be better managed globally or when the suggestion box is shown.
    // For now, let's assume it's handled by the suggestion module itself when a selection is made.
},

cleanupSuggestionListeners() {
    // If specific listeners were added here, remove them.
    // Since we are integrating into existing @input/@keydown, this might be minimal.
    if (this.suggestionBoxVisible) {
        window.hideSuggestions && window.hideSuggestions();
        this.suggestionBoxVisible = false;
    }
},

// --- End Suggestion Box ---

        init() {
            // Ensure attachments is an array
            if (!this.note.attachments) {
                this.note.attachments = [];
            }
             // Decrypt content if necessary (basic check, real decryption in page-loader)
            if (this.note.is_encrypted && typeof this.note.content === 'string' && this.note.content.startsWith('{')) {
                const password = Alpine.store('app').pagePassword;
                if (password) {
                    try {
                        const decrypted = window.decrypt(password, this.note.content);
                        if (decrypted !== null) {
                            this.note.content = decrypted;
                        } else {
                            console.warn(`Decryption failed for note ${this.note.id} in component`);
                            // this.note.content remains the encrypted placeholder or an error message
                        }
                    } catch (e) {
                         console.warn(`Decryption error for note ${this.note.id} in component: ${e.message}`);
                    }
                }
            }
        },

        toggleCollapse() {
            this.note.collapsed = !this.note.collapsed;
            // Persist collapse state (example, actual API call might differ)
            if (window.notesAPI && window.notesAPI.batchUpdateNotes) {
                window.notesAPI.batchUpdateNotes([{
                    type: 'update',
                    payload: { id: this.note.id, collapsed: this.note.collapsed ? 1 : 0 }
                }]).catch(err => console.error('Failed to save collapse state:', err));
            }
            this.$nextTick(() => { // Update feather icons if any were revealed/hidden
                if (typeof feather !== 'undefined' && feather.replace) {
                    feather.replace();
                }
            });
        },

        editNote() {
            if (this.isEditing) return;
            this.isEditing = true; // Triggers $watch in initializeEditor
        },

        handleBlur() {
            // Add a small delay to allow other click events (e.g., suggestion box) to fire
            setTimeout(() => {
                if (!this.isEditing) return; // Already exited edit mode

                const newContent = normalizeNewlines(getRawTextWithNewlines(this.$refs.contentDiv));

                if (this.note.content !== newContent) {
                    this.note.content = newContent;
                    // Save the note (either immediate or debounced)
                    // The main app.js has a debouncedSaveNote on global input for '.note-content.edit-mode'
                    // We can call saveNoteImmediately here if direct save is preferred on blur
                    saveNoteImmediately(this.$el); // this.$el refers to the root element of this noteComponent
                }
                this.isEditing = false;
            }, 150); // 150ms delay, adjust as needed
        },

        handleInput(event) {
            // Update note content reactively for debounced save
            const currentRawText = getRawTextWithNewlines(event.target);
            this.note.content = currentRawText;
            debouncedSaveNote(this.$el);

            // --- Suggestion Logic (from old switchToEditMode) ---
            if (window.getLinkQueryInfo && window.showSuggestions && window.hideSuggestions) {
                this.suggestionQueryInfo = window.getLinkQueryInfo(this.$refs.contentDiv);
                if (this.suggestionQueryInfo) {
                    window.showSuggestions(this.suggestionQueryInfo.query, this.suggestionQueryInfo.triggerPosition);
                    this.suggestionBoxVisible = true;
                } else {
                    window.hideSuggestions();
                    this.suggestionBoxVisible = false;
                }
            }
            // --- End Suggestion Logic ---
        },

        handlePaste(event) {
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
            // Content update and save will be handled by input event, which also triggers suggestion logic
        },

        handleNoteKeyDown(event) {
            // --- Suggestion Logic (from old switchToEditMode) ---
            if (this.suggestionBoxVisible && window.navigateSuggestions && window.getSelectedSuggestion && window.hideSuggestions) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    window.navigateSuggestions(event.key);
                    return; // Prevent further keydown processing by globalHandleNoteKeyDown
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    const selectedPageName = window.getSelectedSuggestion();
                    if (selectedPageName) {
                        this.insertPageLink(selectedPageName); // Implement this method
                    } else {
                        window.hideSuggestions();
                        this.suggestionBoxVisible = false;
                    }
                    return; // Prevent further keydown processing
                } else if (event.key === 'Escape' || event.key === 'Tab') {
                    event.preventDefault();
                    window.hideSuggestions();
                    this.suggestionBoxVisible = false;
                    return; // Prevent further keydown processing
                }
            }
            // --- End Suggestion Logic ---

            // Call the global handler for other keys (Enter for new note, Tab for indent, etc.)
            globalHandleNoteKeyDown(event, this.$el);
        },

        insertPageLink(selectedPageName) {
            // This is a simplified version of insertSelectedPageLink from note-renderer.js
            // A more robust solution would use the DOM manipulation utilities from the original function.
            if (!this.suggestionQueryInfo || !this.$refs.contentDiv) {
                this.cleanupSuggestionListeners();
                return;
            }

            const contentEl = this.$refs.contentDiv;
            const fullText = contentEl.textContent;
            const before = fullText.substring(0, this.suggestionQueryInfo.replaceStartOffset);
            const after = fullText.substring(this.suggestionQueryInfo.replaceEndOffset);
            const newText = `${before}[[${selectedPageName}]]${after}`;
            
            contentEl.textContent = newText;
            this.note.content = newText; // Update model
            
            // Position cursor after the inserted link - this is tricky with textContent.
            // For simplicity, we'll just focus and let the user click. A full DOM solution is better.
            contentEl.focus();

            // Manually trigger input event to save and update suggestions
            const inputEvent = new Event('input', { bubbles: true, cancelable: true });
            contentEl.dispatchEvent(inputEvent);

            this.cleanupSuggestionListeners();
        },

        // --- Attachment Methods ---
        async deleteAttachment(attachmentId) {
            if (!confirm('Are you sure you want to delete this attachment?')) return;
            try {
                await window.attachmentsAPI.deleteAttachment(attachmentId, this.note.id);
                this.note.attachments = this.note.attachments.filter(att => att.id !== attachmentId);
                // Update has_attachments flag on the note in the store if necessary
                const storeNote = Alpine.store('app').notes.find(n => n.id === this.note.id);
                if (storeNote) {
                    storeNote.has_attachments = this.note.attachments.length > 0;
                }
            } catch (error) {
                console.error('Failed to delete attachment:', error);
                alert('Error deleting attachment: ' + error.message);
            }
        },

        viewAttachment(attachment) {
            if (attachment.type && attachment.type.startsWith('image/')) {
                // Assuming a global function or component method to open an image viewer
                if (window.openImageViewerModal) {
                     window.openImageViewerModal(attachment.url);
                } else {
                    window.open(attachment.url, '_blank'); // Fallback
                }
            } else {
                this.openAttachment(attachment);
            }
        },

        openAttachment(attachment) {
             window.open(attachment.url, '_blank');
        },

        handleBulletClick() {
            if (window.ui && typeof window.ui.focusOnNote === 'function') {
                window.ui.focusOnNote(this.note.id);
            } else if (typeof focusOnNote === 'function') { // Fallback if global focusOnNote exists
                focusOnNote(this.note.id);
            } else {
                console.warn('focusOnNote function not available.');
            }
        },

        handleBulletContextMenu(event) {
            event.preventDefault(); // Already done by .prevent modifier
            // Remove any existing context menus
            document.querySelectorAll('.bullet-context-menu').forEach(menu => menu.remove());

            const menu = document.createElement('div');
            menu.className = 'bullet-context-menu';
            // Basic menu items - this should be enhanced to match original functionality
            menu.innerHTML = `
                <div class="menu-item" data-action="copy-transclusion">Copy transclusion</div>
                <div class="menu-item" data-action="copy-anchor">Copy anchor link</div>
                <div class="menu-item" data-action="delete-note">Delete note</div>
            `;
            menu.style.position = 'fixed';
            menu.style.left = `${event.pageX}px`;
            menu.style.top = `${event.pageY}px`;
            menu.style.zIndex = '1000'; // Ensure it's on top

            const handleMenuAction = async (actionEvent) => {
                const action = actionEvent.target.dataset.action;
                if (!action) return;

                menu.remove();
                document.removeEventListener('click', closeMenuOnClickOutside);

                switch (action) {
                    case 'copy-transclusion':
                        const transclusionLink = `!{{${this.note.id}}}`;
                        await navigator.clipboard.writeText(transclusionLink);
                        // TODO: Show feedback
                        break;
                    case 'copy-anchor':
                        const urlParams = new URLSearchParams(window.location.search);
                        const currentPage = urlParams.get('page') || 'default'; // Or get from store
                        const anchorLink = `${window.location.origin}${window.location.pathname}?page=${encodeURIComponent(currentPage)}#note-${this.note.id}`;
                        await navigator.clipboard.writeText(anchorLink);
                        // TODO: Show feedback
                        break;
                    case 'delete-note':
                        if (confirm(`Are you sure you want to delete note ${this.note.id}?`)) {
                            try {
                                await window.notesAPI.deleteNote(this.note.id);
                                // Remove from store - this should trigger Alpine to update UI
                                Alpine.store('app').removeNoteById(this.note.id);
                                // If store holds a tree, removeNoteById needs to handle tree mutation or full rebuild.
                                // For now, assume removeNoteById updates the store correctly.
                                // A safer but heavier approach is to call refreshNotesStoreAndReloadView()
                            } catch (error) {
                                console.error(`Error deleting note ${this.note.id}:`, error);
                                alert(`Failed to delete note: ${error.message}`);
                            }
                        }
                        break;
                }
            };

            const closeMenuOnClickOutside = (clickEvent) => {
                if (!menu.contains(clickEvent.target)) {
                    menu.remove();
                    document.removeEventListener('click', closeMenuOnClickOutside);
                }
            };

            menu.addEventListener('click', handleMenuAction);
            // Add timeout to allow current event loop to finish before attaching,
            // preventing immediate close if contextmenu was via click.
            setTimeout(() => {
                document.addEventListener('click', closeMenuOnClickOutside);
            }, 0);

            document.body.appendChild(menu);
            if (typeof feather !== 'undefined') feather.replace(); // For icons in menu if any
        }
    }
}
