// Imports
import { loadPage } from './page-loader.js';
import { safeAddEventListener } from '../utils.js';
// Assuming 'ui' is global and provides ui.domRefs. If not, it would need to be imported.
// import ui from '../ui.js'; 

export function initGlobalEventListeners() {
    // Handle browser back/forward navigation
    window.addEventListener('popstate', (event) => {
        if (event.state && event.state.pageName) {
            loadPage(event.state.pageName, false, false);
        }
    });

    // Add delegated click listener for content images (pasted or from Markdown)
    // This was in initializeApp in app.js
    if (ui.domRefs.notesContainer) { // notesContainer from ui.domRefs
        safeAddEventListener(ui.domRefs.notesContainer, 'click', (e) => {
            const target = e.target;
            if (target.matches('img.content-image') && target.dataset.originalSrc) {
                e.preventDefault();
                if (ui.domRefs.imageViewerModal && ui.domRefs.imageViewerModalImg && ui.domRefs.imageViewerModalClose) {
                    ui.domRefs.imageViewerModalImg.src = target.dataset.originalSrc;
                    ui.domRefs.imageViewerModal.classList.add('active');

                    const closeImageModal = () => {
                        ui.domRefs.imageViewerModal.classList.remove('active');
                        ui.domRefs.imageViewerModalImg.src = '';
                        ui.domRefs.imageViewerModalClose.removeEventListener('click', closeImageModal);
                        ui.domRefs.imageViewerModal.removeEventListener('click', outsideClickHandlerForContentImage);
                    };

                    const outsideClickHandlerForContentImage = (event) => {
                        if (event.target === ui.domRefs.imageViewerModal) {
                            closeImageModal();
                        }
                    };
                    
                    ui.domRefs.imageViewerModalClose.addEventListener('click', closeImageModal, { once: true });
                    ui.domRefs.imageViewerModal.addEventListener('click', outsideClickHandlerForContentImage, { once: true });

                } else {
                    console.error('Image viewer modal elements not found in domRefs.');
                    window.open(target.dataset.originalSrc, '_blank'); // Fallback
                }
            }
        }, 'notesContainerImageClick');
    }

    // Splash screen toggle logic
    // This was in initializeApp in app.js
    const splashScreen = document.getElementById('splash-screen'); // Direct access, not via ui.domRefs in original
    const toggleSplashBtn = document.getElementById('toggle-splash-btn'); // Direct access
    let isSplashActive = false; // This state is now local to this module's closure

    function showSplashScreen() {
        if (splashScreen) splashScreen.classList.remove('hidden');
        isSplashActive = true;
        document.body.style.overflow = 'hidden';
        if (window.splashAnimations && typeof window.splashAnimations.start === 'function') {
            window.splashAnimations.start();
        }
    }

    function hideSplashScreen() {
        if (splashScreen) splashScreen.classList.add('hidden');
        isSplashActive = false;
        document.body.style.overflow = '';
        if (window.splashAnimations && typeof window.splashAnimations.stop === 'function') {
            window.splashAnimations.stop();
        }
    }

    if (toggleSplashBtn) {
        safeAddEventListener(toggleSplashBtn, 'click', (e) => { 
            e.stopPropagation(); 
            if(isSplashActive) hideSplashScreen(); else showSplashScreen(); 
        }, 'toggleSplashBtn');
    }
    if (splashScreen) {
        safeAddEventListener(splashScreen, 'click', () => { 
            if(isSplashActive) hideSplashScreen(); 
        }, 'splashScreen');
    }
}
