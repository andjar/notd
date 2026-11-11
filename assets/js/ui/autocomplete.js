/**
 * Suggestions while typing in a note:
 *
 *   [[par      page names; choosing one completes the link
 *   /mee       note templates; choosing one inserts its text
 *
 * One popup serves both. ui/note-events.js feeds it the input and key events
 * of the note being edited.
 *
 * @module ui/autocomplete
 */

import { templatesAPI } from '../api_client.js';
import { pageNames, matchNames } from '../app/page-names.js';
import { readText, caretOffset, replaceRange } from './note-view.js';

let popup = null;
let items = [];        // [{label, hint, apply(element)}]
let selected = 0;
let target = null;     // the .note-content being edited
let generation = 0;
let templates = null;

function loadTemplates() {
    if (!templates) {
        templates = templatesAPI.list('note').catch((error) => {
            templates = null;
            console.warn('[templates] could not load templates', error);
            return [];
        });
    }
    return templates;
}

/** What is being typed right before the caret, if it calls for suggestions. */
function contextAt(element) {
    const caret = caretOffset(element);
    if (caret === null) return null;
    const text = readText(element);
    const before = text.slice(0, caret);

    const open = before.lastIndexOf('[[');
    if (open !== -1) {
        const query = before.slice(open + 2);
        if (!/[\]\n]/.test(query) && query.length <= 100) {
            // Brackets are closed automatically while typing: replace those too.
            const end = text.startsWith(']]', caret) ? caret + 2 : caret;
            return { kind: 'page', query, start: open, end };
        }
    }

    const slash = /(^|\s)\/([\w-]*)$/.exec(before);
    if (slash) {
        return { kind: 'template', query: slash[2], start: caret - slash[2].length - 1, end: caret };
    }
    return null;
}

async function suggestionsFor(context) {
    if (context.kind === 'page') {
        const names = await pageNames();
        const matches = matchNames(names, context.query, 8);
        const typed = context.query.trim();
        const result = matches.map((name) => ({ label: name, text: `[[${name}]]` }));
        if (typed && !names.some((n) => n.toLowerCase() === typed.toLowerCase())) {
            result.push({ label: typed, hint: 'new page', text: `[[${typed}]]` });
        }
        return result;
    }
    const all = await loadTemplates();
    const q = context.query.toLowerCase();
    return all
        .filter((t) => t.name.toLowerCase().includes(q))
        .slice(0, 8)
        .map((t) => ({ label: t.name, hint: 'template', text: t.content || '' }));
}

function ensurePopup() {
    if (popup) return popup;
    popup = document.createElement('ul');
    popup.className = 'autocomplete-popup';
    popup.setAttribute('role', 'listbox');
    popup.hidden = true;
    // mousedown, not click: the note must keep the focus.
    popup.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const li = e.target.closest('li[data-index]');
        if (li) choose(Number(li.dataset.index));
    });
    document.body.appendChild(popup);
    return popup;
}

function caretRect(element) {
    const selection = window.getSelection();
    if (selection && selection.rangeCount) {
        const rects = selection.getRangeAt(0).getClientRects();
        if (rects.length) return rects[rects.length - 1];
    }
    return element.getBoundingClientRect();
}

function draw() {
    const el = ensurePopup();
    el.replaceChildren(...items.map((item, index) => {
        const li = document.createElement('li');
        li.dataset.index = index;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', index === selected ? 'true' : 'false');
        if (index === selected) li.className = 'selected';
        const label = document.createElement('span');
        label.textContent = item.label;
        li.appendChild(label);
        if (item.hint) {
            const hint = document.createElement('small');
            hint.textContent = item.hint;
            li.appendChild(hint);
        }
        return li;
    }));
    el.hidden = false;
    const rect = caretRect(target);
    const width = el.offsetWidth;
    const height = el.offsetHeight;
    const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
    const below = rect.bottom + 4;
    const top = below + height > window.innerHeight - 8 && rect.top - height - 4 > 0 ? rect.top - height - 4 : below;
    el.style.left = `${left}px`;
    el.style.top = `${top}px`;
}

function choose(index) {
    const item = items[index];
    const element = target;
    if (!item || !element) return hide();
    const context = contextAt(element);
    hide();
    if (!context) return;
    replaceRange(element, context.start, context.end, item.text);
}

export function isOpen() {
    return !!popup && !popup.hidden;
}

export function hide() {
    generation++;
    if (popup) popup.hidden = true;
    items = [];
    target = null;
}

/** Call after every input in a note that is being edited. */
export async function update(element) {
    const context = contextAt(element);
    if (!context) return hide();
    const mine = ++generation;
    const found = await suggestionsFor(context);
    if (mine !== generation) return; // something newer happened meanwhile
    if (!found.length || document.activeElement !== element) return hide();
    generation = mine;
    target = element;
    items = found;
    selected = 0;
    draw();
}

/**
 * Call on keydown in a note, before anything else.
 * @returns {boolean} true if the key was used by the popup
 */
export function handleKeyDown(event) {
    if (!isOpen()) return false;
    switch (event.key) {
        case 'ArrowDown':
        case 'ArrowUp':
            event.preventDefault();
            selected = (selected + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            draw();
            return true;
        case 'Enter':
        case 'Tab':
            event.preventDefault();
            choose(selected);
            return true;
        case 'Escape':
            event.preventDefault();
            hide();
            return true;
        default:
            return false;
    }
}
