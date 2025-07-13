export default function sidebarComponent() {
    return {
        leftCollapsed: localStorage.getItem('leftSidebarCollapsed') === 'true',
        rightCollapsed: localStorage.getItem('rightSidebarCollapsed') === 'true',
        init() {
            // Initialize component
        },
        toggleLeft() {
            this.leftCollapsed = !this.leftCollapsed;
            localStorage.setItem('leftSidebarCollapsed', this.leftCollapsed);
        },
        toggleRight() {
            this.rightCollapsed = !this.rightCollapsed;
            localStorage.setItem('rightSidebarCollapsed', this.rightCollapsed);
        },
        leftIcon() {
            return this.leftCollapsed ? 'chevron-right' : 'chevron-left';
        },
        rightIcon() {
            return this.rightCollapsed ? 'chevron-left' : 'chevron-right';
        }
    };
} 