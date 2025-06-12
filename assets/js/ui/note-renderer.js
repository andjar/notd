// FILE: assets/js/ui/note-renderer.js

/**
 * UI Module for rendering individual notes and their content.
 * Handles Markdown parsing, property rendering, and mode switching.
 * @module ui/note-renderer
 */

import { saveNoteImmediately } from '../app/note-actions.js';
import { attachmentsAPI } from '../api_client.js';

// --- Main Rendering Function ---

/**
 * Renders a single note object into an HTML element.
 * @param {Object} note - The note data object.
 * @param {number} [nestingLevel=0] - The current nesting level for indentation.
 * @returns {HTMLElement} The fully constructed note element.
 */
export function renderNote(note, nestingLevel = 0) {
    const noteItemEl = document.createElement('div');
    noteItemEl.className = 'note-item';
    noteItemEl.dataset.noteId = note.id;
    noteItemEl.style.setProperty('--nesting-level', nestingLevel);

    if (note.children && note.children.length > 0) noteItemEl.classList.add('has-children');
    if (note.collapsed) noteItemEl.classList.add('collapsed');

    const noteHeaderRow = document.createElement('div');
    noteHeaderRow.className = 'note-header-row';

    const controlsEl = document.createElement('div');
    controlsEl.className = 'note-controls';
    
    const bulletEl = document.createElement('span');
    bulletEl.className = 'note-bullet';
    bulletEl.dataset.noteId = note.id;
    controlsEl.appendChild(bulletEl);

    if (note.children && note.children.length > 0) {
        const arrowEl = document.createElement('span');
        arrowEl.className = 'note-collapse-arrow';
        arrowEl.innerHTML = `<i data-feather="chevron-right"></i>`;
        arrowEl.dataset.noteId = note.id;
        controlsEl.insertBefore(arrowEl, bulletEl);
    }
    noteHeaderRow.appendChild(controlsEl);

    const contentWrapperEl = document.createElement('div');
    contentWrapperEl.className = 'note-content-wrapper';
    
    const contentEl = document.createElement('div');
    contentEl.className = 'note-content rendered-mode';
    contentEl.dataset.placeholder = 'Type something...';
    contentEl.dataset.noteId = note.id;
    contentEl.dataset.rawContent = note.content || '';
    contentEl.innerHTML = parseAndRenderContent(note.content || '');
    contentWrapperEl.appendChild(contentEl);

    const propertiesEl = document.createElement('div');
    propertiesEl.className = 'inline-properties';
    renderInlineProperties(propertiesEl, note.properties);
    contentWrapperEl.appendChild(propertiesEl);

    const attachmentsEl = document.createElement('div');
    attachmentsEl.className = 'note-attachments';
    contentWrapperEl.appendChild(attachmentsEl);
    if (note.id && !String(note.id).startsWith('temp-')) {
        renderAttachments(attachmentsEl, note.id, note.has_attachments);
    }

    noteHeaderRow.appendChild(contentWrapperEl);
    noteItemEl.appendChild(noteHeaderRow);

    const childrenContainerEl = document.createElement('div');
    childrenContainerEl.className = 'note-children';
    if (note.collapsed) childrenContainerEl.style.display = 'none';
    
    if (note.children && note.children.length > 0) {
        note.children.forEach(childNote => {
            childrenContainerEl.appendChild(renderNote(childNote, nestingLevel + 1));
        });
    }
    noteItemEl.appendChild(childrenContainerEl);

    return noteItemEl;
}

// --- Content Parsing and Mode Switching ---

export function parseAndRenderContent(rawContent) {
    if (typeof rawContent !== 'string') return '';
    let html = rawContent;

    const taskRegex = /^(TODO|DOING|DONE|WAITING|CANCELLED|NLR|SOMEDAY)\s+/;
    const taskMatch = html.match(taskRegex);
    if (taskMatch) {
        const status = taskMatch[1];
        const taskContent = html.substring(status.length + 1);
        const isChecked = ['DONE', 'CANCELLED', 'NLR'].includes(status);
        const isDisabled = ['CANCELLED', 'NLR'].includes(status);
        
        return `
            <div class="task-container ${status.toLowerCase()}">
                <div class="task-checkbox-container">
                    <input type="checkbox" class="task-checkbox" data-marker-type="${status}" ${isChecked ? 'checked' : ''} ${isDisabled ? 'disabled' : ''} />
                </div>
                <div class="task-content ${status.toLowerCase()}-text">${parseMarkdown(taskContent)}</div>
            </div>
        `;
    }

    return parseMarkdown(html);
}

function parseMarkdown(text) {
    let html = text.replace(/</g, "<").replace(/>/g, ">");
    const propertyLines = [];
    html = html.replace(/^\{.*::.*\}$/gm, (match) => {
        propertyLines.push(match);
        return `<!--PROP_PLACEHOLDER_${propertyLines.length - 1}-->`;
    });

    html = html.replace(/\[\[(.*?)\]\]/g, `<a href="#" class="page-link" data-page-name="$1">$1</a>`);
    html = html.replace(/!\{\{(.*?)}}/g, `<div class="transclusion-placeholder" data-block-ref="$1">Loading...</div>`);
    html = html.replace(/SQL\{([^}]+)\}/g, (match, query) => `<div class="sql-query-placeholder" data-sql-query="${query.replace(/"/g, '"')}">Loading Query...</div>`);

    if (typeof marked !== 'undefined') {
        html = marked.parse(html, { breaks: true, gfm: true });
    } else {
        html = html.replace(/\n/g, '<br>');
    }

    html = html.replace(/<!--PROP_PLACEHOLDER_(\d+)-->/g, (match, index) => propertyLines[parseInt(index, 10)] || '');
    return html;
}

export function switchToEditMode(contentEl) {
    if (contentEl.classList.contains('edit-mode')) return;

    contentEl.classList.remove('rendered-mode');
    contentEl.classList.add('edit-mode');
    contentEl.contentEditable = 'true';
    contentEl.innerHTML = '';
    contentEl.textContent = contentEl.dataset.rawContent || '';
    contentEl.focus();

    const range = document.createRange();
    const sel = window.getSelection();
    range.selectNodeContents(contentEl);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);

    const handleBlur = () => {
        const blurEvent = new CustomEvent('note-blur', { bubbles: true });
        contentEl.dispatchEvent(blurEvent);
    };
    contentEl.addEventListener('blur', handleBlur, { once: true });
}

export function switchToRenderedMode(contentEl) {
    if (contentEl.classList.contains('rendered-mode')) return;

    const noteItem = contentEl.closest('.note-item');
    if (noteItem) {
        saveNoteImmediately(noteItem);
    }
    
    const rawText = getRawTextWithNewlines(contentEl);
    contentEl.dataset.rawContent = rawText;

    contentEl.classList.remove('edit-mode');
    contentEl.classList.add('rendered-mode');
    contentEl.contentEditable = 'false';
    contentEl.innerHTML = parseAndRenderContent(rawText);
}

// --- Utility & Sub-Component Rendering ---

export function getRawTextWithNewlines(element) {
    // This is a simplified version. A more complex one might be needed
    // to handle divs and brs from pasting, but this works for standard typing.
    return element.textContent || '';
}

export function renderInlineProperties(container, properties) {
    if (!container) return;
    container.innerHTML = '';
    if (!properties || Object.keys(properties).length === 0) return;

    Object.entries(properties).forEach(([key, values]) => {
        (Array.isArray(values) ? values : [values]).forEach(prop => {
            const colonCount = prop.colon_count || 2;
            const behavior = (window.PROPERTY_BEHAVIORS_BY_COLON_COUNT || {})[colonCount] || { visible_view: true };
            if (!behavior.visible_view) return;

            const propEl = document.createElement('span');
            propEl.className = 'property-inline';
            propEl.textContent = `${key}:: ${prop.value}`;
            container.appendChild(propEl);
        });
    });
}

export async function renderAttachments(container, noteId, hasAttachments) {
    if (!hasAttachments) {
        container.innerHTML = '';
        container.style.display = 'none';
        return;
    }
    container.style.display = 'block';
    container.innerHTML = '<em>Loading attachments...</em>';

    try {
        const attachments = await attachmentsAPI.getNoteAttachments(noteId);
        container.innerHTML = '';
        if (attachments.length === 0) {
            container.style.display = 'none';
            return;
        }

        attachments.forEach(att => {
            const attEl = document.createElement('div');
            attEl.className = 'note-attachment-item';
            // ... (attachment item rendering logic) ...
            container.appendChild(attEl);
        });

        if (typeof feather !== 'undefined') feather.replace();
    } catch (error) {
        console.error(`Failed to load attachments for note ${noteId}:`, error);
        container.innerHTML = '<small class="error-message">Could not load attachments.</small>';
    }
}

// Delegated event listener setup for this module
export function initializeDelegatedNoteEventListeners(container) {
    if (!container) return;
    container.addEventListener('click', (e) => {
        const content = e.target.closest('.note-content.rendered-mode');
        if (content && !e.target.closest('a, button, input')) {
            switchToEditMode(content);
        }
    });
}