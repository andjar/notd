/**
 * Application Initializer
 * Handles the main setup and DOMContentLoaded event.
 */

// State imports
import { setSaveStatus } from './state.js';

// Module initializers and core functions
import { sidebarState } from './sidebar.js';
import { loadPage, prefetchRecentPagesData, getInitialPage, fetchAndDisplayPages } from './page-loader.js';
import { initGlobalEventListeners } from './event-handlers.js';
import { initGlobalSearch, initPageSearchModal } from './search.js';
import { initSuggestionUI, fetchAllPages } from '../ui/page-link-suggestions.js';
import { pagesAPI } from '../api_client.js'; // Import pagesAPI

// Import UI module
import { ui } from '../ui.js';

// Expose page creation function globally for modules like calendar-widget
window.createPageWithContent = async (pageName, initialContent = '') => {
    try {
        const newPage = await pagesAPI.createPage(pageName, initialContent);
        if (newPage && newPage.id) {
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
    const splashScreen = document.getElementById('splash-screen');
    if (splashScreen) splashScreen.classList.remove('hidden'); 
    
    try {
        // Initialize sidebars
        sidebarState.init(); 
        
        // Initialize UI components and modals
        ui.initPagePropertiesModal();
        ui.updateSaveStatusIndicator('saved');
        
        // Initialize search functionalities
        initGlobalSearch();
        initPageSearchModal();
        
        // Set up global event listeners (e.g., popstate, keyboard shortcuts)
        initGlobalEventListeners();
        
        // Initialize the UI for page link suggestions `[[...]]`
        initSuggestionUI();
        // Asynchronously fetch all page names for the suggestion cache
        fetchAllPages(); 
        
        // Determine the initial page to load from URL or default
        const urlParams = new URLSearchParams(window.location.search);
        const initialPageName = urlParams.get('page') || getInitialPage(); 
        
        // Load the main page content
        await loadPage(initialPageName, false); 
        // Fetch and display the list of recent pages in the sidebar
        await fetchAndDisplayPages(initialPageName);
        
        // Pre-fetch data for other recent pages in the background
        await prefetchRecentPagesData(); 
        
        // Hide the initial save indicator until a change is made
        const initialSaveIndicator = document.getElementById('save-status-indicator');
        if (initialSaveIndicator) {
            initialSaveIndicator.classList.add('status-hidden'); 
        }
        
        console.log('App initialized successfully');
        
    } catch (error) { 
        console.error('Failed to initialize application:', error);
        // Ensure splash screen is hidden on error to show error message
        if (splashScreen) splashScreen.classList.add('hidden');
        // Provide feedback to the user in case of a critical initialization failure
        document.body.innerHTML = `<div style="padding: 20px; text-align: center;"><h1>App Initialization Failed</h1><p>${error.message}</p><p>Check console for details.</p></div>`;
    } finally {
        // Ensure the splash screen is always hidden after initialization attempt
        if (splashScreen) {
            if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
                window.splashAnimations.stop();
            }
            splashScreen.classList.add('hidden');
        }
    }
}