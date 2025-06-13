/**
 * @file Handles template functionality including autocomplete and insertion
 */

import { templatesAPI } from '../api_client.js';
import { ui } from '../ui.js';

class TemplateAutocomplete {
    constructor() {
        this.templates = [];
        this.dropdownEl = null;
        this.activeTarget = null;
        this.selectedIndex = -1;
        this.init();
    }

    async init() {
        console.log('[Templates] Initializing template autocomplete');
        this.dropdownEl = document.createElement('div');
        this.dropdownEl.className = 'template-autocomplete-dropdown';
        this.dropdownEl.style.display = 'none';
        this.dropdownEl.style.position = 'absolute';
        this.dropdownEl.style.border = '1px solid #ccc';
        this.dropdownEl.style.backgroundColor = 'white';
        this.dropdownEl.style.zIndex = '1000';
        this.dropdownEl.style.maxHeight = '200px';
        this.dropdownEl.style.overflowY = 'auto';
        document.body.appendChild(this.dropdownEl);

        // Add global click listener to hide dropdown
        document.addEventListener('click', (e) => {
            if (this.dropdownEl && !this.dropdownEl.contains(e.target) && this.activeTarget !== e.target) {
                this.hide();
            }
        }, true);

        // Add keyboard navigation
        document.addEventListener('keydown', this.handleKeyDown.bind(this), true);

        await this.loadTemplates();
    }

    async loadTemplates() {
        console.log('[Templates] Loading templates');
        try {
            const response = await templatesAPI.getTemplates('note');
            this.templates = Array.isArray(response) ? response : (response.data || []);
            console.log('[Templates] Templates loaded:', this.templates);
            
            if (this.templates.length === 0) {
                this.templates = [{name: "No templates available", content: ""}];
            }
        } catch (error) {
            console.error('[Templates] Error loading templates:', error);
            this.templates = [{name: "Error loading templates", content: ""}];
        }
    }

    handleKeyDown(e) {
        if (!this.dropdownEl || this.dropdownEl.style.display === 'none') return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                e.stopPropagation();
                if (this.templates && this.templates.length > 0 && this.selectedIndex < this.templates.length - 1) {
                    const items = this.dropdownEl.querySelectorAll('li');
                    if (this.selectedIndex > -1 && items[this.selectedIndex]) {
                        items[this.selectedIndex].classList.remove('selected');
                    }
                    this.selectedIndex++;
                    if (items[this.selectedIndex]) {
                        items[this.selectedIndex].classList.add('selected');
                    }
                }
                break;

            case 'ArrowUp':
                e.preventDefault();
                e.stopPropagation();
                if (this.templates && this.templates.length > 0 && this.selectedIndex > 0) {
                    const items = this.dropdownEl.querySelectorAll('li');
                    if (items[this.selectedIndex]) {
                        items[this.selectedIndex].classList.remove('selected');
                    }
                    this.selectedIndex--;
                    if (items[this.selectedIndex]) {
                        items[this.selectedIndex].classList.add('selected');
                    }
                }
                break;

            case 'Enter':
                e.preventDefault();
                e.stopPropagation();
                if (this.selectedIndex !== -1 && this.templates && this.templates[this.selectedIndex]) {
                    const templateName = this.templates[this.selectedIndex].name;
                    this.insertTemplate(this.activeTarget, templateName);
                }
                break;

            case 'Escape':
                e.preventDefault();
                e.stopPropagation();
                this.hide();
                break;
        }
    }

    insertTemplate(contentDiv, templateName) {
        if (!contentDiv || !templateName) return;

        try {
            const selection = window.getSelection();
            if (!selection.rangeCount) return;
            
            const range = selection.getRangeAt(0);
            const cursorPosition = range.startOffset;
            const text = contentDiv.textContent || '';
            
            // Find the last '/' before the cursor
            const lastSlashIndex = text.lastIndexOf('/', cursorPosition);
            if (lastSlashIndex === -1) return;

            // Find the template object
            const template = this.templates.find(t => t.name === templateName);
            if (!template) {
                console.error(`[Templates] Template "${templateName}" not found`);
                this.hide();
                return;
            }

            const templateContent = template.content || '';
            const beforeSlash = text.substring(0, lastSlashIndex);
            const afterCursor = text.substring(cursorPosition);
            const newText = beforeSlash + templateContent + afterCursor;

            // Update the content
            contentDiv.textContent = newText;

            // Set cursor position after the inserted template
            const newCursorPosition = beforeSlash.length + templateContent.length;
            const newRange = document.createRange();

            if (contentDiv.childNodes.length === 0) {
                contentDiv.appendChild(document.createTextNode(''));
            }

            const nodeToSetCursorIn = (contentDiv.firstChild && contentDiv.firstChild.nodeType === Node.TEXT_NODE) 
                ? contentDiv.firstChild 
                : contentDiv;

            const maxOffset = nodeToSetCursorIn.nodeType === Node.TEXT_NODE 
                ? nodeToSetCursorIn.textContent.length 
                : nodeToSetCursorIn.childNodes.length;
            
            newRange.setStart(nodeToSetCursorIn, Math.min(newCursorPosition, maxOffset));
            newRange.setEnd(nodeToSetCursorIn, Math.min(newCursorPosition, maxOffset));
            selection.removeAllRanges();
            selection.addRange(newRange);

            // Update dataset for save mechanism
            contentDiv.dataset.rawContent = contentDiv.textContent;

            // Trigger input event to ensure note content is saved
            contentDiv.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (error) {
            console.error('[Templates] Error inserting template:', error);
        } finally {
            this.hide();
        }
    }

    show(targetElement) {
        this.activeTarget = targetElement;
        this.selectedIndex = -1;
        
        if (!this.dropdownEl) {
            console.error('[Templates] Dropdown element not initialized');
            return;
        }

        if (!this.templates || this.templates.length === 0) {
            this.dropdownEl.innerHTML = '<li>Loading templates...</li>';
        } else {
            this.dropdownEl.innerHTML = '';
            const ul = document.createElement('ul');
            ul.style.listStyle = 'none';
            ul.style.margin = '0';
            ul.style.padding = '5px';
            
            const items = this.templates.map((template, index) => {
                const li = document.createElement('li');
                li.textContent = template.name;
                li.style.padding = '5px';
                li.style.cursor = 'pointer';
                li.dataset.index = index;
                li.dataset.templateName = template.name;
                return li;
            });

            items.forEach((li, index) => {
                li.addEventListener('mouseover', () => {
                    if (this.selectedIndex !== -1) {
                        items[this.selectedIndex].classList.remove('selected');
                    }
                    this.selectedIndex = index;
                    li.classList.add('selected');
                });

                li.addEventListener('mouseout', () => {
                    if (this.selectedIndex !== index) {
                        li.classList.remove('selected');
                    }
                });

                li.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const templateName = li.dataset.templateName;
                    if (templateName) {
                        this.insertTemplate(this.activeTarget, templateName);
                    }
                });

                ul.appendChild(li);
            });

            this.dropdownEl.appendChild(ul);

            if (items.length > 0) {
                this.selectedIndex = 0;
                items[0].classList.add('selected');
            }
        }

        const rect = targetElement.getBoundingClientRect();
        this.dropdownEl.style.top = `${rect.bottom + window.scrollY}px`;
        this.dropdownEl.style.left = `${rect.left + window.scrollX}px`;
        this.dropdownEl.style.display = 'block';
    }

    hide() {
        if (this.dropdownEl) {
            this.dropdownEl.style.display = 'none';
        }
        this.activeTarget = null;
        this.selectedIndex = -1;
    }
}

// Create and export a singleton instance
export const templateAutocomplete = new TemplateAutocomplete();

// Handle note input for template triggers
export function handleNoteInput(e) {
    if (!e.target.matches('.note-content.edit-mode')) return;

    const contentDiv = e.target;
    const text = contentDiv.textContent || '';
    const selection = window.getSelection();
    if (!selection.rangeCount) return;

    const range = selection.getRangeAt(0);
    const cursorPosition = range.startOffset;
    const isDropdownVisible = templateAutocomplete.dropdownEl.style.display === 'block';

    let shouldShowDropdown = false;
    let shouldHideDropdown = false;

    // Check if we should show/hide the dropdown
    if (cursorPosition > 0) {
        const charBeforeCursor = text.charAt(cursorPosition - 1);
        if (charBeforeCursor === '/') {
            shouldShowDropdown = true;
        } else if (isDropdownVisible) {
            const lastSlashIndex = text.lastIndexOf('/', cursorPosition);
            if (lastSlashIndex !== -1) {
                const textAfterSlash = text.substring(lastSlashIndex + 1, cursorPosition);
                if (textAfterSlash.match(/^[a-zA-Z0-9_]*$/)) {
                    // Valid template name characters, keep dropdown open
                    shouldShowDropdown = true;
                } else {
                    shouldHideDropdown = true;
                }
            } else {
                shouldHideDropdown = true;
            }
        }
    } else if (isDropdownVisible) {
        shouldHideDropdown = true;
    }

    if (text.length === 0 && isDropdownVisible) {
        shouldHideDropdown = true;
    }

    if (shouldShowDropdown && !isDropdownVisible) {
        templateAutocomplete.show(contentDiv);
    } else if (shouldHideDropdown && isDropdownVisible) {
        templateAutocomplete.hide();
    }
}

// Initialize template functionality
export function initializeTemplateHandling() {
    console.log('[Templates] Initializing template handling');
    document.addEventListener('input', handleNoteInput);
} 