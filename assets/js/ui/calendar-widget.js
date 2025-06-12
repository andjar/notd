// assets/js/ui/calendar-widget.js

import { apiRequest, pagesAPI } from '../api_client.js';

const domRefs = {}; // To store DOM references for the calendar widget

let currentPageName = null;
let currentDisplayDate = new Date();
let calendarPagesCache = [];
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
 * Initializes DOM references for the calendar widget.
 */
function initializeDomRefs() {
    domRefs.calendarWidget = document.getElementById('calendar-widget');
    domRefs.monthYearDisplay = document.getElementById('current-month-year');
    domRefs.prevMonthBtn = document.getElementById('prev-month-btn');
    domRefs.nextMonthBtn = document.getElementById('next-month-btn');
    domRefs.calendarDaysGrid = document.getElementById('calendar-days-grid');
}

/**
 * Creates a single day element for the calendar.
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
 * Renders the calendar grid using cached page data.
 */
function renderCalendar() {
    if (!domRefs.monthYearDisplay || !domRefs.calendarDaysGrid) return;

    domRefs.monthYearDisplay.textContent = currentDisplayDate.toLocaleString('default', { month: 'long', year: 'numeric' });
    domRefs.calendarDaysGrid.innerHTML = ''; // Clear previous content

    const year = currentDisplayDate.getFullYear();
    const month = currentDisplayDate.getMonth(); // 0-indexed

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    let startDayOfWeek = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1; // Assuming Monday is the first day (0=Sun, 1=Mon...6=Sat)

    for (let i = 0; i < startDayOfWeek; i++) {
        domRefs.calendarDaysGrid.appendChild(createDayElement('', true));
    }

    for (let day = 1; day <= lastDay.getDate(); day++) {
        const currentDate = new Date(year, month, day);
        const formattedDate = toYYYYMMDD(currentDate);

        const pageForThisDate = calendarPagesCache.find(p => p.name === formattedDate || p.properties?.date?.some(d => d.value === formattedDate));
        const isToday = formattedDate === toYYYYMMDD(new Date());
        
        // **FIXED**: More robust check for the current page to enable glowing effect.
        const isCurrentPageDate = (currentPageName === formattedDate) || (pageForThisDate && currentPageName === pageForThisDate.name);

        domRefs.calendarDaysGrid.appendChild(
            createDayElement(day, false, isToday, pageForThisDate, isCurrentPageDate)
        );
    }
}

/**
 * Fetches all page data for the calendar, caches it, and then renders the calendar.
 */
async function fetchCalendarData() {
    if (!domRefs.monthYearDisplay || !domRefs.calendarDaysGrid) return;
    
    if (!isDataInitialized) {
        domRefs.calendarDaysGrid.innerHTML = '<div>Loading...</div>';
    }

    try {
        const response = await pagesAPI.getPages({ per_page: 5000 });
        calendarPagesCache = response?.pages || [];
        isDataInitialized = true;
        renderCalendar(); // Render with the newly fetched data
    } catch (error) {
        console.error('Error fetching pages for calendar:', error);
        if (domRefs.calendarDaysGrid) domRefs.calendarDaysGrid.innerHTML = '<div>Error loading.</div>';
    }
}

function setupEventListeners() {
    if (!domRefs.calendarWidget) initializeDomRefs();
    if (!domRefs.prevMonthBtn) return;

    domRefs.prevMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() - 1);
        renderCalendar(); // **FIXED**: Just render from cache
    });

    domRefs.nextMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() + 1);
        renderCalendar(); // **FIXED**: Just render from cache
    });

    domRefs.calendarDaysGrid.addEventListener('click', (e) => {
        const dayEl = e.target.closest('.calendar-day:not(.empty)');
        if (!dayEl) return;

        // A page name can be from an existing page or the date itself for a new page
        const pageNameToLoad = dayEl.dataset.pageName || dayEl.dataset.date;
        if (pageNameToLoad) {
            window.loadPage(pageNameToLoad);
        }
    });
}

export const calendarWidget = {
    init() {
        initializeDomRefs();
        setupEventListeners();
        fetchCalendarData(); // Initial data fetch
    },
    async setCurrentPage(pageName) {
        const oldPageName = currentPageName;
        currentPageName = pageName;

        if (!isDataInitialized) return; // Wait for initial fetch to complete

        // If the page has changed, check if we need to refresh our data
        if (oldPageName !== pageName) {
            const isNewPageInCache = calendarPagesCache.some(p => p.name === pageName);
            // If the new page isn't in our cache, it's likely just been created.
            // We need to refetch our data to get it.
            if (!isNewPageInCache) {
                await fetchCalendarData(); // This fetches and then renders.
                return; // Render is done, exit.
            }
        }
        
        // If it's a known page or the same page, just re-render from cache.
        renderCalendar();
    },
    /**
     * Public method to allow external components to trigger a data refresh.
     */
    refresh() {
        fetchCalendarData();
    }
};