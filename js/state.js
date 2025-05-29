// For managing global application state
let currentPage = null;
let recentPages = [];
let prefetchedBlocks = {}; // Cache for prefetched blocks for the current page load
let noteTemplates = {};
let activeBlockElement = null; // For keyboard navigation
let isEditorOpen = false;      // To track if a textarea editor is active
let isToolbarVisible = true;   // To track if the note-action bar is visible

let suggestionsPopup = null;
let activeSuggestionIndex = -1;
let currentSuggestions = [];

// breadcrumbPath is used by zoomInOnNote and renderBreadcrumbs.
// It's implicitly part of the UI state when zoomed in.
let breadcrumbPath = null;

// UI State Management
const UIState = {
    // State values
    _state: {
        toolbarVisible: true,
        leftSidebarCollapsed: false,
        rightSidebarCollapsed: true,
        customSQLQuery: '',
        queryExecutionFrequency: 'manual'
    },

    // State change listeners
    _listeners: new Map(),

    // Initialize state from API
    async initialize() {
        try {
            const settings = await fetchSettings([
                'toolbarVisible',
                'leftSidebarCollapsed',
                'rightSidebarCollapsed',
                'customSQLQuery',
                'queryExecutionFrequency'
            ]);

            if (settings && Object.keys(settings).length > 0) {
                this._state.toolbarVisible = settings.toolbarVisible === 'true';
                this._state.leftSidebarCollapsed = settings.leftSidebarCollapsed === 'true';
                this._state.rightSidebarCollapsed = settings.rightSidebarCollapsed === 'true';
                this._state.customSQLQuery = settings.customSQLQuery || '';
                this._state.queryExecutionFrequency = settings.queryExecutionFrequency || 'manual';
            }
            this._notifyListeners('all');
        } catch (error) {
            console.error('Failed to initialize UI state:', error);
            // Keep default values
        }
    },

    // Get current state
    get(key) {
        return this._state[key];
    },

    // Update state and persist to backend
    async set(key, value) {
        if (!(key in this._state)) {
            console.error(`Invalid UI state key: ${key}`);
            return false;
        }

        const oldValue = this._state[key];
        this._state[key] = value;

        try {
            const success = await updateSetting(key, value);
            if (!success) {
                // Revert state on failure
                this._state[key] = oldValue;
                console.error(`Failed to update UI state for ${key}`);
                return false;
            }
            this._notifyListeners(key);
            return true;
        } catch (error) {
            // Revert state on error
            this._state[key] = oldValue;
            console.error(`Error updating UI state for ${key}:`, error);
            return false;
        }
    },

    // Subscribe to state changes
    subscribe(key, callback) {
        if (!this._listeners.has(key)) {
            this._listeners.set(key, new Set());
        }
        this._listeners.get(key).add(callback);
        // Return unsubscribe function
        return () => {
            const callbacks = this._listeners.get(key);
            if (callbacks) {
                callbacks.delete(callback);
            }
        };
    },

    // Notify listeners of state changes
    _notifyListeners(changedKey) {
        if (changedKey === 'all') {
            // Notify all listeners of all keys
            for (const [key, callbacks] of this._listeners) {
                callbacks.forEach(callback => callback(this._state[key]));
            }
        } else {
            // Notify listeners of specific key
            const callbacks = this._listeners.get(changedKey);
            if (callbacks) {
                callbacks.forEach(callback => callback(this._state[changedKey]));
            }
        }
    }
};

// Export UIState for use in other modules
window.UIState = UIState;
