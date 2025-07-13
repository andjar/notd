import { pagesAPI } from '../api_client.js';
import { recentHistory } from './recent-history.js';

/**
 * Recent pages manager that combines client-side history with server-side data
 * This provides the best of both worlds: user navigation history + server data
 */
class RecentPagesManager {
    constructor() {
        this.cache = new Map();
        this.cacheTimeout = 5 * 60 * 1000; // 5 minutes
    }

    /**
     * Get recent pages for display in sidebar
     * Combines client-side history with server-side data
     * @param {number} limit - Maximum number of pages to return
     * @returns {Promise<Array>} Array of recent page objects
     */
    async getRecentPages(limit = 7) {
        try {
            // Get client-side history first
            const clientHistory = recentHistory.getRecentPages(limit);
            
            // Get server-side recent pages
            const serverResponse = await pagesAPI.getRecentPages();
            const serverPages = serverResponse?.recent_pages || [];
            
            // Combine and deduplicate
            const combinedPages = this.combinePages(clientHistory, serverPages, limit);
            
            return combinedPages;
        } catch (error) {
            console.warn('Error getting recent pages, falling back to client history:', error);
            // Fallback to client-side history only
            return this.getClientHistoryPages(limit);
        }
    }

    /**
     * Combine client history with server pages
     * @param {Array} clientHistory - Array of page names from client history
     * @param {Array} serverPages - Array of page objects from server
     * @param {number} limit - Maximum number of pages to return
     * @returns {Array} Combined and deduplicated page objects
     */
    combinePages(clientHistory, serverPages, limit) {
        const combined = [];
        const seen = new Set();
        
        // Add client history pages first (prioritize user navigation)
        for (const pageName of clientHistory) {
            if (seen.has(pageName)) continue;
            
            // Find corresponding server data
            const serverPage = serverPages.find(p => p.name === pageName);
            
            if (serverPage) {
                combined.push(serverPage);
            } else {
                // Create a basic page object if not found in server data
                combined.push({
                    id: null,
                    name: pageName,
                    updated_at: new Date().toISOString()
                });
            }
            
            seen.add(pageName);
        }
        
        // Add remaining server pages to fill up to limit
        for (const serverPage of serverPages) {
            if (combined.length >= limit) break;
            if (seen.has(serverPage.name)) continue;
            
            combined.push(serverPage);
            seen.add(serverPage.name);
        }
        
        return combined;
    }

    /**
     * Get pages from client history only (fallback)
     * @param {number} limit - Maximum number of pages to return
     * @returns {Array} Array of basic page objects
     */
    getClientHistoryPages(limit = 7) {
        const history = recentHistory.getRecentPages(limit);
        return history.map(pageName => ({
            id: null,
            name: pageName,
            updated_at: new Date().toISOString()
        }));
    }

    /**
     * Get recent pages with caching
     * @param {number} limit - Maximum number of pages to return
     * @returns {Promise<Array>} Array of recent page objects
     */
    async getRecentPagesCached(limit = 7) {
        const cacheKey = `recent_pages_${limit}`;
        const cached = this.cache.get(cacheKey);
        
        if (cached && (Date.now() - cached.timestamp < this.cacheTimeout)) {
            return cached.data;
        }
        
        const data = await this.getRecentPages(limit);
        this.cache.set(cacheKey, {
            data: data,
            timestamp: Date.now()
        });
        
        return data;
    }

    /**
     * Clear the cache
     */
    clearCache() {
        this.cache.clear();
    }

    /**
     * Get statistics about recent pages
     * @returns {Object} Statistics object
     */
    getStats() {
        const historyStats = recentHistory.getStats();
        return {
            clientHistory: historyStats,
            cacheSize: this.cache.size,
            cacheTimeout: this.cacheTimeout
        };
    }
}

// Create singleton instance
export const recentPagesManager = new RecentPagesManager(); 