import { calendarCache } from './calendar-cache.js';

export default function calendarComponent() {
    return {
        currentDate: new Date(),
        days: [],
        isLoading: false,
        currentPageName: '',
        weekdays: ['M', 'T', 'W', 'T', 'F', 'S', 'S'],
        calendarCells: [],
        
        monthYear() {
            return this.currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
        },
        
        async init() {
            this.isLoading = true;
            try {
                // Get current page from URL
                const urlParams = new URLSearchParams(window.location.search);
                this.currentPageName = urlParams.get('page') || '';
                
                // Initialize cache (loads from storage or fetches from server)
                await calendarCache.init();
                this.buildMonth();
            } catch (error) {
                console.error('Calendar initialization error:', error);
            } finally {
                this.isLoading = false;
            }
        },
        
        buildMonth() {
            this.days = [];
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            const first = new Date(year, month, 1);
            const startDay = first.getDay() === 0 ? 6 : first.getDay() - 1;
            
            // Add empty days for padding
            for (let i = 0; i < startDay; i++) {
                this.days.push({ empty: true });
            }
            
            const lastDay = new Date(year, month + 1, 0).getDate();
            const todayStr = this.formatDate(new Date());
            const { dateToPageMap } = calendarCache.getCalendarData();
            
            // Build days for the month
            for (let d = 1; d <= lastDay; d++) {
                const dateStr = this.formatDate(new Date(year, month, d));
                const page = dateToPageMap.get(dateStr);
                
                this.days.push({
                    empty: false,
                    day: d,
                    date: dateStr,
                    today: dateStr === todayStr,
                    hasPage: !!page,
                    pageName: page ? page.name : dateStr, // Use date as page name if no page exists
                    isCurrentPage: dateStr === this.currentPageName || (page && page.name === this.currentPageName)
                });
            }
            // Combine weekdays and days into a single array for the grid
            this.calendarCells = [
                ...this.weekdays.map(w => ({ weekday: true, label: w })),
                ...this.days
            ];
        },
        
        formatDate(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
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
            this.currentDate = new Date();
            this.buildMonth();
        },
        
        dayClick(day) {
            if (day.empty) return;
            
            // **OPTIMIZED**: Always navigate to the date - backend will create page if needed
            // No need to check if page exists or create it manually
            window.location.href = `page.php?page=${encodeURIComponent(day.date)}`;
        },
        
        // Method to refresh calendar data (for when new pages are created)
        async refresh() {
            try {
                await calendarCache.refresh();
                this.buildMonth();
            } catch (error) {
                console.error('Calendar refresh error:', error);
            }
        },
        
        // Method to update current page (called when navigating)
        updateCurrentPage(pageName) {
            this.currentPageName = pageName;
            this.buildMonth();
        }
    };
} 