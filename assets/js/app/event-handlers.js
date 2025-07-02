// assets/js/app/event-handlers.js (No changes needed, just verification)

import { getNextDayPageName, getPreviousDayPageName } from './page-loader.js';
import { currentPageName } from './state.js';
import { ui } from '../ui.js';

export function initGlobalEventListeners() {
    // Global Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        // Journal navigation shortcuts
        if (e.ctrlKey && e.altKey) {
            const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
            if (dateRegex.test(currentPageName)) {
                if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    const nextPage = getNextDayPageName(currentPageName);
                    window.location.href = `page.php?page=${encodeURIComponent(nextPage)}`;
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    const prevPage = getPreviousDayPageName(currentPageName);
                    window.location.href = `page.php?page=${encodeURIComponent(prevPage)}`;
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

    // Handle splash screen toggle button
    document.addEventListener('click', (e) => {

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