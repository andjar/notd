/**
 * @file Main application entry point. Initializes the app and sets up global event listeners.
 * @module app
 */

// Alpine.js will be loaded from CDN and available as global Alpine
import noteComponent from './app/note-component.js';
import { splashScreen } from './app/splash-screen.js';
import sidebarComponent from './app/sidebar-component.js';
import calendarComponent from './app/calendar-component.js';

// Wait for Alpine to be available
document.addEventListener('alpine:init', () => {

// Alpine.js directive for feather icons
Alpine.directive('feather', (el, { expression }, { evaluate, effect }) => {
    effect(() => {
        const iconName = evaluate(expression);
        
        if (typeof feather !== 'undefined' && feather.icons && iconName && feather.icons[iconName]) {
            try {
                const svgString = feather.icons[iconName].toSvg();
                const parser = new DOMParser();
                const svgDoc = parser.parseFromString(svgString, 'image/svg+xml');
                const svgElement = svgDoc.querySelector('svg');
                
                if (svgElement) {
                    // Copy classes and attributes from the original element
                    Array.from(el.attributes).forEach(attr => {
                        if (!attr.name.startsWith('x-') && !attr.name.startsWith(':') && attr.name !== 'data-feather') {
                            svgElement.setAttribute(attr.name, attr.value);
                        }
                    });
                    
                    // Replace the element content
                    el.innerHTML = svgElement.outerHTML;
                }
            } catch (error) {
                console.warn('Error rendering feather icon:', iconName, error);
            }
        }
    });
});

Alpine.store('app', {
  // Core state variables
  currentPageId: null,
  currentPageName: null,
  saveStatus: 'saved', // valid: saved, saving, error
  pageCache: new Map(),
  notes: [],
  focusedNoteId: null,
  pagePassword: null,
  
  // Additional state variables from state.js
  lastResponse: null,
  activeRequests: 0,
  
  // Constants
  CACHE_MAX_AGE_MS: 5 * 60 * 1000, // 5 minutes
  MAX_PREFETCH_PAGES: 3,
  
  // Helper methods for state management
  setCurrentPageId(newId) {
    this.currentPageId = newId;
    window.currentPageId = newId; // Keep window object in sync for debugging
  },
  
  setCurrentPageName(newName) {
    this.currentPageName = newName;
    window.currentPageName = newName;
  },
  
  setSaveStatus(newStatus) {
    this.saveStatus = newStatus;
    window.saveStatus = newStatus;
  },
  
  setPagePassword(newPassword) {
    this.pagePassword = newPassword;
    window.currentPagePassword = newPassword;
  },
  
  setNotes(newNotes) {
    this.notes = newNotes;
    window.notesForCurrentPage = newNotes;
  },
  
  addNote(note) {
    this.notes.push(note);
    this.notes.sort((a, b) => a.order_index - b.order_index);
    window.notesForCurrentPage = this.notes;
  },
  
  removeNoteById(noteId) {
    const idx = this.notes.findIndex(n => String(n.id) === String(noteId));
    if (idx > -1) this.notes.splice(idx, 1);
    window.notesForCurrentPage = this.notes;
  },
  
  updateNote(updatedNote) {
    const idx = this.notes.findIndex(n => String(n.id) === String(updatedNote.id));
    if (idx > -1) {
      this.notes[idx] = { ...this.notes[idx], ...updatedNote };
    } else {
      this.notes.push(updatedNote);
    }
    window.notesForCurrentPage = this.notes;
  },
  
  setFocusedNoteId(newNoteId) {
    this.focusedNoteId = newNoteId;
    window.currentFocusedNoteId = newNoteId;
  },
  
  // Page cache management
  setPageCache(key, value) {
    this.pageCache.set(key, value);
  },
  
  getPageCache(key) {
    return this.pageCache.get(key);
  },
  
  hasPageCache(key) {
    return this.pageCache.has(key);
  },
  
  deletePageCache(key) {
    return this.pageCache.delete(key);
  },
  
  clearPageCache() {
    this.pageCache.clear();
  }
});
    Alpine.data('noteComponent', noteComponent);
    Alpine.data('splashScreen', splashScreen);
    Alpine.data('sidebarComponent', sidebarComponent);
    Alpine.data('calendarComponent', calendarComponent);
});

// Alpine.start() is automatically called by the CDN version

// Page management
import { loadPage, refreshNotesStoreAndReloadView } from './app/page-loader.js';
window.loadPage = loadPage;
window.refreshNotesStoreAndReloadView = refreshNotesStoreAndReloadView;

// UI and event handling
import { ui } from './ui.js';
import { safeAddEventListener } from './utils.js';

// **ENHANCEMENT**: Import parseAndRenderContent for global access
import { parseAndRenderContent } from './ui/note-renderer.js';

// Note actions
import {
    handleAddRootNote,
    handleNoteKeyDown,
    debouncedSaveNote
} from './app/note-actions.js';

// Template handling
import { initializeTemplateHandling } from './app/template-handler.js';

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
import { initGlobalSearch, initPageSearchModal, initNoteSearchModal } from './app/search.js';

// --- Global Function Exposure ---
window.displayPageProperties = displayPagePropertiesFromEditor;
window.parseAndRenderContent = parseAndRenderContent; // **ENHANCEMENT**: Expose markdown rendering function globally

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

    // Click handler for task checkboxes is now in `ui.initializeDelegatedNoteEventListeners`
    // which itself is largely replaced by x-on in noteComponent template.
    // Task checkbox clicks are handled by x-on in the (future) noteComponent template for tasks.
    
    // Input handler for debounced saving of content -- THIS IS NOW REDUNDANT
    // noteComponent.handleInput calls debouncedSaveNote.
    /*
    notesContainer.addEventListener('input', (e) => {
        if (e.target.matches('.note-content.edit-mode')) {
            const noteItem = e.target.closest('.note-item');
            if (noteItem) {
                const contentDiv = e.target;
                // The rawContent dataset is less critical now as noteComponent.note.content is the source of truth
                // contentDiv.dataset.rawContent = ui.normalizeNewlines(ui.getRawTextWithNewlines(contentDiv));
                debouncedSaveNote(noteItem);
            }
        }
    });
    */
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
        initializeTemplateHandling(); // Initialize template functionality

        // Initialize search modals
        initGlobalSearch(); // Already existed, ensure it's called if not already
        initPageSearchModal(); // Already existed, ensure it's called if not already
        initNoteSearchModal(); // Initialize our new note search modal

        // feather.replace(); // Initialize feather icons after DOM is ready
        
        // Calendar is now initialized in `app-init.js` to ensure it's ready before page load.
    } catch (error) {
        console.error('Failed to initialize application:', error);
        document.body.innerHTML = `<h1>Application Initialization Failed</h1><p>${error.message}</p><p>Check the console for more details.</p>`;
    }
});