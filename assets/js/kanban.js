import { notesAPI } from './api_client.js';
import { displayKanbanBoard } from './ui/kanban-board.js';
import { setNotesForCurrentPage, notesForCurrentPage } from './app/state.js'; // To store fetched notes

document.addEventListener('DOMContentLoaded', async () => {
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
            // Simulate fetching from multiple pages or a future "all notes" endpoint
            // Replace this with actual logic if such an endpoint exists or after API modification.
            // For now, as a placeholder, let's assume we can fetch notes from page 1 and 2
            // and merge them. This is just to have some data.
            const page1Data = await notesAPI.getPageData(1); // Assuming page 1 exists
            if (page1Data && page1Data.notes) {
                allNotes = allNotes.concat(page1Data.notes);
            }
            const page2Data = await notesAPI.getPageData(2); // Assuming page 2 exists
             if (page2Data && page2Data.notes) {
                allNotes = allNotes.concat(page2Data.notes.filter(n2 => !allNotes.find(n1 => n1.id === n2.id))); // Avoid duplicates
            }
            // A more robust solution would be a dedicated API endpoint.
            if (allNotes.length === 0) {
                 console.warn('No notes found from placeholder fetch logic. Kanban board might be empty.');
            }

        } catch (fetchError) {
            console.error('Error fetching initial notes for Kanban:', fetchError);
            allNotes = []; // Ensure it's an array
        }
        
        setNotesForCurrentPage(allNotes); // Store globally if other parts of kanban-board expect it via state.js

        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = ''; // Clear "Loading..." message
            displayKanbanBoard(kanbanRootElement, notesForCurrentPage()); // notesForCurrentPage from state.js
        }

    } catch (error) {
        console.error('Error initializing Kanban board:', error);
        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = '<p class="error-message">Error loading Kanban board. Please try again later.</p>';
        }
    }
});
