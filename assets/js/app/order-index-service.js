/**
 * Service for calculating order_index values for notes.
 */

/**
 * Calculates a proposed order_index for a note based on its desired position
 * relative to its siblings.
 *
 * @param {Array<Object>} notesArray - The current array of all notes for the page.
 *                                    Each note object is expected to have at least `id` and `order_index`.
 * @param {string|null} parentId - The ID of the parent note. Null for root notes.
 * @param {string|null} previousSiblingId - The ID of the note that will be immediately before the new/moved note.
 *                                        Null if inserting at the beginning of the list (or if it's the only note).
 * @param {string|null} nextSiblingId - The ID of the note that will be immediately after the new/moved note.
 *                                    Null if inserting at the end of the list (or if it's the only note).
 * @returns {number} The calculated floating-point order_index.
 * @throws {Error} If required sibling notes are not found in notesArray.
 */
export function calculateOrderIndex(notesArray, parentId, previousSiblingId, nextSiblingId) {
    console.log('[calculateOrderIndex] Inputs:', { parentId, previousSiblingId, nextSiblingId });

    let previousSiblingOrderIndex = null;
    if (previousSiblingId) {
        const previousSibling = notesArray.find(n => String(n.id) === String(previousSiblingId));
        if (!previousSibling) {
            console.error('[calculateOrderIndex] Error: Previous sibling not found for ID:', previousSiblingId);
            throw new Error(`calculateOrderIndex: Previous sibling with ID ${previousSiblingId} not found.`);
        }
        previousSiblingOrderIndex = parseFloat(previousSibling.order_index);
    }

    let nextSiblingOrderIndex = null;
    if (nextSiblingId) {
        const nextSibling = notesArray.find(n => String(n.id) === String(nextSiblingId));
        if (!nextSibling) {
            console.error('[calculateOrderIndex] Error: Next sibling not found for ID:', nextSiblingId);
            throw new Error(`calculateOrderIndex: Next sibling with ID ${nextSiblingId} not found.`);
        }
        nextSiblingOrderIndex = parseFloat(nextSibling.order_index);
    }

    let newOrderIndex;

    if (previousSiblingId === null) { // Inserting at the beginning
        if (nextSiblingId === null) { // Only child
            newOrderIndex = 1.0;
            console.log('[calculateOrderIndex] Case: Only child. Index:', newOrderIndex);
        } else { // Inserting before nextSibling
            newOrderIndex = nextSiblingOrderIndex / 2.0;
            console.log('[calculateOrderIndex] Case: Before next sibling. Next sibling index:', nextSiblingOrderIndex, 'New index:', newOrderIndex);
        }
    } else if (nextSiblingId === null) { // Inserting at the end
        newOrderIndex = previousSiblingOrderIndex + 1.0;
        console.log('[calculateOrderIndex] Case: After previous sibling. Previous sibling index:', previousSiblingOrderIndex, 'New index:', newOrderIndex);
    } else { // Inserting between previousSibling and nextSibling
        newOrderIndex = (previousSiblingOrderIndex + nextSiblingOrderIndex) / 2.0;
        console.log('[calculateOrderIndex] Case: Between siblings. Previous index:', previousSiblingOrderIndex, 'Next index:', nextSiblingOrderIndex, 'New index:', newOrderIndex);
    }
    
    // Ensure it's a float and handle potential issues like division by zero if nextSiblingOrderIndex was 0
    if (newOrderIndex === 0 && previousSiblingId === null && nextSiblingId !== null) {
        // If inserting before a note with order_index 0, need a different strategy (e.g., negative or re-index)
        // For now, let's make it a small positive number. This case should be rare if order_index starts at 1.0.
        newOrderIndex = nextSiblingOrderIndex > 0 ? nextSiblingOrderIndex / 2.0 : -1.0; // Or throw error, or re-index
        console.warn('[calculateOrderIndex] Adjusted index for inserting before 0. New index:', newOrderIndex);
    }
    
    if (isNaN(newOrderIndex) || !isFinite(newOrderIndex)) {
        console.error('[calculateOrderIndex] Error: Calculated order_index is NaN or Infinity.', {previousSiblingOrderIndex, nextSiblingOrderIndex});
        // Fallback or error based on more robust requirements, e.g. if siblings had non-numeric order_index.
        // For now, attempt a simple recovery or throw.
        if (previousSiblingOrderIndex !== null && isFinite(previousSiblingOrderIndex)) newOrderIndex = previousSiblingOrderIndex + 1.0;
        else if (nextSiblingOrderIndex !== null && isFinite(nextSiblingOrderIndex)) newOrderIndex = nextSiblingOrderIndex / 2.0;
        else newOrderIndex = Date.now() / 1000; // Last resort, not ideal
        console.warn('[calculateOrderIndex] Recovered newOrderIndex to:', newOrderIndex);
        // throw new Error('calculateOrderIndex: Resulting order_index is NaN or Infinity.');
    }

    console.log('[calculateOrderIndex] Output:', newOrderIndex);
    return newOrderIndex;
}
