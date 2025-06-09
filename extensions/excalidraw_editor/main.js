// Import the attachmentsAPI from the global scope (assuming api_client.js is loaded)
// Note: If api_client.js exports modules, this import would be direct.
// For now, assuming api_client.js makes attachmentsAPI globally available or on window.
const attachmentsAPI = window.attachmentsAPI; 

// Excalidraw AppState and Export functions will be available on window.ExcalidrawLib
const ExcalidrawLib = window.ExcalidrawLib;

document.addEventListener('DOMContentLoaded', () => {
    const excalidrawContainer = document.getElementById('excalidraw-container');
    const saveButton = document.getElementById('save-to-note-btn');
    const noteIdDisplay = document.getElementById('note-id-display');

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
                console.log("Excalidraw API initialized");
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

    // 2. Retrieve note_id from URL
    try {
        const urlParams = new URLSearchParams(window.location.search);
        currentNoteId = urlParams.get('note_id');
        if (currentNoteId) {
            noteIdDisplay.textContent = `Note ID: ${currentNoteId}`;
            console.log('Current Note ID:', currentNoteId);
        } else {
            noteIdDisplay.textContent = 'Note ID: Not Provided!';
            console.error('Note ID not found in URL parameters.');
            saveButton.disabled = true; // Disable save if no note_id
            saveButton.title = "Cannot save: Note ID is missing in the URL.";
        }
    } catch (e) {
        console.error("Error parsing URL parameters:", e);
        noteIdDisplay.textContent = 'Note ID: Error';
        saveButton.disabled = true;
        saveButton.title = "Cannot save: Error reading Note ID from URL.";
    }


    // 3. Handle "Save as Attachment" button click
    saveButton.addEventListener('click', async () => {
        if (!currentNoteId) {
            alert('Error: Note ID is missing. Cannot save attachment.');
            return;
        }
        if (!excalidrawAPI) {
            alert('Error: Excalidraw API is not available. Cannot save.');
            return;
        }

        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';

        try {
            // 4. Export drawing as PNG Blob
            // Get all elements from Excalidraw scene
            const elements = excalidrawAPI.getSceneElements();
            if (!elements || elements.length === 0) {
                alert("The canvas is empty. Nothing to save.");
                saveButton.disabled = false;
                saveButton.textContent = 'Save as Attachment';
                return;
            }
            
            const appState = excalidrawAPI.getAppState();

            // Use Excalidraw's exportToBlob utility
            const blob = await ExcalidrawLib.exportToBlob({
                elements: elements,
                mimeType: 'image/png',
                appState: {
                    ...appState,
                    // Ensure options like viewBackgroundColor are set if you want them in the export
                    // For example, to ensure transparent background is not used if not desired:
                    // viewBackgroundColor: appState.viewBackgroundColor || '#ffffff', 
                    exportWithDarkMode: appState.theme === 'dark', // Example: export dark mode if active
                },
                // files: excalidrawAPI.getFiles() // Include if you support images within Excalidraw
            });

            if (!blob) {
                throw new Error('Failed to export drawing to Blob.');
            }

            // 5. Prepare FormData
            const formData = new FormData();
            formData.append('note_id', currentNoteId);
            // Use a filename like "excalidraw_drawing_TIMESTAMP.png"
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
            const filename = `excalidraw_${timestamp}.png`;
            formData.append('attachmentFile', blob, filename);

            console.log('FormData prepared for upload:', { note_id: currentNoteId, filename });

            // 6. Call attachmentsAPI.uploadAttachment
            // Ensure attachmentsAPI is available (loaded from api_client.js)
            if (!attachmentsAPI || !attachmentsAPI.uploadAttachment) {
                 throw new Error('attachmentsAPI or uploadAttachment function is not available. Ensure api_client.js is loaded correctly.');
            }
            
            const result = await attachmentsAPI.uploadAttachment(formData);
            console.log('Attachment upload result:', result);
            alert('Drawing saved successfully as an attachment!');
            // Optionally, you could close the extension window or redirect,
            // or clear the canvas if it's meant for single use.

        } catch (error) {
            console.error('Error saving Excalidraw drawing:', error);
            alert(`Error saving drawing: ${error.message}`);
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'Save as Attachment';
        }
    });
});
