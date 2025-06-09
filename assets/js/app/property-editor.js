import { currentPageId } from './state.js';
import { ui } from '../ui.js';
import { propertiesAPI } from '../api_client.js';

function handleAddPropertyButtonClick() {
    const pagePropertiesList = ui.domRefs.pagePropertiesList;
    if (!pagePropertiesList) {
        console.error('pagePropertiesList element not found for adding property!');
        return;
    }

    const noPropertiesMessage = pagePropertiesList.querySelector('p.no-properties-message');
    if (noPropertiesMessage) {
        noPropertiesMessage.remove();
    }

    const propItem = document.createElement('div');
    propItem.className = 'page-property-item new-property-row';
    // const uniqueIdSuffix = Date.now(); // Not strictly needed for ID if we grab element directly

    propItem.innerHTML = `
        <span class="page-property-key" contenteditable="true" data-original-key="" data-is-new="true" placeholder="PropertyName"></span>
        <span class="page-property-separator">:</span>
        <input type="text" class="page-property-value" data-property="" value="" data-is-new-value="true" placeholder="PropertyValue" />
        <button class="page-property-delete" data-property="" data-is-new-delete="true" title="Remove this new property">&times;</button>
    `;
    pagePropertiesList.appendChild(propItem);

    const newKeySpan = propItem.querySelector('.page-property-key');
    if (newKeySpan) {
        newKeySpan.focus();
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(newKeySpan); // Selects placeholder text if any, or just sets cursor if empty
        selection.removeAllRanges();
        selection.addRange(range);
    }
    if (typeof feather !== 'undefined' && feather.replace) {
        feather.replace(); // Refresh icons for the new delete button
    }
}

function setupAddPropertyButtonListener() {
    const addBtn = ui.domRefs.addPagePropertyBtn;
    if (addBtn && !addBtn.dataset.listenerAttached) {
        addBtn.addEventListener('click', handleAddPropertyButtonClick);
        addBtn.dataset.listenerAttached = 'true';
    }
}

/**
 * Displays page properties in the modal.
 * (This is the version from app.js, to be used by the modal)
 * @param {Object} properties - Properties object for the current page.
 */
export function displayPageProperties(properties) {
    const pagePropertiesList = ui.domRefs.pagePropertiesList; // Assumes ui.domRefs is available
    if (!pagePropertiesList) {
        console.error('pagePropertiesList element not found!');
        return;
    }
    pagePropertiesList.innerHTML = '';
    if (!properties || Object.keys(properties).length === 0) {
        pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
        // Still set up the encryption listener even if no properties exist
        setupPagePropertiesModalListeners();
        return;
    }
    Object.entries(properties).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach((singleValue, index) => {
                const propItem = document.createElement('div');
                propItem.className = 'page-property-item';
                propItem.innerHTML = `
                    <span class="page-property-key" contenteditable="true" data-original-key="${key}" data-is-array="true" data-array-index="${index}">${key}</span>
                    <span class="page-property-separator">:</span>
                    <input type="text" class="page-property-value" data-property="${key}" data-array-index="${index}" data-original-value="${singleValue.value}" value="${singleValue.value}" />
                    <button class="page-property-delete" data-property="${key}" data-array-index="${index}" title="Delete this ${key} value">×</button>
                `;
                pagePropertiesList.appendChild(propItem);
            });
        } else {
            const propItem = document.createElement('div');
            propItem.className = 'page-property-item';
            propItem.innerHTML = `
                <span class="page-property-key" contenteditable="true" data-original-key="${key}">${key}</span>
                <span class="page-property-separator">:</span>
                <input type="text" class="page-property-value" data-property="${key}" data-original-value="${(typeof value === 'object' && value !== null && value.hasOwnProperty('value')) ? value.value : (value || '')}" value="${(typeof value === 'object' && value !== null && value.hasOwnProperty('value')) ? value.value : (value || '')}" />
                <button class="page-property-delete" data-property="${key}" title="Delete ${key} property">×</button>
            `;
            pagePropertiesList.appendChild(propItem);
        }
    });

    const existingListener = pagePropertiesList._propertyEventListener;
    if (existingListener) {
        pagePropertiesList.removeEventListener('blur', existingListener, true);
        pagePropertiesList.removeEventListener('keydown', existingListener);
        pagePropertiesList.removeEventListener('click', existingListener);
        pagePropertiesList.removeEventListener('change', existingListener);
    }
    const propertyEventListener = async (e) => {
        // Change on property value
        if (e.type === 'change' && e.target.matches('.page-property-value')) {
            const valueInput = e.target;
            const keyForValue = valueInput.dataset.property;
            const finalNewValue = valueInput.value.trim(); 
            const originalValue = valueInput.dataset.originalValue;

            if (valueInput.dataset.isNewValue === 'true') {
                if (!keyForValue) {
                    // console.warn("Attempted to save a new property value, but its key is not set.");
                    // If the key is empty, the blur handler for the key should have removed the row.
                    // If for some reason it's still here with an empty key, don't save.
                    const keySpan = valueInput.closest('.page-property-item')?.querySelector('.page-property-key');
                    if (!keySpan || !keySpan.textContent.trim()) {
                         valueInput.closest('.page-property-item')?.remove(); // Clean up row if key is truly empty
                         if (ui.domRefs.pagePropertiesList.children.length === 0 && !ui.domRefs.pagePropertiesList.querySelector('p.no-properties-message')) {
                            ui.domRefs.pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
                         }
                    }
                    return; 
                }
                await addPageProperty(keyForValue, finalNewValue); 
                valueInput.dataset.originalValue = finalNewValue; 
                delete valueInput.dataset.isNewValue;

                const propertyItemRow = valueInput.closest('.page-property-item');
                const deleteButton = propertyItemRow.querySelector('.page-property-delete');
                if (deleteButton && deleteButton.dataset.isNewDelete) {
                    delete deleteButton.dataset.isNewDelete;
                    deleteButton.title = `Delete ${keyForValue} property`;
                }
                const keySpan = propertyItemRow.querySelector('.page-property-key');
                if (keySpan && keySpan.dataset.isNew){ 
                    delete keySpan.dataset.isNew;
                    keySpan.dataset.originalKey = keyForValue; 
                }

            } else {
                // Existing logic for updating property value
                if (finalNewValue !== originalValue) {
                    const arrayIndexStr = valueInput.dataset.arrayIndex;
                    if (arrayIndexStr !== undefined) {
                        await updateArrayPropertyValue(keyForValue, parseInt(arrayIndexStr), finalNewValue);
                    } else {
                        await updatePageProperty(keyForValue, finalNewValue);
                    }
                    valueInput.dataset.originalValue = finalNewValue;
                }
            }
        } else if (e.type === 'blur' && e.target.matches('.page-property-key')) {
            // Blur on property key
            const keySpan = e.target;
            const propertyItemRow = keySpan.closest('.page-property-item');

            if (keySpan.dataset.isNew === 'true') {
                const newKeyName = keySpan.textContent.trim();

                if (!newKeyName && propertyItemRow) {
                    propertyItemRow.remove();
                    if (ui.domRefs.pagePropertiesList.children.length === 0 && !ui.domRefs.pagePropertiesList.querySelector('p.no-properties-message')) {
                        ui.domRefs.pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
                    }
                    return; 
                }
                
                keySpan.dataset.originalKey = newKeyName;
                delete keySpan.dataset.isNew; 

                const valueInput = propertyItemRow.querySelector('.page-property-value');
                valueInput.dataset.property = newKeyName; 

                const deleteButton = propertyItemRow.querySelector('.page-property-delete');
                deleteButton.dataset.property = newKeyName;
                deleteButton.title = `Delete ${newKeyName} property`; 
                if (deleteButton.dataset.isNewDelete) {
                    delete deleteButton.dataset.isNewDelete;
                }
                
                if (valueInput.value.trim() !== '' && valueInput.dataset.isNewValue === 'true') {
                   valueInput.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
                } else if (valueInput.value.trim() === '' && valueInput.dataset.isNewValue === 'true') {
                    // Also dispatch change for empty new values to ensure they are processed (e.g. saved as empty or potentially validated)
                    valueInput.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
                }

            } else {
                // Existing logic for renaming property key
                const originalKey = keySpan.dataset.originalKey;
                const newKey = keySpan.textContent.trim();
                if (newKey !== originalKey && newKey !== '') {
                    const isArray = keySpan.dataset.isArray === 'true';
                    const arrayIndexStr = keySpan.dataset.arrayIndex; 

                    if (isArray && arrayIndexStr !== undefined) { 
                        await renameArrayPropertyKey(originalKey, newKey, parseInt(arrayIndexStr));
                    } else {
                        await renamePropertyKey(originalKey, newKey);
                    }
                    keySpan.dataset.originalKey = newKey; 
                } else if (newKey === '' && originalKey) { 
                    keySpan.textContent = originalKey; 
                }
            }
        } else if (e.type === 'keydown' && e.key === 'Enter') {
            if (e.target.matches('.page-property-value')) {
                e.target.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (e.target.matches('.page-property-key')) {
                e.target.blur();
            }
        } else if (e.type === 'click' && e.target.matches('.page-property-delete')) {
            // Click on delete button
            const deleteButton = e.target;
            const propertyItemRow = deleteButton.closest('.page-property-item');

            if (deleteButton.dataset.isNewDelete === 'true') {
                if (propertyItemRow) propertyItemRow.remove(); 
                if (ui.domRefs.pagePropertiesList.children.length === 0 && !ui.domRefs.pagePropertiesList.querySelector('p.no-properties-message')) {
                    ui.domRefs.pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
                }
            } else {
                // Existing logic for deleting property (with confirmation)
                const key = deleteButton.dataset.property;
                const arrayIndexStr = deleteButton.dataset.arrayIndex;
                let confirmMessage = arrayIndexStr !== undefined ? `Are you sure you want to delete this "${key}" value?` : `Are you sure you want to delete the property "${key}"?`;
                
                const confirmed = await ui.showGenericConfirmModal('Delete Property', confirmMessage); 
                if (confirmed) {
                    if (arrayIndexStr !== undefined) {
                        await deleteArrayPropertyValue(key, parseInt(arrayIndexStr));
                    } else {
                        await deletePageProperty(key);
                    }
                }
            }
        }
    };
    pagePropertiesList._propertyEventListener = propertyEventListener;
    pagePropertiesList.addEventListener('blur', propertyEventListener, true);
    pagePropertiesList.addEventListener('keydown', propertyEventListener);
    pagePropertiesList.addEventListener('click', propertyEventListener);
    pagePropertiesList.addEventListener('change', propertyEventListener);
    
    // Set up the encryption icon listener
    setupPagePropertiesModalListeners();
    
    setupAddPropertyButtonListener(); // Ensure the listener for the Add button is active

    if (typeof feather !== 'undefined' && feather.replace) feather.replace();
}

export async function renamePropertyKey(oldKey, newKey) {
    if (!currentPageId) return;
    try {
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const value = properties[oldKey];
        if (value === undefined) { console.warn(`Property ${oldKey} not found for renaming`); return; }
        await propertiesAPI.deleteProperty('page', currentPageId, oldKey);
        await propertiesAPI.setProperty({ entity_type: 'page', entity_id: currentPageId, name: newKey, value: value });
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties); // Assumes this function is available in this module
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error renaming property key:', error); alert('Failed to rename property');
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

export async function renameArrayPropertyKey(oldKey, newKey, arrayIndex) { // arrayIndex was missing
    if (!currentPageId) return;
    try {
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const values = properties[oldKey];
        if (!Array.isArray(values)) { console.warn(`Property ${oldKey} is not an array for renaming`); return; }
        await propertiesAPI.deleteProperty('page', currentPageId, oldKey);
        for (const value of values) {
            await propertiesAPI.setProperty({ entity_type: 'page', entity_id: currentPageId, name: newKey, value: value });
        }
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error renaming array property key:', error); alert('Failed to rename property');
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
         if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

export async function updateArrayPropertyValue(key, arrayIndex, newValue) {
    if (!currentPageId) return;
    try {
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const values = properties[key];
        if (!Array.isArray(values) || arrayIndex >= values.length) { console.warn(`Invalid array property update: ${key}[${arrayIndex}]`); return; }
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        for (let i = 0; i < values.length; i++) {
            const value = i === arrayIndex ? newValue : values[i];
            await propertiesAPI.setProperty({ entity_type: 'page', entity_id: currentPageId, name: key, value: value });
        }
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error updating array property value:', error); alert('Failed to update property value');
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

export async function deleteArrayPropertyValue(key, arrayIndex) {
    if (!currentPageId) return;
    try {
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        const values = properties[key];
        if (!Array.isArray(values) || arrayIndex >= values.length) { console.warn(`Invalid array property deletion: ${key}[${arrayIndex}]`); return; }
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        const remainingValues = values.filter((_, i) => i !== arrayIndex);
        for (const value of remainingValues) {
            await propertiesAPI.setProperty({ entity_type: 'page', entity_id: currentPageId, name: key, value: value });
        }
        const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(updatedProperties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error deleting array property value:', error); alert('Failed to delete property value');
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    }
}

export async function addPageProperty(key, value) {
    if (!currentPageId) return;
    try {
        await propertiesAPI.setProperty({ entity_type: 'page', entity_id: currentPageId, name: key, value: value });
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error adding page property:', error); alert('Failed to add property');
    }
}

export async function updatePageProperty(key, value) {
    if (!currentPageId) return;
    try {
        await propertiesAPI.setProperty({ entity_type: 'page', entity_id: currentPageId, name: key, value: value });
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error updating page property:', error); alert('Failed to update property');
    }
}

export async function deletePageProperty(key) {
    if (!currentPageId) return;
    try {
        await propertiesAPI.deleteProperty('page', currentPageId, key);
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        displayPageProperties(properties);
        if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
            ui.renderPageInlineProperties(properties, ui.domRefs.pagePropertiesContainer);
        }
    } catch (error) {
        console.error('Error deleting page property:', error); alert('Failed to delete property');
    }
}

/**
 * Waits for sjcl library to be available with full functionality
 */
function waitForSjcl(maxAttempts = 20) {
    return new Promise((resolve, reject) => {
        let attempts = 0;
        const checkSjcl = () => {
            attempts++;
            if (typeof window.sjcl !== 'undefined' && 
                window.sjcl.hash && 
                window.sjcl.hash.sha256 && 
                window.sjcl.hash.sha256.hash &&
                window.sjcl.codec &&
                window.sjcl.codec.hex) {
                resolve(window.sjcl);
            } else if (attempts >= maxAttempts) {
                console.error('SJCL check failed. Current state:', {
                    sjclExists: typeof window.sjcl !== 'undefined',
                    hasHash: window.sjcl?.hash,
                    hasSha256: window.sjcl?.hash?.sha256,
                    hasHashMethod: window.sjcl?.hash?.sha256?.hash,
                    hasCodec: window.sjcl?.codec,
                    hasHex: window.sjcl?.codec?.hex
                });
                reject(new Error('SJCL library failed to load completely after maximum attempts'));
            } else {
                setTimeout(checkSjcl, 100); // Wait 100ms and try again
            }
        };
        checkSjcl();
    });
}

/**
 * Handles setting up page encryption by prompting for a password and storing the hashed version
 */
export async function setupPageEncryption() {
    if (!currentPageId) {
        console.warn('No current page ID available for encryption setup');
        return;
    }

    try {
        // Check if page is already encrypted
        const properties = await propertiesAPI.getProperties('page', currentPageId);
        if (properties.encrypt) {
            const confirmed = await ui.showGenericConfirmModal(
                'Page Already Encrypted', 
                'This page is already encrypted. Do you want to change the encryption password?'
            );
            if (!confirmed) return;
        }

        // Prompt user for password
        const password = await ui.showGenericInputModal('Set Encryption Password', 'Enter a password to encrypt this page:');
        if (!password || password.trim() === '') {
            return; // User cancelled or entered empty password
        }

        // Confirm password
        const confirmPassword = await ui.showGenericInputModal('Confirm Password', 'Please confirm your password:');
        if (confirmPassword !== password) {
            alert('Passwords do not match. Please try again.');
            return;
        }

        // Wait for sjcl library to be available and hash the password
        try {
            const sjcl = await waitForSjcl();
            const hashedPassword = sjcl.hash.sha256.hash(password);
            const hashedPasswordHex = sjcl.codec.hex.fromBits(hashedPassword);

            // Add/update the encrypt property
            await propertiesAPI.setProperty({ 
                entity_type: 'page', 
                entity_id: currentPageId, 
                name: 'encrypt', 
                value: hashedPasswordHex 
            });

            // Refresh the properties display
            const updatedProperties = await propertiesAPI.getProperties('page', currentPageId);
            displayPageProperties(updatedProperties);
            if (ui.domRefs.pagePropertiesContainer && typeof ui.renderPageInlineProperties === 'function') {
                ui.renderPageInlineProperties(updatedProperties, ui.domRefs.pagePropertiesContainer);
            }

            alert('Page encryption has been set successfully. The page will require the password when accessed.');

        } catch (sjclError) {
            console.error('Error with SJCL library or encryption setup:', sjclError);
            alert('Encryption library not available or failed to load. Please reload the page and try again.');
        }

    } catch (error) {
        console.error('Error setting up page encryption:', error);
        alert('Failed to set up page encryption. Please try again.');
    }
}

/**
 * Sets up event listeners for the page properties modal, including the encryption icon
 */
export function setupPagePropertiesModalListeners() {
    const encryptionIcon = document.getElementById('page-encryption-icon');
    if (encryptionIcon) {
        // Remove existing listener if any
        const existingListener = encryptionIcon._encryptionClickListener;
        if (existingListener) {
            encryptionIcon.removeEventListener('click', existingListener);
        }

        // Add new listener
        const encryptionClickListener = async (e) => {
            e.preventDefault();
            e.stopPropagation();
            await setupPageEncryption();
        };

        encryptionIcon._encryptionClickListener = encryptionClickListener;
        encryptionIcon.addEventListener('click', encryptionClickListener);
    }
}
