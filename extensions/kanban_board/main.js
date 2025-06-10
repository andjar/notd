import { notesAPI, pagesAPI, queryAPI } from '../../assets/js/api_client.js'; // Corrected path and added pagesAPI
import { displayKanbanBoard } from './ui.js'; // Corrected path assuming ui.js is in the same directory
import { setNotesForCurrentPage, notesForCurrentPage } from '../../assets/js/app/state.js'; // Corrected path

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
            // Fetch notes using queryAPI with correct SQL for Kanban statuses
            console.log('Fetching task notes using queryAPI with SQL join on Properties...');
            const KANBAN_STATUSES = ['todo', 'doing', 'done', 'someday', 'waiting'];
            const statusList = KANBAN_STATUSES.map(s => `'${s}'`).join(', ');
            const sql = `
                SELECT DISTINCT N.id
                FROM Notes N
                JOIN Properties P ON N.id = P.note_id
                WHERE P.name = 'status'
                AND P.value IN (${statusList})
            `;
            allNotes = await queryAPI.queryNotes(sql, { include_properties: true, per_page: 1000 });

            if (!allNotes || allNotes.length === 0) {
                console.warn('No task notes found. Kanban board might be empty.');
                allNotes = []; // Ensure it's an array in case of null/undefined response
            }
        } catch (fetchError) {
            console.error('Error fetching initial notes for Kanban:', fetchError);
            allNotes = []; // Ensure it's an array on error
        }
        
        setNotesForCurrentPage(allNotes); // Store globally

        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = ''; // Clear "Loading..." message
            // notesForCurrentPage is an array that holds the notes from the state
            displayKanbanBoard(kanbanRootElement, notesForCurrentPage); 
        }

    } catch (error) {
        console.error('Error initializing Kanban board:', error);
        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = '<p class="error-message">Error loading Kanban board. Please try again later.</p>';
        }
    }
}
