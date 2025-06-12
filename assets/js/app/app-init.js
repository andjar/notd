// FILE: assets/js/app/app-init.js (Now Correct)

import { fetchAndProcessPageData, getInitialPage } from './page-loader.js';
import { ui } from '../ui.js'; // This import is correct
import { initGlobalEventListeners } from './event-handlers.js';
import { initGlobalSearch, initPageSearchModal } from './search.js';
import { fetchAllPages } from '../ui/page-link-suggestions.js';
import { sidebarState } from './sidebar.js';

/**
 * Initializes the entire application.
 */
export async function initializeApp() {
    const splashScreen = document.getElementById('splash-screen');
    if (splashScreen) splashScreen.classList.remove('hidden');

    try {
        // This call will now succeed because ui.init is a function
        ui.init(); 
        
        initGlobalEventListeners();
        sidebarState.init();
        initGlobalSearch();
        initPageSearchModal();

        await fetchAllPages();

        const urlParams = new URLSearchParams(window.location.search);
        const pageNameFromUrl = urlParams.get('page');
        const initialPageName = pageNameFromUrl || getInitialPage();
        
        await window.loadPage(initialPageName, false);

        if (splashScreen) {
            if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
                window.splashAnimations.stop();
            }
            splashScreen.classList.add('hidden');
        }
        console.log('App initialized successfully.');

    } catch (error) {
        console.error('Failed to initialize application:', error);
        if (splashScreen) {
            splashScreen.classList.add('hidden');
        }
        document.body.innerHTML = `<div style="text-align: center; padding: 2rem; color: #a04040;"><h1>Application Failed to Start</h1><p>${error.message}</p><p>Please check the console for more details.</p></div>`;
    }
}