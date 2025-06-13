// Import necessary modules
import { notesAPI } from '../../assets/js/api_client.js';
// import { notesForCurrentPage, updateNoteInCurrentPage } from '../app/state.js'; // state.js is used by kanban-app.js, direct use here is reduced
import { parseAndRenderContent } from '../../assets/js/ui/note-renderer.js'; // For rendering card content, if needed
// Sortable is loaded globally via script tag, no need to import

// Dynamically generate KANBAN_COLUMNS from configured states
let KANBAN_COLUMNS = [];
const DEFAULT_FALLBACK_COLUMNS = [
    { id: 'todo', title: 'Todo', statusMatcher: 'TODO' },
    { id: 'doing', title: 'Doing', statusMatcher: 'DOING' },
    { id: 'done', title: 'Done', statusMatcher: 'DONE' }
];

if (window.configuredKanbanStates && Array.isArray(window.configuredKanbanStates) && window.configuredKanbanStates.length > 0) {
    KANBAN_COLUMNS = window.configuredKanbanStates.map(state => ({
        id: state.toLowerCase(),
        title: state.charAt(0).toUpperCase() + state.slice(1).toLowerCase(),
        statusMatcher: state.toUpperCase()
    }));
} else {
    console.warn('[Kanban UI] window.configuredKanbanStates not found or empty. Using default columns (Todo, Doing, Done).');
    KANBAN_COLUMNS = DEFAULT_FALLBACK_COLUMNS;
}

const FALLBACK_STATUS = KANBAN_COLUMNS.length > 0 ? KANBAN_COLUMNS[0].statusMatcher : 'TODO';
const FALLBACK_COLUMN_ID = KANBAN_COLUMNS.length > 0 ? KANBAN_COLUMNS[0].id : 'todo';


/**
 * Extracts and normalizes the status from a note object.
 * @param {object} note - The note object.
 * @returns {string} - The uppercase status string (e.g., 'TODO', 'DOING').
 */
function getNoteStatus(note) {
    let status = FALLBACK_STATUS; // Default status from configured states or 'TODO'
    if (note.properties && note.properties.status) {
        let rawStatus = '';
        if (Array.isArray(note.properties.status) && note.properties.status.length > 0) {
            rawStatus = typeof note.properties.status[0] === 'string' ? note.properties.status[0] : (note.properties.status[0].value || '');
        } else if (typeof note.properties.status === 'string') {
            rawStatus = note.properties.status;
        } else if (note.properties.status.value && typeof note.properties.status.value === 'string') {
            rawStatus = note.properties.status.value;
        }

        if (rawStatus) {
            status = rawStatus.toUpperCase();
        }
    }
    return status;
}

/**
 * Creates a basic DOM element for a Kanban card.
 * @param {object} note - The original note object.
 * @returns {HTMLElement} - A div element representing the card.
 */
function createKanbanCard(note) {
    const cardElement = document.createElement('div');
    cardElement.classList.add('kanban-card');
    cardElement.setAttribute('draggable', 'true');
    cardElement.dataset.noteId = note.id;

    const currentStatus = getNoteStatus(note);
    cardElement.dataset.currentStatus = currentStatus;
    
    // Render complex content if necessary, for now, just text.
    // cardElement.innerHTML = parseAndRenderContent(note.content, note.id); // If complex rendering is needed
    cardElement.innerHTML = note.content; // Simpler version for now

    // console.log(`Creating card for note ID ${note.id}, status ${currentStatus}`);
    return cardElement;
}

/**
 * Displays the Kanban board.
 * @param {HTMLElement} containerElement - The DOM element to render the board into.
 * @param {Array<object>} notes - An array of note objects for the current page.
 */
export function displayKanbanBoard(containerElement, notes) {
    if (!containerElement) {
        console.error('Kanban container element not found!');
        return;
    }
    if (!notes) {
        console.warn('No notes provided to displayKanbanBoard.');
        notes = []; // Ensure notes is an array
    }

    containerElement.innerHTML = ''; // Clear previous content
    containerElement.classList.add('kanban-board-active');

    const notesById = new Map(notes.map(note => [String(note.id), note]));

    // Create column elements
    KANBAN_COLUMNS.forEach(colConfig => {
        const columnEl = document.createElement('div');
        columnEl.classList.add('kanban-column');
        columnEl.dataset.statusId = colConfig.id; // e.g., 'todo'
        columnEl.dataset.statusMatcher = colConfig.statusMatcher; // e.g., 'todo'

        const titleEl = document.createElement('h3');
        titleEl.classList.add('kanban-column-title');
        titleEl.textContent = colConfig.title;
        columnEl.appendChild(titleEl);

        const cardsContainerEl = document.createElement('div');
        cardsContainerEl.classList.add('kanban-column-cards');
        columnEl.appendChild(cardsContainerEl);

        containerElement.appendChild(columnEl);
    });

    // Process notes and distribute them into columns
    notes.forEach(note => {
        const status = getNoteStatus(note);

        const targetColumnEl = containerElement.querySelector(`.kanban-column[data-status-matcher="${status}"] .kanban-column-cards`);
        if (targetColumnEl) {
            const cardEl = createKanbanCard(note); // Pass the whole note
            targetColumnEl.appendChild(cardEl);
        } else {
            // Fallback: if status is not recognized, put in the first configured column
            const fallbackColumnSelector = `.kanban-column[data-status-matcher="${FALLBACK_STATUS}"] .kanban-column-cards`;
            const fallbackColumnEl = containerElement.querySelector(fallbackColumnSelector);
            if (fallbackColumnEl) {
                console.warn(`[Kanban] No column found for status: '${status}' for note ID ${note.id}. Placing in '${FALLBACK_COLUMN_ID}'.`);
                const cardEl = createKanbanCard(note);
                // Optionally, update the card's dataset.currentStatus to the fallback status if you want the card to reflect this
                // cardEl.dataset.currentStatus = FALLBACK_STATUS; 
                fallbackColumnEl.appendChild(cardEl);
            } else {
                 console.error(`[Kanban] No column found for status: ${status} and fallback '${FALLBACK_COLUMN_ID}' column also not found for note ID ${note.id}`);
            }
        }
    });

    // Initialize Sortable.js on card containers
    const cardContainers = containerElement.querySelectorAll('.kanban-column-cards');
    cardContainers.forEach(container => {
        new Sortable(container, {
            group: 'kanban-cards',
            animation: 150,
            ghostClass: 'kanban-ghost',
            chosenClass: 'kanban-chosen',
            dragClass: 'kanban-drag',
            onEnd: async (evt) => {
                const itemEl = evt.item; // The card that was moved
                const noteId = itemEl.dataset.noteId;
                const oldStatus = itemEl.dataset.currentStatus;    // e.g. "todo"
                
                const targetColumnEl = itemEl.closest('.kanban-column');
                if (!targetColumnEl) {
                    console.error('Failed to find target column for moved item.');
                    evt.from.appendChild(itemEl); // Basic revert
                    alert('Error processing card move. Please refresh.');
                    return;
                }
                const newStatus = targetColumnEl.dataset.statusMatcher; // e.g. "doing"

                // console.log(`Card moved: Note ID ${noteId}, From Status: ${oldStatus}, To Status: ${newStatus}`);

                if (newStatus === oldStatus && evt.oldIndex === evt.newIndex && evt.from === evt.to) {
                     // console.log('No actual change in status or order within the same column.');
                    return;
                }

                const note = notesById.get(String(noteId));
                if (!note) {
                    console.error(`Original note object not found for ID: ${noteId}`);
                    evt.from.appendChild(itemEl); // Revert
                    alert('Error finding original note data. Please refresh.');
                    return;
                }
                
                // New: Check for and update status prefix in note content
                let newContent = note.content;
                // Dynamically generate statusKeywords from KANBAN_COLUMNS
                const statusKeywords = KANBAN_COLUMNS.map(col => col.statusMatcher.toUpperCase());
                if (statusKeywords.length === 0) { // Fallback if KANBAN_COLUMNS is somehow empty
                    statusKeywords.push('TODO', 'DOING', 'DONE'); 
                    console.warn('[Kanban UI] statusKeywords was empty during Sortable onEnd, using default TODO, DOING, DONE.');
                }
                const statusRegex = new RegExp(`^(${statusKeywords.join('|')}):?\\s+`, 'i');
                const match = note.content.match(statusRegex);
        
                if (match) {
                    const newStatusUpper = newStatus.toUpperCase();
                    // Replace prefix and keep the rest of the content
                    newContent = newStatusUpper + ": " + note.content.substring(match[0].length);
                } else if (oldStatus !== newStatus) { // If no prefix matched and status changed
                    const newStatusUpper = newStatus.toUpperCase();
                    newContent = newStatusUpper + ": " + note.content;
                }
                
                console.log(`Updating note ${noteId}: from status '${oldStatus}' to '${newStatus}'. New content will be: "${newContent}"`);

                try {
                    if (!notesAPI || typeof notesAPI.updateNote !== 'function') {
                        console.error('notesAPI.updateNote is not available. Cannot save changes.');
                        alert('Error: Cannot save changes to the server. API is not configured.');
                        evt.from.appendChild(itemEl); // Revert
                        return;
                    }

                    // The API call now only sends the content
                    await notesAPI.updateNote(parseInt(noteId), { content: newContent });
                    
                    // Update local note object
                    if (!note.properties) {
                        note.properties = {};
                    }
                    note.properties.status = newStatus; // Still useful to update the local JS representation of status
                    note.content = newContent; // Update local content
                    itemEl.innerHTML = newContent; // Update card content visually
                    
                    notesById.set(String(noteId), note); // Update the map entry

                    // Update card's dataset for future drags
                    itemEl.dataset.currentStatus = newStatus;
                    
                    console.log(`Note ${noteId} updated successfully to status ${newStatus} with new content.`);

                } catch (error) {
                    console.error(`Failed to update note ${noteId} (content to "${newContent}") via API:`, error);
                    // Attempt to get some part of the content for the alert, assuming itemEl.textContent is simple
                    const cardContentPreview = itemEl.textContent.trim().substring(0,20);
                    alert(`Error updating task "${cardContentPreview}...". Please check console and refresh.`);
                    // Revert optimistic UI changes if API call fails
                    note.content = notesById.get(String(noteId)).content; // Revert content change
                    itemEl.innerHTML = note.content; // Revert visual change
                    if (note.properties) note.properties.status = oldStatus; // Revert status if it was changed
                    itemEl.dataset.currentStatus = oldStatus; // Revert dataset

                    evt.from.appendChild(itemEl); // Send item back to original column
                }
            }
        });
    });

    // Feather icons might need to be re-initialized if cards use them and are dynamically created
    if (window.feather) {
        window.feather.replace();
    }
}
