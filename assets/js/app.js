/**
 * Main application module for NotTD
 * Handles state management, event handling, and coordination between UI and API
 * @module app
 */

// Core state management - not directly used here but good to see imports
import { currentPageId } from './app/state.js';

// Page management
import { loadPage } from './app/page-loader.js';
window.loadPage = loadPage; 

// UI and event handling
import { ui } from './ui.js';
import { safeAddEventListener } from './utils.js';

// Note actions
import {
    handleAddRootNote,
    handleNoteKeyDown,
    handleTaskCheckboxClick,
    debouncedSaveNote
} from './app/note-actions.js';

// API clients for global exposure if needed
import { notesAPI, propertiesAPI, attachmentsAPI, searchAPI, pagesAPI, templatesAPI } from './api_client.js';
window.notesAPI = notesAPI;
window.propertiesAPI = propertiesAPI;
window.attachmentsAPI = attachmentsAPI;
window.searchAPI = searchAPI;
window.pagesAPI = pagesAPI;
window.templatesAPI = templatesAPI;

// Property editor functions
import { 
    displayPageProperties as displayPagePropertiesFromEditor, 
    initPropertyEditor
} from './app/property-editor.js';

// App initialization
import { initializeApp } from './app/app-init.js';

// --- Global Function Exposure ---
window.displayPageProperties = displayPagePropertiesFromEditor;

// --- Event Handlers Setup ---
const { notesContainer, addRootNoteBtn } = ui.domRefs;

if (!notesContainer) {
    console.error('Critical DOM element missing: notesContainer. App cannot function.');
}

// Add root note button
safeAddEventListener(addRootNoteBtn, 'click', handleAddRootNote, 'addRootNoteBtn');

// **FIXED**: This is where note-level event listeners should be attached.
if (notesContainer) {
    // Keydown for structural changes (Enter, Tab, Backspace) and navigation (Arrows)
    notesContainer.addEventListener('keydown', handleNoteKeyDown);

    // Click handler for task checkboxes
    notesContainer.addEventListener('click', (e) => {
        if (e.target.matches('.task-checkbox')) {
            handleTaskCheckboxClick(e);
        }
    });

    // Input handler for debounced saving of content
    notesContainer.addEventListener('input', (e) => {
        if (e.target.matches('.note-content.edit-mode')) {
            const noteItem = e.target.closest('.note-item');
            if (noteItem) {
                const contentDiv = e.target;
                contentDiv.dataset.rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
                debouncedSaveNote(noteItem);
            }
        }
    });
}

// --- Drag and Drop for File Uploads ---
if (notesContainer) {
    notesContainer.addEventListener('dragover', (e) => {
        e.preventDefault();
        const noteWrapper = e.target.closest('.note-content-wrapper');
        if (noteWrapper) {
            noteWrapper.classList.add('dragover');
        }
    });

    notesContainer.addEventListener('dragleave', (e) => {
        const noteWrapper = e.target.closest('.note-content-wrapper');
        if (noteWrapper) {
            noteWrapper.classList.remove('dragover');
        }
    });

    notesContainer.addEventListener('drop', async (e) => {
        e.preventDefault();
        const noteWrapper = e.target.closest('.note-content-wrapper');
        if (!noteWrapper) return;
        
        noteWrapper.classList.remove('dragover');
        const noteItem = noteWrapper.closest('.note-item');
        const noteId = noteItem?.dataset.noteId;

        if (!noteId || noteId.startsWith('temp-')) {
            alert('Please save the note before adding attachments.');
            return;
        }

        const files = Array.from(e.dataTransfer.files);
        if (files.length === 0) return;

        ui.updateSaveStatusIndicator('pending');
        try {
            for (const file of files) {
                const formData = new FormData();
                formData.append('attachmentFile', file);
                formData.append('note_id', noteId);
                await attachmentsAPI.uploadAttachment(formData);
            }
            // Refresh attachments for the specific note instead of reloading the whole page
            const attachmentsContainer = noteItem.querySelector('.note-attachments');
            if(attachmentsContainer) {
                 await ui.renderAttachments(attachmentsContainer, noteId, true);
            }
            ui.updateSaveStatusIndicator('saved');
        } catch (error) {
            console.error('Error uploading file(s) via drag and drop:', error);
            alert('File upload failed: ' + error.message);
            ui.updateSaveStatusIndicator('error');
        }
    });
}

// --- Application Startup ---
document.addEventListener('DOMContentLoaded', async () => {
    if (typeof ui === 'undefined' || !notesContainer) {
        console.error('UI module or critical DOM elements not loaded. Application cannot start.');
        document.body.innerHTML = '<h1>Application failed to start. Please check the console.</h1>';
        return;
    }
    
    try {
        await initializeApp();
        initPropertyEditor(); // Initialize listeners for the property modal
        ui.initializeDelegatedNoteEventListeners(notesContainer); // **FIXED**: Call the main event initializer
        ui.calendarWidget.init(); // Initialize the calendar widget
    } catch (error) {
        console.error('Failed to initialize application:', error);
        document.body.innerHTML = `<h1>Application Initialization Failed</h1><p>${error.message}</p><p>Check the console for more details.</p>`;
    }
});
