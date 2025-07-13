/**
 * Recent page history manager that tracks user navigation
 * This provides a more accurate "recent pages" list based on actual user visits
 */
class RecentHistory {
    constructor() {
        this.STORAGE_KEY = 'notd_recent_history';
        this.MAX_HISTORY_SIZE = 20;
    }

    /**
     * Add a page to recent history
     * @param {string} pageName - The page name
     */
    addPage(pageName) {
        try {
            const history = this.getHistory();
            
            // Remove the page if it already exists (to move it to the top)
            const filteredHistory = history.filter(entry => entry.pageName !== pageName);
            
            // Add the page to the beginning
            const newEntry = {
                pageName: pageName,
                timestamp: Date.now(),
                visitCount: this.getVisitCount(pageName, history) + 1
            };
            
            filteredHistory.unshift(newEntry);
            
            // Keep only the most recent entries
            const trimmedHistory = filteredHistory.slice(0, this.MAX_HISTORY_SIZE);
            
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(trimmedHistory));
        } catch (error) {
            console.warn('Error updating recent history:', error);
        }
    }

    /**
     * Get the recent history
     * @returns {Array} Array of recent page entries
     */
    getHistory() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY);
            return stored ? JSON.parse(stored) : [];
        } catch (error) {
            console.warn('Error reading recent history:', error);
            return [];
        }
    }

    /**
     * Get recent pages (for display in sidebar)
     * @param {number} limit - Maximum number of pages to return
     * @returns {Array} Array of recent page names
     */
    getRecentPages(limit = 7) {
        const history = this.getHistory();
        return history.slice(0, limit).map(entry => entry.pageName);
    }

    /**
     * Get visit count for a specific page
     * @param {string} pageName - The page name
     * @param {Array} history - Optional history array (for performance)
     * @returns {number} Number of visits
     */
    getVisitCount(pageName, history = null) {
        const hist = history || this.getHistory();
        const entry = hist.find(e => e.pageName === pageName);
        return entry ? entry.visitCount : 0;
    }

    /**
     * Clear all history
     */
    clearHistory() {
        try {
            localStorage.removeItem(this.STORAGE_KEY);
        } catch (error) {
            console.warn('Error clearing recent history:', error);
        }
    }

    /**
     * Get history statistics
     * @returns {Object} History statistics
     */
    getStats() {
        const history = this.getHistory();
        return {
            totalEntries: history.length,
            totalVisits: history.reduce((sum, entry) => sum + entry.visitCount, 0),
            mostVisited: history.sort((a, b) => b.visitCount - a.visitCount).slice(0, 5)
        };
    }
}

// Create singleton instance
export const recentHistory = new RecentHistory(); 