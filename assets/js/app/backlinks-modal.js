import { domRefs } from '../ui/dom-refs.js';
import { searchAPI } from '../api_client.js';

/**
 * Backlinks Modal functionality
 */
export class BacklinksModal {
    constructor() {
        this.isOpen = false;
        this.currentPageName = null;
        this.init();
    }

    init() {
        this.bindEvents();
        this.setupKeyboardShortcuts();
    }

    bindEvents() {
        // Open modal text element
        if (domRefs.openBacklinksModalBtn) {
            domRefs.openBacklinksModalBtn.addEventListener('click', () => {
                this.openModal();
            });
        }

        // Close modal button
        if (domRefs.backlinksModalClose) {
            domRefs.backlinksModalClose.addEventListener('click', () => {
                this.closeModal();
            });
        }

        // Close on backdrop click
        if (domRefs.backlinksModal) {
            domRefs.backlinksModal.addEventListener('click', (e) => {
                if (e.target === domRefs.backlinksModal) {
                    this.closeModal();
                }
            });
        }
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl+B to open backlinks modal
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                this.openModal();
            }

            // Escape to close modal
            if (e.key === 'Escape' && this.isOpen) {
                this.closeModal();
            }
        });
    }

    async openModal() {
        if (!domRefs.backlinksModal) return;

        // Get current page name from URL
        const urlParams = new URLSearchParams(window.location.search);
        this.currentPageName = urlParams.get('page');
        
        if (!this.currentPageName) {
            console.error('No page name found in URL');
            return;
        }

        try {
            // Show loading state
            this.showLoadingState();
            domRefs.backlinksModal.classList.add('active');
            this.isOpen = true;

            // Fetch backlinks data
            const backlinks = await this.fetchBacklinks(this.currentPageName);
            this.populateModal(backlinks);
        } catch (error) {
            console.error('Error opening backlinks modal:', error);
            this.showErrorState('Failed to load backlinks');
        }
    }

    closeModal() {
        if (!domRefs.backlinksModal) return;

        domRefs.backlinksModal.classList.remove('active');
        this.isOpen = false;
    }

    async fetchBacklinks(pageName) {
        try {
            const response = await searchAPI.getBacklinks(pageName);
            return response?.results || [];
        } catch (error) {
            console.error('Error fetching backlinks:', error);
            throw error;
        }
    }

    showLoadingState() {
        if (!domRefs.backlinksModalContent) return;

        domRefs.backlinksModalContent.innerHTML = `
            <div class="loading-state">
                <div class="loading-spinner"></div>
                <p>Loading backlinks...</p>
            </div>
        `;
    }

    showErrorState(message) {
        if (!domRefs.backlinksModalContent) return;

        domRefs.backlinksModalContent.innerHTML = `
            <div class="error-state">
                <p>${message}</p>
            </div>
        `;
    }

    populateModal(backlinks) {
        if (!domRefs.backlinksModalContent) return;

        if (!backlinks || backlinks.length === 0) {
            domRefs.backlinksModalContent.innerHTML = `
                <div class="no-backlinks-state">
                    <p>No backlinks found for this page.</p>
                </div>
            `;
            return;
        }

        const backlinksHtml = backlinks.map(backlink => {
            const snippet = this.createSnippet(backlink.content, this.currentPageName);
            return `
                <div class="backlink-item">
                    <a href="page.php?page=${encodeURIComponent(backlink.page_name)}" class="page-link">
                        <i data-feather="file-text" class="backlink-icon"></i>
                        ${this.escapeHtml(backlink.page_name)}
                    </a>
                    <div class="backlink-snippet">${snippet}</div>
                </div>
            `;
        }).join('');

        domRefs.backlinksModalContent.innerHTML = backlinksHtml;

        // Reinitialize Feather icons
        if (window.feather) {
            window.feather.replace();
        }
    }

    createSnippet(content, pageName) {
        if (!content) return '';

        // Find the context around the page link
        const pageNameRegex = new RegExp(`\\[\\[${this.escapeRegex(pageName)}\\]\\]`, 'gi');
        const match = content.match(pageNameRegex);
        
        if (!match) {
            // If no direct link found, just show a snippet of the content
            return this.escapeHtml(content.substring(0, 200)) + (content.length > 200 ? '...' : '');
        }

        // Find the position of the link
        const linkIndex = content.toLowerCase().indexOf(`[[${pageName.toLowerCase()}]]`);
        if (linkIndex === -1) {
            return this.escapeHtml(content.substring(0, 200)) + (content.length > 200 ? '...' : '');
        }

        // Create a snippet around the link (100 chars before and after)
        const start = Math.max(0, linkIndex - 100);
        const end = Math.min(content.length, linkIndex + pageName.length + 100);
        let snippet = content.substring(start, end);

        // Add ellipsis if we're not at the beginning/end
        if (start > 0) snippet = '...' + snippet;
        if (end < content.length) snippet = snippet + '...';

        // Highlight the page link in the snippet
        snippet = snippet.replace(
            new RegExp(`\\[\\[${this.escapeRegex(pageName)}\\]\\]`, 'gi'),
            '<span class="wiki-link">[[$&]]</span>'
        );

        return snippet;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}

// Initialize the backlinks modal when the module is loaded
export function initBacklinksModal() {
    return new BacklinksModal();
} 