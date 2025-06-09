/**
 * Service for calculating order_index values for notes.
 */

/**
 * Calculates a target order_index for a note and determines any sibling updates required.
 *
 * @param {Array<Object>} notesArray - The current array of all notes.
 *                                    Each note object is expected to have `id`, `order_index`, and `parentId`.
 * @param {string|null} parentId - The ID of the parent note for the note being moved/inserted. Null for root notes.
 * @param {string|null} previousSiblingId - The ID of the note that will be immediately before the new/moved note.
 *                                        Null if inserting at the beginning or if it's the only child.
 * @param {string|null} nextSiblingId - The ID of the note that will be immediately after the new/moved note.
 *                                    Null if inserting at the end or if it's the only child.
 * @returns {Object} An object containing:
 *                   `targetOrderIndex` (number): The calculated order_index for the note.
 *                   `siblingUpdates` (Array<{id: string, newOrderIndex: number}>): An array of notes that need their order_index updated.
 * @throws {Error} If sibling notes (if provided) are not found in notesArray.
 */
export function calculateOrderIndex(notesArray, parentId, previousSiblingId, nextSiblingId) {
    let targetOrderIndex;
    const siblingUpdates = [];

    // Filter children of the same parent and parse order_index as integer
    const parentChildren = notesArray
        .filter(note => String(note.parent_note_id) === String(parentId))
        .map(note => ({
            ...note,
            order_index: parseInt(note.order_index, 10)
        }))
        .sort((a, b) => a.order_index - b.order_index);

    const previousSibling = previousSiblingId ? parentChildren.find(n => String(n.id) === String(previousSiblingId)) : null;
    const nextSibling = nextSiblingId ? parentChildren.find(n => String(n.id) === String(nextSiblingId)) : null;

    if (previousSiblingId && !previousSibling) {
        throw new Error(`calculateOrderIndex: Previous sibling with ID ${previousSiblingId} not found.`);
    }
    if (nextSiblingId && !nextSibling) {
        throw new Error(`calculateOrderIndex: Next sibling with ID ${nextSiblingId} not found.`);
    }

    const previousSiblingOrderIndex = previousSibling ? previousSibling.order_index : null;
    const nextSiblingOrderIndex = nextSibling ? nextSibling.order_index : null;

    if (previousSiblingId === null) { // Inserting at the beginning
        targetOrderIndex = 0;
        if (nextSiblingId === null) { // Only child
            // No siblings to update
        } else { // Inserting before nextSibling
            // All existing children of this parent need their order_index incremented
            parentChildren.forEach((child, index) => {
                siblingUpdates.push({ id: String(child.id), newOrderIndex: index + 1 });
            });
        }
    } else if (nextSiblingId === null) { // Inserting at the end
        targetOrderIndex = previousSiblingOrderIndex + 1;
        // No siblings after this one to update
    } else { // Inserting between previousSibling and nextSibling
        if (nextSiblingOrderIndex - previousSiblingOrderIndex > 1) {
            targetOrderIndex = previousSiblingOrderIndex + 1;
            // No re-numbering needed for subsequent siblings
        } else {
            targetOrderIndex = previousSiblingOrderIndex + 1;
            // Re-number nextSibling and all subsequent siblings
            const nextSiblingIndexInParentArray = parentChildren.findIndex(n => String(n.id) === String(nextSiblingId));
            if (nextSiblingIndexInParentArray !== -1) {
                for (let i = nextSiblingIndexInParentArray; i < parentChildren.length; i++) {
                    siblingUpdates.push({
                        id: String(parentChildren[i].id),
                        newOrderIndex: targetOrderIndex + 1 + (i - nextSiblingIndexInParentArray)
                    });
                }
            }
        }
    }
    
    // Ensure targetOrderIndex is an integer, should be by logic but as a safeguard.
    if (targetOrderIndex === undefined || targetOrderIndex === null || isNaN(parseInt(targetOrderIndex, 10))) {
        console.error('[calculateOrderIndex] Critical error: targetOrderIndex is not a valid integer.', { parentId, previousSiblingId, nextSiblingId, previousSiblingOrderIndex, nextSiblingOrderIndex });
        // This case should ideally not be reached if logic is correct.
        // Fallback to 0 if it's the only child, or append if others exist.
        if (parentChildren.length === 0) {
            targetOrderIndex = 0;
        } else {
            targetOrderIndex = parentChildren[parentChildren.length -1].order_index + 1;
        }
         console.warn('[calculateOrderIndex] Fallback targetOrderIndex set to:', targetOrderIndex);
    } else {
        targetOrderIndex = parseInt(targetOrderIndex, 10);
    }

    return { targetOrderIndex, siblingUpdates };
}
