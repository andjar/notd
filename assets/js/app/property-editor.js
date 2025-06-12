import { currentPageId } from './state.js';
import { ui } from '../ui.js';
import { pagesAPI } from '../api_client.js';

let pageContentForModal = '';

function parsePropertiesFromContent(content) {
    const properties = {};
    if (!content) return properties;
    const regex = /\{([^:}]+):(:{2,})([^}]+)\}/g;
    let match;
    while ((match = regex.exec(content)) !== null) {
        const key = match[1].trim();
        const value = match[3].trim();
        const weight = match[2].length;
        if (!properties[key]) {
            properties[key] = [];
        }
        properties[key].push({ value, internal: weight > 2 });
    }
    return properties;
}

function rebuildContentWithProperties(originalContent, properties) {
    let cleanContent = (originalContent || '').replace(/\{([^:}]+):(:{2,})([^}]+)\}\n?/g, '').trim();
    let propertiesString = '';
    for (const [key, instances] of Object.entries(properties)) {
        if (!instances) continue;
        for (const instance of instances) {
            const colons = instance.internal ? ':::' : '::';
            propertiesString += `{${key}${colons}${instance.value}}\n`;
        }
    }
    // Only add a newline if there's content AND properties.
    const separator = (cleanContent && propertiesString) ? '\n\n' : '';
    return cleanContent + separator + propertiesString.trim();
}

async function _updatePageContent(newContent) {
    try {
        ui.updateSaveStatusIndicator('pending');
        const updatedPage = await pagesAPI.updatePage(currentPageId, { content: newContent });
        pageContentForModal = updatedPage.content || '';
        // The properties are now derived from the returned content, so we pass the new properties to the UI.
        displayPageProperties(updatedPage.properties || {});
        ui.renderPageInlineProperties(updatedPage.properties || {}, ui.domRefs.pagePropertiesContainer);
        ui.updateSaveStatusIndicator('saved');
    } catch (error) {
        console.error("Failed to update page content:", error);
        alert("Failed to save property changes.");
        ui.updateSaveStatusIndicator('error');
    }
}

// --- CORE CRUD Functions for Properties ---

async function addPageProperty(key, value, isInternal = false) {
    const properties = parsePropertiesFromContent(pageContentForModal);
    if (!properties[key]) {
        properties[key] = [];
    }
    properties[key].push({ value, internal: isInternal });
    const newContent = rebuildContentWithProperties(pageContentForModal, properties);
    await _updatePageContent(newContent);
}

async function deletePageProperty(key, index) {
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

async function updatePageProperty(key, index, newValue) {
    const properties = parsePropertiesFromContent(pageContentForModal);
    if (properties[key] && properties[key][index] !== undefined) {
        properties[key][index].value = newValue;
    }
    const newContent = rebuildContentWithProperties(pageContentForModal, properties);
    await _updatePageContent(newContent);
}

async function renamePropertyKey(oldKey, index, newKey) {
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

// --- UI Rendering and Event Handling ---

export async function displayPageProperties(properties) {
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
    
    pagePropertiesList.innerHTML = '';
    let hasVisibleProperties = false;
    
    Object.entries(properties).forEach(([key, instances]) => {
        if (!Array.isArray(instances)) return;
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
    });

    if (!hasVisibleProperties) {
        pagePropertiesList.innerHTML = '<p class="no-properties-message">No properties set for this page.</p>';
    }
}

const propertyModalListener = async (e) => {
    const target = e.target;
    
    if (e.type === 'blur' && target.matches('.page-property-key')) {
        const oldKey = target.dataset.key;
        const index = parseInt(target.dataset.index, 10);
        const newKey = target.textContent.trim();
        if (newKey && newKey !== oldKey) {
            await renamePropertyKey(oldKey, index, newKey);
        } else {
            target.textContent = oldKey;
        }
    }

    if (e.type === 'change' && target.matches('.page-property-value')) {
        const key = target.dataset.key;
        const index = parseInt(target.dataset.index, 10);
        const newValue = target.value.trim();
        await updatePageProperty(key, index, newValue);
    }

    if (e.type === 'click' && target.matches('.page-property-delete')) {
        const key = target.dataset.key;
        const index = parseInt(target.dataset.index, 10);
        if (confirm(`Are you sure you want to delete this value for "${key}"?`)) {
            await deletePageProperty(key, index);
        }
    }

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

export function initPropertyEditor() {
    const list = ui.domRefs.pagePropertiesList;
    const addBtn = ui.domRefs.addPagePropertyBtn;
    if (list) {
        list.addEventListener('blur', propertyModalListener, true);
        list.addEventListener('change', propertyModalListener, true);
        list.addEventListener('click', propertyModalListener);
        list.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.matches('.page-property-key, .page-property-value')) {
                e.preventDefault();
                e.target.blur();
            }
        });
    }
    if (addBtn) {
        addBtn.addEventListener('click', propertyModalListener);
    }
}
