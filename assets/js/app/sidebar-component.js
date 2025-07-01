export default function sidebarComponent() {
    return {
        leftCollapsed: localStorage.getItem('leftSidebarCollapsed') === 'true',
        rightCollapsed: localStorage.getItem('rightSidebarCollapsed') === 'true',
        init() {
            this.updateBodyClasses();
        },
        toggleLeft() {
            this.leftCollapsed = !this.leftCollapsed;
            localStorage.setItem('leftSidebarCollapsed', this.leftCollapsed);
            this.updateBodyClasses();
        },
        toggleRight() {
            this.rightCollapsed = !this.rightCollapsed;
            localStorage.setItem('rightSidebarCollapsed', this.rightCollapsed);
            this.updateBodyClasses();
        },
        leftIcon() {
            return this.leftCollapsed ? 'chevron-right' : 'chevron-left';
        },
        rightIcon() {
            return this.rightCollapsed ? 'chevron-left' : 'chevron-right';
        },
        updateBodyClasses() {
            document.body.classList.toggle('left-sidebar-collapsed', this.leftCollapsed);
            document.body.classList.toggle('right-sidebar-collapsed', this.rightCollapsed);
        }
    };
} 