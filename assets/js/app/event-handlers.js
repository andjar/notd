// assets/js/app/event-handlers.js (No changes needed, just verification)

import { loadPage, getNextDayPageName, getPreviousDayPageName } from './page-loader.js';
import { currentPageName } from './state.js';
import { ui } from '../ui.js';

export function initGlobalEventListeners() {
    // Handle browser back/forward navigation
    window.addEventListener('popstate', (event) => {
        if (event.state && event.state.pageName) {
            // Load the page without adding a new history entry
            loadPage(event.state.pageName, false, false);
        }
    });

    // Global Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        // Journal navigation shortcuts
        if (e.ctrlKey && e.altKey) {
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            if (dateRegex.test(currentPageName)) {
                if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    const nextPage = getNextDayPageName(currentPageName);
                    loadPage(nextPage);
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    const prevPage = getPreviousDayPageName(currentPageName);
                    loadPage(prevPage);
                }
            }
        }

        // Other global shortcuts can be added here
        // e.g., Ctrl+K for search
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('global-search-input');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });
}