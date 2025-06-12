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
        },
        async renderFavorites() {
            const container = ui.domRefs.favoritesContainer;
            if (!container) return;
            container.innerHTML = '<em>Loading...</em>';
            try {
                // Logic to fetch and render favorite pages
                // Example: const favs = await pagesAPI.getFavorites();
                container.innerHTML = '... favorites rendered ...';
            } catch (e) {
                container.innerHTML = '<small>Could not load favorites.</small>';
            }
        },
        async renderExtensionIcons() {
            const apiUrl = 'api/v1/extensions.php'; // Ensure this path is correct from your web root
            const iconsContainer = ui.domRefs.extensionIconsContainer;

            if (!iconsContainer) {
                console.error('Extension icons container (extension-icons-container) not found.');
                return;
            }
            iconsContainer.innerHTML = ''; // Clear existing icons

            try {
                const response = await fetch(apiUrl);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();

                if (data.success && Array.isArray(data.extensions)) {
                    if (data.extensions.length === 0) {
                        // iconsContainer.textContent = 'No active extensions.'; // Optional: display message
                        return; // Nothing to render
                    }

                    data.extensions.forEach(extension => {
                        const linkEl = document.createElement('a');
                        linkEl.href = `extensions/${extension.name}/index.php`; // Link to the extension's entry point
                        linkEl.title = extension.name; // Tooltip for accessibility

                        const iconEl = document.createElement('i'); // Using <i> as is common for icon fonts/SVG icons
                        iconEl.setAttribute('data-feather', extension.featherIcon);
                        iconEl.classList.add('sidebar-extension-icon'); // For common styling
                        
                        linkEl.appendChild(iconEl);
                        iconsContainer.appendChild(linkEl);
                    });

                } else {
                    console.error('Failed to load extension icons or data format is incorrect:', data);
                    // iconsContainer.textContent = 'Error loading extensions.'; // Optional: display error
                }
            } catch (error) {
                console.error('Error fetching or rendering extension icons:', error);
                // iconsContainer.textContent = 'Error loading extensions.'; // Optional: display error
            }
        }
    },
    init() {
        // Left Sidebar
        this.left.element = ui.domRefs.leftSidebar;
        this.left.button = ui.domRefs.toggleLeftSidebarBtn;
        if (this.left.element && this.left.button) {
            this.left.isCollapsed = localStorage.getItem('leftSidebarCollapsed') === 'true';
            this.left.element.classList.toggle('collapsed', this.left.isCollapsed);
            document.body.classList.toggle('left-sidebar-collapsed', this.left.isCollapsed);
            this.left.button.addEventListener('click', () => this.left.toggle());
        }

        // Right Sidebar
        this.right.element = ui.domRefs.rightSidebar;
        this.right.button = ui.domRefs.toggleRightSidebarBtn;
        if (this.right.element && this.right.button) {
            this.right.isCollapsed = localStorage.getItem('rightSidebarCollapsed') === 'true';
            this.right.element.classList.toggle('collapsed', this.right.isCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.right.isCollapsed);
            this.right.button.addEventListener('click', () => this.right.toggle());
            // this.right.renderFavorites();
            // this.right.renderExtensionIcons();
        }
    }
};
