import { notesAPI, pagesAPI, queryAPI, searchAPI } from '../../assets/js/api_client.js'; // Corrected path and added pagesAPI
import { displayKanbanBoard } from './ui.js'; // Corrected path assuming ui.js is in the same directory
import { setNotesForCurrentPage, notesForCurrentPage } from '../../assets/js/app/state.js'; // Corrected path

let currentBoardId = 'all_tasks'; // Default board

function getBoardStatuses(board) {
    // Use the globally configured states from kanban.php
    const validStates = window.configuredKanbanStates || ['TODO', 'DOING', 'DONE'];
    
    if (!board) return validStates;
    if (Array.isArray(board.statuses) && board.statuses.length > 0) {
        // Filter statuses to only include valid ones
        return board.statuses.filter(status => validStates.includes(status));
    }
    return validStates;
}

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

function filterNotesByBoard(notes, currentBoardId) {
    const currentBoard = window.kanbanConfig.boards.find(b => b.id === currentBoardId);
    if (!currentBoard) {
        console.error(`Board configuration not found for ID: ${currentBoardId}`);
        return [];
    }

    // Filter notes based on status
    let filteredNotes = notes.filter(note => {
        const statusProperty = note.properties?.status?.[0]?.value;
        return statusProperty && currentBoard.statuses.includes(statusProperty.toUpperCase());
    });

    // Apply additional filters if any
    if (currentBoard.filters && currentBoard.filters.length > 0) {
        filteredNotes = filteredNotes.filter(note => {
            return currentBoard.filters.every(filter => {
                const property = note.properties?.[filter.name]?.[0]?.value;
                return property === filter.value;
            });
        });
    }

    return filteredNotes;
}

export async function initializeKanban() {
    const kanbanRootElement = document.getElementById('kanban-root');

    if (!kanbanRootElement) {
        console.error('Kanban root element #kanban-root not found.');
        return;
    }

    try {
        console.log('Searching for task notes...');
        let allNotes = [];

        // Get current board configuration
        const currentBoard = window.kanbanConfig?.boards?.find(b => b.id === currentBoardId);
        if (!currentBoard) {
            console.warn(`Board configuration not found for ID: ${currentBoardId}, using default configuration`);
        }

        // Get statuses for the current board
        const statuses = getBoardStatuses(currentBoard);
        console.log('Using statuses:', statuses);

        // Build search query for status
        const statusQuery = statuses
            .map(status => `status:${status.toLowerCase()}`)
            .join(' OR ');

        // Add any additional filters from board configuration
        let searchQuery = statusQuery;
        if (currentBoard?.filters && Array.isArray(currentBoard.filters) && currentBoard.filters.length > 0) {
            const filterQueries = currentBoard.filters.map(filter => 
                `${filter.name}:${filter.value}`
            );
            searchQuery = `(${statusQuery}) AND (${filterQueries.join(' AND ')})`;
        }

        console.log('Using search query:', searchQuery);

        try {
            // Use searchAPI from api_client.js
            const response = await searchAPI.search(searchQuery, {
                per_page: 1000  // Get a large number of results since we're filtering by properties
            });

            if (response?.results) {
                // Map the search results to our note format, using the actual fields from the search API
                allNotes = response.results.map(result => ({
                    id: result.note_id,
                    content: result.content,
                    content_snippet: result.content_snippet, // Include the snippet for display
                    properties: result.properties || {},
                    page_id: result.page_id,
                    page_name: result.page_name,
                    // Include any encrypted status if present
                    is_encrypted: result.properties?.encrypted?.some(p => 
                        String(p.value).toLowerCase() === 'true'
                    ) || false
                }));

                console.log(`Found ${allNotes.length} notes matching the search criteria`);
            } else {
                console.warn('Search API returned unexpected format:', response);
                allNotes = [];
            }

            if (!allNotes || allNotes.length === 0) {
                console.warn('No task notes found matching the search criteria.');
                allNotes = [];
            }

        } catch (searchError) {
            console.error('Error searching for task notes:', searchError);
            throw searchError;
        }

        setNotesForCurrentPage(allNotes);

        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = '';
            displayKanbanBoard(kanbanRootElement, notesForCurrentPage); 
        }

    } catch (error) {
        console.error('Error initializing Kanban board:', error);
        if (kanbanRootElement) {
            kanbanRootElement.innerHTML = `
                <div class="error-message">
                    <p>Error loading Kanban board. Please try again later.</p>
                    <p class="error-details">${error.message}</p>
                    <p class="error-hint">Make sure the board configuration is properly set up in window.kanbanConfig</p>
                </div>`;
        }
    }
}

// Initialize the board selector when the module loads
document.addEventListener('DOMContentLoaded', () => {
    initializeBoardSelector();
    initializeKanban();
});
