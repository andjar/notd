import { pagesAPI } from '../api_client.js';

export default function calendarComponent() {
    return {
        pagesMap: new Map(),
        currentDate: new Date(),
        days: [],
        monthYear() {
            return this.currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
        },
        init() {
            this.fetchPages();
        },
        async fetchPages() {
            try {
                const resp = await pagesAPI.getPages({ per_page: 5000 });
                const pages = resp?.pages || [];
                this.pagesMap.clear();
                pages.forEach(p => {
                    if (/^\d{4}-\d{2}-\d{2}$/.test(p.name)) {
                        this.pagesMap.set(p.name, p);
                    }
                    if (p.properties?.date && Array.isArray(p.properties.date)) {
                        p.properties.date.forEach(prop => {
                            if (prop.value && !this.pagesMap.has(prop.value)) {
                                this.pagesMap.set(prop.value, p);
                            }
                        });
                    }
                });
                this.buildMonth();
            } catch (e) {
                console.error('Calendar fetch error:', e);
            }
        },
        buildMonth() {
            this.days = [];
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            const first = new Date(year, month, 1);
            const startDay = first.getDay() === 0 ? 6 : first.getDay() - 1;
            for (let i = 0; i < startDay; i++) this.days.push({ empty: true });
            const lastDay = new Date(year, month + 1, 0).getDate();
            const todayStr = this.formatDate(new Date());
            for (let d = 1; d <= lastDay; d++) {
                const dateStr = this.formatDate(new Date(year, month, d));
                const page = this.pagesMap.get(dateStr);
                this.days.push({
                    empty: false,
                    day: d,
                    date: dateStr,
                    today: dateStr === todayStr,
                    hasPage: !!page,
                    pageName: page ? page.name : null
                });
            }
        },
        formatDate(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        },
        prevMonth() {
            this.currentDate.setMonth(this.currentDate.getMonth() - 1);
            this.buildMonth();
        },
        nextMonth() {
            this.currentDate.setMonth(this.currentDate.getMonth() + 1);
            this.buildMonth();
        },
        goToday() {
            this.currentDate = new Date();
            this.buildMonth();
        },
        dayClick(day) {
            if (day.empty) return;
            if (day.hasPage) {
                window.loadPage(day.pageName);
            } else if (window.createPageWithContent) {
                window.createPageWithContent(day.date, '');
            }
        }
    };
} 