/**
 * Calendar Widget Module for NotTD application
 * Handles rendering and interaction of the calendar.
 * @module calendarWidget
 */

import { apiRequest } from '../api_client.js';

const domRefs = {}; // To store DOM references for the calendar widget

let currentPageName = null; // To keep track of the currently active page for highlighting

/**
 * Converts a Date object to a 'YYYY-MM-DD' string.
 * @param {Date} date - The date to convert.
 * @returns {string} The formatted date string.
 */
function toYYYYMMDD(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Initializes DOM references for the calendar widget.
 */
function initializeDomRefs() {
    domRefs.calendarWidget = document.getElementById('calendar-widget');
    domRefs.calendarHeader = document.querySelector('.calendar-header');
    domRefs.monthYearDisplay = document.getElementById('current-month-year');
    domRefs.prevMonthBtn = document.getElementById('prev-month-btn');
    domRefs.nextMonthBtn = document.getElementById('next-month-btn');
    domRefs.calendarDaysGrid = document.getElementById('calendar-days-grid');
}

let currentDisplayDate = new Date(); // Stores the month and year currently displayed by the calendar

/**
 * Renders the calendar grid for the current month and year.
 * @param {Date} date - The date to display the calendar for (month and year).
 * @param {Array} pagesWithDates - Array of pages that have a 'date' property, used for highlighting.
 */
async function renderCalendar(date, pagesWithDates = []) {
    if (!domRefs.calendarDaysGrid) {
        initializeDomRefs(); // Ensure refs are initialized
    }
    if (!domRefs.calendarDaysGrid) return; // If still not found, exit

    domRefs.calendarDaysGrid.innerHTML = '';
    domRefs.monthYearDisplay.textContent = date.toLocaleString('default', { month: 'long', year: 'numeric' });

    const year = date.getFullYear();
    const month = date.getMonth(); // 0-indexed

    // Get the first day of the month
    const firstDay = new Date(year, month, 1);
    // Get the last day of the month
    const lastDay = new Date(year, month + 1, 0);

    // Calculate the day of the week for the first day (0 = Sunday, 6 = Saturday)
    // Adjust to make Monday = 0, Sunday = 6
    let startDayOfWeek = firstDay.getDay();
    startDayOfWeek = startDayOfWeek === 0 ? 6 : startDayOfWeek - 1; // Convert Sunday (0) to 6, Monday (1) to 0, etc.

    // Fill in leading empty days
    for (let i = 0; i < startDayOfWeek; i++) {
        domRefs.calendarDaysGrid.appendChild(createDayElement('', true));
    }

    // Fill in days of the month
    for (let day = 1; day <= lastDay.getDate(); day++) {
        const currentDate = new Date(year, month, day);
        const formattedDate = toYYYYMMDD(currentDate);

        // Find a page that is either named with this date, or has a property for this date.
        const pageForThisDate = pagesWithDates.find(p => {
            if (p.name === formattedDate) return true;
            if (p.properties && p.properties.date) {
                return p.properties.date.some(prop => prop.value === formattedDate);
            }
            return false;
        });

        const isToday = formattedDate === toYYYYMMDD(new Date());
        const isCurrentPageDate = pageForThisDate && currentPageName === pageForThisDate.name;

        domRefs.calendarDaysGrid.appendChild(
            createDayElement(day, false, isToday, pageForThisDate, isCurrentPageDate)
        );
    }
}

/**
 * Creates a single day element for the calendar.
 * @param {string|number} day - The day number or empty string for placeholder.
 * @param {boolean} isEmpty - True if it's an empty placeholder day.
 * @param {boolean} isToday - True if the day is today.
 * @param {Object|null} pageForThisDate - The page object associated with this date, if any.
 * @param {boolean} isCurrentPageDate - True if the page for this date is the currently active page.
 * @returns {HTMLElement} The day element.
 */
function createDayElement(day, isEmpty, isToday = false, pageForThisDate = null, isCurrentPageDate = false) {
    const div = document.createElement('div');
    div.className = 'calendar-day';
    if (isEmpty) {
        div.classList.add('empty');
    } else {
        div.textContent = day;
        const formattedDate = currentDisplayDate.getFullYear() + '-' + 
                              String(currentDisplayDate.getMonth() + 1).padStart(2, '0') + '-' + 
                              String(day).padStart(2, '0');
        div.dataset.date = formattedDate;

        if (isToday) {
            div.classList.add('today');
        }
        if (pageForThisDate) {
            div.classList.add('has-content'); // Indicate that there's content on this day
            // Store the page name to navigate to if clicked
            div.dataset.pageName = pageForThisDate.name;
            div.title = `Page: ${pageForThisDate.name}`; // Tooltip for content
        }
        if (isCurrentPageDate) {
            div.classList.add('current-page');
        }
    }
    return div;
}

/**
 * Fetches pages for a given month and re-renders the calendar.
 * @param {Date} date - The date representing the month to fetch pages for.
 */
async function fetchAndRenderCalendar(date) {
    const year = date.getFullYear();
    const month = date.getMonth(); // month is 0-indexed

    try {
        // Fetch all pages to check against calendar dates.
        // In a larger app, this might be optimized to fetch only pages for the visible month.
        const response = await apiRequest(`pages.php?page=1&per_page=1000`);

        const allPages = response.data || [];
        const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
        
        // Filter pages to find those that have a 'date' property or are named like a date for the current month
        const pagesForMonth = allPages.filter(page => {
            // Check if page name is a date in the current view
            if (dateRegex.test(page.name)) {
                const [pageYear, pageMonth] = page.name.split('-').map(Number);
                if (pageYear === year && (pageMonth - 1) === month) {
                    return true;
                }
            }

            // Check if a 'date' property points to a date in the current view
            if (page.properties && page.properties.date) {
                return page.properties.date.some(prop => {
                    if (!prop.value) return false;
                    const [propYear, propMonth] = prop.value.split('-').map(Number);
                    return propYear === year && (propMonth - 1) === month;
                });
            }
            return false;
        });

        renderCalendar(date, pagesForMonth);
    } catch (error) {
        console.error('Error fetching pages for calendar:', error);
        // Render calendar without page data if there's an error
        renderCalendar(date);
    }
}


/**
 * Sets up event listeners for the calendar navigation.
 */
function setupEventListeners() {
    if (!domRefs.calendarWidget) {
        initializeDomRefs();
    }
    if (!domRefs.prevMonthBtn || !domRefs.nextMonthBtn || !domRefs.calendarDaysGrid) return;

    domRefs.prevMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() - 1);
        fetchAndRenderCalendar(currentDisplayDate);
    });

    domRefs.nextMonthBtn.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() + 1);
        fetchAndRenderCalendar(currentDisplayDate);
    });

    domRefs.calendarDaysGrid.addEventListener('click', (e) => {
        const dayElement = e.target.closest('.calendar-day');
        if (!dayElement || dayElement.classList.contains('empty')) return;

        // If a page name is directly associated (from 'date' property or name match), use it.
        let pageNameToLoad = dayElement.dataset.pageName;

        // If no page name is associated, assume it's a daily note named after the date.
        if (!pageNameToLoad && dayElement.dataset.date) {
            pageNameToLoad = dayElement.dataset.date;
        }

        if (pageNameToLoad && window.loadPage) {
            window.loadPage(pageNameToLoad);
        }
    });
}

/**
 * Initializes the calendar widget.
 */
function init() {
    initializeDomRefs();
    setupEventListeners();
    fetchAndRenderCalendar(currentDisplayDate);
}

/**
 * Updates the currently active page name for highlighting in the calendar.
 * @param {string} pageName - The name of the currently loaded page.
 */
function setCurrentPage(pageName) {
    currentPageName = pageName;
    // Re-render to update highlights if the calendar is already visible
    fetchAndRenderCalendar(currentDisplayDate);
}

export const calendarWidget = {
    init,
    setCurrentPage,
    domRefs // Expose domRefs for external access if needed (e.g., for initial setup in app.js)
}; 