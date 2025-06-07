import { notesAPI, pagesAPI } from '../assets/js/api_client.js'; // Corrected path and added pagesAPI
import { displayKanbanBoard } from './ui.js'; // Corrected path assuming ui.js is in the same directory
import { setNotesForCurrentPage, notesForCurrentPage } from '../assets/js/app/state.js'; // Corrected path

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
            // Fetch all pages with their notes
            console.log('Fetching all pages with details for Kanban board...');
            const pagesWithDetails = await pagesAPI.getPages({ include_details: 1, include_internal: 0 });
            
            let allNotes = [];
            if (pagesWithDetails && Array.isArray(pagesWithDetails)) {
                pagesWithDetails.forEach(pageContainer => {
                    // The structure from pagesAPI.getPages with include_details=1 is:
                    // [ { page: {...}, notes: [...] }, ... ] when fetching multiple pages.
                    // However, api_client.js for pagesAPI.getPages currently returns an array of page objects,
                    // and if include_details is true, notes are directly embedded in each page object.
                    // Let's check the actual api_client.js pagesAPI.getPages behavior.
                    // It returns apiRequest(`pages.php...`), which returns `response.data`.
                    // The spec for GET api/pages.php (all pages) with include_details=1 suggests
                    // data: [ { page: { id, name, ..., notes: [...] } }, ... ] OR
                    // data: [ { id, name, ..., notes: [...] }, ... ]
                    // The client code for pagesAPI.getPages doesn't seem to transform this structure further.
                    // Let's assume `pageContainer` is an object that has a `notes` array.
                    if (pageContainer.notes && Array.isArray(pageContainer.notes)) {
                        allNotes = allNotes.concat(pageContainer.notes);
                    }
                });
            }
            
            // Remove duplicate notes if any (e.g. if a note somehow appears on multiple pages, though unlikely with current model)
            const uniqueNotesMap = new Map();
            allNotes.forEach(note => uniqueNotesMap.set(note.id, note));
            allNotes = Array.from(uniqueNotesMap.values());

            if (allNotes.length === 0) {
                 console.warn('No notes found after fetching all pages. Kanban board might be empty.');
            }

        } catch (fetchError) {
            console.error('Error fetching initial notes for Kanban:', fetchError);
            allNotes = []; // Ensure it's an array
        }
        
        setNotesForCurrentPage(allNotes); // Store globally

        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = ''; // Clear "Loading..." message
            // notesForCurrentPage should be a function that returns the notes array from state
            displayKanbanBoard(kanbanRootElement, notesForCurrentPage()); 
        }

    } catch (error) {
        console.error('Error initializing Kanban board:', error);
        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = '<p class="error-message">Error loading Kanban board. Please try again later.</p>';
        }
    }
}
