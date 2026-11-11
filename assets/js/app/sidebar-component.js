/**
 * The two collapsible sidebars (an Alpine component on .app-container).
 *
 * @module app/sidebar-component
 */

const stored = (key, fallback) => {
    try {
        const value = localStorage.getItem(key);
        return value === null ? fallback : value === 'true';
    } catch {
        return fallback;
    }
};
const store = (key, value) => {
    try { localStorage.setItem(key, value); } catch { /* storage unavailable */ }
};

const narrow = () => window.innerWidth <= 600;

export default function sidebarComponent() {
    return {
        leftCollapsed: stored('leftSidebarCollapsed', narrow()),
        rightCollapsed: stored('rightSidebarCollapsed', true),
        init() {
            // On a phone the sidebars cover the notes: start with both closed.
            if (narrow()) {
                this.leftCollapsed = true;
                this.rightCollapsed = true;
            }
            // The phone toolbar sits outside this component.
            window.addEventListener('notd-toggle-left', () => this.toggleLeft());
            window.addEventListener('notd-toggle-right', () => this.toggleRight());
        },
        toggleLeft() {
            this.leftCollapsed = !this.leftCollapsed;
            if (narrow() && !this.leftCollapsed) this.rightCollapsed = true;
            else store('leftSidebarCollapsed', this.leftCollapsed);
        },
        toggleRight() {
            this.rightCollapsed = !this.rightCollapsed;
            if (narrow() && !this.rightCollapsed) this.leftCollapsed = true;
            else store('rightSidebarCollapsed', this.rightCollapsed);
        },
        leftIcon() {
            return this.leftCollapsed ? 'chevron-right' : 'chevron-left';
        },
        rightIcon() {
            return this.rightCollapsed ? 'chevron-left' : 'chevron-right';
        },
    };
}
