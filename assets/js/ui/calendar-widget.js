// assets/js/ui/calendar-widget.js

import { pagesAPI } from '../api_client.js';

// **IMPROVEMENT**: Centralized DOM references for the widget.
const domRefs = {};

// **IMPROVEMENT**: State is managed more explicitly for clarity and performance.
// Caches all page data fetched from the server.
let pagesCache = [];
// A Map for O(1) date lookups for performance. Key: 'YYYY-MM-DD', Value: page object.
let dateToPageMap = new Map();

let currentPageName = null;
let currentDisplayDate = new Date();
let isDataInitialized = false;

/**
 * Converts a Date object to a 'YYYY-MM-DD' string.
 * @param {Date} date - The date to convert.
 * @returns {string} The formatted date string.
 */
function toYYYYMMDD(date) {
    return date.toISOString().split('T')[0];
}

/**
 * **IMPROVEMENT**: Initializes DOM refs and dynamically restructures the header
 * for a cleaner layout and the new "Today" button.
 */
function initializeDomRefs() {
    domRefs.calendarWidget = document.getElementById('calendar-widget');
    if (!domRefs.calendarWidget) return;

    domRefs.calendarHeader = domRefs.calendarWidget.querySelector('.calendar-header');
    domRefs.monthYearDisplay = document.getElementById('current-month-year');
    domRefs.prevMonthBtn = document.getElementById('prev-month-btn');
    domRefs.nextMonthBtn = document.getElementById('next-month-btn');
    domRefs.calendarDaysGrid = document.getElementById('calendar-days-grid');

    // Dynamically create a controls wrapper and the "Today" button.
    // This makes the layout robust and independent of the initial HTML structure.
    if (domRefs.calendarHeader && domRefs.prevMonthBtn && domRefs.nextMonthBtn && !domRefs.calendarHeader.querySelector('.calendar-nav-controls')) {
        const controlsWrapper = document.createElement('div');
        controlsWrapper.className = 'calendar-nav-controls';

        const todayBtn = document.createElement('button');
        todayBtn.id = 'today-btn';
        todayBtn.className = 'arrow-btn today-btn';
        todayBtn.title = 'Go to Today';
        todayBtn.textContent = 'Today';
        domRefs.todayBtn = todayBtn;

        // Add buttons to the wrapper in the desired visual order
        controlsWrapper.appendChild(todayBtn);
        controlsWrapper.appendChild(domRefs.prevMonthBtn);
        controlsWrapper.appendChild(domRefs.nextMonthBtn);

        // Add the new control group to the header
        domRefs.calendarHeader.appendChild(controlsWrapper);
    }
}

/**
 * **IMPROVEMENT**: Processes the raw page list into a fast lookup map.
 * This is the core performance optimization, changing lookups from O(N) to O(1).
 */
function processPageDataIntoMap() {
    dateToPageMap.clear();
    const dateRegex = /^\d{4}-\d{2}-\d{2}$/;

    for (const page of pagesCache) {
        // Map pages where the name is a date (e.g., daily notes)
        if (dateRegex.test(page.name)) {
            dateToPageMap.set(page.name, page);
        }

        // Map pages that have a 'date' property
        if (page.properties?.date && Array.isArray(page.properties.date)) {
            for (const dateProp of page.properties.date) {
                // Ensure we don't overwrite a daily note page with a page that just has a property
                if (dateProp.value && !dateToPageMap.has(dateProp.value)) {
                    dateToPageMap.set(dateProp.value, page);
                }
            }
        }
    }
}

/**
 * Creates a single day element for the calendar. (This function is unchanged)
 */
function createDayElement(day, isEmpty, isToday = false, pageForThisDate = null, isCurrentPageDate = false) {
    const div = document.createElement('div');
    div.className = 'calendar-day';
    if (isEmpty) {
        div.classList.add('empty');
    } else {
        div.textContent = day;
        const formattedDate = toYYYYMMDD(new Date(currentDisplayDate.getFullYear(), currentDisplayDate.getMonth(), day));
        div.dataset.date = formattedDate;

        if (isToday) div.classList.add('today');
        if (pageForThisDate) {
            div.classList.add('has-content');
            div.dataset.pageName = pageForThisDate.name;
            div.title = `Page: ${pageForThisDate.name}`;
        }
        if (isCurrentPageDate) {
            div.classList.add('current-page');
        }
    }
    return div;
}

/**
 * **IMPROVEMENT**: Renders the calendar using the fast `dateToPageMap`.
 */
function renderCalendar() {
    if (!domRefs.monthYearDisplay || !domRefs.calendarDaysGrid) return;

    domRefs.monthYearDisplay.textContent = currentDisplayDate.toLocaleString('default', { month: 'long', year: 'numeric' });
    domRefs.calendarDaysGrid.innerHTML = '';

    const year = currentDisplayDate.getFullYear();
    const month = currentDisplayDate.getMonth();
    
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    // JS getDay() is 0 (Sun) - 6 (Sat). Convert to 0 (Mon) - 6 (Sun) for layout.
    const startDayOfWeek = firstDayOfMonth.getDay() === 0 ? 6 : firstDayOfMonth.getDay() - 1;

    for (let i = 0; i < startDayOfWeek; i++) {
        domRefs.calendarDaysGrid.appendChild(createDayElement('', true));
    }

    const todayFormatted = toYYYYMMDD(new Date());

    for (let day = 1; day <= lastDayOfMonth.getDate(); day++) {
        const currentDate = new Date(year, month, day);
        const formattedDate = toYYYYMMDD(currentDate);

        // **PERFORMANCE-FIX**: O(1) map lookup instead of slow O(N) array.find().
        const pageForThisDate = dateToPageMap.get(formattedDate);
        const isToday = formattedDate === todayFormatted;
        const isCurrentPageDate = (currentPageName === formattedDate) || (pageForThisDate && currentPageName === pageForThisDate.name);

        domRefs.calendarDaysGrid.appendChild(
            createDayElement(day, false, isToday, pageForThisDate, isCurrentPageDate)
        );
    }
}

/**
 * Fetches all page data, processes it for fast lookups, and renders the calendar.
 */
async function fetchAndProcessData() {
    if (!domRefs.calendarWidget) return;
    
    if (!isDataInitialized && domRefs.calendarDaysGrid) {
        domRefs.calendarDaysGrid.innerHTML = '<div>Loading...</div>';
    }

    try {
        const response = await pagesAPI.getPages({ per_page: 5000 });
        pagesCache = response?.pages || [];
        
        // **IMPROVEMENT**: Process data right after fetching for optimal performance.
        processPageDataIntoMap();

        isDataInitialized = true;
        renderCalendar();
    } catch (error) {
        console.error('Error fetching pages for calendar:', error);
        if (domRefs.calendarDaysGrid) domRefs.calendarDaysGrid.innerHTML = '<div>Error loading.</div>';
    }
}

function setupEventListeners() {
    if (!domRefs.calendarWidget) return;

    // The month navigation is fast because it just re-renders using the existing processed map.
    domRefs.prevMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() - 1);
        renderCalendar();
    });

    domRefs.nextMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() + 1);
        renderCalendar();
    });

    // **IMPROVEMENT**: Add event listener for the new "Today" button.
    if (domRefs.todayBtn) {
        domRefs.todayBtn.addEventListener('click', () => {
            const today = new Date();
            // Only re-render if we are not already viewing the current month.
            if (currentDisplayDate.getMonth() !== today.getMonth() || currentDisplayDate.getFullYear() !== today.getFullYear()) {
                 currentDisplayDate = today;
                 renderCalendar();
            }
        });
    }

    domRefs.calendarDaysGrid.addEventListener('click', (e) => {
        const dayEl = e.target.closest('.calendar-day:not(.empty)');
        if (!dayEl) return;

        const pageNameToLoad = dayEl.dataset.pageName || dayEl.dataset.date;
        if (pageNameToLoad && window.loadPage) {
            window.loadPage(pageNameToLoad);
        }
    });
}

export const calendarWidget = {
    init() {
        initializeDomRefs();
        if (!domRefs.calendarWidget) return; // Halt if the widget isn't on the page

        setupEventListeners();
        fetchAndProcessData(); // Initial data fetch and processing
    },
    async setCurrentPage(pageName) {
        if (!isDataInitialized) return;

        const oldPageName = currentPageName;
        currentPageName = pageName;
        
        if (oldPageName !== pageName) {
            const isNewPageInCache = pagesCache.some(p => p.name === pageName);
            
            // If the new page isn't in our cache (e.g., just created), refetch all data.
            // This is a simple and effective way to ensure consistency.
            if (!isNewPageInCache) {
                await fetchAndProcessData(); // This fetches, processes, and then renders.
                return;
            }
        }
        
        // If it's a known page or the same page, just re-render, which is now very fast.
        renderCalendar();
    },
    refresh() {
        fetchAndProcessData();
    }
};