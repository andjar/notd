// Import UI module
import { ui } from '../ui.js';
import { pagesAPI } from '../api_client.js';

export const sidebarState = {
    left: {
        isCollapsed: false,
        element: null, 
        button: null,  
        toggle() {
            if (!this.element || !this.button) return;
            this.isCollapsed = !this.isCollapsed;
            this.element.classList.toggle('collapsed', this.isCollapsed);
            document.body.classList.toggle('left-sidebar-collapsed', this.isCollapsed);
            localStorage.setItem('leftSidebarCollapsed', this.isCollapsed);
            this.updateButtonVisuals();
        },
        updateButtonVisuals() {
            if (!this.button) return;
            this.button.textContent = this.isCollapsed ? '☰' : '✕';
            this.button.title = this.isCollapsed ? 'Show left sidebar' : 'Hide left sidebar';
        }
    },
    right: {
        isCollapsed: false,
        element: null, 
        button: null,  
        toggle() {
            if (!this.element || !this.button) return;
            this.isCollapsed = !this.isCollapsed;
            this.element.classList.toggle('collapsed', this.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.isCollapsed);
            localStorage.setItem('rightSidebarCollapsed', this.isCollapsed);
            this.updateButtonVisuals();
        },
        updateButtonVisuals() {
            if (!this.button) return;
            this.button.textContent = this.isCollapsed ? '☰' : '✕';
            this.button.title = this.isCollapsed ? 'Show right sidebar' : 'Hide right sidebar';
        },
        async renderFavorites() {
            const favoritesContainer = ui.domRefs.favoritesContainer;
            if (!favoritesContainer) {
                console.error("Favorites container not found");
                return;
            }
            favoritesContainer.innerHTML = 'Loading favorites...';
    
            try {
                // **FIXED**: Destructure the `pages` array from the response object.
                const { pages } = await pagesAPI.getPages({ include_details: true });

                if (!Array.isArray(pages)) {
                    favoritesContainer.innerHTML = 'Could not load favorites.';
                    console.error('Failed to fetch pages or invalid response:', pages);
                    return;
                }
    
                // **FIXED**: Filter based on the new property structure from the API Spec.
                // A page is a favorite if it has a 'favorite' property array where at least one entry's value is 'true'.
                const favoritePages = pages.filter(
                    page => page.properties?.favorite?.some(prop => String(prop.value).toLowerCase() === 'true')
                );
    
                favoritesContainer.innerHTML = '';
    
                if (favoritePages.length === 0) {
                    favoritesContainer.textContent = 'No favorite pages yet.';
                    return;
                }
    
                const displayLimit = 5;
                let pagesToDisplay = favoritePages;
                let showMoreNeeded = false;
    
                if (favoritePages.length > displayLimit) {
                    pagesToDisplay = favoritePages.slice(0, displayLimit);
                    showMoreNeeded = true;
                }
    
                pagesToDisplay.forEach(page => {
                    const link = document.createElement('a');
                    link.href = `?page=${encodeURIComponent(page.name)}`; // Use page name for consistency
                    link.textContent = page.name;
                    link.classList.add('favorite-page-link');
                    // Add click handler to use SPA navigation
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
            const apiUrl = 'api/v1/extensions.php';
            const iconsContainer = ui.domRefs.extensionIconsContainer;

            if (!iconsContainer) {
                console.error('Extension icons container (extension-icons-container) not found.');
                return;
            }
            iconsContainer.innerHTML = '';

            try {
                // Using fetch directly as this is a simple GET with no complex handling
                const response = await fetch(apiUrl);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();

                // **FIXED**: Check for the new API response format from the spec.
                if (data.status === 'success' && data.data && Array.isArray(data.data.extensions)) {
                    if (data.data.extensions.length === 0) {
                        return; // Nothing to render
                    }

                    data.data.extensions.forEach(extension => {
                        const linkEl = document.createElement('a');
                        linkEl.href = `extensions/${extension.name}/index.php`;
                        linkEl.title = extension.name;
                        linkEl.target = "_blank"; // Open extensions in a new tab

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
        // Initialize left sidebar
        this.left.element = ui.domRefs.leftSidebar;
        this.left.button = ui.domRefs.toggleLeftSidebarBtn;
        if (this.left.element && this.left.button) {
            this.left.isCollapsed = localStorage.getItem('leftSidebarCollapsed') === 'true';
            this.left.element.classList.toggle('collapsed', this.left.isCollapsed);
            document.body.classList.toggle('left-sidebar-collapsed', this.left.isCollapsed);
            this.left.updateButtonVisuals();
            this.left.button.addEventListener('click', () => this.left.toggle());
        }

        // Initialize right sidebar
        this.right.element = ui.domRefs.rightSidebar;
        this.right.button = ui.domRefs.toggleRightSidebarBtn;
        if (this.right.element && this.right.button) {
            this.right.isCollapsed = localStorage.getItem('rightSidebarCollapsed') === 'true';
            this.right.element.classList.toggle('collapsed', this.right.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.right.isCollapsed);
            this.right.updateButtonVisuals();
            this.right.button.addEventListener('click', () => this.right.toggle());
            if (this.right.element) {
                await Promise.all([this.right.renderFavorites(), this.right.renderExtensionIcons()]);
            }
        }
    }
};
