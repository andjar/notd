/**
 * UI Module - The central hub for all UI-related functions and objects.
 * This module is responsible for all direct DOM manipulation, rendering, and
 * setting up UI-specific event listeners. It should not contain business logic
 * or data-fetching logic, but rather functions that are called by the main
 * application controller (app.js) to update the view.
 * @module ui
 */

import { domRefs } from './ui/dom-refs.js';
import { renderNote, parseAndRenderContent, switchToEditMode, switchToRenderedMode, getRawTextWithNewlines, renderAttachments, renderInlineProperties, initializeDelegatedNoteEventListeners } from './ui/note-renderer.js';
import { displayNotes, updateNoteElement, addNoteElement, removeNoteElement, moveNoteElement, buildNoteTree, initializeDragAndDrop, updateParentVisuals } from './ui/note-elements.js';
import { setSaveStatus } from './app/state.js';
import { propertiesAPI } from './api_client.js';

// --- UI Components / Widgets ---

/**
 * Calendar Widget Module
 */
const calendarWidget = {
    currentDate: new Date(),
    currentPageName: null,

    init() {
        this.calendarEl = domRefs.rightSidebar?.querySelector('.calendar-widget');
        if (!this.calendarEl) return;
        
        this.monthEl = this.calendarEl.querySelector('.current-month');
        this.daysEl = this.calendarEl.querySelector('.calendar-days');
        this.prevBtn = this.calendarEl.querySelector('.calendar-nav.prev');
        this.nextBtn = this.calendarEl.querySelector('.calendar-nav.next');
        
        this.bindEvents();
        this.render();
    },
    
    bindEvents() {
        if (this.prevBtn) this.prevBtn.addEventListener('click', () => {
            this.currentDate.setMonth(this.currentDate.getMonth() - 1);
            this.render();
        });
        if (this.nextBtn) this.nextBtn.addEventListener('click', () => {
            this.currentDate.setMonth(this.currentDate.getMonth() + 1);
            this.render();
        });
    },
    
    setCurrentPage(pageName) {
        this.currentPageName = pageName;
        this.render();
    },
    
    render() {
        if (!this.monthEl || !this.daysEl) return;
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();
        this.monthEl.textContent = new Date(year, month).toLocaleString('default', { month: 'long', year: 'numeric' });
        this.daysEl.innerHTML = '';
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        const today = new Date();
        for (let i = 0; i < firstDayOfMonth.getDay(); i++) this.daysEl.appendChild(document.createElement('div'));
        for (let day = 1; day <= lastDayOfMonth.getDate(); day++) {
            const dayEl = document.createElement('div');
            dayEl.className = 'calendar-day';
            dayEl.textContent = day;
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            if (day === today.getDate() && month === today.getMonth() && year === today.getFullYear()) dayEl.classList.add('today');
            if (this.currentPageName === dateStr) dayEl.classList.add('current-page');
            dayEl.addEventListener('click', () => { if (typeof window.loadPage === 'function') window.loadPage(dateStr); });
            this.daysEl.appendChild(dayEl);
        }
    }
};

// --- Core UI Functions ---

function updatePageTitle(name) {
    const safeName = name ? name.replace(/</g, '<').replace(/>/g, '>') : 'notd';
    document.title = `${safeName} - notd`;
}

function renderPageTitle(pageName) {
    if (!domRefs.pageTitle) return;
    domRefs.pageTitle.innerHTML = '';
    const parts = pageName.split('/');
    parts.forEach((part, index) => {
        if (index < parts.length - 1) {
            const path = parts.slice(0, index + 1).join('/');
            const link = document.createElement('a');
            link.href = `#`;
            link.textContent = part;
            link.classList.add('namespace-breadcrumb');
            link.dataset.pageName = path;
            domRefs.pageTitle.appendChild(link);
            domRefs.pageTitle.appendChild(document.createTextNode(' / '));
        } else {
            domRefs.pageTitle.appendChild(document.createTextNode(part));
        }
    });
    const gearIcon = document.createElement('i');
    gearIcon.dataset.feather = 'settings';
    gearIcon.className = 'page-title-gear';
    gearIcon.id = 'page-properties-gear';
    gearIcon.title = 'Page Properties';
    domRefs.pageTitle.appendChild(gearIcon);
    if (typeof feather !== 'undefined') feather.replace();
}

function updatePageList(pages, activePageName) {
    if (!domRefs.pageListContainer) return;
    domRefs.pageListContainer.innerHTML = '';
    if (!pages || !Array.isArray(pages) || pages.length === 0) {
        domRefs.pageListContainer.innerHTML = '<li>No pages found.</li>';
        return;
    }
    const sorted = [...pages].sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
    const limited = sorted.slice(0, 20);
    limited.forEach(page => {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.href = `#`;
        link.dataset.pageName = page.name;
        link.textContent = page.name;
        if (page.name === activePageName) link.classList.add('active');
        li.appendChild(link);
        domRefs.pageListContainer.appendChild(li);
    });
}

function updateActivePageLink(pageName) {
    if (!domRefs.pageListContainer) return;
    domRefs.pageListContainer.querySelectorAll('a').forEach(link => {
        link.classList.toggle('active', link.dataset.pageName === pageName);
    });
}

function updateSaveStatusIndicator(newStatus) {
    setSaveStatus(newStatus);
    const indicator = domRefs.saveStatusIndicator;
    if (!indicator) return;
    indicator.classList.remove('status-saved', 'status-pending', 'status-error', 'status-hidden');
    indicator.classList.add(`status-${newStatus}`);
    let iconHtml = '';
    switch (newStatus) {
        case 'saved': iconHtml = '<i data-feather="check-circle"></i>'; indicator.title = 'All changes saved'; break;
        case 'pending': iconHtml = '<div class="dot-spinner"><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div><div class="dot-spinner__dot"></div></div>'; indicator.title = 'Saving...'; break;
        case 'error': iconHtml = '<i data-feather="alert-triangle"></i>'; indicator.title = 'Error saving'; break;
    }
    indicator.innerHTML = iconHtml;
    if (typeof feather !== 'undefined') feather.replace();
}

// --- Modals and Dynamic Content ---

function renderBacklinks(backlinksData) {
    if (!domRefs.backlinksContainer) return;
    if (!backlinksData || !Array.isArray(backlinksData) || backlinksData.length === 0) {
        domRefs.backlinksContainer.innerHTML = '<p>No backlinks found.</p>';
        return;
    }
    const html = backlinksData.map(link => `
        <div class="backlink-item">
            <a href="#" class="page-link" data-page-name="${link.source_page_name}">${link.source_page_name}</a>
            <div class="backlink-snippet">${link.content_snippet || ''}</div>
        </div>
    `).join('');
    domRefs.backlinksContainer.innerHTML = html;
}

function handleTransclusions() { /* Logic for finding and replacing !{{...}} placeholders */ }
function handleSqlQueries() { /* Logic for finding and replacing SQL{...} placeholders */ }

function initPagePropertiesModal() {
    if (!domRefs.pagePropertiesModal) return;
    const showModal = async () => {
        if (!window.currentPageId) return;
        try {
            const properties = await propertiesAPI.getProperties('page', window.currentPageId);
            if (typeof window.displayPageProperties === 'function') {
                window.displayPageProperties(properties); // This function now lives in property-editor.js
                domRefs.pagePropertiesModal.classList.add('active');
            }
        } catch (error) { console.error("Error loading properties for modal:", error); }
    };
    const hideModal = () => domRefs.pagePropertiesModal.classList.remove('active');
    
    document.body.addEventListener('click', (e) => {
        if (e.target.closest('#page-properties-gear')) showModal();
    });
    domRefs.pagePropertiesModal.querySelector('.modal-close-x')?.addEventListener('click', hideModal);
    domRefs.pagePropertiesModal.addEventListener('click', (e) => { if (e.target === domRefs.pagePropertiesModal) hideModal(); });
}

// --- UI Initializer ---

function init() {
    console.log("[UI] Initializing...");
    initPagePropertiesModal();
    calendarWidget.init();
    if (domRefs.notesContainer) {
        initializeDelegatedNoteEventListeners(domRefs.notesContainer);
    } else {
        console.error("[UI Init] Notes container not found.");
    }
    updateSaveStatusIndicator('saved');
    console.log("[UI] Initialization complete.");
}

// --- The Main Exported UI Object ---

export const ui = {
    // Core
    init,
    domRefs,

    // Page & Layout
    updatePageTitle,
    renderPageTitle,
    updatePageList,
    updateActivePageLink,
    updateSaveStatusIndicator,

    // Note Rendering (from note-renderer.js)
    renderNote,
    parseAndRenderContent,
    switchToEditMode,
    switchToRenderedMode,
    getRawTextWithNewlines,
    renderAttachments,
    renderInlineProperties,

    // Note Structure (from note-elements.js)
    displayNotes,
    updateNoteElement,
    addNoteElement,
    removeNoteElement,
    moveNoteElement,
    buildNoteTree,
    initializeDragAndDrop,
    updateParentVisuals,

    // Dynamic Content
    renderBacklinks,
    handleTransclusions,
    handleSqlQueries,
    
    // Widgets & Modals
    calendarWidget,
    initPagePropertiesModal,
};

// Make it globally available for legacy calls or simple event handlers
window.ui = ui;