// assets/js/app/sidebar.js

import { ui } from '../ui.js';
import { pagesAPI, apiRequest } from '../api_client.js';

export const sidebarState = {
    left: {
        // Use the correct DOM reference from the central domRefs object
        element: ui.domRefs.leftSidebar,
        button: ui.domRefs.toggleLeftSidebarBtn,
        isCollapsed: false,
        toggle() {
            this.isCollapsed = !this.isCollapsed;
            localStorage.setItem('leftSidebarCollapsed', this.isCollapsed);
            this.element.classList.toggle('collapsed', this.isCollapsed);
            document.body.classList.toggle('left-sidebar-collapsed', this.isCollapsed);
            this.updateButtonVisuals();
        },
        updateButtonVisuals() {
            if (this.button) {
                // **FIX**: Use innerHTML to render Feather icons correctly
                this.button.innerHTML = this.isCollapsed ? '<i data-feather="menu"></i>' : '<i data-feather="x"></i>';
                if (typeof feather !== 'undefined') feather.replace({ width: '20px', height: '20px' });
            }
        }
    },
    right: {
        element: ui.domRefs.rightSidebar,
        button: ui.domRefs.toggleRightSidebarBtn,
        isCollapsed: true, 
        toggle() {
            this.isCollapsed = !this.isCollapsed;
            localStorage.setItem('rightSidebarCollapsed', this.isCollapsed);
            this.element.classList.toggle('collapsed', this.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.isCollapsed);
            this.updateButtonVisuals();
        },
        updateButtonVisuals() {
            if (this.button) {
                this.button.innerHTML = this.isCollapsed ? '<i data-feather="menu"></i>' : '<i data-feather="x"></i>';
                if (typeof feather !== 'undefined') feather.replace({ width: '20px', height: '20px' });
            }
        },
        async renderFavorites() {
            // ... (The renderFavorites function from the previous response was correct, no changes needed here)
             const favoritesContainer = document.getElementById('favorites-container');
            const displayLimit = 5;
            if (!favoritesContainer) {
                console.error("Favorites container not found");
                return;
            }
            favoritesContainer.innerHTML = 'Loading favorites...';
    
            try {
                const { pages } = await pagesAPI.getPages({ per_page: 500 });

                if (!Array.isArray(pages)) {
                    favoritesContainer.innerHTML = 'Could not load favorites.';
                    return;
                }
    
                const favoritePages = pages.filter(
                    page => page.properties?.favorite?.some(prop => String(prop.value).toLowerCase() === 'true')
                );
    
                favoritesContainer.innerHTML = '';
    
                if (favoritePages.length === 0) {
                    favoritesContainer.textContent = 'No favorite pages yet.';
                    return;
                }
    
                const showMoreNeeded = favoritePages.length > displayLimit;
                const pagesToDisplay = showMoreNeeded ? favoritePages.slice(0, displayLimit) : favoritePages;
    
                pagesToDisplay.forEach(page => {
                    const link = document.createElement('a');
                    link.href = `?page=${encodeURIComponent(page.name)}`;
                    link.textContent = page.name;
                    link.classList.add('favorite-page-link');
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        window.loadPage(page.name);
                    });
                    favoritesContainer.appendChild(link);
                });
    
                if (showMoreNeeded) {
                    const showMoreBtn = document.createElement('button');
                    showMoreBtn.textContent = `Show more (${favoritePages.length - displayLimit})`;
                    showMoreBtn.classList.add('show-more-favorites-btn');
                    showMoreBtn.addEventListener('click', () => {
                        favoritesContainer.innerHTML = '';
                        favoritePages.forEach(page => {
                            const link = document.createElement('a');
                            link.href = `?page=${encodeURIComponent(page.name)}`;
                            link.textContent = page.name;
                            link.classList.add('favorite-page-link');
                            link.addEventListener('click', (e) => {
                                e.preventDefault();
                                window.loadPage(page.name);
                            });
                            favoritesContainer.appendChild(link);
                        });
                    }, { once: true });
                    favoritesContainer.appendChild(showMoreBtn);
                }
    
            } catch (error) {
                console.error("Error rendering favorites:", error);
                if (favoritesContainer) {
                    favoritesContainer.innerHTML = 'Error loading favorites.';
                }
            }
        },
        async renderExtensionIcons() {
            // **FIX**: Use the correct DOM reference from the central ui object.
            const iconsContainer = ui.domRefs.extensionIconsContainer;

            if (!iconsContainer) {
                console.error('Extension icons container not found.');
                return;
            }
            iconsContainer.innerHTML = '';

            try {
                const data = await apiRequest('extensions.php');
                
                if (data && Array.isArray(data.extensions)) {
                    if (data.extensions.length === 0) {
                        return;
                    }

                    data.extensions.forEach(extension => {
                        const linkEl = document.createElement('a');
                        linkEl.href = `extensions/${extension.name}/index.php`;
                        linkEl.title = extension.name;
                        linkEl.target = "_blank";

                        const iconEl = document.createElement('i');
                        iconEl.setAttribute('data-feather', extension.featherIcon);
                        iconEl.classList.add('sidebar-extension-icon');
                        
                        linkEl.appendChild(iconEl);
                        iconsContainer.appendChild(linkEl);
                    });
                    
                    if (typeof feather !== 'undefined') {
                        feather.replace();
                    }

                } else {
                    throw new Error('Failed to load extension icons or data format is incorrect.');
                }
            } catch (error) {
                console.error('Error fetching or rendering extension icons:', error);
                if(iconsContainer) iconsContainer.innerHTML = '<small>Extensions unavailable</small>';
            }
        }
    },
    async init() {
        if (this.left.element && this.left.button) {
            this.left.isCollapsed = localStorage.getItem('leftSidebarCollapsed') === 'true';
            document.body.classList.toggle('left-sidebar-collapsed', this.left.isCollapsed);
            this.left.updateButtonVisuals();
            // **FIX**: The event listener is now correctly part of the init logic.
            this.left.button.addEventListener('click', () => this.left.toggle());
        }
        if (this.right.element && this.right.button) {
            this.right.isCollapsed = localStorage.getItem('rightSidebarCollapsed') !== 'false';
            document.body.classList.toggle('right-sidebar-collapsed', this.right.isCollapsed);
            this.right.updateButtonVisuals();
             // **FIX**: The event listener is now correctly part of the init logic.
            this.right.button.addEventListener('click', () => this.right.toggle());
            if (this.right.element) {
                await Promise.all([this.right.renderFavorites(), this.right.renderExtensionIcons()]);
            }
        }
    }
};