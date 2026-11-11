/**
 * The month calendar in the sidebar (an Alpine component). Days that have a
 * journal page are marked; the marks are fetched one month at a time.
 *
 * @module app/calendar-component
 */

import { pagesAPI } from '../api_client.js';
import { localDate, pageUrl } from '../utils.js';

const months = new Map(); // 'YYYY-MM' => Promise<Map<date, page name>>

function daysWithPages(month) {
    if (!months.has(month)) {
        months.set(month, pagesAPI.datesInMonth(month)
            .then((rows) => new Map(rows.map((r) => [r.date, r.page_name])))
            .catch(() => {
                months.delete(month);
                return new Map();
            }));
    }
    return months.get(month);
}

export default function calendarComponent() {
    const current = new URLSearchParams(window.location.search).get('page') || '';
    const shown = /^\d{4}-\d{2}-\d{2}$/.test(current) ? new Date(`${current}T12:00:00`) : new Date();
    return {
        currentDate: isNaN(shown) ? new Date() : shown,
        currentPageName: current,
        calendarCells: [],

        monthYear() {
            return this.currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
        },

        init() {
            this.buildMonth();
        },

        async buildMonth() {
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            const key = `${year}-${String(month + 1).padStart(2, '0')}`;
            this.draw(year, month, new Map());
            const pages = await daysWithPages(key);
            // The user may have moved on to another month meanwhile.
            if (this.currentDate.getFullYear() === year && this.currentDate.getMonth() === month) this.draw(year, month, pages);
        },

        draw(year, month, pages) {
            const first = new Date(year, month, 1).getDay();
            const padding = first === 0 ? 6 : first - 1; // weeks start on Monday
            const lastDay = new Date(year, month + 1, 0).getDate();
            const today = localDate();
            const cells = ['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((label) => ({ weekday: true, label }));
            for (let i = 0; i < padding; i++) cells.push({ empty: true });
            for (let day = 1; day <= lastDay; day++) {
                const date = localDate(new Date(year, month, day));
                const pageName = pages.get(date);
                cells.push({
                    empty: false,
                    day,
                    date,
                    today: date === today,
                    hasPage: !!pageName,
                    pageName: pageName || date,
                    isCurrentPage: date === this.currentPageName || (!!pageName && pageName === this.currentPageName),
                });
            }
            this.calendarCells = cells;
        },

        prevMonth() {
            this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() - 1, 1);
            this.buildMonth();
        },

        nextMonth() {
            this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() + 1, 1);
            this.buildMonth();
        },

        goToday() {
            window.location.href = pageUrl(localDate());
        },

        dayClick(cell) {
            if (!cell.empty) window.location.href = pageUrl(cell.pageName);
        },
    };
}
