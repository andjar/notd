// Import UI module
import { ui } from '../ui.js';

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
        }
    }
};
