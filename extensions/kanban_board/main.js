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

    // Filter notes based on status, checking both note properties and parent properties
    let filteredNotes = notes.filter(note => {
        const statusProperty = note.properties?.status?.[0]?.value || 
                             note.parent_properties?.status?.[0]?.value;
        return statusProperty && currentBoard.statuses.includes(statusProperty.toUpperCase());
    });

    // Apply additional filters if any, checking both note properties and parent properties
    if (currentBoard.filters && currentBoard.filters.length > 0) {
        filteredNotes = filteredNotes.filter(note => {
            return currentBoard.filters.every(filter => {
                const noteProperty = note.properties?.[filter.name]?.[0]?.value;
                const parentProperty = note.parent_properties?.[filter.name]?.[0]?.value;
                return noteProperty === filter.value || parentProperty === filter.value;
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

        try {
            // Use searchAPI.getTasks to get all tasks with parent properties
            const response = await searchAPI.getTasks('ALL', { includeParentProps: true });

            if (response?.results) {
                // Map the search results to our note format, including parent properties
                allNotes = response.results.map(result => ({
                    id: result.note_id,
                    content: result.content,
                    content_snippet: result.content_snippet,
                    properties: result.properties || {},
                    parent_properties: result.parent_properties || {},  // Include parent properties
                    page_id: result.page_id,
                    page_name: result.page_name,
                    // Check both note and parent properties for encryption status
                    is_encrypted: (result.properties?.encrypted?.some(p => 
                        String(p.value).toLowerCase() === 'true'
                    ) || result.parent_properties?.encrypted?.some(p => 
                        String(p.value).toLowerCase() === 'true'
                    )) || false
                }));

                // Filter notes based on the current board's statuses
                allNotes = allNotes.filter(note => {
                    const statusProperty = note.properties?.status?.[0]?.value || 
                                         note.parent_properties?.status?.[0]?.value;
                    return statusProperty && statuses.includes(statusProperty.toUpperCase());
                });

                // Apply additional filters if any
                if (currentBoard?.filters && Array.isArray(currentBoard.filters) && currentBoard.filters.length > 0) {
                    allNotes = allNotes.filter(note => {
                        return currentBoard.filters.every(filter => {
                            const noteProperty = note.properties?.[filter.name]?.[0]?.value;
                            const parentProperty = note.parent_properties?.[filter.name]?.[0]?.value;
                            return noteProperty === filter.value || parentProperty === filter.value;
                        });
                    });
                }

                console.log(`Found ${allNotes.length} notes matching the board criteria`);
            } else {
                console.warn('Search API returned unexpected format:', response);
                allNotes = [];
            }

            if (!allNotes || allNotes.length === 0) {
                console.warn('No task notes found matching the board criteria.');
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
