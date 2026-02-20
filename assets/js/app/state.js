// Alpine.js state management only

function getAppStore() {
    if (typeof window !== 'undefined' && window.Alpine && window.Alpine.store) {
        return window.Alpine.store('app');
    }
    throw new Error('Alpine store not available');
}

// 🔄 COMPATIBILITY LAYER: Syncing for legacy code
// TODO: Remove window.notesForCurrentPage once all references are migrated to Alpine.store
export function syncNotesState(notes) {
    const appStore = getAppStore();
    appStore.notes = notes;
    // DEPRECATED: For backward compatibility only
    if (typeof window !== 'undefined') {
        window.notesForCurrentPage = notes;
    }
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
    // **FIX**: Use syncNotesState to ensure both stores are updated
    syncNotesState(newNotes);
}

export function addNoteToCurrentPage(note) {
    const appStore = getAppStore();
    appStore.addNote(note);
    // Keep window global pointing to the same array
    if (typeof window !== 'undefined') {
        window.notesForCurrentPage = appStore.notes;
    }
}

export function removeNoteFromCurrentPageById(noteId) {
    const appStore = getAppStore();
    appStore.removeNoteById(noteId);
    // Keep window global pointing to the same array
    if (typeof window !== 'undefined') {
        window.notesForCurrentPage = appStore.notes;
    }
}

export function updateNoteInCurrentPage(updatedNote) {
    const appStore = getAppStore();
    appStore.updateNote(updatedNote);
    // Keep window global pointing to the same array
    if (typeof window !== 'undefined') {
        window.notesForCurrentPage = appStore.notes;
    }
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
