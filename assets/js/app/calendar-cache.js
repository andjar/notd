import { pagesAPI } from '../api_client.js';

/**
 * Calendar cache manager that eliminates loading states
 * This provides instant calendar rendering after the first load
 */
class CalendarCache {
    constructor() {
        this.STORAGE_KEY = 'notd_calendar_cache';
        this.CACHE_MAX_AGE_MS = 10 * 60 * 1000; // 10 minutes (longer than page cache)
        this.isInitialized = false;
        this.pagesCache = [];
        this.dateToPageMap = new Map();
    }

    /**
     * Initialize the calendar cache
     * @returns {Promise<boolean>} True if cache was loaded successfully
     */
    async init() {
        if (this.isInitialized) return true;

        // Try to load from cache first
        const cached = this.loadFromStorage();
        if (cached) {
            this.pagesCache = cached.pages || [];
            this.dateToPageMap = new Map(cached.dateToPageMap || []);
            this.isInitialized = true;
            console.log('[Calendar Cache] Loaded from cache:', this.pagesCache.length, 'pages');
            return true;
        }

        // If no cache, fetch from server
        return await this.refresh();
    }

    /**
     * Get calendar data (pages and date mapping)
     * @returns {Object} Calendar data object
     */
    getCalendarData() {
        return {
            pages: this.pagesCache,
            dateToPageMap: this.dateToPageMap,
            isInitialized: this.isInitialized
        };
    }

    /**
     * Refresh calendar data from server
     * @returns {Promise<boolean>} True if refresh was successful
     */
    async refresh() {
        try {
            console.log('[Calendar Cache] Fetching fresh data from server...');
            const response = await pagesAPI.getPages({ per_page: 5000 });
            this.pagesCache = response?.pages || [];
            
            // Process data into fast lookup map
            this.processPageDataIntoMap();
            
            // Save to storage
            this.saveToStorage();
            
            this.isInitialized = true;
            console.log('[Calendar Cache] Refreshed:', this.pagesCache.length, 'pages');
            return true;
        } catch (error) {
            console.error('[Calendar Cache] Error refreshing data:', error);
            return false;
        }
    }

    /**
     * Process page data into fast lookup map
     */
    processPageDataIntoMap() {
        this.dateToPageMap.clear();
        const dateRegex = /^\d{4}-\d{2}-\d{2}$/;

        for (const page of this.pagesCache) {
            // Map pages where the name is a date (e.g., daily notes)
            if (dateRegex.test(page.name)) {
                this.dateToPageMap.set(page.name, page);
            }

            // Map pages that have a 'date' property
            if (page.properties?.date && Array.isArray(page.properties.date)) {
                for (const dateProp of page.properties.date) {
                    // Ensure we don't overwrite a daily note page with a page that just has a property
                    if (dateProp.value && !this.dateToPageMap.has(dateProp.value)) {
                        this.dateToPageMap.set(dateProp.value, page);
                    }
                }
            }
        }
    }

    /**
     * Get page for a specific date
     * @param {string} date - Date in YYYY-MM-DD format
     * @returns {Object|null} Page object or null if not found
     */
    getPageForDate(date) {
        return this.dateToPageMap.get(date) || null;
    }

    /**
     * Check if a page exists in cache
     * @param {string} pageName - The page name
     * @returns {boolean} True if page exists in cache
     */
    hasPage(pageName) {
        return this.pagesCache.some(p => p.name === pageName);
    }

    /**
     * Add a new page to cache (for newly created pages)
     * @param {Object} page - The page object to add
     */
    addPage(page) {
        // Remove existing page if it exists
        this.pagesCache = this.pagesCache.filter(p => p.name !== page.name);
        
        // Add new page
        this.pagesCache.push(page);
        
        // Reprocess the map
        this.processPageDataIntoMap();
        
        // Save to storage
        this.saveToStorage();
    }

    /**
     * Load cache from localStorage
     * @returns {Object|null} Cached data or null if not found/expired
     */
    loadFromStorage() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY);
            if (!stored) return null;

            const cached = JSON.parse(stored);
            
            // Check if cache is expired
            if (Date.now() - cached.timestamp > this.CACHE_MAX_AGE_MS) {
                localStorage.removeItem(this.STORAGE_KEY);
                return null;
            }

            return cached;
        } catch (error) {
            console.warn('[Calendar Cache] Error loading from storage:', error);
            return null;
        }
    }

    /**
     * Save cache to localStorage
     */
    saveToStorage() {
        try {
            const dataToCache = {
                pages: this.pagesCache,
                dateToPageMap: Array.from(this.dateToPageMap.entries()),
                timestamp: Date.now()
            };
            
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(dataToCache));
        } catch (error) {
            console.warn('[Calendar Cache] Error saving to storage:', error);
        }
    }

    /**
     * Clear the cache
     */
    clearCache() {
        try {
            localStorage.removeItem(this.STORAGE_KEY);
            this.pagesCache = [];
            this.dateToPageMap.clear();
            this.isInitialized = false;
        } catch (error) {
            console.warn('[Calendar Cache] Error clearing cache:', error);
        }
    }

    /**
     * Get cache statistics
     * @returns {Object} Cache statistics
     */
    getStats() {
        return {
            isInitialized: this.isInitialized,
            pagesCount: this.pagesCache.length,
            dateMapSize: this.dateToPageMap.size,
            cacheAge: this.getCacheAge()
        };
    }

    /**
     * Get cache age in milliseconds
     * @returns {number} Cache age in milliseconds
     */
    getCacheAge() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY);
            if (!stored) return 0;

            const cached = JSON.parse(stored);
            return Date.now() - cached.timestamp;
        } catch (error) {
            return 0;
        }
    }
}

// Create singleton instance
export const calendarCache = new CalendarCache(); 