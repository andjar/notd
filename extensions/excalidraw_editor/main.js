import { attachmentsAPI } from '../../assets/js/api_client.js';

// Excalidraw AppState and Export functions will be available on window.ExcalidrawLib
const ExcalidrawLib = window.ExcalidrawLib;

document.addEventListener('DOMContentLoaded', () => {
    const excalidrawContainer = document.getElementById('excalidraw-container');
    const saveBtn = document.getElementById('save-to-note-btn');
    const noteIdDisplay = document.getElementById('note-id-display');
    const errorMessage = document.getElementById('error-message');
    const successMessage = document.getElementById('success-message');

    let currentNoteId = null;
    let excalidrawAPI = null;
    let isSaving = false;

    // Helper to update UI state
    function updateUI() {
        noteIdDisplay.textContent = currentNoteId ? `Note ID: ${currentNoteId}` : 'Note ID: Not Provided!';
        saveBtn.disabled = isSaving || !currentNoteId || !excalidrawAPI;
        errorMessage.style.display = errorMessage.textContent ? '' : 'none';
        successMessage.style.display = successMessage.textContent ? '' : 'none';
    }

    // Retrieve note_id from URL
    try {
        const urlParams = new URLSearchParams(window.location.search);
        currentNoteId = urlParams.get('note_id');
    } catch (e) {
        currentNoteId = null;
    }

    // Initialize Excalidraw and store its API
    if (excalidrawContainer && window.Excalidraw) {
        const excalidrawProps = {
            onChange: () => {},
            onPointerUpdate: () => {},
            excalidrawAPI: (api) => {
                excalidrawAPI = api;
                window.excalidrawAPI = api;
                updateUI();
            }
        };
        ReactDOM.render(
            React.createElement(window.Excalidraw, excalidrawProps),
            excalidrawContainer
        );
    } else {
        excalidrawContainer.innerHTML = 'Error: Excalidraw could not be initialized.';
        return;
    }

    // Save button click handler
    saveBtn.addEventListener('click', async () => {
        if (!currentNoteId) {
            errorMessage.textContent = 'Error: Note ID is missing. Cannot save attachment.';
            successMessage.textContent = '';
            updateUI();
            return;
        }
        if (!excalidrawAPI) {
            errorMessage.textContent = 'Error: Excalidraw API is not available. Cannot save.';
            successMessage.textContent = '';
            updateUI();
            return;
        }
        isSaving = true;
        errorMessage.textContent = '';
        successMessage.textContent = '';
        updateUI();
        try {
            const elements = excalidrawAPI.getSceneElements();
            if (!elements || elements.length === 0) {
                errorMessage.textContent = 'The canvas is empty. Nothing to save.';
                updateUI();
                isSaving = false;
                return;
            }
            const appState = excalidrawAPI.getAppState();
            const appState = excalidrawAPI.getAppState();
            const timestamp = new Date().toISOString().replace(/[:.]/g, '-');

            // Export PNG
            const pngBlob = await window.Excalidraw.exportToBlob({
                elements,
                mimeType: 'image/png',
                appState: {
                    ...appState,
                    exportWithDarkMode: appState.theme === 'dark',
                },
            });
            if (!pngBlob) throw new Error('Failed to export drawing to PNG Blob.');

            // Export JSON
            const jsonString = JSON.stringify(elements, null, 2);
            const jsonBlob = new Blob([jsonString], { type: 'application/json' });
            if (!jsonBlob) throw new Error('Failed to create JSON Blob for scene data.');

            const formData = new FormData();
            formData.append('note_id', currentNoteId);

            const pngFilename = `excalidraw_${timestamp}.png`;
            const jsonFilename = `excalidraw_${timestamp}.excalidraw`; // Using .excalidraw extension for scene file

            formData.append('attachmentFile[]', pngBlob, pngFilename);
            formData.append('attachmentFile[]', jsonBlob, jsonFilename);

            if (!window.attachmentsAPI || !window.attachmentsAPI.uploadAttachment) {
                throw new Error('attachmentsAPI or uploadAttachment function is not available.');
            }
            await window.attachmentsAPI.uploadAttachment(formData);
            successMessage.textContent = 'Drawing and scene data saved successfully as attachments!';
        } catch (error) {
            errorMessage.textContent = `Error saving drawing: ${error.message}`;
        } finally {
            isSaving = false;
            updateUI();
        }
    });

    // Initial UI update
    updateUI();
});
