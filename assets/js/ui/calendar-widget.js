// assets/js/ui/calendar-widget.js

import { apiRequest } from '../api_client.js';

let currentPageName = null;
let currentDisplayDate = new Date();

const toYYYYMMDD = (date) => date.toISOString().split('T')[0];

function createDayElement(day, formattedDate, isToday, pageForThisDate, isCurrentPageDate) {
    // ... (This function is correct, no changes needed)
    const div = document.createElement('div');
    div.className = 'calendar-day';
    if (!day) {
        div.classList.add('empty');
    } else {
        div.textContent = day;
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

async function render() {
    const calendarEl = document.getElementById('calendar-widget');
    if (!calendarEl) return;
    
    const monthDisplay = calendarEl.querySelector('.month-year-display');
    const daysGrid = calendarEl.querySelector('.calendar-days');
    
    if(!monthDisplay || !daysGrid) return;
    
    monthDisplay.textContent = currentDisplayDate.toLocaleString('default', { month: 'long', year: 'numeric' });
    daysGrid.innerHTML = '';

    const year = currentDisplayDate.getFullYear();
    const month = currentDisplayDate.getMonth();

    try {
        // **FIX**: Correctly handle the paginated response.
        const response = await apiRequest('pages.php?per_page=5000'); // Fetch a large number to get all relevant pages
        const allPages = response?.data || [];
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        let startDayOfWeek = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1;

        for (let i = 0; i < startDayOfWeek; i++) {
            daysGrid.appendChild(createDayElement('', ''));
        }

        for (let day = 1; day <= lastDay.getDate(); day++) {
            const currentDate = new Date(year, month, day);
            const formattedDate = toYYYYMMDD(currentDate);

            const pageForThisDate = allPages.find(p => p.name === formattedDate || p.properties?.date?.some(d => d.value === formattedDate));
            const isToday = formattedDate === toYYYYMMDD(new Date());
            const isCurrentPageDate = currentPageName === pageForThisDate?.name;

            daysGrid.appendChild(
                createDayElement(day, formattedDate, isToday, pageForThisDate, isCurrentPageDate)
            );
        }
    } catch (error) {
        console.error('Error fetching pages for calendar:', error);
        daysGrid.innerHTML = '<tr><td colspan="7">Error loading calendar data.</td></tr>';
    }
}


function setupEventListeners() {
    document.getElementById('prev-month-btn')?.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() - 1);
        render();
    });

    document.getElementById('next-month-btn')?.addEventListener('click', () => {
        currentDisplayDate.setMonth(currentDisplayDate.getMonth() + 1);
        render();
    });

    document.getElementById('calendar-days-grid')?.addEventListener('click', (e) => {
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
        setupEventListeners();
        render();
    },
    setCurrentPage(pageName) {
        currentPageName = pageName;
        render(); // Re-render to update highlights
    }
};