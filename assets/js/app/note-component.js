import { saveNoteImmediately } from '../app/note-actions.js';
import { getRawTextWithNewlines, normalizeNewlines } from '../ui/note-renderer.js';

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
            this.$watch('isEditing', (value) => {
                if (value) {
                    this.switchToEditMode();
                } else {
                    this.switchToRenderedMode();
                }
            });
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

            let textToEdit = this.note.content || '';

            this.contentEl.classList.remove('rendered-mode');
            this.contentEl.classList.add('edit-mode');
            this.contentEl.contentEditable = true;
            this.contentEl.style.whiteSpace = 'pre-wrap';
            this.contentEl.innerHTML = ''; // Clear HTML to set textContent
            this.contentEl.textContent = textToEdit;
            this.contentEl.focus();

            // Re-attach event listeners for input, paste, etc. if needed
            // For now, relying on delegated events or Alpine's x-on
        },

        switchToRenderedMode() {
            if (!this.contentEl) return;

            const rawTextValue = getRawTextWithNewlines(this.contentEl);
            const newContent = normalizeNewlines(rawTextValue);
            
            this.note.content = newContent; // Update the note data
            
            this.contentEl.classList.remove('edit-mode');
            this.contentEl.classList.add('rendered-mode');
            this.contentEl.contentEditable = false;
            this.contentEl.style.whiteSpace = '';

            // Re-render content via Alpine's x-html
            // This will happen automatically when this.note.content changes

            // Save the note
            saveNoteImmediately(this.note);

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
        }
    }
}
