/**
 * Keyboard shortcuts that work anywhere on the page.
 *
 *   Ctrl+Alt+Left / Right   previous / next day (on a journal page)
 *   Ctrl+K                  focus the search box
 *   Ctrl+Z / Ctrl+Shift+Z   undo / redo outline changes (outside text fields)
 *
 * (Ctrl+Space, Shift+Space and Ctrl+B are set up by the modules they open.)
 *
 * @module app/shortcuts
 */

import * as outline from './outline.js';
import { page } from './page-state.js';
import { isDateName, shiftDate, pageUrl } from '../utils.js';

export function initShortcuts() {
    document.addEventListener('keydown', (e) => {
        const mod = e.ctrlKey || e.metaKey;
        if (e.ctrlKey && e.altKey && (e.key === 'ArrowRight' || e.key === 'ArrowLeft') && isDateName(page.name)) {
            e.preventDefault();
            window.location.href = pageUrl(shiftDate(page.name, e.key === 'ArrowRight' ? 1 : -1));
            return;
        }
        if (mod && !e.altKey && !e.shiftKey && e.key === 'k') {
            e.preventDefault();
            const input = document.getElementById('global-search-input');
            input?.focus();
            input?.select();
            return;
        }
        if (mod && !e.altKey && (e.key === 'z' || e.key === 'Z' || e.key === 'y')) {
            const el = document.activeElement;
            if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return; // notes handle their own
            e.preventDefault();
            if (e.key === 'y' || e.shiftKey) outline.redo(); else outline.undo();
        }
    });
}
