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
        },
        // updateButtonVisuals() {
        //     // No longer needed, handled by Alpine
        // }
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
        },
        // updateButtonVisuals() {
        //     // No longer needed, handled by Alpine
        // },
        async renderFavorites() {
            const favoritesContainer = document.getElementById('favorites-container');
            const displayLimit = 7; // Match Recent Pages limit
            if (!favoritesContainer) {
                console.error("Favorites container not found");
                return;
            }
            favoritesContainer.innerHTML = '';

            try {
                const { pages } = await pagesAPI.getPages({ per_page: 500 });

                if (!Array.isArray(pages)) {
                    favoritesContainer.innerHTML = '<div class="no-items-message">Could not load favorites.</div>';
                    return;
                }

                const favoritePages = pages.filter(
                    page => page.properties?.favorite?.some(prop => String(prop.value).toLowerCase() === 'true')
                );

                if (favoritePages.length === 0) {
                    favoritesContainer.innerHTML = '<div class="no-items-message">No favorite pages yet.</div>';
                    return;
                }

                // Sort pages by last updated
                favoritePages.sort((a, b) => {
                    if (a.updated_at > b.updated_at) return -1;
                    if (a.updated_at < b.updated_at) return 1;
                    return a.name.localeCompare(b.name);
                });

                const showMoreNeeded = favoritePages.length > displayLimit;
                const pagesToDisplay = showMoreNeeded ? favoritePages.slice(0, displayLimit) : favoritePages;

                // Create a list container
                const listContainer = document.createElement('ul');
                listContainer.className = 'favorites-list';

                pagesToDisplay.forEach(page => {
                    const listItem = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = '#';
                    link.dataset.pageName = page.name;
                    link.className = 'favorite-page-link';
                    
                    // Add active class if this is the current page
                    if (page.name === window.currentPageName) {
                        link.classList.add('active');
                    }

                    // Create the link content with icon and name
                    const icon = document.createElement('i');
                    icon.dataset.feather = 'star';
                    icon.className = 'favorite-page-icon';
                    
                    const nameSpan = document.createElement('span');
                    nameSpan.className = 'favorite-page-name';
                    nameSpan.textContent = page.name;

                    link.appendChild(icon);
                    link.appendChild(nameSpan);
                    listItem.appendChild(link);
                    listContainer.appendChild(listItem);

                    // Add click handler
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        window.loadPage(page.name);
                    });
                });

                favoritesContainer.appendChild(listContainer);

                if (showMoreNeeded) {
                    const showMoreBtn = document.createElement('button');
                    showMoreBtn.textContent = `Show more (${favoritePages.length - displayLimit})`;
                    showMoreBtn.className = 'show-more-favorites-btn';
                    showMoreBtn.addEventListener('click', () => {
                        favoritesContainer.innerHTML = '';
                        const fullList = document.createElement('ul');
                        fullList.className = 'favorites-list';
                        
                        favoritePages.forEach(page => {
                            const listItem = document.createElement('li');
                            const link = document.createElement('a');
                            link.href = '#';
                            link.dataset.pageName = page.name;
                            link.className = 'favorite-page-link';
                            
                            if (page.name === window.currentPageName) {
                                link.classList.add('active');
                            }

                            const icon = document.createElement('i');
                            icon.dataset.feather = 'star';
                            icon.className = 'favorite-page-icon';
                            
                            const nameSpan = document.createElement('span');
                            nameSpan.className = 'favorite-page-name';
                            nameSpan.textContent = page.name;

                            link.appendChild(icon);
                            link.appendChild(nameSpan);
                            listItem.appendChild(link);
                            fullList.appendChild(listItem);

                            link.addEventListener('click', (e) => {
                                e.preventDefault();
                                window.loadPage(page.name);
                            });
                        });

                        favoritesContainer.appendChild(fullList);
                    });
                    favoritesContainer.appendChild(showMoreBtn);
                }

                // Use centralized Feather icon manager
                if (window.FeatherManager) {
                    window.FeatherManager.requestUpdate();
                } else if (typeof feather !== 'undefined') {
                    try {
                        feather.replace();
                    } catch (error) {
                        console.error('Error rendering feather icons:', error);
                    }
                }

            } catch (error) {
                console.error("Error rendering favorites:", error);
                favoritesContainer.innerHTML = '<div class="no-items-message">Error loading favorites.</div>';
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
                const response = await apiRequest('extensions.php');
                
                // Handle the response format - apiRequest returns the 'data' portion
                const extensions = response?.extensions || response || [];
                
                if (Array.isArray(extensions)) {
                    if (extensions.length === 0) {
                        return;
                    }

                    extensions.forEach(extension => {
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
                    
                    // Use centralized Feather icon manager
                    if (window.FeatherManager) {
                        window.FeatherManager.requestUpdate();
                    } else if (typeof feather !== 'undefined') {
                        setTimeout(() => {
                            try {
                                feather.replace();
                            } catch (error) {
                                console.error('Error rendering feather icons:', error);
                            }
                        }, 0);
                    }

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
            this.left.button.addEventListener('click', () => this.left.toggle());
        }
        if (this.right.element && this.right.button) {
            const storedState = localStorage.getItem('rightSidebarCollapsed');
            this.right.isCollapsed = storedState === null ? true : storedState === 'true';
            this.right.element.classList.toggle('collapsed', this.right.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.right.isCollapsed);
            this.right.button.addEventListener('click', () => this.right.toggle());
            if (this.right.element) {
                await Promise.all([this.right.renderFavorites(), this.right.renderExtensionIcons()]);
            }
        }
    }
};