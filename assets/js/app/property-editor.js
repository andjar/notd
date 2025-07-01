import { currentPageId, setCurrentPagePassword } from './state.js';
import { ui, hidePagePropertiesModal, promptForEncryptionPassword } from '../ui.js';
import { pagesAPI, notesAPI } from '../api_client.js';
import { encrypt } from '../utils.js'; // Import encrypt from utils.js

// Stores the full page content while the modal is open
let pageContentForModal = '';

// --- CORE CRUD Functions for Properties ---

async function _updatePageContent(newContent) {
    try {
        ui.updateSaveStatusIndicator('pending');
        const updatedPage = await pagesAPI.updatePage(currentPageId, { content: newContent });
        pageContentForModal = updatedPage.content || '';
        // The properties are now derived from the returned content, so we pass the new properties to the UI.
        displayPageProperties(updatedPage.properties || {});
        ui.renderPageInlineProperties(updatedPage.properties || {}, null);
        ui.updateSaveStatusIndicator('saved');
        hidePagePropertiesModal();
    } catch (error) {
        console.error("Failed to update page content:", error);
        alert("Failed to save property changes.");
        ui.updateSaveStatusIndicator('error');
    }
}

// --- UI Rendering and Event Handling ---

export async function displayPageProperties(properties) {
    // When the modal is opened, fetch the LATEST page content to work with.
    try {
        const pageData = await pagesAPI.getPageById(currentPageId);
        pageContentForModal = pageData.content || '';
    } catch (error) {
        console.error("Could not fetch page data for property editor:", error);
        ui.domRefs.pagePropertiesList.innerHTML = '<p>Error: Page data not found.</p>';
        return;
    }

    const pagePropertiesList = ui.domRefs.pagePropertiesList;
    if (!pagePropertiesList) return;
    
    pagePropertiesList.innerHTML = ''; // Clear existing content

    const textarea = document.createElement('textarea');
    textarea.id = 'page-content-editor';
    textarea.className = 'full-width-textarea';
    textarea.value = pageContentForModal;
    textarea.placeholder = 'Enter page content and properties here...';
    textarea.rows = 20; // Or adjust as needed

    pagePropertiesList.appendChild(textarea);
}

async function handleEncryptPage() {
    let password;
    try {
        password = await promptForEncryptionPassword(); // Use the modal to get the password
    } catch (error) {
        console.warn(error.message);
        return; // User cancelled or an error occurred in the modal
    }

    ui.updateSaveStatusIndicator('pending', 'Encrypting page...');

    // sjcl is available globally via assets/libs/sjcl.js
    try {
        // 1. Create the hashed password for the page property
        const hashedPassword = sjcl.hash.sha256.hash(password);
        const hashedPasswordHex = sjcl.codec.hex.fromBits(hashedPassword);

        // Add the encrypt property to the content
        const textarea = document.getElementById('page-content-editor');
        let currentContent = textarea ? textarea.value : pageContentForModal;

        // Remove any existing {encrypt::...} properties first
        currentContent = currentContent.replace(/\{encrypt:::(.*?)\}/g, '').trim();

        const encryptProperty = `{encrypt:::${hashedPasswordHex}}`;
        let newContent = currentContent;
        if (newContent) {
            newContent += '\n' + encryptProperty;
        } else {
            newContent = encryptProperty;
        }
        
        textarea.value = newContent; // Update the textarea immediately

        // 2. Encrypt all notes for the current page
        const notes = await notesAPI.getPageData(currentPageId);
        const batchUpdates = notes.map(note => {
            if (note.is_encrypted) return null; // Already encrypted, skip
            return {
                type: 'update', // batch operations need a type
                payload: {
                    id: note.id,
                    content: encrypt(password, note.content),
                    is_encrypted: 1
                }
            };
        }).filter(Boolean); // Filter out nulls

        if (batchUpdates.length > 0) {
            // **FIX**: The batchUpdateNotes function expects the array of operations directly.
            await notesAPI.batchUpdateNotes(batchUpdates);
        }

        // 3. Save the page content with the new property AND save the password in state
        await _updatePageContent(newContent);
        setCurrentPagePassword(password); // Set password in state for the current session

        ui.updateSaveStatusIndicator('saved');
        alert("Page encryption has been set successfully. The page will require the password when accessed.");

    } catch (e) {
        console.error("Error during encryption setup:", e);
        alert("Failed to set up page encryption. Please check console for details.");
        ui.updateSaveStatusIndicator('error');
    }
}

const propertyModalListener = async (e) => {
    const target = e.target;
    
    // Handle content update on blur from the textarea
    if (e.type === 'blur' && target.id === 'page-content-editor') {
        const newContent = target.value;
        if (newContent !== pageContentForModal) { // Only save if content has changed
            await _updatePageContent(newContent);
        }
    }

    // Handle Save button click
    if (e.type === 'click' && target.id === 'save-page-content-btn') {
        const textarea = document.getElementById('page-content-editor');
        if (textarea) {
            const newContent = textarea.value;
            if (newContent !== pageContentForModal) {
                await _updatePageContent(newContent);
            }
        }
    }

    // Handle Encrypt button click
    if (e.type === 'click' && target.id === 'page-encryption-button') {
        await handleEncryptPage();
    }
};

export function initPropertyEditor() {
    const list = ui.domRefs.pagePropertiesList;
    const saveBtn = document.getElementById('save-page-content-btn'); // Get the new save button
    const encryptBtn = ui.domRefs.pageEncryptionButton; // Get the encryption button

    if (list) {
        list.addEventListener('blur', propertyModalListener, true); // Use capture phase for blur on children
        list.addEventListener('input', (e) => { // Listen for input to enable instant updates or more granular control
            if (e.target.id === 'page-content-editor') {
                // Optional: Live update if needed, but blur is sufficient for saving
            }
        });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', propertyModalListener);
    }
    if (encryptBtn) {
        encryptBtn.addEventListener('click', propertyModalListener);
    }
}