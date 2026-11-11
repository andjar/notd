/**
 * Entry point of the page view. Wires the modules together and loads the page.
 *
 *   app/outline.js        the notes: model, outbox, undo        (the only writer)
 *   ui/note-*.js          the outline on screen and its events
 *   app/page-*.js         the page around the outline
 *   app/search.js etc.    sidebar tools
 *
 * Alpine is used only for the small reactive widgets around the outline
 * (sidebars, calendar, splash screen); the outline itself is plain DOM.
 *
 * @module app
 */

import { splashScreen } from './app/splash-screen.js';
import sidebarComponent from './app/sidebar-component.js';
import calendarComponent from './app/calendar-component.js';
import { loadCurrentPage } from './app/page-loader.js';
import { initPageActions, pageLoaded } from './app/page-actions.js';
import { initSearch } from './app/search.js';
import { initBacklinks } from './app/backlinks.js';
import { initShortcuts } from './app/shortcuts.js';
import { addRootNote } from './app/note-actions.js';
import { initNoteEvents } from './ui/note-events.js';
import { setSaveStatus } from './ui/note-elements.js';
import { renderIcons } from './utils.js';

document.addEventListener('alpine:init', () => {
    // <i x-feather="expression"> draws the named Feather icon and follows the expression.
    Alpine.directive('feather', (el, { expression }, { evaluate, effect }) => {
        effect(() => {
            const icon = typeof feather !== 'undefined' ? feather.icons[evaluate(expression)] : null;
            if (icon) el.innerHTML = icon.toSvg();
        });
    });
    Alpine.data('splashScreen', splashScreen);
    Alpine.data('sidebarComponent', sidebarComponent);
    Alpine.data('calendarComponent', calendarComponent);
});

function start() {
    renderIcons(document);
    setSaveStatus('saved');
    initNoteEvents();
    initPageActions();
    initSearch();
    initBacklinks();
    initShortcuts();
    document.getElementById('add-root-note-btn')?.addEventListener('click', addRootNote);
    document.getElementById('mobile-add-root-note-btn')?.addEventListener('click', addRootNote);
    loadCurrentPage().then(pageLoaded);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
