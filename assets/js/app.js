/**
 * @file Main application entry point. Initializes the app and sets up global event listeners.
 * @module app
 */

// Alpine.js will be loaded from CDN and available as global Alpine
import { splashScreen } from './app/splash-screen.js';
import sidebarComponent from './app/sidebar-component.js';
import calendarComponent from './app/calendar-component.js';
import { sidebarState } from './app/sidebar.js';



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
  
  // Notes live in the outline model (app/outline.js), not in this store.
  setNotes() {},

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
    Alpine.data('splashScreen', splashScreen);
    Alpine.data('sidebarComponent', sidebarComponent);
    Alpine.data('calendarComponent', calendarComponent);
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
    handleNoteInput
} from './app/note-actions.js';
import * as outline from './app/outline.js';
import { uploadFilesToNote } from './ui/note-renderer.js';

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
    
    // Every keystroke updates the model; the outbox coalesces and sends.
    notesContainer.addEventListener('input', handleNoteInput);
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
        const noteWrapper = e.target.closest('.note-content-wrapper');
        if (!noteWrapper || !e.dataTransfer?.files?.length) return;
        e.preventDefault();
        noteWrapper.classList.remove('dragover');
        const noteItem = noteWrapper.closest('.note-item');
        const noteId = noteItem?.dataset.noteId;
        if (noteId) await uploadFilesToNote(noteId, Array.from(e.dataTransfer.files), noteItem);
    });
}

// Mobile toolbar event handlers
const { 
  mobileToolbar, 
  mobileToggleLeftSidebarBtn, 
  mobileAddRootNoteBtn, 
  mobileToggleRightSidebarBtn, 
  leftSidebar, 
  rightSidebar 
} = ui.domRefs;

if (mobileToolbar) {
  // Helper: Only one sidebar open at a time on mobile
  function closeOtherSidebar(side) {
    if (window.innerWidth > 600) return;
    if (side === 'left') {
      // Open left, close right
      if (rightSidebar && !rightSidebar.classList.contains('collapsed')) {
        sidebarState.right.toggle();
      }
    } else if (side === 'right') {
      // Open right, close left
      if (leftSidebar && !leftSidebar.classList.contains('collapsed')) {
        sidebarState.left.toggle();
      }
    }
  }

  mobileToggleLeftSidebarBtn?.addEventListener('click', () => {
    if (window.innerWidth > 600) return;
    sidebarState.left.toggle();
    closeOtherSidebar('left');
  });
  mobileToggleRightSidebarBtn?.addEventListener('click', () => {
    if (window.innerWidth > 600) return;
    sidebarState.right.toggle();
    closeOtherSidebar('right');
  });
  mobileAddRootNoteBtn?.addEventListener('click', () => {
    if (window.innerWidth > 600) return;
    handleAddRootNote();
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
        // Note click/collapse/checkbox handlers must be live as soon as notes
        // are rendered, which happens inside initializeApp().
        ui.initializeDelegatedNoteEventListeners(notesContainer);
        await initializeApp();
        initPropertyEditor(); // Initialize listeners for the property modal
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