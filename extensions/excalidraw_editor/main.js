import { attachmentsAPI } from '../../assets/js/api_client.js';

// Excalidraw AppState and Export functions will be available on window.ExcalidrawLib
const ExcalidrawLib = window.ExcalidrawLib;

document.addEventListener('DOMContentLoaded', () => {
    const excalidrawContainer = document.getElementById('excalidraw-container');

    let currentNoteId = null;
    let excalidrawAPI = null; // To store the Excalidraw API instance

    // 1. Initialize Excalidraw and store its API
    if (excalidrawContainer && ExcalidrawLib) {
        const excalidrawProps = {
            // initialData: { ... } // Optionally load initial data
            onChange: (elements, appState) => {
                // console.log("Elements:", elements);
                // console.log("State:", appState);
            },
            onPointerUpdate: (payload) => {
                // console.log("Pointer Update:", payload);
            },
            // Store the Excalidraw API once it's available
            excalidrawAPI: (api) => {
                excalidrawAPI = api;
                // Expose the API globally for Alpine.js to access
                window.excalidrawAPI = api;
                console.log("Excalidraw API initialized and exposed globally");
            }
        };
        ReactDOM.render(
            React.createElement(ExcalidrawLib.Excalidraw, excalidrawProps),
            excalidrawContainer
        );
    } else {
        console.error('Excalidraw container or ExcalidrawLib not found.');
        if (excalidrawContainer) {
            excalidrawContainer.innerHTML = 'Error: Excalidraw could not be initialized.';
        }
        return; // Stop if Excalidraw cannot be initialized
    }

    // 2. Retrieve note_id from URL (this is now handled by Alpine.js, but we keep it for reference)
    try {
        const urlParams = new URLSearchParams(window.location.search);
        currentNoteId = urlParams.get('note_id');
        if (currentNoteId) {
            console.log('Current Note ID:', currentNoteId);
        } else {
            console.error('Note ID not found in URL parameters.');
        }
    } catch (e) {
        console.error("Error parsing URL parameters:", e);
    }

    // Note: The save functionality is now handled by Alpine.js in excalidraw.html
    // This file now only handles the Excalidraw initialization and API exposure
});
