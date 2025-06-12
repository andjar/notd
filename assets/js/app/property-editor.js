import { currentPageId, getPageCache, setPageCache } from './state.js';
import { ui } from '../ui.js';
import { pagesAPI } from '../api_client.js';

let pageContentForModal = '';

/**
 * Parses properties from a content string into a structured object.
 * This is a client-side utility for the property editor.
 * @param {string} content - The content string to parse.
 * @returns {Object} A structured properties object.
 */
function parsePropertiesFromContent(content) {
    const properties = {};
    if (!content) return properties;

    const regex = /\{([^:}]+):(:{2,})([^}]+)\}/g;
    let match;
    while ((match = regex.exec(content)) !== null) {
        const key = match[1].trim();
        const value = match[3].trim();
        const weight = match[2].length; // Number of colons

        if (!properties[key]) {
            properties[key] = [];
        }
        properties[key].push({ value, internal: weight > 2 });
    }
    return properties;
}

/**
 * Rebuilds the content string from a properties object.
 * This function assumes a simple one-property-per-line format.
 * @param {string} originalContent - The original content to preserve non-property text.
 * @param {Object} properties - The structured properties object.
 * @returns {string} The updated content string.
 */
function rebuildContentWithProperties(originalContent, properties) {
    // Strip old properties from the content
    let cleanContent = (originalContent || '').replace(/\{[^:}]+:{2,}[^}]+\}\n?/g, '').trim();

    let propertiesString = '';
    for (const [key, instances] of Object.entries(properties)) {
        for (const instance of instances) {
            const colons = instance.internal ? ':::' : '::';
            propertiesString += `{${key}${colons}${instance.value}}\n`;
        }
    }

    return (cleanContent ? cleanContent + '\n\n' : '') + propertiesString.trim();
}

/**
 * Displays page properties in the modal. Fetches and stores the page's content.
 * @param {Object} properties - Properties object for the current page.
 */
export async function displayPageProperties(properties) {
    // Fetch full page data to get the `content` string
    const pageData = getPageCache(currentPageName.value);
    if (!pageData) {
        console.error("Could not find page data in cache for property editor.");
        ui.domRefs.pagePropertiesList.innerHTML = '<p>Error: Page data not found.</p>';
        return;
    }
    pageContentForModal = pageData.content || '';

    // Proceed to render the modal UI
    const pagePropertiesList = ui.domRefs.pagePropertiesList;
    if (!pagePropertiesList) return;
    
    pagePropertiesList.innerHTML = '';
    let hasVisibleProperties = false;
    
    Object.entries(properties).forEach(([key, instances]) => {
        if (Array.isArray(instances)) {
            instances.forEach((instance, index) => {
                hasVisibleProperties = true;
                const propItem = document.createElement('div');
                propItem.className = 'page-property-item';
                propItem.innerHTML = `
                    <span class="page-property-key" contenteditable="true" data-key="${key}" data-index="${index}">${key}</span>
                    <span class="page-property-separator">:</span>
                    <input type="text" class="page-property-value" value="${instance.value}" data-key="${key}" data-index="${index}" />
                    <button class="page-property-delete" data-key="${key}" data-index="${index}" title="Delete this value">×</button>
                `;
                pagePropertiesList.appendChild(propItem);
            });
        }
    });

    if (!hasVisibleProperties) {
        pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
    }
}

async function _updatePageContent(newContent) {
    try {
        ui.updateSaveStatusIndicator('pending');
        const updatedPage = await pagesAPI.updatePage(currentPageId.value, { content: newContent });
        
        // Update cache with the new, confirmed page data
        const currentCached = getPageCache(updatedPage.name) || {};
        setPageCache(updatedPage.name, { ...currentCached, ...updatedPage, timestamp: Date.now() });

        pageContentForModal = updatedPage.content || '';
        
        // Re-render properties in the modal and on the page
        displayPageProperties(updatedPage.properties || {});
        ui.renderPageInlineProperties(updatedPage.properties || {}, ui.domRefs.pagePropertiesContainer);
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error("Failed to update page content:", error);
        alert("Failed to save property changes.");
        ui.updateSaveStatusIndicator('error');
    }
}

export async function addPageProperty(key, value, isInternal = false) {
    const properties = parsePropertiesFromContent(pageContentForModal);
    if (!properties[key]) {
        properties[key] = [];
    }
    properties[key].push({ value, internal: isInternal });
    const newContent = rebuildContentWithProperties(pageContentForModal, properties);
    await _updatePageContent(newContent);
}

export async function deletePageProperty(key, index) {
    const properties = parsePropertiesFromContent(pageContentForModal);
    if (properties[key] && properties[key][index] !== undefined) {
        properties[key].splice(index, 1);
        if (properties[key].length === 0) {
            delete properties[key];
        }
    }
    const newContent = rebuildContentWithProperties(pageContentForModal, properties);
    await _updatePageContent(newContent);
}

export async function updatePageProperty(key, index, newValue) {
    const properties = parsePropertiesFromContent(pageContentForModal);
    if (properties[key] && properties[key][index] !== undefined) {
        properties[key][index].value = newValue;
    }
    const newContent = rebuildContentWithProperties(pageContentForModal, properties);
    await _updatePageContent(newContent);
}

export async function renamePagePropertyKey(oldKey, index, newKey) {
    const properties = parsePropertiesFromContent(pageContentForModal);
    if (properties[oldKey] && properties[oldKey][index] !== undefined) {
        const instance = properties[oldKey].splice(index, 1)[0];
        if (properties[oldKey].length === 0) {
            delete properties[oldKey];
        }
        if (!properties[newKey]) {
            properties[newKey] = [];
        }
        properties[newKey].push(instance);
    }
    const newContent = rebuildContentWithProperties(pageContentForModal, properties);
    await _updatePageContent(newContent);
}

// Event handler for the properties modal
const propertyModalListener = async (e) => {
    const target = e.target;

    // Handle key changes on blur
    if (e.type === 'blur' && target.matches('.page-property-key')) {
        const oldKey = target.dataset.key;
        const index = parseInt(target.dataset.index, 10);
        const newKey = target.textContent.trim();
        if (newKey && newKey !== oldKey) {
            await renamePagePropertyKey(oldKey, index, newKey);
        } else {
            target.textContent = oldKey; // Revert if empty or unchanged
        }
    }

    // Handle value changes on change (after blur or enter)
    if (e.type === 'change' && target.matches('.page-property-value')) {
        const key = target.dataset.key;
        const index = parseInt(target.dataset.index, 10);
        const newValue = target.value.trim();
        await updatePageProperty(key, index, newValue);
    }

    // Handle delete button clicks
    if (e.type === 'click' && target.matches('.page-property-delete')) {
        const key = target.dataset.key;
        const index = parseInt(target.dataset.index, 10);
        if (confirm(`Are you sure you want to delete this value for "${key}"?`)) {
            await deletePageProperty(key, index);
        }
    }

    // Handle adding a new property
    if (e.type === 'click' && target.id === 'add-page-property-btn') {
        const newKey = prompt("Enter new property key:");
        if (newKey && newKey.trim()) {
            const newValue = prompt(`Enter value for "${newKey}":`);
            if (newValue !== null) {
                await addPageProperty(newKey.trim(), newValue.trim());
            }
        }
    }
};

// This function should be called once when the UI is initialized
export function initPropertyEditor() {
    const list = ui.domRefs.pagePropertiesList;
    const addBtn = ui.domRefs.addPagePropertyBtn;
    if (list) {
        list.addEventListener('blur', propertyModalListener, true);
        list.addEventListener('change', propertyModalListener, true);
        list.addEventListener('click', propertyModalListener);
    }
    if (addBtn) {
        addBtn.addEventListener('click', propertyModalListener);
    }
}