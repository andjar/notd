/**
 * Main application module for NotTD
 * Handles state management, event handling, and coordination between UI and API
 * @module app
 */

// Core state management
import {
    setCurrentPageId,
    setCurrentPageName,
    setSaveStatus,
    setNotesForCurrentPage
} from './app/state.js';

// Page management
import { loadPage, getInitialPage } from './app/page-loader.js';

// UI and event handling
import { sidebarState } from './app/sidebar.js';
import { initGlobalEventListeners } from './app/event-handlers.js';
import { safeAddEventListener } from './utils.js';
import { ui } from './ui.js';

// Note actions
import {
    handleAddRootNote,
    handleNoteKeyDown,
    handleTaskCheckboxClick,
    debouncedSaveNote
} from './app/note-actions.js';

// Import API clients for global exposure if needed
import { notesAPI, propertiesAPI, templatesAPI, pagesAPI } from './api_client.js';

// Import app initialization
import { initializeApp } from './app/app-init.js';

// Make some APIs globally accessible for debugging or legacy access
window.notesAPI = notesAPI;
window.propertiesAPI = propertiesAPI;
window.templatesAPI = templatesAPI;
window.pagesAPI = pagesAPI;
window.loadPage = loadPage; // Expose for direct calls if necessary

// Get DOM references from UI module
const {
    notesContainer,
    addRootNoteBtn,
} = ui.domRefs;

// Verify critical DOM elements
if (!notesContainer) {
    console.error('Critical DOM element missing: notesContainer. App cannot function.');
}

// --- Event Handlers ---

// Add root note
safeAddEventListener(addRootNoteBtn, 'click', handleAddRootNote, 'addRootNoteBtn');

// Note-level event delegation
if (notesContainer) {
    // Keyboard navigation and editing
    notesContainer.addEventListener('keydown', handleNoteKeyDown);

    // Click interactions (e.g., task markers)
    notesContainer.addEventListener('click', (e) => {
        if (e.target.matches('.task-checkbox')) {
            handleTaskCheckboxClick(e);
        }
    });

    // Input event for debounced save.
    // This listener handles live typing in any note's content area.
    safeAddEventListener(notesContainer, 'input', (e) => {
        if (e.target.matches('.note-content.edit-mode')) {
            const noteItem = e.target.closest('.note-item');
            if (noteItem) {
                const contentDiv = e.target;
                // Update the rawContent dataset immediately on input.
                // This dataset is the source of truth for debounced saves.
                const rawTextValue = ui.getRawTextWithNewlines(contentDiv);
                const normalizedContent = ui.normalizeNewlines(rawTextValue);
                contentDiv.dataset.rawContent = normalizedContent;
                // Trigger the debounced save function.
                debouncedSaveNote(noteItem);
            }
        }
    }, 'notesContainerInput');
}

// --- Application Startup ---

document.addEventListener('DOMContentLoaded', async () => {
    // Ensure UI module is loaded and critical elements are present
    if (typeof ui === 'undefined' || !notesContainer) {
        console.error('UI module or critical DOM elements not loaded. Application cannot start.');
        document.body.innerHTML = '<h1>Application failed to start. Please check the console.</h1>';
        return;
    }
    
    try {
        await initializeApp();
    } catch (error) {
        console.error('Failed to initialize application:', error);
        document.body.innerHTML = `<h1>Application Initialization Failed</h1><p>${error.message}</p><p>Check the console for more details.</p>`;
    }
});