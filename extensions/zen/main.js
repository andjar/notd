// Keep track of the current note ID globally within this script's scope for Zen editor
let currentNoteId = null; 

// Function to parse URL query parameters - exposed for testing if needed, but primarily internal
function getQueryParam(param) {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get(param);
}

// Main initialization logic for the Zen editor
async function initializeZenEditor() {
  const saveButton = document.getElementById('save-button');
  const textArea = document.getElementById('zen-text-area');
  const statusMessage = document.getElementById('status-message');
  // currentNoteId is already defined in the outer scope

  // Display status messages
  function showStatus(message, isError = false) {
    if (statusMessage) {
      statusMessage.textContent = message;
      statusMessage.style.color = isError ? 'red' : 'green';
      setTimeout(() => {
        statusMessage.textContent = '';
      }, 3000);
    } else {
      // Fallback if statusMessage element isn't in the DOM for some reason
      console.log(`Status (${isError ? 'Error' : 'Success'}): ${message}`);
    }
  }

  // Load content function
  async function loadContent(noteIdToLoad) {
    if (!textArea) {
        console.error("#zen-text-area not found. Cannot load content.");
        if(saveButton) saveButton.disabled = true;
        return;
    }
    if (!noteIdToLoad) {
      showStatus('No note ID provided in URL.', true);
      textArea.textContent = "No note loaded. Please open a note to edit in Zen mode.";
      if(saveButton) saveButton.disabled = true;
      currentNoteId = null; // Ensure currentNoteId is reset
      return;
    }
    currentNoteId = noteIdToLoad; // Set the global currentNoteId for the editor session
    console.log(`Loading content for note ID: ${currentNoteId}`);
    try {
    try {
      const note = await notesAPI.getNoteById(currentNoteId);
      if (note && typeof note.content === 'string') {
        textArea.textContent = note.content;
        if(saveButton) saveButton.disabled = false;
      } else if (note && typeof note.content !== 'string') {
        textArea.textContent = ''; // Note exists but content is not a string or is empty
        showStatus('Note content is empty or in an unexpected format.', false);
        if(saveButton) saveButton.disabled = false;
      } else { // note is null or undefined
        textArea.textContent = "Note not found. Cannot load content.";
        showStatus('Note not found.', true);
        if(saveButton) saveButton.disabled = true;
        currentNoteId = null; // Reset if note not found
      }
    } catch (error) {
      console.error('Error loading note:', error);
      textArea.textContent = "Error loading note content.";
      showStatus('Error loading note.', true);
      if(saveButton) saveButton.disabled = true;
      currentNoteId = null; // Reset on error
    }
  }

  // Save content function
  async function saveContentToAPI(noteIdToSave, contentToSave) {
    if (!noteIdToSave) {
      showStatus('Cannot save: No note ID available.', true);
      return;
    }
    if (!textArea) { // Should not happen if loadContent was successful
        console.error("#zen-text-area not found. Cannot save content.");
        showStatus('Error: Text area not found.', true);
        return;
    }
    console.log(`Saving content for note ID: ${noteIdToSave}`);
    try {
      // The mock API's batchUpdateNotes was simplified.
      // The real API expects: [{ id: noteId, content: content, type: 'update' }]
      // The mock was changed to: notesAPI.batchUpdateNotes([{ id: noteId, content: content, type: 'update' }])
      const response = await notesAPI.batchUpdateNotes([{ id: noteIdToSave, content: contentToSave, type: 'update' }]);
      // Assuming response is {success: true/false} or similar from mock
      if (response && response.success) {
        showStatus('Saved!', false);
      } else {
        showStatus('Error saving note. API returned failure.', true);
      }
    } catch (error) {
      console.error('Error saving note:', error);
      showStatus('Error saving note.', true);
    }
  }

  // Setup event listener for the save button
  if (saveButton) {
    saveButton.addEventListener('click', () => {
      if (currentNoteId && textArea) {
        const newContent = textArea.innerText; // Using innerText to get plain text
        saveContentToAPI(currentNoteId, newContent);
      } else if (!textArea) {
        showStatus('Error: Text area not found.', true);
      }
      else {
        showStatus('No note is currently loaded. Cannot save.', true);
      }
    });
  } else {
    console.error("#save-button not found in the DOM. Save functionality will not work.");
  }

  // Initial content load
  // Ensure DOM elements are checked before use, even if checked at top of initializeZenEditor
  if (!textArea) {
     console.error("#zen-text-area not found in the DOM. Zen editor cannot function.");
     if(statusMessage) showStatus("Critical error: Text area missing.", true);
     if(saveButton) saveButton.disabled = true;
     return; // Stop initialization if text area is missing
  }
   if (!statusMessage){
    console.warn("#status-message not found in the DOM. Status messages will be logged to console only.");
  }


  const noteIdFromUrl = getQueryParam('noteId');
  if (typeof notesAPI !== 'undefined' && notesAPI && typeof notesAPI.getNoteById === 'function' && typeof notesAPI.batchUpdateNotes === 'function') {
    await loadContent(noteIdFromUrl); // Call loadContent with the extracted noteId
  } else {
    showStatus('notesAPI is not available or incomplete. Cannot load or save notes.', true);
    if (textArea) textArea.textContent = "Error: notesAPI not found or incomplete. Make sure api_client.js is loaded correctly and provides expected functions.";
    if (saveButton) saveButton.disabled = true;
    console.error('notesAPI is not defined, or getNoteById/batchUpdateNotes is missing. Ensure api_client.js is correctly loaded and notesAPI is globally available with all methods.');
  }
}

// Expose the initialization function to the global scope for testability
// and for the DOMContentLoaded event listener.
if (typeof window !== 'undefined') {
  window.initializeZenEditor = initializeZenEditor;
}

// Standard event listener to trigger initialization when the DOM is ready.
document.addEventListener('DOMContentLoaded', () => {
  if (window.initializeZenEditor) {
    window.initializeZenEditor();
  } else {
    console.error("initializeZenEditor function not found on window object. Zen editor may not start.");
  }
});
