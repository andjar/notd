// Alpine.js state management only

function getAppStore() {
    if (typeof window !== 'undefined' && window.Alpine && window.Alpine.store) {
        return window.Alpine.store('app');
    }
    throw new Error('Alpine store not available');
}

// --- State management ---
export function setCurrentPageId(newId) {
    getAppStore().setCurrentPageId(newId);
}

export function setCurrentPageName(newName) {
    getAppStore().setCurrentPageName(newName);
}

export function setSaveStatus(newStatus) {
    getAppStore().setSaveStatus(newStatus);
}

export function setCurrentPagePassword(newPassword) {
    getAppStore().setPagePassword(newPassword);
}

export function getCurrentPagePassword() {
    return getAppStore().pagePassword;
}

export function setNotesForCurrentPage(newNotes) {
    getAppStore().setNotes(newNotes);
}

export function addNoteToCurrentPage(note) {
    getAppStore().addNote(note);
}

export function removeNoteFromCurrentPageById(noteId) {
    getAppStore().removeNoteById(noteId);
}

export function updateNoteInCurrentPage(updatedNote) {
    getAppStore().updateNote(updatedNote);
}

export function setCurrentFocusedNoteId(newNoteId) {
    getAppStore().setFocusedNoteId(newNoteId);
}

// --- Page cache functions ---
export function setPageCache(key, value) {
    getAppStore().setPageCache(key, value);
}

export function getPageCache(key) {
    return getAppStore().getPageCache(key);
}

export function hasPageCache(key) {
    return getAppStore().hasPageCache(key);
}

export function deletePageCache(key) {
    return getAppStore().deletePageCache(key);
}

export function clearPageCache() {
    getAppStore().clearPageCache();
}

// --- Getters for reactive properties ---
export function getCurrentPageId() {
    return getAppStore().currentPageId;
}

export function getCurrentPageName() {
    return getAppStore().currentPageName;
}

export function getSaveStatus() {
    return getAppStore().saveStatus;
}

export function getNotesForCurrentPage() {
    return getAppStore().notes;
}

export function getCurrentFocusedNoteId() {
    return getAppStore().focusedNoteId;
}

// --- Constants ---
export const CACHE_MAX_AGE_MS = 5 * 60 * 1000; // 5 minutes
export const MAX_PREFETCH_PAGES = 3;
