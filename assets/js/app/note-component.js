import { saveNoteImmediately, handleNoteKeyDown } from '../app/note-actions.js';
import { getRawTextWithNewlines, normalizeNewlines, switchToEditMode, switchToRenderedMode } from '../ui/note-renderer.js';

/**
 * Alpine.js component for rendering and managing a single note.
 * @param {Object} initialNote - The note data object.
 * @param {number} nestingLevel - The nesting level of the note.
 */
export default function noteComponent(initialNote, nestingLevel = 0) {
    return {
        note: initialNote,
        nestingLevel: nestingLevel,
        isEditing: false,
        contentEl: null, // Reference to the content editable div

        // Expose parseAndRenderContent for x-html
        parseContent(content) {
            return window.parseAndRenderContent(content);
        },

        init() {
            this.contentEl = this.$refs.contentDiv;
            
            // Watch for changes in the note content to trigger reactivity
            this.$watch('note.content', (newContent) => {
                if (this.contentEl && !this.isEditing) {
                    // Update the rendered content when note changes
                    this.contentEl.innerHTML = this.parseContent(newContent);
                }
            });
            
            this.$watch('isEditing', (value) => {
                if (value) {
                    this.switchToEditMode();
                } else {
                    this.switchToRenderedMode();
                }
            });
            
            // Auto-focus new notes that are empty and have temporary IDs
            if (this.note.content === '' && String(this.note.id).startsWith('temp-')) {
                this.$nextTick(() => {
                    this.editNote();
                });
            }
            
            this.$nextTick(() => {
                if (window.FeatherManager) {
                    window.FeatherManager.requestUpdate();
                } else {
                    try {
                        if (typeof feather !== 'undefined' && feather.replace) {
                            feather.replace();
                        }
                    } catch (error) {
                        console.warn('Feather icon replacement failed in note component:', error.message);
                    }
                }
            });
        },

        toggleCollapse() {
            this.note.collapsed = !this.note.collapsed;
            // TODO: Call API to save collapse state
            console.log(`Toggling collapse for note ${this.note.id}, new state: ${this.note.collapsed}`);
        },

        editNote() {
            if (this.isEditing) return;
            this.isEditing = true;
        },

        switchToEditMode() {
            if (!this.contentEl) return;
            // Use the UI module's switchToEditMode function
            switchToEditMode(this.contentEl);
        },

        switchToRenderedMode() {
            if (!this.contentEl) return;

            const rawTextValue = getRawTextWithNewlines(this.contentEl);
            const newContent = normalizeNewlines(rawTextValue);
            
            this.note.content = newContent; // Update the note data
            
            // Use the UI module's switchToRenderedMode function
            switchToRenderedMode(this.contentEl);

            // Save the note - find the note element and save it
            const noteElement = this.contentEl.closest('.note-item');
            if (noteElement) {
                saveNoteImmediately(noteElement);
            }

            this.isEditing = false;
        },

        handleInput(event) {
            // Update note content as user types
            this.note.content = getRawTextWithNewlines(event.target);
            // Debounced save will be handled by a global listener or a specific Alpine plugin
        },

        handlePaste(event) {
            // This logic needs to be fully migrated or handled by a global listener
            // For now, just prevent default to avoid unformatted paste
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text');
            document.execCommand('insertText', false, text);
        },

        handleNoteKeyDown(event) {
            // Call the imported handleNoteKeyDown function
            return handleNoteKeyDown(event);
        }
    }
}
