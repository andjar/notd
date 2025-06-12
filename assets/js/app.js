// FILE: assets/js/app.js (Final Version)

import { currentPageId } from './app/state.js';
import { fetchAndProcessPageData } from './app/page-loader.js';
import { initializeApp } from './app/app-init.js';
import { ui } from './ui.js';
import { notesAPI, searchAPI } from './api_client.js';
// *** FIX: Import all required actions from note-actions ***
import { handleAddRootNote, debouncedSaveNote, saveNoteImmediately, handleCreateAndFocusFirstNote } from './app/note-actions.js';

// Make key functions globally accessible for event handlers and inline JS
window.loadPage = loadPage;
window.ui = ui;

/**
 * Main function to load a page and update the entire UI.
 */
async function loadPage(pageName, updateHistory = true) {
    if (window.blockPageLoad) { console.warn('Page load blocked.'); return; }
    window.blockPageLoad = true;

    ui.updatePageTitle(`Loading ${pageName}...`);
    if (ui.domRefs.notesContainer) ui.domRefs.notesContainer.innerHTML = '<p>Loading page...</p>';
    
    try {
        const pageData = await fetchAndProcessPageData(pageName);
        if (!pageData) throw new Error("Received no data from page loader.");

        if (updateHistory) {
            history.pushState({ pageName: pageData.name }, '', `?page=${encodeURIComponent(pageData.name)}`);
        }

        ui.updatePageTitle(pageData.name);
        ui.renderPageTitle(pageData.name);
        ui.updateActivePageLink(pageData.name);
        if (ui.calendarWidget) ui.calendarWidget.setCurrentPage(pageData.name);

        ui.displayNotes(pageData.notes, pageData.id);
        
        if (pageData.notes.length === 0) {
            // *** FIX: Use imported function ***
            await handleCreateAndFocusFirstNote(pageData.id);
        }

        const backlinks = await searchAPI.getBacklinks(pageData.name);
        ui.renderBacklinks(backlinks);

    } catch (error) {
        // ... error handling ...
    } finally {
        window.blockPageLoad = false;
    }
}

// --- Centralized Event Listeners ---
if (ui.domRefs.notesContainer) {
    // Listener for debounced save on text input
    ui.domRefs.notesContainer.addEventListener('input', (e) => {
        if (e.target.matches('.note-content.edit-mode')) {
            const noteItem = e.target.closest('.note-item');
            if (noteItem) {
                e.target.dataset.rawContent = ui.getRawTextWithNewlines(e.target);
                debouncedSaveNote(noteItem);
            }
        }
    });

    // Listener for blur event to save and switch to rendered mode
    ui.domRefs.notesContainer.addEventListener('note-blur', async (e) => {
        const contentDiv = e.target;
        const noteItem = contentDiv.closest('.note-item');
        if (noteItem) {
            await saveNoteImmediately(noteItem);
            ui.switchToRenderedMode(contentDiv);
        }
    });
}

// Add root note button
if (ui.domRefs.addRootNoteBtn) {
    ui.domRefs.addRootNoteBtn.addEventListener('click', handleAddRootNote);
}

// Initialize the application
document.addEventListener('DOMContentLoaded', initializeApp);