import { currentPageId } from './state.js';
import { ui } from '../ui.js';
import { propertiesAPI } from '../api_client.js';

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
                    <input type="text" class="page-property-value" data-property="${key}" data-array-index="${index}" data-original-value="${singleValue}" value="${singleValue}" />
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
                <input type="text" class="page-property-value" data-property="${key}" data-original-value="${value || ''}" value="${value || ''}" />
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
        if (e.type === 'change' && e.target.matches('.page-property-value')) {
            const key = e.target.dataset.property;
            const newValue = e.target.value.trim();
            const originalValue = e.target.dataset.originalValue;
            const arrayIndex = e.target.dataset.arrayIndex;
            if (newValue !== originalValue) {
                if (arrayIndex !== undefined) {
                    await updateArrayPropertyValue(key, parseInt(arrayIndex), newValue);
                } else {
                    await updatePageProperty(key, newValue);
                }
                e.target.dataset.originalValue = newValue;
            }
        } else if (e.type === 'blur' && e.target.matches('.page-property-key')) {
            const originalKey = e.target.dataset.originalKey;
            const newKey = e.target.textContent.trim();
            const isArray = e.target.dataset.isArray === 'true';
            const arrayIndex = e.target.dataset.arrayIndex; // Corrected to get arrayIndex
            if (newKey !== originalKey && newKey !== '') {
                if (isArray) {
                     await renameArrayPropertyKey(originalKey, newKey, parseInt(arrayIndex)); // Pass arrayIndex
                } else {
                    await renamePropertyKey(originalKey, newKey);
                }
                e.target.dataset.originalKey = newKey;
            } else if (newKey === '') {
                e.target.textContent = originalKey;
            }
        } else if (e.type === 'keydown' && e.key === 'Enter') {
            if (e.target.matches('.page-property-value')) {
                e.target.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (e.target.matches('.page-property-key')) {
                e.target.blur();
            }
        } else if (e.type === 'click' && e.target.matches('.page-property-delete')) {
            const key = e.target.dataset.property;
            const arrayIndex = e.target.dataset.arrayIndex;
            let confirmMessage = arrayIndex !== undefined ? `Are you sure you want to delete this "${key}" value?` : `Are you sure you want to delete the property "${key}"?`;
            const confirmed = await ui.showGenericConfirmModal('Delete Property', confirmMessage); // Assumes ui.showGenericConfirmModal
            if (confirmed) {
                if (arrayIndex !== undefined) {
                    await deleteArrayPropertyValue(key, parseInt(arrayIndex));
                } else {
                    await deletePageProperty(key);
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
