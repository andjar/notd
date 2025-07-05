// Legacy state.js - now a bridge to Alpine store
// This file maintains backward compatibility while directing all state management to Alpine store

// Helper to access Alpine store
function getAppStore() {
    if (typeof window !== 'undefined' && window.Alpine && window.Alpine.store) {
        return window.Alpine.store('app');
    }
    console.warn('Alpine store not available, falling back to window properties');
    return null;
}

// --- Legacy exports (now proxying to Alpine store) ---

export function setCurrentPageId(newId) {
    const store = getAppStore();
    if (store) {
        store.setCurrentPageId(newId);
    } else {
        window.currentPageId = newId;
    }
}

export function setCurrentPageName(newName) {
    const store = getAppStore();
    if (store) {
        store.setCurrentPageName(newName);
    } else {
        window.currentPageName = newName;
    }
}

export function setSaveStatus(newStatus) {
    const store = getAppStore();
    if (store) {
        store.setSaveStatus(newStatus);
    } else {
        window.saveStatus = newStatus;
    }
}

export function setCurrentPagePassword(newPassword) {
    const store = getAppStore();
    if (store) {
        store.setPagePassword(newPassword);
    } else {
        window.currentPagePassword = newPassword;
    }
}

export function getCurrentPagePassword() {
    const store = getAppStore();
    if (store) {
        return store.pagePassword;
    }
    return window.currentPagePassword || null;
}

export function setNotesForCurrentPage(newNotes) {
    const store = getAppStore();
    if (store) {
        store.setNotes(newNotes);
    } else {
        window.notesForCurrentPage = newNotes;
    }
}

export function addNoteToCurrentPage(note) {
    const store = getAppStore();
    if (store) {
        store.addNote(note);
    } else if (window.notesForCurrentPage) {
        window.notesForCurrentPage.push(note);
        window.notesForCurrentPage.sort((a,b)=>a.order_index-b.order_index);
    }
}

export function removeNoteFromCurrentPageById(noteId) {
    const store = getAppStore();
    if (store) {
        store.removeNoteById(noteId);
    } else if (window.notesForCurrentPage) {
        const idx = window.notesForCurrentPage.findIndex(n=>String(n.id)===String(noteId));
        if(idx>-1) window.notesForCurrentPage.splice(idx,1);
    }
}

export function updateNoteInCurrentPage(updatedNote) {
    const store = getAppStore();
    if (store) {
        store.updateNote(updatedNote);
    } else if (window.notesForCurrentPage) {
        const idx = window.notesForCurrentPage.findIndex(n=>String(n.id)===String(updatedNote.id));
        if(idx>-1){
            window.notesForCurrentPage[idx]={...window.notesForCurrentPage[idx],...updatedNote};
        }else{
            window.notesForCurrentPage.push(updatedNote);
        }
    }
}

export function setCurrentFocusedNoteId(newNoteId) {
    const store = getAppStore();
    if (store) {
        store.setFocusedNoteId(newNoteId);
    } else {
        window.currentFocusedNoteId = newNoteId;
    }
}

// --- Page cache functions ---
export function setPageCache(key, value) {
    const store = getAppStore();
    if (store) {
        store.setPageCache(key, value);
    }
}

export function getPageCache(key) {
    const store = getAppStore();
    if (store) {
        return store.getPageCache(key);
    }
    return null;
}

export function hasPageCache(key) {
    const store = getAppStore();
    if (store) {
        return store.hasPageCache(key);
    }
    return false;
}

export function deletePageCache(key) {
    const store = getAppStore();
    if (store) {
        return store.deletePageCache(key);
    }
    return false;
}

export function clearPageCache() {
    const store = getAppStore();
    if (store) {
        store.clearPageCache();
    }
}

// --- Getters for reactive properties ---
export function getCurrentPageId() {
    const store = getAppStore();
    return store ? store.currentPageId : window.currentPageId;
}

export function getCurrentPageName() {
    const store = getAppStore();
    return store ? store.currentPageName : window.currentPageName;
}

export function getSaveStatus() {
    const store = getAppStore();
    return store ? store.saveStatus : window.saveStatus;
}

export function getNotesForCurrentPage() {
    const store = getAppStore();
    return store ? store.notes : (window.notesForCurrentPage || []);
}

export function getCurrentFocusedNoteId() {
    const store = getAppStore();
    return store ? store.focusedNoteId : window.currentFocusedNoteId;
}

// --- Legacy exports for direct access (deprecated, use getters instead) ---
export const currentPageId = null; // Use getCurrentPageId() instead
export const currentPageName = null; // Use getCurrentPageName() instead
export const saveStatus = 'saved'; // Use getSaveStatus() instead
export const notesForCurrentPage = []; // Use getNotesForCurrentPage() instead
export const currentFocusedNoteId = null; // Use getCurrentFocusedNoteId() instead

// --- Constants ---
export const CACHE_MAX_AGE_MS = 5 * 60 * 1000; // 5 minutes
export const MAX_PREFETCH_PAGES = 3;

// Legacy state variables (for window object compatibility)
if (typeof window !== 'undefined') {
    window.currentPageId = window.currentPageId || null;
    window.currentPageName = window.currentPageName || null;
    window.saveStatus = window.saveStatus || 'saved';
    window.notesForCurrentPage = window.notesForCurrentPage || [];
    window.currentFocusedNoteId = window.currentFocusedNoteId || null;
    window.currentPagePassword = window.currentPagePassword || null;
}
