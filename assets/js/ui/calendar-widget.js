// assets/js/ui/calendar-widget.js

import { apiRequest } from '../api_client.js';

const domRefs = {}; // To store DOM references for the calendar widget

let currentPageName = null;
let currentDisplayDate = new Date();

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

async function fetchAndRenderCalendar() {
    if (!domRefs.monthYearDisplay || !domRefs.calendarDaysGrid) return;
    
    domRefs.monthYearDisplay.textContent = currentDisplayDate.toLocaleString('default', { month: 'long', year: 'numeric' });
    domRefs.calendarDaysGrid.innerHTML = '<div>Loading...</div>';

    const year = currentDisplayDate.getFullYear();
    const month = currentDisplayDate.getMonth(); // 0-indexed

    try {
        const response = await apiRequest('pages.php?per_page=5000'); // Fetch a large number to get all relevant pages
        const allPages = response?.data || [];
        
        domRefs.calendarDaysGrid.innerHTML = ''; // Clear loading message
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        let startDayOfWeek = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1;

        for (let i = 0; i < startDayOfWeek; i++) {
            domRefs.calendarDaysGrid.appendChild(createDayElement('', true));
        }

        for (let day = 1; day <= lastDay.getDate(); day++) {
            const currentDate = new Date(year, month, day);
            const formattedDate = toYYYYMMDD(currentDate);

            const pageForThisDate = allPages.find(p => p.name === formattedDate || p.properties?.date?.some(d => d.value === formattedDate));
            const isToday = formattedDate === toYYYYMMDD(new Date());
            const isCurrentPageDate = currentPageName === pageForThisDate?.name;

            domRefs.calendarDaysGrid.appendChild(
                createDayElement(day, false, isToday, pageForThisDate, isCurrentPageDate)
            );
        }
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
        fetchAndRenderCalendar();
    });

    domRefs.nextMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() + 1);
        fetchAndRenderCalendar();
    });

    domRefs.calendarDaysGrid.addEventListener('click', async (e) => {
        const dayEl = e.target.closest('.calendar-day:not(.empty)');
        if (!dayEl) return;

        let pageNameToLoad = dayEl.dataset.pageName;

        if (pageNameToLoad) {
            window.loadPage(pageNameToLoad);
        } else if (dayEl.dataset.date) {
            // Day has no page, so create it
            const dateToCreate = dayEl.dataset.date;
            const created = await window.createPageWithContent(dateToCreate, '{type::journal}');
            if (!created) {
                alert(`Could not create journal page for ${dateToCreate}.`);
            }
        }
    });
}

export const calendarWidget = {
    init() {
        initializeDomRefs();
        setupEventListeners();
        fetchAndRenderCalendar();
    },
    setCurrentPage(pageName) {
        currentPageName = pageName;
        fetchAndRenderCalendar();
    }
};