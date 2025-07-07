/**
 * @file Main application entry point. Initializes the app and sets up global event listeners.
 * @module app
 */

// Alpine.js will be loaded from CDN and available as global Alpine
import noteComponent from './app/note-component.js';
import { splashScreen } from './app/splash-screen.js';
import sidebarComponent from './app/sidebar-component.js';
import calendarComponent from './app/calendar-component.js';

// Imports for notesManager
import { calculateOrderIndex } from './app/order-index-service.js';
import { notesAPI as notesAPIClient } from './api_client.js'; // aliased to avoid conflict if notesAPI is used elsewhere globally
import { loadPage as appLoadPage } from './app/page-loader.js'; // aliased
import { pageCache as appPageCache } from './app/page-cache.js'; // aliased, though pageCache might not be directly used in notesManager yet

function notesManager() {
    return {
        notes: [], // This will be populated by displayNotes via notesContainer.__x.getUnobservedData().notes

        // Alpine Sort plugin calls this handler: handle(itemKey, newPosition, targetSortableElement, sourceSortableElement, newIndexWithinTarget, oldIndexWithinSource)
        handleDrop(itemKey, newPosition, targetEl, sourceEl) {
            console.log('[AlpineSort] Drop event:', { itemKey, newPosition, targetEl_dataset_parentId: targetEl.dataset.parentId, targetEl_id: targetEl.id, sourceEl_id: sourceEl.id });
            const noteId = String(itemKey); // itemKey is note.id, ensure string for comparison

            let newParentId = null;
            // targetEl is the sortable container (#notes-container or a .note-children div)
            // data-parent-id is set on .note-children divs
            if (targetEl.classList.contains('note-children') && targetEl.dataset.parentId) {
                newParentId = String(targetEl.dataset.parentId);
            }

            console.log('[AlpineSort] Dragged Note ID:', noteId, 'Target Parent ID:', newParentId, 'New Index in Target:', newPosition);

            let draggedNoteData = null;
            let originalParentArray = null;
            let originalNoteIndex = -1;

            const findAndRemoveNote = (notesArr, id) => {
                for (let i = 0; i < notesArr.length; i++) {
                    if (String(notesArr[i].id) === id) {
                        const note = notesArr[i];
                        originalParentArray = notesArr;
                        originalNoteIndex = i;
                        notesArr.splice(i, 1);
                        return note;
                    }
                    if (notesArr[i].children) {
                        const foundNote = findAndRemoveNote(notesArr[i].children, id);
                        if (foundNote) return foundNote;
                    }
                }
                return null;
            };

            draggedNoteData = findAndRemoveNote(this.notes, noteId);

            if (!draggedNoteData) {
                console.error('[AlpineSort] Critical: Dragged note data not found for ID:', noteId, '. Forcing reload.');
                appLoadPage(window.currentPageName, false, false);
                return;
            }

            draggedNoteData.parent_note_id = newParentId ? parseInt(newParentId) : null;

            let targetArrayForInsertion;
            if (newParentId) {
                const findTargetArray = (notesArr, pId) => {
                    for (let note of notesArr) {
                        if (String(note.id) === pId) {
                            if (!note.children) note.children = []; // Ensure children array exists
                            return note.children;
                        }
                        if (note.children) {
                            const found = findTargetArray(note.children, pId);
                            if (found) return found;
                        }
                    }
                    return null;
                };
                targetArrayForInsertion = findTargetArray(this.notes, newParentId);
            } else {
                targetArrayForInsertion = this.notes; // Root
            }

            if (!targetArrayForInsertion) {
                console.error('[AlpineSort] Critical: Target array for insertion not found. Parent ID:', newParentId, '. Reverting and forcing reload.');
                if (originalParentArray && originalNoteIndex !== -1) {
                    originalParentArray.splice(originalNoteIndex, 0, draggedNoteData); // Put it back
                }
                appLoadPage(window.currentPageName, false, false);
                return;
            }

            targetArrayForInsertion.splice(newPosition, 0, draggedNoteData);

            const allNotesFlatForCalc = [];
            function flattenNotesForCalc(notesToFlatten) {
                for (const note of notesToFlatten) {
                    allNotesFlatForCalc.push(note);
                    if (note.children) {
                        flattenNotesForCalc(note.children);
                    }
                }
            }
            flattenNotesForCalc(this.notes); // Use the current state of this.notes

            const previousSiblingInTarget = targetArrayForInsertion[newPosition - 1];
            const nextSiblingInTarget = targetArrayForInsertion[newPosition + 1];
            const previousSiblingId = previousSiblingInTarget ? String(previousSiblingInTarget.id) : null;
            const nextSiblingId = nextSiblingInTarget ? String(nextSiblingInTarget.id) : null;

            console.log('[AlpineSort] Calculating order index with:', { newParentIdForCalc: newParentId, previousSiblingId, nextSiblingId, movedNoteId: noteId });

            const { targetOrderIndex, siblingUpdates } = calculateOrderIndex(
                allNotesFlatForCalc,
                newParentId, // This is the parent_id for the moved note
                previousSiblingId,
                nextSiblingId,
                noteId
            );

            console.log('[AlpineSort] calculateOrderIndex result:', { targetOrderIndex, siblingUpdates });

            const operations = [
                { type: 'update', payload: { id: noteId, parent_note_id: newParentId ? parseInt(newParentId) : null, order_index: targetOrderIndex } },
                ...siblingUpdates.map(upd => ({ type: 'update', payload: { id: String(upd.id), order_index: upd.newOrderIndex } }))
            ];

            draggedNoteData.order_index = targetOrderIndex;
            siblingUpdates.forEach(upd => {
                const sibToUpdate = allNotesFlatForCalc.find(n => String(n.id) === String(upd.id));
                if (sibToUpdate) sibToUpdate.order_index = upd.newOrderIndex;
            });

            this.notes = [...this.notes]; // Trigger reactivity for the whole structure

            console.log('[AlpineSort] Sending operations to backend:', operations);

            notesAPIClient.batchUpdateNotes(operations)
                .then(() => {
                    console.log('[AlpineSort] Batch update successful.');
                    if (window.appPageCache && typeof window.appPageCache.removePage === 'function') { // Ensure using imported cache
                        appPageCache.removePage(window.currentPageName);
                    }
                     // UI should be up-to-date due to Alpine's reactivity.
                     // Re-flatten and sort if local display depends on explicit sort not handled by x-for structure.
                     // For now, assume direct manipulation of this.notes and children arrays is enough.
                })
                .catch(error => {
                    console.error("[AlpineSort] Failed to save note drop changes:", error);
                    alert("Could not save new note positions. The page will now reload to ensure data consistency.");
                    if (window.appPageCache && typeof window.appPageCache.removePage === 'function') {
                        appPageCache.removePage(window.currentPageName);
                    }
                    appLoadPage(window.currentPageName, false, false);
                });
        }
    };
}

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
    console.log('[CACHE] setPageCache:', key);
    this.pageCache.set(key, value);
  },
  
  getPageCache(key) {
    console.log('[CACHE] getPageCache:', key);
    return this.pageCache.get(key);
  },
  
  hasPageCache(key) {
    return this.pageCache.has(key);
  },
  
  deletePageCache(key) {
    console.log('[CACHE] deletePageCache:', key);
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
    Alpine.data('notesManager', notesManager); // Register the new notesManager
});

// Alpine.start() is automatically called by the CDN version

// Page management
import { loadPage } from './app/page-loader.js';
window.loadPage = loadPage;

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