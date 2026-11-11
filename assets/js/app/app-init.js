/**
 * @file Initializes the application, setting up UI components and loading the initial page.
 * @module app-init
 */

// State imports
import { setSaveStatus } from './state.js';

// Module initializers and core functions
import { sidebarState } from './sidebar.js';
import { loadPage, getInitialPage, fetchAndDisplayPages } from './page-loader.js';
import { initGlobalEventListeners } from './event-handlers.js';
import { initGlobalSearch, initPageSearchModal } from './search.js';
import { initSuggestionUI, fetchAllPages } from '../ui/page-link-suggestions.js';
import { pagesAPI } from '../api_client.js'; // Import pagesAPI
import { calendarCache } from './calendar-cache.js';
import { initBacklinksModal } from './backlinks-modal.js';

// Import UI module
import { ui } from '../ui.js';

// Expose page creation function globally for modules like calendar-widget
window.createPageWithContent = async (pageName, initialContent = '') => {
    try {
        const newPage = await pagesAPI.createPage(pageName, initialContent);
        if (newPage && newPage.id) {
            // Add new page to calendar cache if it's a date-based page
            calendarCache.addPage(newPage);
            
            await loadPage(newPage.name, true);
            return true;
        } else {
            console.error('Failed to create page: Invalid response from API');
            return false;
        }
    } catch (error) {
        console.error('Error creating page:', error);
        alert(`Error creating page: ${error.message}`);
        return false;
    }
};

/**
 * Initializes the entire application.
 * This function sets up UI components, event listeners, and loads the initial page.
 */
export async function initializeApp() {
    try {
        ui.initPagePropertiesModal();
        ui.updateSaveStatusIndicator('saved');
        
        initGlobalSearch();
        initPageSearchModal();
        initBacklinksModal();
        
        // Calendar is now handled by Alpine.js component
        
        initGlobalEventListeners();
        initSuggestionUI();
        fetchAllPages(); 
        
        const urlParams = new URLSearchParams(window.location.search);
        const initialPageName = urlParams.get('page') || getInitialPage(); 
        
        await loadPage(initialPageName, false); 
        await fetchAndDisplayPages(initialPageName);
        
        
    } catch (error) { 
        console.error('Failed to initialize application:', error);
        document.body.innerHTML = `<div style="padding: 20px; text-align: center;"><h1>App Initialization Failed</h1><p>${error.message}</p><p>Check console for details.</p></div>`;
    }
}