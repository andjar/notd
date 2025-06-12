/**
 * Calendar Widget Module
 */
export const calendarWidget = {
    currentDate: new Date(),
    currentPageName: null,
    
    init() {
        this.calendarEl = document.querySelector('.calendar-widget');
        if (!this.calendarEl) return;
        
        this.monthEl = this.calendarEl.querySelector('.current-month');
        this.daysEl = this.calendarEl.querySelector('.calendar-days');
        this.prevBtn = this.calendarEl.querySelector('.calendar-nav.prev');
        this.nextBtn = this.calendarEl.querySelector('.calendar-nav.next');
        
        this.bindEvents();
        this.render();
    },
    
    bindEvents() {
        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => {
                this.currentDate.setDate(1);
                this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                this.render();
            });
        }
        
        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => {
                this.currentDate.setDate(1);
                this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                this.render();
            });
        }
    },
    
    setCurrentPage(pageName) {
        this.currentPageName = pageName;
        this.render();
    },
    
    render() {
        if (!this.monthEl || !this.daysEl) return;
        
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();
        
        this.monthEl.textContent = new Date(year, month).toLocaleString('default', { 
            month: 'long', 
            year: 'numeric' 
        });
        
        this.daysEl.innerHTML = '';
        
        const firstDay = new Date(year, month, 1);
        let startingDay = firstDay.getDay(); // 0 for Sunday, 1 for Monday, etc.

        // Adjust startingDay to make Monday = 0, Tuesday = 1, ..., Sunday = 6
        // If Sunday (0), convert to 6 (end of week); otherwise, subtract 1.
        startingDay = startingDay === 0 ? 6 : startingDay - 1; 

        const lastDay = new Date(year, month + 1, 0);
        const totalDays = lastDay.getDate();
        
        for (let i = 0; i < startingDay; i++) {
            const emptyDay = document.createElement('div');
            emptyDay.className = 'calendar-day empty';
            this.daysEl.appendChild(emptyDay);
        }
        
        const today = new Date();
        for (let day = 1; day <= totalDays; day++) {
            const dayEl = document.createElement('div');
            dayEl.className = 'calendar-day';
            dayEl.textContent = day;
            
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            if (day === today.getDate() && 
                month === today.getMonth() && 
                year === today.getFullYear()) {
                dayEl.classList.add('today');
            }
            
            if (this.currentPageName === dateStr) {
                dayEl.classList.add('current-page');
            }
            
            dayEl.addEventListener('click', () => {
                if (typeof window.loadPage === 'function') {
                    window.loadPage(dateStr);
                }
            });
            
            this.daysEl.appendChild(dayEl);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    calendarWidget.init();
});