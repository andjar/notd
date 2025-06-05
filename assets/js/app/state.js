// This file will store the application state.

// --- State Variables (Primarily for read access from outside) ---
export let currentPageId = null;
export let currentPageName = null;
export let saveStatus = 'saved'; // valid: saved, saving, error
export let pageDataCache = new Map(); // Changed back to Map
export const CACHE_MAX_AGE_MS = 5 * 60 * 1000; // 5 minutes
export const MAX_PREFETCH_PAGES = 3;
export let notesForCurrentPage = [];
export let currentFocusedNoteId = null;

// --- Assign to window object (for debugging or specific global needs) ---
// Initial assignment. Setters will keep these updated.
window.currentPageId = currentPageId;
window.currentPageName = currentPageName;
window.saveStatus = saveStatus;
window.pageDataCache = pageDataCache; // Note: window.pageDataCache will be the Map instance
window.CACHE_MAX_AGE_MS = CACHE_MAX_AGE_MS; // Constants likely don't need setters
window.MAX_PREFETCH_PAGES = MAX_PREFETCH_PAGES; // Constants likely don't need setters
window.notesForCurrentPage = notesForCurrentPage;
window.currentFocusedNoteId = currentFocusedNoteId;

// --- Setter Functions ---

export function setCurrentPageId(newId) {
  currentPageId = newId;
  window.currentPageId = newId;
}

export function setCurrentPageName(newName) {
  currentPageName = newName;
  window.currentPageName = newName;
}

export function setSaveStatus(newStatus) {
  saveStatus = newStatus;
  window.saveStatus = newStatus;
}

// For notesForCurrentPage, we might want more granular functions
// e.g., addNote, removeNote, updateNote, or set all notes.
// For this refactor, a simple setter for the whole array is implemented.
// More complex operations should ensure window.notesForCurrentPage is also updated.
export function setNotesForCurrentPage(newNotes) {
  notesForCurrentPage = newNotes;
  window.notesForCurrentPage = newNotes; // Assigns the new array reference
}

// Helper to add a single note - ensures notesForCurrentPage array is mutated directly
// and window object is kept in sync.
export function addNoteToCurrentPage(note) {
    notesForCurrentPage.push(note);
    // window.notesForCurrentPage should automatically reflect this change
    // as it holds a reference to the notesForCurrentPage array.
}

// Helper to remove a note by ID - ensures notesForCurrentPage array is mutated directly
// and window object is kept in sync.
export function removeNoteFromCurrentPageById(noteId) {
    const indexToRemove = notesForCurrentPage.findIndex(n => String(n.id) === String(noteId));
    if (indexToRemove > -1) {
        notesForCurrentPage.splice(indexToRemove, 1);
    }
    // window.notesForCurrentPage will reflect this change.
}

// Helper to update a note in the array - ensures notesForCurrentPage array is mutated
// and window object is kept in sync.
export function updateNoteInCurrentPage(updatedNote) {
    const noteIndex = notesForCurrentPage.findIndex(n => String(n.id) === String(updatedNote.id));
    if (noteIndex > -1) {
        notesForCurrentPage[noteIndex] = { ...notesForCurrentPage[noteIndex], ...updatedNote };
    } else {
        // If note not found, add it (optional behavior, depends on requirements)
        notesForCurrentPage.push(updatedNote);
    }
    // window.notesForCurrentPage will reflect this change.
}


export function setCurrentFocusedNoteId(newNoteId) {
  currentFocusedNoteId = newNoteId;
  window.currentFocusedNoteId = newNoteId;
}

// --- pageDataCache Management Functions ---

export function setPageCache(key, value) {
  pageDataCache.set(key, value);
  // window.pageDataCache automatically reflects this change as it's a Map.
}

export function getPageCache(key) {
  return pageDataCache.get(key);
}

export function hasPageCache(key) {
  return pageDataCache.has(key);
}

export function deletePageCache(key) {
  return pageDataCache.delete(key);
}

export function clearPageCache() {
    pageDataCache.clear();
}
