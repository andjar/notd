// This file now simply exports a function that returns the store's configuration.
// It no longer has its own event listener.

export function defineStore() {
    Alpine.store('app', {
        // Core page state
        currentPageId: null,
        currentPageName: '',
        notes: [], // Will hold the tree of notes for the current page
        recentPages: [],

        // UI State
        // Alpine and its plugins are guaranteed to be available when this function is called.
        isLeftSidebarCollapsed: Alpine.$persist(false).as('leftSidebarCollapsed'),
        isRightSidebarCollapsed: Alpine.$persist(true).as('rightSidebarCollapsed'),
        isSearchModalOpen: false,
        isPropertiesModalOpen: false,
        
        // Save status
        saveStatus: 'saved', // 'saved', 'pending', 'error'

        // Method to process flat notes array into a tree for rendering
        setNotes(notesData) {
            this.notes = this.buildNoteTree(notesData || []);
        },

        buildNoteTree(notes, parentId = null) {
            return notes
                .filter(note => (note.parent_note_id || null) == parentId)
                .sort((a, b) => (a.order_index || 0) - (b.order_index || 0))
                .map(note => ({
                    ...note,
                    children: this.buildNoteTree(notes, note.id)
                }));
        },
    });
}