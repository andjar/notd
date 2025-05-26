// For managing global application state
let currentPage = null;
let recentPages = [];
let prefetchedBlocks = {}; // Cache for prefetched blocks for the current page load
let noteTemplates = {};
let activeBlockElement = null; // For keyboard navigation
let isEditorOpen = false;      // To track if a textarea editor is active

let suggestionsPopup = null;
let activeSuggestionIndex = -1;
let currentSuggestions = [];

// breadcrumbPath is used by zoomInOnNote and renderBreadcrumbs.
// It's implicitly part of the UI state when zoomed in.
let breadcrumbPath = null;
