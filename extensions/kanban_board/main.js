import { notesAPI, pagesAPI, queryAPI } from '../../assets/js/api_client.js'; // Corrected path and added pagesAPI
import { displayKanbanBoard } from './ui.js'; // Corrected path assuming ui.js is in the same directory
import { setNotesForCurrentPage, notesForCurrentPage } from '../../assets/js/app/state.js'; // Corrected path

let currentBoardId = 'all_tasks'; // Default board

function initializeBoardSelector() {
    const selector = document.getElementById('board-selector');
    if (!selector || !window.kanbanConfig || !window.kanbanConfig.boards) {
        console.error('Board selector or config not found');
        return;
    }

    // Populate the selector with board options
    window.kanbanConfig.boards.forEach(board => {
        const option = document.createElement('option');
        option.value = board.id;
        option.textContent = board.label;
        selector.appendChild(option);
    });

    // Set up change handler
    selector.addEventListener('change', (e) => {
        currentBoardId = e.target.value;
        initializeKanban(); // Reinitialize with new board selection
    });
}

function buildBoardSql(statusList) {
    const currentBoard = window.kanbanConfig.boards.find(b => b.id === currentBoardId);
    if (!currentBoard) {
        console.error(`Board configuration not found for ID: ${currentBoardId}`);
        return null;
    }

    let sql = `
        SELECT DISTINCT N.id
        FROM Notes N
        JOIN Properties P ON N.id = P.note_id
        WHERE P.name = 'status'
        AND P.value IN (${statusList})
    `;

    // Add additional filters if any
    if (currentBoard.filters && currentBoard.filters.length > 0) {
        const additionalFilters = currentBoard.filters.map(filter => {
            return `EXISTS (
                SELECT 1 FROM Properties P2 
                WHERE P2.note_id = N.id 
                AND P2.name = '${filter.name}' 
                AND P2.value = '${filter.value}'
            )`;
        });
        
        if (additionalFilters.length > 0) {
            sql += ` AND ${additionalFilters.join(' AND ')}`;
        }
    }

    return sql;
}

export async function initializeKanban() {
    const kanbanRootElement = document.getElementById('kanban-root');

    if (!kanbanRootElement) {
        console.error('Kanban root element #kanban-root not found.');
        return;
    }

    try {
        // Attempt to fetch notes.
        // OPTION 1: Fetch notes from a specific page designated for tasks (e.g., page_id = 1, replace with actual ID if known)
        // const pageIdForTasks = 1; // Example: Assuming a page with ID 1 is for tasks.
        // console.log(`Fetching tasks from page ID: ${pageIdForTasks}`);
        // const pageData = await notesAPI.getPageData(pageIdForTasks);
        // let notesToProcess = pageData.notes || [];

        // OPTION 2: If there's a way to get all notes or all task notes.
        // This is a placeholder for where a more specific API call might be needed.
        // For now, let's try to fetch from a few known pages or a general query if one exists.
        // As a fallback, let's try to get notes from a common page like 'Journal' (assuming its ID or a way to fetch it).
        // This part highlights the need for a better "get all tasks" API endpoint.
        
        // Let's try to get data from multiple pages or a general source.
        // This is a placeholder. We'll assume for now `getAllNotes` is a function that tries to get all notes
        // or notes from several important pages. This will likely need to be refined or an API change proposed.
        console.log('Attempting to fetch all notes for Kanban board...');
        let allNotes = [];
        try {
            console.log('Fetching task notes using queryAPI with SQL join on Properties...');
            
            let currentKanbanStatuses = [];
            if (window.configuredKanbanStates && Array.isArray(window.configuredKanbanStates) && window.configuredKanbanStates.length > 0) {
                currentKanbanStatuses = window.configuredKanbanStates;
            } else {
                console.warn('[Kanban Main] window.configuredKanbanStates not found or empty. Using default statuses (TODO, DOING, DONE) for query.');
                currentKanbanStatuses = ['TODO', 'DOING', 'DONE'];
            }
            const statusList = currentKanbanStatuses.map(s => `'${s.toUpperCase()}'`).join(', ');
            
            const sql = buildBoardSql(statusList);
            if (!sql) {
                throw new Error('Failed to build SQL query for current board');
            }

            allNotes = await queryAPI.queryNotes(sql, { include_properties: true, per_page: 1000 });

            if (!allNotes || allNotes.length === 0) {
                console.warn('No task notes found. Kanban board might be empty.');
                allNotes = [];
            }
        } catch (fetchError) {
            console.error('Error fetching initial notes for Kanban:', fetchError);
            allNotes = [];
        }
        
        setNotesForCurrentPage(allNotes);

        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = '';
            displayKanbanBoard(kanbanRootElement, notesForCurrentPage); 
        }

    } catch (error) {
        console.error('Error initializing Kanban board:', error);
        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = '<p class="error-message">Error loading Kanban board. Please try again later.</p>';
        }
    }
}

// Initialize the board selector when the module loads
document.addEventListener('DOMContentLoaded', () => {
    initializeBoardSelector();
    initializeKanban();
});
