/**
 * Application Initializer
 * Handles the main setup and DOMContentLoaded event.
 */

// State imports
import { notesForCurrentPage, currentPageName, saveStatus, setSaveStatus } from './state.js';
import { debounce } from '../utils.js';

// Module initializers and core functions
import { sidebarState } from './sidebar.js';
import { loadPage, prefetchRecentPagesData, getInitialPage, fetchAndDisplayPages } from './page-loader.js';
import { initGlobalEventListeners } from './event-handlers.js';
import { initGlobalSearch, initPageSearchModal } from './search.js';

// Import UI module
import { ui } from '../ui.js';

async function initializeApp() {
    const splashScreen = document.getElementById('splash-screen');
    if (splashScreen) splashScreen.classList.remove('hidden'); 
    
    try {
        sidebarState.init(); 
        
        // Initialize UI components
        if (typeof ui !== 'undefined') {
            ui.initPagePropertiesModal();
            ui.updateSaveStatusIndicator('saved');
        } else {
            console.error("UI module not loaded. Please check script loading order.");
            return;
        }
        
        initGlobalSearch();
        initPageSearchModal();
        initGlobalEventListeners(); 
        
        const urlParams = new URLSearchParams(window.location.search);
        const initialPageName = urlParams.get('page') || getInitialPage(); 
        
        await loadPage(initialPageName, false); 
        await fetchAndDisplayPages(initialPageName);
        
        await prefetchRecentPagesData(); 
        
        const initialSaveIndicator = document.getElementById('save-status-indicator');
        if (initialSaveIndicator) {
            initialSaveIndicator.classList.add('status-hidden'); 
        }
        
        if (splashScreen) {
            if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
                window.splashAnimations.stop();
            }
            splashScreen.classList.add('hidden');
        }
        console.log('App initialized successfully');
        
    } catch (error) { 
        console.error('Failed to initialize application:', error);
        if (splashScreen && typeof window.splashAnimations !== 'undefined' && typeof window.splashAnimations.stop === 'function') {
            window.splashAnimations.stop(); 
        }
        if(splashScreen) splashScreen.classList.add('hidden');
        if (document.body) {
            document.body.innerHTML = '<div style="padding: 20px; text-align: center;"><h1>App Initialization Failed</h1><p>' + error.message + '</p>Check console for details.</div>';
        }
    }
}

// Export the initializeApp function
export { initializeApp };
