/**
 * Page cache manager that persists data across page loads using localStorage
 * This provides faster loading for recently visited pages
 */
class PageCache {
    constructor() {
        this.CACHE_PREFIX = 'notd_page_cache_';
        this.CACHE_MAX_AGE_MS = 5 * 60 * 1000; // 5 minutes
        this.MAX_CACHE_SIZE = 20; // Maximum number of cached pages
    }

    /**
     * Get cached page data
     * @param {string} pageName - The page name
     * @returns {Object|null} Cached page data or null if not found/expired
     */
    getPage(pageName) {
        try {
            const cacheKey = this.CACHE_PREFIX + this.sanitizeKey(pageName);
            const cached = localStorage.getItem(cacheKey);
            
            if (!cached) return null;
            
            const pageData = JSON.parse(cached);
            
            // Check if cache is expired
            if (Date.now() - pageData.timestamp > this.CACHE_MAX_AGE_MS) {
                this.removePage(pageName);
                return null;
            }
            
            return pageData;
        } catch (error) {
            console.warn('Error reading from page cache:', error);
            return null;
        }
    }

    /**
     * Store page data in cache
     * @param {string} pageName - The page name
     * @param {Object} pageData - The page data to cache
     */
    setPage(pageName, pageData) {
        try {
            const cacheKey = this.CACHE_PREFIX + this.sanitizeKey(pageName);
            const dataToCache = {
                ...pageData,
                timestamp: Date.now()
            };
            
            localStorage.setItem(cacheKey, JSON.stringify(dataToCache));
            
            // Clean up old cache entries if we exceed max size
            this.cleanupCache();
        } catch (error) {
            console.warn('Error writing to page cache:', error);
        }
    }

    /**
     * Remove a page from cache
     * @param {string} pageName - The page name
     */
    removePage(pageName) {
        try {
            const cacheKey = this.CACHE_PREFIX + this.sanitizeKey(pageName);
            localStorage.removeItem(cacheKey);
        } catch (error) {
            console.warn('Error removing from page cache:', error);
        }
    }

    /**
     * Clear all cached pages
     */
    clearAll() {
        try {
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(this.CACHE_PREFIX)) {
                    keysToRemove.push(key);
                }
            }
            
            keysToRemove.forEach(key => localStorage.removeItem(key));
        } catch (error) {
            console.warn('Error clearing page cache:', error);
        }
    }

    /**
     * Get cache statistics
     * @returns {Object} Cache statistics
     */
    getStats() {
        try {
            const cachedPages = [];
            let totalSize = 0;
            
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(this.CACHE_PREFIX)) {
                    const pageName = key.replace(this.CACHE_PREFIX, '');
                    const cached = localStorage.getItem(key);
                    if (cached) {
                        const pageData = JSON.parse(cached);
                        cachedPages.push({
                            name: pageName,
                            timestamp: pageData.timestamp,
                            size: cached.length
                        });
                        totalSize += cached.length;
                    }
                }
            }
            
            return {
                count: cachedPages.length,
                totalSize: totalSize,
                pages: cachedPages.sort((a, b) => b.timestamp - a.timestamp)
            };
        } catch (error) {
            console.warn('Error getting cache stats:', error);
            return { count: 0, totalSize: 0, pages: [] };
        }
    }

    /**
     * Clean up old cache entries to stay within size limits
     */
    cleanupCache() {
        try {
            const stats = this.getStats();
            
            if (stats.count <= this.MAX_CACHE_SIZE) {
                return; // No cleanup needed
            }
            
            // Remove oldest entries
            const pagesToRemove = stats.pages
                .slice(this.MAX_CACHE_SIZE)
                .map(page => page.name);
            
            pagesToRemove.forEach(pageName => {
                this.removePage(pageName);
            });
            
            console.log(`Cleaned up ${pagesToRemove.length} old cache entries`);
        } catch (error) {
            console.warn('Error cleaning up cache:', error);
        }
    }

    /**
     * Sanitize key for localStorage (remove special characters)
     * @param {string} key - The key to sanitize
     * @returns {string} Sanitized key
     */
    sanitizeKey(key) {
        return key.replace(/[^a-zA-Z0-9_-]/g, '_');
    }

    /**
     * Check if a page is cached and not expired
     * @param {string} pageName - The page name
     * @returns {boolean} True if page is cached and valid
     */
    hasPage(pageName) {
        return this.getPage(pageName) !== null;
    }
}

// Create singleton instance
export const pageCache = new PageCache(); 