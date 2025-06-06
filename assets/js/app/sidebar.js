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
            favoritesContainer.innerHTML = 'Loading favorites...'; // Initial message
    
            try {
                const pages = await pagesAPI.getPages({ include_details: true });
                if (!pages || !Array.isArray(pages)) {
                    favoritesContainer.innerHTML = 'Could not load favorites.';
                    console.error('Failed to fetch pages or invalid response:', pages);
                    return;
                }
    
                const favoritePages = pages.filter(
                    page => page.properties && page.properties.favorite === 'true'
                );
    
                favoritesContainer.innerHTML = ''; // Clear loading message
    
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
                    link.href = `page.php?id=${page.id}`;
                    link.textContent = page.name;
                    link.classList.add('favorite-page-link'); // Add a class for potential styling
                    favoritesContainer.appendChild(link);
                });
    
                if (showMoreNeeded) {
                    const showMoreBtn = document.createElement('button');
                    showMoreBtn.textContent = `Show more (${favoritePages.length - displayLimit})`;
                    showMoreBtn.classList.add('show-more-favorites-btn'); // Add a class
                    showMoreBtn.addEventListener('click', () => {
                        favoritesContainer.innerHTML = ''; // Clear current items and button
                        favoritePages.forEach(page => { // Render all favorites
                            const link = document.createElement('a');
                            link.href = `page.php?id=${page.id}`;
                            link.textContent = page.name;
                            link.classList.add('favorite-page-link');
                            favoritesContainer.appendChild(link);
                        });
                    }, { once: true }); // Remove listener after first click
                    favoritesContainer.appendChild(showMoreBtn);
                }
    
            } catch (error) {
                console.error("Error rendering favorites:", error);
                if (favoritesContainer) { // Check again in case error happened before it was set
                    favoritesContainer.innerHTML = 'Error loading favorites.';
                }
            }
        }
    },
    init() {
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
            if (this.right.element) { // Ensure right sidebar exists
                this.right.renderFavorites(); // Call the new method
            }
        }
    }
};
