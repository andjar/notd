// FILE: assets/js/app/event-handlers.js

import { getNextDayPageName, getPreviousDayPageName } from './page-loader.js';
import { currentPageName } from './state.js';
import { ui } from '../ui.js';

let gKeyPressed = false;
const dateRegex = /^\d{4}-\d{2}-\d{2}$/;

export function initGlobalEventListeners() {
    // Handle browser back/forward navigation
    window.addEventListener('popstate', (event) => {
        if (event.state && event.state.pageName) {
            // Use the global loadPage function
            window.loadPage(event.state.pageName, false);
        }
    });

    // Delegated click listener for page links anywhere in the app
    document.body.addEventListener('click', (e) => {
        const pageLink = e.target.closest('a.page-link');
        if (pageLink && pageLink.dataset.pageName) {
            e.preventDefault();
            window.loadPage(pageLink.dataset.pageName);
        }
    });

    // Keyboard shortcuts for daily note navigation
    window.addEventListener('keydown', (event) => {
        const activeElement = document.activeElement;
        const isInputFocused = activeElement && (activeElement.tagName === 'INPUT' || activeElement.isContentEditable);

        if (event.key === 'g' && !isInputFocused) {
            gKeyPressed = true;
            setTimeout(() => { gKeyPressed = false; }, 1500); // Reset after 1.5s
        } else if (gKeyPressed) {
            if (event.key === 'n') {
                event.preventDefault();
                if (currentPageName && dateRegex.test(currentPageName)) {
                    window.loadPage(getNextDayPageName(currentPageName));
                }
            } else if (event.key === 'p') {
                event.preventDefault();
                if (currentPageName && dateRegex.test(currentPageName)) {
                    window.loadPage(getPreviousDayPageName(currentPageName));
                }
            }
            gKeyPressed = false; // Reset after any second key
        }
    });
}