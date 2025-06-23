document.addEventListener('alpine:init', () => {
    Alpine.store('app', {
        // State from state.js
        currentPageId: null,
        currentPageName: '',
        notes: [], // Will hold the tree of notes for the current page
        saveStatus: 'saved', // 'saved', 'pending', 'error'

        // UI State (from sidebar.js and general app state)
        isLeftSidebarCollapsed: Alpine.$persist(false).as('leftSidebarCollapsed'),
        isRightSidebarCollapsed: Alpine.$persist(true).as('rightSidebarCollapsed'),
        isSearchModalOpen: false,
        isPropertiesModalOpen: false,
        recentPages: [], // For recent pages list in sidebar

        // Methods to interact with state
        setNotes(notesData) {
            this.notes = this.buildNoteTree(notesData);
        },

        buildNoteTree(notes, parentId = null) {
            if (!notes) return [];
            return notes
                .filter(note => (note.parent_note_id || null) == parentId)
                .sort((a, b) => (a.order_index || 0) - (b.order_index || 0))
                .map(note => ({
                    ...note,
                    children: this.buildNoteTree(notes, note.id)
                }));
        },

        // Placeholder for methods that will be moved from sidebar.js or other places
        // Example: toggleLeftSidebar, toggleRightSidebar, etc.
        // These will be fleshed out in later phases or as part of root component.
    });
});
