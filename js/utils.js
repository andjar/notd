// Utility functions

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024; const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function findNoteAndPath(targetId, notesArray, currentPath = []) {
    for (const note of notesArray) {
        const newPath = [...currentPath, note];
        if (String(note.id) === String(targetId)) {
            return { note, path: newPath };
        }
        if (note.children) {
            const foundInChildren = findNoteAndPath(targetId, note.children, newPath);
            if (foundInChildren) {
                return foundInChildren;
            }
        }
    }
    return null;
}

function adjustLevels(notesArray, currentLevel) {
    if (!notesArray) return;
    notesArray.forEach(note => {
        note.level = currentLevel;
        if (note.children) adjustLevels(note.children, currentLevel + 1);
    });
}
