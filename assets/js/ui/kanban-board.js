// Import necessary modules
import { notesAPI } from '../api_client.js';
// import { notesForCurrentPage, updateNoteInCurrentPage } from '../app/state.js'; // state.js is used by kanban-app.js, direct use here is reduced
import { parseAndRenderContent } from './note-renderer.js'; // For rendering card content, if needed
// Sortable is loaded globally via script tag, no need to import

// Define Kanban Columns
const KANBAN_COLUMNS = [
    { id: 'todo', title: 'Todo', statusMatcher: 'TODO' },
    { id: 'doing', title: 'Doing', statusMatcher: 'DOING' },
    { id: 'done', title: 'Done', statusMatcher: 'DONE' },
    { id: 'someday', title: 'Someday', statusMatcher: 'SOMEDAY' },
    { id: 'waiting', title: 'Waiting', statusMatcher: 'WAITING' },
    // { id: 'archived', title: 'Archived', statusMatcher: ['CANCELLED', 'NLR'] } // For later
];

const TASK_PREFIXES = ['TODO ', 'DOING ', 'DONE ', 'SOMEDAY ', 'WAITING ', 'CANCELLED ', 'NLR '];

/**
 * Checks note content for task prefixes.
 * @param {string} noteContent - The raw content of the note.
 * @returns {object|null} - An object { status: 'PREFIX', content: 'Actual task text', originalPrefix: 'PREFIX ' } or null if not a task.
 */
function getTaskStatusAndContent(noteContent) {
    if (typeof noteContent !== 'string') return null;
    for (const prefix of TASK_PREFIXES) {
        if (noteContent.startsWith(prefix)) {
            return {
                status: prefix.trim(), // e.g., 'TODO'
                content: noteContent.substring(prefix.length),
                originalPrefix: prefix
            };
        }
    }
    return null;
}

/**
 * Creates a basic DOM element for a Kanban card.
 * @param {object} note - The original note object.
 * @param {object} taskInfo - The result from getTaskStatusAndContent.
 * @returns {HTMLElement} - A div element representing the card.
 */
function createKanbanCard(note, taskInfo) {
    const cardElement = document.createElement('div');
    cardElement.classList.add('kanban-card');
    cardElement.setAttribute('draggable', 'true');
    cardElement.dataset.noteId = note.id;
    cardElement.dataset.originalPrefix = taskInfo.originalPrefix;
    cardElement.dataset.currentStatus = taskInfo.status;
    // Render complex content if necessary, for now, just text.
    // cardElement.innerHTML = parseAndRenderContent(taskInfo.content, note.id); // If complex rendering is needed
    cardElement.innerHTML = taskInfo.content; // Simpler version for now

    // console.log(`Creating card for note ID ${note.id}, status ${taskInfo.status}, prefix ${taskInfo.originalPrefix}`);
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
        columnEl.dataset.statusMatcher = colConfig.statusMatcher; // e.g., 'TODO'

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
        const taskInfo = getTaskStatusAndContent(note.content);
        if (taskInfo) {
            const targetColumnEl = containerElement.querySelector(`.kanban-column[data-status-matcher="${taskInfo.status}"] .kanban-column-cards`);
            if (targetColumnEl) {
                const cardEl = createKanbanCard(note, taskInfo);
                targetColumnEl.appendChild(cardEl);
            } else {
                console.warn(`[Kanban] No column found for status: ${taskInfo.status} for note ID ${note.id}`);
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
                const originalPrefix = itemEl.dataset.originalPrefix; // e.g. "TODO "
                const oldStatus = itemEl.dataset.currentStatus;    // e.g. "TODO"
                
                const targetColumnEl = itemEl.closest('.kanban-column');
                if (!targetColumnEl) {
                    console.error('Failed to find target column for moved item.');
                    // Potentially revert UI or show error
                    evt.from.appendChild(itemEl); // Basic revert
                    alert('Error processing card move. Please refresh.');
                    return;
                }
                const newStatus = targetColumnEl.dataset.statusMatcher; // e.g. "DOING"

                // console.log(`Card moved: Note ID ${noteId}, From Status: ${oldStatus}, To Status: ${newStatus}, Original Prefix: ${originalPrefix}`);

                // If no actual status change or reordering within the same column (if oldIndex/newIndex are same)
                if (newStatus === oldStatus && evt.oldIndex === evt.newIndex) {
                    // console.log('No actual change in status or order.');
                    return;
                }

                const note = notesById.get(String(noteId));
                if (!note) {
                    console.error(`Original note object not found for ID: ${noteId}`);
                    evt.from.appendChild(itemEl); // Revert
                    alert('Error finding original note data. Please refresh.');
                    return;
                }

                // Reconstruct content
                // Get the base task text (content without the old prefix)
                let baseContent = note.content;
                if (typeof note.content === 'string' && note.content.startsWith(originalPrefix)) {
                    baseContent = note.content.substring(originalPrefix.length);
                } else {
                    // If the original prefix isn't there (e.g., content was edited externally or prefix was removed)
                    // Fallback to itemEl.textContent, but this might be lossy if card HTML is complex
                    baseContent = itemEl.textContent.trim(); 
                    console.warn(`Original prefix "${originalPrefix}" not found in note content "${note.content}". Using card text content as fallback: "${baseContent}"`);
                }
                
                const newRawContent = newStatus + ' ' + baseContent;
                console.log(`Updating note ${noteId}: from '${oldStatus}' to '${newStatus}'. New raw content: "${newRawContent}"`);

                try {
                    if (!notesAPI || typeof notesAPI.updateNote !== 'function') {
                        console.error('notesAPI.updateNote is not available. Cannot save changes.');
                        alert('Error: Cannot save changes to the server. API is not configured.');
                        evt.from.appendChild(itemEl); // Revert
                        return;
                    }

                    await notesAPI.updateNote(parseInt(noteId), { content: newRawContent });
                    
                    // Update local note object (important for consistency if notes array is reused)
                    note.content = newRawContent;
                    notesById.set(String(noteId), note); // Update the map entry

                    // Update card's dataset for future drags
                    itemEl.dataset.originalPrefix = newStatus + ' ';
                    itemEl.dataset.currentStatus = newStatus;
                    
                    console.log(`Note ${noteId} updated successfully to status ${newStatus}.`);

                } catch (error) {
                    console.error(`Failed to update note ${noteId} via API:`, error);
                    alert(`Error updating task "${baseContent.substring(0,20)}...". Please check console and refresh.`);
                    // Revert UI change by moving the item back to its original column and position
                    evt.from.appendChild(itemEl); 
                    // Note: This basic revert doesn't handle original sort order within 'from' list.
                    // A more robust solution might involve re-rendering or more precise Sortable.js revert.
                }
            }
        });
    });

    // Feather icons might need to be re-initialized if cards use them and are dynamically created
    if (window.feather) {
        window.feather.replace();
    }
}
