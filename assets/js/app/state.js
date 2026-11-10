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

export function setCurrentFocusedNoteId(newNoteId) {
    getAppStore().setFocusedNoteId(newNoteId);
}

// --- Getters for reactive properties ---
export function getCurrentPageId() {
    return getAppStore().currentPageId;
}

export function getCurrentPageName() {
    return getAppStore().currentPageName;
}

// Notes are held by the outline model: import { model } from './outline.js'.

export function getCurrentFocusedNoteId() {
    return getAppStore().focusedNoteId;
}
