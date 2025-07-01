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

    // Handle clicks on internal page links (e.g., in backlinks, child pages, page title breadcrumbs, sidebar)
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[data-page-name]');
        if (link) {
            e.preventDefault();
            const pageName = link.dataset.pageName;
            loadPage(pageName);
        }

        // Handle splash screen toggle button
        const toggleSplashBtn = e.target.closest('#toggle-splash-btn');
        if (toggleSplashBtn) {
            e.preventDefault();
            console.log('Splash toggle button clicked');
            
            const splashScreen = document.getElementById('splash-screen');
            if (!splashScreen) {
                console.error('Splash screen element not found');
                return;
            }
            
            // Simple approach: check if splash screen is currently visible
            const isCurrentlyVisible = splashScreen.style.display !== 'none' && 
                                     splashScreen.style.visibility !== 'hidden' &&
                                     !splashScreen.classList.contains('hidden') &&
                                     splashScreen.offsetParent !== null;
            
            console.log('Splash screen currently visible:', isCurrentlyVisible);
            console.log('Current display style:', splashScreen.style.display);
            console.log('Current visibility style:', splashScreen.style.visibility);
            console.log('Has hidden class:', splashScreen.classList.contains('hidden'));
            console.log('Offset parent:', splashScreen.offsetParent);
            
            if (isCurrentlyVisible) {
                console.log('Hiding splash screen');
                
                // Try Alpine.js first
                if (window.Alpine && window.Alpine.$data) {
                    try {
                        const splashComponent = window.Alpine.$data(splashScreen);
                        if (splashComponent && typeof splashComponent.hideSplash === 'function') {
                            splashComponent.hideSplash();
                        }
                        if (splashComponent) {
                            splashComponent.show = false;
                        }
                    } catch (error) {
                        console.log('Alpine.js access failed:', error);
                    }
                }
                
                // Direct DOM manipulation as fallback
                splashScreen.style.display = 'none';
                splashScreen.style.visibility = 'hidden';
                splashScreen.style.opacity = '0';
                
                // Update button icon
                const icon = toggleSplashBtn.querySelector('i');
                if (icon) {
                    icon.setAttribute('data-feather', 'play-circle');
                    if (typeof feather !== 'undefined') {
                        feather.replace();
                    }
                }
                
                console.log('Splash screen hidden');
            } else {
                console.log('Showing splash screen');
                
                // Try Alpine.js first
                if (window.Alpine && window.Alpine.$data) {
                    try {
                        const splashComponent = window.Alpine.$data(splashScreen);
                        if (splashComponent && typeof splashComponent.showSplash === 'function') {
                            splashComponent.showSplash();
                        }
                        if (splashComponent) {
                            splashComponent.show = true;
                        }
                    } catch (error) {
                        console.log('Alpine.js access failed:', error);
                    }
                }
                
                // Direct DOM manipulation as fallback
                splashScreen.style.display = 'flex';
                splashScreen.style.visibility = 'visible';
                splashScreen.style.opacity = '1';
                
                // Update button icon
                const icon = toggleSplashBtn.querySelector('i');
                if (icon) {
                    icon.setAttribute('data-feather', 'pause-circle');
                    if (typeof feather !== 'undefined') {
                        feather.replace();
                    }
                }
                
                console.log('Splash screen shown');
            }
        }
    });
}